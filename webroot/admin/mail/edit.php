<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT . "View/admin_header.php";

require_once INCLUDE_ROOT . "Model/EmailAccount.php";

$acc = new EmailAccount($fdb, $_GET['account_id'], $user->id);
if($user->created($acc)):
	alert("<strong>Error:</strong> Not your email account.",'alert-error');
	redirect_to("/>admin/list_email_accounts");
endif;

if(!empty($_POST)) 
{
	$acc->changeSettings($_POST);
	redirect_to(">admin/edit_email_account?account_id=".$_GET['account_id']);
	alert('<strong>Success!</strong> Your email account settings were changed!','alert-success');
}
require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/acp_nav.php";
?>

<form class="form-horizontal"  id="edit_email_account" name="edit_email_account" method="post" action="<?=WEBROOT?>admin/edit_email_account?account_id=<?=h($_GET['account_id'])?>">
	<div class="control-group">
		<label class="control-label" for="from">
			<?php echo _("From:"); ?>
		</label>
		<div class="controls">
			<input required type="text" placeholder="you@example.org" name="from" id="from" value="<?=$acc->account['from']; ?>">
		</div>
	</div>
	
	<div class="control-group">
		<label class="control-label" for="from_name">
			<?php echo _("From (Name):"); ?>
		</label>
		<div class="controls">
			<input required type="text" placeholder="Your Name" name="from_name" id="from_name" value="<?=$acc->account['from_name']; ?>">
		</div>
	</div>
	
	<div class="control-group">
		<label class="control-label" for="host">
			<?php echo _("SMTP Host:"); ?>
		</label>
		<div class="controls">
			<input required type="text" placeholder="ssl://smtp.gmail.com" name="host" id="host" value="<?=$acc->account['host']; ?>">
		</div>
	</div>
	
	<div class="control-group">
		<label class="control-label" for="port">
			<?php echo _("Port:"); ?>
		</label>
		<div class="controls">
			<input required type="text" placeholder="467" name="port" id="port" value="<?=$acc->account['port']; ?>">
		</div>
	</div>
	
	<div class="control-group">
		<label class="control-label" for="tls">
			<?php echo _("TLS:"); ?>
		</label>
		<div class="controls">
			<input type="hidden" name="tls" value="0">
			<input type="checkbox"name="tls" id="tls" value="1" <?= ($acc->account['tls']) ? 'checked':''; ?>>
		</div>
	</div>
	
	<div class="control-group">
		<label class="control-label" for="username">
			<?php echo _("Username:"); ?>
		</label>
		<div class="controls">
			<input required type="text" placeholder="you@example.org" name="username" id="username" value="<?=$acc->account['username']; ?>">
		</div>
	</div>
	
	<div class="control-group">
		<label class="control-label" for="password">
			<?php echo _("Password:"); ?>
		</label>
		<div class="controls">
			<input required type="text" placeholder="you@example.org" name="password" id="password" value="<?=$acc->account['password']; ?>">
		</div>
	</div>
	
	<div class="control-group">
		<div class="controls">
			<input class="btn" required type="submit" value="<?php echo _("Save account"); ?>">
		</div>
	</div>
</form>

<?php
require_once INCLUDE_ROOT . "View/footer.php";
