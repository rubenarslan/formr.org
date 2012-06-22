<?php

//@True if $min<=strlen($str)<=$max
function isInRange($str,$min,$max) {
  if(strlen(trim($str))<$min or strlen(trim($str))>$max)
    return false;
  return true;
}

function containsValidChars($username) { //return type check
  if(preg_match("/[^a-z0-9-_.]/",strtolower($username))!=0)
    return false;
  return true;
}

function usernameExists($username) {
  global $dbhost,$dbname,$dbuser,$dbpass,$lang;
  $conn=mysql_connect($dbhost,$dbuser,$dbpass);
  if(!$conn)
    return $lang['CONNECT_ERROR'];
  if(!mysql_select_db($dbname,$conn)) {
    mysql_close();
    return $lang['DBSELECT_ERROR'];
  }
  $query="SELECT * FROM users WHERE username='".mysql_real_escape_string($username)."'";
  $res=mysql_query($query);
  if($res===false)
    return $lang['QUERY_ERROR'];
  /* $row=mysql_fetch_assoc($res); */
  if(mysql_num_rows($res))
    return true;
  return false;
}

function urlExists($url,$user_id) {
  global $dbhost,$dbname,$dbuser,$dbpass,$lang;
  $conn=mysql_connect($dbhost,$dbuser,$dbpass);
  if(!$conn)
    return $lang['CONNECT_ERROR'];
  if(!mysql_select_db($dbname,$conn)) {
    mysql_close();
    return $lang['DBSELECT_ERROR'];
  }
  $query="SELECT * FROM websites WHERE url='".mysql_real_escape_string($url)."' AND user_id='".mysql_real_escape_string($user_id)."'";
  $res=mysql_query($query);
  if($res===false)
    return $lang['QUERY_ERROR'];
  if(mysql_num_rows($res))
    return true;
  return false;
}

function emailExists($email) {
  global $dbhost,$dbname,$dbuser,$dbpass,$lang;
  $conn=mysql_connect($dbhost,$dbuser,$dbpass);
  if(!$conn)
    return $lang['CONNECT_ERROR'];
  if(!mysql_select_db($dbname,$conn)) {
    mysql_close();
    return $lang['DBSELECT_ERROR'];
  }
  $query="SELECT * FROM users WHERE email='".mysql_real_escape_string($email)."'";
  $res=mysql_query($query);
  if($res===false)
    return $lang['QUERY_ERROR'];
  /* $row=mysql_fetch_assoc($res); */
  if(mysql_num_rows($res))
    return true;
  return false;
}

//Add rules for Username Validation here
function userValid($username) {
  global $lang;
  if($username==='')
    return $lang['UNAME_EMPTY'];
  if(!isInRange($username,3,20))
    return $lang['UNAME_RANGE'];
  if(!containsValidChars($username))
    return $lang['UNAME_CHAR'];
  $tmp=usernameExists($username);
  if($tmp===true)
    return $lang['UNAME_EXISTS'];
  elseif($tmp===false)
    return true;
  else
    return $tmp;
  return true;
}

//Add rules for EMail Validation here
function emailValid($email) {
  global $lang;
  if($email==='')
    return $lang['EMAIL_EMPTY'];
  if(!isInRange($email,8,30))
    return $lang['EMAIL_RANGE'];
  $tmp=emailExists($email);
  if($tmp===true)
    return $lang['EMAIL_EXISTS'];
  elseif($tmp===false)
    return true;
  else
    return $tmp;
  return true;
}

//Add rules for Password Validation here
function passwordValid($p0,$p1) {
  global $lang;
  if($p0==='')
    return $lang['PWD_EMPTY'];
  if($p1==='')
    return $lang['PWDR_EMPTY'];
  if($p0!==$p1)
    return $lang['PWD_MATCH'];
  if(!isInRange($p0,6,30))
    return $lang['PWD_RANGE'];
  if(strpos($p0,' ')!==false)
    return $lang['PWD_SPACES'];
  return true;
}

function bankNameValid($bank_name) {
  if(!isset($bank_name) or $bank_name=='')
    return "Bank Name muss angegeben werden";
  return true;
}

function blzValid($blz) {
  if(!isset($blz) or $blz=='')
    return "BLZ muss angegeben werden";
  return true;
}

function kontoNummerValid($ko) {
  if(!isset($ko) or $ko=='')
    return "Kontonummer muss angegeben werden";
  return true;
}

function fnameValid($fname) {
  global $lang;
  if($fname==='')
    return $lang['FNAME_EMPTY'];
  if(!isInRange($fname,2,30))
    return $lang['FNAME_RANGE'];
  return true;
}

function lnameValid($lname) {
  global $lang;
  if($lname==='')
    return $lang['LNAME_EMPTY'];
  if(!isInRange($lname,2,30))
    return $lang['LNAME_RANGE'];
  return true;
}

function streetValid($street) {
  global $lang;
  if($street==='')
    return $lang['STREET_EMPTY'];
  if(!isInRange($street,3,50))
    return $lang['STREET_RANGE'];
  return true;
}

function address2Valid($address2) {
  return true;
}

function cityValid($city) {
  global $lang;
  if($city==='')
    return $lang['CITY_EMPTY'];
  if(!isInRange($city,3,30))
    return $lang['CITY_RANGE'];
  return true;
}

function stateValid($state) {
  /* if($state==='') */
  /*   return "State field may not be empty"; */
  /* if(!isInRange($state,2,30)) */
  /*   return "State field length not in Range"; */
  return true;
}

