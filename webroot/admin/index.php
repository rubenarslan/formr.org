<?php
require_once '../../define_root.php';
require_once INCLUDE_ROOT . "View/admin_header.php";
require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/acp_nav.php";
?>
<h2>runs &amp; studies <small>what's the difference?</small></h2>
<p>
	<strong>Studies</strong> are meant to be a simple survey that can be completed
in one session (though it can reference fields in other surveys for skipif and substitution logic).<br>
The only way a survey can be accessed is through a session key (which allows
exactly one session). These keys can be created by another applications (via API), a user, by runs.

<p>
	<strong>Runs</strong> on the other hand can manage access to studies, combine them with other studies or external pages (e.g. social network), send emails to re-invite someone after a break. They will also be able to loop studies (for diaries or experience sampling). Runs have users, so they can allow access to certain studies based on whether someone has registered with an email or they can let the same user fill out the same study repeatedly (diary loops).
</p>

<?php
$studies = $user->getStudies();
if($studies) {
  echo '
	  <div class="span5">
	  <h3>Studies</h3>
	  <ul class="nav nav-pills nav-stacked">';
  foreach($studies as $study) {
    echo "<li>
		<a href='".WEBROOT."admin/survey/".$study['name']."/'>".$study['name']."</a>
	</li>";
  }
  echo "</ul></div>";
}
?>
<?php
$runs = $user->getRuns();
if($runs) {
	echo '
  	  <div class="span5">
		<h3>Runs</h3>
		<ul class="nav nav-pills nav-stacked">';
	foreach($runs as $menu_run) {
		echo "<li>
			<a href='".WEBROOT."admin/run/{$menu_run['name']}/'>{$menu_run['name']}</a>
		</li>";
	}
  echo "</ul></div>";
}
?>
	
<?php
require_once INCLUDE_ROOT . "View/footer.php";