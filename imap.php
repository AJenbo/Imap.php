<?php
/**
 * Declare the IMAP class
 *
 * PHP version 5
 *
 * @category IMAP
 * @package  IMAP
 * @author   Anders Jenbo <anders@jenbo.dk>
 * @license  GPLv2 http://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://github.com/AJenbo/PHP-imap
 */

/**
 * Access IMAP mailboxes without PHP IMAP extension
 *
 * PHP version 5
 *
 * @category IMAP
 * @package  IMAP
 * @author   Anders Jenbo <anders@jenbo.dk>
 * @license  GPLv2 http://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://github.com/AJenbo/PHP-imap
 */
class IMAP
{
    //TODO Process all responces from _responce
    //TODO Handle process each line as it is fetched instead of expecting specefic responces

    public $capabilities = array();
    public $error = '';  // error string

    private $_host = '';
    private $_port = 143;
    private $_user = '';
    private $_password = '';
    private $_socket = null;
    private $_tag = 0;
    private $_selected = false;

    /**
     * Connects to a server.
     *
     * @param string $user     A valid username.
     * @param string $password A valid password.
     * @param string $host     Server to connect to.
     * @param int    $port     Default is 143.
     */
    function __construct($user, $password, $host, $port = 143)
    {
        $this->_host = $host;
        $this->_port = $port;
        $this->_user = $user;
        $this->_password = $password;

        $this->_connect();
    }

    /**
     * Close the active connection and clean up
     */
    function __destruct()
    {
        $this->_writeLine('LOGOUT');
        @fclose($this->_socket);
    }

    /**
     * Open a connection to the server and login
     *
     * @return null
     */
    private function _connect()
    {
        $this->_socket = stream_socket_client(
            $this->_host . ':' . $this->_port,
            $errno,
            $errstr,
            30
        );
        if (!$this->_socket) {
            throw new Exception("$errstr ($errno)");
        }
        stream_set_blocking($this->_socket, 1);

        $this->_responce();
        $this->_capability();
        $this->_authenticate();
    }

    /**
     * Send a command to the server
     *
     * @param string $command The command to send over the wire
     * @param bool   $literal Weather this is a literal write
     *
     * @return null
     */
    private function _writeLine($command, $literal = false)
    {
        if (!$literal) {
            $this->_tag++;
            $command = $this->_tag . ' ' . $command;
        }

        if (!@fputs($this->_socket, $command . "\r\n")) {
            $error = error_get_last();
            throw new Exception($error['message']);
        }
    }

