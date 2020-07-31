<?php

require_once(__DIR__ . '/BaseWorker.php');

/**
 * Use this class with QueryResults to run a query written directly by the user
 *
 * @see QueryResults
 *
 * $Id$
 */
class QRPlain extends BaseWorker {

    private $sql = null;

	public static $DEFAULT_OPTIONS = array(
        'save_history' => true,
    );

    /**
     * Filters options coming from the QueryResults class
     *
     * @param array The options
     * @return array The options, filtered appropriately
     */
    public static function filterOptions(array $options) {
		$filtered = array();
		if (isset($options['save_history'])) {
			$filtered['save_history'] = $options['save_history'] ? true : false;
		}
		else {
			$filtered['save_history'] = self::$DEFAULT_OPTIONS['save_history'];
		}
		return $filtered;
	}

	/**
	 * Sets paramaters for this query
	 *
	 * @param array The parameters provided by the end user
	 * @return boolean Whether they're suffient to run this query
	 */
    private function setParams(array $params) {
        if ($params['sql']) {
            $this->sql = $params['sql'];
            return true;
        } else {
            return false;
        }
    }

	/**
	 * Runs the query and build the response
	 *
	 * @param array (optional) The parameters provided by the end user
	 * @return boolean Whether the request was successful
	 */
	public function run(array $params = array()) {
        global $data, $lang;

        if (!$this->setParams($params)) {
            return $this->error_callback($lang['strinsufficientparams'])
        }

        // Run query
        // Set fetch mode to NUM so that duplicate field names are properly returned
        $data->conn->setFetchMode(ADODB_FETCH_NUM);
        $this->result = $data->conn->Execute($this->sql);
		if (!is_object($this->result)) {
			return $this->error_callback($lang['strqueryerror']);
		}

		// Save query to history, if necessary
        if ($this->options['save_history']) {
            $misc->saveScriptHistory($this->sql);
        }

        // If there were rows returned
        if ($this->result->recordCount() > 0) {
            $this->displayResultsTable();
        }

        // If any rows were affected
        elseif ($data->conn->Affected_Rows() > 0) {
            $this->write("<p>", $data->conn->Affected_Rows(), " {$lang['strrowsaff']}</p>\n");
        } 

        // Otherwise, no data to display
        else {
            $this->write('<p>', $lang['strnodata'], "</p>\n");
        }

        return true;
    }

	/**
	 * Writes the results table to the output stream
	 */
	private function displayResultsTable() {
        $this->write("<table>\n<tr>");
        foreach ($rs->fields as $k => $v) {
            $finfo = $rs->fetchField($k);
            $this->write("<th class=\"data\">", $misc->printVal($finfo->name), "</th>");
        }
        $this->write("</tr>\n");
        $i = 0;
        while (!$rs->EOF) {
            $id = (($i % 2) == 0 ? '1' : '2');
            $this->write("<tr class=\"data{$id}\">\n");
            foreach ($rs->fields as $k => $v) {
                $finfo = $rs->fetchField($k);
                $this->write("<td style=\"white-space:nowrap;\">", $misc->printVal($v, $finfo->type, array('null' => true)), "</td>");
            }
            $this->write("</tr>\n");
            $rs->moveNext();
            $i++;
        }
        $this->write("</table>\n");
        $this->write("<p>", $rs->recordCount(), " {$lang['strrows']}</p>\n");
	}

}

?>
