<?php namespace AJenbo;

/**
 * @license  GPLv2 http://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://github.com/AJenbo/PHP-imap
 */

class Imap
{
    //TODO Process all responces from responce
    //TODO Handle process each line as it is fetched instead of expecting specefic responces
    //TODO show error for * NO Invalid message sequence number: 1

    public $capabilities = [];

    private $host = '';
    private $port = 143;
    private $user = '';
    private $password = '';
    private $socket = null;
    private $tag = 0;
    private $selected = false;

    /**
     * Set up vars and initiate call connect()
     *
     * @param string $user     A valid username.
     * @param string $password A valid password.
     * @param string $host     Server to connect to.
     * @param int    $port     Default is 143.
     */
    public function __construct($user, $password, $host, $port = 143)
    {
        $this->host = $host;
        $this->port = $port;
        $this->user = $user;
        $this->password = $password;

        $this->connect();
    }

    /**
     * Close the connection
     */
    public function __destruct()
    {
        $this->writeLine('LOGOUT');
        @fclose($this->socket);
    }

    /**
     * Open a connection to the server and authenticate
     *
     * @return null
     */
    private function connect()
    {
        $this->socket = stream_socket_client(
            $this->host . ':' . $this->port,
            $errno,
            $errstr,
            30
        );
        if (!$this->socket) {
            throw new Exception($errstr . ' (' . $errno . ')');
        }
        stream_set_blocking($this->socket, 1);

        $this->responce();
        $this->capability();
        $this->authenticate();
    }

    /**
     * Send a command to the server
     *
     * @param string $command The command to send over the wire
     * @param bool   $literal Weather this is a literal write
     *
     * @return null
     */
    private function writeLine($command, $literal = false)
    {
        if (!$literal) {
            $this->tag++;
            $command = $this->tag . ' ' . $command;
        }

        if (!@fputs($this->socket, $command . "\r\n")) {
            $error = error_get_last();
            throw new Exception($error['message']);
        }
    }

    /**
     * Retrive the full responce message from server
     *
     * @param bool $literal Weather to expect a ready for literal message
     *
     * @return array Responce from server devided in to types
     */
    private function responce($literal = false)
    {
        $responce = '';
        $return = array('message' => '', 'responce' => '', 'data' => '');
        while (true) {
            $line = fgets($this->socket);
            $stream = stream_get_meta_data($this->socket);
            if (!$stream['unread_bytes']
                && (($literal && preg_match('/^\+ /u', $line))
                || preg_match('/^[0-9*]+ (?:OK|NO|BAD)/u', $line))
            ) {
                break;
            }
            $responce .= $line;
        }

        if ($literal) {
            if (!preg_match('/^[+] /u', $line)) {
                throw new Exception($line);
            }
            return true;
        }

        $return['data'] = $responce;

        if (!preg_match('/^[0-9*]+ OK/u', $line)) {
            throw new Exception($line);
        }
        if (preg_match('/^[0-9*]+ OK \[([^\]]+)\] (.*)$/u', $line, $matches)) {
            $return['responce'] = $matches[1];
            $return['message'] = $matches[2];
        } elseif (preg_match('/^[0-9*]+ OK (.*)$/u', $line, $matches)) {
            $return['message'] = $matches[1];
        }

        return $return;
    }

    /**
     * Populate the capabilites variable with the serveres reported capabilitys
     *
     * @param string $string String to use instead of fetching from the server
     *
     * @return null
     */
    private function capability($string = '')
    {
        if (!$string) {
            $this->writeLine('CAPABILITY');
            $responce = $this->responce();
            $string = substr($responce['data'], 13);
        }

        $this->capabilities = array();

        $string = explode(' ', $string);
        foreach ($string as $capability) {
            $capability = trim($capability);
            if (strpos($capability, '=') === false) {
                if (!@$this->capabilities[$capability]) {
                    $this->capabilities[$capability] = true;
                }
            } else {
                $capability = explode('=', $capability);
                if (@$this->capabilities[$capability[0]] === true) {
                    $this->capabilities[$capability[0]] = array();
                }
                $this->capabilities[$capability[0]][$capability[1]] = true;
            }
        }
    }

    /**
     * Use most secure way to login to server
     *
     * @return null
     */
    private function authenticate()
    {
        $authenticated = false;
        if (!$authenticated) {
            $authenticated = $this->authenticatePlain();
        }
        if (!$authenticated) {
            $authenticated = $this->authenticateLogin();
        }
        if (!$authenticated) {
            $this->login();
        }
    }

