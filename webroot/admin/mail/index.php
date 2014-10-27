<?php
if(!empty($_POST) AND isset($_POST['create'])) {
	$acc = new EmailAccount($fdb, null, $user->id);
	if( 
		$acc->create()
	)
	{
		alert('<strong>Success!</strong> You added a new email account!','alert-success');
		redirect_to("admin/mail/edit/?account_id=".$acc->id);
	}
	else {
		alert(implode($acc->errors),'alert-danger');
	}
}

Template::load('header');
Template::load('acp_nav');

?>
<form id="list_email_accounts" name="list_email_accounts" method="post" action="<?=WEBROOT?>admin/mail/">
<div class="col-md-3 col-md-offset-1 well">
<h3>Email accounts</h3>
<p><button class="btn btn-default btn-info" name="create" type="submit"><i class="fa-fw fa fa-envelope-o"></i> Create new account</button></p>

<?php
$accs = $user->getEmailAccounts();
if($accs) {
  echo '<ul class="fa-ul">
	  ';
  foreach($accs as $account) {
    echo "<li>
		<i class=\"fa-li fa fa-envelope\"></i> <a href='".WEBROOT."admin/mail/edit/?account_id=".$account['id']."'>".$account['from']."</a>
	</li>";
  }
  echo '</ul>';
}
?>
</div>
</form>

<?php Template::load('footer');
