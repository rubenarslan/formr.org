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
        <div class="pull-right hidden-xs">Copyright &copy; <?= date('Y') ?> formr<?php
                        if (!empty($user) && $user->loggedIn()) {
                            echo ' - ' . FORMR_VERSION;
                        }
                            ?></div>
        <ul class="nav navbar-nav">
        <li><a href="https://github.com/rubenarslan/formr.org" target="_blank"><i class="fa fa-github-alt fa-fw"></i> Github repository </a></li>
        <li><a href="https://github.com/rubenarslan/formr" target="_blank"><i class="fa fa-github-alt fa-fw"></i> R package on Github </a></li>
    </ul>
</footer>

</div>
<!-- ./wrapper -->

</body>

</html>