    /**
     * The plain authentification methode
     *
     * @return bool True if authenticated
     */
    private function authenticatePlain()
    {
        if (!@$this->capabilities['AUTH']['PLAIN']) {
            return false;
        }

        $auth = base64_encode(chr(0) . $this->user . chr(0) . $this->password);
        $command = 'AUTHENTICATE PLAIN';

        if (@$this->capabilities['SASL-IR']) {
            $this->writeLine($command . ' ' . $auth);
        } else {
            if (@$this->capabilities['LITERAL+']) {
                $this->writeLine($command . ' {' . strlen($auth) . '+}');
            } else {
                $this->writeLine($command);
                $this->responce(true);
            }
            $this->writeLine($auth, true);
        }

        try {
            $responce = $this->responce();
            $this->capability(substr($responce['responce'], 11));
            return true;
        } catch (Exception $e) {
        }

        return false;
    }

    /**
     * The login authentification methode
     *
     * @return bool True if authenticated
     */
    private function authenticateLogin()
    {
        //TODO onc.com supports this with out saying so, should we always try it?
        if (!@$this->capabilities['AUTH']['LOGIN']) {
            return false;
        }

        $username = base64_encode($this->user);
        $password = base64_encode($this->password);
        $command = 'AUTHENTICATE LOGIN';

        if (@$this->capabilities['SASL-IR']) {
            $command = $command . ' ' . $username;
            //TODO one.com failes with this login methode and LITERAL+
            if (@$this->capabilities['LITERAL+']) {
                $this->writeLine($command . ' {' . strlen($password) . '+}');
            } else {
                $this->writeLine($command);
                $this->responce(true);
            }
        } else {
            if (@$this->capabilities['LITERAL+']) {
                $this->writeLine($command . ' {' . strlen($username) . '+}');
                $this->writeLine($username . ' {' . strlen($password) . '+}', true);
            } else {
                $this->writeLine($command);
                $this->responce(true);
                $this->writeLine($username, true);
                $this->responce(true);
            }
        }
        $this->writeLine($password, true);

        try {
            $responce = $this->responce();
            $this->capability(substr($responce['responce'], 11));
            return true;
        } catch (Exception $e) {
        }

        return false;
    }

    /**
     * The most basic authentification methode
     *
     * @return null
     */
    private function login()
    {
        $command = 'LOGIN ' . $this->user . ' ' . $this->password;
        $this->writeLine($command);

        $responce = $this->responce();
        $this->capability(substr($responce['responce'], 11));
    }

    /**
     * Keep connection alive during a period of inactivity
     * TODO get posible responce since last check.
     *
     * @return null
     */
    public function noop()
    {
        $this->writeLine('NOOP');
        $this->responce();
    }

    /**
     * Open a mailbox
     *
     * @param string $mailbox  Name of mailbox to be selected
     * @param bool   $readOnly Weather to open it in read only mode
     *
     * @return array Contaning array of flags, and other properties of the mailbox
     */
    public function select($mailbox = 'INBOX', $readOnly = false)
    {
        $mailbox = mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');

        if ($readOnly) {
            $command = 'EXAMINE "' . $mailbox . '"';
        } else {
            $command = 'SELECT "' . $mailbox . '"';
        }

        $this->writeLine($command);
        $responce = $this->responce();
        $this->selected = true;

        $return = array();

        preg_match(
            '/^\* FLAGS \(([^(]+)\)/u',
            $responce['data'],
            $matches
        );
        if ($matches) {
            $flags = array();
            foreach (explode(' ', $matches[1]) as $flag) {
                $flags[$flag] = true;
            }
            $return['flags'] = $flags;
        }

        preg_match(
            '/^\* OK \[PERMANENTFLAGS \(([^(]+)\)\]/u',
            $responce['data'],
            $matches
        );
        if ($matches) {
            $permanentflags = array();
            foreach (explode(' ', $matches[1]) as $flag) {
                $permanentflags[$flag] = true;
            }
            $return['permanentflags'] = $permanentflags;
        }

        preg_match_all(
            '/^\* ([0-9]+) (EXISTS|RECENT)/mu',
            $responce['data'],
            $matches,
            PREG_SET_ORDER
        );
        foreach ($matches as $matche) {
            $return[mb_strtolower($matche[2])] = (int) $matches[1];
        }

        preg_match_all(
            '/^\* OK \\[(UNSEEN|UIDVALIDITY|UIDNEXT|HIGHESTMODSEQ) ([0-9]+)\\]/mu',
            $responce['data'],
            $matches,
            PREG_SET_ORDER
        );
        foreach ($matches as $matche) {
            $return[mb_strtolower($matche[1])] = (int) $matches[2];
        }

        return $return;
    }

    /**
     * Create a mailbox
     *
     * @param string $mailbox Name of mailbox to create
     *
     * @return null
     */
    public function create($mailbox)
    {
        $mailbox = mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');
        $this->writeLine('CREATE "' . $mailbox . '"');
        $this->responce();
    }

