<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <title>formr admin</title>
        <!-- Tell the browser to be responsive to screen width -->
        <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
       <?php
			foreach ($css as $id => $files) {
				print_stylesheets($files, $id);
			}
			foreach ($js as $id => $files) {
				print_scripts($files, $id);
			}
		?>

		<?php
			if (!isset($runs) || !isset($studies)) {
				$runs = $user->getRuns('id DESC', 5);
				$studies =  $user->getStudies('id DESC', 5);
			}
		?>
    </head>

    <body class="hold-transition skin-black">
        <div class="wrapper">

            <header class="main-header">
                <nav class="navbar navbar-static-top">
                    <div class="container-fluid">
                        <div class="navbar-header">
                            <a href="<?= admin_url(); ?>" class="navbar-brand"><b>form</b>{`r}</a>
                            <button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#navbar-collapse">
                                <i class="fa fa-bars"></i>
                            </button>
                        </div>

                        <!-- Collect the nav links, forms, and other content for toggling -->
                        <div class="collapse navbar-collapse" id="navbar-collapse">
                            <ul class="nav navbar-nav">
                                <li class="dropdown">
                                    <a href="#" class="dropdown-toggle" data-toggle="dropdown"><i class="fa fa-pencil-square"></i> Surveys <span class="caret"></span></a>
                                    <ul class="dropdown-menu" role="menu">
                                        <li><a href="<?php echo admin_url('survey'); ?>"><i class="fa fa-plus-circle"></i> Create new Survey</a></li>
                                        <?php if (!empty($studies)): ?>
											<li class="divider"></li>
											<?php foreach ($studies as $menu_study): ?>
												<li><a href="<?php echo admin_study_url($menu_study['name']); ?>"><?php echo $menu_study['name']; ?></a></li>
											<?php endforeach;?>
										<?php endif; ?>
										<li class="divider"></li>
										<li>
											<a href="<?php echo admin_url('survey/list'); ?>"><i class="fa fa-th-list"></i> View All</a>
										</li>
										
                                    </ul>
                                </li>
                                <li class="dropdown">
                                    <a href="#" class="dropdown-toggle" data-toggle="dropdown"><i class="fa fa-rocket"></i> Runs <span class="caret"></span></a>
                                    <ul class="dropdown-menu" role="menu">
                                        <li><a href="<?php echo admin_url('run'); ?>"><i class="fa fa-plus-circle"></i> Create new Run</a></li>
                                        <?php if (!empty($runs)): ?>
											<li class="divider"></li>
											<?php foreach ($runs as $menu_run): ?>
												<li><a href="<?php echo admin_run_url($menu_run['name']); ?>"><?php echo $menu_run['name']; ?></a></li>
											<?php endforeach; ?>
										<?php endif; ?>
										<li class="divider"></li>
										<li>
											<a href="<?php echo admin_url('run/list'); ?>"><i class="fa fa-th-list"></i> View All</a>
										</li>
                                    </ul>
                                </li>
                                <li><a href="<?php echo admin_url('mail'); ?>"><i class="fa fa-envelope"></i> <span>Mail Accounts</span></a></li>
                                <li class="dropdown">
                                    <a href="#" class="dropdown-toggle" data-toggle="dropdown"><i class="fa fa-cogs"></i> Advanced <span class="caret"></span></a>
                                    <ul class="dropdown-menu" role="menu">
                                        <li><a href="https://github.com/rubenarslan/formr.org"><i class="fa fa-github-alt fa-fw"></i> Github repository </a></li>
                                        <li><a href="https://github.com/rubenarslan/formr"><i class="fa fa-github-alt fa-fw"></i> R package on Github </a></li>
                                        <?php if ($user->isSuperAdmin()): ?>
										<li><a href="<?php echo site_url('superadmin/cron_log'); ?>"><i class="fa fa-cog fa-fw"></i>cron log</a></li>
                                        <li><a href="<?php echo site_url('superadmin/user_management'); ?>"><i class="fa fa-users fa-fw"></i> manage users</a></li>
                                        <li><a href="<?php echo site_url('superadmin/active_users'); ?>"><i class="fa fa-users fa-fw"></i> active users</a></li>
                                        <li><a href="<?php echo site_url('superadmin/runs_management'); ?>"><i class="fa fa-list fa-fw"></i> manage runs</a></li>
										<?php endif ;?>
									</ul>
                                </li>
                            </ul>

                            <ul class="nav navbar-nav navbar-right">
								<li><a href="<?php echo site_url('edit_user'); ?>"><i class="fa fa-user"></i> <span><?= $user->email;?> </span></a></li>
                                <li><a href="<?php echo site_url('documentation/#help'); ?>"><i class="fa fa-question-circle"></i> <span>Help</span></a></li>
                                <li><a href="<?php echo site_url(); ?>"><i class="fa fa-globe"></i> <span>Go to site</span></a></li>
                                <li><a href="<?php echo site_url('logout'); ?>"><i class="fa fa-power-off"></i> <span>Logout</span></a></li>
                            </ul>
                        </div><!-- /.navbar-collapse -->
                    </div><!-- /.container-fluid -->
                </nav>
            </header>
			<div class="alerts-container"></div>
