<?php
// SPDX-License-Identifier: MIT
// Apply small integration points to the pinned FiveFilters source, failing
// the build if upstream code changes rather than silently installing no hook.

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php install.php /path/to/fivefilters\n");
    exit(1);
}
$root = rtrim($argv[1], '/');
$enabled = "_FF_FTR_MODE == 'simple' && trim(getenv('FTR_FLARESOLVERR_URL')) !== ''";
$mainPath = $root.'/makefulltextfeed.php';
$extractPath = $root.'/extract.php';
$main = file_get_contents($mainPath);
$extract = file_get_contents($extractPath);

function replace_once($source, $from, $to)
{
    if (substr_count($source, $from) !== 1) {
        throw new RuntimeException('FiveFilters integration point changed: '.$from);
    }
    return str_replace($from, $to, $source);
}

$agentHook = <<<'PHP'
if (__ENABLED__) {
        require_once dirname(__FILE__).'/libraries/humble-http-agent/FlareSolverrHttpAgent.php';
        $http = new FlareSolverrHttpAgent($_req_options);
    } else {
        $http = new HumbleHttpAgent($_req_options);
    }
PHP;
$cacheHook = <<<'PHP'
if (__ENABLED__) {
        // Browser results must not reuse earlier native-download failures.
        $cache_id = md5('flaresolverr-v1:'.$cache_id);
    }
    $check_cache = true;
PHP;
$main = replace_once($main, '$http = new HumbleHttpAgent($_req_options);', str_replace('__ENABLED__', $enabled, $agentHook));
$main = replace_once($main, '$check_cache = true;', str_replace('__ENABLED__', $enabled, $cacheHook));
$extract = replace_once($extract, "require 'makefulltextfeed.php';", <<<'PHP'
try {
    require 'makefulltextfeed.php';
} catch (FlareSolverrException $e) {
    http_response_code(502);
    header('Cache-Control: no-store');
    header_remove('Expires');
    error_log($e->getMessage());
    if (isset($debug_mode) && $debug_mode) {
        debug($e->getMessage());
    } else {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(array('error' => $e->getMessage()));
    }
    exit(1);
}
PHP
);
if (!copy(__DIR__.'/FlareSolverrHttpAgent.php', $root.'/libraries/humble-http-agent/FlareSolverrHttpAgent.php') ||
    file_put_contents($mainPath, $main) === false || file_put_contents($extractPath, $extract) === false) {
    throw new RuntimeException('Could not install FlareSolverr integration.');
}
echo "FlareSolverr integration installed.\n";