    /**
     * Delete mailbox
     *
     * @param string $mailbox Name of mailbox to delete
     *
     * @return null
     */
    public function delete($mailbox)
    {
        if ($this->selected) {
            throw new Exception('Close mailbox first');
        }

        $mailbox = mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');
        $this->writeLine('DELETE "' . $mailbox . '"');
        $this->responce();
    }

    /**
     * Rename an exists mailbox
     *
     * @param string $mailbox    Name of mailbox to rename
     * @param string $mailboxNew New name for mailbox
     *
     * @return null
     */
    public function rename($mailbox, $mailboxNew)
    {
        if ($this->selected) {
            throw new Exception('Close mailbox first');
        }

        $mailbox = mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');
        $mailboxNew = mb_convert_encoding($mailboxNew, 'UTF7-IMAP', 'UTF-8');
        $this->writeLine('RENAME "' . $mailbox . '" "' . $mailboxNew . '"');
        $this->responce();
    }

    /**
     * Subscribe to a mailbox
     *
     * @param string $mailbox Name of mailbox to subscribe to
     *
     * @return null
     */
    public function subscribe($mailbox)
    {
        $mailbox = mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');
        $this->writeLine('SUBSCRIBE "' . $mailbox . '"');
        $this->responce();
    }

    /**
     * Unsubscribe from a mailbox
     *
     * @param string $mailbox Name of mailbox to unsubscribe from
     *
     * @return null
     */
    public function unsubscribe($mailbox)
    {
        $mailbox = mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');
        $this->writeLine('UNSUBSCRIBE "' . $mailbox .'"');
        $this->responce();
    }

    /**
     * Query for existing mailboxes
     *
     * @param string $mailbox Reference mailbox
     * @param string $search  Search string (see rfc3501 6.3.8)
     * @param bool   $lsub    Weather to list subscribed mailboxes
     *
     * @return array Array of mailboxes contaning array of attributes,
     *               delimiter charecter and name
     */
    public function listMailboxes($mailbox = '', $search = '*', $lsub = false)
    {
        $type = 'LIST';
        if ($lsub) {
            $type = 'LSUB';
        }

        $mailbox = mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');
        $search = mb_convert_encoding($search, 'UTF7-IMAP', 'UTF-8');
        $this->writeLine($type . ' "' . $mailbox . '" "' . $search . '"');

        $responce = $this->responce();

        preg_match_all(
            '/^\* ' . $type . ' \(([^)]*)\) "([^"]+)" "([^"]+)"/mu',
            $responce['data'],
            $matches,
            PREG_SET_ORDER
        );

        $mailboxesSort = array();
        $mailboxes = array();
        foreach ($matches as $mailbox) {
            $attributes = array();
            foreach (explode(' ', $mailbox[1]) as $attribute) {
                $attributes[$attribute] = true;
            }
            $delimiter = mb_convert_encoding($mailbox[2], 'UTF-8', 'UTF7-IMAP');
            $name = mb_convert_encoding($mailbox[3], 'UTF-8', 'UTF7-IMAP');

            $mailboxesSort[] = $name;

            $mailboxes[] = array(
                'attributes' => $attributes,
                'delimiter' => $delimiter,
                'name' => $name
            );
        }

        array_multisort($mailboxesSort, SORT_LOCALE_STRING, $mailboxes);

        return $mailboxes;
    }

    /**
     * Get mailbox status
     *
     * @param string $mailbox Name of mailbox to get status from
     * @param string $item    The type of status (see rfc3501 6.3.10)
     *
     * @return array Key is item
     */
    public function status($mailbox, $item)
    {
        $mailbox = mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');
        $this->writeLine('STATUS "' . $mailbox . '" (' . $item . ')');
        $responce = $this->responce();

        $return = array();

        preg_match_all(
            '/^\* STATUS "[^"]+" \((MESSAGES|RECENT|UIDNEXT|UIDVALIDITY|UNSEEN) ([0-9]+)\)/mu',
            $responce['data'],
            $matches,
            PREG_SET_ORDER
        );
        foreach ($matches as $matche) {
            $return[mb_strtolower($matche[1])] = (int) $matches[2];
        }

        return $return;
    }

    /**
     * Save an email in a specified mailbox
     *
     * @param string $mailbox Name of mailbox to append messages to
     * @param string $message Full message header and body
     * @param string $flags   Flags seporated by space
     *
     * @return mixed Either the assinged message UID or true
     */
    public function append($mailbox, $message, $flags = '')
    {
        $mailbox = mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');
        $command = 'APPEND "' . $mailbox . '" (' . $flags . ') {' . strlen($message);

        if (@$this->capabilities['LITERAL+']) {
            $this->writeLine($command . '+}');
        } else {
            $this->writeLine($command . '}');
            $this->responce(true);
        }

        $this->writeLine($message, true);
        $responce = $this->responce();

        preg_match('/APPENDUID [0-9]+ ([0-9]+)/u', $responce['responce'], $match);
        if ($match) {
            return $match[1];
        } else {
            return true;
        }
    }

