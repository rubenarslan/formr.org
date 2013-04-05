<?
require ('admin_header.php');
// Öffne Datenbank, mache ordentlichen Header, binde Stylesheets, Scripts ein
require ('includes/header.php');


//write new times to the database
if( ( isset($_POST['starthour']) and $_POST['starthour'] != "" ) AND 
    ( isset($_POST['endhour']) and $_POST['endhour'] != "" ) ) {

	$starttime = ((($_POST['starthour'] * 60) + $_POST['startminute']) * 60 ) + $_POST['startseconds'];
	$endtime = ((($_POST['endhour'] * 60) + $_POST['endminute']) * 60) + $_POST['endseconds'];
	
	if( !time_exists($starttime,$endtime) ){ /* if these times don't yet exist already inthe database */
		$query = "INSERT INTO ".TIMESTABLE." SET starttime=".$starttime.",endtime=".$endtime.";";
		
		mysql_query($query) or die( mysql_error() );
	} else {
		echo "<p style=\"background: #ff0000;\">Time exists already or overlaps with another time!</p>";
	}
}

if( isset($_POST['deleteselected']) ) {
	foreach($_POST['edittimes'] as $item) {
		$query = "DELETE FROM ".TIMESTABLE." WHERE id=".$item.";";
		mysql_query($query) or die( mysql_error() );
	}
}

createtimestable();             /* won't create unless the table doesn't exist */

$query = "SELECT * FROM ".TIMESTABLE." ORDER BY starttime ASC";
$results = mysql_query($query) or die( mysql_error() );

if( mysql_numrows($results) > 0 ) {
	echo "<p style=\"background: #CCCCCC;\"><strong>Navigation</strong></p>";
	echo "<form method=\"POST\" action=\"index.php\"><input type=\"submit\" value=\"Back to main menu\" /></form>";

	echo "<p style=\"background: #CCCCCC;\"><strong>Edit-Times</strong></p>";
	echo "<form method=\"POST\" action=\"edittimes.php\"><table class=\"editstudies\">";
	echo "<th>Select</th> <th> - </th> <th> Start-Time</th> <th>End-Time</th>";
	while( $result = mysql_fetch_assoc( $results ) ) {
		echo "<tr><td><input type=\"checkbox\" name=\"edittimes[]\" value=\"".$result['id']."\"/></td>";
		echo "<td></td>";
		
		echo "<td>" . date("H.i.s",strtotime( $result['starttime'] . " seconds today")) . " Uhr</td>";
		echo "<td>" . date("H.i.s",strtotime( $result['endtime'] . " seconds today")) . " Uhr</td></tr>";
	}
	echo "</table><input type=\"submit\" name=\"deleteselected\" value=\"Delete Selected\" /></form>";

	render_add_new_time();

} else {
	echo "<p style=\"background: #CCCCCC;\"><strong>Navigation</strong></p>";
	echo "<form method=\"POST\" action=\"index.php\"><input type=\"submit\" value=\"Back to main menu\" /></form>";

	render_add_new_time(); 

}



// schließe main-div
echo "</div>\n";

// schließe Datenbank-Verbindung, füge bei Bedarf Analytics ein
require('includes/footer.php');
?>
