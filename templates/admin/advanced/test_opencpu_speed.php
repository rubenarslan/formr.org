<?php

Template::loadChild('admin/header');

// Get the OpenCPU instance
$openCPU = OpenCPU::getInstance();

echo "<h2>OpenCPU test</h2>";
echo '<h5>Testing ' . Config::get('opencpu_instance')['public_url'] . '</h5>';

$max = 30;

// Check if OpenCPU instance requires authentication
$requires_auth = Config::get('opencpu_instance')['requires_auth'] ?? false;
$auth_token = null;

// If authentication is required, retrieve the token
if ($requires_auth) {
    $auth_token = Config::get('opencpu_instance')['auth_token'];
}

for ($i = 0; $i < $max; $i++):

    $source = '{' .
        mt_rand() . '
        ' . str_repeat(" ", $i) . '
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
        fragment.only = TRUE, options = c("base64_images", "smartypants")
        )
        ' . str_repeat(" ", $max - $i) . '
    }';

    $start_time = microtime(true);

    // Execute the R code snippet
    $results = $openCPU->snippet($source);
    $responseHeaders = $openCPU->getResponseHeaders();

    // Get the HTTP status from the curl_info array
    $http_status = $openCPU->getRequestInfo('http_code');

    $alert_type = 'alert-success';

    // Handle HTTP status and errors
    if ($http_status > 302 || $http_status === 0) {
        $alert_type = 'alert-danger';
    }

    alert('1. HTTP status: ' . $http_status, $alert_type);

    // Check if total_time is present in the headers before adding it
    $responseHeaders['total_time_php'] = round(microtime(true) - $start_time, 3);
    
    if (isset($responseHeaders['total_time'])) {
        $times['total_time'][] = $responseHeaders['total_time'];
    }
    if (isset($responseHeaders['total_time_php'])) {
        $times['total_time_php'][] = $responseHeaders['total_time_php'];
    }

endfor;

$data = 'times = "' . json_encode($times['total_time_php']).'"';

// Prepare the R code snippet to generate the plot
$source = '
'. $data .'
times = jsonlite::fromJSON(times)
library(ggplot2)
library(stringr)
p <- qplot(times)
print(p)';

// Submit the R code to generate the plot
$results = $openCPU->snippet($source);
$responseHeaders = $openCPU->getResponseHeaders();

echo opencpu_debug($results);
// Get the HTTP status from the curl_info array
$http_status = $openCPU->getRequestInfo('http_code');

// If the HTTP status is successful, retrieve the plot
if ($http_status >= 200 && $http_status <= 302) {
    // Retrieve the file path for the plot (usually a PNG)
    $files = $results->getFiles('/graphics/');

    if (!empty($files)) {
        // Display the plot image in the HTML
        $plot_url = array_values($files)[0]; // Assuming the first file is the plot
        echo "<img src='{$plot_url}' alt='Plot'>";
    } else {
        echo "<p>No plot was generated.</p>";
    }
} else {
    alert('Error: Failed to generate plot. HTTP status: ' . $http_status, 'alert-danger');
}

Template::loadChild('admin/footer');
