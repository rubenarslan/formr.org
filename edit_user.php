<?php
require_once "includes/define_root.php";
require_once INCLUDE_ROOT."config/config.php";
if(!userIsLoggedIn()) {
  header("Location: index.php");
  die();
}

require_once INCLUDE_ROOT."view_header.php";

echo '<ul class="nav nav-tabs">';

if(userIsAdmin())
{
   ?>
    <li><a href="<?=WEBROOT?>acp/acp.php"><?php echo _("Admin control panel"); ?></a></li>   
   <?php
}

if(userIsLoggedIn()) {  
	?>
	<li><a href="<?=WEBROOT?>logout.php"><?php echo _("Ausloggen"); ?></a></li>
	<li class="active"><a href="<?=WEBROOT?>edit_user.php"><?php echo _("Einstellungen ändern"); ?></a></li>
<?php
} else {
?>
     <li><a href="<?=WEBROOT?>login.php"><?php echo _("Login"); ?></a></li>
     <li><a href="<?=WEBROOT?>register.php"><?php echo _("Registrieren") ?></a></li>
<?php
}

echo "</ul>";

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
  <label><?php echo _("Aktuelles Passwort"); ?>
  </label>
  <input type="password" name="password" id="password"/>
  </p>
  <p>
  <label><?php echo _("Neues Passwort"); ?>
  </label>
  <input type="password" name="password_new" id="password_new"/>
  </p>
  <p>
  <label><?php echo _("Neues Passwort Wiederholung"); ?>
  </label>
  <input type="password" name="password_newr" id="password_newr"/>
  </p>
<p>
  <button type="submit"><?php echo _("Speichern"); ?></button>
</p>
  </form>

<?php
require_once INCLUDE_ROOT."view_footer.php";
