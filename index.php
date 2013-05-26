<?php
require_once 'define_root.php';

require_once INCLUDE_ROOT."Model/Site.php";
require_once INCLUDE_ROOT."view_header.php";

$runs = $user->getAvailableRuns();

require_once INCLUDE_ROOT."public_nav.php";

if($runs) {
  echo "<h3>Aktuelle Studien:</h3>";
  echo "<ul class='span4 nav nav-pills nav-stacked'>";
  foreach($runs as $run) {
    echo "<li>
		<a href='".WEBROOT."{$run['name']}'>".$run['name']."</a>
	</li>";
  }
  echo "</ul>";
}

require_once INCLUDE_ROOT."view_footer.php";
