<?php

require_once(__DIR__ . '/Base.php');

/**
 * Use this class with QueryResults to run a set of queries uploaded as a plain text file
 *
 * @see QueryResults
 *
 * $Id$
 */
class QRScript extends Base {

    const DEFAULT_SCRIPT_NAME = 'script';

    private $script_name = null;
    private $callback = null;

	/**
	 * Sets options particular to this worker
	 *
	 * @param array Any options passed in from QueryResults
	 * @return void
	 */
    protected function setOptions(array $options) {
        // we don't have any right now
    }

	/**
	 * Sets paramaters for this script run
	 *
	 * @param array The parameters provided by the end user
	 * @return boolean Whether they're suffient to run this script
	 */
    protected function setParams(array $params) {
        if (!isset($params['script_name'])) {
            $params['script_name'] = self::DEFAULT_SCRIPT_NAME;
        }
        // NB: executeScript() relies on $_FILES
        if (!isset($_FILES[$params['script_name']])
            || $_FILES[$params['script_name']]['size'] > 0) {
            return false;
        }
        $this->script_name = $params['script_name'];
        return true;
    }

	/**
	 * Runs the query and build the response
	 *
	 * @param array (optional) The parameters provided by the end user
	 * @return boolean Whether the request was successful
	 */
	public function run(array $params = array()) {
        global $data;
        $data->executeScript($this->script_name, array($this, 'scriptCallback'));
    }

	private function scriptCallback($query, $rs, $lineno) {
		global $data, $misc, $lang, $_connection;

		// Check if $rs is false, if so then there was a fatal error
		if ($rs === false) {
            $msg = $_FILES[$name]['name']) . ':' . $lineno . ': ' . $_connection->getLastError();
            return $this->error_callback($msg);
		}

		// Print query results
		switch (pg_result_status($rs)) {
			case PGSQL_TUPLES_OK:
				// If rows returned, then display the results
				$num_fields = pg_numfields($rs);
				$this->write("<p><table>\n<tr>");
				for ($k = 0; $k < $num_fields; $k++) {
					$this->write("<th class=\"data\">", $misc->printVal(pg_fieldname($rs, $k)), "</th>");
				}

				$i = 0;
				$row = pg_fetch_row($rs);
				while ($row !== false) {
					$id = (($i % 2) == 0 ? '1' : '2');
					$this->write("<tr class=\"data{$id}\">\n");
					foreach ($row as $k => $v) {
						$this->write("<td style=\"white-space:nowrap;\">", $misc->printVal($v, pg_fieldtype($rs, $k), array('null' => true)), "</td>");
					}
					$this->write("</tr>\n");
					$row = pg_fetch_row($rs);
					$i++;
				};
				$this->write("</table><br/>\n");
				$this->write($i, " {$lang['strrows']}</p>\n");
				break;

			case PGSQL_COMMAND_OK:
				// If we have the command completion tag
				if (version_compare(phpversion(), '4.3', '>=')) {
					$this->write(htmlspecialchars(pg_result_status($rs, PGSQL_STATUS_STRING)), "<br/>\n");
				}
				// Otherwise if any rows have been affected
				elseif ($data->conn->Affected_Rows() > 0) {
					$this->write($data->conn->Affected_Rows(), " {$lang['strrowsaff']}<br/>\n");
				}
				// Otherwise output nothing...
				break;

			case PGSQL_EMPTY_QUERY:
				break;

			default:
				break;
		}
	}

}

?>
