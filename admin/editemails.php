<?
require ('admin_header.php');
// Öffne Datenbank, mache ordentlichen Header, binde Stylesheets, Scripts ein
require ('includes/header.php');
// Endet mit </html>
require ('includes/design.php');
// macht das ganze Klickibunti, endet mit <div id="main"

//write new times to the database
if( ( isset($_POST['addemailname']) AND $_POST['addemailname'] != "" ) AND 
	( isset($_POST['addemailsubject']) AND $_POST['addemailsubject'] != "" ) AND 
	( isset($_POST['addemailbody']) AND $_POST['addemailbody'] != "" ) AND 
	( isset($_POST['deltaday']) AND $_POST['deltaday'] != "" ) AND 
	( isset($_POST['abshour']) AND $_POST['abshour'] != "" ) AND 
	( isset($_POST['absminute']) AND $_POST['absminute'] != "" )) {

	$deltatime = $_POST['deltaday'] * 60 * 60 * 24;
	$abstime = ($_POST['abshour'] * 60 * 60) + ($_POST['absminute'] * 60);

	$query = "INSERT INTO ".EMAILSTABLE." SET name='".$_POST['addemailname']."',subject='".mysql_real_escape_string($_POST['addemailsubject'])."',body='".mysql_real_escape_string($_POST['addemailbody'])."',delta='".$deltatime."',abstime='".$abstime."';";
		
	mysql_query($query) or die( mysql_error() );
}

if( isset($_POST['deleteselected']) ) {
	foreach($_POST['edittimes'] as $item) {
		$query = "DELETE FROM ".EMAILSTABLE." WHERE id=".$item.";";
		mysql_query($query) or die( mysql_error() );
	}
}

createemailtables();             /* won't create unless the table doesn't exist */

$query = "SELECT * FROM ".EMAILSTABLE." ORDER BY id ASC";
$results = mysql_query($query) or die( mysql_error() );

if( mysql_numrows($results) > 0 ) {
	echo "<p style=\"background: #CCCCCC;\"><strong>Navigation</strong></p>";
	echo "<form method=\"POST\" action=\"index.php\"><input type=\"submit\" value=\"Back to main menu\" /></form>";

	echo "<p style=\"background: #CCCCCC;\"><strong>Edit Emails</strong></p>";
	echo "<form method=\"POST\" action=\"editemails.php\"><table class=\"editstudies\">";
	echo "<th>Select</th> <th> ID </th> <th> Name </th> <th>Time of Day</th> <th> DeltaTime </th> <th> Subject </th> <th> Body </th>";
	while( $result = mysql_fetch_assoc( $results ) ) {
		echo "<tr><td><input type=\"checkbox\" name=\"edittimes[]\" value=\"".$result['id']."\"/></td>";
		echo "<td>".$result['id']."</td>";
		echo "<td><input type='text' size='10' maxlength='30' readonly='readonly' value='".$result['name']."'/></td>";

		$hours = sprintf("%02d",(($result['abstime'] - ($result['abstime'] % 3600)) / 3600));
		$minutes = sprintf("%02d",((($result['abstime'] % 3600) - (($result['abstime'] % 3600) % 60)) / 60));

		echo "<td>" . $hours . ":" . $minutes . "</td>";

		$days = (($result['delta'] - ($result['delta'] % 86400)) / 86400);

		echo "<td>" . $days . "</td>";
		echo "<td><input type=\"text\" size=\"35\" maxlength=\"50\" readonly='readonly'  value=\"".$result['subject']."\"></input></td>";
		echo "<td><textarea cols=\"28\" rows=\"10\" readonly='readonly' >".$result['body']."</textarea></td></tr>";
	}
	echo "</table><input type=\"submit\" name=\"deleteselected\" value=\"Delete Selected\" /></form>";

	render_add_new_email();
} else {
	echo "<p style=\"background: #CCCCCC;\"><strong>Navigation</strong></p>";
	echo "<form method=\"POST\" action=\"index.php\"><input type=\"submit\" value=\"Back to main menu\" /></form>";

	render_add_new_email(); 
}



// schließe main-div
echo "</div>\n";
// binde Navigation ein
require ('includes/navigation.php');
// schließe Datenbank-Verbindung, füge bei Bedarf Analytics ein
require('includes/footer.php');
?>
