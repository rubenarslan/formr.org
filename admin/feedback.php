<?
require ('admin_header.php');
/*
	feedback.php - graphical feedback output generator
*/

// Öffne Datenbank, mache ordentlichen Header, binde Stylesheets, Scripts ein
require ('includes/header.php');

// Endet mit </html>
require ('includes/design.php');
// macht das ganze Klickibunti, endet mit <div id="main"

function imagelinethick($image, $x1, $y1, $x2, $y2, $color, $thick = 1)
{
    if ($thick == 1) {
        return imageline($image, $x1, $y1, $x2, $y2, $color);
    }
    $t = $thick / 2 - 0.5;
    if ($x1 == $x2 || $y1 == $y2) {
        return imagefilledrectangle($image, round(min($x1, $x2) - $t), round(min($y1, $y2) - $t), round(max($x1, $x2) + $t), round(max($y1, $y2) + $t), $color);
    }
    $k = ($y2 - $y1) / ($x2 - $x1); //y = kx + q
    $a = $t / sqrt(1 + pow($k, 2));
    $points = array(
        round($x1 - (1+$k)*$a), round($y1 + (1-$k)*$a),
        round($x1 - (1-$k)*$a), round($y1 - (1+$k)*$a),
        round($x2 + (1+$k)*$a), round($y2 - (1-$k)*$a),
        round($x2 + (1-$k)*$a), round($y2 + (1+$k)*$a),
    );
    imagefilledpolygon($image, $points, 4, $color);
    return imagepolygon($image, $points, 4, $color);
}

$impath = "images/image.png";

$bg_path = "images/feedback-bg.png";
$bg_size = getimagesize($bg_path);

$img_size = array(700,$bg_size[1]);
$img = imagecreatetruecolor($img_size[0],$img_size[1]);

//doesn't work???? ubuntu doesn't ship bundled php5, so will probably not be available on servers either
//imageantialias($img,true);

// load the bg image bg
$bg_image = ImageCreateFromPNG($bg_path);

// copy from the background image so we don't have to do everything manually
imagecopy(
	$img,
	$bg_image,
	($img_size[0] - $bg_size[0]) / 2, 
	0,
	0,0,$bg_size[0],$bg_size[1]);

//constants
define('Extra_M',3.48);
define('Extra_SD',0.87);
define('Vertr_M',3.02);
define('Vertr_SD',0.73);
define('Gewiss_M',3.53);
define('Gewiss_SD',0.69);
define('Neuro_M',2.88);
define('Neuro_SD',0.77);
define('Offen_M',3.96);
define('Offen_SD',0.62);

//Extraversion: 
define('Extra_per',(
	(6- $result['Extra1_E_pre'])+ $result['Extra2_E_pre'] + 
	(6- $result['Extra3_E_pre'])+ $result['Extra4_E_pre']) / 4);

	//Verträglichkeit: 
define('Vertr_per',(
	(6- $result['Vertr1_E_pre'])+ $result['Vertr2_E_pre'] + 
	(6- $result['Vertr3_E_pre'])+(6- $result['Vertr4_E_pre'])) / 4);

	//Gewissenhaftigkeit: 
define('Gewiss_per', ( $result['Gewiss1_E_pre'] + 
	(6 - $result['Gewiss2_E_pre']) + $result['Gewiss3_E_pre'] + 
	$result['Gewiss4_E_pre']) / 4);
	
	//Emotionale Ansprechbarkeit: 
define('Neuro_per',( $result['Neuro1_E_pre'] + (6 - $result['Neuro2_E_pre'] ) + 
	$result['Neuro3_E_pre'] + $result['Neuro4_E_pre']) / 4);

	//Offenheit für Erfahrungen: 
define('Offen_per',( $result['Offen1_E_pre'] + $result['Offen2_E_pre'] + 
	$result['Offen3_E_pre'] + $result['Offen4_E_pre'] + 
	(6 - $result['Offen5_E_pre'])) / 5);

// extraversion
if( Extra_per <= Extra_M - (2 * Extra_SD)) { 
	$extraversion = 0; // sehr niedrig
} elseif( (Extra_per > Extra_M - (2 * Extra_SD)) AND (Extra_per <= Extra_M - Extra_SD) ) {
	$extraversion = 1; // niegrig
} elseif( (Extra_per > Extra_M - Extra_SD)  AND (Extra_per < Extra_M + Extra_SD) ) {
	$extraversion = 2; // durchschnittlich
} elseif( (Extra_per >= Extra_M + Extra_SD) AND (Extra_per < Extra_M + (2 * Extra_SD)) ) {
	$extraversion = 3; // hoch 
} elseif( (Extra_per >= Extra_M + (2 * Extra_SD)) ) {
	$extraversion = 4; // sehr hoch
}

// verträglichkeit
if( Vertr_per <= Vertr_M - (2 * Vertr_SD) ) {
	$vertraeglichkeit = 0; // sehr niedrig
} elseif( (Vertr_per > Vertr_M - (2 * Vertr_SD)) AND (Vertr_per <= Vertr_M - Vertr_SD) ) {
	$vertraeglichkeit = 1; // niedrig
} elseif( (Vertr_per > Vertr_M - Vertr_SD)  AND (Vertr_per < Vertr_M + Vertr_SD) ) {
	$vertraeglichkeit = 2; // durchschnittlich
} elseif( (Vertr_per >= Vertr_M + Vertr_SD) AND (Vertr_per < Vertr_M + 2 * Vertr_SD) ) {
	$vertraeglichkeit = 3; // hoch 
} elseif( (Vertr_per >= Vertr_M + (2 * Vertr_SD)) ) {
	$vertraeglichkeit = 4; // sehr hoch 
}

