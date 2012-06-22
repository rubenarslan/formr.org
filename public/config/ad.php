<?php

function specialKeywordValid($keyword,$d) {
  if($d=="keyword") {
    if(!isset($keyword) or $keyword=='')
      return "Special Keyword darf nicht leer sein";    
  }
  return true;
}

function resolveValid($resolve,$d) {
  if($d=="keyword") {
    return true;
  }
  return true;
}

function searchIndexValid($search_index,$d) {
  if($d=="keyword") {
    if(!isset($search_index))
      return "Search Index nicht gesetzt";
    //kategorie check
    return true;
  }
  return true;
}

function resultCountValid($result_count,$d) {
  if($d=="keyword") {
    if(!isset($result_count))
      return "Result Count nicht gesetzt";
    if($result_count<1 or $result_count>10)
      return "Anzahl der Ergebnise muss zwischen 1 und 10 liegen";
  }
  return true;
}

function prefixValid($prefix) {
  return true;
}

function postfixValid($postfix) {
  return true;
}



function dataOptionValid($data_option) {
  if($data_option!='keyword' and $data_option!='asin' and $data_option!='asinlist')
    return "Data Option invalid";
  return true;
}

class Ad {
  public $status=false;
  private $errors=array();
  public $id;
  public $website_id;
  public $user_id;
  public $name;
  public $data_option;
  public $css;
  public $template;
  public $associate_tag;
  public $access_key;
  public $private_key;
  public $prefix;
  public $postfix;
  public $special_keyword;
  public $resolve;
  public $search_index;
  public $result_count;

  /* function __construct($name,$website_id) { */
  /*   if($name!=NULL or $website_id!=NULL) { */
  /*     $name=trim($name); */
  /*     $tmp=adNameValid($name,$website_id); */
  /*     if($tmp!==true) { */
  /*       $this->status=false; */
  /*       $this->errors[]=$tmp; */
  /*     } */
  /*     $this->name=$name; */
  /*     $this->website_id=$website_id; */
  /*     if(count($this->errors)==0) */
  /*       $this->status=true; */
  /*   } */
  /* } */

