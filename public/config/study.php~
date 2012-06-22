<?php


function defaultAdNameExists($name) {
  global $dbhost,$dbname,$dbuser,$dbpass,$lang;
  $conn=mysql_connect($dbhost,$dbuser,$dbpass);
  if(!$conn)
    return $lang['CONNECT_ERROR'];
  if(!mysql_select_db($dbname,$conn)) {
    mysql_close();
    return $lang['DBSELECT_ERROR'];
  }
  $query="SELECT * FROM default_ads WHERE name='".mysql_real_escape_string($name)."'";
  $res=mysql_query($query);
  if($res===false)
    return $lang['QUERY_ERROR'];
  if(mysql_num_rows($res))
    return true;
  return false;
}

function cssValid($css) {
  if($css=='')
    return "CSS may not be empty";
  return true;
}

function templateValid($template) {
  if($template=='')
    return "Templtea may not be empty";
  return true;
}

function defaultAdNameValid($name) {
  global $lang;
  if($name=="")
    return "Name may not be empty";
  if(!isInRange($name,2,30))
    return "Name length error";
  $tmp=defaultAdNameExists($name);
  if($tmp===true)
    return "Name exists";
  return true;  
}

class Default_Ad {
  public $status=false;
  private $errors=array();
  public $id;
  public $name;
  public $css;
  public $template;


  function Constructor($name,$css,$template) {
    $name=trim($name);
    $tmp=defaultAdNameValid($name);
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
    }
    $tmp=cssValid($css);
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
    }
    $tmp=templateValid($template);
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
    }
    $this->name=$name;
    $this->css=$css;
    $this->template=$template;
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
    $query="SELECT * FROM default_ads WHERE id='".$id."'";
    $result=mysql_query($query);
    if(!$result or mysql_num_rows($result)==false) {
      $this->status=false;
      $this->errors[]="Could not execute query";
      mysql_close();
      return false;
    }
    $row=mysql_fetch_array($result);
    $name=isset($row['name']) ? $row['name'] : '';
    $css=isset($row['css']) ? $row['css'] : '';
    $template=isset($row['template']) ? $row['template'] : '';
    $this->id=$id;
    $this->name=$name;
    $this->css=$css;
    $this->template=$template;
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
    $css=mysql_real_escape_string($this->css);
    $template=mysql_real_escape_string($this->template);
    $id=uniqid();
    $query="INSERT INTO default_ads (id,name,css,template) VALUES ('$id','$name','$css','$template');";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]="Could not execute query2";
      mysql_close();
      return false;
    }
    /* $query="SELECT id FROM default_ads WHERE name='".$name."'"; */
    /* $result=mysql_query($query); */
    /* if(!$result or mysql_num_rows($result)==false) { */
    /*   $this->status=false; */
    /*   $this->errors[]="Could not execute query3"; */
    /*   mysql_close(); */
    /*   return false; */
    /* } */
    /* $row=mysql_fetch_array($result); */
    /* $id=isset($row['id']) ? $row['id'] : ' '; */
    $this->id=$id;
    return true;
  }

  function changeName($name) {
    global $dbhost,$dbname,$dbuser,$dbpass;
    $name=trim($name);
    $tmp=defaultAdNameValid($name);
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
    $query="UPDATE default_ads SET name = '$name' WHERE id = '$this->id'";
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

  function changeTemplate($template) {
    global $dbhost,$dbname,$dbuser,$dbpass;
    $template=trim($template);
    $tmp=templateValid($template);
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
    $query="UPDATE default_ads SET template = '$template' WHERE id = '$this->id'";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]="Could not execute query";
      mysql_close();
      return;
    }
    $this->template=$template;
    $this->status=true;
  }

  function changeCss($css) {
    global $dbhost,$dbname,$dbuser,$dbpass;
    $css=trim($css);
    $tmp=cssValid($css);
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
    $query="UPDATE default_ads SET css = '$css' WHERE id = '$this->id'";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]="Could not execute query";
      mysql_close();
      return;
    }
    $this->css=$css;
    $this->status=true;
  }

  function GetErrors() {
    $tmp=$this->errors;
    $this->errors=array();
    return $tmp;
  }


}

?>