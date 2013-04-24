<?php
require_once '../includes/define_root.php';
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
<form id="edit_form" name="edit_form" method="post" action="edit_study.php?id=<?php echo $_GET['id']; ?>" enctype="multipart/form-data">
  <p>
  <label><?php echo _("Name"); ?>
  </label>
  <input type="text" name="name" id="name" value="<?php echo $study->name; ?>"/>
  </p>
  <p>
  <label><?php echo _("Datenbank Prefix"); ?>
  </label>
  <input type="text" name="prefix" id="prefix" value="<?php echo $study->prefix; ?>"/>
  </p>
  <p>
  <label><?php echo _("Studie nur für registrierte Benutzer verfügbar"); ?>
  </label>
  <input type="checkbox" name="registered" id="registered" <?php if($study->registered_req==true) echo "checked";?>/>
  </p>
  <p>
  <label><?php echo _("Veröffentlichen"); ?>
  </label>
  <input type="checkbox" name="public" id="public" <?php if($study->public==true) echo "checked";?>/>
  </p>

  <p>
  <label>Logo Upload(gif/jpg/jpeg bis 1Mb)</label>
  <input type="file" name="logo" id="logo"/>
  </p>                    

  <button type="submit"><?php echo _("Absenden"); ?></button>
  </form>



<br>
  <p><a href="view_study.php?id=<?php echo $study->id; ?>"><?php echo _("Zurück zur Studie"); ?></a></p>

<?php
require_once INCLUDE_ROOT . "view_footer.php";