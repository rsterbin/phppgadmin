<?php

/**
 * Session storage and handling for CSRF tokens
 *
 * $Id$
 */
class TokenStorage {

    private $session_key = '_TokenStorage_csrf_tokens';

    /**
     * Contstructor: starts with an empty set of tokens
     */
    public function __construct() {
        if (!isset($_SESSION[$this->session_key])) {
            $_SESSION[$this->session_key] = array();
        }
    }

    /**
     * Gets the token for a particular form, or creates it if it's missing
     *
     * @param string An identifier for the form
     * @param integer The timeout, in seconds
     * @param boolean Whether this token is single-use
     * @return string The generated token
     */
    public function get($identifier, $timeout, $single) {
print '<pre style="color: green;">' . "\n"; var_dump($_SESSION[$this->session_key]); print '</pre>' . "\n";
        $identifier = trim("$identifier");
        if (!is_numeric("$timeout")) {
            $timeout = 1800; // default to 30 minutes
        }
        $single = ($single ? true : false);
        $expires = time() + intval($timeout);

        // If there's a token for this form already that's still usable (and
        // has a matching single-use flag), bump up the timeout and use that
        foreach ($_SESSION[$this->session_key] as $i => $t) {
            if ($this->belongsTo($identifier, $t)
                && $this->usable($t)
                && $t['single'] === $single) {
                $_SESSION[$this->session_key][$i]['expires'] = $expires;
                return $t['token'];
            }
        }

        // Otherwise, create one
        $token = bin2hex(random_bytes(32));
        $_SESSION[$this->session_key][] = array(
            'token' => $token,
            'identifier' => $identifier,
            'expires' => $expires,
            'single' => $single,
            'used' => false,
        );
        return $token;
    }

    /**
     * Returns whether a stored token is still usable
     *
     * @param array The stored token info
     * @return boolean Whether the token is still usable
     */
    private function usable($tokenInfo) {
        if ($tokenInfo['expires'] < time()) {
            return false;
        }
        if ($tokenInfo['single'] && $tokenInfo['used']) {
            return false;
        }
        return true;
    }

    /**
     * Returns whether a stored token belongs to the identifier
     *
     * @param string An identifier for the form
     * @param array The stored token info
     * @return boolean Whether the token is still usable
     */
    private function belongsTo($identifier, $tokenInfo) {
        $identifier = trim($identifier);
        if ($tokenInfo['identifier'] !== $identifier) {
            return false;
        }
        return true;
    }

    /**
     * Returns whether a stored token matches a given token
     *
     * @param string The token passed in with the submitted form
     * @param array The stored token info
     * @return boolean Whether the token is still usable
     */
    private function matches($token, $tokenInfo) {
        return hash_equals($tokenInfo['token'], $token);
    }

    /**
     * Validates a token
     *
     * @param string An identifier for the form
     * @param string The token passed in with the submitted form
     * @return boolean Whether the token is valid
     */
    public function validate($identifier, $token) {
print '<pre style="color: blue;">' . "\n"; var_dump($_SESSION[$this->session_key]); print '</pre>' . "\n";
        $found = -1;
        foreach ($_SESSION[$this->session_key] as $i => $t) {
            if ($this->matches($token, $_SESSION[$this->session_key][$i])
                && $this->belongsTo($identifier, $t)
                && $this->usable($t)) {
                $found = $i;
                break;
            }
        }
        if ($found < 0) {
            return false;
        }
        $_SESSION[$this->session_key][$found]['used'] = true;
        return true;
    }

    /**
     * Cleans out any expired tokens
     */
    public function cleanup() {
print '<pre style="color: purple;">' . "\n"; var_dump($_SESSION[$this->session_key]); print '</pre>' . "\n";
        foreach ($_SESSION[$this->session_key] as $i => $t) {
            if (!$this->usable($t)) {
                unset($_SESSION[$this->session_key][$i]);
                continue;
            }
        }
        $_SESSION[$this->session_key] = array_values($_SESSION[$this->session_key]);
print '<pre style="color: magenta;">' . "\n"; var_dump($_SESSION[$this->session_key]); print '</pre>' . "\n";
    }

    /**
     * Returns a list of all stored tokens
     *
     * @return array the token list
     */
    public function getAll() {
        return $_SESSION[$this->session_key];
    }

}

?>
