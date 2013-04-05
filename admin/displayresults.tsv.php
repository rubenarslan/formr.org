<?php
require ('includes/settings.php');
date_default_timezone_set('Europe/Berlin');

$table = RESULTSTABLE;

// sending query
$result = mysql_query("SELECT * FROM {$table}");
if (!$result) {
    die("Query to show fields from table failed");
}

header("Content-type: application/csv");
header("Content-Disposition: attachment; filename=$table".date('YmdHis').".csv");
header("Pragma: no-cache");
header("Expires: 0");


$fields_num = mysql_num_fields($result);

// printing table headers
for($i=0; $i<$fields_num; $i++)
{
    $field = mysql_fetch_field($result);
	$fieldname = $field->name;
    if($i!=($fields_num-1)) echo $fieldname."\t";
    else echo $fieldname;
}
echo "\n";

// printing table rows
while($row = mysql_fetch_row($result))
{

    // $row is array... foreach( .. ) puts every element
    // of $row to $cell variable
	$rowwidth = count($row);
    for($i=0;$i<$rowwidth;$i++) {
		$row[$i] = preg_replace("/[\r\n|\r|\n]/","\\n",$row[$i]);
		$row[$i] = str_replace("\t","    ",$row[$i]);
        if($i!=($rowwidth-1)) echo "{$row[$i]}\t";
		else echo "{$row[$i]}";
	}

    echo "\n";
}
mysql_free_result($result);

?>