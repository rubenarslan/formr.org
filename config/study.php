<?php

function nameExists($name) {
  $query="SELECT * FROM ".STUDIES." WHERE name='".mysql_real_escape_string($name)."'";
  $res=mysql_query($query);
  if($res===false)
    return _("nameExists Datenbankfehler"). mysql_error();
  if(mysql_num_rows($res))
    return true;
  return false;
}

function nameValid($name) {
  $name=trim($name);
  if($name=="")
    return _("Kein Studienname angegeben");
  if(!isInRange($name,3,20))
    return _("Studienname muss zwischen 3 und 20 Zeichen lang sein");
  $tmp=nameExists($name);
  if($tmp==true)
    return _("Eine Studie mit diesem Namen existiert bereits");
  return true;
}

function prefixExists($prefix) {
  $query="SELECT * FROM ".STUDIES." WHERE prefix='".mysql_real_escape_string($prefix)."'";
  $res=mysql_query($query);
  if($res===false)
    die(_("prefixExists Datenbankfehler").mysql_error());
  if(mysql_num_rows($res))
    return true;
  return false;
}

function prefixValid($prefix) {
  $prefix=trim($prefix);
  if($prefix=="")
    return _("Kein Datenbankprefix angegeben");
  if(!isInRange($prefix,3,20))
    return _("Das Datenbankprefix muss zwischen 3 und 20 Zeichen lang sein");
  $tmp=prefixExists($prefix);
  if($tmp==true)
    return _("Das Datenbankprefix existiert bereits");
  return true;
}

class Study {
  public $status=false;
  private $errors=array();
  public $id;
  public $user_id;
  public $name;
  public $logo_name;
  public $prefix;
  public $registered_req=false;
  public $email_req=false;
  public $bday_req=false;
  public $public=false;

