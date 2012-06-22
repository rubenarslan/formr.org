<?
 
require ('settings.php');
require ('mysql.php');
require('variables.php');	
require ('functions.php');

$partner = $_GET['partner'];
$data = $_GET['data'];
$vpncode = $_GET['vpncode'];
$vexed = $_GET['vexed'];
$attachment = $_GET['attachment'];

$pre_post_coordinates = array(
	"Extra_per" => array(
		"1" => array("x" => 193, "y" => 75),
		"2" => array("x" => 263, "y" => 75),
		"3" => array("x" => 357, "y" => 75),
		"4" => array("x" => 450, "y" => 75),
		"5" => array("x" => 520, "y" => 75)),
	"Vertr_per" => array(
		"1" => array("x" => 193, "y" => 136),
		"2" => array("x" => 263, "y" => 136),
		"3" => array("x" => 357, "y" => 136),
		"4" => array("x" => 450, "y" => 136),
		"5" => array("x" => 529, "y" => 136)),
	"Gewiss_per" => array(
		"1" => array("x" => 193, "y" => 198),
		"2" => array("x" => 263, "y" => 198),
		"3" => array("x" => 357, "y" => 198),
		"4" => array("x" => 450, "y" => 198),
		"5" => array("x" => 529, "y" => 198)),
	"Neuro_per" => array(
		"1" => array("x" => 193, "y" => 260),
		"2" => array("x" => 263, "y" => 260),
		"3" => array("x" => 357, "y" => 260),
		"4" => array("x" => 450, "y" => 260),
		"5" => array("x" => 529, "y" => 260)),
	"Offen_per" => array(
		"1" => array("x" => 193, "y" => 324),
		"2" => array("x" => 263, "y" => 324),
		"3" => array("x" => 357, "y" => 324),
		"4" => array("x" => 450, "y" => 324),
		"5" => array("x" => 529, "y" => 324)));

// thing is 282px high
$study_coordinates = array(
	//positive values
	"positive" => array(
		// position
		"0" => array("x" => 200, "y" => 352),
		"1" => array("x" => 240, "y" => 352),
		"2" => array("x" => 280, "y" => 352),
		"3" => array("x" => 320, "y" => 352),
		"4" => array("x" => 360, "y" => 352),
		"5" => array("x" => 400, "y" => 352),
		"6" => array("x" => 440, "y" => 352),
		"7" => array("x" => 480, "y" => 352),
		"8" => array("x" => 520, "y" => 352),
		"9" => array("x" => 560, "y" => 352),
		"10" => array("x" => 600, "y" => 352),
		"11" => array("x" => 640, "y" => 352),
		"12" => array("x" => 680, "y" => 352),
		"13" => array("x" => 720, "y" => 352),
		"14" => array("x" => 760, "y" => 352),
		"15" => array("x" => 800, "y" => 352),
		"16" => array("x" => 840, "y" => 352),
		"17" => array("x" => 880, "y" => 352),
		"18" => array("x" => 920, "y" => 352),
		"19" => array("x" => 960, "y" => 352)),
	"negative" => array(
		// position
		"0" => array("x" => 200, "y" => 777),
		"1" => array("x" => 240, "y" => 777),
		"2" => array("x" => 280, "y" => 777),
		"3" => array("x" => 320, "y" => 777),
		"4" => array("x" => 360, "y" => 777),
		"5" => array("x" => 400, "y" => 777),
		"6" => array("x" => 440, "y" => 777),
		"7" => array("x" => 480, "y" => 777),
		"8" => array("x" => 520, "y" => 777),
		"9" => array("x" => 560, "y" => 777),
		"10" => array("x" => 600, "y" => 777),
		"11" => array("x" => 640, "y" => 777),
		"12" => array("x" => 680, "y" => 777),
		"13" => array("x" => 720, "y" => 777),
		"14" => array("x" => 760, "y" => 777),
		"15" => array("x" => 800, "y" => 777),
		"16" => array("x" => 840, "y" => 777),
		"17" => array("x" => 880, "y" => 777),
		"18" => array("x" => 920, "y" => 777),
		"19" => array("x" => 960, "y" => 777)));


switch ($data) {
	case "pretest":
		pre_post_feedback($vpncode,$pre_post_coordinates,$data,$partner,$attachment);
		break;
	case "posttest":
		pre_post_feedback($vpncode,$pre_post_coordinates,$data,$partner,$attachment);
		break;
	case "study":
		study_feedback($vpncode,$vexed,$study_coordinates,$data,$attachment);
		break;
}

?>
