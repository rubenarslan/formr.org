<?php

function queue_message($vpncode,$id,$abstime,$delta) {
    //get the email address through the vpncode, the email message template from the database and calculate the absolute time the message will be sent
    // then add an entry to the message queue
    //$query = "SELECT  ".EMAILSTABLE.".subject,".EMAILSTABLE.".body,".VPNDATATABLE.".email FROM ".EMAILSTABLE." JOIN ".VPNDATATABLE." ON ".VPNDATATABLE.".vpncode='$vpncode' WHERE ".EMAILSTABLE.".id=$id;";
    $query = "SELECT email FROM ".VPNDATATABLE." WHERE vpncode='$vpncode'";
    $result = mysql_query( $query ) or die( exception_handler(mysql_error() . "<br/>" . $query . "<br/> in queue_message" ));
    $data = mysql_fetch_assoc( $result );

	$base_time = mktime(0,0,0,date('n'),date('j'),date('Y'));
	$time = $base_time + $delta + $abstime;

    if( $data['email'] != "" ) {
        $query = "INSERT INTO ".MESSAGEQUEUE." (email_id,vpncode,email_address,send_time) VALUES ('".$id."','".$vpncode."','".$data['email']."','".$time."');";
        mysql_query($query) or die( exception_handler(mysql_error() . "<br/>" . $query . "<br/> in queue_message" ));
    }
}



function queue_email($vpncode,$type) {
    if($type == 'loop') {
        // if this looped study sends out reminders after each iteration, queue them now
        $email_queue = "SELECT ".STUDIESTABLE.".loopemail,".STUDIESTABLE.".loopemail_id,".EMAILSTABLE.".delta,".EMAILSTABLE.".abstime FROM ".STUDIESTABLE." JOIN ".EMAILSTABLE." ON ".STUDIESTABLE.".loopemail_id = ".EMAILSTABLE.".id WHERE ".STUDIESTABLE.".name = '". $study."';";
        $result = mysql_query( $email_queue ) or die(exception_handler(mysql_error() . "<br/>" . $query . "<br/> in queue_email" ));
        $email_data = mysql_fetch_assoc($result);
        if($email_data['loopemail']) {
            ///now queue the thing in the message queue with the right absolute timestamp and message id
            queue_message($vpncode,$email_data['loopemail_id'],$email_data['abstime'],$email_data['delta']);
        }
    } elseif($type == 'post') {
        /* do the email queuing if necessary */
        $email_queue = "SELECT ".STUDIESTABLE.".postemail,".STUDIESTABLE.".postemail_id,".EMAILSTABLE.".delta,".EMAILSTABLE.".abstime FROM ".STUDIESTABLE." JOIN ".EMAILSTABLE." ON ".STUDIESTABLE.".postemail_id = ".EMAILSTABLE.".id WHERE ".STUDIESTABLE.".name = '". $study."';";
        $result = mysql_query( $email_queue ) or die( exception_handler(mysql_error() . "<br/>" . $query . "<br/> in sendmail" ));
        $email_data = mysql_fetch_assoc($result);
        if($email_data['postemail']) {
            ///now queue the thing in the message queue with the right absolute timestamp and message id
            queue_message($vpncode,$email_data['postemail_id'],$email_data['abstime'],$email_data['delta']);
        }
    }
}


function send_invitation($vpncode) {
    $query = "SELECT * FROM ".VPNDATATABLE." WHERE vpncode='".$vpncode."'";
    $data = mysql_query( $query ) or die(  exception_handler(mysql_error() . "<br/>" . $query . "<br/> in send_invitation" ));
    $data = mysql_fetch_assoc($data);

	$config = get_config();

    $url = "http://" . get_base_path() . "/survey.php?vpncode=" . $vpncode;
	// $date = "Date: " . date("d-m-Y H.i.s T");
    $from = "From: " . $config["email_header_from"];
    $reply_to = "Reply-To: " . $config["email_header_reply_to"];
	$cc = "Cc: " . $config["email_header_cc"];
	$bcc = "Bcc: " . $config["email_header_bcc"];
	$content_type = "Content-Type: text/plain; charset=utf-8";
	$header = $from . PHP_EOL . $reply_to . PHP_EOL . $cc . PHP_EOL . $bcc . PHP_EOL . $content_type . PHP_EOL . 'X-Mailer: PHP/' . phpversion();
    $to = $data["email"];
    $subject = $config["email_subject_text"];
    $body = $config["email_body_text"] . PHP_EOL . $url;

    $mail = mail($to,$subject,$body,$header);
	return $mail;
}

function send_feedback_permalink($vpncode) {
    $query = "SELECT * FROM ".VPNDATATABLE." WHERE vpncode='".$vpncode."'";
    $data = mysql_query( $query ) or die(  exception_handler(mysql_error() . "<br/>" . $query . "<br/> in send_invitation" ));
    $data = mysql_fetch_assoc($data);

	$config = get_config();

    $url = "http://" . get_base_path() . $_SERVER['REQUEST_URI'];
	// $date = "Date: " . date("d-m-Y H.i.s T");
    $from = "From: " . $config["email_header_from"];
    $reply_to = "Reply-To: " . $config["email_header_reply_to"];
	$cc = "Cc: " . $config["email_header_cc"];
	$bcc = "Bcc: " . $config["email_header_bcc"];
	$content_type = "Content-Type: text/plain; charset=utf-8";
	$header = $from . PHP_EOL . $reply_to . PHP_EOL . $cc . PHP_EOL . $bcc . PHP_EOL . $content_type . PHP_EOL . 'X-Mailer: PHP/' . phpversion();
    $to = $data["email"];
    $subject = $config["email_subject_text"];
    $body = $url;

    $mail = mail($to,$subject,$body,$header);
	return $mail;
}
