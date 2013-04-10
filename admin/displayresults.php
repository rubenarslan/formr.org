<?php
require_once 'admin_header.php';
?>
<html><head><title>MySQL Table Viewer</title></head><body>
<?php

require_once 'includes/header.php';

$table = RESULTSTABLE;

// sending query
$result = mysql_query("SELECT * FROM {$table}");
if (!$result) {
    die("Query to show fields from table failed");
}


$fields_num = mysql_num_fields($result);

echo "<h1>Table: {$table}. <a href=\"displayresults.tsv.php\">Download results as tab-separated values</a></h1>";
echo "<table class='table'>
	<thead><tr>";
// printing table headers
for($i=0; $i<$fields_num; $i++)
{
    $field = mysql_fetch_field($result);
    echo "<th>{$field->name}</th>";
}
echo "</tr></thead>
<tbody>";
// printing table rows
while($row = mysql_fetch_row($result))
{
    echo "<tr>";

    // $row is array... foreach( .. ) puts every element
    // of $row to $cell variable
    foreach($row as $cell)
        echo "<td>$cell</td>";

    echo "</tr>\n";
}
mysql_free_result($result);

echo "</tbody></table>\n";

require_once 'includes/footer.php';
