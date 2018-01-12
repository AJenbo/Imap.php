<?php namespace AJenbo;

use AJenbo\Imap\Exception;

/**
 * @license GPLv2 http://www.gnu.org/licenses/gpl-2.0.html
 *
 * @todo Process all responces from responce
 * @todo Handle process each line as it is fetched instead of expecting specefic responces
 * @todo show error for * NO Invalid message sequence number: 1
 */
class Imap
{
    /** @var (bool[]|bool)[] */
    public $capabilities = [
        'LITERAL+' => false,
        'AUTH' => [
            'LOGIN' => false,
            'PLAIN' => false,
        ],
    ];

    /** @var resource */
    private $socket;

    /** @var string */
    private $host = '';

    /** @var int */
    private $port = 143;

    /** @var string */
    private $user = '';

    /** @var string */
    private $password = '';

    /** @var int */
    private $tag = 0;

    /** @var bool */
    private $selected = false;

    /**
     * Set up vars and initiate call connect().
     *
     * @param string $user     a valid username
     * @param string $password a valid password
     * @param string $host     server to connect to
     * @param int    $port     default is 143
     */
    public function __construct(string $user, string $password, string $host, int $port = 143)
    {
        $this->host     = $host;
        $this->port     = $port;
        $this->user     = $user;
        $this->password = $password;

        $this->connect();
    }

    /**
     * Close the connection.
     */
    public function __destruct()
    {
        $this->writeLine('LOGOUT');
        @fclose($this->socket);
    }

    /**
     * Open a connection to the server and authenticate.
     *
     * @throws Exception
     *
     * @return void
     */
    private function connect(): void
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
        stream_set_blocking($this->socket, true);

