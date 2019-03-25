<?php

class LogParser {

    const LOG_MARKER = '----------';
    const LOG_MARKER_START = 'cron-run call start';
    const LOG_MARKER_END = 'cron-run call end';
    const LOG_MARKER_ALERTS_START = '<alerts>';
    const LOG_MARKER_ALERTS_END = '</alerts>';
    const LOG_MARKER_START_GM = 'Processing run >>>';

    public function __construct() {
        
    }

    public function getCronLogFiles() {
        $search = APPLICATION_ROOT . 'tmp/logs/cron/*.log';
        $files = array();
        foreach (glob($search) as $file) {
            $filename = str_replace('cron-run-', '', basename($file));
            $files[$filename] = $file;
        }
        return $files;
    }

    public function printCronLogFile($file, $expand = false) {
        $file = APPLICATION_ROOT . 'tmp/logs/cron/cron-run-' . $file;
        if (!file_exists($file)) {
            return null;
        }

        $handle = fopen($file, "r");
        $id = 1;
        $class = $expand ? ' in' : null;
        $openRow = false;
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (!$line) {
                    continue;
                }

                if (strstr($line, self::LOG_MARKER)) {
                    $id++;
                    continue;
                }

                if (strstr($line, self::LOG_MARKER_START) !== false || strstr($line, self::LOG_MARKER_START_GM) !== false) {
                    if ($openRow === true) {
                        echo '</div></div>';
                        $id++;
                    }
                    $openRow = true;
                    $id_str = 'log-entry-' . $id;
                    $time = strtotime($this->stripLineTime($line, true));
                    echo '<div class="log-entry panel panel-default" data-time="' . $time . '">';

                    echo '<div class="panel-heading">';
                    $date = 'Cron run: ' . date('Y-m-d H:i', $time);
                    echo '<a class="accordion-toggle" data-toggle="collapse" data-parent="#log-entries" href="#' . $id_str . '" aria-expanded="false">' . $date . '</a>';
                    echo '</div>';

                    echo '<div id="' . $id_str . '" class="panel-content panel-collapse collapse ' . $class . '" aria-expanded="false">';
                } elseif (strstr($line, self::LOG_MARKER_END) !== false) {
                    $openRow = false;
                    echo '</div></div>';
                } elseif (strstr($line, self::LOG_MARKER_ALERTS_START) !== false) {
                    echo '<div class="alerts">';
                } elseif (strstr($line, self::LOG_MARKER_ALERTS_END) !== false) {
                    echo '</div>';
                } else {
                    echo $this->stripLineTime($line);
                }
            }

            fclose($handle);
        } else {
            echo 'an error occured while trying to open log file';
        }
    }

    protected function stripLineTime($line, $returntime = false) {
        $pattern = '/(?P<datetime>[0-9]{1,4}-[0-9]{1,2}-[0-9]{1,2} [0-9]{1,2}:[0-9]{1,2}:[0-9]{1,2})/';
        $matches = array();
        if ($returntime === true) {
            preg_match($pattern, $line, $matches);
            if (isset($matches['datetime'])) {
                return $matches['datetime'];
            }
            return 0;
        }

        return preg_replace($pattern, '', str_replace('<br />', '', $line));
    }

}
