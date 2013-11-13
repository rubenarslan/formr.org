<?php
require_once '../../define_root.php';
require_once INCLUDE_ROOT . "View/admin_header.php";
require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/acp_nav.php";
?>
<div class="col-md-5">
	<h2>runs &amp; surveys <small>what's the difference?</small></h2>
	<p>
		<strong>Surveys</strong> are meant to be simple. They can be completed
	in one session (though they can reference and use information gathered in other surveys). Surveys are never accessible on their own, they are always part of a (sometimes simple) run. They are created through spreadsheets containing survey items.
	<p>
		<strong>Runs</strong> allow you to chain simple surveys, together with other modules: emails, branches, pauses, etc. You can make simple one-shot surveys with a pretty R-generated feedback plot at the end. You could use them for looped surveys: training studies, diaries or experience sampling. You can use them to permit access only to users who fulfill certain criteria.
	</p>

	<?php
	$studies = $user->getStudies();
	if($studies) {
	  echo '
		  <div class="col-md-6">
		  <h3>Surveys</h3>
		  <ul class="fa-ul">';
	  foreach($studies as $study) {
	    echo "<li>
			<a href='".WEBROOT."admin/survey/".$study['name']."/'>
		<i class='fa fa-pencil-square fa-fw'></i>
		".$study['name']."</a>
		</li>";
	  }
	  echo "</ul></div>";
	}
	?>
	<?php
	$runs = $user->getRuns();
	if($runs) {
		echo '
	  	  <div class="col-md-6">
			<h3>Runs</h3>
			<ul class="fa-ul">';
		foreach($runs as $menu_run) {
			echo "<li>
				<a href='".WEBROOT."admin/run/{$menu_run['name']}/'>
			<i class='fa fa-rocket fa-fw'></i>
			{$menu_run['name']}</a>
			</li>";
		}
	  echo "</ul></div>";
	}
	?>
</div>
<?php
require_once INCLUDE_ROOT . "View/footer.php";