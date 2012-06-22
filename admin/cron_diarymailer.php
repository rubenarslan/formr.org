<?php
require ('admin_header.php');
?>
<html><head><title>AN EMAIL A DAY</title></head><body>
<?php
# this shouldn't be sent while they're in the process of editing
# should maybe put the date in the vpndata table as well
ini_set("display_errors",1);

require ('includes/settings.php');

$db_host = $DBhost;
$db_user = $DBuser;
$db_pwd = $DBpass;
$database = $DBName;

if (!mysql_connect($db_host, $db_user, $db_pwd))
    die("Can't connect to database");

if (!mysql_select_db($database))
    die("Can't select database");
mysql_query("set names 'utf8';");

function queue_message($vpncode,$id,$abstime,$delta) {
    //get the email address through the vpncode, the email message template from the database and calculate the absolute time the message will be sent
    // then add an entry to the message queue
    //$query = "SELECT  ".EMAILSTABLE.".subject,".EMAILSTABLE.".body,".VPNDATATABLE.".email FROM ".EMAILSTABLE." JOIN ".VPNDATATABLE." ON ".VPNDATATABLE.".vpncode='$vpncode' WHERE ".EMAILSTABLE.".id=$id;";
    $query = "SELECT email FROM ".VPNDATATABLE." WHERE vpncode='$vpncode'";
    $result = mysql_query( $query ) or die( mysql_error() . "<br/>" . $query . "<br/> in queue_message" );
    $data = mysql_fetch_assoc( $result );

	$base_time = mktime(0,0,0,date('n'),date('j'),date('Y'));
	$time = $base_time + $delta + $abstime;

    if( $data['email'] != "" ) {
        $query = "INSERT INTO ".MESSAGEQUEUE." (email_address,email_id,send_time) VALUES ('".$data['email']."','".$id."','".$time."');";
        mysql_query($query) or die( mysql_error() . "<br/>" . $query . "<br/> in queue_message" );
    }
}


$yougetsome = mysql_query("SELECT vpncode,study FROM  ".VPNDATATABLE."
	WHERE email != '' AND 
	(study = 'diary' OR study = 'pretest') AND
	(laborbesucht IS NOT NULL OR tagebuch_zuerst=1)") OR die(mysql_error());

$emailt = mysql_query("SELECT id FROM ".EMAILSTABLE." WHERE name = 'zugang' LIMIT 1")  or die(mysql_error()); # id, name, subject, body
$emailt = mysql_fetch_assoc($emailt);
$email_id = $emailt['id'];

$mailsqueued = 0;
while($you = mysql_fetch_row($yougetsome)) {
	$vpncode = $you[0];
	$study = $you[1];
	queue_message($vpncode,$email_id,0,0);
	$mailsqueued++;
}

echo "Success. ".$mailsqueued." mails have been cued";


function get_tiny_url($url)  {  
  $ch = curl_init();  
  $timeout = 5;
  curl_setopt($ch,CURLOPT_URL,'http://tinyurl.com/api-create.php?url='.$url);  
  curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);  
  curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,$timeout);  
  $data = curl_exec($ch);
  curl_close($ch);  
  return $data;
}
$curtime = time();

$query = "SELECT ".VPNDATATABLE.".vpncode, ".MESSAGEQUEUE.".id,".EMAILSTABLE.".subject,".EMAILSTABLE.".body,".MESSAGEQUEUE.".email_address 
    FROM ".EMAILSTABLE." 
    JOIN ".MESSAGEQUEUE." ON ".MESSAGEQUEUE.".email_id = ".EMAILSTABLE.".id 
	LEFT JOIN ".VPNDATATABLE." ON ".VPNDATATABLE.".email = ".MESSAGEQUEUE.".email_address 
    WHERE ".MESSAGEQUEUE.".send_time < $curtime LIMIT 200;";


$query_resc = mysql_query($query) or die( mysql_error() );
// do the mailing
$message_id = array();
while( $email = mysql_fetch_assoc( $query_resc ) ) {
	$from = "From: info@vomstudiumindenberuf.de";
	$reply_to = "Reply-To: info@vomstudiumindenberuf.de";
	$content_type = "Content-Type: text/plain; charset=utf-8";
	$header = $from . PHP_EOL . $reply_to . PHP_EOL . $content_type . PHP_EOL . 'X-Mailer: PHP/' . phpversion();
	$url = "http://vomstudiumindenberuf.de/tagebuch/survey.php?vpncode=" . $email["vpncode"];
	$tiny_url = get_tiny_url($url);
	if($tiny_url=='' OR $tiny_url==NULL) $tiny_url = "<".$url.">";
	
	$body = str_replace("[loginlink]", $tiny_url,stripslashes($email['body']));

#if($email['email_address']=='dufnermi@hu-berlin.de')	{$mail = mail( "dufnermi@googlemail.com", $email['subject'], $body, $header ); echo "yay"; }
	$mail = mail( $email['email_address'], $email['subject'], $body, $header );
print( $email['email_address']. "<br>" );

    if( $mail ) {
        array_push($message_id,$email['id']);
    }
}

echo count($message_id) . " messages sent";

// collect our garbage
if( !empty($message_id) ) {
    $query = "DELETE FROM ".MESSAGEQUEUE." WHERE ".MESSAGEQUEUE.".id IN (" . implode(",",$message_id) . ")";
    mysql_query( $query ) or die( mysql_error() );
}


?>
</body></html>