  function Constructor($name,$prefix,$user_id) {
    $tmp=prefixValid($prefix);
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
    }
    $tmp=nameValid($name);
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
    }
    $this->name=$name;
    $this->prefix=$prefix;
    $this->user_id=$user_id;
    if(count($this->errors)==0)
      $this->status=true;
    return true;
  }

  function fillIn($id) {
    $id=mysql_real_escape_string($id);
    $query="SELECT * FROM ".STUDIES." WHERE id='".$id."'";
    $result=mysql_query($query);
    if(!$result or mysql_num_rows($result)==false) {
      $this->status=false;
      $this->errors[]=_("fillIn Datenbankfehler"). mysql_error();
      return false;
    }
    $row=mysql_fetch_array($result);
    $name=isset($row['name']) ? $row['name'] : '';
    $logo_name=isset($row['logo_name']) ? $row['logo_name'] : '';
    $prefix=isset($row['prefix']) ? $row['prefix'] : '';
    $user_id=isset($row['user_id']) ? $row['user_id'] : '';
    $public=isset($row['public']) ? $row['public'] : '';
    $reg_req=isset($row['registered_req']) ? $row['registered_req'] : '';
    $email_req=isset($row['email_req']) ? $row['email_req'] : '';
    $bday_req=isset($row['bday_req']) ? $row['bday_req'] : '';
    $this->id=$id;
    $this->user_id=$user_id;
    $this->name=$name;
    $this->logo_name=$logo_name;
    $this->prefix=$prefix;
    $this->public=$public;
    $this->registered_req=$reg_req;
    $this->email_req=$email_req;
    $this->bday_req=$bday_req;
    $this->status=true;
  }

  function Register() { 
    $name=mysql_real_escape_string($this->name);
    $prefix=mysql_real_escape_string($this->prefix);
    $user_id=mysql_real_escape_string($this->user_id);
    $id=uniqid();

    $query="INSERT INTO ".STUDIES." (id,user_id,name,prefix) VALUES ('$id','$user_id','$name','$prefix');";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]=_("Register Datenbankfehler: "). mysql_error();
      return false;
    }
    $this->id=$id;
    return true;
  }

  function CreateDB() { 
    $prefix=$this->prefix;

    define('TABLEPREFIX',$prefix."_");
	
	require_once ('../includes/settings.php');
	require_once ('../includes/functions.php');
	require_once ('../admin/includes/functions.php');

	$table_exists = false;

	// test if tables exist already
	$tables = array(ADMINTABLE,EMAILSTABLE,ITEMDISPLAYTABLE,
	ITEMSTABLE,MESSAGEQUEUE,
	RESULTSTABLE,SNRESULTSTABLE,STUDIESTABLE,SUBSTABLE,TIMESTABLE,VPNDATATABLE);

	foreach ($tables as $table) {
	    if( !table_exists($table) ) {
	      create_table($table);		
	    }
	}

    return true;
  }

  function uploadLogo() {
    if(!(isset($_FILES['logo'])) or $_FILES['logo']['error']!=0) {
      $this->status=false;
      $this->errors[]=_("Fehler beim hochladen des Logos");
      return;
    }
    if($_FILES['logo']['size']>1000000) {
      $this->status=false;
      $this->errors[]=_("Die Datei muss unter 1Mb sein");
      return;
    }
    $file_type=substr(strrchr($_FILES['logo']['name'],'.'),1);
    if($file_type!='gif' and $file_type!='jpg' and $file_type!='jpeg') {
      $this->status=false;
      $this->errors[]=_("Die Datei muss gif, jpg oder jpeg Endung habe");
      return;
    }
    $file_name=substr(md5(uniqid(rand(),true)),0,5).'.'.$file_type;
    while(file_exists("../images/".$file_name))
      $file_name=substr(md5(uniqid(rand(),true)),0,5).'.'.$file_type;
    $target="../images/".$file_name;
    if(!move_uploaded_file($_FILES['logo']['tmp_name'], $target)) {
      $this->status=false;
      $this->errors[]=_("Die Datei konnte nicht gespeichert werden");
      return;
    }
    $query="UPDATE ".STUDIES." SET logo_name = '".mysql_real_escape_string($file_name)."' WHERE id = '$this->id'";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]=_("uploadLogo Datenbankfehler"). mysql_error();
      return;
    }
    mysql_close();
    define('TABLEPREFIX',$this->prefix."_");    
    require_once('../includes/settings.php');
    
    $conn=mysql_connect($DBhost,$DBuser,$DBpass);
    if(!$conn) {
      $this->status=false;
      $this->errors[]=_("Konnte keine Verbindung zur Datenbank herstellen");
      return;
    }
    if(!mysql_select_db($DBname,$conn)) {
      $this->status=false;
      $this->errors[]=_("uploadLogo2 Datenbankfehler"). mysql_error();
      mysql_close();
      return;
    }
    $query="UPDATE ".ADMINTABLE." SET value = '".mysql_real_escape_string($file_name)."' WHERE id = 1 ";
    $result=mysql_query($query) or die(mysql_error());  
    if(!$result) {
      $this->status=false;
      $this->errors[]=_("uploadLogo3 Datenbankfehler"). mysql_error();
      mysql_close();
      return;
    }
    return true;
  }
  

  function changeName($name) {
    $tmp=nameValid($name);
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
      return;
    }
    $query="UPDATE ".STUDIES." SET name = '$name' WHERE id = '$this->id'";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]=_("changeName Datenbankfehler"). mysql_error();
      return;
    }
    $this->name=$name;
    $this->status=true;
  }

  function changePrefix($prefix) { //todo: do a change table name query for every query
    $tmp=prefixValid($prefix);
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
      return;
    }
    $query="UPDATE ".STUDIES." SET prefix = '$prefix' WHERE id = '$this->id'";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]=_("changePrefix Datenbankfehler"). mysql_error();
      return;
    }
    $this->prefix=$prefix;
    $this->status=true;
  }

  function changePublic($public) {
    $tmp=true;
    if($public!=false and $public!=true)
      $tmp=_("Falscher Wert für Variable Public");
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
      return;
    }
    $query="UPDATE ".STUDIES." SET public = '$public' WHERE id = '$this->id'";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]=_("changePublic Datenbankfehler"). mysql_error();
      return;
    }
    $this->public=$public;
    $this->status=true;
  }

  function changeRegisteredReq($registered_req) {
    $tmp=true;
    if($registered_req!=false and $registered_req!=true)
      $tmp=_("Falscher Wert für Variable reg_req");
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
      return;
    }
    $query="UPDATE ".STUDIES." SET registered_req = '$registered_req' WHERE id = '$this->id'";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]=_("changeRegisteredReq Datenbankfehler"). mysql_error();
      return;
    }
    $this->registered_req=$registered_req;
    $this->status=true;
  }

  function changeEmailReq($email_req) {
    $tmp=true;
    if($email_req!=false and $email_req!=true)
      $tmp=_("Falscher Wert für Variable email_req");
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
      return;
    }
    $query="UPDATE ".STUDIES." SET email_req = '$email_req' WHERE id = '$this->id'";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]=_("changeEmailReq Datenbankfehler"). mysql_error();
      return;
    }
    $this->email_req=$email_req;
    $this->status=true;
  }
  function changeBdayReq($bday_req) {
    $tmp=true;
    if($bday_req!=false and $bday_req!=true)
      $tmp=_("Falscher Wert für Variable bday_req");
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
      return;
    }
    $query="UPDATE ".STUDIES." SET bday_req = '$bday_req' WHERE id = '$this->id'";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]=_("changeBdayReq Datenbankfehler"). mysql_error();
      return;
    }
    $this->bday_req=$bday_req;
    $this->status=true;
  }

  function GetErrors() {
    $tmp=$this->errors;
    $this->errors=array();
    return $tmp;
  }


}

?>