        $this->responce();
        $this->capability();
        $this->authenticate();
    }

    /**
     * Send a command to the server.
     *
     * @param string $command The command to send over the wire
     * @param bool   $literal Weather this is a literal write
     *
     * @throws Exception
     *
     * @return void
     */
    private function writeLine(string $command, bool $literal = false): void
    {
        if (!$literal) {
            $this->tag++;
            $command = $this->tag . ' ' . $command;
        }

        if (!@fwrite($this->socket, $command . "\r\n")) {
            $error = error_get_last();
            throw new Exception($error['message'] ?? 'Unknown');
        }
    }

    /**
     * Send a command to the server that prepares the server for a data trasfer.
     *
     * This dosn't actually transfer the data
     *
     * @param string $command The command to send over the wire
     * @param string $data    The data that will be uploaded
     * @param bool   $literal Weather this is a literal write
     *
     * @throws Exception
     *
     * @return void
     */
    private function startTransfer(string $command, string $data, bool $literal = false): void
    {
        if ($this->capabilities['LITERAL+']) {
            $command .= ' {' . strlen($data) . '+}';
        }

        $this->writeLine($command, $literal);

        if (!$this->capabilities['LITERAL+']) {
            $this->responce(true);
        }
    }

    /**
     * Retrive the full responce message from server.
     *
     * @param bool $literal Weather to expect a ready for literal message
     *
     * @throws Exception
     *
     * @return string[] Responce from server devided in to types
     */
    private function responce($literal = false): array
    {
        $responce = '';
        $return = ['message' => '', 'responce' => '', 'data' => ''];
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

            return [];
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
     * Populate the capabilites variable with the serveres reported capabilitys.
     *
     * @param string $string String to use instead of fetching from the server
     *
     * @return void
     */
    private function capability(string $string = ''): void
    {
        if (!$string) {
            $this->writeLine('CAPABILITY');
            $responce = $this->responce();
            $string = substr($responce['data'], 13);
        }

        $this->capabilities = [];

        $string = explode(' ', $string);
        foreach ($string as $capability) {
            $capability = trim($capability);
            if (false === strpos($capability, '=')) {
                $this->capabilities[$capability] = true;
                continue;
            }

            $capability = explode('=', $capability);
            if (!is_array($this->capabilities[$capability[0]] ?? null)) {
                $this->capabilities[$capability[0]] = [];
            }
            $this->capabilities[$capability[0]][$capability[1]] = true;
        }
    }

    /**
     * Use most secure way to login to server.
     *
     * @return void
     */
    private function authenticate(): void
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
     * The plain authentification methode.
     *
     * @return bool True if authenticated
     */
    private function authenticatePlain(): bool
    {
        if (empty($this->capabilities['AUTH']['PLAIN'])) {
            return false;
        }

        $auth = base64_encode(chr(0) . $this->user . chr(0) . $this->password);
        $command = 'AUTHENTICATE PLAIN';

        if (!empty($this->capabilities['SASL-IR'])) {
            $this->writeLine($command . ' ' . $auth);
        } else {
            $this->startTransfer($command);
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
     * The login authentification methode.
     *
     * @todo Some providers supports this with out saying so, should we always try it?
     * @todo Some providers failes SASL-IR login with LITERAL+
     *
     * @return bool True if authenticated
     */
    private function authenticateLogin(): bool
    {
        if (empty($this->capabilities['AUTH']['LOGIN'])) {
            return false;
        }

        $username = base64_encode($this->user);
        $password = base64_encode($this->password);
        $command = 'AUTHENTICATE LOGIN';

        if (!empty($this->capabilities['SASL-IR'])) {
            $command = $command . ' ' . $username;
            $this->startTransfer($command, $password);
        } else {
            $this->startTransfer($command, $username);
            $this->startTransfer($username, $password);
        }
        $this->writeLine($password, true);

        try {
            $responce = $this->responce();
            $this->capability(substr($responce['responce'], 11));
        } catch (Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * The most basic authentification methode.
     *
     * @return void
     */
    private function login(): void
    {
        $command = 'LOGIN ' . $this->user . ' ' . $this->password;
        $this->writeLine($command);

        $responce = $this->responce();
        $this->capability(substr($responce['responce'], 11));
    }

    /**
     * Keep connection alive during a period of inactivity
     *
     * @todo Get posible responce since last check.
     *
     * @return void
     */
    public function noop(): void
    {
        $this->writeLine('NOOP');
        $this->responce();
    }

    /**
     * Open a mailbox.
     *
     * @param string $mailbox  Name of mailbox to be selected
     * @param bool   $readOnly Weather to open it in read only mode
     *
     * @return array Contaning array of flags, and other properties of the mailbox
     */
    public function select(string $mailbox = 'INBOX', bool $readOnly = false): array
    {
        $mailbox = mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');

        $command = 'EXAMINE "' . $mailbox . '"';
        if (!$readOnly) {
            $command = 'SELECT "' . $mailbox . '"';
        }

        $this->writeLine($command);
        $responce = $this->responce();
        $this->selected = true;

        $return = [];

        preg_match(
            '/^\* FLAGS \(([^(]+)\)/u',
            $responce['data'],
            $matches
        );
        if ($matches) {
            $flags = [];
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
            $permanentflags = [];
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
            $return[mb_strtolower($matche[2])] = (int) $matche[1];
        }

        preg_match_all(
            '/^\* OK \\[(UNSEEN|UIDVALIDITY|UIDNEXT|HIGHESTMODSEQ) ([0-9]+)\\]/mu',
            $responce['data'],
            $matches,
            PREG_SET_ORDER
        );
        foreach ($matches as $matche) {
            $return[mb_strtolower($matche[1])] = (int) $matche[2];
        }

        return $return;
    }

    /**
     * Create a mailbox.
     *
     * @param string $mailbox Name of mailbox to create
     *
     * @return void
     */
    public function create(string $mailbox): void
    {
        $mailbox = mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');
        $this->writeLine('CREATE "' . $mailbox . '"');
        $this->responce();
    }

    /**
     * Delete mailbox.
     *
     * @param string $mailbox Name of mailbox to delete
     *
     * @throws Exception
     *
     * @return void
     */
    public function delete(string $mailbox): void
    {
        if ($this->selected) {
            throw new Exception('Close mailbox first');
        }

        $mailbox = mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');
        $this->writeLine('DELETE "' . $mailbox . '"');
        $this->responce();
    }

    /**
     * Rename an exists mailbox.
     *
     * @param string $mailbox    Name of mailbox to rename
     * @param string $mailboxNew New name for mailbox
     *
     * @throws Exception
     *
     * @return void
     */
    public function rename(string $mailbox, string $mailboxNew): void
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
     * Subscribe to a mailbox.
     *
     * @param string $mailbox Name of mailbox to subscribe to
     *
     * @return void
     */
    public function subscribe(string $mailbox): void
    {
        $mailbox = mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');
        $this->writeLine('SUBSCRIBE "' . $mailbox . '"');
        $this->responce();
    }

    /**
     * Unsubscribe from a mailbox.
     *
     * @param string $mailbox Name of mailbox to unsubscribe from
     *
     * @return void
     */
    public function unsubscribe(string $mailbox): void
    {
        $mailbox = mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');
        $this->writeLine('UNSUBSCRIBE "' . $mailbox . '"');
        $this->responce();
    }

    /**
     * Query for existing mailboxes.
     *
     * @param string $mailbox Reference mailbox
     * @param string $search  Search string (see rfc3501 6.3.8)
     * @param bool   $lsub    Weather to list subscribed mailboxes
     *
     * @return array[] Array of mailboxes contaning array of attributes, delimiter charecter and name
     */
    public function listMailboxes(string $mailbox = '', string $search = '*', bool $lsub = false): array
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

        $mailboxesSort = [];
        $mailboxes = [];
        foreach ($matches as $mailbox) {
            $attributes = [];
            foreach (explode(' ', $mailbox[1]) as $attribute) {
                $attributes[$attribute] = true;
            }
            $delimiter = mb_convert_encoding($mailbox[2], 'UTF-8', 'UTF7-IMAP');
            $name = mb_convert_encoding($mailbox[3], 'UTF-8', 'UTF7-IMAP');

            $mailboxesSort[] = $name;

            $mailboxes[] = [
                'attributes' => $attributes,
                'delimiter'  => $delimiter,
                'name'       => $name,
            ];
        }

        array_multisort($mailboxesSort, SORT_LOCALE_STRING, $mailboxes);

        return $mailboxes;
    }

    /**
     * Get mailbox status.
     *
     * @param string $mailbox Name of mailbox to get status from
     * @param string $item    The type of status (see rfc3501 6.3.10)
     *
     * @return int[] The key is the item
     */
    public function status(string $mailbox, string $item): array
    {
        $mailbox = mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');
        $this->writeLine('STATUS "' . $mailbox . '" (' . $item . ')');
        $responce = $this->responce();

        $return = [];

        preg_match_all(
            '/^\* STATUS "[^"]+" \((MESSAGES|RECENT|UIDNEXT|UIDVALIDITY|UNSEEN) ([0-9]+)\)/mu',
            $responce['data'],
            $matches,
            PREG_SET_ORDER
        );
        foreach ($matches as $matche) {
            $return[mb_strtolower($matche[1])] = (int) $matche[2];
        }

        return $return;
    }

    /**
     * Save an email in a specified mailbox.
     *
     * @param string $mailbox Name of mailbox to append messages to
     * @param string $message Full message header and body
     * @param string $flags   Flags seporated by space
     *
     * @return ?int Either the assinged message UID or true
     */
    public function append(string $mailbox, string $message, string $flags = ''): ?int
    {
        $mailbox = mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');
        $command = 'APPEND "' . $mailbox . '" (' . $flags . ')';
        if (!$this->capabilities['LITERAL+']) {
            $command .= ' {' . strlen($message) . '}';
        }

        $this->startTransfer($command, $message);
        $this->writeLine($message, true);
        $responce = $this->responce();

        preg_match('/APPENDUID [0-9]+ ([0-9]+)/u', $responce['responce'], $match);
        if ($match) {
            return $match[1];
        }

        return null;
    }

    /**
     * Run housekeeping on the current mailbox.
     *
     * @throws Exception
     *
     * @return void
     */
    public function check(): void
    {
        if (!$this->selected) {
            throw new Exception('Open mailbox first');
        }

        $this->writeLine('CHECK');
        $this->responce();
    }

    /**
     * Delete messages flaged with \Deleted and close mailbox.
     *
     * @throws Exception
     *
     * @return void
     */
    public function close(): void
    {
        if (!$this->selected) {
            throw new Exception('Open mailbox first');
        }

        $this->writeLine('CLOSE');
        $this->responce();

        $this->selected = false;
    }

    /**
     * Delete messages flaged with \Deleted.
     *
     * @throws Exception
     *
     * @return int[] Message numbers that where deleted
     */
    public function expunge(): array
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
     * @throws Exception
     *
     * @return int[] Array of matching id's or false
     */
    public function search(string $criteria, bool $uid = false): array
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
        }

        return [];
    }

    /**
     * Retrieves data associated with messages in the mailbox.
     *
     * @param string $msgSet Message(s) to fetch
     * @param string $data   Atom or a parenthesized (see rfc3501 6.4.5)
     * @param bool   $uid    Weather to use UID
     *
     * @throws Exception
     *
     * @return string[] Raw from responce()
     */
    public function fetch(string $msgSet, string $data, bool $uid = false): array
    {
        if (!$this->selected) {
            throw new Exception('Open mailbox first');
        }

        $command = 'FETCH ' . $msgSet . ' ' . $data;
        if ($uid) {
            $command = 'UID ' . $command;
        }

        $this->writeLine($command);

        return $this->responce();
    }

    /**
     * Update message flags.
     *
     * @param string $msgSet Message(s) to fetch
     * @param string $action How to preforme the change (see rfc3501 6.4.6)
     * @param string $flags  Flags seporated by space
     * @param bool   $uid    Weather to use UID
     *
     * @throws Exception
     *
     * @return array[] Key is message id with the message flags as a sub array under the flags key
     */
    public function store(string $msgSet, string $action, string $flags, bool $uid = false): array
    {
        if (!$this->selected) {
            throw new Exception('Open mailbox first');
        }

        $command = 'STORE ' . $msgSet . ' ' . $action . ' (' . $flags . ')';
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

        $fetchs = [];
        foreach ($matches as $fetch) {
            $flags = [];
            foreach (explode(' ', $fetch[2]) as $flag) {
                $flags[$flag] = true;
            }

            $fetchs[$fetch[1]] = ['flags' => $flags];
        }

        return $fetchs;
    }

    /**
     * The copy the specified message(s) to a specified mailbox.
     *
     * @param string $msgSet  Message(s) to fetch
     * @param string $mailbox Name of mailbox to copy messages to
     * @param bool   $uid     Weather to use UID
     *
     * @throws Exception
     *
     * @return void
     */
    public function copy(string $msgSet, string $mailbox, bool $uid = false): void
    {
        if (!$this->selected) {
            throw new Exception('Open mailbox first');
        }

        $mailbox = mb_convert_encoding($mailbox, 'UTF7-IMAP', 'UTF-8');
        $command = 'COPY ' . $msgSet . ' "' . $mailbox . '"';
        if ($uid) {
            $command = 'UID ' . $command;
        }

        $this->writeLine($command);
        $this->responce();
    }
}
