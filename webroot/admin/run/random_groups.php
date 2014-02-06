<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT . "View/admin_header.php";
require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/acp_nav.php";
?>
<div class="row">
	<div class="col-md-12">
		<h2>randomisation results</h2>
<?php
$g_users = $fdb->prepare("SELECT 
	`survey_run_sessions`.session,
	`survey_unit_sessions`.id AS session_id,
	`survey_runs`.name AS run_name,
	`survey_run_units`.position,
	`survey_units`.type AS unit_type,
	`survey_unit_sessions`.created,
	`survey_unit_sessions`.ended,
	`survey_users`.email,
	`shuffle`.group
	
	
FROM `survey_unit_sessions`

LEFT JOIN `shuffle`
ON `shuffle`.session_id = `survey_unit_sessions`.id
LEFT JOIN `survey_run_sessions`
ON `survey_run_sessions`.id = `survey_unit_sessions`.run_session_id
LEFT JOIN `survey_users`
ON `survey_users`.id = `survey_run_sessions`.user_id
LEFT JOIN `survey_units`
ON `survey_unit_sessions`.unit_id = `survey_units`.id
LEFT JOIN `survey_run_units`
ON `survey_unit_sessions`.unit_id = `survey_run_units`.unit_id
LEFT JOIN `survey_runs`
ON `survey_runs`.id = `survey_run_units`.run_id
WHERE `survey_runs`.name = :run_name AND
`survey_units`.type = 'Shuffle'
ORDER BY `survey_run_sessions`.id DESC,`survey_unit_sessions`.id ASC;");
$g_users->bindParam(':run_name',$run->name);
$g_users->execute();

$users = array();
while($userx = $g_users->fetch(PDO::FETCH_ASSOC))
{
	$userx['Unit in Run'] = $userx['unit_type']. " <span class='hastooltip' title='position in run {$userx['run_name']} '>({$userx['position']})</span>";
	$userx['Email'] = "<small title=\"{$userx['session']}\">{$userx['email']}</small>";
	$userx['Group'] = "<big title=\"Assigned group\">{$userx['group']}</small>";
	$userx['Created'] = "<small>{$userx['created']}</small>";
	
	unset($userx['run_name']);
	unset($userx['unit_type']);
	unset($userx['created']);
	unset($userx['ended']);
	unset($userx['position']);
	unset($userx['email']);
	unset($userx['group']);
#	$user['body'] = "<small title=\"{$user['body']}\">". substr($user['body'],0,50). "…</small>";
	
	$users[] = $userx;
}
if(!empty($users)) {
	?>
	
	<ul class="nav nav-pills nav-stacked span6">
		<li class="nav-header">
			Export results as
		</li>
		<li>
			<a href="<?=WEBROOT?>admin/run/<?=$run->name?>/random_groups_export"><i class="fa fa-floppy-o fa-fw"></i> CSV – good for big files, problematic to import into German Excel (comma-separated)</a>
		</li>

	</ul>
	<table class='table'>
		<thead><tr>
	<?php
	foreach(current($users) AS $field => $value):
	    echo "<th>{$field}</th>";
	endforeach;
	?>
		</tr></thead>
	<tbody>
		<?php
		$last_user = '';
		$tr_class = '';
		
		// printing table rows
		foreach($users AS $row):
			if($row['Email']!==$last_user):
				$tr_class = ($tr_class=='') ? 'alternate' : '';
				$last_user = $row['Email'];
			endif;
			echo '<tr class="'.$tr_class.'">';

		    // $row is array... foreach( .. ) puts every element
		    // of $row to $cell variable
		    foreach($row as $cell):
		        echo "<td>$cell</td>";
			endforeach;

		    echo "</tr>\n";
		endforeach;
	}
		?>

	</tbody></table>
	</div>
</div>

<?php
require_once INCLUDE_ROOT . "View/footer.php";