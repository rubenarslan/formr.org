<?php

class Run {
  public $status=false;
  private $errors=array();
  public $id;
  public $user_id;
  public $name;
  public $registered_req=false;
  public $public=false;

  function isEmpty() {
    $query="SELECT * FROM run_data WHERE run_id='".mysql_real_escape_string($this->id)."'";
    $res=mysql_query($query);
    if($res===false)
      return _("Datenbankfehler");
    if(mysql_num_rows($res))
      return false;
    return true;
  }

/* todo/bug: the same study may appear in a single run multiple times. 
they can only be differentiated by position. 
See: getNextStudiesById($sid,$pos) for one way to solve it, may not work for this function though.
also: add study position to the $study[] array
*/
  function getPrevStudies($study) { 
    $studies=array();
    if(!isset($study))
      return NULL;
    $query="SELECT * FROM run_data WHERE run_id='".mysql_real_escape_string($this->id)."'"."AND study_id='".$study->id."'";
    $res=mysql_query($query);
    if(!$res or mysql_num_rows($res)==false) {
      $this->status=false;
      $this->errors[]=_("Datenbankfehler");
      return NULL;
    }
    $row=mysql_fetch_array($res);    
    $study_pos=isset($row['position']) ? $row['position'] : '-1';
    $query="SELECT * FROM run_data WHERE run_id='".mysql_real_escape_string($this->id)."' AND position < $study_pos";
    $res=mysql_query($query);
    if(!$res or mysql_num_rows($res)==false) {
      $this->status=false;
      $this->errors[]=_("Datenbankfehler");
      return NULL;
    }
    if(!mysql_num_rows($res))
      return NULL;
    while($row=mysql_fetch_array($res)) {
      $s_id=$row['study_id'];
      $st=new Study();
      $st->FillIn($s_id);
      if(!$st->status)
        return NULL;
      $studies[]=$st;
    }
    return $studies;
  }

  function getNextStudiesByIdAsRunData($sid,$pos) {
    $studies=array();
    $query="SELECT * FROM run_data WHERE run_id='".mysql_real_escape_string($this->id)."' AND position > $pos";
    $res=mysql_query($query);
    if(!$res or mysql_num_rows($res)==false) {
      $this->status=false;
      $this->errors[]=_("Datenbankfehler");
      return NULL;
    }
    if(!mysql_num_rows($res))
      return NULL;
    while($row=mysql_fetch_array($res)) {
      $id=$row['id'];
      $pos=$row['position'];
      $studies[]=array($id,$pos);
    }
    return $studies;
  }

  function getFirstStudyId() {
    $query="SELECT * FROM run_data WHERE run_id='".mysql_real_escape_string($this->id)."' AND position = 0";
    $res=mysql_query($query);
    if(!$res or mysql_num_rows($res)==false) {
      $this->status=false;
      $this->errors[]=_("Datenbankfehler");
      return -1;
    }
    $row=mysql_fetch_array($res);    
    $id=isset($row['study_id']) ? $row['study_id'] : '-1';
    $this->status=true;
    return $id;
  }

  function getNextStudy($study) {
    if(!isset($study) or !is_object($study))
      return NULL;
    $run_data=$this->GetRunData();
    $found_study=0;
    foreach($run_data as $rd) {
      if($found_study) {
        $next_study=new Study;
        $next_study->FillIn($rd[0]);
        if(!$next_study->status)
          return NULL;
        return $next_study;
      }
      if($study->id==$rd[0])
        $found_study=1;
    }
    return NULL;
  }

  function isOptional($study) {
    if(!isset($study) or !is_object($study))
      return false;
    $run_data=$this->GetRunData();
    foreach($run_data as $rd) {
      if($study->id==$rd[0]) {
        return $rd[3];
      }
    }
    return false;
  }

  function getPosition() {
    if($this->isEmpty())
      return 0;
    $query="SELECT * FROM run_data WHERE run_id='".mysql_real_escape_string($this->id)."'";
    $res=mysql_query($query);
    if(!$res)
      return -1;
    $row=mysql_fetch_array($res);
    $pos=0;
    while($row) {
      $position=isset($row['position']) ? $row['position'] : '-1';
      if($position>$pos)
        $pos=$position;
      $row=mysql_fetch_array($res);
    }
    $pos=$pos+1;
    return $pos;
  }

