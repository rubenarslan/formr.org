<?php

function nameExists($name) {
  global $dbhost,$dbname,$dbuser,$dbpass,$lang;
  $conn=mysql_connect($dbhost,$dbuser,$dbpass);
  if(!$conn)
    return $lang['CONNECT_ERROR'];
  if(!mysql_select_db($dbname,$conn)) {
    mysql_close();
    return $lang['DBSELECT_ERROR'];
  }
  $query="SELECT * FROM studies WHERE name='".mysql_real_escape_string($name)."'";
  $res=mysql_query($query);
  if($res===false)
    return $lang['QUERY_ERROR'];
  if(mysql_num_rows($res))
    return true;
  return false;
}

function nameValid($name) {
  $name=trim($name);
  if($name=="")
    return "Name darf nicht leer sein";
  if(!isInRange($name,3,20))
    return "Name muss 3-20 Zeichen lang sein";
  $tmp=nameExists($name);
  if($tmp==true)
    return "Name existiert bereits";
  return true;
}

function prefixExists($prefix) {
  global $dbhost,$dbname,$dbuser,$dbpass,$lang;
  $conn=mysql_connect($dbhost,$dbuser,$dbpass);
  if(!$conn)
    return $lang['CONNECT_ERROR'];
  if(!mysql_select_db($dbname,$conn)) {
    mysql_close();
    return $lang['DBSELECT_ERROR'];
  }
  $query="SELECT * FROM studies WHERE prefix='".mysql_real_escape_string($prefix)."'";
  $res=mysql_query($query);
  if($res===false)
    return $lang['QUERY_ERROR'];
  if(mysql_num_rows($res))
    return true;
  return false;
}