// gewissenhaftigkeit
if( (Gewiss_per<= Gewiss_M - (2 * Gewiss_SD)) ) {
	$gewissenhaftigkeit = 0;
} elseif( (Gewiss_per > Gewiss_M - (2 * Gewiss_SD)) AND (Gewiss_per <= Gewiss_M - Gewiss_SD) ) {
	$gewissenhaftigkeit = 1;
} elseif( (Gewiss_per > (Gewiss_M - Gewiss_SD))  AND (Gewiss_per < (Gewiss_M + Gewiss_SD)) ) {
	$gewissenhaftigkeit = 2;
} elseif( (Gewiss_per >= (Gewiss_M + Gewiss_SD)) AND (Gewiss_per < (Gewiss_M + (2 * Gewiss_SD))) ) {
	$gewissenhaftigkeit = 3;
} elseif( (Gewiss_per >= (Gewiss_M + (2 * Gewiss_SD))) ) {
	$gewissenhaftigkeit = 4;
}

// emotionale ansprechbarkeit
if( (Neuro_per <= Neuro_M - (2 * Neuro_SD)) ) {
	$emotionalitaet = 0;
} elseif( (Neuro_per > Neuro_M - (2 * Neuro_SD)) AND (Neuro_per <= Neuro_M - Neuro_SD) ) {
	$emotionalitaet = 1;
} elseif( (Neuro_per > Neuro_M - Neuro_SD)  AND (Neuro_per < Neuro_M + Neuro_SD) ) {
	$emotionalitaet = 2;
} elseif( (Neuro_per >= Neuro_M + Neuro_SD) AND (Neuro_per < Neuro_M + (2 * Neuro_SD)) ) {
	$emotionalitaet = 3;
} elseif( (Neuro_per >= Neuro_M + (2 * Neuro_SD)) ) {
	$emotionalitaet = 4;
}

// offen fuer neue erfahrungen
if( (Offen_per <= Offen_M - (2 * Offen_SD)) ) {
	$offenheit = 0;
} elseif( (Offen_per > Offen_M - (2 * Offen_SD)) AND (Offen_per <= Offen_M - Offen_SD) ) {
	$offenheit = 1;
} elseif( (Offen_per > Offen_M - Offen_SD)  AND (Offen_per < Offen_M + Offen_SD) ) {
	$offenheit = 2;
} elseif( (Offen_per >= Offen_M + Offen_SD) AND (Offen_per < Offen_M + (2 * Offen_SD))  ) {
	$offenheit = 3;
} elseif( (Offen_per >= Offen_M + (2 * Offen_SD)) ) {
	$offenheit = 4;
}

$line_color = imagecolorallocate($img,0,0,255);

// locations in pixels for start/end of lines corresponding to  the different questions
$extraversion_loc = array(array(185,68), array(257,68), array(360,68), array(442,68), array(515,68));
$vertraeglichkeit_loc = array(array(185,130), array(257,130), array(360,130), array(442,130), array(515,130));
$gewissenhaftigkeit_loc = array(array(185,192), array(257,192), array(360,192), array(442,192), array(515,192));
$emtionalitaet_loc = array(array(185,254), array(257,254), array(360,254), array(442,254), array(515,254));
$offenheit_loc = array(array(185,316), array(257,316), array(360,316), array(442,316), array(515,316));
//linie da drunter 185x348	257x348	360x348	442x348	515x348

imagelinethick($img,
	$extraversion_loc[$extraversion][0],
	$extraversion_loc[$extraversion][1],
	$vertraeglichkeit_loc[$vertraeglichkeit][0],
	$vertraeglichkeit_loc[$vertraeglichkeit][1],
	$line_color,2);

imagelinethick($img,
	$vertraeglichkeit_loc[$vertraeglichkeit][0],
	$vertraeglichkeit_loc[$vertraeglichkeit][1],
	$gewissenhaftigkeit_loc[$gewissenhaftigkeit][0],
	$gewissenhaftigkeit_loc[$gewissenhaftigkeit][1],
	$line_color,2);

imagelinethick($img,
	$gewissenhaftigkeit_loc[$gewissenhaftigkeit][0],
	$gewissenhaftigkeit_loc[$gewissenhaftigkeit][1],
	$emtionalitaet_loc[$emotionalitaet][0],
	$emtionalitaet_loc[$emotionalitaet][1],
	$line_color,2);

imagelinethick($img,
	$emtionalitaet_loc[$emotionalitaet][0],
	$emtionalitaet_loc[$emotionalitaet][1],
	$offenheit_loc[$offenheit][0],
	$offenheit_loc[$offenheit][1],
	$line_color,2);

// start buffering
ob_start();
imagepng($img);
$contents =  ob_get_contents();
ob_end_clean();

echo "<img src='data:image/png;base64,".base64_encode($contents)."' />";
imagedestroy( $img );	

// schließe main-div
echo "</div>\n";
// binde Navigation ein
require ('includes/navigation.php');
// schließe Datenbank-Verbindung, füge bei Bedarf Analytics ein
require('includes/footer.php');
?>