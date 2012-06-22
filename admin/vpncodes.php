<?
require ('admin_header.php');
/* 	admin.php - Editor to add string subsitutions in $formulierung texts
June, 2010 - remember the great wheather!
 */

// Öffne Datenbank, mache ordentlichen Header, binde Stylesheets, Scripts ein
require ('includes/header.php');
// Endet mit </html>
require ('includes/design.php');
// macht das ganze Klickibunti, endet mit <div id="main"
//

if($_SERVER["REQUEST_METHOD"] == "POST") {
	foreach($_POST as $item => $value) {
		if( preg_match("/^email_/",$item,$matches,PREG_OFFSET_CAPTURE)) {
			$query = "UPDATE ".VPNDATATABLE." SET email='".$value."' WHERE id='".substr($item,strlen("email_"))."'";
			mysql_query($query) or die("something went wrong");
		}
	}
}

?>

<style type="text/css">
table#vpn td {
	padding: 10px;
}
table#vpn {
	margin-bottom: 50px;
}
</style>

<form method="POST" action="vpncodes.php">
<?
if( table_exists(VPNDATATABLE) ) {
	echo "<table id=\"vpn\" border=\"1\">";
	echo "<tr><th>ID</th><th>vpncode</th><th>partnercode</th><th>email</th><th>type</th><th>study</th></tr>";

	$query = "SELECT * FROM ".VPNDATATABLE;
	$results = mysql_query($query) or die( "something went wrong" );
	while( $row = mysql_fetch_assoc($results) ) {
		echo "<tr>";
		echo "<td>".$row['id']."</td>";
		echo "<td>".$row['vpncode']."</td>";
		echo "<td>".$row['partnercode']."</td>";
		echo "<td><textarea name=\"email_".$row['id']."\">".$row['email']."</textarea></td>";
		echo "<td>".$row['vpntype']."</td>";
		echo "<td>".$row['study']."</td>";
		echo "</tr>";
	}
	echo "</table>";
}
?>
<input type="submit" name="Save"/>
</form>

<?
// schließe main-div
echo "</div>\n";
// binde navigation ein
require ('includes/navigation.php');
// schließe datenbank-verbindung, füge bei bedarf analytics ein
require('includes/footer.php');
?>
