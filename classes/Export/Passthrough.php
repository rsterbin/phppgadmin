<?php

/**
 * Database export via passthrough call to pg_dump
 *
 * $Id$
 */
class Export_Passthrough {

    private $ok = true;
    private $msg;
    private $executable_path;
    private $options = array(
        'server_id' => null,
        'cluster_wide' => false,
        'database' => null,
        'schema' => null,
        'subject' => null,
        'object' => null,
        'format' => 'copy',
        'identifiers' => false,
        'drop' => false,
        'output' => 'show',
        'output_filename' => 'dump.sql',
        'user_agent' => null,
        'ssl' => null,
        'containing' => 'dataonly',
    );

    /**
     * Contstructor: intitializes settings
     *
     * @param array $options the settings for this export (see class object for defaults)
     */
    public function __construct($options) {
        $this->setOptions($options);
        $this->verify();
    }

    /**
     * Sets the options from the ones provide by the user
     *
     * Note that this method will invalidate the export if the options passed in
     * are not usable.
     *
     * @param  array   $userdata the user-provided options
     * @return boolean whether the options were set without invalidating
     */
    private function setOptions($userdata) {
        global $lang;

        // Check that the options provided are reasonable
        // server_id will be checked by Misc
        if (isset($userdata['server_id'])) {
            $this->options['server_id'] = $userdata['server_id'];
        }

        // cluster_wide will be evaluated as a boolean
        if (isset($userdata['cluster_wide'])) {
            $this->options['cluster_wide'] = $userdata['cluster_wide'] ? true : false;
        }

        // database should be a valid PostgreSQL database name, but we don't
        // want to go through a rigorous validation process when pg_dump will
        // do that for us.  Instead, we want to be sure it's something that we
        // can set as an environment variable (has no newline or return char and
        // does not contain BOTH a single and double quote).
        if (isset($userdata['database'])) {
            if ($this->okayToUseAsEnvVar($userdata['database'])) {
                $this->options['database'] = $userdata['database'];
            }
            else {
                return $this->invalidate(sprintf($lang['strbaddatabase']));
            }
        }

        // schema is passed via the command line and properly escaped, so we can
        // let postgres deal with it
        if (isset($userdata['schema'])) {
            $this->options['schema'] = $userdata['schema'];
        }

        // subject must be one of 'server', 'database', 'schema', 'table', or
        // 'view' -- in other contexts, allowable values also include
        // 'aggregate', 'column', 'sequence', 'function', 'role', and 'root';
        // however, this method cannot actually provide those exports, so we reject them.
        if (isset($userdata['subject'])) {
            $test = strtolower($userdata['subject']);
            switch ($test) {
                case 'server':
                case 'database':
                case 'schema':
                case 'table':
                case 'view':
                    $this->options['subject'] = $test;
                    break;
                default:
                    return $this->invalidate(sprintf($lang['strbadexportsubject'], $test));
            }
        }

        // object (contains a table or view name) is passed via the command line
        // and properly escaped, so we can let postgres deal with it
        if (isset($userdata['object'])) {
            $this->options['object'] = $userdata['object'];
        }   

        // format must be one of 'copy' or 'sql'
        if (isset($userdata['format'])) {
            $test = strtolower($userdata['format']);
            switch ($test) {
                case 'copy':
                case 'sql':
                    $this->options['format'] = $test;
                    break;
                default:
                    return $this->invalidate(sprintf($lang['strbadexportformat'], $test));
            }
        }

        // identifiers will be evaluated as a boolean
        if (isset($userdata['identifiers'])) {
            $this->options['identifiers'] = $userdata['identifiers'] ? true : false;
        }

        // drop will be evaluated as a boolean
        if (isset($userdata['drop'])) {
            $this->options['drop'] = $userdata['drop'] ? true : false;
        }

        // output must be one of 'show', 'download', or 'gzipped'
        if (isset($userdata['output'])) {
            $test = strtolower($userdata['output']);
            switch ($test) {
                case 'show':
                case 'download':
                case 'gzipped':
                    $this->options['output'] = $test;
                    break;
                default:
                    return $this->invalidate(sprintf($lang['strbadexporttype'], $test));
            }
        }

        // user_agent can be passed in or retrieved from $_SERVER
        if (isset($userdata['user_agent'])) {
            $this->options['user_agent'] = $userdata['user_agent'];
        } else {
            $this->options['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
        }

        // ssl can be passed in or retrieved from $_SERVER
        if (isset($userdata['ssl'])) {
            $this->options['ssl'] = $userdata['ssl'] ? true : false;
        } else {
            $this->options['ssl'] = isset($_SERVER['HTTPS']);
        }

        // output_filename should use a set of characters that we can guarantee will work in all browsers we support
        if (isset($userdata['output_filename'])) {
            if (preg_match("/^[a-zA-Z0-9._\\-+@$~!'=()\\[\\]{}]+$/", $userdata['output_filename'])) {
                $this->options['output_filename'] = $userdata['output_filename'];
            } else {
                return $this->invalidate(sprintf($lang['strbadexportname'], $userdata['output_filename']));
            }
        } else {
            if ($this->options['output'] == 'gzipped') {
                $this->options['output_filename'] = $this->options['output_filename'] . '.gz';
            }
        }

        return true;
    }

    /**
     * Verifies that we have the necessary settings to run the passthrough call
     * to pg_dump
     *
     * @return boolean whether the verification was a success
     */
    private function verify() {
        global $misc, $lang;

        // Check that we have the appropriate pg_dump/pg_dumpall path
        if (!$misc->isDumpEnabled($this->options['cluster_wide'])) {
            return $this->invalidate($lang['strnodumps']);
        }

        // Check that the command exists and is usable
		$this->server_info = $misc->getServerInfo($this->options['server_id']);

		// Get the path of the pg_dump/pg_dumpall executable
		$this->executable_path = $misc->escapeShellCmd($this->server_info[$this->options['cluster_wide'] ? 'pg_dumpall_path' : 'pg_dump_path']);

		// Obtain the pg_dump version number and check if the path is good
		$this->executable_version = array();
        $matches = array();
		preg_match("/(\d+(?:\.\d+)?)(?:\.\d+)?.*$/", exec($this->executable_path . " --version"), $matches);
		if (empty($matches)) {
			if ($this->options['cluster_wide']) {
				return $this->invalidate(sprintf($lang['strbadpgdumpallpath'], $this->server_info['pg_dumpall_path']));
			}
            else {
				return $this->invalidate(sprintf($lang['strbadpgdumppath'], $this->server_info['pg_dump_path']));
			}
		} else {
            $this->executable_version = (float) $matches[1];
        }

        return true;
    }

    /**
     * Marks the object as invalid and sets an error message
     *
     * @param  string  $msg the error message
     * @return boolean always false
     */
    private function invalidate($msg = '') {
        $this->ok = false;
        $this->msg = $msg;
        return false;
    }

    /**
     * Returns whether a given string can safely be provided as the value in an
     * environement variable
     *
     * It's unclear whether newlines will work as expected, and since we use
     * single quotes when the value contains a double quote and vice versa, we
     * can't properly handle both in the value.
     *
     * @param  string  $given the given value
     * @return boolean whether we can set it in an environment variable
     */
    private function okayToUseAsEnvVar($given) {
        if (preg_match('/\n/', $given)
            || (
                preg_match('/"/', $given)
                && preg_match("/'/", $given)
            )) {
                return false;
        }
        return true;
    }

    /**
     * Finds the appropriate headers for the export
     *
     * @return array the headers
     */
    public function getHeaders() {
        $headers = array();
        if ($this->options['output'] == 'download' || $this->options['output'] == 'gzipped') {
            // MSIE is totally broken for SSL downloading, so we need to have it
            // download in-place as plain text
            if (strstr($this->options['user_agent'], 'MSIE') && $this->options['ssl']) {
                $headers[] = 'Content-Type: text/plain';
            }
            else {
                $headers[] = 'Content-Type: application/download';
                $headers[] = sprintf('Content-Disposition: attachment; filename=%s', $this->options['output_filename']);
            }
        } else {
            $headers[] = 'Content-Type: text/plain';
        }
        return $headers;
    }

    /**
     * Finds the appropriate environment variables for the export
     *
     * @return array the headers
     */
    public function getEnvVars() {
        $env_vars = array();
		$env_vars[] = 'PGPASSWORD=' . $this->server_info['password'];
		$env_vars[] = 'PGUSER=' . $this->server_info['username'];
		$hostname = $this->server_info['host'];
		if ($hostname !== null && $hostname != '') {
			$env_vars[] = 'PGHOST=' . $hostname;
		}
		$port = $this->server_info['port'];
		if ($port !== null && $port != '') {
			$env_vars[] = 'PGPORT=' . $port;
		}
        if (!$this->options['cluster_wide']) {
            if (preg_match('/^[a-zA-Z0-9_]+$/', $this->options['database'])) {
                $env_vars[] = 'PGDATABASE=' . $this->options['database'];
            } else {
                $env_vars[] = 'PGDATABASE="' . $this->options['database'] . '"';
            }
        }
        return $env_vars;
    }

    /**
     * Finds the flags and returns the command to use
     *
     * @return string the command
     */
    public function getCommand() {

        // Build command for executing pg_dump/pg_dumpall
        $cmd = $this->executable_path;

        // NB: we are PG 7.4+, so we always have a schema
        $f_schema = '';
        if ($this->options['schema']) {
            $f_schema = $this->options['schema'];
            $data->fieldClean($f_schema);
        }

        // Check for a specified table/view
        switch ($this->options['subject']) {
            case 'schema':
                // This currently works for 8.2+ (due to the orthoganl -t -n issue introduced then)
                $cmd .= " -n " . $misc->escapeShellArg("\"{$f_schema}\"");
                break; 
            case 'table':
            case 'view':
                $f_object = $this->options['object'];
                $data->fieldClean($f_object);

                // Starting in 8.2, -n and -t are orthogonal, so we now schema qualify
                // the table name in the -t argument and quote both identifiers
                if ( ((float) $this->executable_version[1]) >= 8.2 ) {
                    $cmd .= " -t " . $misc->escapeShellArg("\"{$f_schema}\".\"{$f_object}\"");
                }
                else {
                    // If we are 7.4 or higher, assume they are using 7.4 pg_dump and
                    // set dump schema as well.  Also, mixed case dumping has been fixed
                    // then..
                    $cmd .= " -t " . $misc->escapeShellArg($f_object)
                        . " -n " . $misc->escapeShellArg($f_schema);
                }
                break; 
        }

        // Check for GZIP compression specified
        if ($this->options['output'] == 'gzipped' && !$this->options['cluster_wide']) {
            $cmd .= " -Z 9";
        }

        switch ($this->options['containing']) {
            case 'dataonly':
                $cmd .= ' -a';
                if ($this->options['format'] == 'sql') $cmd .= ' --inserts';
                elseif ($this->options['identifiers']) $cmd .= ' -o';
                break;
            case 'structureonly':
                $cmd .= ' -s';
                if ($this->options['drop']) $cmd .= ' -c';
                break;
            case 'structureanddata':
                if ($this->options['format'] == 'sql') $cmd .= ' --inserts';
                elseif ($this->options['identifiers']) $cmd .= ' -o';
                if ($this->options['drop']) $cmd .= ' -c';
                break;
        }

        return $cmd;
    }

    /**
     * Returns whether the export can proceed with the settings provided
     *
     * @return boolean whether we're set up correctly
     */
    public function canProceed() {
        return $this->ok;
    }

    /**
     * Returns an error message, if one is set
     *
     * @return string the error message, or null if there isn't one
     */
    public function getErrorMessage() {
        return $this->msg;
    }

    /**
     * Runs the export as requested
     *
     * @return boolean whether the run happened
     */
    public function run() {
        $hdr = $this->getHeaders();
        foreach ($hdr as $h) {
            header($h);
        }
        $env = $this->getEnvVars();
        foreach ($env as $e) {
            putenv($e);
        }
        $cmd = $this->getCommand();
		passthru($cmd);
    }

}

?>
