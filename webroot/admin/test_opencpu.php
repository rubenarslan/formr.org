<?php
require_once '../../define_root.php';

require_once INCLUDE_ROOT.'View/admin_header.php';


require_once INCLUDE_ROOT . "Model/OpenCPU.php";
$openCPU = new OpenCPU($settings['alternative_opencpu_instance']);

require_once INCLUDE_ROOT . "View/header.php";
require_once INCLUDE_ROOT . "View/acp_nav.php";

echo "<h2>OpenCPU test</h2>";
echo '<h5>testing '.$settings['alternative_opencpu_instance'].'</h5>';

$source = '{

library(knitr)
knit2html(text = "' . addslashes("__Hello__ World `r 1`
```{r}
library(ggplot2)  
qplot(rnorm(100))
qplot(rnorm(1000), rnorm(1000))
```
") . '",
fragment.only = T, options=c("base64_images","smartypants")
)
}';
$before = microtime();
$results = $openCPU->identity(array('x' =>  $source),'', true);
		
if($openCPU->http_status > 302 OR $openCPU->http_status === 0) $alert_type = 'alert-danger';
else $alert_type = 'alert-success';

alert("1. HTTP status: ".$openCPU->http_status,$alert_type);
		
		
$accordion = $openCPU->debugCall($results);

alert("1. Request took " . round(microtime() - $before / 1000 / 60,4) . " minutes", 'alert-info');


$openCPU = new OpenCPU($settings['alternative_opencpu_instance']);
$source = '{
rnorm(10)
}';
$before = microtime();
$results = $openCPU->identity(array('x' =>  $source),'', true);

if($openCPU->http_status > 302 OR $openCPU->http_status === 0) $alert_type = 'alert-danger';
else $alert_type = 'alert-success';
alert("2. HTTP status: ".$openCPU->http_status,$alert_type);
$accordion2 = $openCPU->debugCall($results);
alert("2. Request took " . round(microtime() - $before / 1000 / 60,4) . " minutes", 'alert-info');


$openCPU = new OpenCPU($settings['alternative_opencpu_instance']);
$source = '{
rnorm(10)
}';

$source = '{ 
(function() {

Taeglicher_Fragebogen_1 = as.data.frame(jsonlite::fromJSON("{\"session\":[\"9af46f1515d8ec647f203ede9a58bef66371a2665343d752a46db2365583d3d0\",\"9af46f1515d8ec647f203ede9a58bef66371a2665343d752a46db2365583d3d0\"],\"session_id\":[1923,1928],\"study_id\":[219,219],\"modified\":[\"2014-02-09 19:44:08\",\"2014-02-09 19:45:33\"],\"created\":[\"2014-02-09 19:40:39\",\"2014-02-09 19:44:13\"],\"ended\":[\"2014-02-09 19:44:08\",\"2014-02-09 19:45:33\"],\"zufriedenheit_bez_1\":[6,4],\"zufriedenheit_bez_2\":[1,2],\"geschlechtsverkehr_1\":[\"1, 2\",\"1, 2, 3\"],\"geschlechtsverkehr_2\":[2,2],\"geschlechtsverkehr_3\":[6,3],\"geschlechtsverkehr_4\":[2,3],\"extra_pair_1\":[1,1],\"menstruation_1\":[2,1],\"menstruation_2\":[2,1],\"menstruation_3\":[1,2],\"mate_guarding_1\":[6,6],\"kleiderwahl_1\":[6,3],\"kleiderwahl_2\":[6,3],\"kleiderwahl_3\":[6,3],\"kleiderwahl_4\":[5,3],\"kleiderwahl_5\":[1,3],\"kleiderwahl_6\":[5,3],\"kleiderwahl_7\":[1,3],\"extra_pair\":[6,3],\"attraktivitaet\":[6,3],\"attraktivitaet_partner\":[6,3],\"mate_guarding_2\":[6,3],\"NARQ_self_admiration_1\":[6,3],\"NARQ_self_admiration_2\":[5,3],\"NARQ_self_admiration_3\":[5,3],\"NARQ_rivalry_1\":[5,3],\"NARQ_rivalry_2\":[4,3],\"NARQ_rivalry3\":[4,3],\"eifersucht_1\":[4,3],\"mate_guarding_3\":[4,3],\"eifersucht_2\":[3,3],\"eifersucht_3\":[3,3],\"mate_guarding__4\":[3,3],\"mate_guarding_5_bekundung_liebe_2\":[4,3],\"extra_pair_3\":[3,3],\"mate_guarding_6\":[4,3],\"extra_pair_4\":[3,3],\"extra_pair_5\":[4,3],\"extra_pair_6\":[3,3],\"mate_guarding_7\":[4,3],\"mate_guarding_8\":[3,3],\"aufmerksamkeit_1_mate_guarding_female\":[3,3],\"aufmerksamkeit_2\":[3,3],\"aufmerksamkeit_3_mate_guarding_female\":[3,3],\"extra_pair_7\":[3,3],\"extra_pair_8\":[3,3],\"extra_pair_9\":[2,3],\"extra_pair_10\":[2,3],\"extra_pair_11\":[3,3],\"extra_pair_12\":[3,3],\"extra_pair_13\":[3,3],\"SES\":[3,3],\"besondere_ereignisse\":[\"Nein\",\"nein\"]}"), stringsAsFactors=F);

library(lubridate); 
nrow(Taeglicher_Fragebogen_1) < 20 || 
( as.Date(head(Taeglicher_Fragebogen_1$created, 1)) + days(35) ) < today();
# wenn der taegliche fragebogen seltener als 20 mal und ueber einen kuerzeren zeitraum als 35 tage ausgefuellt wurde, muss er weiter ausgefuellt werden.
})() }';
$before = microtime();
$results = $openCPU->identity(array('x' =>  $source),'', true);

