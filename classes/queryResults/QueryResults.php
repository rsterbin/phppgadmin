<?php

require_once(__DIR__ . './Plain.php');
require_once(__DIR__ . './Browse.php');
require_once(__DIR__ . './Script.php');

// TODO: Figure out whether standard error handling for queries is okay here,
// or if we need to catch them and do something more specific

/**
 * Run a query and display data
 *
 * This should allow us to move the functionality of sql.php and display.php out
 * of files that can be accessed directly and protect each specific form post
 * with a single-use token.
 *
 * @see QueryResults
 *
 * $Id$
 */
class QueryResults {

	const METHOD_PLAIN = 'PLAIN';
	const METHOD_BROWSE = 'BROWSE';
	const METHOD_SCRIPT = 'SCRIPT';

	private $ready = false;
	private $started = false;
	private $completed = false;
	private $start_time = null;
	private $output_stream = null;

	private $sql = null;
	private $context = array(
		'url' => '',
		'params' => array(),
		'other' => array();
	);

	private $result = array(
		'success' => null,
		'error' => null,
		'duration' => null,
	);

	private $method = self::METHOD_PLAIN;

	private $options = array(
		'token' => array(
			'disabled' => false,
			'identifier' => 'general',
			'value' => null,
		),
		'time_execution' => true,
		'search_path' => null,
		'output_stream' => null,
		'error_callback' => null,
		'worker' => array(),
	);

	/**
	 * Constructor
	 *
	 * @param string The worker method (one of the METHOD_* class constants)
	 * @param array The current location within the site (keys "url" and "params" are treated as such; any other keys will be stored as "other")
	 * @param array (optional) Any options for the bits around the worker, like csrf token, timing, and error handling
	 */
	public function __construct(string $method, array $context, array $options = array()) {
		$this->method = $method;
		$this->context['url'] = is_string($context['url']) ? $context['url'] : '';
		$this->context['params'] = is_array($context['params']) ? $context['params'] : array();
		foreach (array_keys($context) as $k) {
			if ($k === 'url' || $k === 'params') {
				continue;
			}
			$this->context['other'][$k] = $context[$k];
		}
		$this->setOptions($options);
	}

	/**
	 * Sets the options, ignoring invalid ones
	 *
	 * @param array The user-provided options
	 */
	private function setOptions(array $options) {
		if (isset($options['token']) && is_array($options['token'])) {
			$this->options['token'] = $options['token'];
		}
		if (isset($options['time_execution'])) {
			$this->options['time_execution'] = $options['time_execution'] ? true : false;
		}
		if (isset($options['output_stream']) && is_resource($options['output_stream'])
			&& get_resource_type($options['output_stream']) === 'stream') {
			$this->options['output_stream'] = $options['output_stream'];
		}
		if (isset($options['error_callback']) && is_callable($options['error_callback'])) {
			$this->options['error_callback'] = $options['error_callback'];
		}
		if ($this->method === self::METHOD_PLAIN) {
			$this->options['worker'] = QRPlain::filterOptions($options);
		}
		elseif ($this->method === self::METHOD_SCRIPT) {
			$this->options['worker'] = QRScript::filterOptions($options);
		}
		elseif ($this->method === self::METHOD_BROWSE) {
			$this->options['worker'] = QRBrowse::filterOptions($options);
		}
	}

	/**
	 * Runs the query or queries as requested
	 *
	 * @param array The parameters necessary to run the query or queries
	 * @return array The result; keys are "success" (boolean), "error" (string), and "duration" (string containing duration in milliseconds to three decimal points)
	 */
	public function run($params) {
		global $misc;

		// Go to starting state
		$this->started = true;
		$this->completed = false;
		$this->result['success'] = null;
		$this->result['error'] = null;
		$this->output_stream = $this->options['output_stream'] || fopen('php://output', 'w');

		if (!$this->checkToken()) {
			return $this->setError($lang['strbadcsrftoken']);
		}

		if ($this->options['search_path']) {
			$paths = array_map('trim', explode(',', $this->options['search_path']));
			$ok = $data->setSearchPath($paths);
			if ($ok != 0) {
				return $this->setError($lang['strbadsearchpath']);
			}
		}

		$this->startTiming();
		$ecb = array($this, 'setError');
		switch ($this->method) {
			case self::METHOD_PLAIN:
				$worker = new QRPlain($this->output_stream, $ecb, $this->mergeContext());
				break;
			case self::METHOD_BROWSE:
				$worker = new QRBrowse($this->output_stream, $ecb, $this->mergeContext());
				break;
			case self::METHOD_SCRIPT:
				$worker = new QRScript($this->output_stream, $ecb, $this->mergeContext());
				break;
			default:
				return $this->setError($lang['strqueryerror']);
		}
		$ok = $worker->run($params);
		if (!$ok) {
			$this->result['success'] = false;
		}

		$this->stopTiming();
		return $this->result;
	}

	/**
	 * Merges the user-provided context with the settings we use at this level
	 *
	 * @return array the merged context
	 */
	private function mergeContext() {
		$ours = array(
			'search_path' => $this->options['search_path'],
		);
		return array_merge($ours, $this->context);
	}

	/**
	 * Starts timing the query/queries
	 */
	private function startTiming() {
		if (!$this->options['time_execution']) {
			return;
		}
		if (function_exists('microtime')) {
			list($usec, $sec) = explode(' ', microtime());
			$this->start_time = ((float)$usec + (float)$sec);
		} else {
			$this->start_time = null;
		}
	}

	/**
	 * Stops timing the query/queries
	 */
	private function stopTiming() {
		if (!$this->options['time_execution']) {
			return;
		}
		if ($this->start_time !== null) {
			list($usec, $sec) = explode(' ', microtime());
			$end_time = ((float)$usec + (float)$sec);
			// Get duration in milliseconds, round to 3dp's
			$this->result['duration'] = number_format(($end_time - $start_time) * 1000, 3);
		}
	}

	/**
	 * Checks the token
	 *
	 * @return boolean Whether it's okay to proceed
	 */
	private function checkToken() {
		if ($this->options['token']['disabled'] === false) {
			$id = $this->options['token']['identifier'];
			$val = $this->options['token']['value'];
			if ($val === null) {
				return $misc->validateCsrfToken($id);
			}
			return $misc->manuallyValidateCsrfToken($val, $id);
		}
		return true;
	}

	/**
	 * Sets the result as an error
	 *
	 * Also calls the error callback, if applicable
	 *
	 * @param string The error message
	 * @param array The full result
	 */
	private function setError($msg) {
		$this->result['success'] = false;
		$this->result['error'] = $msg;
		if (is_callable($this->options['error_callback'])) {
			$cb = $this->options['error_callback'];
			$cb($msg, $this->mergeContext());
		}
		return $this->result;
	}

}

?>
