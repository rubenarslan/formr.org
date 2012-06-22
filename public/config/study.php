<?php


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
  public $prefix;

  function Constructor($prefix,$user_id) {
    $prefix=trim($prefix);
    $tmp=prefixValid($prefix);
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
    }
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
    $prefix=isset($row['prefix']) ? $row['prefix'] : '';
    $user_id=isset($row['user_id']) ? $row['user_id'] : '';
    $this->id=$id;
    $this->user_id=$user_id;
    $this->prefix=$prefix;
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
    $prefix=mysql_real_escape_string($this->prefix);
    $user_id=mysql_real_escape_string($this->user_id);
    $id=uniqid();
    $query="INSERT INTO studies (id,user_id,prefix) VALUES ('$id','$user_id','$prefix');";
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
    require ('../../install.php');
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


  /* function changeCss($css) { */
  /*   global $dbhost,$dbname,$dbuser,$dbpass; */
  /*   $css=trim($css); */
  /*   $tmp=cssValid($css); */
  /*   if($tmp!==true) { */
  /*     $this->status=false; */
  /*     $this->errors[]=$tmp; */
  /*     return; */
  /*   } */
  /*   $conn=mysql_connect($dbhost,$dbuser,$dbpass); */
  /*   if(!$conn) { */
  /*     $this->status=false; */
  /*     $this->errors[]="Could not connect do database"; */
  /*     return; */
  /*   } */
  /*   if(!mysql_select_db($dbname,$conn)) { */
  /*     $this->status=false; */
  /*     $this->errors[]="Could not connect do database"; */
  /*     mysql_close(); */
  /*     return; */
  /*   } */
  /*   $query="UPDATE default_ads SET css = '$css' WHERE id = '$this->id'"; */
  /*   $result=mysql_query($query); */
  /*   if(!$result) { */
  /*     $this->status=false; */
  /*     $this->errors[]="Could not execute query"; */
  /*     mysql_close(); */
  /*     return; */
  /*   } */
  /*   $this->css=$css; */
  /*   $this->status=true; */
  /* } */

  function GetErrors() {
    $tmp=$this->errors;
    $this->errors=array();
    return $tmp;
  }


}

?>