    /**
     * Retrive the full responce message from server
     *
     * @param bool $literal Weather to expect a ready for literal message
     *
     * @return array TODO
     */
    private function _responce($literal = false)
    {
        $responce = '';
        $return = array('message' => '', 'responce' => '', 'data' => '');
        while (true) {
            $line = fgets($this->_socket);
            $stream = stream_get_meta_data($this->_socket);
            if (!$stream['unread_bytes']) {
                if (($literal && preg_match('/^[+] /', $line))
                    || preg_match('/^[0-9*]+ OK/', $line)
                    || preg_match('/^[0-9*]+ NO/', $line)
                    || preg_match('/^[0-9*]+ BAD/', $line)
                ) {
                    break;
                }
            }
            $responce .= $line;
        }

        $return['data'] = $responce;

        if ($literal) {
            if (!preg_match('/^[+] /', $line)) {
                throw new Exception($line);
            }
            return true;
        } else {
            if (!preg_match('/^[0-9*]+ OK/', $line)) {
                throw new Exception($line);
            }
            if (preg_match('/^[0-9*]+ OK \[([^\]]+)\] (.*)$/', $line, $matches)) {
                $return['responce'] = $matches[1];
                $return['message'] = $matches[2];
            } elseif (preg_match('/^[0-9*]+ OK (.*)$/', $line, $matches)) {
                $return['message'] = $matches[1];
            }
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
    private function _capability($string = '')
    {
        if (!$string) {
            $this->_writeLine('CAPABILITY');
            $responce = $this->_responce();
            $string = substr($responce['data'], 13);
        }

        $this->capabilities = array();

        $string = explode(' ', $string);
        foreach ($string as $capability) {
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
    private function _authenticate()
    {
        $authenticated = false;
        if (!$authenticated) {
            $authenticated = $this->_authenticatePlain();
        }
        if (!$authenticated) {
            $authenticated = $this->_authenticateLogin();
        }
        if (!$authenticated) {
            $this->_login();
        }
    }

    /**
     * The plain authentification methode
     *
     * @return bool True if authenticated
     */
    private function _authenticatePlain()
    {
        if (!@$this->capabilities['AUTH']['PLAIN']) {
            return false;
        }

        $auth = base64_encode(chr(0) . $this->_user . chr(0) . $this->_password);
        $command = 'AUTHENTICATE PLAIN';

        if (@$this->capabilities['SASL-IR']) {
            $this->_writeLine($command . ' ' . $auth);
        } else {
            if (@$this->capabilities['LITERAL+']) {
                $this->_writeLine($command . ' {' . strlen($auth) . '+}');
            } else {
                $this->_writeLine($command);
                $this->_responce(true);
            }
            $this->_writeLine($auth, true);
        }

        try {
            $responce = $this->_responce();
            $this->_capability(substr($responce['responce'], 11));
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
    private function _authenticateLogin()
    {
        //TODO onc.com supports this with out saying so, should we always try it?
        if (!@$this->capabilities['AUTH']['LOGIN']) {
            return false;
        }

        $username = base64_encode($this->_user);
        $password = base64_encode($this->_password);
        $command = 'AUTHENTICATE LOGIN';

        if (@$this->capabilities['SASL-IR']) {
            $command = $command . ' ' . $username;
            //TODO one.com failes with this login methode and LITERAL+
            if (@$this->capabilities['LITERAL+']) {
                $this->_writeLine($command . ' {' . strlen($password) . '+}');
            } else {
                $this->_writeLine($command);
                $this->_responce(true);
            }
        } else {
            if (@$this->capabilities['LITERAL+']) {
                $this->_writeLine($command . ' {' . strlen($username) . '+}');
                $this->_writeLine($username . ' {' . strlen($password) . '+}', true);
            } else {
                $this->_writeLine($command);
                $this->_responce(true);
                $this->_writeLine($username, true);
                $this->_responce(true);
            }
        }
        $this->_writeLine($password, true);

        try {
            $responce = $this->_responce();
            $this->_capability(substr($responce['responce'], 11));
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
    private function _login()
    {
        $command = 'LOGIN ' . $this->_user . ' ' . $this->_password;
        $this->_writeLine($command);

        $responce = $this->_responce();
        $this->_capability(substr($responce['responce'], 11));
    }

    /**
     * Keep connection alive during a period of inactivity
     *
     * @return null
     */
    public function noop()
    {
        $this->_writeLine('NOOP');
        $this->_responce();
    }

    /**
     * The list_mailbox command gets the specified list of mailbox
     *
     * Reference     Mailbox Name  Interpretation
     * ------------  ------------  --------------
     * ~smith/Mail/  foo.*         ~smith/Mail/foo.*
     * archive/      %             archive/%
     * #news.        comp.mail.*   #news.comp.mail.*
     * ~smith/Mail/  /usr/doc/foo  /usr/doc/foo
     * archive/      ~fred/Mail/*  ~fred/Mail/*
     *
     * @param string $mailbox Reference mailbox
     * @param string $search  Search string
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
        $this->_writeLine($type . ' "' . $mailbox . '" "' . $search . '"');

        $responce = $this->_responce();

        preg_match_all(
            '/[*] ' . $type . ' \(([^)]*)\) "([^"]+)" "([^"]+)"/',
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
     * Create a mailbox
     *
     * @param string $mailbox Name of mailbox to create
     *
     * @return null
     */
    public function create($mailbox)
    {
        $mailbox = mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');
        $this->_writeLine('CREATE "' . $mailbox . '"');
        $this->_responce();
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
        $mailbox = mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');
        $mailboxNew = mb_convert_encoding($mailboxNew, 'UTF7-IMAP', 'UTF-8');
        $this->_writeLine('RENAME "' . $mailbox . '" "' . $mailboxNew . '"');
        $this->_responce();
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
        $mailbox = mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');
        $this->_writeLine('DELETE "' . $mailbox . '"');
        $this->_responce();
    }

    /**
     * The subscribe_mailbox command adds the specified mailbox name to the
     * server's set of "active" or "subscribed" mailboxes
     *
     * @param string $mailbox Name of mailbox to subscribe to
     *
     * @return null
     */
    public function subscribe($mailbox)
    {
        $mailbox = mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');
        $this->_writeLine('SUBSCRIBE "' . $mailbox . '"');
        $this->_responce();
    }

    /**
     * The subscribe_mailbox command removes the specified mailbox name to the
     * server's set of "active" or "subscribed" mailboxes
     *
     * @param string $mailbox Name of mailbox to unsubscribe from
     *
     * @return null
     */
    public function unsubscribe($mailbox)
    {
        $mailbox = mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');
        $this->_writeLine('UNSUBSCRIBE "' . $mailbox .'"');
        $this->_responce();
    }

    /**
     * Get mailbox status
     *
     * @param string $mailbox    Name of mailbox to get status from
     * @param string $status_cmd The type of status
     *
     * @return array Raw from _responce()
     */
    public function status($mailbox, $status_cmd)
    {
        $mailbox = mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');
        $this->_writeLine('STATUS "' . $mailbox . '" (' . $status_cmd . ')');

        return $this->_responce();
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

        $this->_writeLine($command);
        $responce = $this->_responce();
        $this->_selected = true;

        $return = array();

        preg_match(
            '/[*] FLAGS \(([^(]+)\)/',
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
            '/[*] OK \[PERMANENTFLAGS \(([^(]+)\)\]/',
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

        preg_match(
            '/[*] ([0-9]+) EXISTS/',
            $responce['data'],
            $matches
        );
        if ($matches) {
            $return['exists'] = $matches[1];
        }

        preg_match(
            '/[*] ([0-9]+) RECENT/',
            $responce['data'],
            $matches
        );
        if ($matches) {
            $return['recent'] = $matches[1];
        }

        preg_match(
            '/[*] OK \[UNSEEN ([0-9]+)\]/',
            $responce['data'],
            $matches
        );
        if ($matches) {
            $return['unseen'] = $matches[1];
        }

        preg_match(
            '/[*] OK \[UIDVALIDITY ([0-9]+)\]/',
            $responce['data'],
            $matches
        );
        if ($matches) {
            $return['uidvalidity'] = $matches[1];
        }

        preg_match(
            '/[*] OK \[UIDNEXT ([0-9]+)\]/',
            $responce['data'],
            $matches
        );
        if ($matches) {
            $return['uidnext'] = $matches[1];
        }

        preg_match(
            '/[*] OK \[HIGHESTMODSEQ ([0-9]+)\]/',
            $responce['data'],
            $matches
        );
        if ($matches) {
            $return['highestmodseq'] = $matches[1];
        }

        return $return;
    }

    /**
     * The CHECK command requests a checkpoint of the currently selected
     * mailbox. A checkpoint refers to any implementation-dependent
     * housekeeping associated with the mailbox
     *
     * @return array Raw from _responce()
     */
    public function check()
    {
        if (!$this->_selected) {
            $this->error = 'Error : No mail box is selected.!<br>';
            return false;
        }
        $this->_writeLine('CHECK');

        return $this->_responce();
    }

    /**
     * The expunge_mailbox command permanently removes from the currently selected
     * mailbox all messages that have the \Deleted flag set, and returns
     * to authenticated state from selected state.  tagged EXPUNGE
     * responces are sent.
     *
     * @return array Raw from _responce()
     */
    public function expunge()
    {
        if (!$this->_selected) {
            $this->error = 'Error : No mail box is selected.!<br>';
            return false;
        }
        $this->_writeLine('EXPUNGE');

        return $this->_responce();
    }

    /**
     * The search_mailbox command  searches the mailbox for messages that match
     * the given searching criteria.  Searching criteria consist of one
     * or more search keys.
     * The defined search keys are as follows.  Refer to the Formal
     * Syntax section for the precise syntactic definitions of the
     * arguments.
     *
     * <message set>  Messages with message sequence numbers
     *                corresponding to the specified message sequence
     *                number set
     *
     * ALL            All messages in the mailbox; the default initial
     *                key for ANDing.
     *
     * ANSWERED       Messages with the \Answered flag set.
     *
     * BCC <string>   Messages that contain the specified string in the
     *                envelope structure's BCC field.
     *
     * BEFORE <date>  Messages whose internal date is earlier than the
     *                specified date.
     *
     * BODY <string>  Messages that contain the specified string in the
     *                body of the message.
     *
     * CC <string>    Messages that contain the specified string in the
     *                envelope structure's CC field.
     *
     * DELETED        Messages with the \Deleted flag set.
     *
     * DRAFT          Messages with the \Draft flag set.
     *
     * FLAGGED        Messages with the \Flagged flag set.
     *
     * FROM <string>  Messages that contain the specified string in the
     *                envelope structure's FROM field.
     *
     * HEADER <field-name> <string>
     *                Messages that have a header with the specified
     *                field-name (as defined in [RFC-822]) and that
     *                contains the specified string in the [RFC-822]
     *                field-body.
     *
     * KEYWORD <flag> Messages with the specified keyword set.
     *
     * LARGER <n>     Messages with an [RFC-822] size larger than the
     *                specified number of octets.
     *
     * NEW            Messages that have the \Recent flag set but not the
     *                \Seen flag.  This is functionally equivalent to
     *                "(RECENT UNSEEN)".
     *
     * NOT <search-key>
     *                Messages that do not match the specified search
     *                key.
     *
     * OLD            Messages that do not have the \Recent flag set.
     *                This is functionally equivalent to "NOT RECENT" (as
     *                opposed to "NOT NEW").
     *
     * ON <date>      Messages whose internal date is within the
     *                specified date.
     *
     * OR <search-key1> <search-key2>
     *                Messages that match either search key.
     *
     * RECENT         Messages that have the \Recent flag set.
     *
     * SEEN           Messages that have the \Seen flag set.
     *
     * SENTBEFORE <date>
     *                Messages whose [RFC-822] Date: header is earlier
     *                than the specified date.
     *
     * SENTON <date>  Messages whose [RFC-822] Date: header is within the
     *                specified date.
     *
     * SENTSINCE <date>
     *                Messages whose [RFC-822] Date: header is within or
     *                later than the specified date.
     *
     * SINCE <date>   Messages whose internal date is within or later
     *                than the specified date.
     *
     * SMALLER <n>    Messages with an [RFC-822] size smaller than the
     *                specified number of octets.
     *
     * SUBJECT <string>
     *                Messages that contain the specified string in the
     *                envelope structure's SUBJECT field.
     *
     * TEXT <string>  Messages that contain the specified string in the
     *                header or body of the message.
     *
     * TO <string>    Messages that contain the specified string in the
     *                envelope structure's TO field.
     *
     * UID <message set>
     *                Messages with unique identifiers corresponding to
     *                the specified unique identifier set.
     *
     * UNANSWERED     Messages that do not have the \Answered flag set.
     *
     * UNDELETED      Messages that do not have the \Deleted flag set.
     *
     * UNDRAFT        Messages that do not have the \Draft flag set.
     *
     * UNFLAGGED      Messages that do not have the \Flagged flag set.
     *
     * UNKEYWORD <flag>
     *                Messages that do not have the specified keyword set.
     *
     * UNSEEN         Messages that do not have the \Seen flag set.
     *
     * Example:      search('FLAGGED SINCE 1-Feb-1994 NOT FROM "Smith"')
     *
     * @param string $search_cri TODO
     * @param bool   $uid        Weather to use UID
     *
     * @return mixed Array of matching id's or false
     */
    public function search($search_cri, $uid = false)
    {
        if (!$this->_selected) {
            $this->error = 'Error : No mail box is selected.!<br>';
            return false;
        }

        $command = 'SEARCH CHARSET "UTF-8" ' . $search_cri;
        if ($uid) {
            $command = 'UID ' . $command;
        }

        $this->_writeLine($command);
        $responce = $this->_responce();

        preg_match('/[*] SEARCH ([\s0-9]+)/', $responce['data'], $match);
        if ($match) {
            return explode(' ', $match[1]);
        } else {
            return false;
        }
    }

    /**
     * The fetch_mail function retrieves data associated with a message in the
     * mailbox.  The data items to be fetched can be either a single atom
     * or a parenthesized list.
     *
     * ALL       Macro equivalent to: (FLAGS INTERNALDATE RFC822.SIZE ENVELOPE)
     *
     * BODY      Non-extensible form of BODYSTRUCTURE.
     *
     * BODY[<section>]<<partial>>
     *
     * BODY.PEEK[<section>]<<partial>>
     *           An alternate form of BODY[<section>] that does not
     *           implicitly set the \Seen flag.
     *
     * BODYSTRUCTURE  The [MIME-IMB] body structure of the message.  This
     *           is computed by the server by parsing the [MIME-IMB]
     *           header fields in the [RFC-822] header and
     *           [MIME-IMB] headers.
     *
     * ENVELOPE  TYhe envelope structure of the message.  This is
     *           computed by the server by parsing the [RFC-822]
     *           header into the component parts, defaulting various
     *           fields as necessary.
     *
     * FAST      Macro equivalent to: (FLAGS INTERNALDATE RFC822.SIZE)
     *
     * FLAGS     The flags that are set for this message.
     *
     * FULL      Macro equivalent to: (FLAGS INTERNALDATE RFC822.SIZE ENVELOPE BODY)
     *
     * INTERNALDATE The internal date of the message.
     *
     * RFC822    Functionally equivalent to BODY[], differing in the
     *           syntax of the resulting untagged FETCH data (RFC822
     *           is returned).
     *
     * RFC822.HEADER  Functionally equivalent to BODY.PEEK[HEADER],
     *           differing in the syntax of the resulting untagged
     *           FETCH data (RFC822.HEADER is returned).
     *
     * RFC822.SIZE  The [RFC-822] size of the message.
     *
     * RFC822.TEXT  Functionally equivalent to BODY[TEXT], differing in
     *           the syntax of the resulting untagged FETCH data
     *           (RFC822.TEXT is returned).
     *
     * UID       The unique identifier for the message.
     *
     * Example : fetch('2:4', '(FLAGS BODY[HEADER.FIELDS (DATE FROM)]')
     *
     * @param string $msg_set       Message(s) to fetch
     * @param string $msg_data_name TODO
     * @param bool   $uid           Weather to use UID
     *
     * @return array Raw from _responce()
     */
    public function fetch($msg_set, $msg_data_name, $uid = false)
    {
        if (!$this->_selected) {
            $this->error = 'Error : No mail box is selected.!<br>';
            return false;
        }

        $command = "FETCH $msg_set $msg_data_name";
        if ($uid) {
            $command = 'UID ' . $command;
        }

        $this->_writeLine($command);
        return $this->_responce();
    }

    /**
     * Update message flags
     *
     * FLAGS         Replace the flags for the message with the
     *               argument.  The new value of the flags are returned
     *               as if a FETCH of those flags was done.
     *
     * FLAGS.SILENT  Equivalent to FLAGS, but without returning a new value.
     *
     * +FLAGS        Add the argument to the flags for the message.  The
     *               new value of the flags are returned as if a FETCH
     *               of those flags was done.
     *
     * +FLAGS.SILENT Equivalent to +FLAGS, but without returning a new
     *               value.
     *
     * -FLAGS        Remove the argument from the flags for the message.
     *               The new value of the flags are returned as if a
     *               FETCH of those flags was done.
     *
     * -FLAGS.SILENT Equivalent to -FLAGS, but without returning a new
     *               value.
     *
     * @param string $msg_set       Message(s) to fetch
     * @param string $msg_data_name TODO
     * @param string $value         TODO
     * @param bool   $uid           Weather to use UID
     *
     * @return array Raw from _responce()
     */
    public function store($msg_set, $msg_data_name, $value, $uid = false)
    {
        if (!$this->_selected) {
            $this->error = 'Error : No mail box is selected.!<br>';
            return false;
        }

        $command = "STORE $msg_set $msg_data_name ($value)";
        if ($uid) {
            $command = 'UID ' . $command;
        }

        $this->_writeLine($command);
        return $this->_responce();
    }

    /**
     * The copy the specified message(s) to a specified mailbox
     *
     * @param string $msg_set Message(s) to fetch
     * @param string $mailbox Name of mailbox to copy messages to
     * @param bool   $uid     Weather to use UID
     *
     * @return array Raw from _responce()
     */
    public function copy($msg_set, $mailbox, $uid = false)
    {
        $mailbox = mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');
        if (!$this->_selected) {
            $this->error = 'Error : No mail box is selected.!<br>';
            return false;
        }

        $command = 'COPY ' . $msg_set . ' "' . $mailbox . '"';
        if ($uid) {
            $command = 'UID ' . $command;
        }

        $this->_writeLine($command);
        return $this->_responce();
    }

    /**
     * Save an email in a specified mailbox
     *
     * @param string $mailbox Name of mailbox to append messages to
     * @param string $message Full message header and body
     * @param string $flags   Flags to be set on new message
     *
     * @return mixed Either the assinged message UID or true
     */
    public function append($mailbox, $message, $flags = '')
    {
        $mailbox = mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');
        $command = 'APPEND "' . $mailbox . '" ($flags) {' . strlen($message);

        if (@$this->capabilities['LITERAL+']) {
            $this->_writeLine($command . '+}');
        } else {
            $this->_writeLine($command . '}');
            $this->_responce(true);
        }

        $this->_writeLine($message, true);
        $responce = $this->_responce();

        preg_match('/APPENDUID [0-9]+ ([0-9]+)/', $responce['responce'], $match);
        if ($match) {
            return $match[1];
        } else {
            return true;
        }
    }
}

