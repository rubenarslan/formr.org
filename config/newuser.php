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

function emailExists($email) {
  $query="SELECT * FROM ".USERS." WHERE email='".mysql_real_escape_string($email)."'";
  $res=mysql_query($query);
  if($res===false)
    return _("Datenbankfehler"). mysql_error();
  /* $row=mysql_fetch_assoc($res); */
  if(mysql_num_rows($res)===1)
    return true;
  return false;
}

//Add rules for EMail Validation here
function emailValid($email) {
  if($email==='')
    return _("Keine Email Adresse angegeben");
  $tmp=emailExists($email);
  if($tmp===true)
    return _("Die angegebene Email Adresse existiert bereits");
  elseif($tmp===false)
    return true;
  else
    return $tmp;
  return true;
}

//Add rules for Password Validation here
function passwordValid($p0,$p1) {
  if($p0==='')
    return _("Kein Passwort angegeben");
  if($p1==='')
    return _("Keine Passwortwiederholung angegeben");
  if($p0!==$p1)
    return _("Die Passwörter stimmen nicht überein");
  if(!isInRange($p0,5,30))
    return _("Das Passwort muss zwischen 5 und 30 Zeichen lang sein");
  if(strpos($p0,' ')!==false)
    return _("Das Passwort darf keine Leerzeichen enthalten");
  return true;
}

class NewUser {

  public $status=false;
  private $errors=array();
  public $email;
  public $password;
  public $vpncode;
  
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

  //todo: not used
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
    if($this->status) {
      $secure_pwd=generateHash($this->password);
      $email=mysql_real_escape_string($this->email);
      $vpncode=mysql_real_escape_string($this->vpncode);
      $query="INSERT INTO ".USERS." (email,password,vpncode) VALUES('$email','$secure_pwd','$vpncode');";
      $ret=mysql_query($query);
      if($ret==false) {
        $this->status=false;
        $this->errors[]=_("Datenbankfehler"). mysql_error();
        return false;
      }
      return true;
    }
    return false;
  }
  
}
