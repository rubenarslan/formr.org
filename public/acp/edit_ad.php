<?php
require_once $_SERVER['DOCUMENT_ROOT']."/tmp/config/config.php";
if(!userIsAdmin() or !isset($_GET['id'])) {
  header("Location: /tmp/index.php");
  die();
}
global $language,$available_languages,$lang;
$ad=new Ad;
$ad->fillIn($_GET['id']);
if(!$ad->status)
  header("Location: /tmp/index.php");

if(!empty($_POST)) {
  $errors=array();
  if(isset($_POST['name']) and $_POST['name']!==$ad->name)
    $ad->changeName($_POST['name']);
  if(!$ad->status)
    $errors=array_merge($errors,$ad->GetErrors());
  if(isset($_POST['associate_tag']) and $_POST['associate_tag']!==$ad->associate_tag)
    $ad->changeAssociateTag($_POST['associate_tag']);
  if(!$ad->status)
    $errors=array_merge($errors,$ad->GetErrors());
  if(isset($_POST['access_key']) and $_POST['access_key']!==$ad->access_key)
    $ad->changeAccessKey($_POST['access_key']);
  if(!$ad->status)
    $errors=array_merge($errors,$ad->GetErrors());
  if(isset($_POST['private_key']) and $_POST['private_key']!==$ad->private_key)
    $ad->changePrivateKey($_POST['private_key']);
  if(!$ad->status)
    $errors=array_merge($errors,$ad->GetErrors());

}

?>
<?php
include("pre_content.php");
?>	

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
<form id="edit_form" name="edit_form" method="post" action="/tmp/acp/edit_ad.php?id=<?php echo $_GET['id']; ?>">
  <p>
  <label><?php echo $lang['AD_NAME']; ?>
  </label>
  <input type="text" name="name" id="name" value="<?php echo $ad->name; ?>"/>
  </p>
  <p>
  <label><?php echo $lang['ASSOCIATE_TAG']; ?>
  </label>
  <input type="text" name="associate_tag" id="associate_tag" value="<?php echo $ad->associate_tag; ?>"/>
  </p>
  <p>
  <label><?php echo $lang['ACCESS_KEY']; ?>
  </label>
  <input type="text" name="access_key" id="access_key" value="<?php echo $ad->access_key; ?>"/>
  </p>
  <p>
  <label><?php echo $lang['PRIVATE_KEY']; ?>
  </label>
  <input type="text" name="private_key" id="private_key" value="<?php echo $ad->private_key; ?>"/>
  </p>
  <button type="submit">Edit Ad-Block Data</button>
  </form>

<p><a href="view_ads.php?id=<?php echo $ad->website_id; ?>">Back to Websites Ads</a></p>


<?php
include("post_content.php");
?>	