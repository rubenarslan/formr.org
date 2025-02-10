</div>
<div class="clearfix"></div>
</div>
</div>
</div>
</div>
<div class="clear"></div>
</section>

</div>

<footer class="main-footer">
    <ul class="nav navbar-nav pull-right hidden-xs">
        <li>
            <a href="<?= site_url() ?>">
                Copyright &copy;
                <?= date('Y') ?> formr
                <?php
                    if (!empty($user) && $user->loggedIn()) {
                        echo ' - ' . FORMR_VERSION;
                    }
                ?>
            </a>
        </li>
    </ul>
    <ul class="nav navbar-nav">
        <li><a href="https://github.com/rubenarslan/formr.org" target="_blank"><i class="fa fa-github-alt fa-fw"></i> Github repository </a></li>
        <li><a href="https://github.com/rubenarslan/formr" target="_blank"><i class="fa fa-github-alt fa-fw"></i> R package on Github </a></li>
    </ul>
    <div class="clear clearfix"></div>
</footer>

</div>
<!-- ./wrapper -->

</body>

</html>