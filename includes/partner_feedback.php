<?
 
require ('settings.php');
require ('mysql.php');
require('variables.php');	
require ('functions.php');

$vpncode_a = $_GET['vpncode'];
$partner_a = get_vpn_data($vpncode_a);

$vpncode_b = $partner_a->partnercode;
$partner_b = get_vpn_data($vpncode_b);

$study_a = $partner_a->study;
$study_b = $partner_b->study;

$coordinates_a = array(
	"Extra_per" => array(
		"1" => array("x" => , "y" => ),
		"2" => array("x" => , "y" => ),
		"3" => array("x" => , "y" => ),
		"4" => array("x" => , "y" => ),
		"5" => array("x" => , "y" => )),
	"Vertr_per" => array(
		"1" => array("x" => , "y" => ),
		"2" => array("x" => , "y" => ),
		"3" => array("x" => , "y" => ),
		"4" => array("x" => , "y" => ),
		"5" => array("x" => , "y" => )),
	"Gewiss_per" => array(
		"1" => array("x" => , "y" => ),
		"2" => array("x" => , "y" => ),
		"3" => array("x" => , "y" => ),
		"4" => array("x" => , "y" => ),
		"5" => array("x" => , "y" => )),
	"Neuro_per" => array(
		"1" => array("x" => , "y" => ),
		"2" => array("x" => , "y" => ),
		"3" => array("x" => , "y" => ),
		"4" => array("x" => , "y" => ),
		"5" => array("x" => , "y" => )),
	"Offen_per" => array(
		"1" => array("x" => , "y" => ),
		"2" => array("x" => , "y" => ),
		"3" => array("x" => , "y" => ),
		"4" => array("x" => , "y" => ),
		"5" => array("x" => , "y" => )));

$coordinates_b = array(
	"Extra_per" => array(
		"1" => array("x" => , "y" => ),
		"2" => array("x" => , "y" => ),
		"3" => array("x" => , "y" => ),
		"4" => array("x" => , "y" => ),
		"5" => array("x" => , "y" => )),
	"Vertr_per" => array(
		"1" => array("x" => , "y" => ),
		"2" => array("x" => , "y" => ),
		"3" => array("x" => , "y" => ),
		"4" => array("x" => , "y" => ),
		"5" => array("x" => , "y" => )),
	"Gewiss_per" => array(
		"1" => array("x" => , "y" => ),
		"2" => array("x" => , "y" => ),
		"3" => array("x" => , "y" => ),
		"4" => array("x" => , "y" => ),
		"5" => array("x" => , "y" => )),
	"Neuro_per" => array(
		"1" => array("x" => , "y" => ),
		"2" => array("x" => , "y" => ),
		"3" => array("x" => , "y" => ),
		"4" => array("x" => , "y" => ),
		"5" => array("x" => , "y" => )),
	"Offen_per" => array(
		"1" => array("x" => , "y" => ),
		"2" => array("x" => , "y" => ),
		"3" => array("x" => , "y" => ),
		"4" => array("x" => , "y" => ),
		"5" => array("x" => , "y" => )));

$values_a = get_feedback_data($vpncode_a,$study_a);
$values_b = get_feedback_data($vpncode_b,$study_b);

$Extra_per_a = ((6 - $values_a['Extra1_E_pre']) + $values_a['Extra2_E_pre'] + (6 - $values_a['Extra3_E_pre']) + $values_a['Extra4_E_pre']) / 4;
$Extra_per_b = ((6 - $values_b['Extra1_E_pre']) + $values_b['Extra2_E_pre'] + (6 - $values_b['Extra3_E_pre']) + $['Extra4_E_pre']) / 4;

$Vertr_per_a = ((6 - $values_a['Vertr1_E_pre']) + $values_a['Vertr2_E_pre'] + (6 - $values_a['Vertr3_E_pre']) + (6 - $values_a['Vertr4_E_pre'])) / 4;
$Vertr_per_b = ((6 - $values_b['Vertr1_E_pre']) + $values_b['Vertr2_E_pre'] + (6 - $values_b['Vertr3_E_pre']) + (6 - $values_b['Vertr4_E_pre'])) / 4;

$Gewiss_per_a = ($values_a['Gewiss1_E_pre'] + (6 - $values_a['Gewiss2_E_pre']) + $values_a['Gewiss3_E_pre'] + $values_a['Gewiss4_E_pre']) / 4;
$Gewiss_per_b = ($values_b['Gewiss1_E_pre'] + (6 - $values_b['Gewiss2_E_pre']) + $values_b['Gewiss3_E_pre'] + $values_b['Gewiss4_E_pre']) / 4;

$Neuro_per_a = ($values_a['Neuro1_E_pre'] + (6 - $values_a['Neuro2_E_pre']) + $values_a['Neuro3_E_pre'] + $values_a['Neuro4_E_pre']) / 4;
$Neuro_per_b = ($values_b['Neuro1_E_pre'] + (6 - $values_b['Neuro2_E_pre']) + $values_b['Neuro3_E_pre'] + $values_b['Neuro4_E_pre']) / 4;

$Offen_per_a = ($values_a['Offen1_E_pre'] + $values_a['Offen2_E_pre'] + $values_a['Offen3_E_pre'] + $values_a['Offen4_E_pre'] + (6 - $values_a['Offen5_E_pre'])) / 5;
$Offen_per_b = ($values_b['Offen1_E_pre'] + $values_b['Offen2_E_pre'] + $values_b['Offen3_E_pre'] + $values_b['Offen4_E_pre'] + (6 - $values_b['Offen5_E_pre'])) / 5;

header("Content-type: image/png");
$im = imagecreatefrompng("images/feedback_template2.png");
$color = imagecolorallocate($im,0,0,255);
imageantialias($im,true);

draw_feedback($im, $color, array(
	"coordinates" => $coordinates_a, 
	"extraversion" => get_extraversion($Extra_per_a)
	"compatibility" => get_compatibility($Vertr_per_a),
	"diligence" => get_diligence($Gewiss_per_a),
	"emotional" => get_emotional($Neuro_per_a),
	"openess" => get_openess($Offen_per_a)));

draw_feedback($im, $color, array(
	"coordinates" => $coordinates_b, 
	"extraversion" => get_extraversion($Extra_per_b),
	"compatibility" => get_compatibility($Vertr_per_b),
	"diligence" => get_diligence($Gewiss_per_b),
	"emotional" => get_emotional($Neuro_per_b),
	"openess" => get_openess($Offen_per_b)));

imagepng($im);
imagedestroy($im);
?>
