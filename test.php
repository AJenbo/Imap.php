<?php
/**
 * Test the IMAP class
 *
 * PHP version 5
 *
 * @category IMAP
 * @package  IMAP
 * @author   Anders Jenbo <anders@jenbo.dk>
 * @license  GPLv2 http://www.gnu.org/licenses/gpl-2.0.html
 * @link     https://github.com/AJenbo/PHP-imap
 */

require_once 'imap.php';
$imap = new IMAP(
    'username',
    'password',
    'localhost',
    "143"
);

$imap->noop();
$response = $imap->listMailboxes();
var_dump($response);
$imap->create('INBOX.test');
$imap->subscribe('INBOX.test');
$imap->unsubscribe('INBOX.test');
$response = $imap->status('INBOX.test', 'MESSAGES');
var_dump($response);

$message = 'Date: Mon, 7 Feb 1994 21:52:25 -0800 (PST)
From: Fred Foobar <foobar@Blurdybloop.COM>
Subject: afternoon meeting
To: mooch@owatagu.siam.edu
Message-Id: <B27397-0100000@Blurdybloop.COM>
MIME-Version: 1.0
Content-Type: TEXT/PLAIN; CHARSET=UTF-8

Hello Joe, do you think we can meet at 3:30 tomorrow?';
$response = $imap->append('INBOX.test', $message, '\Seen');
var_dump($response);
$response = $imap->select('INBOX.test');
var_dump($response);
$response = $imap->search('SINCE 1-Feb-1994');
var_dump($response);
$response = $imap->fetch('1', '(FLAGS BODY[HEADER.FIELDS (DATE FROM)])');
var_dump($response);
$imap->copy('1', 'INBOX.test');
$response = $imap->store('1:*', '+Flags', '\Deleted');
var_dump($response);
$response = $imap->expunge();
var_dump($response);
$imap->check();
$response = $imap->close();
$imap->rename('INBOX.test', 'INBOX.test2');
$imap->delete('INBOX.test2');

