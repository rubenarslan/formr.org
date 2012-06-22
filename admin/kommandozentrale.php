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
  <title>Kommandozentrale</title>
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
$anon = isset($_GET['anon']);

$DBhost = "localhost";
$DBuser = "d011c47b";
$DBpass = "rK4tqKJccWnysUeu";
$DBName = "d011c47b";

mysql_connect($DBhost,$DBuser,$DBpass) or die("Datenbank-Verbindung fehlgeschlagen. Bitte versuchen Sie es noch einmal.");
@mysql_select_db("$DBName") or die("Datenbank-Auswahl fehlgeschlagen. Bitte versuchen Sie es noch einmal.");
mysql_query("set names 'utf8';");

function get_tiny_url($url)  {  
  $ch = curl_init();  
  $timeout = 5;
  curl_setopt($ch,CURLOPT_URL,'http://tinyurl.com/api-create.php?url='.$url);  
  curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);  
  curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,$timeout);  
  $data = curl_exec($ch);  
  curl_close($ch);  
  return $data;
}
	?>
	<?php
	$total = 225+25; # 25 in excess to account for initial dropout
	$sprache = mysql_num_rows(mysql_query("SELECT id FROM vpnueberblick WHERE studiengang='Sprach- / Kulturwissenschaften'"));
	$sprache_left = ($total*0.2) - $sprache;
	$recht = mysql_num_rows(mysql_query("SELECT id FROM vpnueberblick WHERE studiengang='Rechts-, Wirtschafts- / Sozialwissenschaften'"));
	$recht_left = ($total*0.3) - $recht;
	$ingen = mysql_num_rows(mysql_query("SELECT id FROM vpnueberblick WHERE studiengang='Ingenieurwissenschaften'"));
	$ingen_left = ($total*0.2) -$ingen;
	$mathe = mysql_num_rows(mysql_query("SELECT id FROM vpnueberblick WHERE studiengang='Mathematik / Naturwissenschaften'"));
	$mathe_left = ($total*0.2) - $mathe;
	$andere = mysql_num_rows(mysql_query("SELECT id FROM vpnueberblick WHERE studiengang='andere'"));
	$andere_left = ($total*0.1) - $andere;
	?>
	
<h1>		Kommandozentrale		</h1>
<?php if(isset($_GET['linkverschickt'])) { ?>
<h2 style="background:yellow;border:1px solid black"><?=($_GET['linkverschickt']?"Reminder an {$_GET['email']} verschickt.":'Fehler bei Reminder');?></h2>
<?php } ?>
<?php if(isset($_GET['geloescht'])) { ?>
<h2 style="background:yellow;border:1px solid black"><?=($_GET['geloescht']?"{$_GET['email']} gelöscht.":"Fehler bei Löschung von {$_GET['email']}");?></h2>
<?php } ?>
<?php if(!$anon) { ?><h2><a href="/tagebuch/admin/kommandozentrale.php?anon">Anonyme Version</a> (mit Vpncodes)</a></h2> <?php }
else {?><h2><a href="/tagebuch/admin/kommandozentrale.php">Nonanonyme Version</a> (ohne Vpncodes, mit Emails)</a></h2>  <?php } ?>
<h2><a href="/tagebuch/admin/laborbesuch.php">Laborbesucher eintragen</a></h2>
<h2>Adminbereiche: 
	<a href="/persoenlichkeit/admin/">FB1</a> <b>|</b>
	<a href="/persoenlichkeit2/admin/">FB2</a> <b>|</b>
	<a href="/tagebuch/admin/">Tagebuch</a> <b>|</b>
	<a href="/freunde/admin/">Freunde</a> <b>|</b>
	<a href="/administrator/">Joomla Admin</a> <b>|</b> 
	<a href="/tagebuch/admin/joomla_export.php">Joomla Export</a> <b>|</b>
	<a href="https://www.terminland.de/vomstudiumindenberuf/intern/">Terminland</a>
	</h2>
