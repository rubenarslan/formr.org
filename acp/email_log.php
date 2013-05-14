<?php
require_once '../define_root.php';
require_once INCLUDE_ROOT . "admin/admin_header.php";
require_once INCLUDE_ROOT . "view_header.php";
require_once INCLUDE_ROOT . "acp/acp_nav.php";
?>	
<h2>email log <small>sent during runs</small></h2>

<?php
$g_emails = $fdb->query("SELECT 
	`survey_email_accounts`.from_name AS `from`, 
	`survey_emails`.subject,
	`survey_email_log`.created,
	`survey_email_log`.recipient FROM `survey_email_log`
	
	LEFT JOIN `survey_emails`
ON `survey_email_log`.email_id = `survey_emails`.id
LEFT JOIN `survey_email_accounts`
ON `survey_emails`.account_id = `survey_email_accounts`.id
;");

$emails = array();
while($email = $g_emails->fetch(PDO::FETCH_ASSOC))
	$emails[] = $email;

if(!empty($emails)) {
	?>
	<table class='table table-striped'>
		<thead><tr>
	<?php
	foreach(current($results) AS $field => $value):
	    echo "<th>{$field}</th>";
	endforeach;
	?>
		</tr></thead>
	<tbody>
		<?php
		// printing table rows
		foreach($results AS $row):
		    echo "<tr>";

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

<?php
require_once INCLUDE_ROOT . "view_footer.php";