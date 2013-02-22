<?php

class User {

  public $status=false;
  private $errors=array();
  public $id;
  public $email;
  public $password;
  public $default_language;
  public $active;
  public $anonymous=false;
  public $vpncode;
  private $completed_studies=array();
  private $started_studies=array();

  function EligibleForStudyRun($study,$run) { 
    if(!is_object($study) or !is_object($run))
      return false;
    if(!$this->EligibleForRun($run))
      return false;
    if($study->id==$run->getFirstStudyId())
      return true;
    $prev_studies=$run->getPrevStudies($study);
    if(!$prev_studies)
      return false;
    foreach($prev_studies as $ps) {
      if(!$this->userHasCompletedStudy($ps))
        return false;
    }
    return true;
  }

  function userHasCompletedStudy($study) {
    if(!isset($study))
      return false;
    if(!$this->anonymous) {   
      $query="SELECT * FROM ".USERS_STUDIES." where user_id='".mysql_real_escape_string($this->id)."' AND study_id='".mysql_real_escape_string($study->id)."' AND completed = 1";
      $result=mysql_query($query);
      if(!$result or !mysql_num_rows($result) or mysql_num_rows($result)!=1)
        return false;
    }
    foreach($this->completed_studies as $cs) {
      if($cs==$study->id)
        return true;
    }
    return false;
  }

  function userCompletedStudy($study) {
    if(!isset($study))
      return false;
    $sid=$study->id;
    foreach($this->completed_studies as $cs) {
      if($cs==$sid)
        return true;
    }
    foreach($this->started_studies as $ss) {
      if($ss==$sid) {     
        if(!$this->anonymous) {
          $conn=mysql_connect($dbhost,$dbuser,$dbpass);
          if(!$conn)
            return false;
          if(!mysql_select_db($dbname,$conn))
            return false;
          $query="UPDATE ".USERS_STUDIES." SET completed = 1 WHERE study_id = '$sid' AND user_id = '$this->id'";
          $result=mysql_query($query);
          if(!$result)
            return false;
        }
        $this->completed_studies[]=$sid;
        return true;
      }
    }
    return false;
  }

  function userStartedStudy($study) {
    if(!isset($study))
      return false;
    $sid=$study->id;
    foreach($this->completed_studies as $cs) {
      if($cs==$sid)
        return false;
    }
    foreach($this->started_studies as $ss) {
      if($ss==$sid)
        return true;
    }
    if(!$this->anonymous) {
      $conn=mysql_connect($dbhost,$dbuser,$dbpass);
      if(!$conn)
        return false;
      if(!mysql_select_db($dbname,$conn))
        return false;
      $id=uniqid();
      $query="INSERT INTO ".USERS_STUDIES." (id,user_id,study_id) VALUES ('$id','$this->id','$sid');";
      $result=mysql_query($query);
      if(!$result)
        return false;
    }
    $this->started_studies[]=$sid;
    return true;
  }

  function getUsersStudies() {
    $query="SELECT * FROM ".USERS_STUDIES." where user_id='".mysql_real_escape_string($this->id)."' AND completed = 1";
    $result=mysql_query($query);
    if(!$result or mysql_num_rows($result)===false)
      return false;
    while($row=mysql_fetch_array($result)) {
      $this->completed_studies[]=$row['study_id'];
    }
    $query="SELECT * FROM ".USERS_STUDIES." where user_id='".mysql_real_escape_string($this->id)."' AND completed = 0";
    $result=mysql_query($query);
    if(!$result or mysql_num_rows($result)===false)
      return false;
    while($row=mysql_fetch_array($result)) {
      $this->started_studies[]=$row['study_id'];
    }
    return true;    
  }

  function EligibleForStudy($study) {
    $this->status=true;
    if(!isset($study) or !is_object($study)) {
      $this->errors[]=_("Interner Fehler");
      $this->status=false;
      return false;
    }
    if(!$study->public) {
      $this->errors[]=_("Diese Studie wurde noch nicht veröffentlicht");
      $this->status=false;
    }
    if($study->registered_req and $this->anonymous==true) {
      $this->errors[]=_("Sie müssen registriert sein um an dieser Studie teilzunehmen.");
      $this->status=false;
    }
    return $this->status;
  }

  function EligibleForRun($run) {
    $this->status=true;
    if(!isset($run) or !is_object($run)) {
      $this->errors[]=_("Interner Fehler");
      $this->status=false;
      return false;
    }
    if(!$run->public) {
      $this->errors[]=_("Diese Studie wurde noch nicht veröffentlicht");
      $this->status=false;
    }
    if($run->registered_req and $this->anonymous==true) {
      $this->errors[]=_("Sie müssen registriert sein um an dieser Studie teilzunehmen.");
      $this->status=false;
    }
    return $this->status;
  }

