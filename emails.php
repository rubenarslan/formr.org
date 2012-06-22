<?
require ('includes/header.php');
// ends with </html>
require ('includes/design.php');
// ends with <div id="main"

$timestarted = $_GET['ts'];
$vpndata = get_vpn_data($vpncode); // returns an object
$study = $vpndata->study;

/* first we finish cleaning up everything */
update_timestamps($vpncode,$study,$timestarted);
// update study field in vpndata table and send/queue emails if needed
post_study_hook($vpncode,$study);
// garbage collection, don't keep any of that stuff around
remove_stale_itemsdisplayed($vpncode,time());

$fake_row = array();
$fake_row["skipif"] = '{ "global": { "mode": "any_true", "1": { "skipif": "((Tage_nBeh_MC_Sw = 1) AND (Schw_nBeh_Sw = 7))", "mode": "any_true" } } }';

$previous_study = $study;
$vpndata = get_vpn_data($vpncode); // returns an object
$study = $vpndata->study;
$vexed = check_vpn_results( $vpncode, $fake_row, $timestarted);

// if current vpncode is a number 2 or person A has been hurt a lot
if( $vexed && $previous_study == "pretest" && $study != $previous_study) {
	if( $vpndata->vpntype == 2 ) {
		//redirect to end-of-internet
		redirect_to("studienende.php?vpncode=$vpncode&ts=$timestarted");
	} else {	
		// vpncode is vexed, 
		echo "<div class='emails secondary-color'>";
		echo "<div class='email-text'>Bitte geben sie jetzt ihre eigene Email ein.</div>";

		echo "<form method='post' action='studienende.php?emails=true&ts=$timestarted'>";
		echo "<input type='hidden' name='vpncode' value='$vpncode' />";
		echo "<input type='hidden' name='previous_study' value='$previous_study'/>";

		echo "<div class='email-box'>";
		echo "<label for='vpncode_email'>Ihre eigene Email-Adresse:</label>";
		echo "<input id='vpncode_email' type='text' name='vpncode_email'></input>";
		echo "</div>";

		echo "<div class='email-box'>";
		echo "<input type='submit' name='abschicken' value='abschicken'/>";
		echo "</div>";

		echo "</form>";
		echo "</div>";
	}
} else if( !$vexed AND $previous_study == "pretest" && $study != $previous_study) {
		echo "<div class='emails secondary-color'>";
		echo "<div class='email-text'>Bitte geben sie jetzt ihre eigene, und die Email Adresse ihres Partners ein.</div>";

		echo "<form method='post' action='studienende.php?emails=true&ts=$timestarted'>";
		echo "<input type='hidden' name='vpncode' value='$vpncode' />";
		echo "<input type='hidden' name='previous_study' value='$previous_study'/>";

		echo "<div class='email-box'>";
		echo "<label for='vpncode_email'>Ihre eigene Email-Adresse:</label>";
		echo "<input id='vpncode_email' type='text' name='vpncode_email'></input>";
		echo "</div>";

		echo "<div class='email-box'>";
		echo "<label for='partnercode_email'>Die Email-Adresse ihres Partners:</label>";
		echo "<input id='partnercode_email' type='text' name='partnercode_email'></input>";
		echo "</div>";

		echo "<div>";
		echo "<input type='submit' name='abschicken' value='abschicken'/>";
		echo "</div>";

		echo "</form>";
		echo "</div>";
/*
 * CASE: coming out of main study (hence the $study variable points to posttest)
 * showing study feedback
 */
} elseif( $vexed && $previous_study == "study" && $study != $previous_study) {
	echo "<div class='emails secondary-color'>";
	echo "<div>Bitte geben sie jetzt die Email Adresse ihres Partners ein.</div>";

	echo "<form method='post' action='studienende.php?emails=true&ts=$timestarted'>";
	echo "<input type='hidden' name='vpncode' value='$vpncode' />";
	echo "<input type='hidden' name='previous_study' value='$previous_study'/>";

	echo "<div>";
	echo "<label for='partnercode_email'>Die Email-Adresse ihres Partners:</label>";
	echo "<input id='partnercode_email' type='text' name='partnercode_email'></input>";
	echo "</div>";

	echo "<div>";
	echo "<input type='submit' name='abschicken' value='abschicken'/>";
	echo "</div>";

	echo "</form>";
	echo "</div>";
} else {
	//redirect to end-of-internet
	redirect_to("studienende.php?vpncode=$vpncode&previous_study=$previous_study&emails=false&ts=$timestarted");
}


// schließe main-div
echo "</div>\n";
// binde Navigation ein
require ('includes/navigation.php');
// schließe Datenbank-Verbindung, füge bei Bedarf Analytics ein
require('includes/footer.php');
?>
