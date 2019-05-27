<?php

Template::loadChild('header');
Template::loadChild('acp_nav');

$openCPU = new OpenCPU(Config::get('alternative_opencpu_instance'));

echo "<h2>OpenCPU test</h2>";
echo '<h5>testing ' . Config::get('alternative_opencpu_instance') . '</h5>';

$max = 30;
for ($i = 0; $i < $max; $i++):
    $openCPU->clearUserData();
    $source = '{' .
            mt_rand() . '
		' .
            str_repeat(" ", $i) .
            '
		library(knitr)
		knit2html(text = "' . addslashes("__Hello__ World `r 1`
		```{r}
		library(ggplot2)  
		qplot(rnorm(100))
		qplot(rnorm(1000), rnorm(1000))
		library(formr)
		'blabla' %contains% 'bla'
		```
		") . '",
		fragment.only = T, options=c("base64_images","smartypants")
		)
		' .
            str_repeat(" ", $max - $i) .
            '
	}';

    $start_time = microtime(true);
    $results = $openCPU->identity(array('x' => $source), '', true);
    $responseHeaders = $openCPU->responseHeaders();

    $alert_type = 'alert-success';
    if ($openCPU->http_status > 302 || $openCPU->http_status === 0) {
        $alert_type = 'alert-danger';
    }

    alert('1. HTTP status: ' . $openCPU->http_status, $alert_type);

    $accordion = $openCPU->debugCall($results);
    $responseHeaders['total_time_php'] = round(microtime(true) - $start_time, 3);

    if (isset($times)):
        $times['total_time'][] = $responseHeaders['total_time'];
        $times['total_time_php'][] = $responseHeaders['total_time_php'];
    else:
        $times = array();
        $times['total_time'] = array($responseHeaders['total_time']);
        $times['total_time_php'] = array($responseHeaders['total_time_php']);
    endif;

endfor;

$datasets = array('times' => $times);
$source = '
# plot times
```{r}
library(ggplot2)
library(stringr)
library(reshape2)
just_times = times[,str_detect(names(times), "time")]
times_m = melt(just_times)
# qplot(value, data = times_m) + facet_wrap(~ variable)
# just_size = times[,str_detect(names(times),"_size")]
# size_m = melt(just_size)
# qplot(value, data = size_m) + facet_wrap(~ variable)
summary(times)
```';
unset($times['certinfo']);

$openCPU->addUserData(array('datasets' => $datasets));
$accordion = $openCPU->knitForAdminDebug($source);
$alert_type = 'alert-success';

if ($openCPU->http_status > 302 OR $openCPU->http_status === 0) {
    $alert_type = 'alert-danger';
}
alert('1. HTTP status: ' . $openCPU->http_status, $alert_type);

echo $accordion;

$alerts = $site->renderAlerts();
if (!empty($alerts)):
    echo '<div class="row"><div class="col-md-8 all-alerts">';
    echo $alerts;
    echo '</div></div>';
endif;

Template::loadChild('footer');
