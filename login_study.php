<?php
// Öffne Datenbank, mache ordentlichen Header, binde Stylesheets, Scripts ein
require ('includes/header.php');
?>

<?php
// session_start();
date_default_timezone_set('Europe/Berlin');
$time = date("Y.m.d - H.i.s");
$studientitel = TITLE;
if (isset($_REQUEST["goto"]) AND $_REQUEST["goto"]!="") {
	$goto=mysql_real_escape_string($_REQUEST["goto"]);
} else {
	$goto="survey.php";
}

echo "<div class='secondary-color welcome'>";

if (USERPOOL=="limited") {

	echo "<div class='center'>";
	echo "<form action=\"" . $goto . "\" method=\"post\" name=\"Anmeldung\">";
	echo "Ihr Code:<input type=\"text\" value=\"" . getvpncode() . " name=\"vpncode\" />";
	echo "<input type=\"hidden\" value=\"$time\" name=\"begansurveysmsintvar\" />";
	echo "<div><input class=\"button\" type=submit value=\"Weiter zur Studie\" /></div>";
	echo "</form>";
	echo "</div>";

} elseif (USERPOOL=="open") {
    echo "<form action='$goto' method='post' name='Anmeldung'>";
        echo WELCOME;
        echo "<input type='hidden' value='$time' name='begansurveysmsintvar' />";
        echo "<input type='hidden' value='" . generate_vpncode() . "' name='vpncode' />";
        echo "<div><input class='button' type='submit' value='Weiter zur Studie' /></div>";
    echo "</form>";

} else {
	echo "Bitte folgen Sie dem Link aus Ihrer Übersichts-Seite oder dem in Ihren Emails, um das Tagebuch auszufüllen.";
}

echo "</div>";
// schließe main-div
echo "</div>";
// schließe Datenbank-Verbindung, füge bei Bedarf Analytics ein
require('includes/footer.php');
?>
