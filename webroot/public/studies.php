<?php
require_once '../../define_root.php';

require_once INCLUDE_ROOT."Model/Site.php";
require_once INCLUDE_ROOT."View/header.php";

$runs = $user->getAvailableRuns();

require_once INCLUDE_ROOT."View/public_nav.php";

if($runs) {
?>
<div class="row">
	<div class="col-lg-6 col-lg-offset-1 col-sm-5 col-sm-offset-1 col-xs-12">
		<h2><?=_("Current studies:")?></h2>
<?php
  foreach($runs as $run) {
    echo '
<div class="row">
	<div class="col-lg-12 well">
		<h4><a href="'.WEBROOT.$run['name']. '">'. ($run['title']?$run['title']:$run['name']).'</a></h4>
		'.$run['public_blurb_parsed'].'
	</div>
</div>';
  }
}
?>

	</div>
</div>
<?php
require_once INCLUDE_ROOT."View/footer.php";
