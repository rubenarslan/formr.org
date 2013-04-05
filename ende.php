<?
// Öffne Datenbank, mache ordentlichen Header, binde Stylesheets, Scripts ein
require ('includes/header.php');
?>

<?
$time = date("Y.m.d - H.i.s");
$unixtime = time();

$study = get_study_by_vpn($vpncode); // returns string

$timestarted = $_GET['ts'];

if(study_part_completed($vpncode,'review')) { ## only update all that if he actually finished that part (and not another one "backbutton" refresh problems)
	update_timestamps($vpncode,$study,$timestarted);
	// update study field in vpndata table and send/queue emails if needed
	post_study_hook($vpncode,$study);
	// garbage collection
	remove_stale_itemsdisplayed($vpncode,$timestarted);
}
    echo "<table width=\"" . SRVYTBLWIDTH . "\">";
    echo "<tr class=\"bottomsubmit\"><td id=\"bottomsubmit\" colspan=\"2\">Vielen Dank für Ihre Teilnahme!</td></tr>";
#
#    update_timestamps($vpncode,$study,$timestarted);
#    // update study field in vpndata table and send/queue emails if needed
#    post_study_hook($vpncode,$study);
#    // garbage collection
#    remove_stale_itemsdisplayed($vpncode);

?>

</table>

<?
// schließe main-div
echo "</div>\n";
// schließe Datenbank-Verbindung, füge bei Bedarf Analytics ein
require('includes/footer.php');
?>