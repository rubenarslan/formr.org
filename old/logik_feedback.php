<?
// Öffne Datenbank, mache ordentlichen Header, binde Stylesheets, Scripts ein
require ('includes/header.php');
// Endet mit </html>
require ('includes/design.php');
// macht das ganze Klickibunti, endet mit <div id="main"

// Schreibe, was gepostet wurde
// writepostedvars($vpncode);

// Baue mir eine Tabelle

function myPrint($value) { print "$value "; } 

/*
Examples of array_walk()
function myPrint($value) { print "$value "; } 
$array1 = array(1,2,3,4);
array_walk($array1, "add", 3);
array_walk($array1, "myPrint");
$array1 = array(1,2,3,4); echo "<br />";
array_walk($array1, "substract", 3);
array_walk($array1, "myPrint");
$array1 = array(1,2,3,4); echo "<br />";
array_walk($array1, "multiply", 3);
array_walk($array1, "myPrint");
$array1 = array(1,2,3,4); echo "<br />";
array_walk($array1, "divide", 3);
array_walk($array1, "myPrint");
$array1 = array(1,2,3,4); echo "<br />";
array_walk($array1, "myPrint");
*/

	// recoding functions
	// possible operations are: substract, add, multiply, divide
	function substract(&$value, $key, $factor) { $value -= $factor; }
	function add(&$value, $key, $factor) { $value += $factor; }
	function multiply(&$value, $key, $factor) { $value *= $factor; }
	function divide(&$value, $key, $factor) { $value /= $factor; }
	function recodevalues($valuestorecode,$function,$value) {
		array_walk($valuestorecode, $function, $value);
		return $valuestorecode;
		// $valuestorecode remains an internal variable to this function
	}
	// BSP: $recoded = recodevalues($values,"substract",3));
	// $values zum Beispiel von getvalues($items)
	// Skalen verschieben, reverse coden etc. alles kein Problem
		
	function mean($values) {
		$count = count($values); //total numbers in array
	    $total = array_sum ($values);
    	$mean = ($total/$count); // get average value
		return $mean;
	}

	function getvalues($vpncode,$items) {
		$query = "SELECT " . implode(", ", array_values($items)) . " FROM " . RESULTSTABLE . " WHERE vpncode='" . $vpncode . "'";
		$result=mysql_query($query);
		if(DEBUG) {
			// echo $query;
			// echo mysql_error();
		}
		$resultarray=mysql_fetch_row($result);
		return $resultarray;
	}

	function getzvalue($values,$mean,$sd) {
		$z = (mean($values)-$mean)/$sd;
		if(DEBUG) {
			// print_r($resultarray) . "<br />";
			echo "values are " . implode(", ", array_map('quote', $values)) . "<br />";
			echo "person mean is " . mean($values) . "<br />";
			echo "sample mean is " . $mean . "<br />";
			echo "sample sd is " . $sd . "<br />";
		}
		return round($z,2);
	}
	
	function sumgetzvalue($values,$mean,$sd) {
		$z = (array_sum($values)-$mean)/$sd;
		if(DEBUG) {
			// print_r($resultarray) . "<br />";
			echo "values are " . implode(", ", array_map('quote', $values)) . "<br />";
			echo "person sum is " . array_sum($values) . "<br />";
			echo "sample mean is " . $mean . "<br />";
			echo "sample sd is " . $sd . "<br />";
			echo "person z is: " . round($z,2) . "<br />";
		}
		
		return round($z,2);
	}

	function zvaluetoword($z) {
		if ($z <= -2 ) {
			$word = " sehr niedrigen ";
		} elseif ($z <= -1 ) {
			$word = " niedrigen ";
		} elseif ($z <= 1 ) {
			$word = " durchschnittlichen ";
		} elseif ($z < 2 ) {
			$word = " hohen ";
		} elseif ($z >= 2 ) {
			$word = " sehr hohen ";
		}
	return $word;
	}
	
	function cutofftoword($values,$cutoff) {
		// Mache Summe
		$total = array_sum($values);
	 	if ($total < $cutoff ) {
			$word = " niedrigen ";
		} else {
			$word = " hohen ";
		}  
		return $word;
	}

	/* function recode($values,$direction,$value) {
	array_walk(array arr, string func, [mixed userdata])
	
	if ($direction == "left") {
		
	} else if ($direction == "right") {
		
	} else {
		echo "ERROR! Direction must be left (for minus) or right (for plus).";
	}
	}
	*/
// }


?>