if($openCPU->http_status > 302 OR $openCPU->http_status === 0) $alert_type = 'alert-danger';
else $alert_type = 'alert-success';
alert("3. HTTP status: ".$openCPU->http_status,$alert_type);
$accordion3 = $openCPU->debugCall($results);
alert("3. Request took " . round(microtime() - $before / 1000 / 60,4) . " minutes", 'alert-info');



$source = '{ 
(function() {

Taeglicher_Fragebogen_1 = as.data.frame(jsonlite::fromJSON("{\"session\":[\"9af46f1515d8ec647f203ede9a58bef66371a2665343d752a46db2365583d3d0\",\"9af46f1515d8ec647f203ede9a58bef66371a2665343d752a46db2365583d3d0\"],\"session_id\":[1923,1928],\"study_id\":[219,219],\"modified\":[\"2014-02-09 19:44:08\",\"2014-02-09 19:45:33\"],\"created\":[\"2014-02-09 19:40:39\",\"2014-02-09 19:44:13\"],\"ended\":[\"2014-02-09 19:44:08\",\"2014-02-09 19:45:33\"],\"zufriedenheit_bez_1\":[6,4],\"zufriedenheit_bez_2\":[1,2],\"geschlechtsverkehr_1\":[\"1, 2\",\"1, 2, 3\"],\"geschlechtsverkehr_2\":[2,2],\"geschlechtsverkehr_3\":[6,3],\"geschlechtsverkehr_4\":[2,3],\"extra_pair_1\":[1,1],\"menstruation_1\":[2,1],\"menstruation_2\":[2,1],\"menstruation_3\":[1,2],\"mate_guarding_1\":[6,6],\"kleiderwahl_1\":[6,3],\"kleiderwahl_2\":[6,3],\"kleiderwahl_3\":[6,3],\"kleiderwahl_4\":[5,3],\"kleiderwahl_5\":[1,3],\"kleiderwahl_6\":[5,3],\"kleiderwahl_7\":[1,3],\"extra_pair\":[6,3],\"attraktivitaet\":[6,3],\"attraktivitaet_partner\":[6,3],\"mate_guarding_2\":[6,3],\"NARQ_self_admiration_1\":[6,3],\"NARQ_self_admiration_2\":[5,3],\"NARQ_self_admiration_3\":[5,3],\"NARQ_rivalry_1\":[5,3],\"NARQ_rivalry_2\":[4,3],\"NARQ_rivalry3\":[4,3],\"eifersucht_1\":[4,3],\"mate_guarding_3\":[4,3],\"eifersucht_2\":[3,3],\"eifersucht_3\":[3,3],\"mate_guarding__4\":[3,3],\"mate_guarding_5_bekundung_liebe_2\":[4,3],\"extra_pair_3\":[3,3],\"mate_guarding_6\":[4,3],\"extra_pair_4\":[3,3],\"extra_pair_5\":[4,3],\"extra_pair_6\":[3,3],\"mate_guarding_7\":[4,3],\"mate_guarding_8\":[3,3],\"aufmerksamkeit_1_mate_guarding_female\":[3,3],\"aufmerksamkeit_2\":[3,3],\"aufmerksamkeit_3_mate_guarding_female\":[3,3],\"extra_pair_7\":[3,3],\"extra_pair_8\":[3,3],\"extra_pair_9\":[2,3],\"extra_pair_10\":[2,3],\"extra_pair_11\":[3,3],\"extra_pair_12\":[3,3],\"extra_pair_13\":[3,3],\"SES\":[3,3],\"besondere_ereignisse\":[\"Nein\",\"nein\"]}"), stringsAsFactors=F)

library(lubridate);
nrow(Taeglicher_Fragebogen_1) < 20 || 
( as.Date(head(Taeglicher_Fragebogen_1$created, 1)) + days(35) ) < today()
})() }';
$before = microtime();
$results = $openCPU->identity(array('x' =>  $source),'', true);

if($openCPU->http_status > 302 OR $openCPU->http_status === 0) $alert_type = 'alert-danger';
else $alert_type = 'alert-success';
alert("4. HTTP status: ".$openCPU->http_status,$alert_type);
$accordion4 = $openCPU->debugCall($results);
alert("4. Request took " . round(microtime() - $before / 1000 / 60,4) . " minutes", 'alert-info');



$alerts = $site->renderAlerts();
if(!empty($alerts)):
	echo '<div class="row"><div class="col-md-8 all-alerts">';
	echo $alerts;
	echo '</div></div>';
endif;

echo "<h4>test knitr with plot</h4>";

echo $accordion;

echo "<h4>test simple function</h4>";

echo $accordion2;

echo "<h4>test long call</h4>";

echo $accordion3;

echo "<h4>test long call without final comment</h4>";

echo $accordion4;