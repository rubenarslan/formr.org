<?php
/* require_once $_SERVER['DOCUMENT_ROOT']."/tmp/config/config.php"; */
require_once "config/config.php";
if(userIsLoggedIn()) {
  header("Location: index.php");
  die();
}
?>
<?php
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
<form id="login_form" name="login_form" method="post" action="login.php">
  <p>
  <label>Email
  </label>
  <input type="text" name="email" id="email" value="<?php if(isset($_POST['email']))echo $_POST['email'];?>"/>
  </p>
  <p>
  <label>Passwort
  </label>
  <input type="password" name="password" id="password" value="<?php if(isset($_POST['password']))echo $_POST['password'];?>"/>
  </p>
  <button type="submit">Login</button>
  </form>


<?php
include("post_content.php");
?>	