<h3>Wie viele haben wir schon woher?</h3>
<ul>
	<li><big><strong>Insgesamt</strong>: <?=($sprache + $recht + $ingen +$mathe + $andere) ?> (<?=($sprache_left + $recht_left + $ingen_left +$mathe_left + $andere_left )?> fehlen)</big></li>
	<li><strong>Sprach- / Kulturwissenschaften</strong>: <?=$sprache?> (<?=$sprache_left?> fehlen)</li>
	<li><strong>Rechts-, Wirtschafts- / Sozialwissenschaften</strong>: <?=$recht?> (<?=$recht_left?> fehlen)</li>
	<li><strong>Ingenieurwissenschaften</strong>: <?=$ingen?> (<?=$ingen_left?> fehlen)</li>
	<li><strong>Mathematik / Naturwissenschaften</strong>: <?=$mathe?> (<?=$mathe_left?> fehlen)</li>
	<li><strong>Andere</strong>: <?=$andere?> (<?=$andere_left?> fehlen)</li>
</ul>	


	<?php

$daysforth = 15; //wieviele tage im graphen
echo '<h3>Joomla-Anmeldungen in den letzten ' . $daysforth. " Tagen</h3>";
echo '<h4>Das sind noch nicht die qualifizierten, rechts ist heute</h4>';

						$inletzterzeitzahlen = mysql_query("SELECT TO_DAYS(NOW())-TO_DAYS(registerDate) AS tage,COUNT(*) AS anzahl FROM jos_users WHERE  DATE(registerDate) >= ADDDATE(CURDATE(),-".$daysforth.") GROUP BY tage ORDER BY tage DESC") or die(mysql_error());

						$anzahl = array_fill_keys(range($daysforth,0),0);

						while($row=mysql_fetch_assoc($inletzterzeitzahlen)) {
							$anzahl[$row['tage']] = $row['anzahl'];
						}

						$dataseturl = '&amp;chd='.'t:' . implode(',',$anzahl);
						if(max($anzahl)!=0) $dataseturl .= '&amp;chds=0,'.max($anzahl);
						echo '<img src="http://chart.apis.google.com/chart?chs=500x100&amp;chm=B,76A4FB,0,0,0&amp;chco=0077CC&amp;cht=lc&amp;chxt=x,y&amp;chxr='
						.'1,'. min($anzahl) . ',' . max($anzahl) .'|'.
						'0,'. $daysforth . ',0'
						.$dataseturl . '" alt="Graph der fälligen Vokabeln der nächsten '.$daysforth.' Tage" title="Graph der fälligen Vokabeln der nächsten  '.$daysforth.' Tage" />';
						?>

													<?php

												$daysforth = 15; //wieviele tage im graphen
												echo '<h3>Qualifizierte in den letzten ' . $daysforth. " Tagen</h3>";

																		$inletzterzeitzahlen = mysql_query("SELECT TO_DAYS(NOW())-TO_DAYS(transferred_from_joomla) AS tage,COUNT(*) AS anzahl FROM vpnueberblick WHERE  DATE(transferred_from_joomla) >= ADDDATE(CURDATE(),-".$daysforth.") GROUP BY tage ORDER BY tage DESC") or die(mysql_error());

																		$anzahl = array_fill_keys(range($daysforth,0),0);

																		while($row=mysql_fetch_assoc($inletzterzeitzahlen)) {
																			$anzahl[$row['tage']] = $row['anzahl'];
																		}

																		$dataseturl = '&amp;chd='.'t:' . implode(',',$anzahl);
																		if(max($anzahl)!=0) $dataseturl .= '&amp;chds=0,'.max($anzahl);
																		echo '<img src="http://chart.apis.google.com/chart?chs=500x100&amp;chm=B,76A4FB,0,0,0&amp;chco=0077CC&amp;cht=lc&amp;chxt=x,y&amp;chxr='
																		.'1,'. min($anzahl) . ',' . max($anzahl) .'|'.
																		'0,'. $daysforth . ',0'
																		.$dataseturl . '" alt="Graph der fälligen Vokabeln der nächsten '.$daysforth.' Tage" title="Graph der fälligen Vokabeln der nächsten  '.$daysforth.' Tage" />';
																		?>


																			<?php

																		$daysforth = 50; //wieviele tage im graphen
																		echo '<h3>Joomla-Anmeldungen in den letzten ' . $daysforth. " Tagen</h3>";

																								$inletzterzeitzahlen = mysql_query("SELECT TO_DAYS(NOW())-TO_DAYS(registerDate) AS tage,COUNT(*) AS anzahl FROM jos_users WHERE  DATE(registerDate) >= ADDDATE(CURDATE(),-".$daysforth.") GROUP BY tage ORDER BY tage DESC") or die(mysql_error());

																								$anzahl = array_fill_keys(range($daysforth,0),0);

																								while($row=mysql_fetch_assoc($inletzterzeitzahlen)) {
																									$anzahl[$row['tage']] = $row['anzahl'];
																								}

																								$dataseturl = '&amp;chd='.'t:' . implode(',',$anzahl);
																								if(max($anzahl)!=0) $dataseturl .= '&amp;chds=0,'.max($anzahl);
																								echo '<img src="http://chart.apis.google.com/chart?chs=500x100&amp;chm=B,76A4FB,0,0,0&amp;chco=0077CC&amp;cht=lc&amp;chxt=x,y&amp;chxr='
																								.'1,'. min($anzahl) . ',' . max($anzahl) .'|'.
																								'0,'. $daysforth . ',0'
																								.$dataseturl . '" alt="Graph der fälligen Vokabeln der nächsten '.$daysforth.' Tage" title="Graph der fälligen Vokabeln der nächsten  '.$daysforth.' Tage" />';
																								?>

