<nav class="navbar navbar-default">
    <div class="container-fluid">
        <div class="navbar-header">
            <button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#formr-nav" aria-expanded="false">
                <span class="sr-only">Toggle navigation</span>
                <span class="icon-bar"></span>
                <span class="icon-bar"></span>
                <span class="icon-bar"></span>
            </button>
            <a class="navbar-brand" href="<?php echo site_url(); ?>"><span>f</span>orm<span>{`r}</span></a>
        </div>

        <div class="collapse navbar-collapse" id="formr-nav">
            <ul class="nav navbar-nav">
                <li><a href="<?php echo site_url('about'); ?>">About</a></li>
                <li><a href="<?php echo site_url('documentation'); ?>">Documentation</a></li>
                <li><a href="<?php echo site_url('studies'); ?>">Studies</a></li>
                <li><a href="<?php echo site_url('publications'); ?>">Publications</a></li>
            </ul>
            <ul class="nav navbar-nav navbar-right">
                <?php if (!empty($user) && $user->loggedIn()): ?>
                    <li class="account"><a href="<?php echo site_url('account'); ?>"><i class="fa fa-user fa-fw"></i> Account</a></li>
                    <li><a href="<?php echo site_url('logout'); ?>"><i class="fa fa-power-off fa-fw"></i> Logout </a></li>
                <?php else: ?>
                    <li class="account"><a href="<?php echo site_url('login'); ?>"><i class="fa fa-sign-in fa-fw"></i> Login</a></li>
                    <li><a href="<?php echo site_url('register'); ?>"><i class="fa fa-pencil fa-fw"></i> Sign up</a></li>
                <?php endif; ?>
                <?php if (!empty($user) && $user->isAdmin()): ?>
                    <li><a href="<?php echo admin_url(); ?>"><i class="fa fa-eye-slash fa-fw"></i>Admin</a></li>
                    <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>