    /**
     * Run housekeeping on the current mailbox
     *
     * @return null
     */
    public function check()
    {
        if (!$this->selected) {
            throw new Exception('Open mailbox first');
        }

        $this->writeLine('CHECK');
        $this->responce();
    }

    /**
     * Delete messages flaged with \Deleted and close mailbox
     *
     * @return null
     */
    public function close()
    {
        if (!$this->selected) {
            throw new Exception('Open mailbox first');
        }

        $this->writeLine('CLOSE');
        $this->responce();

        $this->selected = false;
    }

    /**
     * Delete messages flaged with \Deleted
     *
     * @return array Message numbers that where deleted
     */
    public function expunge()
    {
        if (!$this->selected) {
            throw new Exception('Open mailbox first');
        }

        $this->writeLine('EXPUNGE');
        $responce = $this->responce();

        preg_match_all(
            '/^\* ([0-9]+) EXPUNGE/mu',
            $responce['data'],
            $matches
        );

        return $matches[1];
    }

    /**
     * Search the current mailbox for messages that match the given search criteria.
     *
     * @param string $criteria Searching criteria (see rfc3501 6.4.4)
     * @param bool   $uid      Weather to use UID
     *
     * @return mixed Array of matching id's or false
     */
    public function search($criteria, $uid = false)
    {
        if (!$this->selected) {
            throw new Exception('Open mailbox first');
        }

        $command = 'SEARCH CHARSET "UTF-8" ' . $criteria;
        if ($uid) {
            $command = 'UID ' . $command;
        }

        $this->writeLine($command);
        $responce = $this->responce();

        preg_match('^\* SEARCH ([ 0-9]+)/u', $responce['data'], $match);
        if ($match) {
            return explode(' ', $match[1]);
        } else {
            return false;
        }
    }

    /**
     * Retrieves data associated with messages in the mailbox.
     *
     * @param string $msg_set Message(s) to fetch
     * @param string $data    Atom or a parenthesized (see rfc3501 6.4.5)
     * @param bool   $uid     Weather to use UID
     *
     * @return array Raw from responce()
     */
    public function fetch($msg_set, $data, $uid = false)
    {
        if (!$this->selected) {
            throw new Exception('Open mailbox first');
        }

        $command = 'FETCH ' . $msg_set . ' ' . $data;
        if ($uid) {
            $command = 'UID ' . $command;
        }

        $this->writeLine($command);
        return $this->responce();
    }

    /**
     * Update message flags
     *
     * @param string $msg_set Message(s) to fetch
     * @param string $action  How to preforme the change (see rfc3501 6.4.6)
     * @param string $flags   Flags seporated by space
     * @param bool   $uid     Weather to use UID
     *
     * @return array Key is message id with the message flags as a sub array under
     * the flags key
     */
    public function store($msg_set, $action, $flags, $uid = false)
    {
        if (!$this->selected) {
            throw new Exception('Open mailbox first');
        }

        $command = 'STORE ' . $msg_set . ' ' . $action . ' (' . $flags . ')';
        if ($uid) {
            $command = 'UID ' . $command;
        }

        $this->writeLine($command);
        $responce = $this->responce();

        preg_match_all(
            '/^\* ([0-9]+) FETCH \(FLAGS \(([^(]*)\)\)/mu',
            $responce['data'],
            $matches,
            PREG_SET_ORDER
        );

        $fetchs = array();
        foreach ($matches as $fetch) {
            $flags = array();
            foreach (explode(' ', $fetch[2]) as $flag) {
                $flags[$flag] = true;
            }

            $fetchs[$fetch[1]] = array(
                'flags' => $flags
            );
        }

        return $fetchs;
    }

    /**
     * The copy the specified message(s) to a specified mailbox
     *
     * @param string $msg_set Message(s) to fetch
     * @param string $mailbox Name of mailbox to copy messages to
     * @param bool   $uid     Weather to use UID
     *
     * @return array Raw from responce()
     */
    public function copy($msg_set, $mailbox, $uid = false)
    {
        if (!$this->selected) {
            throw new Exception('Open mailbox first');
        }

        $mailbox = mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');
        $command = 'COPY ' . $msg_set . ' "' . $mailbox . '"';
        if ($uid) {
            $command = 'UID ' . $command;
        }

        $this->writeLine($command);
        $this->responce();
    }
}
