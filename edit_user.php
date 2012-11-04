<?php
require_once "config/config.php";
if(!userIsLoggedIn()) {
  header("Location: index.php");
  die();
}
?>
<?php
if(!empty($_POST)) {
  $errors=array();
  if(isset($_POST['email']) and $_POST['email']!=$currentUser->email)
    $currentUser->changeEmail($_POST['email']);
  if(!$currentUser->status)
    $errors=array_merge($errors,$currentUser->GetErrors());
  if(isset($_POST['password']) and isset($_POST['password_new']) and isset($_POST['password_newr']) and ($_POST['password']!='' or $_POST['password_new']!='' or $_POST['password_newr']!=''))
    $currentUser->changePassword($_POST['password'],$_POST['password_new'],$_POST['password_newr']);
  if(!$currentUser->status)
    $errors=$currentUser->GetErrors();
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
<form id="edit_form" name="edit_form" method="post" action="edit_user.php">
  <p>
  <label><?php echo _("Email Adresse"); ?>
  </label>
  <input type="text" name="email" id="email" value="<?php echo $currentUser->email; ?>"/>
  </p>
<br>
  <p>
  <label>Aktuelles Passwort
  </label>
  <input type="password" name="password" id="password"/>
  </p>
  <p>
  <label>Neues Passwort
  </label>
  <input type="password" name="password_new" id="password_new"/>
  </p>
  <p>
  <label>Neues Passwort Wiederholung
  </label>
  <input type="password" name="password_newr" id="password_newr"/>
  </p>
<p>
  <button type="submit">Speichern</button>
</p>
  </form>

<?php
include("post_content.php");
?>	