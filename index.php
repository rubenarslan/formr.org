<?php
require_once 'define_root.php';

require_once INCLUDE_ROOT."config/config.php";
require_once INCLUDE_ROOT."view_header.php";

$studies = $currentUser->GetAvailableStudies();
$runs = $currentUser->GetAvailableRuns();

require_once INCLUDE_ROOT."public_nav.php";


if($studies or $runs) {
  echo "<h3>Aktuelle Studien:</h3>";
  echo "<ul class='span4 nav nav-pills nav-stacked'>";
}
if($runs) {
  foreach($runs as $run) {
    if($currentUser->anonymous and $run->registered_req)
      break;
    echo "<li>
		<a href='".WEBROOT."{$run->name}/survey'>".$run->name."</a>
	</li>";
  }
}
if($studies) {
  foreach($studies as $study) {
    if($currentUser->anonymous and $study->registered_req)
      break;
    echo "<li>
		<a href='".WEBROOT."{$study->name}/survey'>".$study->name."</a>
	</li>";
  }
}
if($studies or $runs)
  echo "</ul>";

require_once INCLUDE_ROOT."view_footer.php";
