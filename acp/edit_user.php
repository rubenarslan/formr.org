<?php
require_once $_SERVER['DOCUMENT_ROOT']."/tmp/config/config.php";
if(!userIsAdmin() or !isset($_GET['id'])) {
  header("Location: /tmp/index.php");
  die();
}
global $language,$available_languages,$lang;
$user=new User;
$user->fillIn($_GET['id']);
if(!$user->status)
  header("Location: /tmp/index.php");

if(!empty($_POST)) {
  $rec=false;
  if(isset($_POST['recursive']) and $_POST['recursive']==true)
    $rec=true;
  $errors=array();
  if(isset($_POST['default_language']) and $_POST['default_language']!==$user->default_language) {
    $user->changeDefaultLanguage($_POST['default_language']);
    if(!$user->status)
      $errors=array_merge($errors,$user->GetErrors());
    else {
      $lang_file=validLangOrDefault($_POST['default_language']);
      require_once("lang/".$lang_file.".php");  
    }
  }
  if(isset($_POST['fname']) and $_POST['fname']!==$user->fname)
    $user->changeFname($_POST['fname']);
  if(!$user->status)
    $errors=array_merge($errors,$user->GetErrors());
  if(isset($_POST['lname']) and $_POST['lname']!==$user->lname)
    $user->changeLname($_POST['lname']);
  if(!$user->status)
    $errors=array_merge($errors,$user->GetErrors());
  if(isset($_POST['email']) and $_POST['email']!==$user->email)
    $user->changeEmail($_POST['email']);
  if(!$user->status)
    $errors=array_merge($errors,$user->GetErrors());
  if(isset($_POST['street']) and $_POST['street']!==$user->street)
    $user->changeStreet($_POST['street']);
  if(!$user->status)
    $errors=array_merge($errors,$user->GetErrors());
  if(isset($_POST['address2']) and $_POST['address2']!==$user->address2)
    $user->changeAddress2($_POST['address2']);
  if(!$user->status)
    $errors=array_merge($errors,$user->GetErrors());
  if(isset($_POST['city']) and $_POST['city']!==$user->city)
    $user->changeCity($_POST['city']);
  if(!$user->status)
    $errors=array_merge($errors,$user->GetErrors());
  if(isset($_POST['state']) and $_POST['state']!==$user->state)
    $user->changeState($_POST['state']);
  if(!$user->status)
    $errors=array_merge($errors,$user->GetErrors());
  if(isset($_POST['postal']) and $_POST['postal']!==$user->postal)
    $user->changePostal($_POST['postal']);
  if(!$user->status)
    $errors=array_merge($errors,$user->GetErrors());
  if(isset($_POST['country']) and $_POST['country']!==$user->country)
    $user->changeCountry($_POST['country']);
  if(!$user->status)
    $errors=array_merge($errors,$user->GetErrors());
  if(isset($_POST['uid']) and $_POST['uid']!==$user->uid)
    $user->changeUid($_POST['uid']);
  if(!$user->status)
    $errors=array_merge($errors,$user->GetErrors());
  if($user->active==true and !isset($_POST['active']))
    $user->changeActive(false);
  elseif($user->active==false and isset($_POST['active']))
    $user->changeActive(true);
  if(!$user->status)
    $errors=array_merge($errors,$user->GetErrors());
  if($user->email_verified==true and !isset($_POST['email_verified']))
    $user->changeEmailVerified(false);
  elseif($user->email_verified==false and isset($_POST['email_verified']))
    $user->changeEmailVerified(true);
  if(!$user->status)
    $errors=array_merge($errors,$user->GetErrors());
  if(isset($_POST['associate_tag']) and $_POST['associate_tag']!==$user->associate_tag)
    $user->changeAssociateTag($_POST['associate_tag'],$rec);
  if(!$user->status)
    $errors=array_merge($errors,$user->GetErrors());
  if(isset($_POST['access_key']) and $_POST['access_key']!==$user->access_key)
    $user->changeAccessKey($_POST['access_key'],$rec);
  if(!$user->status)
    $errors=array_merge($errors,$user->GetErrors());
  if(isset($_POST['private_key']) and $_POST['private_key']!==$user->private_key)
    $user->changePrivateKey($_POST['private_key'],$rec);
  if(!$user->status)
    $errors=array_merge($errors,$user->GetErrors());
}

