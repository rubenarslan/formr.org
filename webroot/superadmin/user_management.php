<?php
require_once '../../define_root.php';
require_once INCLUDE_ROOT . "View/admin_header.php";
$js = '<script src="'.WEBROOT.'assets/run_users.js"></script>';
require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/acp_nav.php";
require_once INCLUDE_ROOT . "Model/Pagination.php";
?>	
<h2>formr users</h2>
<?php
$user_count= $fdb->prepare("SELECT COUNT(id) AS count
FROM `survey_users`");

$pagination = new Pagination($user_count, 200, true);
$limits = $pagination->getLimits();

$g_users = $fdb->prepare("SELECT 
	`survey_users`.id,
	`survey_users`.created,
	`survey_users`.modified,
	`survey_users`.email,
	`survey_users`.admin,
	`survey_users`.email_verified
	
FROM `survey_users`

ORDER BY `survey_users`.id ASC 
LIMIT $limits;");
$g_users->execute();

$users = array();
while($userx = $g_users->fetch(PDO::FETCH_ASSOC))
{
	$userx['Email'] = '<a href="'.h($userx['email']).'">'.h($userx['email']).'</a>' . ($userx['email_verified'] ? " <i class='fa fa-check-circle-o'></i>":" <i class='fa fa-envelope-o'></i>");
	$userx['Created'] = "<small class='hastooltip' title='{$userx['created']}'>".timetostr(strtotime($userx['created']))."</small>";
	$userx['Modified'] = "<small class='hastooltip' title='{$userx['modified']}'>".timetostr(strtotime($userx['modified']))."</small>";
	$userx['Admin'] = "
		<form class='form-inline form-ajax' action='".WEBROOT."superadmin/ajax_admin?user_id={$userx['id']}' method='post'>
		<span class='input-group' style='width:160px'>
			<span class='input-group-btn'>
				<button type='submit' class='btn hastooltip'
				title='Give this level to this user'><i class='fa fa-hand-o-right'></i></button>
			</span>
			<input type='number' name='admin_level' max='100' min='-1' value='".h($userx['admin'])."' class='form-control'>
		</span>
	</form>";
	

	unset($userx['email']);
	unset($userx['created']);
	unset($userx['modified']);
	unset($userx['admin']);
	unset($userx['id']);
	unset($userx['email_verified']);
#	$user['body'] = "<small title=\"{$user['body']}\">". substr($user['body'],0,50). "…</small>";

	$users[] = $userx;
}

session_over($site, $user);
?>

	<?php
	
	if(!empty($users)):
		?>
		<table class='table table-striped'>
			<thead><tr>
		<?php
		foreach(current($users) AS $field => $value):
			    echo "<th>{$field}</th>";
		endforeach;
		?>
			</tr></thead>
		<tbody>
			<?php
			// printing table rows
			foreach($users AS $row):
			    // $row is array... foreach( .. ) puts every element
			    // of $row to $cell variable
			    foreach($row as $cell):
			        echo "<td>$cell</td>";
				endforeach;

			    echo "</tr>\n";
			endforeach;
			?>
		</tbody></table>
	<?php
	$pagination->render("superadmin/user_management");
	
	endif;
	?>
	</div>
</div>