function prefixValid($prefix) {
  $prefix=trim($prefix);
  if($prefix=="")
    return "Prefix darf nicht leer sein";
  if(!isInRange($prefix,3,20))
    return "Prefix muss 3-20 Zeichen lang sein";
  $tmp=prefixExists($prefix);
  if($tmp==true)
    return "Prefix existiert bereits";
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
    global $dbhost,$dbname,$dbuser,$dbpass,$lang;
    $conn=mysql_connect($dbhost,$dbuser,$dbpass);
    if(!$conn) {
      $this->status=false;
      $this->errors[]="Could not connect do database";
      return false;
    }
    if(!mysql_select_db($dbname,$conn)) {
      $this->status=false;
      $this->errors[]="Could not connect do database";
      mysql_close();
      return false;
    }
    $id=mysql_real_escape_string($id);
    $query="SELECT * FROM studies WHERE id='".$id."'";
    $result=mysql_query($query);
    if(!$result or mysql_num_rows($result)==false) {
      $this->status=false;
      $this->errors[]="Could not execute query";
      mysql_close();
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
    global $dbhost,$dbname,$dbuser,$dbpass,$lang;
    $conn=mysql_connect($dbhost,$dbuser,$dbpass);
    if(!$conn) {
      $this->status=false;
      $this->errors[]="Could not connect do database";
      return false;
    }
    if(!mysql_select_db($dbname,$conn)) {
      $this->status=false;
      $this->errors[]="Could not connect do database";
      mysql_close();
      return false;
    }
    $name=mysql_real_escape_string($this->name);
    $prefix=mysql_real_escape_string($this->prefix);
    $user_id=mysql_real_escape_string($this->user_id);
    $id=uniqid();
    $query="INSERT INTO studies (id,user_id,name,prefix) VALUES ('$id','$user_id','$name','$prefix');";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]="Could not execute query2";
      mysql_close();
      return false;
    }
    $this->id=$id;
    return true;
  }

  function CreateDB() { 
    $prefix=$this->prefix;
    define('TABLEPREFIX',$prefix."_");    
    require ('../install.php');
    /* global $dbhost,$dbname,$dbuser,$dbpass,$lang; */
    /* $conn=mysql_connect($dbhost,$dbuser,$dbpass); */
    /* if(!$conn) { */
    /*   $this->status=false; */
    /*   $this->errors[]="Could not connect do database"; */
    /*   return false; */
    /* } */
    /* if(!mysql_select_db($dbname,$conn)) { */
    /*   $this->status=false; */
    /*   $this->errors[]="Could not connect do database"; */
    /*   mysql_close(); */
    /*   return false; */
    /* } */
    /* $prefix=mysql_real_escape_string($this->prefix); */
    /* $user_id=mysql_real_escape_string($this->user_id); */
    /* $id=uniqid(); */
    /* $query="INSERT INTO studies (id,user_id,prefix) VALUES ('$id','$user_id','$prefix');"; */
    /* $result=mysql_query($query); */
    /* if(!$result) { */
    /*   $this->status=false; */
    /*   $this->errors[]="Could not execute query2"; */
    /*   mysql_close(); */
    /*   return false; */
    /* } */
    /* $this->id=$id; */
    return true;
  }

  function uploadLogo() {
    global $dbhost,$dbname,$dbuser,$dbpass;
    if(!(isset($_FILES['logo'])) or $_FILES['logo']['error']!=0) {
      $this->status=false;
      $this->errors[]="Could not upload Logo";
      return;
    }
    if($_FILES['logo']['size']>1000000) {
      $this->status=false;
      $this->errors[]="Datei muss unter 1Mb sein";
      return;
    }
    $file_type=substr(strrchr($_FILES['logo']['name'],'.'),1);
    if($file_type!='gif' and $file_type!='jpg' and $file_type!='jpeg') {
      $this->status=false;
      $this->errors[]="Datei muss gif, jpg oder jpeg Endung habe";
      return;
    }
    $file_name=substr(md5(uniqid(rand(),true)),0,5).'.'.$file_type;
    while(file_exists("../images/".$file_name))
      $file_name=substr(md5(uniqid(rand(),true)),0,5).'.'.$file_type;
    $target="../images/".$file_name;
    if(!move_uploaded_file($_FILES['logo']['tmp_name'], $target)) {
      $this->status=false;
      $this->errors[]="Datei konnte nicht gespeichert werden";
      return;
    }
    $conn=mysql_connect($dbhost,$dbuser,$dbpass);
    if(!$conn) {
      $this->status=false;
      $this->errors[]="Could not connect do database";
      return;
    }
    if(!mysql_select_db($dbname,$conn)) {
      $this->status=false;
      $this->errors[]="Could not connect do database";
      mysql_close();
      return;
    }
    $query="UPDATE studies SET logo_name = '".mysql_real_escape_string($file_name)."' WHERE id = '$this->id'";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]="Could not execute query";
      mysql_close();
      return;
    }
    mysql_close();
    define('TABLEPREFIX',$this->prefix."_");    
    require_once('../includes/settings.php');
    
    $conn=mysql_connect($DBhost,$DBuser,$DBpass);
    if(!$conn) {
      $this->status=false;
      $this->errors[]="Could not connect do database";
      return;
    }
    if(!mysql_select_db($DBName,$conn)) {
      $this->status=false;
      $this->errors[]="Could not connect do database";
      mysql_close();
      return;
    }
    $query="UPDATE ".ADMINTABLE." SET value = '".mysql_real_escape_string($file_name)."' WHERE id = 1 ";
    $result=mysql_query($query) or die(mysql_error());  
    if(!$result) {
      $this->status=false;
      $this->errors[]="Could not execute query2";
      mysql_close();
      return;
    }
    return true;
  }
  

  function changeName($name) {
    global $dbhost,$dbname,$dbuser,$dbpass;
    $tmp=nameValid($name);
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
      return;
    }
    $conn=mysql_connect($dbhost,$dbuser,$dbpass);
    if(!$conn) {
      $this->status=false;
      $this->errors[]="Could not connect do database";
      return;
    }
    if(!mysql_select_db($dbname,$conn)) {
      $this->status=false;
      $this->errors[]="Could not connect do database";
      mysql_close();
      return;
    }
    $query="UPDATE studies SET name = '$name' WHERE id = '$this->id'";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]="Could not execute query";
      mysql_close();
      return;
    }
    $this->name=$name;
    $this->status=true;
  }

  function changePrefix($prefix) {
    global $dbhost,$dbname,$dbuser,$dbpass;
    $tmp=prefixValid($prefix);
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
      return;
    }
    $conn=mysql_connect($dbhost,$dbuser,$dbpass);
    if(!$conn) {
      $this->status=false;
      $this->errors[]="Could not connect do database";
      return;
    }
    if(!mysql_select_db($dbname,$conn)) {
      $this->status=false;
      $this->errors[]="Could not connect do database";
      mysql_close();
      return;
    }
    $query="UPDATE studies SET prefix = '$prefix' WHERE id = '$this->id'";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]="Could not execute query";
      mysql_close();
      return;
    }
    $this->prefix=$prefix;
    $this->status=true;
  }

  function changePublic($public) {
    global $dbhost,$dbname,$dbuser,$dbpass;
    $tmp=true;
    if($public!=false and $public!=true)
      $tmp="falscher wert fuer public";
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
      return;
    }
    $conn=mysql_connect($dbhost,$dbuser,$dbpass);
    if(!$conn) {
      $this->status=false;
      $this->errors[]="Could not connect do database";
      return;
    }
    if(!mysql_select_db($dbname,$conn)) {
      $this->status=false;
      $this->errors[]="Could not connect do database";
      mysql_close();
      return;
    }
    $query="UPDATE studies SET public = '$public' WHERE id = '$this->id'";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]="Could not execute query";
      mysql_close();
      return;
    }
    $this->public=$public;
    $this->status=true;
  }

  function changeRegisteredReq($registered_req) {
    global $dbhost,$dbname,$dbuser,$dbpass;
    $tmp=true;
    if($registered_req!=false and $registered_req!=true)
      $tmp="falscher wert fuer reg_req";
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
      return;
    }
    $conn=mysql_connect($dbhost,$dbuser,$dbpass);
    if(!$conn) {
      $this->status=false;
      $this->errors[]="Could not connect do database";
      return;
    }
    if(!mysql_select_db($dbname,$conn)) {
      $this->status=false;
      $this->errors[]="Could not connect do database";
      mysql_close();
      return;
    }
    $query="UPDATE studies SET registered_req = '$registered_req' WHERE id = '$this->id'";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]="Could not execute query";
      mysql_close();
      return;
    }
    $this->registered_req=$registered_req;
    $this->status=true;
  }

  function changeEmailReq($email_req) {
    global $dbhost,$dbname,$dbuser,$dbpass;
    $tmp=true;
    if($email_req!=false and $email_req!=true)
      $tmp="falscher wert fuer reg_req";
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
      return;
    }
    $conn=mysql_connect($dbhost,$dbuser,$dbpass);
    if(!$conn) {
      $this->status=false;
      $this->errors[]="Could not connect do database";
      return;
    }
    if(!mysql_select_db($dbname,$conn)) {
      $this->status=false;
      $this->errors[]="Could not connect do database";
      mysql_close();
      return;
    }
    $query="UPDATE studies SET email_req = '$email_req' WHERE id = '$this->id'";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]="Could not execute query";
      mysql_close();
      return;
    }
    $this->email_req=$email_req;
    $this->status=true;
  }
  function changeBdayReq($bday_req) {
    global $dbhost,$dbname,$dbuser,$dbpass;
    $tmp=true;
    if($bday_req!=false and $bday_req!=true)
      $tmp="falscher wert fuer reg_req";
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
      return;
    }
    $conn=mysql_connect($dbhost,$dbuser,$dbpass);
    if(!$conn) {
      $this->status=false;
      $this->errors[]="Could not connect do database";
      return;
    }
    if(!mysql_select_db($dbname,$conn)) {
      $this->status=false;
      $this->errors[]="Could not connect do database";
      mysql_close();
      return;
    }
    $query="UPDATE studies SET bday_req = '$bday_req' WHERE id = '$this->id'";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]="Could not execute query";
      mysql_close();
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