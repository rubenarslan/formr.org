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

?>
<?php
require_once INCLUDE_ROOT . "view_header.php";
?>	

<p><strong><?php echo _("Email Benachrichtigungen: "); ?></strong> <?php echo $study->name; ?> <br /> 
<?php
if(!empty($_POST) and count($errors)>0) {
?>
<div id="errors">
<?php errorOutput($errors); ?>
</div>
<?php
    }
?>
<form id="mail_form" name="mail_form" method="post" action="edit_study_mails.php?id=<?php echo $_GET['id']; ?>" >
  <p>
  <label><?php echo _("Name"); ?>
  </label>
  <input type="text" name="name" id="name"  value="<?php if(isset($_POST['name'])) echo $_POST['name']; ?>"/>
  </p>
  <p>
  <label><?php echo _("Betreff"); ?>
  </label>
  <input type="text" name="subject" id="subject"  value="<?php if(isset($_POST['subject'])) echo $_POST['subject']; ?>"/>
  </p>
  <p>
  <label><?php echo _("Nachricht"); ?>
  </label>
   <TEXTAREA name="message" id="message" rows="12" cols="60">
  <?php if(isset($_POST['message'])) echo $_POST['message']; ?>
   </TEXTAREA>
  </p>
  <p>
  <label><?php echo _("Email schicken nach wieviel Tagen?"); ?>
  </label>
  <input type="text" name="subject" id="subject"  value="<?php if(isset($_POST['subject'])) echo $_POST['subject']; ?>"/>
  </p>
  <button type="submit"><?php echo _("Benachrichtigung einrichten"); ?></button>
  </form>



<br>
  <p><a href="view_study.php?id=<?php echo $study->id; ?>"><?php echo _("Zurück zur Studie"); ?></a></p>

<?php
require_once INCLUDE_ROOT . "view_footer.php";