<?php
error_reporting(E_ALL);
ini_set('display_errors','on');
if (!function_exists('_')) {
	function _($text) {
		return $text;
	}
}

#require_once $_SERVER['DOCUMENT_ROOT']."/zwang/app/Config/database.php";
require_once(dirname(__FILE__)."/../../../Config/database.php");

$db = new DATABASE_CONFIG();
$dbhost= $db->default['host'];
$dbname= $db->default['database'];
$dbuser= $db->default['login'];
$dbpass= $db->default['password'];
if(isset($db->default['port'])) $dbhost .= ':'.$db->default['port'];

/* function sqlConnect() { */
  /* global $dbhost,$dbname,$dbuser,$dbpass;   */
mysql_connect($dbhost,$dbuser,$dbpass) or die("Datenbank-Verbindung fehlgeschlagen. Bitte versuchen Sie es noch einmal.");
@mysql_select_db("$dbname") or die("Datenbank-Auswahl fehlgeschlagen. Bitte versuchen Sie es noch einmal.");
/* } */

/*table names*/
$prefix = 'survey_';
define('USERS',$prefix . 'users');
define('STUDIES',$prefix . 'studies');
define('USERS_STUDIES',$prefix . 'users_studies');
define('RUNS',$prefix . 'runs');
define('RUN_DATA',$prefix . 'run_data');

require_once "newuser.php";
require_once "user.php";
require_once "study.php";
require_once "run.php";

session_start();

if(!isset($_SESSION["userObj"])) {
  $user=new User;
  $user->anonymous();
  $_SESSION['userObj']=$user;
}
if(is_object($_SESSION["userObj"]))
  $currentUser=$_SESSION["userObj"];

function generate_vpncode() {
    $charcters = array("∂","√","ç","∫","µ","≤","≥","†","®","∑","œ","Ω","≈","ß","ƒ","©","∆","˚","¬","¥","ø","π");
    $letters = array("a","b","c","d","e","f","g","h","i","j","k","l","m","n","o","p","q","r","s","t","u","v","w","x","y","z");
    $string = "";
    for($i=0; $i < 10; $i++) {
        $string = $string . $charcters[ rand(0,sizeof($charcters) - 1) ] . $letters[ rand(0,sizeof($letters) - 1) ];
    }
    $vpn = sha1($string);
    return $vpn;
}

//$available_languages[0] is default language 
$available_languages=array("de","en");

//pages in main navigation
$pages=array("contact.php" => "Contact",
             "faq.php" => "FAQ",
             "index.php" => "Home",
             );

$language=getLanguage();

//if $lang is a valid language, $lang is returned. otherwise the default language is returned
function validLangOrDefault($lang) {
  global $available_languages;
  foreach($available_languages as $l) {
    if($lang===$l)
      return $lang;
  }
  return $available_languages[0];
}

//tries to find out the users prefered language vie GET,POST,SESSION,COOKIE. If no language is found
//default language will be used
function getLanguage() {
  global $currentUser,$available_languages;
  $lang=$available_languages[0];
  if(isset($_GET['lang']))
    $lang=$_GET['lang'];
  elseif(isset($_POST['lang']))
    $lang=$_POST['lang'];
  elseif(isset($_SESSION['lang']))
    $lang=$_SESSION['lang'];
  elseif(isset($_COOKIE['lang']))
    $lang=$_COOKIE['lang'];
  elseif(userIsLoggedIn() and isset($currentUser))
    $lang=$currentUser->default_language; //todo: set default lang in user profile
  return validLangOrDefault($lang);
}

function setLanguage($lang) {
  $_SESSION['lang']=$lang;
  setcookie('lang',$lang);
}

