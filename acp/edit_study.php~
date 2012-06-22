<?php
/* require_once $_SERVER['DOCUMENT_ROOT']."/tmp/config/config.php"; */
require_once "../config/config.php";
global $currentUser;
if(!userIsAdmin() or !isset($_GET['id'])) {
  header("Location: index.php");
  die();
}
global $language,$available_languages,$lang;
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

?>
<?php
include("pre_content.php");
?>	

<p><strong>Edit:</strong> <?php echo $study->name; ?> <br /> 
<?php
if(!empty($_POST) and count($errors)>0) {
?>
<div id="errors">
<?php errorOutput($errors); ?>
</div>
<?php
    }
?>
<form id="edit_form" name="edit_form" method="post" action="edit_study.php?id=<?php echo $_GET['id']; ?>" enctype="multipart/form-data">
  <p>
  <label>Name
  </label>
  <input type="text" name="name" id="name" value="<?php echo $study->name; ?>"/>
  </p>
  <p>
  <label>Datenbank Prefix
  </label>
  <input type="text" name="prefix" id="prefix" value="<?php echo $study->prefix; ?>"/>
  </p>
  <p>
  <label>Studie nur f&uuml;r registrierte Benutzer verf&uuml;gbar
  </label>
  <input type="checkbox" name="registered" id="registered" <?php if($study->registered_req==true) echo "checked";?>/>
  </p>
  <p>
  <label>Ver&ouml;ffentlichen
  </label>
  <input type="checkbox" name="public" id="public" <?php if($study->public==true) echo "checked";?>/>
  </p>

  <p>
  <label>Logo Upload(gif/jpg/jpeg bis 1Mb)</label>
  <input type="file" name="logo" id="logo"/>
  </p>                    

  <button type="submit">Absenden</button>
  </form>



<br>
<p><a href="view_study.php?id=<?php echo $study->id; ?>">Zur&uuml;ck zur Studie</a></p>

<?php
include("post_content.php");
?>	