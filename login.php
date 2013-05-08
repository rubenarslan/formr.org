<?php
require_once 'define_root.php';
require_once INCLUDE_ROOT."config/config.php";

if(userIsLoggedIn()) {
  header("Location: index.php");
  die();
}

if(!empty($_POST)) {
  $user=new User;
  $user->login($_POST['email'],$_POST['password']);
  $errors=array();
  if(!$user->status) {
    $errors=$user->GetErrors();
  } else {
    $_SESSION["userObj"]=$user;
    header("Location: index.php");
  }
}
require_once INCLUDE_ROOT."view_header.php";
require_once INCLUDE_ROOT."public_nav.php";


if(!empty($_POST) and count($errors)>0) {
?>
<div id="errors">
<?php errorOutput($errors); ?>
</div>
<?php
    }
?>
<form id="login_form" name="login_form" method="post" action="login.php">
  <p>
  <label><?php echo _("email address"); ?>
  </label>
  <input type="text" name="email" id="email" value="<?php if(isset($_POST['email']))echo $_POST['email'];?>"/>
  </p>
  <p>
  <label><?php echo _("password"); ?>
  </label>
  <input type="password" name="password" id="password" value="<?php if(isset($_POST['password']))echo $_POST['password'];?>"/>
  </p>
<p>
  <button type="submit"><?php echo _("Login"); ?></button>
</p>
  </form>


<?php
require_once INCLUDE_ROOT."view_footer.php";
