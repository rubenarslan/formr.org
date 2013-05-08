<?php
require_once 'define_root.php';
require_once INCLUDE_ROOT."config/config.php";

if(userIsLoggedIn()) {
  header("Location: index.php");
  die();
}

if(!empty($_POST)) {
  $user=new NewUser($_POST['email'],$_POST['password'],$_POST['passwordr']);
  $errors=array();
  if(!$user->status) {
    $errors=$user->GetErrors();
  } else {
    if(!$user->Register())
      $errors=$user->GetErrors();
    else {
      /* if(!$user->sendMail()) */
      /*   $errors=$user->GetErrors(); */
      /* else { */
      $_SESSION['userMail']=$user->email;
      header("Location: register_success.php");
      /* } */
    }
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


<form id="register_form" name="register_form" method="post" action="register.php">
  <p>
  <label><?php echo _("email address"); ?>
  </label>
  <input type="text" name="email" id="email" value="<?php if(isset($_POST['email'])) echo $_POST['email'];?>"/>
  </p>
  <p>
  <p>
  <label><?php echo _("password"); ?>
  </label>
  <input type="password" name="password" id="password" value="<?php if(isset($_POST['password'])) echo $_POST['password'];?>"/>
  </p>
  <p>
  <label><?php echo _("repeat password"); ?>
  </label>
  <input type="password" name="passwordr" id="passwordr" value="<?php if(isset($_POST['passwordr'])) echo $_POST['passwordr'];?>"/>
  </p>
<p>
  <button type="submit"><?php echo _("Registrieren"); ?></button>
</p>
  </form>


<?php
require_once INCLUDE_ROOT."view_footer.php";