<?php

if(!$anon) { 
	$order = 'tagebuch_zuerst DESC,lab_id';#'studiengang';
	if(isset($_GET['studiengang'])) $order = 'studiengang';#'studiengang';
	if(isset($_GET['anfang'])) $order = 'transferred_from_joomla DESC,tagebuch_zuerst,lab_id';
	if(isset($_GET['nachemail'])) $order = 'email';
}
else $order = 'RAND()';
$query=mysql_query("SELECT * FROM vpnueberblick ORDER BY $order");
$nr = mysql_num_rows($query);

?>

<table style="width:800px;margin-left:-180px;background-color:white;border:1px solid black">
	<tr>
<?php if(!$anon) { ?>	<th><a href="/tagebuch/admin/kommandozentrale.php?nachemail=1">Email</a></th> <?php } ?>		
<?php if($anon) { ?>	<th>Vpncode</th> <?php } ?>
		<th>Sex</th>
		<th title="Anfang Studie/Einladung"><a href="/tagebuch/admin/kommandozentrale.php?anfang=1">Anfang</a></th>
		<th title="Studiengang"><a href="/tagebuch/admin/kommandozentrale.php?studiengang=1">Stdngng</a></th>
		<th>Tagebuch</th>
		<th><a href="/tagebuch/admin/kommandozentrale.php">Laborbesuch</a></th>
		<th>FB 1</th>
		<th>FB 2</th>
		<th>Freunde</th>
		<th>#</th>
		<th>Remind</th>
		<th>Delete</th>
	</tr>
<?php
$tuttoarray=array();