  function Constructor($name,$data_option,$keyword,$resolve,$search_index,$result_count,$prefix,$postfix,$css,$template,$website_id,$user_id) {
    $name=trim($name);
    $tmp=adNameValid($name,$website_id);
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
      return false;
    }
    $tmp=dataOptionValid($data_option);
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
      return false;
    }
    $tmp=specialKeywordValid($keyword,$data_option);
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
      return false;
    }
    $tmp=resolveValid($resolve,$data_option);
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
      return false;
    }
    $tmp=searchIndexValid($search_index,$data_option);
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
      return false;
    }
    $tmp=resultCountValid($result_count,$data_option);
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
      return false;
    }
    $tmp=prefixValid($prefix);
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
      return false;
    }
    $tmp=postfixValid($posfix);
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
      return false;
    }
    $tmp=cssValid($css);
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
      return false;
    }
    $tmp=templateValid($template);
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
      return false;
    }
    $this->name=$name;
    $this->data_option=$data_option;
    $this->css=$css;
    $this->template=$template;
    $this->website_id=$website_id;
    $this->user_id=$user_id;
    if($data_option='keyword') {
      $this->special_keyword=$keyword;
      if($resolve==true)
        $this->resolve=true;
      else
        $this->resolve=false;
      $this->search_index=$search_index;
      $this->result_count=$result_count;
    }
    $this->prefix=$prefix;
    $this->postfix=$postfix;    
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
    $query="SELECT * FROM ads WHERE id='".$id."'";
    $result=mysql_query($query);
    if(!$result or mysql_num_rows($result)==false) {
      $this->status=false;
      $this->errors[]="Could not execute query";
      mysql_close();
      return false;
    }
    $row=mysql_fetch_array($result);
    $user_id=isset($row['user_id']) ? $row['user_id'] : '';
    $website_id=isset($row['website_id']) ? $row['website_id'] : '';
    $name=isset($row['name']) ? $row['name'] : '';
    $data_option=isset($row['data_option']) ? $row['data_option'] : '';
    $css=isset($row['css']) ? $row['css'] : '';
    $template=isset($row['template']) ? $row['template'] : '';
    $prefix=isset($row['prefix']) ? $row['prefix'] : '';
    $postfix=isset($row['postfix']) ? $row['postfix'] : '';
    $special_keyword=isset($row['special_keyword']) ? $row['special_keyword'] : '';
    $resolve=isset($row['resolve']) ? $row['resolve'] : '';
    $search_index=isset($row['search_index']) ? $row['search_index'] : '';
    $result_count=isset($row['result_count']) ? $row['result_count'] : '';
    $associate_tag=isset($row['associate_tag']) ? $row['associate_tag'] : '0';
    $access_key=isset($row['access_key']) ? $row['access_key'] : '0';
    $private_key=isset($row['private_key']) ? $row['private_key'] : '0';
    $this->id=$id;
    $this->user_id=$user_id;
    $this->website_id=$website_id;
    $this->name=$name;
    $this->data_option=$data_option;
    $this->css=$css;
    $this->template=$template;
    $this->prefix=$prefix;
    $this->postfix=$postfix;
    $this->special_keyword=$special_keyword;
    $this->resolve=$resolve;
    $this->search_index=$search_index;
    $this->result_count=$result_count;
    $this->associate_tag=$associate_tag;
    $this->access_key=$access_key;
    $this->private_key=$private_key;
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
    $data_option=mysql_real_escape_string($this->data_option);
    $css=mysql_real_escape_string($this->css);
    $template=mysql_real_escape_string($this->template);
    $website_id=mysql_real_escape_string($this->website_id);
    $user_id=mysql_real_escape_string($this->user_id);
    $prefix=mysql_real_escape_string($this->prefix);
    $postfix=mysql_real_escape_string($this->postfix);
    $special_keyword=mysql_real_escape_string($this->special_keyword);
    $resolve=mysql_real_escape_string($this->resolve);
    $search_index=mysql_real_escape_string($this->search_index);
    $result_count=mysql_real_escape_string($this->result_count);
    $query="SELECT associate_tag, access_key, private_key FROM websites WHERE id='".$website_id."'";
    $result=mysql_query($query);
    if(!$result or mysql_num_rows($result)==false) {
      $this->status=false;
      $this->errors[]="Could not execute query1";
      mysql_close();
      return false;
    }
    $row=mysql_fetch_array($result);
    $associate_tag=isset($row['associate_tag']) ? $row['associate_tag'] : '0';
    $access_key=isset($row['access_key']) ? $row['access_key'] : '0';
    $private_key=isset($row['private_key']) ? $row['private_key'] : '0';
    $id=uniqid();
    $query="INSERT INTO ads (id,user_id,website_id,name,data_option,css,template,prefix,postfix,special_keyword,resolve,search_index,result_count,associate_tag,access_key,private_key) VALUES ('$id','$user_id','$website_id','$name','$data_option','$css','$template','$prefix','$postfix','$special_keyword','$resolve','$search_index','$result_count','$associate_tag','$access_key','$private_key');";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]="Could not execute query2";
      mysql_close();
      return false;
    }
    /* $query="SELECT id FROM ads WHERE website_id='".$website_id."' AND name='".$name."'"; */
    /* $result=mysql_query($query); */
    /* if(!$result or mysql_num_rows($result)==false) { */
    /*   $this->status=false; */
    /*   $this->errors[]="Could not execute query3"; */
    /*   mysql_close(); */
    /*   return false; */
    /* } */
    /* $row=mysql_fetch_array($result); */
    /* $id=isset($row['id']) ? $row['id'] : ' '; */
    $this->associate_tag=$associate_tag;
    $this->access_key=$access_key;
    $this->private_key=$private_key;
    $this->id=$id;
    return true;
  }
  

  function changeName($name) {
    global $dbhost,$dbname,$dbuser,$dbpass;
    $name=trim($name);
    $tmp=adNameValid($name,$this->id);
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
    $query="UPDATE ads SET name = '$name' WHERE id = '$this->id'";
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


  function changeDataOption($do) {
    global $dbhost,$dbname,$dbuser,$dbpass;
    $tmp=dataOptionValid($do);
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
    $query="UPDATE ads SET data_option = '$do' WHERE id = '$this->id'";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]="Could not execute query";
      mysql_close();
      return;
    }
    $this->data_option=$do;
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
    $query="UPDATE ads SET css = '$css' WHERE id = '$this->id'";
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
    $query="UPDATE ads SET template = '$template' WHERE id = '$this->id'";
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


  function changeSpecialKeyword($special_keyword) {
    global $dbhost,$dbname,$dbuser,$dbpass;
    $tmp=specialKeywordValid($special_keyword,'keyword');
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
    $query="UPDATE ads SET special_keyword = '$special_keyword' WHERE id = '$this->id'";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]="Could not execute query";
      mysql_close();
      return;
    }
    $this->special_keyword=$special_keyword;
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
    $query="UPDATE ads SET prefix = '$prefix' WHERE id = '$this->id'";
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

  function changePostfix($postfix) {
    global $dbhost,$dbname,$dbuser,$dbpass;
    $tmp=postfixValid($postfix);
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
    $query="UPDATE ads SET postfix = '$postfix' WHERE id = '$this->id'";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]="Could not execute query";
      mysql_close();
      return;
    }
    $this->postfix=$postfix;
    $this->status=true;
  }

  function changeResolve($resolve) {
    global $dbhost,$dbname,$dbuser,$dbpass;
    $re='';
    if(isset($resolve))
      $re=$resolve;
    $tmp=resolveValid($re,"keyword");
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
    $t=false;
    if($re==true)
      $t=true;
    $query="UPDATE ads SET resolve = '$t' WHERE id = '$this->id'";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]="Could not execute query";
      mysql_close();
      return;
    }
    $this->resolve=$resolve;
    $this->status=true;
  }

  function changeResultCount($result_count) {
    global $dbhost,$dbname,$dbuser,$dbpass;
    $tmp=resultCountValid($result_count,"keyword");
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
    $query="UPDATE ads SET result_count = '$result_count' WHERE id = '$this->id'";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]="Could not execute query";
      mysql_close();
      return;
    }
    $this->result_count=$result_count;
    $this->status=true;
  }

  function changeSearchIndex($search_index) {
    global $dbhost,$dbname,$dbuser,$dbpass;
    $tmp=searchIndexValid($search_index,"keyword");
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
    $query="UPDATE ads SET search_index = '$search_index' WHERE id = '$this->id'";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]="Could not execute query";
      mysql_close();
      return;
    }
    $this->search_index=$search_index;
    $this->status=true;
  }

  function changeAssociateTag($associate_tag) {
    global $dbhost,$dbname,$dbuser,$dbpass;
    $associate_tag=trim($associate_tag);
    $tmp=associateTagValid($associate_tag);
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
    $query="UPDATE ads SET associate_tag = '$associate_tag' WHERE id = '$this->id'";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]="Could not execute query";
      mysql_close();
      return;
    }
    $this->associate_tag=$associate_tag;
    $this->status=true;
  }

  function changeAccessKey($access_key) {
    global $dbhost,$dbname,$dbuser,$dbpass;
    $access_key=trim($access_key);
    $tmp=accessKeyValid($access_key);
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
    $query="UPDATE ads SET access_key = '$access_key' WHERE id = '$this->id'";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]="Could not execute query";
      mysql_close();
      return;
    }
    $this->access_key=$access_key;
    $this->status=true;
  }

  function changePrivateKey($private_key) {
    global $dbhost,$dbname,$dbuser,$dbpass;
    $private_key=trim($private_key);
    $tmp=privateKeyValid($private_key);
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
    $query="UPDATE ads SET private_key = '$private_key' WHERE id = '$this->id'";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]="Could not execute query";
      mysql_close();
      return;
    }
    $this->private_key=$private_key;
    $this->status=true;
  }


  function GetErrors() {
    $tmp=$this->errors;
    $this->errors=array();
    return $tmp;
  }

}

?>