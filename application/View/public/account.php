<?php
Template::load('public/header');
?>

<section id="fmr-hero" style="min-height: 250px;">
	<div class="fmr-overlay"></div>
</section>

<section class="fmr-account-info <?= $showform ?>">
<form action="" method="post" enctype="multipart/form-data">
	<div class="container">
		<div class="fmr-intro">
			<div class="row login-form large well">
				<div class="col-md-2 text-center">
					<div class="profile-user-img"></div>
					<div class="member-info">
						<span>Joined <?= $joined ?></span>
						<span><?= $studies ?> Studies Created</span>
					</div>
				</div>
				<div class="col-md-7">
					<?php Template::load('public/alerts'); ?>

					<div class="read-info">
						<h2 class="read-info"> <?= $names ?> <br /> <i> <?= $affiliation ?></i></h2>
						<p> &nbsp; </p>
						<h4 class="lead"> <i class="fa fa-lock"></i> Login Details</h4>
						<i class="fa fa-envelope-o fa-fw"></i> Email :  <?php echo $user->email; ?> <br />
						<i class="fa fa-eye-slash fa-fw"></i>  Password : ******************

						<p> &nbsp; </p>
						<h4 class="lead"> <i class="fa fa-user"></i> My Account </h4>
						<ul class="list-unstyled">
							<li><a href="<?= admin_url(); ?>"><i class="fa fa-eye-slash  fa-fw" /></i> Go to Admin</a> </li>
							<li><a href="javascript:void(0);" class="edit-info-btn"><i class="fa fa-edit  fa-fw" /></i> Edit Account</a> </li>
							<li><a href="javascript:void(0);" class="edit-info-btn"><i class="fa fa-lock  fa-fw" /></i> Change Password</a> </li>
							<li><a href="<?= site_url('logout'); ?>"><i class="fa fa-power-off fa-fw" /></i> Logout</a> </li>
						</ul>
					</div>

					<div class="edit-info">
						<h4 class="lead"> <i class="fa fa-user"></i> Basic Information</h4>
						
						<div class="form-group label-floating col-md-6 no-padding">
							<label class="control-label"> First Name </label>
							<input class="form-control" name="first_name" value="<?= h($user->first_name) ?>" autocomplete="off">
						</div>
						<div class="form-group label-floating col-md-6 no-padding" style="padding-left: 5px;">
							<label class="control-label"> Last Name </label>
							<input class="form-control" name="last_name" value="<?= h($user->last_name) ?>" autocomplete="off">
						</div>
						<div class="form-group label-floating col-md-12 no-padding">
							<label class="control-label"> Affiliation </label>
							<input class="form-control" name="affiliation"  value="<?= h($user->affiliation) ?>" autocomplete="off">
						</div>
						<div class="clearfix"></div>
						
						<h4 class="lead"> <i class="fa fa-lock"></i> Login Details (changes are effective immediately)</h4>
						<div class="form-group label-floating">
							<label class="control-label" for="email"><i class="fa fa-envelope-o fa-fw"></i> New Email</label>
							<input class="form-control" type="email" id="email" name="new_email" value="<?= h($user->email) ?>" autocomplete="off">
						</div>
						<h5>Password (leave empty to maintain current password)</h5>
						<div class="form-group label-floating">
							<label class="control-label" for="pass2"><i class="fa fa-key fa-fw"></i> Enter New Password (Choose a secure phrase)</label>
							<input class="form-control" type="password" id="pass2" name="new_password" autocomplete="off">
						</div>
						<div class="form-group label-floating">
							<label class="control-label" for="pass3"><i class="fa fa-key fa-fw"></i> Confirm New Password</label>
							<input class="form-control" type="password" id="pass3" name="new_password_c" autocomplete="off">
						</div>
						<p>&nbsp; <br /> &nbsp;</p>
						<h5><i class="fa fa-circle"></i> Enter Old Password to effect changes</h5>
						<div class="form-group label-floating" style="margin-top: 18px;">
							<label class="control-label" for="pass"><i class="fa fa-key fa-fw"></i> Old Password</label>
							<input class="form-control" type="password" id="pass" name="password" autocomplete="off">
						</div>
						<button class="btn btn-raised btn-primary"><i class="fa fa-save"></i> Save</button>
						<button class="btn btn-raised cancel-edit-btn pull-right"><i class="fa fa-close"></i> Cancel</button>
					</div>
				</div>
				<div class="col-md-3 js-fullheight elongate" style="background: #efefef;"></div>
				<div class="clearfix"></div>
			</div>
		</div>
	</div>
</form>
</section>

<?php Template::load('public/footer'); ?>
