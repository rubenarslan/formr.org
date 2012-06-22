<?php
require ('includes/header.php');
// endet mit </html>
require ('includes/design.php');
// endet mit <div id="main"

$timestarted = $_GET['ts'];
$vpndata = get_vpn_data($vpncode); // returns an object
$study = $vpndata->study;

$fake_row = array();
$fake_row["skipif"] = '{ "global": { "mode": "any_true", "1": { "skipif": "((Tage_nBeh_MC_Sw = 1) AND (Schw_nBeh_Sw = 7))", "mode": "any_true" } } }';
$vexed = check_vpn_results( $vpncode, $fake_row, $timestarted);

if($study != "pretest" and $vpndata->partnercode != "") {
	$partner=true;
	$partner_vpn = $vpndata->partnercode;
	$partner_data = get_vpn_data($partner_vpn);
	$partner_study = get_study_by_vpn($partner_vpn);
	$partner_vexed = check_vpn_results($partner_vpn,"((Tage_nBeh_MC_Sw = 1) AND (Schw_nBeh_Sw = 7))");
} else {
	$partner=false;
}

switch ( $study ) {
	case "pretest":
		echo "<img src=\"includes/single_person_feedback.php?vpncode=$vpncode&data=$study&partner=false\"/>";
		break;
	case "study":
		if( !$vexed ) {
			echo "<img src=\"includes/single_person_feedback.php?vpncode=$vpncode&data=$study&partner=false\"/>";
			if($partner == true) {
				echo "<img src=\"includes/single_person_feedback.php?vpncode=$partner_vpn&data=$partner_study&partner=true\"/>";
			}
		} else {
			echo "<img src=\"includes/single_person_feedback.php?vpncode=$vpncode&data=$study&partner=false\"/>";
		}
		break;
	case "posttest":
		echo "<img src=\"includes/single_person_feedback.php?vpncode=$vpncode&data=$study&partner=false\"/>";
		if($partner == true) {
			echo "<img src=\"includes/single_person_feedback.php?vpncode=$partner_vpn&data=$partner_study&partner=true\"/>";
		}
		break;
}

?>

<form method="post" action="emails.php?ts=<?echo $_GET['ts'];?>&vpncode=<?echo $vpncode;?>">
	<input type="hidden" value="<?php echo $vpncode;?>" name="vpncode" />
	<input type="submit" value="Weiter" name="Weiter"/>
</form>

<?
// schließe main-div
echo "</div>\n";
// binde Navigation ein
require ('includes/navigation.php');
// schließe Datenbank-Verbindung, füge bei Bedarf Analytics ein
require('includes/footer.php');
?>
