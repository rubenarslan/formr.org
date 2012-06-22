<?php
/* require_once $_SERVER['DOCUMENT_ROOT']."/tmp/config/config.php"; */
require_once "config/config.php";
if(userIsLoggedIn()) {
  header("Location: index.php");
  die();
}
?>
<?php
global $language,$lang;
if(!empty($_POST)) {
  /* $user=new NewUser($_POST['fname'],$_POST['lname'],$_POST['email'],$_POST['password'],$_POST['passwordr'],$_POST['street'],$_POST['address2'],$_POST['city'],$_POST['state'],$_POST['postal'],$_POST['country'],$_POST['uid'],$um,$_POST['bank_name'],$_POST['blz'],$_POST['kontonummer'],$language); */
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

<form id="register_form" name="register_form" method="post" action="register.php">
  <!-- <p> -->
  <!-- <label><?php echo $lang['FNAME']; ?> -->
  <!-- </label> -->
  <!-- <input type="text" name="fname" id="fname" value="<?php if(isset($_POST['fname'])) echo $_POST['fname'];?>"/> -->
  <!-- </p> -->
  <!-- <p> -->
  <!-- <label><?php echo $lang['LNAME']; ?> -->
  <!-- </label> -->
  <!-- <input type="text" name="lname" id="lname" value="<?php if(isset($_POST['lname'])) echo $_POST['lname'];?>"/> -->
  <!-- </p> -->
  <p>
  <label><?php echo $lang['EMAIL']; ?>
  </label>
  <input type="text" name="email" id="email" value="<?php if(isset($_POST['email'])) echo $_POST['email'];?>"/>
  </p>
  <p>
  <!-- <p> -->
  <!-- <label><?php echo $lang['STREET']; ?> -->
  <!-- </label> -->
  <!-- <input type="text" name="street" id="street" value="<?php if(isset($_POST['street'])) echo $_POST['street'];?>"/> -->
  <!-- </p> -->
  <!-- <p> -->
  <!-- <label><?php echo $lang['ADDRESS2']; ?> -->
  <!-- </label> -->
  <!-- <input type="text" name="address2" id="address2" value="<?php if(isset($_POST['address2'])) echo $_POST['address2'];?>"/> -->
  <!-- </p> -->
  <!-- <p> -->
  <!-- <label><?php echo $lang['CITY']; ?> -->
  <!-- </label> -->
  <!-- <input type="text" name="city" id="city" value="<?php if(isset($_POST['city'])) echo $_POST['city'];?>"/> -->
  <!-- </p> -->
  <!-- <p> -->
  <!-- <label><?php echo $lang['STATE']; ?> -->
  <!-- </label> -->
  <!-- <input type="text" name="state" id="state" value="<?php if(isset($_POST['state'])) echo $_POST['state'];?>"/> -->
  <!-- </p> -->
  <!-- <p> -->
  <!-- <label><?php echo $lang['POSTAL']; ?> -->
  <!-- </label> -->
  <!-- <input type="text" name="postal" id="postal" value="<?php if(isset($_POST['postal'])) echo $_POST['postal'];?>"/> -->
  <!-- </p> -->
  <!-- <p> -->
  <!-- <label><?php echo $lang['COUNTRY']; ?> -->
  <!-- </label> -->
  <!-- <input type="text" name="country" id="country" value="<?php if(isset($_POST['country'])) echo $_POST['country'];?>"/> -->
  <!-- </p> -->
  <p>
  <label><?php echo $lang['PASSWORD']; ?>
  </label>
  <input type="password" name="password" id="password" value="<?php if(isset($_POST['password'])) echo $_POST['password'];?>"/>
  </p>
  <p>
  <label><?php echo $lang['PASSWORD_REPEAT']; ?>
  </label>
  <input type="password" name="passwordr" id="passwordr" value="<?php if(isset($_POST['passwordr'])) echo $_POST['passwordr'];?>"/>
  </p>

  <button type="submit"><?php echo $lang['REGISTER']; ?></button>
  </form>


<?php
include("post_content.php");
?>	
