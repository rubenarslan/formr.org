<?

header ('Content-type: text/html; charset=utf-8');

// Settings einlesen
require ('includes/settings.php');

// MySQL verbinden und Datenbank auswählen (Tabelle noch nicht)
require ('includes/mysql.php');

require ('../includes/variables.php');
require ('../includes/functions.php');

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>";
?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">

<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">

<head> 
		<title><? echo TITLE ?></title>
		<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
		<meta name="description" content="<? echo DESCRIPTION; ?>" />
		<meta name="keywords" content="<? echo KEYWORDS; ?>" />
		<meta name="author" content="<? echo AUTHOR; ?>" />
		<meta name="copyright" content="<? echo COPYRIGHT; ?>" />
		<meta name="page-topic" content="<? echo PAGETOPIC; ?>" />
		
		<?
		
		// Style einbinden
		require ('style.php');

		// Scripts einbinden
		require ('scripts.php');
		
		// Functions einbinden
		require ('functions.php');
		
		?>
			
</head>

<body>
