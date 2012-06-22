<?php
/* require_once $_SERVER['DOCUMENT_ROOT']."/tmp/config/config.php"; */
require_once "../config/config.php";
global $currentUser;
if(!userIsAdmin()) {
  header("Location: ../index.php");
  die();
}
?>
<?php
global $language,$available_languages,$lang;
if(!empty($_POST)) {
  $errors=array();
  
  $run=new Run;
  $run->Constructor($_POST['name'],$currentUser->id);
  if(!$run->status) {
    $errors=$run->GetErrors();
  } else {
    if(!$run->Register())
      $errors=$run->GetErrors();
    else
      header("Location: view_run.php?id=".$run->id."");
  }

}
?>
<?php
include("pre_content.php");
?>	
<?php
if(!empty($_POST) and count($errors)>0) {
?>
<div id="errors">
<?php errorOutput($errors); ?>
</div>
<?php
    }
?>
<form id="add_run" name="add_run" method="post" action="add_run.php">
  <p>
  <p>
  <label>Run Name
  </label>
  <input type="text" name="name" id="name"  value="<?php if(isset($_POST['name'])) echo $_POST['name']; ?>"/>
  </p>
  <button type="submit">Run erstellen</button>
  </form>

<?php
include("post_content.php");
?>	