<?php
error_reporting(-1);
date_default_timezone_set('Europe/Berlin');
ini_set('display_errors',1);
if (!function_exists('_')) {
	function _($text) {
		return $text;
	}
}

require_once INCLUDE_ROOT."../../Config/database.php";

$db = new DATABASE_CONFIG();
$DBhost = $dbhost= $db->default['host'];
$DBname = $dbname= $db->default['database'];
$DBuser = $dbuser= $db->default['login'];
$DBpass = $dbpass= $db->default['password'];
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

require_once INCLUDE_ROOT."config/newuser.php";
require_once INCLUDE_ROOT."config/user.php";
require_once INCLUDE_ROOT."config/study.php";
require_once INCLUDE_ROOT."config/run.php";

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
