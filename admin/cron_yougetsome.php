<?php
require ('admin_header.php');
?>
<html><head><title>are you fit for self insight</title></head><body>
<?php
# should maybe put the date in the vpndata table as well

require ('includes/settings.php');

$db_host = $DBhost;
$db_user = $DBuser;
$db_pwd = $DBpass;
$database = $DBName;

if (!mysql_connect($db_host, $db_user, $db_pwd))
    die("Can't connect to database");

if (!mysql_select_db($database))
    die("Can't select database");
mysql_query("set names 'utf8';");


/*
> damit wir eine repräsentative Stichprobe für die Studie bekommen, müssen wir die Bewerber(-flut) nach Studienrichtung selektieren.
> Folgende Verteilung brauchen wir:
> 1) 20% Sprach- und Kulturwissenschaftler
> 2) 30% Rechts-, Wirtschafts- und Sozialwissenschaftler
> 3) 20% Ingenieurwissenschaftler
> 4) 20% Mathematiker und Naturwissenschaftler
> 5) 10% andere
*/
$total = 225+25; # 25 in excess to account for initial dropout
$eligibletoday = 0;
$eligiblebygroup = array();
$studiengaenge = array(
	'Sprach- / Kulturwissenschaften',
	'Rechts-, Wirtschafts- / Sozialwissenschaften',
	'Ingenieurwissenschaften',
	'Mathematik / Naturwissenschaften',
	'andere');
$left = array(
		'Sprach- / Kulturwissenschaften' => ($total*0.2) - mysql_num_rows(mysql_query("SELECT id FROM vpnueberblick WHERE studiengang='Sprach- / Kulturwissenschaften'")),
		'Rechts-, Wirtschafts- / Sozialwissenschaften' => ($total*0.3) - mysql_num_rows(mysql_query("SELECT id FROM vpnueberblick WHERE studiengang='Rechts-, Wirtschafts- / Sozialwissenschaften'")),
		'Ingenieurwissenschaften' => ($total*0.2) - mysql_num_rows(mysql_query("SELECT id FROM vpnueberblick WHERE studiengang='Ingenieurwissenschaften'")),
		'Mathematik / Naturwissenschaften' =>  ($total*0.2) - mysql_num_rows(mysql_query("SELECT id FROM vpnueberblick WHERE studiengang='Mathematik / Naturwissenschaften'")),
		'andere' =>  ($total*0.1) - mysql_num_rows(mysql_query("SELECT id FROM vpnueberblick WHERE studiengang='andere'"))
		);
foreach($studiengaenge AS $group) {
	$eligible = "SELECT u1.id FROM 
		jos_users AS u1 
		LEFT JOIN jos_comprofiler AS j1
		ON u1.id=j1.user_id
		WHERE 
		j1.cb_welchesstudium = '$group'
		AND u1.registerDate > '04.01.2012 14:00:00'
		AND j1.confirmed  = 1  # only those who confirmed their mail address
		AND u1.block = 1 # only those who weren't authorized already (presumably manually by Sarah)
		AND j1.cb_bart!='nein' # can be missing if not wearing a beard*/
		AND j1.cb_fliessenddeutsch = 'ja' # gut deutsch
		AND j1.cb_adlershofbesuch = 'ja'
		AND (j1.cb_studienende != 'später' AND j1.cb_studienende != 'bin bereits fertig')
		AND j1.cb_studienende != 'in 9 Monaten'
		AND j1.cb_studienende != 'in 10 Monaten'
		AND j1.cb_studienende != 'in 11 Monaten'
		AND j1.cb_studienende != 'in 12 Monaten'
		AND j1.cb_studiumfrei NOT LIKE '%Psychologie%'
		AND j1.cb_abschluss != 'Promotion'
		AND j1.cb_berufseintritt != 'später'
		AND j1.cb_berufseintritt != 'in 10 Monaten'
		AND j1.cb_berufseintritt != 'in 11 Monaten'
		AND j1.cb_berufseintritt != 'in 12 Monaten'
		AND j1.cb_berufseintritt != 'bleibe in meinem jetzigen Job'
		AND j1.cb_bereitsjob != 'ja, bleibe in meinem jetzigen Job'
		AND j1.cb_bereitsjob != 'ja, übergangsweise, bis ich eine Stelle gefunden habe'
		AND (j1.cb_auslandsplan = 'nein' OR  j1.cb_auslandsplan = 'weiß es noch nicht')
		AND j1.cb_wegausberlin != 'ganz sicher'
		AND j1.cb_wohnenoderstudieren  != 'anderswo'
	 # specific limit for those from this study
		";
#echo "<pre>".$eligible . "</pre><br>";
	$eligible = mysql_query($eligible) or die(mysql_error()); # activate after testing
	$eligiblebygroup[$group] = mysql_num_rows($eligible); # specific
	$eligibletoday += $eligiblebygroup[$group]; ## add em up
	$el = array();

	while($row = mysql_fetch_row($eligible))  {
	                  $el[] = $row[0];
		}


	if($eligiblebygroup[$group] > 0) {
		$update = "UPDATE jos_users 
		SET block=0
			WHERE id IN (".implode(',',$el).")
			ORDER BY registerDate ASC # First come first serve
			LIMIT {$left[$group]}";
		$update = mysql_query($update) or die(mysql_error()); # activate after testing	
		}
}

if($eligibletoday>0) {
	echo $eligibletoday." new fit subjects in joomla that will be auto-added.<br>";
	print_r($eligiblebygroup);
}


?>
</body></html>