function postalValid($postal) {
  global $lang;
  if($postal==='')
    return $lang['POSTAL_EMPTY'];
  if(!isInRange($postal,3,10))
    return $lang['POSTAL_RANGE'];
  if(!is_numeric($postal))
    return $lang['POSTAL_NUMERIC'];
  return true;
}

function countryValid($country) {
  global $lang;
  if($country==='')
    return $lang['COUNTRY_EMPTY'];
  if(!isInRange($country,3,50))
    return $lang['COUNTRY_RANGE'];
  return true;
}

function urlValid($url,$user_id) {
  global $lang;
  if($url==='')
    return $lang['WEBSITE_URL_EMPTY'];
  if(!isInRange($url,5,50))
    return $lang['WEBSITE_URL_RANGE'];
  $tmp=urlExists($url,$user_id);
  if($tmp===true)
    return $lang['WEBSITE_URL_EXISTS'];
  return true;
}

function uidValid($uid) {
  global $lang;
  if($uid==='')
    return $lang['UID_EMPTY'];
  if(!isInRange($uid,3,30))
    return $lang['UID_RANGE'];
  /* if(!is_numeric($uid)) */
  /*   return $lang['UID_NUMERIC']; */
  return true;
}

function associateTagValid($associate_tag) {
  global $lang;
  if($associate_tag==='')
    return $lang['ASSOCIATE_TAG_EMPTY'];
  if(!isInRange($associate_tag,3,30))
    return $lang['ASSOCIATE_TAG_RANGE'];
  /* if(!is_numeric($associate_tag)) */
  /*   return $lang['ASSOCIATE_TAG_NUMERIC']; */
  return true;
}

function accessKeyValid($access_key) {
  global $lang;
  if($access_key==='')
    return $lang['ACCESS_KEY_EMPTY'];
  if(!isInRange($access_key,3,30))
    return $lang['ACCESS_KEY_RANGE'];
  /* if(!is_numeric($access_key)) */
  /*   return $lang['ACCESS_KEY_NUMERIC']; */
  return true;
} 

function privateKeyValid($private_key) {
  global $lang;
  if($private_key==='')
    return $lang['PRIVATE_KEY_EMPTY'];
  if(!isInRange($private_key,3,40))
    return $lang['PRIVATE_KEY_RANGE'];
  /* if(!is_numeric($private_key)) */
  /*   return $lang['PRIVATE_KEY_NUMERIC']; */
  return true;
} 

function usageModelValid($usage_model) {
  if(!isset($usage_model))
    return "Usage Model my not be empty";
  if($usage_model=='')
    return "Usage Model my not be empty";
  if($usage_model!=0 and $usage_model!=1 and $usage_model!=2)
    return "WRong usage model";
  return true;
}

class NewUser {

  public $status=false;
  private $errors=array();
  public $fname;
  public $lname;
  public $email;
  public $password;
  public $default_lang;
  public $street;
  public $address2;
  public $city;
  public $state;
  public $postal;
  public $country;
  public $uid;
  public $vpncode;
  private $active;
  private $email_verified;
  private $email_token;
  
  function __construct($email,$password,$passwordr) {
    $email=strtolower(trim($email));
    $password=trim($password);
    $passwordr=trim($passwordr);

    //email validation
    $tmp=emailValid($email);
    if($tmp!==true)
      $this->errors[]=$tmp;

    //password validation
    $tmp=passwordValid($password,$passwordr);
    if($tmp!==true)
      $this->errors[]=$tmp;

    $this->email=$email;
    $this->password=$password;
    $this->vpncode=generate_vpncode();
    if(count($this->errors)==0)
      $this->status=true;
  }

  function GetErrors() {
    return $this->errors;
  }

  /* function sendMail() { */
  /*   /\* if(!sendActivationMail($this->email,$this->activation_token)) { *\/ */
  /*   /\*   $this->errors[]="send mail error"; *\/ */
  /*   /\*   return false; *\/ */
  /*   /\* } *\/ */
  /*   /\* return true; *\/ */
  /*   $this->errors[]=sendActivationMail($this->email,$this->activation_token); */
  /*   return false; */
  /* } */

  function sendMail() {
    if(!sendActivationMail($this->email,$this->email_token)) {
      $this->errors[]="send mail error";
      return false;
    }
    return true;
    /* $this->errors[]=sendActivationMail($this->email,$this->activation_token); */
    /* return false; */
  }

  function Register() {
    global $dbhost,$dbname,$dbuser,$dbpass,$lang,$default_associate_tag,$default_access_key,$default_private_key;
    if($this->status) {
      $secure_pwd=generateHash($this->password);
      $conn=mysql_connect($dbhost,$dbuser,$dbpass);
      if(!$conn) {
        $this->status=false;
        $this->errors[]=$lang['CONNECT_ERROR'];
        return false;
      }
      if(!mysql_select_db($dbname,$conn)) {
        $this->status=false;
        $this->errors[]=$lang['DBSELET_ERROR'];
        mysql_close();
        return false;
      }
      $email=mysql_real_escape_string($this->email);
      $vpncode=mysql_real_escape_string($this->vpncode);
      $id=uniqid();
      $query="INSERT INTO users (id,email,password,vpncode) VALUES('$id','$email','$secure_pwd','$vpncode');";
      $ret=mysql_query($query);
      if($ret==false) {
        $this->status=false;
        $this->errors[]=$lang['QUERY_ERROR'];
        mysql_close();
        return false;
      }
      mysql_close();
      return true;
    }
    return false;
  }
  
}


?>
