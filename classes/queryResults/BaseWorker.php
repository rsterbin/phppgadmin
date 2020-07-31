<?php

/**
 * This is a base class for workers that do the actual running of queries
 *
 * @see QueryResults
 *
 * $Id$
 */
abstract class QRBaseWorker {

    protected $context = array();
    protected $output_stream = null;
    protected $error_callback = null;
    protected $options = array();

    /**
     * Constructor
     *
     * @param resource The output stream to print results to
     * @param callable The method to call in case of error
     * @param array The context (url+params)
     * @param array (optional) Any options for the worker, pre-filtered
     */
    public function __construct($output_stream, callable $error_callback, array $context, array $options = array()) {
        $this->output_stream = $output_stream;
        $this->error_callback = $error_callback;
        $this->context = $context;
        $this->options = $options;
    }

    /**
     * Filters options coming from the QueryResults class
     *
     * @param array The options
     * @return array The options, filtered appropriately
     */
    abstract public static function filterOptions(array $options);

	/**
	 * Runs the query and build the response
	 *
	 * @param array (optional) The parameters provided by the end user
	 * @return boolean Whether the request was successful
	 */
    abstract public function run(array $params = array());

	/**
	 * Writes to the output handler
	 *
	 * @param string* Any number of strings (or other scalar values) are allowed as arguments
	 */
    protected function write() {
        $args = func_get_args();
        foreach ($args as $val) {
            fwrite($this->output_stream, $val);
        }
    }

}

?>
