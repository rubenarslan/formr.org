<?php
require ('admin_header.php');
# should maybe put the date in the vpndata table as well

require ('includes/settings.php');

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

$db_host = $DBhost;
$db_user = $DBuser;
$db_pwd = $DBpass;
$database = $DBName;

if (!mysql_connect($db_host, $db_user, $db_pwd))
    die("Can't connect to database");

if (!mysql_select_db($database))
    die("Can't select database");
mysql_query("set names 'utf8';");

$success = 0;
$mail = mysql_query("SELECT id, vpncode,email,study,tagebuch_zuerst,sex,studiengang,transferred_from_joomla
	FROM vpnueberblick
	WHERE email = '{$_GET['wem']}' LIMIT 1")  or die(mysql_error());

	$emailquery = mysql_query("SELECT * FROM ".EMAILSTABLE." WHERE name = 'reminder' LIMIT 1")  or die(mysql_error()); # id, name, subject, body
	$emailt = mysql_fetch_assoc($emailquery);

if(mysql_num_rows($mail)==1 AND mysql_num_rows($emailquery)==1) {
	$added = mysql_fetch_assoc($mail);
	$tiny_url = get_tiny_url("http://vomstudiumindenberuf.de/ueberblick/?vpncode=".$added['vpncode']);
	if($tiny_url=='' OR $tiny_url==NULL) $tiny_url = "<http://vomstudiumindenberuf.de/ueberblick/?vpncode=".$added['vpncode'].">";

    $from = "From: info@vomstudiumindenberuf.de";
    $to = $added['email'];
    $subject = $emailt['subject'];
	$body = str_replace("[loginlink]", $tiny_url,stripslashes($emailt['body']));
    mail($to,$subject,$body,$from); # TO CHANGE IN MAIN STUDY
	$success = 1;
}


header("Location: http://vomstudiumindenberuf.de/tagebuch/admin/kommandozentrale.php?linkverschickt=".$success."&email=".$added['email']);
exit;
?>