  function addStudy($study,$optional=false) {
    if(!isset($study) or !is_object($study)) {
      $this->status=false;
      $this->errors[]=_("Interner Fehler");
      return;
    }
    $id=uniqid();
    $run_id=mysql_real_escape_string($this->id);
    $study_id=mysql_real_escape_string($study->id);
    $position=$this->getPosition();
    $optional=mysql_real_escape_string($optional);
    $query="INSERT INTO run_data (id,run_id,study_id,position,optional) VALUES ('$id','$run_id','$study_id','$position','$optional');";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]=_("Datenbankfehler");
      return;
    }
    return true;
  }

  function nameExists($name) {
    $query="SELECT * FROM runs WHERE name='".mysql_real_escape_string($name)."'";
    $res=mysql_query($query);
    if($res===false)
      return _("Datenbankfehler");
    if(mysql_num_rows($res))
      return true;
    $query="SELECT * FROM studies WHERE name='".mysql_real_escape_string($name)."'";
    $res=mysql_query($query);
    if($res===false)
      return _("Datenbankfehler");
    if(mysql_num_rows($res))
      return true;
    return false;
  }

  function nameValid($name) {
    $name=trim($name);
    if($name=="")
      return _("Keine Runname angegeben");
    if(!isInRange($name,3,20))
      return _("Runname muss zwischen 3 und 20 Zeichen lang sein");
    $tmp=nameExists($name);
    if($tmp==true)
      return _("Ein Run mit diesem Namen existiert bereits");
    return true;
  }


  function changeName($name) {
    $tmp=nameValid($name);
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
      return;
    }
    $query="UPDATE runs SET name = '$name' WHERE id = '$this->id'";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]=_("Datenbankfehler");
      return;
    }
    $this->name=$name;
    $this->status=true;
  }

  function changePublic($public) {
    $tmp=true;
    if($public!=false and $public!=true)
      $tmp=_("Falscher Wert f&uuml;r Variable public");
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
      return;
    }
    $query="UPDATE runs SET public = '$public' WHERE id = '$this->id'";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]=_("Datenbankfehler");
      return;
    }
    $this->public=$public;
    $this->status=true;
  }

  function removeStudy($sid,$pos) {
    $query="DELETE FROM run_data WHERE run_id = '$this->id' AND study_id = '$sid' AND position='$pos'";
    $result=mysql_query($query);
    if($result!=true)
      return false;
    $studies=$this->getNextStudiesByIdAsRunData($sid,$pos);
    foreach($studies as $study) {
      $this->changeRunDataPosition($study[0],$study[1]-1);
    }
    return true;
  }

  function changeRunDataPosition($id,$new_pos) {
    $query="UPDATE run_data SET position = '$new_pos' WHERE id = '$id'";
    $result=mysql_query($query);
    return $result;
  }

  function changeOptional($op,$sid,$pos) {
    if($op!=1 and $op!=0)
      return false;
    if(!is_numeric($pos))
      return false;
    $query="UPDATE run_data SET optional = '$op' WHERE run_id = '$this->id' AND study_id = '$sid' AND position='$pos'";
    $result=mysql_query($query);
    return $result;
  }
  function changeRegisteredReq($registered_req) {
    $tmp=true;
    if($registered_req!=false and $registered_req!=true)
      $tmp=_("Falscher Wert f&uuml;r Variable reg_req");
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
      return;
    }
    $query="UPDATE runs SET registered_req = '$registered_req' WHERE id = '$this->id'";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]=_("Datenbankfehler");
      return;
    }
    $this->registered_req=$registered_req;
    $this->status=true;
  }

  function GetRunData() {
    $run_data=array();
    $id=(isset($this->id))?mysql_real_escape_string($this->id):'';
    $query="SELECT * FROM run_data WHERE run_id='".$id."' ORDER BY position";
    $result=mysql_query($query);
    if(mysql_num_rows($result)==false) {
      $this->status=false;
      return false;
    }
    if(!$result) {
      $this->status=false;
      $this->errors[]=_("Datenbankfehler");
      return false;
    }
    if(mysql_num_rows($result)!=false) {
      while($row=mysql_fetch_array($result)) {
        $rd=array();
        $s_id=$row['study_id'];
        $study=new Study();
        $study->FillIn($s_id);
        if(!$study->status)
          break;
        $rd[]=$s_id;
        $rd[]=$study->name;
        $rd[]=$row['position'];
        $rd[]=$row['optional'];
        $run_data[]=$rd;
      }
    }
    return $run_data;
  }

  function Constructor($name,$user_id) {
    $tmp=nameValid($name);
    if($tmp!==true) {
      $this->status=false;
      $this->errors[]=$tmp;
    }
    $this->name=$name;
    $this->user_id=$user_id;
    if(count($this->errors)==0)
      $this->status=true;
    return true;
  }

  function Register() { 
    $name=mysql_real_escape_string($this->name);
    $user_id=mysql_real_escape_string($this->user_id);
    $id=uniqid();
    $query="INSERT INTO runs (id,user_id,name) VALUES ('$id','$user_id','$name');";
    $result=mysql_query($query);
    if(!$result) {
      $this->status=false;
      $this->errors[]=_("Datenbankfehler");
      return false;
    }
    $this->id=$id;
    return true;
  }

  function fillIn($id) {
    $id=mysql_real_escape_string($id);
    $query="SELECT * FROM runs WHERE id='".$id."'";
    $result=mysql_query($query);
    if(!$result or mysql_num_rows($result)==false) {
      $this->status=false;
      $this->errors[]=_("Datenbankfehler");
      return false;
    }
    $row=mysql_fetch_array($result);
    $name=isset($row['name']) ? $row['name'] : '';
    $user_id=isset($row['user_id']) ? $row['user_id'] : '';
    $public=isset($row['public']) ? $row['public'] : '';
    $reg_req=isset($row['registered_req']) ? $row['registered_req'] : '';
    $this->id=$id;
    $this->user_id=$user_id;
    $this->name=$name;
    $this->public=$public;
    $this->registered_req=$reg_req;
    $this->status=true;
  }

  function GetErrors() {
    $tmp=$this->errors;
    $this->errors=array();
    return $tmp;
  }



}

?>