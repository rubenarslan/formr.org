<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="de-de" lang="de-de" >
<head>
  <base href="http://vomstudiumindenberuf.de/" />
  <meta http-equiv="content-type" content="text/html; charset=utf-8" />
  <meta name="robots" content="index, follow" />
  <meta name="keywords" content="" />
  <meta name="rights" content="" />
  <meta name="language" content="de-DE" />
  <meta name="title" content="Willkommen zu Vom Studium in den Beruf! Wir freuen uns, dass Sie sich für unsere Studie interessieren." />

  <meta name="generator" content="Joomla! 1.6 - Open Source Content Management" />
  <title>Laborbesucher eintragen</title>
  <link href="/templates/rhuk_milkyway_ext_16/favicon.ico" rel="shortcut icon" type="image/vnd.microsoft.icon" />
  <script src="/media/system/js/core.js" type="text/javascript"></script>


<link rel="stylesheet" href="/templates/system/css/system.css" type="text/css" />
<link rel="stylesheet" href="/templates/system/css/general.css" type="text/css" />
<link rel="stylesheet" href="/templates/rhuk_milkyway_ext_16/css/template.css" type="text/css" />
<link rel="stylesheet" href="/templates/rhuk_milkyway_ext_16/css/white.css" type="text/css" />
<link rel="stylesheet" href="/templates/rhuk_milkyway_ext_16/css/white_bg.css" type="text/css" />

<!--[if lte IE 6]>
<link href="/templates/rhuk_milkyway_ext_16/css/ieonly.css" rel="stylesheet" type="text/css" />
<![endif]-->

</head>
<body id="page_bg" class="color_white bg_white width_medium">
<a name="up" id="up"></a>
<div class="center" align="center">
	<div id="wrapper">
		<div id="wrapper_r">
			<div id="header">
				<div id="header_l">
					<div id="header_r">
						<div id="logo"></div>

						
						

					</div>
				</div>
			</div>

			<div id="tabarea">
				<div id="tabarea_l">
					<div id="tabarea_r">
						<div id="tabmenu">
						<table cellpadding="0" cellspacing="0" class="pill">

							<tr>
								<td class="pill_l">&nbsp;</td>
								<td class="pill_m">
								<div id="pillmenu">
									
									
								</div>
								</td>
								<td class="pill_r">&nbsp;</td>
							</tr>
							</table>

						</div>
					</div>
				</div>
			</div>

			<div id="search">
				
				
			</div>

			<div id="pathway">
				
				
			</div>

			<div class="clr"></div>

			<div id="whitebox">
				<div id="whitebox_t">
					<div id="whitebox_tl">
						<div id="whitebox_tr"></div>
					</div>
				</div>

				<div id="whitebox_m">
					<div id="area">
									

						<div id="leftcolumn">
													
									<div class="module_menu">
			<div>
				<div>
					<div>
											
<ul class="menu">
<li id="item-102" class="current active"><a href="/" >Willkommen</a></li><li id="item-120"><a href="/faq" >FAQ</a></li><li id="item-121"><a href="/anmeldung" >Anmeldung</a></li><li id="item-122"><a href="/impressum" >Impressum</a></li></ul>

					</div>
				</div>
			</div>
		</div>
			<div class="module">
			<div>
				<div>
					<div>
													<h3>Facebook</h3>

											
<iframe src="http://www.facebook.com/plugins/like.php?href=http%3A%2F%2Fvomstudiumindenberuf.de%2F&amp;layout=box_count&amp;show_faces=false&amp;width=176&amp;action=like&amp;font=arial&amp;colorscheme=light&amp;height=100" scrolling="no" frameborder="0" style="border:none; overflow:hidden; width:176px; height:150px;font-size:1.3em;" allowTransparency="true"></iframe>
					</div>
				</div>
			</div>
		</div>
	
												</div>

												<div id="maincolumn">
													
							<table class="nopad">

								<tr valign="top">
									<td>
										<div class="item-page">

<?php

/*
VPNUEBERBLICK
1 	id 	int(11) 			No 	None
	2 	vpncode 	varchar(100) 	
	3 	email 	varchar(100) 	utf8
	4 	study 	varchar(100) 	utf8
	5 	tagebuch_zuerst 	tinyint(
	6 	sex 	tinyint(1) 			
	7 	studiengang 	varchar(100)
	8 	partnercode 	varchar(100)
	9 	vpntype 	int(10) 		
	10 	laborbesucht 	date 		
	11 	transferred_from_joomla
	12 lab_id varchar(20) */
$anon = isset($_GET['anon']);

$DBhost = "localhost";
$DBuser = "d011c47b";
$DBpass = "rK4tqKJccWnysUeu";
$DBName = "d011c47b";

mysql_connect($DBhost,$DBuser,$DBpass) or die("Datenbank-Verbindung fehlgeschlagen. Bitte versuchen Sie es noch einmal.");
@mysql_select_db("$DBName") or die("Datenbank-Auswahl fehlgeschlagen. Bitte versuchen Sie es noch einmal.");
mysql_query("set names 'utf8';");

	?>

<div style="font-size:1.5em;line-height:2em">	
<h1>Laborbesucher eintragen</h1>
<h2>Adminbereiche: 
	<a href="/tagebuch/admin/kommandozentrale.php">Kommandozentrale</a>
	</h2>