?>
<?php
include("pre_content.php");
?>		
	<!-- Start Content -->
	<div id="content">
	
		<!-- Start Left Content -->
		<div id="c_left">
		
		<!-- Start Headline -->
		<h1>Questions?  <span> - Feel free to contact us </span></h1>
	    <img src="../graphic/headline_line.gif" alt="" width="550" height="25" class="headline_line" />
		<br />
		<!-- End Headline -->		
		
		<!-- Start Text -->
		<div id="text_left">
  <p><strong>Edit <?php echo $user->email; ?> </strong><br /> 
<?php
if(!empty($_POST) and count($errors)>0) {
?>
<div id="errors">
<?php errorOutput($errors); ?>
</div>
<?php
    }
?>
<form id="edit_form" name="edit_form" method="post" action="/tmp/acp/edit_user.php?id=<?php echo $_GET['id']; ?>">
  <p>
  <label><?php echo $lang['FNAME']; ?>
  </label>
  <input type="text" name="fname" id="fname" value="<?php echo $user->fname; ?>"/>
  </p>
  <p>
  <label><?php echo $lang['LNAME']; ?>
  </label>
  <input type="text" name="lname" id="lname" value="<?php echo $user->lname; ?>"/>
  </p>
  <p>
  <label><?php echo $lang['EMAIL']; ?>
  </label>
  <input type="text" name="email" id="email" value="<?php echo $user->email; ?>"/>
  </p>
  <p>
  <label><?php echo $lang['STREET']; ?>
  </label>
  <input type="text" name="street" id="street" value="<?php echo $user->street; ?>"/>
  </p>
  <p>
  <label><?php echo $lang['ADDRESS2']; ?>
  </label>
  <input type="text" name="address2" id="address2" value="<?php echo $user->address2; ?>"/>
  </p>
  <p>
  <label><?php echo $lang['CITY']; ?>
  </label>
  <input type="text" name="city" id="city"  value="<?php echo $user->city; ?>"/>
  </p>
  <p>
  <label><?php echo $lang['STATE']; ?>
  </label>
  <input type="text" name="state" id="state" value="<?php echo $user->state; ?>"/>
  </p>
  <p>
  <label><?php echo $lang['POSTAL']; ?>
  </label>
  <input type="text" name="postal" id="postal" value="<?php echo $user->postal; ?>"/>
  </p>
  <p>
  <label><?php echo $lang['COUNTRY']; ?>
  </label>
  <input type="text" name="country" id="country" value="<?php echo $user->country; ?>"/>
  </p>
  <p>
  <label><?php echo $lang['UID']; ?>
  </label>
  <input type="text" name="uid" id="uid"  value="<?php echo $user->uid; ?>"/>
  </p>
  <p>
  <label><?php echo $lang['DEFLANG']; ?>
  </label>
<?php 
  foreach($available_languages as $l) {
  echo "<p>";
  echo "<input type='radio' name='default_language' value='$l'"; if($l===$user->default_language) echo " checked "; echo ">$l<br>";
  echo "</p>";
}
?>
   </p>
<p>
  <label><?php echo $lang['ACTIVE']; ?>
  </label>
  <input type="checkbox" name="active" id="active" <?php if($user->active==true) echo "checked";?>/>
</p>
<p>
  <label><?php echo $lang['EMAIL_VERIFIED']; ?>
  </label>
  <input type="checkbox" name="email_verified" id="email_verified" <?php if($user->email_verified==true) echo "checked";?>/>
