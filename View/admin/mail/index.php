<?php
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
