<?php
require ('admin_header.php');
?>

<html><head><title>Partner registered</title></head><body>
<?php
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

$howmanybefore = mysql_query("SELECT id FROM vpnueberblick WHERE email != ''");
$howmanybefore = mysql_num_rows($howmanybefore);
/*
*** VPNUEBERBLICK ***
  id INT(11) NOT NULL AUTO_INCREMENT, 
    vpncode VARCHAR(100) NOT NULL, 
    email VARCHAR(100) DEFAULT NULL, 
    study VARCHAR(100),
    tagebuch_zuerst TINYINT(1),
	sex TINYINT(1),
	studiengang TINYINT(1),*/
// sending query
### query to transfer joomla users to vpndata table. we could also use the password hashes or something as vpn codes, maybe better for anonymisation.
#$tagebuch_zuerst = rand(0,1);
$update = mysql_query("INSERT IGNORE INTO vpnueberblick (`id` , `vpncode`  , `email` , `study`, `tagebuch_zuerst`, `sex`, `studiengang`, `transferred_from_joomla`)
/*	SELECT '', SHA1( u1.password ) , u1.email, 'pretest', ROUND(RAND()), (j1.cb_geschlecht='männlich'), j1.cb_welchesstudium, CURDATE()
*/	SELECT '', SHA1( u1.password ) , u1.email, 'pretest', 0, (j1.cb_geschlecht='männlich'), j1.cb_welchesstudium, CURDATE()
	FROM jos_users AS u1
	LEFT JOIN jos_comprofiler AS j1 ON u1.id=j1.user_id
	WHERE j1.confirmed  = 1 AND u1.block = 0 AND j1.approved != 2") or die(mysql_error()); # j1.approved != 2 is the red cross, they start out with the clock. j1.confirmed is quite unneccessary actually.

$transferred= mysql_insert_id();

if($transferred>0) {
	$mail = mysql_query("SELECT id, vpncode,email,study,tagebuch_zuerst,sex,studiengang,transferred_from_joomla
	FROM vpnueberblick
	WHERE transferred_from_joomla=CURDATE()")  or die(mysql_error());

	$emailt = mysql_query("SELECT * FROM ".EMAILSTABLE." WHERE name = 'invitation' LIMIT 1")  or die(mysql_error()); # id, name, subject, body
	$emailt = mysql_fetch_assoc($emailt);

	while($added = mysql_fetch_assoc($mail)) {

		$tiny_url = get_tiny_url("http://vomstudiumindenberuf.de/ueberblick/?vpncode=".$added['vpncode']);

		if($tiny_url=='' OR $tiny_url==NULL) $tiny_url = "<http://vomstudiumindenberuf.de/ueberblick/?vpncode=".$added['vpncode'].">";

	    $from = "From: info@vomstudiumindenberuf.de";
	    $to = $added['email'];
	    $subject = $emailt['subject'];
		$body = str_replace("[loginlink]", $tiny_url,stripslashes($emailt['body']));

	    mail($to,$subject,$body,$from); # TO CHANGE IN MAIN STUDY
	}
}

$howmany = mysql_query("SELECT id FROM vpnueberblick WHERE email != ''");

$many =mysql_num_rows($howmany);
$howmany = mysql_num_rows($howmany);

echo "Success. ".($howmany-$howmanybefore)."  people have been transferred. last insert id ".$transferred;

echo "<br> $many transferred people in total";


?>
</body></html>