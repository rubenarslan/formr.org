<?php
// Line coverage for one PHP process — a web request or a CLI script — for
// the coverage pass (tests/coverage/README.md). Loaded as auto_prepend_file;
// inert unless FORMR_COVERAGE_DIR is set and Xdebug runs in coverage mode.
//
// Every process unions the lines it executed into <dir>/<label>.json under a
// lock (label: FORMR_COVERAGE_LABEL, default "web"), so a whole e2e suite
// leaves one file per lane instead of one per request. Only app code is
// kept: vendor/ and tests/ are dropped here, the rest is filtered by
// tests/coverage/php-report.php.

(static function () {
    $dir = getenv('FORMR_COVERAGE_DIR');
    if (!$dir || !function_exists('xdebug_start_code_coverage') || !function_exists('xdebug_info')) {
        return;
    }
    if (!in_array('coverage', (array) xdebug_info('mode'), true)) {
        return;
    }
    $label = preg_replace('/[^a-z0-9_-]/i', '', (string) getenv('FORMR_COVERAGE_LABEL')) ?: 'web';
    $root = dirname(__DIR__, 2) . '/';
    xdebug_start_code_coverage();

    $dump = static function () use ($dir, $label, $root) {
        $data = xdebug_get_code_coverage();
        xdebug_stop_code_coverage(false);
        $keep = [];
        foreach ($data as $file => $lines) {
            if (strpos($file, $root) !== 0) continue;
            $rel = substr($file, strlen($root));
            if (strpos($rel, 'vendor/') === 0 || strpos($rel, 'tests/') === 0) continue;
            foreach ($lines as $line => $hit) {
                if ($hit > 0) $keep[$file][$line] = 1;
            }
        }
        if (!$keep) return;
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        $path = $dir . '/' . $label . '.json';
        $lock = @fopen($path . '.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) return;
        $all = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
        foreach ($keep as $file => $lines) {
            $all[$file] = (isset($all[$file]) ? $all[$file] : []) + $lines;
        }
        file_put_contents($path . '.tmp', json_encode($all));
        rename($path . '.tmp', $path);
        @chmod($path, 0666);
        flock($lock, LOCK_UN);
        fclose($lock);
    };
    // Registered from inside a shutdown function, so it runs after the
    // app's own shutdown handlers (session write, error logging) and sees
    // their lines too.
    register_shutdown_function(static function () use ($dump) {
        register_shutdown_function($dump);
    });
})();
