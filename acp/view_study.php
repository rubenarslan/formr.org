<?php
require_once '../includes/define_root.php';
require_once INCLUDE_ROOT . "config/config.php";

global $currentUser;
if(!userIsAdmin() or !isset($_GET['id'])) {
  header("Location: index.php");
  die();
}

global $language,$available_languages,$lang;
$study=new Study;
$study->fillIn($_GET['id']);
if(!$study->status)
  header("Location: ../index.php");
if(!$currentUser->ownsStudy($_GET['id']))
  header("Location: ../index.php");

require_once INCLUDE_ROOT . "view_header.php";
?>	
<h2><?php echo $study->name;?></h2>

<ul class="nav nav-tabs">
	<li><a href="<?=WEBROOT?>admin/index.php?study_id=<?php echo $study->id; ?>"><?php echo _("Admin Bereich"); ?></a></li>
	<li><a href="<?=WEBROOT?>acp/edit_study.php?id=<?php echo $study->id; ?>"><?php echo _("Veröffentlichung kontrollieren"); ?></a></li>
	<li><a href="<?=WEBROOT?>survey.php?study_id=<?php echo $study->id; ?>"><?php echo _("Studie testen"); ?></a></li>
	<li><a href="<?=WEBROOT?>acp/acp.php"><?php echo _("Zurück zum ACP"); ?></a></li>	
</ul>

<?php /*
<p><a href="edit_study_mails.php?id=<?php echo $study->id; ?>"><?php echo _("E-Mail Benachrichtigungen"); ?></a></p>
*/
?>

<?php
require_once INCLUDE_ROOT . "view_footer.php";
