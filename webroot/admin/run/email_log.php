<?php
require_once '../../../define_root.php';
require_once INCLUDE_ROOT . "View/admin_header.php";
require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/acp_nav.php";
?>	
<h2>email log <small>sent during runs</small></h2>

<?php
$g_emails = $fdb->prepare("SELECT 
	`survey_email_accounts`.from_name, 
	`survey_email_accounts`.`from`, 
	`survey_emails`.subject,
	`survey_emails`.body,
	`survey_email_log`.created,
	`survey_email_log`.recipient FROM `survey_email_log`
	
	LEFT JOIN `survey_emails`
ON `survey_email_log`.email_id = `survey_emails`.id
LEFT JOIN `survey_email_accounts`
ON `survey_emails`.account_id = `survey_email_accounts`.id
LEFT JOIN `survey_unit_sessions`
ON `survey_unit_sessions`.id = `survey_email_log`.session_id
LEFT JOIN `survey_run_sessions`
ON `survey_unit_sessions`.run_session_id = `survey_run_sessions`.id
WHERE `survey_run_sessions`.run_id = :run_id
;");
$g_emails->bindValue(":run_id",$run->id);
$g_emails->execute();
$emails = array();
while($email = $g_emails->fetch(PDO::FETCH_ASSOC))
{
	$email['from'] = "{$email['from_name']}<br><small>{$email['from']}</small>";
	unset($email['from_name']);
	$email['body'] = "<small title=\"{$email['body']}\">". substr($email['body'],0,50). "…</small>";
	
	$emails[] = $email;
}
if(!empty($emails)) {
	?>
	<table class='table table-striped'>
		<thead><tr>
	<?php
	foreach(current($emails) AS $field => $value):
	    echo "<th>{$field}</th>";
	endforeach;
	?>
		</tr></thead>
	<tbody>
		<?php
		// printing table rows
		foreach($emails AS $row):
		    echo "<tr>";

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
} else {
	echo "No emails sent yet.";
}
	?>

<?php
require_once INCLUDE_ROOT . "View/footer.php";