<?
require_once 'define_root.php';
require_once INCLUDE_ROOT . "Model/Site.php";
require_once INCLUDE_ROOT . "Model/OpenCPU.php";

$head = '
<script type="text/javascript" src="'.WEBROOT.'js/vendor/knitr.js"></script>
<link rel="stylesheet" href="'.WEBROOT.'css/knitr.css" type="text/css">';

require_once INCLUDE_ROOT . 'view_header.php';
	echo $site->renderAlerts();

	$openCPU = new OpenCPU();
	/*$openCPU->addUserData(
		array( 1 =>
			array('id' => 1, 'perso1' => 2, 'perso2' => 3),
			   2 =>
			array('id' => 2, 'perso1' => 3, 'perso2' => 1)
		));
			   */
	$openCPU->addUserData(
			array( 'id' => array(1, 2, 3),
				   'perso1' => array(2, 3,2),
				   'perso2' => array(3,1,2),
				   'utf' => array('öüä','""','\'')
				   )
			   );
	echo $openCPU->knitForUserDisplay('
```{r data}
user_data=data.frame(first_name=rep("Petunia",times=50),mood1 = rnorm(50), mood2 = rnorm(50), mood3=rnorm(50), Day = 1:50)
```
Hi `r user_data[1,]$first_name`!

This is a graph showing how **your mood** fluctuated across the 50 days that you filled out our diary.

### Graph

```{r mood.plot}
library(ggplot2)
user_data=data.frame(first_name=rep("Petunia",times=50),mood1 = rnorm(50), mood2 = rnorm(50), mood3=rnorm(50), Day = 1:50)
	
user_data$mood <- rowSums(user_data[,c("mood1","mood2","mood3")])
qplot(Day, mood, data = user_data) + geom_smooth() + scale_y_continuous("Your mood") + theme_bw()
```
');

require_once INCLUDE_ROOT . 'view_footer.php';
