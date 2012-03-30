<?php
require_once('imap.php');
$imap = new IMAP(
    'username',
    'password',
    'localhost',
    "143"
);

$response = $imap->select('INBOX');
var_dump($response);

//$response = $imap->status('INBOX', 'MESSAGES');
//$response = $imap->store(9, '+Flags', '\\Deleted');
//$response = $imap->store(9, '-Flags', '\\Deleted');
$response = $imap->fetch(1, 'BODY[]');
//$response = $imap->fetch(3, 'BODYSTRUCTURE');
var_dump($response);