//todo: (de)activate email verification/activation via root admin acp
//user account activation via token validation. not used
/* function tokenValid($email,$token) { */
/*   global $dbhost,$dbname,$dbuser,$dbpass,$lang; */
/*   $conn=mysql_connect($dbhost,$dbuser,$dbpass); */
/*   if(!$conn) */
/*     return $lang['CONNECT_ERROR']; */
/*   if(!mysql_select_db($dbname,$conn)) { */
/*     mysql_close(); */
/*     return $lang['DBSELECT_ERROR']; */
/*   } */
/*   $query="UPDATE ".USERS." SET email_verified = 1 WHERE email='".mysql_real_escape_string(trim($email))."' AND email_token='".mysql_real_escape_string(trim($token))."'"; */
/*   $res=mysql_query($query); */
/*   if($res!==true) */
/*     return "Query error"; */
/*   mysql_close(); */
/*   return true; */
/* } */
/* function validateToken($token) { */
/*   global $dbhost,$dbname,$dbuser,$dbpass,$lang; */
/*   $conn=mysql_connect($dbhost,$dbuser,$dbpass); */
/*   if(!$conn) */
/*     return $lang['CONNECT_ERROR']; */
/*   if(!mysql_select_db($dbname,$conn)) { */
/*     mysql_close(); */
/*     return $lang['DBSELECT_ERROR']; */
/*   } */
/*   $query="SELECT email_token FROM ".USERS." WHERE email_token='".mysql_real_escape_string(trim($token))."'"; */
/*   $res=mysql_query($query); */
/*   if(mysql_num_rows($res)===false or mysql_num_rows($res)===0) */
/*     return true; */
/*   return false; */
/* } */
/* function generateActivationToken() { */
/*   $token; */
/*   do { */
/*     $token=md5(uniqid(mt_rand(),true)); */
/*   } while(!validateToken($token)); */
/*   return $token; */
/* } */
/* function sendActivationMail($mail,$token) { */
/*   if($mail==='' or $token==='') */
/*     return false; */
/*   $link="http://[URL]/activate_account.php?email=".$mail."&token=".$token.""; */
/*   //return mail($mail,"Activate Account",$link); */
/*   return true; */
/* } */


function generateHash($plainText,$salt=null) {
  if($salt===null) 
    $salt=substr(md5(uniqid(rand(),true)),0,25);
  else
    $salt = substr($salt, 0, 25);
  return $salt . sha1($salt . $plainText);
}

function errorOutput($errors) {
  if(count($errors)<1) {
    return;
  } else {
    echo "<ul>";
    foreach($errors as $error) {
      echo "<li>".$error."</li>";
    }
    echo "</ul>";
  }
}

function userIsLoggedIn() {
  global $currentUser;
  global $dbhost,$dbname,$dbuser,$dbpass;
  if($currentUser==NULL)
    return false;
  if($currentUser->anonymous==true)
    return false;
  /* $conn=mysql_connect($dbhost,$dbuser,$dbpass); */
  /* if(!$conn) */
  /*   return false; */
  /* if(!mysql_select_db($dbname,$conn)) { */
  /*   mysql_close(); */
  /*   return false; */
  /* } */
  $email=$currentUser->email;
  $pwd=$currentUser->password;
  $query="SELECT email, password FROM ".USERS." "; 
  $query.="WHERE email='$email' AND password='$pwd'";
  $ret=mysql_query($query);
  /* mysql_close(); */
  if($ret!==false)
    return true;
  return false;
}

function userIsAdmin() {
  global $currentUser;
  if(!userIsLoggedIn())
    return false;
  global $dbhost,$dbname,$dbuser,$dbpass;
  /* $conn=mysql_connect($dbhost,$dbuser,$dbpass); */
  /* if(!$conn) */
  /*   return false; */
  /* if(!mysql_select_db($dbname,$conn)) { */
  /*   mysql_close(); */
  /*   return false; */
  /* } */
  $email=$currentUser->email;
  $pwd=$currentUser->password;
  $query="SELECT admin FROM ".USERS." ";
  $query.="WHERE email='$email' AND password='$pwd'";
  $ret=mysql_query($query);
  if($ret===false)
    return false;
  if(mysql_num_rows($ret)===false)
    return false;
  $row=mysql_fetch_array($ret);
  if(isset($row['admin']) && $row['admin']==='1')
    return true;
  return false;
}

?>