<?php if(isset($_GET['email']) AND isset($_GET['id'])) {
			$update = mysql_query("UPDATE vpnueberblick 
				SET laborbesucht = CURDATE(),
				lab_id = '".mysql_real_escape_string($_GET['id'])."',
				versuchsleiter = '".mysql_real_escape_string($_GET['versuchsleiter'])."',
				telefon1 = '".mysql_real_escape_string($_GET['telefon1'])."',
				telefon2 = '".mysql_real_escape_string($_GET['telefon2'])."',
				telefon3 = '".mysql_real_escape_string($_GET['telefon3'])."',
				adresse = '".mysql_real_escape_string($_GET['adresse'])."',
				feedbackperpost = '".mysql_real_escape_string($_GET['feedbackperpost'])."',
				weihnachtswarteliste = 0,
				kontaktnotizen = '".mysql_real_escape_string($_GET['kontaktnotizen'])."'
				WHERE LOWER(email) = LOWER('".mysql_real_escape_string($_GET['email'])."')") or die(mysql_error());
				
			$howmany = mysql_affected_rows();
			if($howmany==1) { 
				$worked = true; ?>
				<strong>Erfolgreich eingetragen.</strong><br>
				<?php } 
				else { ?>
			<strong style="color:red">Emailadresse kam nicht vor (<?=$howmany?> mal, oder war schon eingetragen (siehe Kommandozentrale))</strong><br>
		<?php			}?>

<?php } 
else {
	$noemail = true;
}
$wholast = mysql_query("SELECT lab_id,versuchsleiter FROM vpnueberblick ORDER BY lab_id DESC LIMIT 1");
$wholast = mysql_fetch_assoc($wholast);
$nextlabid = $wholast['lab_id']+1;
$lastleiter = $wholast['versuchsleiter'];
 ?>
	<form action="/tagebuch/admin/laborbesuch.php" action="get">
		<p>
			<label>Email: <input style="font-size:1em;float:right;width:20em;" type="text" value="<?php
			if(!isset($worked) AND !isset($noemail)) echo $_GET['email'];
			?>" length="100" name="email"/></label><br style="clear:both">
			<label>VP-ID (EMG): <input style="font-size:1em;float:right;width:3em;" type="text" value="<?php
			if(!isset($worked) AND !isset($noemail)) echo $_GET['id'];
			else {
				echo $nextlabid;
			}
			?>" length="100" name="id"/></label><br>
			
			<label>Versuchsleiter/in <input style="font-size:1em;float:right;width:11em;" type="text" value="<?php
			if(!isset($worked) AND !isset($noemail)) echo $_GET['versuchsleiter'];
			else {
				echo $lastleiter;
			}
			?>" length="100" name="versuchsleiter"/></label><br>
			
			<label>Telefonnummer (präferiert): <input style="font-size:1em;float:right;" type="text" value="<?php
			if(!isset($worked) AND !isset($noemail)) echo $_GET['telefon1'];
			?>" length="100" name="telefon1"/></label><br style="clear:both">
			
			<label>Telefonnummer (alternativ): <input style="font-size:1em;float:right;" type="text" value="<?php
			if(!isset($worked) AND !isset($noemail)) echo $_GET['telefon2'];
			?>" length="100" name="telefon2"/></label><br style="clear:both">
			
			<label>Telefonnummer der Eltern: <input style="font-size:1em;float:right;" type="text" value="<?php
			if(!isset($worked) AND !isset($noemail)) echo $_GET['telefon3'];
			?>" length="100" name="telefon3"/></label><br style="clear:both">


			<label>Eigene Adresse: <textarea name="adresse" style="font-size:1em;float:right;" rows="3" cols="25"><?php
			if(!isset($worked) AND !isset($noemail)) echo $_GET['adresse'];
			?></textarea></label><br style="clear:both">

			
			<label>Andere Kontaktmöglichkeit/Notizen: <textarea name="kontaktnotizen" style="font-size:1em;float:right;" rows="3" cols="25"><?php
			if(!isset($worked) AND !isset($noemail)) echo $_GET['kontaktnotizen'];
			?></textarea></label><br style="clear:both">
		
			<label>Feedback per Post?: <input type="checkbox" name="feedbackperpost" <?php
			if(!isset($worked) AND !isset($noemail)) echo $_GET['feedbackperpost']?' checked="checked" ':'';
			?>></label><br style="clear:both">
			
			<input type="submit" />
		</p>
	</form>
	</div>
		</div>								
										
										
										
										</td>
																	</tr>

							</table>

						</div>
						<div class="clr"></div>
					</div>
					<div class="clr"></div>
				</div>

				<div id="whitebox_b">
					<div id="whitebox_bl">

						<div id="whitebox_br"></div>
					</div>
				</div>
			</div>

			<div id="footerspacer"></div>
		</div>

		<div id="footer">
			<div id="footer_l">

				<div id="footer_r">
					<p id="syndicate">
						
						
					</p>
					<p id="power_by"><a href="mailto:info@vomstudiumindenberuf.de">Kontakt</a>
					</p>
				</div>
			</div>
		</div>

	</div>
</div>


</body>
</html>