  function anonymous() {
    $this->email="anonymous@anonymous.anonymous";
    $this->id="000";
    $this->password="anonymous";
    $this->vpncode=generate_vpncode();
    $this->anonymous=true;
    $this->status=true;    
  }

  function login($email,$password) {
    global $available_languages;
    $query="SELECT * FROM ".USERS." ";
    $query.="WHERE email='$email'";
    $result=mysql_query($query);
    if($result===false) {
      $this->errors[]="Query Error";
      return;
    }
    if(mysql_num_rows($result)===false) {
      $this->errors[]=_("Die Login Daten sind nicht korrekt");
      return;
    } 
    $row=mysql_fetch_array($result);
    if(!isset($row['password'])) {
      $this->errors[]=_("Die Login Daten sind nicht korrekt");
      return;
    }
    $user_pwd=$row['password'];
    $secure_pwd=generateHash($password,$user_pwd);
    if($secure_pwd!==$user_pwd) {
      $this->errors[]=_("Die Login Daten sind nicht korrekt");
      return;
    }

    //todo: email verification and inactive user functionality currently not used
    /* if(!isset($row['email_verified']) or $row['email_verified']==false) { */
    /*   $this->errors[]="This Accounts email address has not been verified."; */
    /*   mysql_close(); */
    /*   return; */
    /* } */
    /* if(!isset($row['active']) or $row['active']==false) { */
    /*   $this->errors[]="This account has not been activated."; */
    /*   mysql_close(); */
    /*   return; */
    /* } */

    /* $this->errors[]=$this->getUsersStudies(); */
    /* return; */

    if(!$this->getUsersStudies()) {
      $this->errors[]=_("Interner Fehler");
      return;
    }
    $id=isset($row['id']) ? $row['id'] : '';
    $vpncode=isset($row['vpncode']) ? $row['vpncode'] : '';
    $this->email=$email;
    $this->id=$id;
    $this->vpncode=$vpncode;
    $this->password=$user_pwd;
    $this->anonymous=false;
    $this->status=true;
  }

  function fillIn($id) {
    global $available_languages;
    $query="SELECT * FROM ".USERS." ";
    $query.="WHERE id='$id'";
    $result=mysql_query($query);
    if($result===false) {
      $this->errors[]="Query Error";
      return;
    }
    if(mysql_num_rows($result)===false) {
      $this->errors[]=_("Interner Fehler");
      return;
    }
    $row=mysql_fetch_array($result);
    $id=isset($row['id']) ? $row['id'] : '';    
    $vpncode=isset($row['vpncode']) ? $row['vpncode'] : '';    
    $this->email=$row['email'];
    $this->password=$row['password'];;
    $this->id=$id;
    $this->vpncode=$vpncode;
    $this->default_language=$row['default_language'];;
    $this->status=true;
  }
  
