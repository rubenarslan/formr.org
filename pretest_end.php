<?
require ('header.php');
global $study;
global $run;

require ('includes/header.php');
// ends with </html>
require ('includes/design.php');
// ends with <div id="main"


/* $study = get_study_by_vpn($vpncode); // returns string */
$vpndata = get_vpn_data($vpncode); // returns an object
$timestarted = $_GET['ts'];

/*  hardcoded nightmare */
/* $query = "UPDATE ".RESULTSTABLE." SET  */
/* 	`END_pretest`='1' */
/* 	WHERE vpncode='$vpncode' AND study='pretest'"; */
/* mysql_query($query) or die ("Fehler bei " . $query . mysql_error() . "<br>"); */

//todo
if(study_part_completed($vpncode,'pretest')) { 
	/* update_timestamps($vpncode,$study,$timestarted); */
	update_timestamps($vpncode,$timestarted);
	// update study field in vpndata table and send/queue emails if needed
	/* post_study_hook($vpncode,$study); */
	post_study_hook($vpncode);
	// garbage collection
	remove_stale_itemsdisplayed($vpncode,$timestarted);
	}
render_form_header($vpncode,$timestarted);
?>
<tr class="bottomsubmit">
	<td colspan="2">
 <p>Vielen Dank für das Ausfüllen des Fragebogens. Sie können nun mit dem Tagebuch fortfahren.</p>
	</td>
</tr>
<tr class="bottomsubmit">
	<td>
		<input type="submit" name="abschicken" value="Weiter"/>
	</td>
</tr>

<?php
render_form_footer();

// schließe table
echo "</table>\n";
// schließe main-div
echo "</div>\n";
// binde Navigation ein
require ('includes/navigation.php');
// schließe Datenbank-Verbindung, füge bei Bedarf Analytics ein
require('includes/footer.php');
?>