while($vpn = mysql_fetch_assoc($query)) {
$tutto=0;
?>
	<tr>
<?php if(!$anon) { ?>			<td><?=$vpn['email'];?></td> <?php } ?>
<?php if($anon) { ?>			<td><a href="http://vomstudiumindenberuf.de/ueberblick/?vpncode=<?=$vpn['vpncode'];?>"><?=$vpn['vpncode'];?></a></td> <?php } ?>
		<td><?=$vpn['sex'];?></td>
		<td><?=$vpn['transferred_from_joomla'];?></td>
		<td><?=substr($vpn['studiengang'],0,5);?></td>
		
<?php
$vpncode = $vpn['vpncode'];
$tagebuchzuerst = mysql_num_rows(mysql_query("SELECT tagebuch_zuerst FROM vpnueberblick WHERE vpncode='$vpncode' AND tagebuch_zuerst=1")); # vpn gibt es ja in diesem else-block, d.h. wenn t_z=1 dann 1 sonst 0


$friendsfilledout = 0; # number of friends who filled out
$persoenlichkeit1 = false; # TRUE/FALSE persoenlichkeit1 filled out
$persoenlichkeit2 = false; # TRUE/FALSE persoenlichkeit2 filled out
$tagebuch = 0; # diary days completed
$laborbesuch = 0; # been to lab

$friendsfilledout = mysql_num_rows(mysql_query("SELECT endedsurveysmsintvar FROM freunde_results WHERE freundcode='$vpncode' AND endedsurveysmsintvar IS NOT NULL"));
$persoenlichkeit1 = mysql_num_rows(mysql_query("SELECT endedsurveysmsintvar FROM persoenlichkeit1_results WHERE vpncode='$vpncode' AND endedsurveysmsintvar IS NOT NULL"));
$persoenlichkeit2 = mysql_num_rows(mysql_query("SELECT endedsurveysmsintvar FROM persoenlichkeit2_results WHERE vpncode='$vpncode' AND endedsurveysmsintvar IS NOT NULL"));
$tagebuch = mysql_num_rows(mysql_query("SELECT endedsurveysmsintvar FROM selfinsight_results WHERE vpncode='$vpncode' AND study='diary' AND endedsurveysmsintvar IS NOT NULL"));
$laborbesuch = mysql_num_rows(mysql_query("SELECT laborbesucht FROM vpnueberblick WHERE vpncode='$vpncode' AND laborbesucht IS NOT NULL")); # been to lab

$pretestdate = mysql_query("SELECT DATE(endedsurveysmsintvar) FROM selfinsight_results WHERE vpncode='$vpncode' AND study='pretest' AND endedsurveysmsintvar IS NOT NULL");
$pretestdate = mysql_fetch_row($pretestdate);
$pretestdate = $pretestdate[0];

$finaldate = mysql_query("SELECT DATE(endedsurveysmsintvar) FROM selfinsight_results WHERE vpncode='$vpncode' AND study='diary' AND iteration=14 AND endedsurveysmsintvar IS NOT NULL");
if(mysql_num_rows($finaldate)!=0) { ## wenn schon fertig.
	$finaldate = mysql_fetch_row($finaldate);
	$finaldate = "'".$finaldate[0]."'";
}
else $finaldate = "CURDATE()";

$verstrichen = mysql_query("SELECT DATEDIFF($finaldate,'$pretestdate')");
$verstrichen = mysql_fetch_row($verstrichen);
$skipped = $verstrichen[0]-$tagebuch;

?>
		<td style="background-color:<?php
		if($tagebuch>13) { echo '#2C7FB8'; $tutto++; }
		else if($tagebuch>0) echo '#F66';
		else echo '#C00'; ?>;">
				<?=$tagebuch?><abbr title="Tage">T</abbr>,<?=$skipped?><abbr title="skipped">s</abbr>
<?php			if(!($tagebuchzuerst==1 OR $laborbesuch==1)) {
				?><br>erst Labor<?php
			}?>
		</td>
		<td style="background-color:<?=($laborbesuch?'#2C7FB8':'#C00')?>;">
			<?php
				if($laborbesuch!=0) { $tutto++;
					?> <strong><?php if($anon)  echo $vpn['lab_id']." – ".$vpn['laborbesucht']; else echo "***"?></strong> <?php }
			if(!(($tagebuchzuerst==1 AND $tagebuch>13) || $tagebuchzuerst==0))
				echo "<br>erst Tagebuch";
				?>
		</td>
		<td style="background-color:<?=($persoenlichkeit1?'#2C7FB8':'#C00')?>">
				<?php
				if($persoenlichkeit1) $tutto++;
				echo $persoenlichkeit1 ?'done':''?>
		</td>
		<td style="background-color:<?=($persoenlichkeit2?'#2C7FB8':'#C00')?>;">
			<?php
			if($persoenlichkeit2) $tutto++;
			echo $persoenlichkeit2 ?'done':''?>
		</td>

		<td style="background-color:	<?php
			if($friendsfilledout>2) { echo '#2C7FB8'; $tutto++; }
			else if($friendsfilledout>0) echo '#F66';
			else echo '#C00'; ?>;">
			<?php echo $friendsfilledout	?>
		</td>
		<td>
			<?php echo $tutto;
			$tuttoarray[] = $tutto; ?>
		</td>
		<td>
			<a href="http://vomstudiumindenberuf.de/tagebuch/admin/linkschicken.php?wem=<?=rawurlencode($vpn['email']);?>">
				Mail!</a>
		</td>
		<td>
			<a style="color:red" href="http://vomstudiumindenberuf.de/tagebuch/admin/vpnloeschen.php?wen=<?=rawurlencode($vpn['email']);?>">
			☓</a>
		</td>
</tr>
<?php } ?>
</table>

	

						</div>
						<div class="clr"></div>
					</div>
					<div class="clr"></div>
				</div>
				<div>
				<h2>Wieviele haben schon wieviele Stationen erfüllt?</h2>
				<p>
				<?php
					print_r(array_count_values($tuttoarray));
					?>
				</p>
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