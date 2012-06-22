<?php
require_once $_SERVER['DOCUMENT_ROOT']."/tmp/config/config.php";
if(!userIsAdmin() or !isset($_GET['id'])) {
  header("Location: /tmp/index.php");
  die();
}
global $language,$available_languages,$lang;
$ad=new Default_Ad;
$ad->fillIn($_GET['id']);
if(!$ad->status)
  header("Location: /tmp/index.php");

if(!empty($_POST)) {
  $errors=array();
  if(isset($_POST['name']) and $_POST['name']!==$ad->name)
    $ad->changeName($_POST['name']);
  if(!$ad->status)
    $errors=array_merge($errors,$ad->GetErrors());
  if(isset($_POST['css']) and $_POST['css']!==$ad->css)
    $ad->changeCss($_POST['css']);
  if(!$ad->status)
    $errors=array_merge($errors,$ad->GetErrors());
  if(isset($_POST['template']) and $_POST['template']!==$ad->template)
    $ad->changeTemplate($_POST['template']);
  if(!$ad->status)
    $errors=array_merge($errors,$ad->GetErrors());
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
  <p><strong>Edit <?php echo $ad->name; ?> </strong><br /> 
<?php
if(!empty($_POST) and count($errors)>0) {
?>
<div id="errors">
<?php errorOutput($errors); ?>
</div>
<?php
    }
?>
<form id="edit_form" name="edit_form" method="post" action="/tmp/acp/edit_default_ad.php?id=<?php echo $_GET['id']; ?>">
  <p>
  <label><?php echo $lang['DEFAULT_AD_NAME']; ?>
  </label>
  <input type="text" name="name" id="name" value="<?php echo $ad->name; ?>"/>
  </p>
  <p>
  <label><?php echo $lang['DEFAULT_AD_CSS']; ?>
  </label>
   <TEXTAREA name="css" id="css" rows="12" cols="60">
<?php echo $ad->css ?>
   </TEXTAREA>
  </p>
  <p>
  <label><?php echo $lang['DEFAULT_AD_TEMPLATE']; ?>
  </label>
   <TEXTAREA name="template" id="template" rows="12" cols="60">
  <?php echo $ad->template; ?>
   </TEXTAREA>
  </p>
  <button type="submit">Edit Ad Template</button>
  </form>

<p><a href="acp.php">Back to ACP</a></p>

		  <br />
		  <br />

		</div>
		<!-- End Text -->
		
		</div>
		<!-- End Left Content -->
		
		<!-- Start Right Contefnt -->
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