<style type="text/css">
	@page { size: 20.999cm 29.699cm; margin-top: 2.499cm; margin-bottom: 2cm; margin-left: 2.499cm; margin-right: 2.499cm }
	table { border-collapse:collapse; border-spacing:0; empty-cells:show }
	td, th { vertical-align:top; font-size:12pt;}
	h1, h2, h3, h4, h5, h6 { clear:both }
	ol, ul { margin:0; padding:0;}
	li { list-style: none; margin:0; padding:0;}
	li span.odfLiEnd { clear: both; line-height:0; width:0; height:0; margin:0; padding:0; }
	span.footnodeNumber { padding-right:1em; }
	* { margin:0; }
	.P1 { font-size:11pt; line-height:115%; margin-bottom:0.353cm; margin-top:0cm; font-family:Arial; font-weight:bold; }
	.P10 { font-size:12pt; line-height:100%; margin-bottom:0cm; margin-top:0cm; font-family:Calibri; text-align:center ! important; }
	.P3 { font-size:12pt; line-height:100%; margin-bottom:0cm; margin-top:0cm; font-family:Calibri; }
	.P4 { font-size:12pt; line-height:100%; margin-bottom:0cm; margin-top:0cm; font-family:Calibri; text-align:center ! important; }
	.P5 { font-size:11pt; line-height:100%; margin-bottom:0cm; margin-top:0cm; font-family:Arial; }
	.P6 { font-size:11pt; line-height:100%; margin-bottom:0cm; margin-top:0cm; font-family:Arial; text-align:center ! important; }
	.P7 { font-size:11pt; line-height:100%; margin-bottom:0cm; margin-top:0cm; font-family:Arial; text-decoration:underline; }
	.P8 { font-size:11pt; line-height:100%; margin-bottom:0cm; margin-top:0cm; font-family:Arial; font-weight:bold; }
	.P9 { font-size:11pt; line-height:100%; margin-bottom:0cm; margin-top:0cm; font-family:Arial; font-weight:bold; }
	.Standard { font-size:11pt; line-height:115%; margin-bottom:0.353cm; margin-top:0cm; font-family:Calibri; }
	.Tabelle1 { width:14.3cm; float:none; }
	.Tabelle1_A1 { padding-left:0.191cm; padding-right:0.191cm; padding-top:0cm; padding-bottom:0cm; border-style:none; }
	.Tabelle1_A { width:7.345cm; }
	.Tabelle1_B { width:6.953cm; }
	.T2 { font-family:Arial; font-size:11pt; font-weight:bold; }
	.T3 { font-family:Arial; font-size:11pt; }
	.T4 { font-family:Arial; font-size:11pt; font-style:italic; font-weight:bold; }
	.T5 { font-family:Arial; font-size:11pt; font-style:italic; }
	.T6 { font-family:Arial; font-size:11pt; font-style:italic; text-decoration:underline; }
	.T8 { color:#ff0000; font-family:Arial; font-size:10pt; }
	<!-- ODF styles with no properties representable as CSS -->
	.Tabelle1.1 { }
	</style>

<?
echo "<table class=\"survey\" width=\"" . SRVYTBLWIDTH . "\">";
echo "<tr class=\"bottomsubmit\"><td class=\"bottomsubmit\" align=\"left\">Im Folgenden erhalten Sie ein persönliches Feedback, das die einzelnen Dimensionen des Fragebogens näher beschreibt und Ihnen mitteilt, ob Sie bezüglich dieser Dimensionen einen durchschnittlichen, niedrigen, sehr niedrigen, hohen oder sehr hohen Wert erzielt haben.<br /><br /><center><div style=\"width: 400px; max-width: 400px; text-align: left;\">";
Bild("glocke.png");
echo "<br /><small>Der persönliche Durchschnittswert, den Sie auf einer Dimension erzielt haben wird verglichen mit dem Durchschnittswert, der an einer anderen Personengruppe gewonnen wurde. Der Durchschnittswertebereich der Gesamtpopulation umfasst alle Werte, welche von den mittleren 68,2 % aller Personen in der Population erzielt werden. Je weiter außerhalb ein Wert von diesem Durchschnittsbereich liegt, desto seltener wird er erzielt.</small></div></center><br /><br />";
?>

<p class="P10"><span class="T2">(1)  „Wo stehe ich gerade im Leben? Welche Sicherheiten und Unsicherheiten gibt es?“</span></p><p class="P5"> </p><p class="P3"><span class="T3">Sie haben einige Fragen zum Identitätsempfinden ausgefüllt. Diese erfassen, wie stimmig, konsistent und klar das Bild ist, dass einer Person von sich selbst hat und mit welcher Sicherheit sie zu sich selbst, ihren Überzeugungen und ihrer Außenwelt steht. Hohe Werte deuten darauf hin, dass eine positive Entwicklung in Richtung eines gefestigten Selbstbildes stattgefunden hat. Niedrige Werte deuten darauf hin, dass es Konflikte und Unsicherheiten bezüglich der Frage gibt, wer man selbst ist und wo man im Leben hin möchte.</span></p><p class="P5"> </p><p class="P3"><span class="T3">

Der von Ihnen erzielte Wert auf der Skala </span><span class="T2">„Identität“</span><span class="T3"> entspricht einer
<em><?
$items = array("IdClar1", "IdClar2", "IdClar3", "IdClar6", "IdClar8");
$reverseitems = array("IdClar4rev", "IdClar5rev", "IdClar7rev", "IdClar9rev", "IdClar10rev", "IdClar11rev");
$normalvalues = getvalues(getvpncode(),$items);
$recodevalues = getvalues(getvpncode(),$reverseitems);
$recodestep1 = recodevalues($recodevalues,multiply,-1);
$recodestep2 = recodevalues($recodestep1,add,6);
$correctedvalues = array_merge($normalvalues, $recodestep2);
if (DEBUG) {
	echo "<br />Normal Items are: ";
	array_walk($items, "myPrint");
	echo "<br />Values of normal items are: ";
	array_walk($normalvalues, "myPrint");
	echo "<br />Reverse-coded items are: ";
	array_walk($reverseitems, "myPrint");
	echo "<br />Values of reverse-coded items are: ";
	array_walk($recodevalues, "myPrint");
	echo "<br />Corrected values of reverse-coded items are: ";
	array_walk($recodestep2, "myPrint");
	echo "<br />";
}
echo zvaluetoword(getzvalue($correctedvalues, 3.84,0.58));
?></em>
Ausprägung der Eigenschaft.</span><span class="T8"> </span><span class="T3">Die Vergleichsstichprobe umfasste  234 Studierende zweier US-amerikanischer Universitäten mit einem Durchschnittsalter zwischen 17 und 23 Jahren (Gesamtaltersspanne: 18-29 Jahre).</span></p><p class="P5"> </p><p class="P5"> </p><p class="P4"><span class="T2">(2) „Wie gehe ich mein Leben an?“</span></p><p class="P5"> </p><p class="P3"><span class="T3">Zum Umgang mit dem eigenen Leben gehört die gedankliche Auseinandersetzung mit Situationen, Aufgaben und Problemen. Sie haben einige Fragen beantwortet, die stabile, zwischenmenschliche Unterschiede im Bedürfnis nach gedanklicher Auseinandersetzung („Need for Cognition“) abbilden. Personen mit einem hohen Need for Cognition neigen dazu, sich aus eigenem Willen heraus mit herausfordernden, aufwändigen und auch anstrengenden Denkaufgaben auseinander zu setzen. Sie haben Spaß am Denken und schätzen Ihre eigenen Denkfähigkeiten positiv ein. Personen mit einem niedrigen Need for Cognition vermeiden lieber die Auseinandersetzung mit komplexen Denkaufgabe und ziehen keine besondere Befriedigung aus intensiver gedanklicher Anstrengung.</span></p><p class="P5"> </p><p class="P3"><span class="T3">

Der von Ihnen erzielte Wert auf der Skala </span><span class="T2">„Need for Cognition“</span><span class="T3"> entspricht einer
<em><?
$items = array("NFC1", "NFC2", "NFC3", "NFC5", "NFC13", "NFC14");
$reverseitems = array("NFC4rev", "NFC6rev", "NFC7rev", "NFC8rev", "NFC9rev", "NFC10rev", "NFC11rev","NFC12rev", "NFC15rev", "NFC16rev");
$normalvalues = recodevalues(getvalues(getvpncode(),$items),substract,4);
$reversevalues = recodevalues(recodevalues(getvalues(getvpncode(),$reverseitems),substract,4),multiply,-1);
$correctedvalues = array_merge($normalvalues, $reversevalues);
if (DEBUG) {
	echo "<br />Normal Items are: ";
	array_walk($items, "myPrint");
	echo "<br />Values of normal items are: ";
	array_walk($normalvalues, "myPrint");
	echo "<br />Reverse-coded items are: ";
	array_walk($reverseitems, "myPrint");
	echo "<br />Corrected values of reverse-coded items are: ";
	array_walk($reversevalues, "myPrint");
	echo "<br />";
}
echo zvaluetoword(sumgetzvalue($correctedvalues, 15.28,11.14));
?></em>
Ausprägung der Eigenschaft. Die Vergleichsstichprobe umfasste 307 Studierende einer deutschen Universität mit einem Durchschnittsalter zwischen 18 und 23 Jahren (Gesamtaltersspanne: 18-42 Jahre).</span></p><p class="P1"> </p><p class="P4"><span class="T2">(3) „Welche Rolle nahmen meine Eltern in meinem Leben ein?“</span></p><p class="P5"> </p><p class="P3"><span class="T3">Sie haben einen Fragebogen ausgefüllt, der erfasst, wie Erziehungseinstellungen und Verhaltensweisen der Hauptbezugsperson in Kindheit und Jugend (bis zum 16. Lebensjahr) von einer Person wahrgenommen werden. Diese werden anhand von zwei Merkmalsdimensionen beschrieben: Das Merkmal „Fürsorge“ bewegt sich zwischen Zuneigung/emotionaler Wärme (hohe Werte) und  Ablehnung/emotionaler Kälte (niedrige Werte). Das Merkmal „Kontrolle“ bewegt sich zwischen Kontrolle/einschränkender Aufdringlichkeit (hohe Werte) und Unterstützung von Unabhängigkeit und Autonomie (niedrige Werte). Je nach Merkmalsausprägung auf beiden Dimensionen, lässt sich das erlebte Erziehungsverhalten folgendermaßen beschreiben:</span></p><p class="P8"> </p><p class="P6"> </p><table border="0" cellspacing="0" cellpadding="0" class="Tabelle1"><colgroup><col width="321"/><col width="304"/></colgroup><tr class="Tabelle11"><td style="text-align:left;width:7.345cm; " class="Tabelle1_A1"><p class="Standard"><span class="T4">Optimale Erziehung</span></p><p class="Standard"><span class="T2">Hohe Fürsorge und niedrige Kontrolle</span></p></td><td style="text-align:left;width:6.953cm; " class="Tabelle1_A1"><p class="Standard"><span class="T4">Zuneigungsvolle Einschränkung</span></p><p class="Standard"><span class="T2">Hohe Fürsorge und hohe Kontrolle</span></p></td></tr><tr class="Tabelle11"><td style="text-align:left;width:7.345cm; " class="Tabelle1_A1"><p class="Standard"><span class="T4">Vernachlässigende Erziehung</span></p><p class="Standard"><span class="T2">Niedrige Fürsorge und niedrige Kontrolle</span></p></td><td style="text-align:left;width:6.953cm; " class="Tabelle1_A1"><p class="Standard"><span class="T4">Zuneigungslose Kontrolle</span></p><p class="Standard"><span class="T2">Niedrige Fürsorge und hohe Kontrolle</span></p></td></tr></table><p class="P5"> </p><p class="P5"> </p><p class="P3"><span class="T3">

Folgende Auswertung gilt, wenn Sie Ihre </span><span class="T5">Mutter als Hauptbezugsperson</span><span class="T3"> betrachtet haben:  </span></p><p class="P3"><span class="T3">(3.1a) Der von Ihnen erzielte Wert auf der „</span><span class="T2">Fürsorge-Dimension</span><span class="T3">“</span><span class="T2"> </span><span class="T3">entspricht einer

<em><?
$items = array("PBI_Care2", "PBI_Care8", "PBI_Care3", "PBI_Care9", "PBI_Care11", "PBI_Care12");
$reverseitems = array("PBI_Care1rev", "PBI_Care4rev", " PBI_Care5rev", "PBI_Care6rev", "PBI_Care7rev", "PBI_Care10rev");
$normalvalues = getvalues(getvpncode(),$items);
$normalvalues = recodevalues($normalvalues,substract,1);
$reversevalues = getvalues(getvpncode(),$reverseitems);
$reversevalues1 = recodevalues($reversevalues,substract,4);
$reversevalues2 = recodevalues($reversevalues1,multiply,-1);
$correctedvalues = array_merge($normalvalues, $reversevalues2);
if (DEBUG) {
	echo "<br />Normal Items are: ";
	array_walk($items, "myPrint");
	echo "<br />Values of normal items are: ";
	array_walk($normalvalues, "myPrint");
	echo "<br />Reverse-coded items are: ";
	array_walk($reverseitems, "myPrint");
	echo "<br />Corrected values of reverse-coded items are: ";
	array_walk($reversevalues2, "myPrint");
	echo "<br />";
}
echo cutofftoword($correctedvalues, 27);
?></em>

Ausprägung der Eigenschaft.</span></p><p class="P3"><span class="T3">(3.2a) Der von Ihnen erzielte Wert auf der „</span><span class="T2">Kontrolle-Dimension</span><span class="T3">“ entspricht einer 

<em><?
$items = array("PBI_Auto1", "PBI_Auto2", "PBI_Auto7","PBI_Auto10", "PBI_Auto11", "PBI_Auto13");
$reverseitems = array("PBI_Auto3rev", "PBI_Auto4rev", "PBI_Auto5rev", "PBI_Auto6rev",  "PBI_Auto8rev", "PBI_Auto9rev",  "PBI_Auto12rev");
$normalvalues = getvalues(getvpncode(),$items);
$normalvalues = recodevalues($normalvalues,substract,1);
$reversevalues = getvalues(getvpncode(),$reverseitems);
$reversevalues1 = recodevalues($reversevalues,substract,4);
$reversevalues2 = recodevalues($reversevalues1,multiply,-1);
$correctedvalues = array_merge($normalvalues, $reversevalues2);
if (DEBUG) {
	echo "<br />Normal Items are: ";
	array_walk($items, "myPrint");
	echo "<br />Values of normal items are: ";
	array_walk($normalvalues, "myPrint");
	echo "<br />Reverse-coded items are: ";
	array_walk($reverseitems, "myPrint");
	echo "<br />Corrected values of reverse-coded items are: ";
	array_walk($reversevalues2, "myPrint");
	echo "<br />";
}
echo cutofftoword($correctedvalues, 13.5);
?></em>


Ausprägung der Eigenschaft.</span></p><p class="P5"> </p><p class="P3"><span class="T3">

Folgende Auswertung gilt, wenn Sie Ihren </span><span class="T5">Vater als Hauptbezugsperson</span><span class="T3"> betrachtet haben:  </span></p><p class="P3"><span class="T3">(3.1b) Der von Ihnen erzielte Wert auf der „</span><span class="T2">Fürsorge-Dimension</span><span class="T3">“ entspricht einer

<em><?
$items = array("PBI_Care2", "PBI_Care8", "PBI_Care3", "PBI_Care9", "PBI_Care11", "PBI_Care12");
$reverseitems = array("PBI_Care1rev", "PBI_Care4rev", " PBI_Care5rev", "PBI_Care6rev", "PBI_Care7rev", "PBI_Care10rev");
$normalvalues = getvalues(getvpncode(),$items);
$normalvalues = recodevalues($normalvalues,substract,1);
$reversevalues = getvalues(getvpncode(),$reverseitems);
$reversevalues1 = recodevalues($reversevalues,substract,4);
$reversevalues2 = recodevalues($reversevalues1,multiply,-1);
$correctedvalues = array_merge($normalvalues, $reversevalues2);
if (DEBUG) {
	echo "<br />Normal Items are: ";
	array_walk($items, "myPrint");
	echo "<br />Values of normal items are: ";
	array_walk($normalvalues, "myPrint");
	echo "<br />Reverse-coded items are: ";
	array_walk($reverseitems, "myPrint");
	echo "<br />Corrected values of reverse-coded items are: ";
	array_walk($reversevalues2, "myPrint");
	echo "<br />";
}
echo cutofftoword($correctedvalues, 24);
?></em>

Ausprägung der Eigenschaft.</span></p><p class="P3"><span class="T3">(3.2b) Der von Ihnen erzielte Wert auf der „</span><span class="T2">Kontrolle-Dimension</span><span class="T3">“ entspricht einer
<em><?
$items = array("PBI_Auto1", "PBI_Auto2", "PBI_Auto7","PBI_Auto10", "PBI_Auto11", "PBI_Auto13");
$reverseitems = array("PBI_Auto3rev", "PBI_Auto4rev", "PBI_Auto5rev", "PBI_Auto6rev",  "PBI_Auto8rev", "PBI_Auto9rev",  "PBI_Auto12rev");
$normalvalues = getvalues(getvpncode(),$items);
$normalvalues = recodevalues($normalvalues,substract,1);
$reversevalues = getvalues(getvpncode(),$reverseitems);
$reversevalues1 = recodevalues($reversevalues,substract,4);
$reversevalues2 = recodevalues($reversevalues1,multiply,-1);
$correctedvalues = array_merge($normalvalues, $reversevalues2);
if (DEBUG) {
	echo "<br />Normal Items are: ";
	array_walk($items, "myPrint");
	echo "<br />Values of normal items are: ";
	array_walk($normalvalues, "myPrint");
	echo "<br />Reverse-coded items are: ";
	array_walk($reverseitems, "myPrint");
	echo "<br />Corrected values of reverse-coded items are: ";
	array_walk($reversevalues2, "myPrint");
	echo "<br />";
}
echo cutofftoword($correctedvalues, 12.5);
?></em>
Ausprägung der Eigenschaft.</span></p><p class="P5"> </p><p class="P3"><span class="T3">Die Vergleichsstichprobe umfasste eine bevölkerungsrepräsentative australische Stichprobe von 650 Personen, die an der „Sydney general practice study“ teilgenommen haben. </span></p><p class="P5"> </p><p class="P5"> </p><p class="P4"><span class="T2">(4) „Welche Sicht habe ich auf mich selbst?“</span></p><p class="P5"> </p><p class="P3"><span class="T3">Einschätzungen über sich selbst haben Sie mit dem Ausfüllen eines Persönlichkeitsfragebogens und eines Fragebogens zur Messung des Selbstwertgefühls vorgenommen.</span></p><p class="P5"> </p><p class="P3"><span class="T6">Ihre Persönlichkeit:</span></p><p class="P5"> </p><p class="P3"><span class="T3">Der Persönlichkeitsfragebogen befasst sich mit den sogenannten „großen fünf“ Faktoren der Persönlichkeit: Extraversion, Verträglichkeit, Ängstlichkeit, Gewissenhaftigkeit und Offenheit für neue Erfahrungen.<br/><br/>Im Folgenden sind die fünf Dimensionen inhaltlich näher beschrieben und Ihre persönlichen Werte auf den Dimensionen angegeben. Die Vergleichsstichprobe umfasste 391 Personen (größtenteils Studierende einer deutschen Universität) mit einem Durchschnittsalter zwischen 17 und 31 Jahren.</span></p><p class="P5"> </p><p class="P5"> </p><p class="P3"><span class="T5">Extraversion (vs. Introversion):</span><span class="T3"> Extravertierte Personen sind eher gesellig, aktiv, gesprächig,<br/>personenorientiert, optimistisch, heiter, lieben Aufregung und gehen aus sich heraus. Wenig extravertierte (d.h. introvertierte) Personen sind dagegen eher zurückhaltend, können gut allein sein, sind reserviert, bleiben im Hintergrund und meiden Aufregung und große Gruppen.</span></p><p class="P5"> </p><p class="P3"><span class="T3">(4.1)

Der von Ihnen erzielte Wert auf der Dimension </span><span class="T2">„Extraversion“</span><span class="T3"> entspricht einer

<em><?
$items = array("BFI6_E", "BFI16_E");
$reverseitems = array("BFI1_Erev", "BFI11_Erev");
$normalvalues = getvalues(getvpncode(),$items);
$recodevalues = getvalues(getvpncode(),$reverseitems);
$recodestep1 = recodevalues($recodevalues,multiply,-1);
$recodestep2 = recodevalues($recodestep1,add,6);
$correctedvalues = array_merge($normalvalues, $recodestep2);
if (DEBUG) {
	echo "<br />Normal Items are: ";
	array_walk($items, "myPrint");
	echo "<br />Values of normal items are: ";
	array_walk($normalvalues, "myPrint");
	echo "<br />Reverse-coded items are: ";
	array_walk($reverseitems, "myPrint");
	echo "<br />Values of reverse-coded items are: ";
	array_walk($recodevalues, "myPrint");
	echo "<br />Corrected values of reverse-coded items are: ";
	array_walk($recodestep2, "myPrint");
	echo "<br />";
}
echo zvaluetoword(getzvalue($correctedvalues, 3.59,0.87));
?></em>

Ausprägung der Eigenschaft.</span></p><p class="P9"> </p><p class="P3"><span class="T5">Verträglichkeit:</span><span class="T3"> Verträgliche Personen sind eher umgänglich, altruistisch, verständnisvoll, wohlwollend, einfühlsam, hilfsbereit, harmoniebedürftig, kooperativ, nachgiebig, passiv, mitfühlend und gutmütig. Unverträgliche Personen sind eher wetteifernd, rivalisierend, widerspenstig, kritisch, misstrauisch,<br/>aggressiv, skeptisch, und unsentimental.</span></p><p class="P5"> </p><p class="P3"><span class="T3">(4.2) Der von Ihnen erzielte Wert auf der Dimension </span><span class="T2">„Verträglichkeit“ </span><span class="T3">entspricht einer

<em><?
$items = array("BFI7_A");
$reverseitems = array("BFI12_Arev", "BFI17_Arev", "BFI2_Arev");
$normalvalues = getvalues(getvpncode(),$items);
$recodevalues = getvalues(getvpncode(),$reverseitems);
$recodestep1 = recodevalues($recodevalues,multiply,-1);
$recodestep2 = recodevalues($recodestep1,add,6);
$correctedvalues = array_merge($normalvalues, $recodestep2);
if (DEBUG) {
	echo "<br />Normal Items are: ";
	array_walk($items, "myPrint");
	echo "<br />Values of normal items are: ";
	array_walk($normalvalues, "myPrint");
	echo "<br />Reverse-coded items are: ";
	array_walk($reverseitems, "myPrint");
	echo "<br />Values of reverse-coded items are: ";
	array_walk($recodevalues, "myPrint");
	echo "<br />Corrected values of reverse-coded items are: ";
	array_walk($recodestep2, "myPrint");
	echo "<br />";
}
echo zvaluetoword(getzvalue($correctedvalues, 2.89,0.77));
?></em>


Ausprägung der Eigenschaft.</span></p><p class="P9"> </p><p class="P3"><span class="T5">Ängstlichkeit (vs. Zuversichtlichkeit):</span><span class="T3"> Ängstliche Personen sind leicht beunruhigt, emotional sensibel, eher nervös, neigen zu Ängsten und Traurigkeit, fühlen sich häufiger unsicher oder verlegen und sind um ihre Gesundheit besorgt. Zuversichtliche Personen sind eher belastbar, entspannt, ruhig, unempfindlich, sorgenfrei, meist ausgeglichen, durch nichts aus der Ruhe zu bringen und haben wenige subjektive körperliche Beschwerden.</span></p><p class="P5"> </p><p class="P3"><span class="T3">(4.3) 

Der von Ihnen erzielte Wert auf der Dimension </span><span class="T2">„Ängstlichkeit“</span><span class="T3"> entspricht einer 

<em><?
$items = array("BFI4_N", "BFI14_N", "BFI19_N");
$reverseitems = array( "BFI9_Nrev");
$normalvalues = getvalues(getvpncode(),$items);
$recodevalues = getvalues(getvpncode(),$reverseitems);
$recodestep1 = recodevalues($recodevalues,multiply,-1);
$recodestep2 = recodevalues($recodestep1,add,6);
$correctedvalues = array_merge($normalvalues, $recodestep2);
if (DEBUG) {
	echo "<br />Normal Items are: ";
	array_walk($items, "myPrint");
	echo "<br />Values of normal items are: ";
	array_walk($normalvalues, "myPrint");
	echo "<br />Reverse-coded items are: ";
	array_walk($reverseitems, "myPrint");
	echo "<br />Values of reverse-coded items are: ";
	array_walk($recodevalues, "myPrint");
	echo "<br />Corrected values of reverse-coded items are: ";
	array_walk($recodestep2, "myPrint");
	echo "<br />";
}
echo zvaluetoword(getzvalue($correctedvalues, 3.12,0.92));
?></em>

Ausprägung der Eigenschaft.</span></p><p class="P5"> </p><p class="P3"><span class="T5">Gewissenhaftigkeit: </span><span class="T3">Gewissenhafte Personen sind eher diszipliniert, zuverlässig, pünktlich, ordentlich, pedantisch, penibel, zielstrebig, und anspruchsvoll. Wenig gewissenhafte Personen sind eher unbeschwert, nachlässig, locker, gleichgültig, unzuverlässig, unbeständig, unsystematisch, und handeln ungeplant.</span></p><p class="P5"> </p><p class="P3"><span class="T3">(4.4) Der von Ihnen erzielte Wert auf der Dimension </span><span class="T2">„Gewissenhaftigkeit“</span><span class="T3"> entspricht einer

<em><?
$items = array("BFI3_C", "BFI13_C", "BFI18_C");	
$reverseitems = array( "BFI8_Crev");
$normalvalues = getvalues(getvpncode(),$items);
$recodevalues = getvalues(getvpncode(),$reverseitems);
$recodestep1 = recodevalues($recodevalues,multiply,-1);
$recodestep2 = recodevalues($recodestep1,add,6);
$correctedvalues = array_merge($normalvalues, $recodestep2);
if (DEBUG) {
	echo "<br />Normal Items are: ";
	array_walk($items, "myPrint");
	echo "<br />Values of normal items are: ";
	array_walk($normalvalues, "myPrint");
	echo "<br />Reverse-coded items are: ";
	array_walk($reverseitems, "myPrint");
	echo "<br />Values of reverse-coded items are: ";
	array_walk($recodevalues, "myPrint");
	echo "<br />Corrected values of reverse-coded items are: ";
	array_walk($recodestep2, "myPrint");
	echo "<br />";
}
echo zvaluetoword(getzvalue($correctedvalues, 3.52,0.73));
?></em>

Ausprägung der Eigenschaft.</span></p><p class="P9"> </p><p class="P3"><span class="T5">Offenheit für Erfahrungen:</span><span class="T3"> Offene Personen sind eher wortgewandt, phantasievoll, aufgeschlossen für<br/>neue Ideen, politisch liberal, kreativ, experimentierfreudig, vielfältig interessiert, intellektuell und kultiviert. Personen mit einer geringen Ausprägung auf dieser Dimension lieben Fakten, bleiben beim<br/>Bekannten und Altbewährten, sind eher bodenständig, konventionell, politisch konservativ, traditionsbewusst, sachlich, realistisch und festgelegt in der Art, wie sie etwas unternehmen.</span></p><p class="P5"> </p><p class="P3"><span class="T3">

(4.5) Der von Ihnen erzielte Wert auf der Dimension </span><span class="T2">„Offenheit für Erfahrungen“</span><span class="T3"> entspricht einer

<em><?
$items = array("BFI5_O", "BFI10_O", "BFI15_O", "BFI20_O");	
$reverseitems = array("BFI21_Orev");
$normalvalues = getvalues(getvpncode(),$items);
$recodevalues = getvalues(getvpncode(),$reverseitems);
$recodestep1 = recodevalues($recodevalues,multiply,-1);
$recodestep2 = recodevalues($recodestep1,add,6);
$correctedvalues = array_merge($normalvalues, $recodestep2);
if (DEBUG) {
	echo "<br />Normal Items are: ";
	array_walk($items, "myPrint");
	echo "<br />Values of normal items are: ";
	array_walk($normalvalues, "myPrint");
	echo "<br />Reverse-coded items are: ";
	array_walk($reverseitems, "myPrint");
	echo "<br />Values of reverse-coded items are: ";
	array_walk($recodevalues, "myPrint");
	echo "<br />Corrected values of reverse-coded items are: ";
	array_walk($recodestep2, "myPrint");
	echo "<br />";
}
echo zvaluetoword(getzvalue($correctedvalues, 4.02,0.64));
?></em>

Ausprägung der Eigenschaft.</span></p><p class="P9"> </p><p class="P3"><span class="T6">Ihr Selbstwertgefühl:</span></p><p class="P5"> </p><p class="P3"><span class="T3">Anhand des von Ihnen ausgefüllten Fragebogens, lässt sich eine Person mit hohem Selbstwert folgendermaßen charakterisieren: Hat Selbstrespekt, erachtet sich als wertvoll, erkennt eigene Verdienste an und erkennt auch eigene Fehler. Entsprechend zeichnet eine Person mit niedrigem Selbstwert sich aus durch: Wenig Selbstrespekt, erachtet sich selbst als unwürdig, unangemessen, oder als in anderer Weise ernsthaft unzureichend.</span></p><p class="P5"> </p><p class="P3"><span class="T3">

(4.6) Der von Ihnen erzielte Wert auf der Dimension </span><span class="T2">„Selbstwertgefühl“</span><span class="T3"> entspricht einer

<em><?
$items = array("RSE1", "RSE3", "RSE4", "RSE7", "RSE10");	
$reverseitems = array("RSE2rev", "RSE5rev", "RSE6rev", "RSE8rev", "RSE9rev");
$normalvalues = getvalues(getvpncode(),$items);
$recodevalues = getvalues(getvpncode(),$reverseitems);
$recodestep1 = recodevalues($recodevalues,multiply,-1);
$recodestep2 = recodevalues($recodestep1,add,7);
$correctedvalues = array_merge($normalvalues, $recodestep2);
if (DEBUG) {
	echo "<br />Normal Items are: ";
	array_walk($items, "myPrint");
	echo "<br />Values of normal items are: ";
	array_walk($normalvalues, "myPrint");
	echo "<br />Reverse-coded items are: ";
	array_walk($reverseitems, "myPrint");
	echo "<br />Values of reverse-coded items are: ";
	array_walk($recodevalues, "myPrint");
	echo "<br />Corrected values of reverse-coded items are: ";
	array_walk($recodestep2, "myPrint");
	echo "<br />";
}
echo zvaluetoword(getzvalue($correctedvalues, 4.92,0.82));
?></em>

Ausprägung der Eigenschaft. Die Vergleichsstichprobe umfasste 4988 Personen, die an einer repräsentativen Haushaltsumfrage in Deutschland teilgenommen haben. Das Durchschnittsalter lag zwischen 30 und 66 Jahren (Gesamtaltersspanne: 14-92 Jahre).</span></p><br /><br />



<?
echo "</td></tr></table>";

// schließe main-div
echo "</div>\n";
// binde Navigation ein
require ('includes/navigation.php');
// schließe Datenbank-Verbindung, füge bei Bedarf Analytics ein
require('includes/footer.php');
?>
