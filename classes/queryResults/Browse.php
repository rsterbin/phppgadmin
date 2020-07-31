<?php

require_once(__DIR__ . '/BaseWorker.php');

/**
 * Browse through the results of a query or table data by page
 *
 * @see QueryResults
 *
 * $Id$
 */
class QRBrowse extends QRBaseWorker {

	const QUERY_TYPE_QUERY = 'QUERY';
	const QUERY_TYPE_SELECT = 'SELECT';
	const QUERY_TYPE_TABLE = 'TABLE';

	const SORT_DIR_ASC = 'asc';
	const SORT_DIR_DESC = 'desc';

	const COLLAPSED_YES = 'collapsed';
	const COLLAPSED_NO = 'expanded';

	private $type = QUERY_TYPE_QUERY;
	private $schema = null;
	private $table = null;
	private $sql = null;
	private $object = null;
	private $sort_key = null;
	private $sort_dir = null;
	private $page = null;
	private $max_pages = 1;

	private $result = null;
	private $key = array();
	private $link_params = array();

	public static $DEFAULT_OPTIONS = array(
		'save_history' => true,
		'pagination_window' => 10,
		'strings' => self::COLLAPSED_YES,
        'plugin_place' => 'display-browse',
	);

    /**
     * Filters options coming from the QueryResults class
     *
     * @param array The options
     * @return array The options, filtered appropriately
     */
    public static function filterOptions(array $options) {
		$filtered = array();
		if (isset($options['nohistory'])) {
			$filtered['save_history'] = $options['nohistory'] ? true : false;
		}
		else {
			$filtered['save_history'] = self::$DEFAULT_OPTIONS['save_history'];
		}
		if (isset($options['pagination_window']) && ctype_digit($options['pagination_window'])) {
			$filtered['pagination_window'] = intval($options['pagination_window']);
		}
		else {
			$filtered['pagination_window'] = self::$DEFAULT_OPTIONS['pagination_window'];
		}
		if (isset($options['strings']) && (
			strtolower($options['strings']) === self::COLLAPSED_YES ||
			strtolower($options['strings']) === self::COLLAPSED_NO)) {
			$filtered['strings'] = strtolower($options['strings']);
		}
		else {
			$filtered['strings'] = self::$DEFAULT_OPTIONS['strings'];
		}
        if (isset($options['plugin_place']) && is_scalar($options['plugin_place'])) {
            $filtered['plugin_place'] = $options['plugin_place'];
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
		if ($params['type'] === self::QUERY_TYPE_QUERY
			|| $params['type'] === self::QUERY_TYPE_SELECT
			|| $params['type'] === self::QUERY_TYPE_TABLE) {
			$this->type = $params['type'];
		}
		else {
			return false;
		}
		if ($params['type'] === self::QUERY_TYPE_TABLE) {
			if (!isset($params['table']) || !is_string($params['table'])) {
				return false;
			}
			else {
				$this->table = $params['table'];
			}
		}
		else {
			if (!isset($params['sql']) || !is_string($params['sql'])) {
				return false;
			}
			else {
				$this->sql = $params['sql'];
			}
		}
		if ($params['type'] !== self::QUERY_TYPE_QUERY) {
			if (!isset($params['schema']) || !is_string($params['schema'])) {
				return false;
			}
			else {
				$this->schema = $params['schema'];
			}
		}
		if (isset($params['object'])) {
			$this->object = $params['object']; // let postgres check this
		}
		if (isset($params['sort_key'])) {
			$this->sort_key = $params['sort_key']; // let postgres check this
		}
		if (isset($params['sort_dir']) && (
				strtolower($params['sort_dir']) === self::SORT_DIR_ASC ||
				strtolower($params['sort_dir']) === self::SORT_DIR_DESC
			)) {
			$this->sort_dir = strtolower($params['sort_dir']);
		}
		if (isset($params['page']) && ctype_digit($params['page'])) {
			$this->page = $params['page'];
		}
		if ($this->object !== null) {
			$this->key = $data->getRowIdentifier($this->object);
		}
		return true;
	}

	/**
	 * Runs the query and build the response
	 *
	 * @param array (optional) The parameters provided by the end user
	 * @return boolean Whether the request was successful
	 */
	public function run(array $params = array()) {
		global $data, $conf, $lang, $misc;

		// Check params
		if (!$this->setParams($params)) {
			return $this->error_callback($lang['strinsufficientparams'])
		}

		// Run query
		$this->result = $data->browseQuery($this->type,
			$this->object,
			$this->sql,
			$this->sort_key,
			$this->sort_dir,
			$conf['max_rows'],
			$this->max_pages);
		if (!is_object($this->result)) {
			return $this->error_callback('');
		}

		// Save query to history, if necessary
		if ($this->options['save_history'] && $type === QUERY_TYPE_QUERY) {
			$misc->saveScriptHistory($this->sql);
		}

		// Prep for display
		$this->buildLinkParams();

		// Display
		$this->displayQueryForm();
		$this->displayPagination();
		// $this->displayTable();
		$this->displayPagination();
		// $this->displayActionLinks();
	}

	/**
	 * Builds the parameters we'll be adding to every link in the output
	 */
	private function buildLinkParams() {
		foreach ($this->context as $k => $v) {
			$this->link_params[$k] = $v;
		}
		if ($this->sort_key !== null) {
			$this->link_params['sort_key'] = $this->sort_key;
		}
		if ($this->sort_dir !== null) {
			$this->link_params['sort_dir'] = $this->sort_dir;
		}
	}

	/**
	 * Displays the textarea with the query inside
	 */
	private function displayQueryForm() {
		$this->write('<form method="POST" action="', $this->buildUrl(), '">');
		$this->write('<textarea width="90%" name="query" rows="5" cols="100" resizable="true">');
		if ($this->type === self::QUERY_TYPE_TABLE) {
			$query = 'SELECT * FROM ' . pg_escape_identifier($this->schema) . '.' . pg_escape_identifier($this->table) . ';';
		} else {
			$query = $this->sql;
		}
		$this->write(htmlspecialchars($query));
		$this->write('</textarea>');
		$this->write('<br>');
		$this->write('<input type="submit"/>');
		$this->write('</form>');
	}

	/**
	 * Builds a url from the context
	 *
	 * @param array (optional) Any extra or replacement parameters for the query string
	 * @return string The url
	 */
	 private function buildUrl(array $extra = array()) {
	 	$params = array_merge($extra, $this->context['params']);
	 	return $this->context['url'] . '?' . http_build_query($params);
	 }

	/**
	 * Merges params (context + settings) for links, plus whatever we're adding or removing
	 *
	 * @return array the merged params
	 */
	 private function mergeParams(array $extra = array()) {
		$ours = array(
			'sortkey' => $this->sort_key,
			'sortdir' => $this->sort_dir,
			'strings' => $this->options['strings'],
		);
		if ($this->search_path) {
			$ours['search_path'] = $this->search_path;
		}
		if (!$this->options['save_history']) {
			$ours['nohistory'] = 't';
		}
	 	return array_merge($extra, $ours, $this->context['params']);
	 }

	// context:
	//   --- always: server, database
	//   --- if present: schema, subject, {object}, return
	//   --- might require munging: query, table/{object}
	//   --- unrelated but needs carrying: action

	// from here:
	//   --- direct use: sortkey, sortdir, page, strings

	/**
	 * Displays the page navigation for queries that return multiple pages
	 */
	private function displayPagination() {
		global $lang;

		if ($this->pagination_buffer === null) {
			if ($this->page < 0 || $this->page > $this->max_pages) return;
			if ($this->max_pages < 0) return;
			if ($this->options['pagination_window'] <= 0) return;

			// Begin
			$this->pagination_buffer = '<p style="text-align: center">' . "\n";

			// First/previous
			if ($this->page != 1) {
				$this->pagination_buffer .= '<a class="pagenav" href="' .
					$this->buildUrl(array('page' => 1)) . '>' . $lang['strfirst'] . '</a>' . "\n";
				$temp = $this->page - 1;
				$this->pagination_buffer .= '<a class="pagenav" href="' .
					$this->buildUrl(array('page' => $temp)) . '>' . $lang['strprev'] . '</a>' . "\n";
			}

			// Window
			$window = $this->options['pagination_window'];
			if ($this->page <= $window) {
				$min_page = 1;
				$max_page = min(2 * $window, $this->max_pages);
			}
			elseif ($this->page > $window && $this->max_pages >= $this->page + $window) {
				$min_page = ($this->page - $window) + 1;
				$max_page = $this->page + $window;
			}
			else {
				$min_page = ($this->page - (2 * $window - ($this->max_pages - $this->page))) + 1;
				$max_page = $this->max_pages;
			}

			// Make sure min_page is always at least 1
			// and max_page is never greater than $this->max_pages
			$min_page = max($min_page, 1);
			$max_page = min($max_page, $this->max_pages);

			for ($i = $min_page; $i <= $max_page; $i++) {
				if ($i != $this->page) {
					$this->pagination_buffer .= '<a class="pagenav" href="' .
						$this->buildUrl(array('page' => $i)) . '>' . $i . '</a>' . "\n";
				}
				else {
					$this->pagination_buffer .= "$i\n";
				}
			}

			// Next/Last
			if ($this->page != $this->max_pages) {
				$temp = $this->page + 1;
				$this->pagination_buffer .= '<a class="pagenav" href="' .
					$this->buildUrl(array('page' => $temp)) . '>' . $lang['strnext'] . '</a>' . "\n";
				$this->pagination_buffer .= '<a class="pagenav" href="' .
					$this->buildUrl(array('page' => $this->max_pages)) . '>' . $lang['strlast'] . '</a>' . "\n";
			}
			$this->pagination_buffer .= "</p>\n";
		}

		$this->write($this->pagination_buffer);
	}

	/**
	 * Displays the table with all our data
	 */
	private function displayTable() {

		$this->write('<table id="data">', "\n");
		$this->write('<tr>', "\n");

        // TODO: Continue from here

		// Check that the key is actually in the result set.  This can occur for select
		// operations where the key fields aren't part of the select.  XXX:  We should
		// be able to support this, somehow.
		$key = $this->key;
		foreach ($this->key as $v) {
			// If a key column is not found in the record set, then we
			// can't use the key.
			if (!in_array($v, array_keys($this->result->fields))) {
				$key = array();
				break;
			}
		}

		$plugin_manager->do_hook('actionbuttons', array(
			'actionbuttons' => &$action_buttons,
			'place' => $self->options['plugin_place'],
		));

		foreach (array_keys($action_buttons) as $action) {
			$action_buttons[$action]['attr']['href']['urlvars'] = array_merge(
				$action_buttons[$action]['attr']['href']['urlvars'],
				$_gets
			);
		}

		$edit_params = isset($action_buttons['edit'])?
			$action_buttons['edit']:array();
		$delete_params = isset($action_buttons['delete'])?
			$action_buttons['delete']:array();

		// Display edit and delete actions if we have a key
		$colspan = count($action_buttons);
		if ($colspan > 0 and count($key) > 0)
			echo "<th colspan=\"{$colspan}\" class=\"data\">{$lang['stractions']}</th>\n";

		// we show OIDs only if we are in TABLE or SELECT type browsing
		printTableHeaderCells($rs, $_gets, isset($object));

		echo "</tr>\n";

		$i = 0;
		reset($rs->fields);
		while (!$rs->EOF) {
			$id = (($i % 2) == 0 ? '1' : '2');
			echo "<tr class=\"data{$id}\">\n";
			// Display edit and delete links if we have a key
			if ($colspan > 0 and count($key) > 0) {
				$keys_array = array();
				$has_nulls = false;
				foreach ($key as $v) {
					if ($rs->fields[$v] === null) {
						$has_nulls = true;
						break;
					}
					$keys_array["key[{$v}]"] = $rs->fields[$v];
				}
				if ($has_nulls) {
					echo "<td colspan=\"{$colspan}\">&nbsp;</td>\n";
				} else {

					if (isset($action_buttons['edit'])) {
						$action_buttons['edit'] = $edit_params;
						$action_buttons['edit']['attr']['href']['urlvars'] = array_merge(
							$action_buttons['edit']['attr']['href']['urlvars'],
							$keys_array
						);
					}

					if (isset($action_buttons['delete'])) {
						$action_buttons['delete'] = $delete_params;
						$action_buttons['delete']['attr']['href']['urlvars'] = array_merge(
							$action_buttons['delete']['attr']['href']['urlvars'],
							$keys_array
						);
					}

					foreach ($action_buttons as $action) {
						echo "<td class=\"opbutton{$id}\">";
						$misc->printLink($action);
						echo "</td>\n";
					}
				}
			}

			print printTableRowCells($rs, $fkey_information, isset($object));

			echo "</tr>\n";
			$rs->moveNext();
			$i++;
		}
		echo "</table>\n";

		echo "<p>", $rs->recordCount(), " {$lang['strrows']}</p>\n";
	}

    /**
     * Gets the action buttons for a given row
     *
     * @param array row
     * @return array the buttons, suitable for printLink()
     */
    private function getActionButtons() {
        global $lang;
		return array(
			'edit' => array (
				'content' => $lang['stredit'],
				'attr'=> array (
					'href' => array (
						'url' => $this->context['url'],
						'urlvars' => array_merge(array (
							'action' => 'confeditrow',
							'strings' => $this->options['strings'],
							'page' => $this->page,
						), $this->context['params'])
					)
				)
			),
			'delete' => array (
				'content' => $lang['strdelete'],
				'attr'=> array (
					'href' => array (
						'url' => $this->context['url'],
						'urlvars' => array_merge(array (
							'action' => 'confdelrow',
							'strings' => $this->options['strings'],
							'page' => $this->page,
						), $this->context['params'])
					)
				)
			),
		);
    }


/*

// TODO: Goes outside
$misc->printTrail(isset($subject) ? $subject : 'database');
$misc->printTabs($subject,'browse');
if (isset($object)) {
	if (isset($_REQUEST['query'])) {
		$_SESSION['sqlquery'] = $_REQUEST['query'];
		$misc->printTitle($lang['strselect']);
		$type = 'SELECT';
	}
	else {
		$type = 'TABLE';
	}
} else {
	$misc->printTitle($lang['strqueryresults']);
	//we comes from sql.php, $_SESSION['sqlquery'] has been set there
	$type = 'QUERY';
}
$misc->printMsg($msg);

// TODO: Figure out later
// This code is used when browsing FK in pure-xHTML (without js)
if (isset($_REQUEST['fkey'])) {
	$ops = array();
	foreach($_REQUEST['fkey'] as $x => $y) {
		$ops[$x] = '=';
	}
	$query = $data->getSelectSQL($_REQUEST['table'], array(), $_REQUEST['fkey'], $ops);
	$_REQUEST['query'] = $query;
}
$fkey_information =& getFKInfo();

// TODO: Does this code go here?


		if (is_object($rs) && $rs->recordCount() > 0) {

			// Show page navigation
			$misc->printPages($_REQUEST['page'], $max_pages, $_gets);
		}
		else echo "<p>{$lang['strnodata']}</p>\n";


*/


}

?>
