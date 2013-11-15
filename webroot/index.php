<?php
require_once '../define_root.php';

require_once INCLUDE_ROOT."Model/Site.php";
require_once INCLUDE_ROOT."View/header.php";

$runs = $user->getAvailableRuns();

require_once INCLUDE_ROOT."View/public_nav.php";

if($runs) {
?>
<div class="row">
	<div class="col-lg-4 col-lg-offset-1 col-sm-5 col-sm-offset-1 col-xs-12 well">
		<h3>Aktuelle Studien:</h3>
		<ul class='span4 nav nav-pills nav-stacked'>

<?php
  foreach($runs as $run) {
    echo "<li>
		<a href='".WEBROOT."{$run['name']}'>".$run['name']."</a>
	</li>";
  }
  echo "</ul>";
}
?>

	</div>
</div>
<?php
require_once INCLUDE_ROOT."View/footer.php";
