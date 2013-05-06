<?php
require_once '../includes/define_root.php';
require_once INCLUDE_ROOT . "config/config.php";
require_once INCLUDE_ROOT . "admin/admin_header.php";

require_once INCLUDE_ROOT . "view_header.php";
?>
<h2><?php echo $study->name;?></h2>

<ul class="nav nav-tabs">
	<li><a href="<?=WEBROOT?>admin/<?= $study->name; ?>/index"><?php echo _("Admin Bereich"); ?></a></li>
	<li><a href="<?=WEBROOT?>acp/edit_study.php?study_name=<?php echo $study->name; ?>"><?php echo _("Veröffentlichung kontrollieren"); ?></a></li>
	<li><a href="<?=WEBROOT?><?php echo $study->name; ?>/access"><?php echo _("Studie testen"); ?></a></li>
	<li><a href="<?=WEBROOT?>acp/acp.php"><?php echo _("Zurück zum ACP"); ?></a></li>	
</ul>

<?php /*
<p><a href="edit_study_mails.php?id=<?php echo $study->id; ?>"><?php echo _("E-Mail Benachrichtigungen"); ?></a></p>
*/
?>

<?php
require_once INCLUDE_ROOT . "view_footer.php";