  function changePassword($password,$password_new,$password_newr) {
    $password=trim($password);
    $password_new=trim($password_new);
    $password_newr=trim($password_newr);
    $tmp=passwordValid($password_new,$password_newr);
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
      return;
    }
    $query="SELECT password FROM ".USERS." ";
    $query.="WHERE id='$this->id'";
    $result=mysql_query($query);
    if(mysql_num_rows($result)===false) {
      $this->errors[]=_("Datenbankfehler"). mysql_error();
      return;
    }
    $row=mysql_fetch_array($result);
    if(!isset($row['password'])) {
      $this->errors[]=_("Datenbankfehler"). mysql_error();
      return;
    }
    $user_pwd=$row['password'];
    $secure_pwd=generateHash($password,$user_pwd);
    if($secure_pwd!==$user_pwd) {
      $this->errors[]=_("Das eingegebene Passwort ist nicht korrekt");
      return;
    }
    $secure_pwd_new=generateHash($password_new);
    $query="UPDATE ".USERS." SET password = '$secure_pwd_new' WHERE email = '$this->email'";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]=_("Datenbankfehler"). mysql_error();
      return;
    }
    $this->password=$secure_pwd_new;
    $this->status=true;
  }

  function changeEmail($email) {
    $email=strtolower(trim($email));
    $tmp=emailValid($email);
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
      return;
    }
    /* $token=generateActivationToken(); */
    /* $query="UPDATE ".USERS." SET email = '$email', email_verified = 0, email_token='$token' WHERE email = '$this->email'"; */
    $query="UPDATE ".USERS." SET email = '$email' WHERE email = '$this->email'";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]=_("Datenbankfehler"). mysql_error();
      return;
    }
    $this->email=$email;
    /* if(!sendActivationMail($email,$token)) { */
    /*   $this->status=false; */
    /*   $this->errors[]="Could not execute query"; */
    /*   mysql_close(); */
    /*   return; */
    /* } */
    /* $this->email_verified=0; */
    $this->status=true;
  }

  function GetErrors() {
    $tmp=$this->errors;
    $this->errors=array();
    return $tmp;
  }

  function logout() {
    if(isset($_SESSION['userObj'])) {
      $_SESSION['userObj']=NULL;
      unset($_SESSION['userObj']);
    }
  }

  function GetStudies() {
    $studies=array();
    $id=(isset($this->id))?mysql_real_escape_string($this->id):'';
    $query="SELECT * FROM ".STUDIES." WHERE user_id='".$id."'";
    $result=mysql_query($query);
    if(mysql_num_rows($result)==false) {
      $this->status=false;
      return false;
    }
    if(!$result) {
      $this->status=false;
      $this->errors[]=_("Datenbankfehler"). mysql_error();
      return false;
    }
    if(mysql_num_rows($result)!=false) {
      while($row=mysql_fetch_array($result)) {
        $s=new Study;
        $s->fillIn($row['id']);
        if($s->status)
          $studies[]=$s;
      }
    }
    return $studies;
  }
  function GetRuns() {
    $runs=array();
    $id=(isset($this->id))?mysql_real_escape_string($this->id):'';
    $query="SELECT * FROM ".RUNS." WHERE user_id='".$id."'";
    $result=mysql_query($query);
    if(mysql_num_rows($result)==false) {
      $this->status=false;
      return false;
    }
    if(!$result) {
      $this->status=false;
      $this->errors[]=_("Datenbankfehler"). mysql_error();
      return false;
    }
    if(mysql_num_rows($result)!=false) {
      while($row=mysql_fetch_array($result)) {
        $r=new Run;
        $r->fillIn($row['id']);
        if($r->status)
          $runs[]=$r;
      }
    }
    return $runs;
  }

  function ownsStudy($id) {
    $query="SELECT * FROM ".STUDIES." ";
    $query.="WHERE id='$id'";
    $result=mysql_query($query);
    if($result===false) {
      return false;
    }
    if(mysql_num_rows($result)===false) {
      return false;
    } 
    $row=mysql_fetch_array($result);
    if($row==false) {
      return false;
    }
    if(isset($row['user_id']) and $row['user_id']==$this->id)
      return true;
    return false;
  }

  function ownsRun($id) {
    $query="SELECT * FROM ".RUNS." ";
    $query.="WHERE id='$id'";
    $result=mysql_query($query);
    if($result===false) {
      return false;
    }
    if(mysql_num_rows($result)===false) {
      return false;
    } 
    $row=mysql_fetch_array($result);
    if($row==false) {
      return false;
    }
    if(isset($row['user_id']) and $row['user_id']==$this->id)
      return true;
    return false;
  }
  
  function GetAvailableStudies() {
    $studies=array();
    $id=(isset($this->id))?mysql_real_escape_string($this->id):'';
    $query="SELECT * FROM ".STUDIES." WHERE public='1' ";
    $result=mysql_query($query);
    if(mysql_num_rows($result)==false) {
      $this->status=false;
      return false;
    }
    if(!$result) {
      $this->status=false;
      $this->errors[]=_("Datenbankfehler"). mysql_error();
      return false;
    }
    if(mysql_num_rows($result)!=false) {
      while($row=mysql_fetch_array($result)) {
        $s=new Study;
        $s->fillIn($row['id']);
        if($s->status)
          $studies[]=$s;
      }
    }
    return $studies;
  }

  function GetAvailableRuns() {
    $runs=array();
    $id=(isset($this->id))?mysql_real_escape_string($this->id):'';
    $query="SELECT * FROM ".RUNS." WHERE public=1";
    $result=mysql_query($query);
    if(mysql_num_rows($result)===false) {
      $this->status=false;
      return false;
    }
    if(!$result) {
      $this->status=false;
      $this->errors[]=_("Datenbankfehler"). mysql_error();
      return false;
    }
    if(mysql_num_rows($result)!=false) {
      while($row=mysql_fetch_array($result)) {
        $r=new Run;
        $r->fillIn($row['id']);
        if($r->status)
          $runs[]=$r;
      }
    }
    return $runs;
  }

}


?>