</p>
  <p>
  <label><?php echo $lang['ASSOCIATE_TAG']; ?>
  </label>
  <input type="text" name="associate_tag" id="associate_tag" value="<?php echo $user->associate_tag; ?>"/>
  </p>
  <p>
  <label><?php echo $lang['ACCESS_KEY']; ?>
  </label>
  <input type="text" name="access_key" id="access_key" value="<?php echo $user->access_key; ?>"/>
  </p>
  <p>
  <label><?php echo $lang['PRIVATE_KEY']; ?>
  </label>
  <input type="text" name="private_key" id="private_key" value="<?php echo $user->private_key; ?>"/>
  </p>
  <p>
  <label><?php echo $lang['RECURSIVE_OVERWRITE']; ?>
  </label>
  <input type="checkbox" name="recursive" id="recursive"/>
  </p>
  <button type="submit">Edit User Data</button>
  </form>

<p><a href="view_websites.php?id=<?php echo $user->id; ?>">View Users Websites</a></p>
<p><a href="view_stats.php?id=<?php echo $user->id; ?>">View Users Stats</a></p>
		  <br />
		  <br />

		</div>
		<!-- End Text -->
		
		</div>
		<!-- End Left Content -->
		
		<!-- Start Right Content -->
      <div id="c_right">
	  	
		<!-- Start Headline -->
		<h1>From our blog  <span> - The latest news </span></h1>
		<img src="../graphic/headline_line.gif" alt="" width="300" height="25" class="headline_line" />
		<br />
		<!-- End Headline -->
		
		<!-- Start Image -->
		<div class="image">
		  <p><a href="http://themeforest.net/item/coffee-junkie-xhmtlcss-version/44738?ref=-ilove2design-" target="_blank"><img src="../graphic/blog.gif" alt="Coffee Junkie" width="82" height="82" border="0" /></a></p>
		</div>
		<!-- End Image -->
		
		<!-- Start Text -->
		<div class="text_right">
		<p><strong>Lorem ipsum dolor sit</strong>
		<br />
		Amet, con adipiscing elit. Proin aliquam,  er non bibendum venenatis, <a href="http://themeforest.net/item/coffee-junkie-xhmtlcss-version/44738?ref=-ilove2design-" target="_blank">see it online here</a>.</p>
		</div>
		<!-- End Text -->
		
		<br style="clear:both" /> 
		<!-- DO NOT REMOVE THIS LINE!!! -->
		
		<!-- Start Divider-->
		<div class="divider">
		<img src="../graphic/headline_line.gif" alt="" width="300" height="25" class="headline_line" />
		</div>
		<!-- End Divider-->
		
		<!-- Start Image -->
		<div class="image">
		  <p><a href="http://themeforest.net/item/coffee-junkie-xhmtlcss-version/44738?ref=-ilove2design-" target="_blank"><img src="../graphic/blog.gif" alt="Coffee Junkie" width="82" height="82" border="0" /></a></p>
		</div>
		<!-- End Image -->
		
		<!-- Start Text -->
		<div class="text_right">
		<p><strong>Lorem ipsum dolor sit</strong>
		<br />
		Amet, con adipiscing elit. Proin aliquam,  er non bibendum venenatis, <a href="http://themeforest.net/item/coffee-junkie-xhmtlcss-version/44738?ref=-ilove2design-" target="_blank">see it online here</a>.</p>
		</div>
		<!-- End Text -->
		
		<br style="clear:both" /> <!-- DO NOT REMOVE THIS LINE!!! -->
		
		<!-- Start Divider-->
		<div class="divider">
		<img src="../graphic/headline_line.gif" alt="" width="300" height="25" class="headline_line" />
		</div>
		<!-- End Divider-->
		
		<!-- Start RSS Line-->
		<div id="rss">
		<p><strong>&raquo; Subscribe to our RSS Feed </strong><img src="../graphic/rss.gif" alt="rss" width="16" height="16" /></p>
		</div>
		<!-- End RSS Line-->
		
	  </div>		
		<!-- End Right Content -->
		
		<br style="clear:both" /> <!-- DO NOT REMOVE THIS LINE!!! -->
		
	</div>
	<!-- End Content -->
<?php
include("post_content.php");
?>	