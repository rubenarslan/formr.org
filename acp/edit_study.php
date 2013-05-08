<?php
require_once '../define_root.php';
require_once INCLUDE_ROOT . "config/config.php";

global $currentUser;
if(!userIsAdmin() or !isset($_GET['id'])) {
  header("Location: index.php");
  die();
}
$study=new Study;
$study->fillIn($_GET['id']);
if(!$study->status)
  header("Location: ../index.php");
if(!$currentUser->ownsStudy($_GET['id']))
  header("Location: ../index.php");

if(!empty($_POST)) {
  $errors=array();
  if(isset($_POST['name']) and $_POST['name']!==$study->name)
    $study->changeName($_POST['name']);
  if(!$study->status)
    $errors=array_merge($errors,$study->GetErrors());
  if(isset($_POST['prefix']) and $_POST['prefix']!==$study->prefix)
    $study->changePrefix($_POST['prefix']);
  if(!$study->status)
    $errors=array_merge($errors,$study->GetErrors());
  if($study->public==true and !isset($_POST['public']))
    $study->changePublic(false);
  elseif($study->public==false and isset($_POST['public']))
    $study->changePublic(true);
  if(!$study->status)
    $errors=array_merge($errors,$study->GetErrors());
  if($study->registered_req==true and !isset($_POST['registered']))
    $study->changeRegisteredReq(false);
  elseif($study->registered_req==false and isset($_POST['registered']))
    $study->changeRegisteredReq(true);
  if(!$study->status)
    $errors=array_merge($errors,$study->GetErrors());
  if(isset($_FILES['logo']) and $_FILES['logo']['error']==0)
    $study->uploadLogo();
  if(!$study->status)
    $errors=array_merge($errors,$study->GetErrors());
}

require_once INCLUDE_ROOT . "view_header.php";
?>

<p><strong><?php echo _("Editiere Studie: "); ?></strong> <?php echo $study->name; ?> <br /> 
<?php
if(!empty($_POST) and count($errors)>0) {
?>
<div id="errors">
<?php errorOutput($errors); ?>
</div>
<?php
    }
?>


<br>
  <p><a href="<?=WEBROOT?>admin<?=$study->name?>/index"><?php echo _("Zurück zur Studie"); ?></a></p>

<?php
require_once INCLUDE_ROOT . "view_footer.php";