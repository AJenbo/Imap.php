<?php

	 /*
	 #####################################################
	 ####                                             ####
	 ####    Author       : Harish Chauhan            ####
	 ####    Start Date   : 14 Oct,2004               ####
	 ####    End Date     : -- Oct,2004               ####
	 ####    Updated      :                           ####
	 ####                 		                      ####
	 #####################################################
	 */

	
	
	/// Constant FLAGS   
	
	define("HKC_ALL_MSG","MESSAGES"); ////USED TO RETRIVE NUMBER OF ALL MESSAGES IN MAILBOX
	define("HKC_RECENT_MSG","RECENT"); ////USED TO RETRIVE NUMBER OF RECENT MESSAGES IN MAILBOX
	define("HKC_UNSEEN_MSG","UNSEEN"); ////USED TO RETRIVE NUMBER OF UNSEEN MESSAGES IN MAILBOX
	define("HKC_UID_NEXT","UIDNEXT"); ////USED TO RETRIVE UIDNEXT NUMBER
	define("HKC_UID_VALIDITY","UIDVALIDITY"); ////USED TO RETRIVE UIDVALIDITY NUMBER

	class IMAPMAIL
	{
		var $host;  // host like 127.0.0.1 or mail.yoursite.com
		var $port;  // port default is 110 or 143
		var $user;  // user for logon
		var $password;  // user paswword
		var $state;   // variable define diffrent state of connection
		var $connection; // handle to a open connection
		var $error;  // error string
		var $must_update;
		var $tag;
		var $mail_box;

		function IMAPMAIL()
		{
			$this->host=NULL;
			$this->port=143;
			$this->user="";
			$this->password="";
			$this->state="DISCONNECTED";
			$this->connection=null;
			$this->error="";
			$this->must_update=false;
			$this->tag=uniqid("HKC");
		}
		/* This functiuon set the host
		example popmail::set_host("mail.yoursite.com") */
	
		function set_host($host)
		{
			$this->host=$host;
		}
		// This functiuon set the portt
		// example popmail::set_port(110)
		function set_port($port)
		{
			$this->port=$port;
		}
		////This functiuon is to retrive the error of last operation 
		////example popmail::get_error()
		function get_error()
		{
			if($this->error)
				return $this->error;
		}
		////This functiuon is to retrive the state of connaction 
		function get_state()
		{
			return $this->state;
		}

		///Function is used to open connection
		function open($host="",$port="")
		{
			if(!empty($host))
				$this->host=$host;
			if(!empty($port))
				$this->port=$port;
			return $this->open_connection();
		}
		
		/// close the active connection
		function close()
		{
			if($this->must_update)
				$this->close_mailbox();
			$this->logout();
			@fclose($this->connection);
			$this->connection=null;
			$this->state="DISCONNECTED";
			return true;
		}
		
		////This functiuon is to open the mailbox 
		//// Arguments 1: mailbox name 2: open as read only or read write mode.
		function open_mailbox($mailbox_name="INBOX",$read_only=false)
		{
			if($read_only)	
			{
				$result=$this->examin_mailbox($mailbox_name);
				if($result)
					$this->mail_box=$mailbox_name;
			}
			else
			{
				$result= $this->select_mailbox($mailbox_name);
				if($result)
					$this->mail_box=$mailbox_name;
			}
			return $result;	
		}
	
		//function returns the number of recent messages 
		function get_unseen_msglist()
		{
			return $this->get_list(HKC_UNSEEN_MSG);
		}
		//function returns the number of recent messages 
		function get_recent_msglist()
		{
			return $this->get_list(HKC_RECENT_MSG);
		}
		//function returns the number of all messages 
		function get_msglist()
		{
			return $this->get_list(HKC_ALL_MSG);
		}

		//function returns the number of messages 
		function get_list($flag)
		{
			$response=$this->get_status($this->mail_box,$flag);
			$response=spliti("$flag",$response);
			return intval($response[1]);
		}
		
		//function retrives the full message from server.
		function get_message_body($msgno,$uid=false)
		{
			if($uid)
				$response=$this->uid_fetch_mail($msgno,"BODY[TEXT]");
			else
				$response=$this->fetch_mail($msgno,"BODY[TEXT]");

			$temp_arr=explode("\n",$response);
			array_shift($temp_arr);
			array_shift($temp_arr);
			array_pop($temp_arr);
			array_pop($temp_arr);
			return implode("\n",$temp_arr);
		}
		
		//function retrives the full message from server.
		function get_message_header($msgno,$uid=false)
		{
			if($uid)
				$response=$this->uid_fetch_mail($msgno,"BODY[HEADER]");
			else
				$response=$this->fetch_mail($msgno,"BODY[HEADER]");

			$temp_arr=explode("\n",$response);
			array_shift($temp_arr);
			array_shift($temp_arr);
			array_pop($temp_arr);
			array_pop($temp_arr);
			return implode("\n",$temp_arr);
		}

		//function retrives the full message from server.
		function get_message($msgno,$uid=false)
		{
			if($uid)
				$response=$this->uid_fetch_mail($msgno,"BODY[]");
			else
				$response=$this->fetch_mail($msgno,"BODY[]");

			$temp_arr=explode("\n",$response);
			array_shift($temp_arr);
			array_shift($temp_arr);
			array_pop($temp_arr);
			array_pop($temp_arr);
			return implode("\n",$temp_arr);
		}
		
		//function put a delete flag the message and when you close the mail box then
		//message flagged as deleted ,Deleted Permanently
		// Example delete_message("2:4") delete the message From 2 to 4
		function delete_message($msgno)
		{
			$this->must_update=true;
			return $this->store_mail_flag($msgno,"+Flags","\\Deleted" );
		}
		
		//function put a delete flag the message
		function rollback_delete($msgno)
		{
			$this->must_update=true;
			return $this->store_mail_flag($msgno,"-Flags","\\Deleted" );
			
		}
		

		/* 
			The Functions is written bellow is the subordinate functions used in 
			communication with SERVER.
		*/
		
		// This function is used to get response line from server
		function get_line()
		{
			while(!feof($this->connection))
			{
				$line.=fgets($this->connection);
				if(strlen($line)>=2 && substr($line,-2)=="\r\n")
					return(substr($line,0,-2));
			}
		}
		////This functiuon is to retrive the full response message from server
		function get_server_responce()
		{
			while(1)
			{
				$response.="\r\n".$this->get_line();
				if(substr($response,strpos($response,$this->tag),strlen($this->tag))==$this->tag)
					break;
			}
			return $response;
		}
		////This functiuon is to send the command to server
		function put_line($msg="")
		{
			return @fputs($this->connection,"$msg\r\n");
		}

		/* 
			The Functions is written bellow is the main commands defined in IMAP
			protocol.  
		*/

		////This functiuon is to open the connection to the server 
		function open_connection()
		{
			if($this->state!="DISCONNECTED")
			{
				$this->error= "Error : Already Connected!<br>";
				return false;
			}
			if(empty($this->host) || empty($this->port))			
			{
				$this->error= "Error : Either HOST or PORT is undifined!<br>";
				return false;
			}
			$this->connection= fsockopen($this->host,$this->port,$errno,$errstr);
			if(!$this->connection)
			{
				$this->error= "Could not make a connection to server , Error : $errstr ($errno)<br>";
				return false;
			}
			$respone=$this->get_line();	
			$this->state="AUTHORIZATION";
			return true;
		}
		
		/*The get_capability function returns a listing of capabilities that the
      server supports.*/
	
		function get_capability()
		{
			if($this->state!="AUTHORIZATION")
			{
				$this->error= "Error : No Connection Found!<br>";
				return false;
			}
			if($this->put_line($this->tag." CAPABILITY"))
			{
				$response=$this->get_server_responce();
				if(substr($response,strpos($response,"$this->tag ")+strlen($this->tag)+1,2)!="OK")
				{
					$this->error= "Error : $response !<br>";
					return false;
				}
			}
			else
			{
				$this->error= "Error : Could not send User request. <br>";
				return false;
			}
			return $response;
		}
	
		/* noop function can be used as a periodic poll for new messages or
      message status updates during a period of inactivity*/
	
		function noop()
		{
			if($this->state!="AUTHORIZATION")
			{
				$this->error= "Error : No Connection Found!<br>";
				return false;
			}
			if($this->put_line($this->tag." NOOP"))
			{
				$response=$this->get_server_responce();
				if(substr($response,strpos($response,"$this->tag ")+strlen($this->tag)+1,2)!="OK")
				{
					$this->error= "Error : $response !<br>";
					return false;
				}
			}
			else
			{
				$this->error= "Error : Could not send User request. <br>";
				return false;
			}
			return true;
		}

		/* The logout function informs the server that the client is done with
      the connection. */

		function logout()
		{
		  if($this->state!="AUTHORIZATION")
		  {
				$this->error= "Error : No Connection Found!<br>";
				return false;
		  }
		  if($this->put_line($this->tag." LOGOUT"))
			{
				$response=$this->get_server_responce();
				if(substr($response,strpos($response,"$this->tag ")+strlen($this->tag)+1,2)!="OK")
				{
					$this->error= "Error : $response !<br>";
					return false;
				}
			}
			else
			{
				$this->error= "Error : Could not send User request. <br>";
				return false;
			}
			return true;
		}
			/// This function is used to authenticate the user
			// arguments $auth_str is a authorization String example LOGIN
			// $ans_str1 and $ans_str2 is a base 64 encoded answer string to server
			// Example if it authentication type is login then user your userid and password
			// as ans_str1 and ans_str2
			
		function authenticate($auth_str,$ans_str1="",$ans_str2="")
		{
			if($this->state=="DISCONNECTED")
			{
				$this->error= "Error : No Connection Found!<br>";
				return false;
			}
			if($this->state=="AUTHENTICATED")
			{
				$this->error= "Error : Already Authenticated!<br>";
				return false;
			}
			if($this->put_line($this->tag." AUTHENTICATE $auth_str"))
			{
				$response=$this->get_line();
				if(strtok($response," ")=="+")
				{
				   $ans_str1=base64_encode($ans_str1);
				   $this->put_line($ans_str1);
				}
				else
				{
					$this->error= "Error : $response !<br>";
					return false;
				}
				$response=$this->get_line();
				if(strtok($response," ")=="+")
				{
					$ans_str2=base64_encode($ans_str2);
					$this->put_line($ans_str2);
				}
				else
				{
					$this->error= "Error : $response !<br>";
					return false;
				}
				$response=$this->get_line();
				if(substr($response,strpos($response,"$this->tag ")+strlen($this->tag)+1,2)!="OK")
				{
					$this->error= "Error : $response !<br>";
					return false;
				}
			}
			else
			{
				$this->error= "Error : Could not send User request. <br>";
				return false;
			}
			$this->state="AUTHENTICATED";
			return $response;
		}
		///// this function is used to login into server
		/// $user is a valid username and $pwd is a valid password.
		function login($user,$pwd)
		{
			if($this->state=="DISCONNECTED")
			{
				$this->error= "Error : No Connection Found!<br>";
				return false;
			}
			if($this->state=="AUTHENTICATED")
			{
				$this->error= "Error : Already Authenticated!<br>";
				return false;
			}
			if($this->put_line($this->tag." LOGIN $user $pwd"))
			{
				$response=$this->get_server_responce();
				
				if(substr($response,strpos($response,"$this->tag ")+strlen($this->tag)+1,2)!="OK")
				{
					$this->error= "Error : $response !<br>";
					return false;
				}
			}
			else
			{
				$this->error= "Error : Could not send User request. <br>";
				return false;
			}
			$this->state="AUTHENTICATED";
			return true;
		}
	
	   /*   The select_mailbox command selects a mailbox so that messages in the
	   mailbox can be accessed. */ 
		function select_mailbox($mailbox_name)
			{
		  if($this->state=="AUTHORIZATION")
		  {
					$this->error= "Error : User is not authorised or logged in.!<br>";
					return false;
		  }
		  if($this->put_line($this->tag." SELECT $mailbox_name"))
			{
				$response=$this->get_server_responce();
				if(substr($response,strpos($response,"$this->tag ")+strlen($this->tag)+1,2)!="OK")
				{
					$this->error= "Error : $response !<br>";
					return false;
				}
			}
			else
			{
				$this->error= "Error : Could not send User request. <br>";
				return false;
			}
			$this->state="SELECTED";
			return $response;
		}
		/* The examin_mailbox command is identical to SELECT and returns the same
		  output; however, the selected mailbox is identified as read-only.*/ 
		function examin_mailbox($mailbox_name)
			{
		  if($this->state=="AUTHORIZATION")
		  {
			$this->error= "Error : User is not authorised or logged in.!<br>";
			return false;
		  }
		  if($this->put_line($this->tag." EXAMINE $mailbox_name"))
			{
				$response=$this->get_server_responce();
				if(substr($response,strpos($response,"$this->tag ")+strlen($this->tag)+1,2)!="OK")
				{
					$this->error= "Error : $response !<br>";
					return false;
				}
			}
			else
			{
				$this->error= "Error : Could not send User request. <br>";
				return false;
			}
			$this->state="SELECTED";
			return $response;
		}
			/* This function create a mail box*/
		function create_mailbox($mailbox_name)
			{
			if($this->state=="AUTHORIZATION")
			{
			$this->error= "Error : User is not authorised or logged in.!<br>";
			return false;
			}
			if($this->put_line($this->tag." CREATE $mailbox_name"))
			{
				$response=$this->get_server_responce();
				if(substr($response,strpos($response,"$this->tag ")+strlen($this->tag)+1,2)!="OK")
				{
					$this->error= "Error : $response !<br>";
					return false;
				}
			}
			else
			{
				$this->error= "Error : Could not send User request. <br>";
				return false;
			}
			return "Mailbox $mailbox_name created.";
		}
	
			/* This function delete exists mail box*/
		function delete_mailbox($mailbox_name)
		{
			if($this->state=="AUTHORIZATION")
			{
					$this->error= "Error : User is not authorised or logged in.!<br>";
					return false;
			}
			if($this->put_line($this->tag." DELETE $mailbox_name"))
			{
				$response=$this->get_server_responce();
				if(substr($response,strpos($response,"$this->tag ")+strlen($this->tag)+1,2)!="OK")
				{
					$this->error= "Error : $response !<br>";
					return false;
				}
			}
			else
			{
				$this->error= "Error : Could not send User request. <br>";
				return false;
			}
			return "Mailbox $mailbox_name deleted.";
		}
	
			/* This function rename exists mail box*/
		function rename_mailbox($old_mailbox_name,$new_mailbox_name)
		{
			if($this->state=="AUTHORIZATION")
			{
			$this->error= "Error : User is not authorised or logged in.!<br>";
			return false;
			}
			if($this->put_line($this->tag." RENAME $old_mailbox_name $new_mailbox_name"))
			{
				$response=$this->get_server_responce();
				if(substr($response,strpos($response,"$this->tag ")+strlen($this->tag)+1,2)!="OK")
				{
					$this->error= "Error : $response !<br>";
					return false;
				}
			}
			else
			{
				$this->error= "Error : Could not send User request. <br>";
				return false;
			}
			return "Mailbox $mailbox_name deleted.";
		}
			/* The subscribe_mailbox command adds the specified mailbox name to the
		  server's set of "active" or "subscribed" mailboxes */
		function subscribe_mailbox($mailbox_name)
		{
			if($this->state=="AUTHORIZATION")
			{
				$this->error= "Error : User is not authorised or logged in.!<br>";
				return false;
			}
			if($this->put_line($this->tag." SUBSCRIBE $mailbox_name"))
			{
				$response=$this->get_server_responce();
				if(substr($response,strpos($response,"$this->tag ")+strlen($this->tag)+1,2)!="OK")
				{
					$this->error= "Error : $response !<br>";
					return false;
				}
			}
			else
			{
				$this->error= "Error : Could not send User request. <br>";
				return false;
			}
			return "Mailbox $mailbox_name subscribed.";
		}
			/* The subscribe_mailbox command removes the specified mailbox name to the
		  server's set of "active" or "subscribed" mailboxes */
		function unsubscribe_mailbox($mailbox_name)
		{
			if($this->state=="AUTHORIZATION")
			{
				$this->error= "Error : User is not authorised or logged in.!<br>";
				return false;
			}
			if($this->put_line($this->tag." UNSUBSCRIBE $mailbox_name"))
			{
				$response=$this->get_server_responce();
				if(substr($response,strpos($response,"$this->tag ")+strlen($this->tag)+1,2)!="OK")
				{
					$this->error= "Error : $response !<br>";
					return false;
				}
			}
			else
			{
				$this->error= "Error : Could not send User request. <br>";
				return false;
			}
			return "Mailbox $mailbox_name unsubscribed.";
		}
	
		/* The list_mailbox command gets the specified list of mailbox
		  
					$ref_mail_box $wild_card   Interpretation
					Reference    Mailbox Name  Interpretation
				   ------------  ------------  --------------
				   ~smith/Mail/  foo.*         ~smith/Mail/foo.*
				   archive/      %             archive/%
				   #news.        comp.mail.*   #news.comp.mail.*
				   ~smith/Mail/  /usr/doc/foo  /usr/doc/foo
				   archive/      ~fred/Mail/*  ~fred/Mail/*
	  */
		function list_mailbox($ref_mail_box="",$wild_card="*")
		{
			if($this->state=="AUTHORIZATION")
			{
				$this->error= "Error : User is not authorised or logged in.!<br>";
				return false;
			}
			if(trim($ref_mail_box)=="")
				$ref_mail_box="\"\"";
			if($this->put_line($this->tag." LIST $ref_mail_box $wild_card"))
			{
				$response=$this->get_server_responce();
				if(substr($response,strpos($response,"$this->tag ")+strlen($this->tag)+1,2)!="OK")
				{
					$this->error= "Error : $response !<br>";
					return false;
				}
			}
			else
			{
				$this->error= "Error : Could not send User request. <br>";
				return false;
			}
			$temp_arr=explode("\r\n",$response);
			$return_arr=array();
			for($i=0;$i<count($temp_arr)-1;$i++)
			{
				$line=$temp_arr[$i];
				array_push($return_arr,substr($line,strrpos($line," ")));
			}
			return $return_arr;
		}
		
		//function is same as list_mailbox rather than it returns active mail box list
		function list_subscribed_mailbox($ref_mail_box="",$wild_card="*")
		{
			if($this->state=="AUTHORIZATION")
			{
				$this->error= "Error : User is not authorised or logged in.!<br>";
				return false;
			}
			if(trim($ref_mail_box)=="")
				$ref_mail_box="\"\"";
			if($this->put_line($this->tag." LSUB $ref_mail_box $wild_card"))
			{
				$response=$this->get_server_responce();
				if(substr($response,strpos($response,"$this->tag ")+strlen($this->tag)+1,2)!="OK")
				{
					$this->error= "Error : $response !<br>";
					return false;
				}
			}
			else
			{
				$this->error= "Error : Could not send User request. <br>";
				return false;
			}
			$temp_arr=explode("\r\n",$response);
			$return_arr=array();
			for($i=0;$i<count($temp_arr)-1;$i++)
			{
				$line=$temp_arr[$i];
				array_push($return_arr,substr($line,strrpos($line," ")));
			}
			return $return_arr;
		}
	
		//function is same as list_mailbox rather than it returns active mail box list
		function get_status($mail_box,$status_cmd)
		{
			if($this->state=="AUTHORIZATION")
			{
				$this->error= "Error : User is not authorised or logged in.!<br>";
				return false;
			}
			if($this->put_line($this->tag." STATUS $mail_box ($status_cmd)"))
			{
				$response=$this->get_server_responce();
				if(substr($response,strpos($response,"$this->tag ")+strlen($this->tag)+1,2)!="OK")
				{
					$this->error= "Error : $response !<br>";
					return false;
				}
			}
			else
			{
				$this->error= "Error : Could not send User request. <br>";
				return false;
			}
			return $response;
		}

		/* The CHECK command requests a checkpoint of the currently selected
		mailbox.  A checkpoint refers to any implementation-dependent
		housekeeping associated with the mailbox */
		function  check_mailbox()
		{
			if($this->state!="SELECTED")
			{
				$this->error= "Error : No mail box is selected.!<br>";
				return false;
			}
			if($this->put_line($this->tag." CHECK"))
			{
				$response=$this->get_server_responce();
				if(substr($response,strpos($response,"$this->tag ")+strlen($this->tag)+1,2)!="OK")
				{
					$this->error= "Error : $response !<br>";
					return false;
				}
			}
			else
			{
				$this->error= "Error : Could not send User request. <br>";
				return false;
			}
			return $response;
		}


		/* The close_mailbox command permanently removes from the currently selected
      mailbox all messages that have the \Deleted flag set, and returns
      to authenticated state from selected state.  No untagged EXPUNGE
      responses are sent.
		*/
		function  close_mailbox()
		{
			if($this->state!="SELECTED")
			{
				$this->error= "Error : No mail box is selected.!<br>";
				return false;
			}
			if($this->put_line($this->tag." CLOSE"))
			{
				$response=$this->get_server_responce();
				if(substr($response,strpos($response,"$this->tag ")+strlen($this->tag)+1,2)!="OK")
				{
					$this->error= "Error : $response !<br>";
					return false;
				}
			}
			else
			{
				$this->error= "Error : Could not send User request. <br>";
				return false;
			}
			return $response;
		}

	 /* The expunge_mailbox command permanently removes from the currently selected
      mailbox all messages that have the \Deleted flag set, and returns
      to authenticated state from selected state.  tagged EXPUNGE
      responses are sent.
		*/
		
		function  expunge_mailbox()
		{
			if($this->state!="SELECTED")
			{
				$this->error= "Error : No mail box is selected.!<br>";
				return false;
			}
			if($this->put_line($this->tag." EXPUNGE "))
			{
				$response=$this->get_server_responce();
				if(substr($response,strpos($response,"$this->tag ")+strlen($this->tag)+1,2)!="OK")
				{
					$this->error= "Error : $response !<br>";
					return false;
				}
			}
			else
			{
				$this->error= "Error : Could not send User request. <br>";
				return false;
			}
			return $response;
		}

			/* The search_mailbox command  searches the mailbox for messages that match
				the given searching criteria.  Searching criteria consist of one
				or more search keys. 
				  The defined search keys are as follows.  Refer to the Formal
				  Syntax section for the precise syntactic definitions of the
				  arguments.
			
				  <message set>  Messages with message sequence numbers
								 corresponding to the specified message sequence
								 number set
			
				  ALL            All messages in the mailbox; the default initial
								 key for ANDing.
			
				  ANSWERED       Messages with the \Answered flag set.
			
				  BCC <string>   Messages that contain the specified string in the
								 envelope structure's BCC field.
			
				  BEFORE <date>  Messages whose internal date is earlier than the
								 specified date.
			
				  BODY <string>  Messages that contain the specified string in the
								 body of the message.
			
				  CC <string>    Messages that contain the specified string in the
								 envelope structure's CC field.
			
				  DELETED        Messages with the \Deleted flag set.
			
				  DRAFT          Messages with the \Draft flag set.
			
				  FLAGGED        Messages with the \Flagged flag set.
			
				  FROM <string>  Messages that contain the specified string in the
								 envelope structure's FROM field.
			
				  HEADER <field-name> <string>
								 Messages that have a header with the specified
								 field-name (as defined in [RFC-822]) and that
								 contains the specified string in the [RFC-822]
								 field-body.
			
				  KEYWORD <flag> Messages with the specified keyword set.
			
				  LARGER <n>     Messages with an [RFC-822] size larger than the
								 specified number of octets.
			
				  NEW            Messages that have the \Recent flag set but not the
								 \Seen flag.  This is functionally equivalent to
								 "(RECENT UNSEEN)".
			
				  NOT <search-key>
								 Messages that do not match the specified search
								 key.
			
				  OLD            Messages that do not have the \Recent flag set.
								 This is functionally equivalent to "NOT RECENT" (as
								 opposed to "NOT NEW").
			
				  ON <date>      Messages whose internal date is within the
								 specified date.
			
				  OR <search-key1> <search-key2>
								 Messages that match either search key.
			
				  RECENT         Messages that have the \Recent flag set.
			
				  SEEN           Messages that have the \Seen flag set.
			
				  SENTBEFORE <date>
								 Messages whose [RFC-822] Date: header is earlier
								 than the specified date.
			
				  SENTON <date>  Messages whose [RFC-822] Date: header is within the
								 specified date.
			
				  SENTSINCE <date>
								 Messages whose [RFC-822] Date: header is within or
								 later than the specified date.
			
				  SINCE <date>   Messages whose internal date is within or later
								 than the specified date.
			
				  SMALLER <n>    Messages with an [RFC-822] size smaller than the
								 specified number of octets.
			
				  SUBJECT <string>
								 Messages that contain the specified string in the
								 envelope structure's SUBJECT field.
			
				  TEXT <string>  Messages that contain the specified string in the
								 header or body of the message.
			
				  TO <string>    Messages that contain the specified string in the
								 envelope structure's TO field.
			
				  UID <message set>
								 Messages with unique identifiers corresponding to
								 the specified unique identifier set.
			
				  UNANSWERED     Messages that do not have the \Answered flag set.
			
				  UNDELETED      Messages that do not have the \Deleted flag set.
			
				  UNDRAFT        Messages that do not have the \Draft flag set.
			
				  UNFLAGGED      Messages that do not have the \Flagged flag set.
			
				  UNKEYWORD <flag>
								 Messages that do not have the specified keyword
								 set.
			
				  UNSEEN         Messages that do not have the \Seen flag set.
			
			   Example:    search_mailbox("FLAGGED SINCE 1-Feb-1994 NOT FROM \"Smith\"")
				  
		*/
		
		function search_mailbox($search_cri,$charset="")
		{
			if($this->state!="SELECTED")
			{
				$this->error= "Error : No mail box is selected.!<br>";
				return false;
			}
			$search_cri =trim($search_cri);
			if(trim($charset)!="")
				$charset="CHARSET \"".trim(addslashes($charset))."\" ";	
			if($this->put_line($this->tag." SEARCH $charset$search_cri"))
			{
				$response=$this->get_server_responce();
				if(substr($response,strpos($response,"$this->tag ")+strlen($this->tag)+1,2)!="OK")
				{
					$this->error= "Error : $response !<br>";
					return false;
				}
			}
			else
			{
				$this->error= "Error : Could not send User request. <br>";
				return false;
			}
			$return=array();
			$temp_arr=explode("\r\n",$response);
			foreach($temp_arr as $line)
			if (substr($line, 0, 9) == "* SEARCH ") 
				$return = array_merge($return,explode(" ",substr($line, 9)));
			return $return;
		}
		
		/*The uid_search_mailbox as same as above but diffrence is that
			it takes uid number as $msg_set;
		*/
		
		function uid_search_mailbox($search_cri,$charset="")
		{
			if($this->state!="SELECTED")
			{
				$this->error= "Error : No mail box is selected.!<br>";
				return false;
			}
			$search_cri =trim($search_cri);
			if(trim($charset)!="")
				$charset="CHARSET \"".trim(addslashes($charset))."\" ";	
			if($this->put_line($this->tag." UID SEARCH $charset$search_cri"))
			{
				$response=$this->get_server_responce();
				if(substr($response,strpos($response,"$this->tag ")+strlen($this->tag)+1,2)!="OK")
				{
					$this->error= "Error : $response !<br>";
					return false;
				}
			}
			else
			{
				$this->error= "Error : Could not send User request. <br>";
				return false;
			}
			$return=array();
			$temp_arr=explode("\r\n",$response);
			foreach($temp_arr as $line)
			if (substr($line, 0, 9) == "* SEARCH ") 
				$return = array_merge($return,explode(" ",substr($line, 9)));
			return $return;
		}
		
		/*
		The fetch_mail function retrieves data associated with a message in the
		mailbox.  The data items to be fetched can be either a single atom
		or a parenthesized list.
		
		ALL            Macro equivalent to: (FLAGS INTERNALDATE RFC822.SIZE ENVELOPE)
		
		BODY           Non-extensible form of BODYSTRUCTURE.
		
		BODY[<section>]<<partial>>
		
		BODY.PEEK[<section>]<<partial>>
					 An alternate form of BODY[<section>] that does not
					 implicitly set the \Seen flag.
		
		BODYSTRUCTURE  The [MIME-IMB] body structure of the message.  This
					 is computed by the server by parsing the [MIME-IMB]
					 header fields in the [RFC-822] header and
					 [MIME-IMB] headers.
		
		ENVELOPE       The envelope structure of the message.  This is
					 computed by the server by parsing the [RFC-822]
					 header into the component parts, defaulting various
					 fields as necessary.
		
		FAST         Macro equivalent to: (FLAGS INTERNALDATE RFC822.SIZE)
		
		FLAGS       The flags that are set for this message.
		
		FULL        Macro equivalent to: (FLAGS INTERNALDATE RFC822.SIZE ENVELOPE BODY)
		
		INTERNALDATE   The internal date of the message.
		
		RFC822      Functionally equivalent to BODY[], differing in the
					 syntax of the resulting untagged FETCH data (RFC822
					 is returned).
		
		RFC822.HEADER  Functionally equivalent to BODY.PEEK[HEADER],
					 differing in the syntax of the resulting untagged
					 FETCH data (RFC822.HEADER is returned).
		
		RFC822.SIZE The [RFC-822] size of the message.
		
		RFC822.TEXT  Functionally equivalent to BODY[TEXT], differing in
					 the syntax of the resulting untagged FETCH data
					 (RFC822.TEXT is returned).
		
		UID            The unique identifier for the message.
		
		Example : fetch_mail( 2:4 (FLAGS BODY[HEADER.FIELDS (DATE FROM)])
		*/

		function  fetch_mail($msg_set,$msg_data_name)
		{
			if($this->state!="SELECTED")
			{
				$this->error= "Error : No mail box is selected.!<br>";
				return false;
			}
			$msg_set =trim($msg_set);
			$msg_data_name =trim($msg_data_name);
			if($this->put_line($this->tag." FETCH $msg_set ($msg_data_name)"))
			{
				$response=$this->get_server_responce();
				if(substr($response,strpos($response,"$this->tag ")+strlen($this->tag)+1,2)!="OK")
				{
					$this->error= "Error : $response !<br>";
					return false;
				}
			}
			else
			{
				$this->error= "Error : Could not send User request. <br>";
				return false;
			}
			return $response;
		}

		/*The uid_fetch_mail as same as above but diffrence is that
			it takes uid number as $msg_set;
		*/

		function  uid_fetch_mail($msg_set,$msg_data_name)
		{
			if($this->state!="SELECTED")
			{
				$this->error= "Error : No mail box is selected.!<br>";
				return false;
			}
			$msg_set =trim($msg_set);
			$msg_data_name =trim($msg_data_name);
			if($this->put_line($this->tag." UID FETCH $msg_set ($msg_data_name)"))
			{
				$response=$this->get_server_responce();
				if(substr($response,strpos($response,"$this->tag ")+strlen($this->tag)+1,2)!="OK")
				{
					$this->error= "Error : $response !<br>";
					return false;
				}
			}
			else
			{
				$this->error= "Error : Could not send User request. <br>";
				return false;
			}
			return $response;
		}

		/*
			  The store_mail_flag function alters data associated with a message in the
			  mailbox.  Normally, store_mail_flag will return the updated value of the
			  data with an untagged FETCH response.  A suffix of ".SILENT" in
			  the data item name prevents the untagged FETCH, and the server
			  SHOULD assume that the client has determined the updated value
			  itself or does not care about the updated value.
			   The currently defined data items that can be stored are:

			  FLAGS <flag list>	Replace the flags for the message with the
							 argument.  The new value of the flags are returned
							 as if a FETCH of those flags was done.
		
			  FLAGS.SILENT <flag list> 
							 Equivalent to FLAGS, but without returning a new value.
		
			  +FLAGS <flag list>
							 Add the argument to the flags for the message.  The
							 new value of the flags are returned as if a FETCH
							 of those flags was done.
		
			  +FLAGS.SILENT <flag list>
							 Equivalent to +FLAGS, but without returning a new
							 value.
		
			  -FLAGS <flag list>
							 Remove the argument from the flags for the message.
							 The new value of the flags are returned as if a
							 FETCH of those flags was done.
		
			  -FLAGS.SILENT <flag list>
							 Equivalent to -FLAGS, but without returning a new
							 value.
		
			  Example :store_mail_flag("3","+FLAGS","Seen");
		*/

		function  store_mail_flag($msg_set,$msg_data_name,$value)
		{
			if($this->state!="SELECTED")
			{
				$this->error= "Error : No mail box is selected.!<br>";
				return false;
			}
			$msg_set =trim($msg_set);
			$msg_data_name =trim($msg_data_name);
			$value =trim($value);
			if($this->put_line($this->tag." STORE $msg_set $msg_data_name ($value)"))
			{
				$response=$this->get_server_responce();
				if(substr($response,strpos($response,"$this->tag ")+strlen($this->tag)+1,2)!="OK")
				{
					$this->error= "Error : $response !<br>";
					return false;
				}
			}
			else
			{
				$this->error= "Error : Could not send User request. <br>";
				return false;
			}
			return $response;
		}
		
		/*The uid_store_mail_flag as same as above but diffrence is that
			it takes uid number as $msg_set;
		*/

		function  uid_store_mail_flag($msg_set,$msg_data_name,$value)
		{
			if($this->state!="SELECTED")
			{
				$this->error= "Error : No mail box is selected.!<br>";
				return false;
			}
			$msg_set =trim($msg_set);
			$msg_data_name =trim($msg_data_name);
			$value =trim($value);
			if($this->put_line($this->tag." UID STORE $msg_set $msg_data_name (\\$value)"))
			{
				$response=$this->get_server_responce();
				if(substr($response,strpos($response,"$this->tag ")+strlen($this->tag)+1,2)!="OK")
				{
					$this->error= "Error : $response !<br>";
					return false;
				}
			}
			else
			{
				$this->error= "Error : Could not send User request. <br>";
				return false;
			}
			return $response;
		}

		/* The copy_mail command copies the specified message(s) to the end of the
			specified destination mailbox
			Example : copy_mail("2:4","TEST")
			*/	

		function  copy_mail($msg_set,$mailbox)
		{
			if($this->state!="SELECTED")
			{
				$this->error= "Error : No mail box is selected.!<br>";
				return false;
			}
			$msg_set =trim($msg_set);
			$mailbox =trim($mailbox);
			if($this->put_line($this->tag." COPY $msg_set $mailbox"))
			{
				$response=$this->get_server_responce();
				if(substr($response,strpos($response,"$this->tag ")+strlen($this->tag)+1,2)!="OK")
				{
					$this->error= "Error : $response !<br>";
					return false;
				}
			}
			else
			{
				$this->error= "Error : Could not send User request. <br>";
				return false;
			}
			return $response;
		}
		
		/*The uid_copy_mail as same as above but diffrence is that
			it takes uid number as $msg_set;
		*/

		function  uid_copy_mail($msg_set,$mailbox)
		{
			if($this->state!="SELECTED")
			{
				$this->error= "Error : No mail box is selected.!<br>";
				return false;
			}
			$msg_set =trim($msg_set);
			$mailbox =trim($mailbox);
			if($this->put_line($this->tag." UID COPY $msg_set $mailbox"))
			{
				$response=$this->get_server_responce();
				if(substr($response,strpos($response,"$this->tag ")+strlen($this->tag)+1,2)!="OK")
				{
					$this->error= "Error : $response !<br>";
					return false;
				}
			}
			else
			{
				$this->error= "Error : Could not send User request. <br>";
				return false;
			}
			return $response;
		}

	}

?>
