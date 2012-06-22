<?
require ('admin_header.php');
/* 	editsubsitions.php - Editor to add string subsitutions in $formulierung texts
	June, 2010 - remember the great wheather!
*/

// Öffne Datenbank, mache ordentlichen Header, binde Stylesheets, Scripts ein
require ('includes/header.php');
// Endet mit </html>
require ('includes/design.php');
// macht das ganze Klickibunti, endet mit <div id="main"


if(
	// ruben removed mode requirement because NULL value means "most recent"
    ( isset($_POST['addsubstkey']) and $_POST['addsubstkey'] != "" ) AND 
	( isset($_POST['addsubstvalue']) and $_POST['addsubstvalue'] != "" ) ) {
	
	if(!isset($_POST['addsubstmode']) or $_POST['addsubstmode'] == "" ) $_POST['addsubstmode'] = "NULL";
	
	$query = "INSERT INTO ".SUBSTABLE." (`id`,`mode`,`key`,`value`) VALUES  (NULL,".$_POST['addsubstmode'].",'".$_POST['addsubstkey']."','".$_POST['addsubstvalue']."');";
	mysql_query($query) or die( mysql_error() );
}

if( isset($_POST['deleteselected']) ) {
	foreach($_POST['editsubsts'] as $item) {
		$query = "DELETE FROM ". SUBSTABLE." WHERE id=".$item.";";
		mysql_query($query) or die( mysql_error() );
	}
}

createsubstable();             /* won't create unless the table doesn't exist */

$query = "SELECT * FROM ".SUBSTABLE;
$results = mysql_query($query) or die( mysql_error() );

if( mysql_numrows($results) > 0 ) {
	echo "<p style=\"background: #CCCCCC;\"><strong>Navigation</strong></p>";
	echo "<form method=\"POST\" action=\"index.php\"><input type=\"submit\" value=\"Back to main menu\" /></form>";

	echo "<p style=\"background: #CCCCCC;\"><strong>Edit Substitutions</strong><br>
	<b>Iterations:</b> Leave empty for _most recent_ day or non-repeated surveys like pretest or posttest. You can also put 1 for pretest/posttest. Diary days start counting at 0.</p>";
	echo "<form method=\"POST\" action=\"editsubstitutions.php\"><table class=\"editstudies\">";
	echo "<th>Select</th> <th title=\"Leave empty for _most recent_ day or non-repeated surveys like pretest or posttest. You can also put 1 for pretest/posttest. Days start counting at 0.\">&nbsp;&nbsp;Iteration</th> <th>Key</th> <th>Value</th>";
	while( $result = mysql_fetch_assoc( $results ) ) {
		echo "<tr><td><input type=\"checkbox\" name=\"editsubsts[]\" value=\"".$result['id']."\"/></td>";
		echo "<td>&nbsp;&nbsp;".$result['mode']."</td>";
			
		echo "<td><input type='text' size='25' readonly='readonly' value='". $result['key']."'></input></td>";
		echo "<td><input type='text' size='25' readonly='readonly' value='".$result['value']."'></input></td></tr>";
	}
	echo "</table><input type=\"submit\" name=\"deleteselected\" value=\"Delete Selected\" /></form>";

	render_add_new_subst();

} else {
	echo "<p style=\"background: #CCCCCC;\"><strong>Navigation</strong></p>";
	echo "<form method=\"POST\" action=\"index.php\"><input type=\"submit\" value=\"Back to main menu\" /></form>";

	render_add_new_subst(); 

}


// schließe main-div
echo "</div>\n";
// binde Navigation ein
require ('includes/navigation.php');
// schließe Datenbank-Verbindung, füge bei Bedarf Analytics ein
require('includes/footer.php');
?>