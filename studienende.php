<?
require ('includes/header.php');
require ('includes/design.php');

$timestarted = $_GET['ts'];
$emails = $_GET['emails'] == "true";

if(isset($_POST["previous_study"])) { 
	$previous_study = $_POST["previous_study"];
} else {
	$previous_study = $_REQUEST["previous_study"];
}

$fake_row = array();
$fake_row["skipif"] = '{ "global": { "mode": "any_true", "1": { "skipif": "((Tage_nBeh_MC_Sw = 1) AND (Schw_nBeh_Sw = 7))", "mode": "any_true"}, "2": { "skipif": "((Tra01S_stu1 = 7) OR (Tra02S_stu1 = 7) OR (Tra03S_stu1 = 7) OR (Tra04S_stu1 = 7) OR (Tra05S_stu1 = 7) OR (Tra06S_stu1 = 7) OR (Tra07S_stu1 = 7) OR (Tra08S_stu1 = 7) OR (Tra09S_stu1 = 7) OR (Tra10S_stu1 = 7) OR (Tra11S_stu1 = 7) OR (Tra12S_stu1 = 7) OR (Tra13S_stu1 = 7) OR (Tra14S_stu1 = 7) OR (Tra15S_stu1 = 7) OR (Tra16S_stu1 = 7) OR (Tra17S_stu1 = 7) OR (Tra18S_stu1 = 7) OR (Tra19S_stu1 = 7) OR (Tra20S_stu1 = 7) OR (Tra21S_stu1 = 7) OR (Tra22S_stu1 = 7) OR (Tra23S_stu1 = 7) OR (Tra24S_stu1 = 7) OR (Tra25S_z_stu1 = 7) OR (Tra26S_z_stu1 = 7) OR (Tra27S_z_stu1 = 7) OR (Tra28S_z_stu1 = 7) OR (TraFreiS_stu1 = 7))", "mode": "any_true" } } } ';
$vexed = check_vpn_results($vpncode, $fake_row, $timestarted);
$vpndata = get_vpn_data($vpncode);
$study = $vpndata->study;

/* send emails if the GET variable is set to true */
if( $emails ) {
    $vpncode_email = $_POST['vpncode_email'];
    $partnercode_email = $_POST['partnercode_email'];
	if( (isset($vpncode_email) && $vpncode_email != "") && (!isset($partnercode_email) || $partnercode_email == "") ) {
		// update current users email dtails
		update_email($vpncode,$vpncode_email);
		send_invitation($vpncode);
	} elseif( (isset($vpncode_email) && $vpncode_email != "") && (isset($partnercode_email) && $partnercode_email != "") && $previous_study == "pretest" && ! $vexed) {
		// both form fields are set, previous study was pretest and person is not vexed
		// update current users email dtails
		update_email($vpncode,$vpncode_email);
		send_invitation($vpncode);
		// first generate a vpncode
		$partnercode = generate_vpncode();
		// make an entry for the current vpncode's partner and set it to right values 
		add_vpn($partnercode,$partnercode_email,"pretest",2);
		// associate partner to current user
		update_partnercode($partnercode,$vpncode);
		// now set the current vpncodes' partnercode to generated code so we make the association complete
		update_partnercode($vpncode,$partnercode);
		// finally, send invitation to both, 1st person and partner
		send_invitation($partnercode);
	} elseif( (isset($partnercode_email) && $partnercode_email != "") && (!isset($vpncode_email) || $vpncode_email == "") && $vexed ) {
		// so here we are, somebody was hurt a lot, gets to invite his buddy and lands him straight into posttest..
		// first generate a vpncode
		$partnercode = generate_vpncode();
		// make an entry for the current vpncode's partner and set it to right values
		add_vpn($partnercode,$partnercode_email,"posttest",2);
		// associate partner to current user
		update_partnercode($partnercode,$vpncode);
		// now set the current vpncodes' partnercode to generated code so we make the association complete
		update_partnercode($vpncode,$partnercode);
		// finally, send invitation to both, 1st person and partner
		send_invitation($partnercode);
	}
}

/* show feedback (or not) */
if( $previous_study != $study ) {

	switch( $previous_study ) {
		case "pretest":
			render_pretest_feedback($vpncode);
			break;
		case "study":
			render_study_feedback($vpncode, $vexed);
			break;
		case "posttest":
			if($vpndata->partnercode != "") {
				render_posttest_feedback($vpncode,$timestarted,true);
			} else {
				render_posttest_feedback($vpncode,null,false);
			}
			send_feedback_permalink($vpncode);
			break;
	}
} else {
	echo "Dies ist das Ende der heutigen Befragung. Bis morgen!";
}

echo "</div>\n";
require ('includes/navigation.php');
require('includes/footer.php');
?>
