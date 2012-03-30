<?php


	include_once("imap.inc.php");
	include_once("mimedecode.inc.php");
	$imap=new IMAPMAIL;
	if(!$imap->open("192.168.0.26","143"))
	{
		echo $imap->get_error();
		exit;
	} 

	$imap->login("harishc","hchauhan");
	echo $imap->error;
	$response=$imap->open_mailbox("INBOX");
	echo $imap->error;
	//echo $response=$imap->get_msglist();
	//echo $response=$imap->delete_message(9);
	//echo $response=$imap->rollback_delete(9);
	$response=$imap->get_message(1);


	///Decoding the mail	

	$mimedecoder=new MIMEDECODE($response,"\r\n");
	$msg=$mimedecoder->get_parsed_message();
	print_r($msg);
	//echo nl2br($response);
	echo $imap->get_error();
	$imap->close();
	//$response=$imap->fetch_mail("3","BODYSTRUCTURE");
	//print_r($response);
	//echo nl2br($response);
	//echo $imap->error;
	echo "<br>";


?>
