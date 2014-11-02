<?php
Template::load('header');
Template::load('acp_nav');
?>

<h2>email log <small>sent during runs</small></h2>

<?php

$email_nr = $fdb->prepare("SELECT COUNT(`survey_email_log`.id) AS count
FROM `survey_email_log`
LEFT JOIN `survey_unit_sessions`
ON `survey_unit_sessions`.id = `survey_email_log`.session_id
LEFT JOIN `survey_run_sessions`
ON `survey_unit_sessions`.run_session_id = `survey_run_sessions`.id
WHERE `survey_run_sessions`.run_id = :run_id");
$email_nr->bindValue(':run_id',$run->id);

$pagination = new Pagination($email_nr,50,true);
$limits = $pagination->getLimits();

$g_emails = $fdb->prepare("SELECT 
	`survey_email_accounts`.from_name, 
	`survey_email_accounts`.`from`, 
	`survey_email_log`.recipient AS `to`,
	`survey_emails`.subject,
	`survey_emails`.body,
	`survey_email_log`.created AS `sent`,
	`survey_run_units`.position AS position_in_run
	
	FROM `survey_email_log`
	
LEFT JOIN `survey_emails`
ON `survey_email_log`.email_id = `survey_emails`.id
LEFT JOIN `survey_run_units`
ON `survey_emails`.id = `survey_run_units`.unit_id
LEFT JOIN `survey_email_accounts`
ON `survey_emails`.account_id = `survey_email_accounts`.id
LEFT JOIN `survey_unit_sessions`
ON `survey_unit_sessions`.id = `survey_email_log`.session_id
LEFT JOIN `survey_run_sessions`
ON `survey_unit_sessions`.run_session_id = `survey_run_sessions`.id
WHERE `survey_run_sessions`.run_id = :run_id

ORDER BY `survey_email_log`.id DESC
LIMIT $limits
;");
$g_emails->bindValue(":run_id",$run->id);
$g_emails->execute();
$emails = array();
while($email = $g_emails->fetch(PDO::FETCH_ASSOC))
{
	$email['from'] = "{$email['from_name']}<br><small>{$email['from']}</small>";
	unset($email['from_name']);
	$email['to'] = $email['to']."<br><small>at run position ".$email['position_in_run']."</small>";
	$email['mail'] = $email['subject']."<br><small>". substr($email['body'],0,100). "…</small>";
	$email['sent'] = '<abbr title="'.$email['sent'].'">'.timetostr(strtotime($email['sent'])).'</abbr>';
	unset($email['position_in_run']);
	unset($email['subject']);
	unset($email['body']);
	$emails[] = $email;
}
session_over($site, $user);

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
$pagination->render("admin/run/".$run->name."/email_log");

} else {
	echo "No emails sent yet.";
}
	?>

<?php Template::load('footer');