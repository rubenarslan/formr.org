<?
// Öffne Datenbank, mache ordentlichen Header, binde Stylesheets, Scripts ein
require ('includes/header.php');



$time = date("Y.m.d - H.i.s");
$unixtime = time();

$study = get_study_by_vpn($vpncode); // returns string

$timestarted = $_GET['ts'];


    echo "<table width=\"" . SRVYTBLWIDTH . "\">";
    echo "<tr class=\"bottomsubmit\"><td id=\"bottomsubmit\" colspan=\"2\">Vielen Dank für Ihre heutige Teilnahme!</td></tr>";


    $query = "SELECT iteration FROM selfinsight_results WHERE study = '$study' AND vpncode='$vpncode' ORDER BY iteration DESC";

    //wenn studie in der user ist loop ist check wie viele iterationen der schon gemacht und entscheide dementsprechend ob er weiter kommt oder nicht
    $results = mysql_query($query) or die( exception_handler(mysql_error() . "<br/>" . $query . "<br/> in post_study_hook" ));
    $row = mysql_fetch_assoc($results);

if(study_part_completed($vpncode,$study,$row['iteration'])) { ## only update all that if he actually finished that part (and not another one "backbutton" refresh problems)
	update_timestamps($vpncode,$study,$timestarted);
	// update study field in vpndata table and send/queue emails if needed
	post_study_hook($vpncode,$study);
	// garbage collection
	remove_stale_itemsdisplayed($vpncode,$timestarted);
	}

#if(get_study_by_vpn($vpncode)=="review") {
#echo "Vorletzter Tag.";
#}
if(false) {

	render_form_header($vpncode,$timestarted);
	?>
	<tr class="bottomsubmit">
		<td colspan="2">
	 <p>Vielen Dank für das Ausfüllen des Tagebuchs. Sie können nun mit dem Endabschnitt fortfahren.</p>
		</td>
	</tr>
	<tr class="bottomsubmit">
		<td>
			<input type="submit" name="abschicken" value="Weiter"/>
		</td>
	</tr>

	<?php
	render_form_footer(); 
}
	?>
</table>

<?
// schließe main-div
echo "</div>\n";
// schließe Datenbank-Verbindung, füge bei Bedarf Analytics ein
require('includes/footer.php');
?>