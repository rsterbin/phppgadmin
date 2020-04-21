<?php
	/**
	 * Does an export of a database, schema, or table (via pg_dump)
	 * to the screen or as a download.
	 *
	 * $Id: dbexport.php,v 1.22 2007/03/25 03:15:09 xzilla Exp $
	 */

	// Prevent timeouts on large exports (non-safe mode only)
	if (!ini_get('safe_mode')) set_time_limit(0);

	// Include application functions
	$_no_output = true;
	$f_schema = $f_object = '';
	include_once('./libraries/lib.inc.php');

    // Include the passthrough exporter
	include_once('./classes/Export/Passthrough.php');

	// Find all the options we'll be passing in
    $options = array(
        'server_id' => isset($_REQUEST['server']) ? $_REQUEST['server'] : null,
        'cluster_wide' => (isset($_REQUEST['subject']) && $_REQUEST['subject'] == 'server'),
        'database' => isset($_REQUEST['database']) ? $_REQUEST['database'] : null,
        'schema' => isset($_REQUEST['schema']) ? $_REQUEST['schema'] : null,
        'subject' => isset($_REQUEST['subject']) ? $_REQUEST['subject'] : null,
        'object' => isset($_REQUEST['subject']) ? $_REQUEST[$_REQUEST['subject']] : null,
        'format' => isset($_REQUEST['d_format']) ? $_REQUEST['d_format'] :
            isset($_REQUEST['sd_format']) ? $_REQUEST['sd_format'] : null,
        'identifiers' => isset($_REQUEST['d_oids']) || isset($_REQUEST['sd_oids']),
        'drop' => isset($_REQUEST['s_clean']) || isset($_REQUEST['sd_clean']),
        'output' => isset($_REQUEST['output']) ? $_REQUEST['output'] : null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'],
        'ssl' => isset($_SERVER['HTTPS']),
        'containing' => isset($_REQUEST['what']) ? $_REQUEST['what'] : null,
    );

    // Create the exporter
    $exporter = new Export_Passthrough($options);
    if (!$exporter->canProceed()) {
        print $exporter->getErrorMessage();
        exit;
    }

    // Run the passthrough export
    $exporter->run();

?>
