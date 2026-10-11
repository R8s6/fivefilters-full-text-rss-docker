<?php
// SPDX-License-Identifier: MIT
// Run against the actual pinned HTTP agent, without a browser or internet.

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php test.php /path/to/fivefilters\n");
    exit(1);
}
$root = rtrim($argv[1], '/');
require_once $root.'/libraries/humble-http-agent/HumbleHttpAgent.php';
require_once $root.'/libraries/humble-http-agent/FlareSolverrHttpAgent.php';

class FixtureFlareSolverrAgent extends FlareSolverrHttpAgent
{
    public $payloads = array();
    public $fixture;
    public function endpoint() { return $this->flaresolverrUrl; }
    protected function postJson($payload)
    {
        $this->payloads[] = json_decode($payload, true);
        return $this->fixture;
    }
}

$checks = 0;
function check($condition, $message)
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: '.$message);
    $checks++;
}
function rejected($callback, $message)
{
    try {
        $callback();
    } catch (FlareSolverrException $e) {
        check(strpos($e->getMessage(), $message) !== false, 'error explains '.$message);
        return;
    }
    throw new RuntimeException('FAIL: expected FlareSolverrException: '.$message);
}
function new_agent($fixture)
{
    $agent = new FixtureFlareSolverrAgent(null, HumbleHttpAgent::METHOD_FILE_GET_CONTENTS);
    $agent->fixture = $fixture;
    return $agent;
}

putenv('FTR_FLARESOLVERR_URL=http://solver:8191/');
putenv('FTR_FLARESOLVERR_TIMEOUT=');
$article = 'https://example.org/article';
$html = '<html><body><p>Rendered article with café.</p></body></html>';
$success = array('status' => 'ok', 'solution' => array(
    'status' => 200,
    'url' => 'https://example.org/final',
    'response' => $html,
    'headers' => array('content-encoding' => 'gzip', 'content-length' => '10', 'content-type' => 'text/html; charset=ISO-8859-1')
));
$agent = new_agent(json_encode($success));
check($agent->endpoint() === 'http://solver:8191/v1', 'base endpoint normalized');
check($agent->parallelSupport() === false, 'browser requests are sequential');
$agent->fetchAll(array($article, $article));
check(count($agent->payloads) === 1, 'duplicate URLs fetched once');
check($agent->payloads[0] === array('cmd' => 'request.get', 'url' => $article, 'maxTimeout' => 60000), 'documented request payload');
$response = $agent->get($article);
check($response['body'] === $html, 'rendered UTF-8 HTML passed through');
check($response['effective_url'] === 'https://example.org/final', 'final URL retained');
check($response['status_code'] === 200, 'status retained');
check($response['headers'] === "Content-Type: text/html; charset=UTF-8\r\n", 'wire compression and charset headers removed');
check(count($agent->payloads) === 1, 'get reuses fetched response');
$agent->get($article, true);
$agent->get($article);
check(count($agent->payloads) === 2, 'removed response can be fetched again');
$agent->get('https://example.org/page-2');
check(count($agent->payloads) === 3, 'additional pages use the same downloader');

putenv('FTR_FLARESOLVERR_URL=http://solver:8191/v1/');
putenv('FTR_FLARESOLVERR_TIMEOUT=45000');
$agent = new_agent(json_encode($success));
check($agent->endpoint() === 'http://solver:8191/v1', 'explicit /v1 not duplicated');
$agent->rewriteUrls = array('example.org' => array('/old' => '/new'));
$agent->get('https://example.org/old#section');
check($agent->payloads[0]['url'] === 'https://example.org/new', 'URL rewrites and fragment removal preserved');
check($agent->payloads[0]['maxTimeout'] === 45000, 'configured timeout used');

rejected(function() use ($success) { new_agent(json_encode($success))->get('file:///etc/passwd'); }, 'Invalid article URL');
foreach (array('not a URL', 'file:///tmp', 'http://solver:8191/?x=y', 'http://solver:8191/#frag', 'http://user:pass@solver:8191/') as $badUrl) {
    putenv('FTR_FLARESOLVERR_URL='.$badUrl);
    rejected(function() use ($success) { new_agent(json_encode($success)); }, 'FTR_FLARESOLVERR_URL');
}
putenv('FTR_FLARESOLVERR_URL=http://solver:8191');
foreach (array('0', '-1', '60s', '999', '120001', '999999999999999999999999') as $badTimeout) {
    putenv('FTR_FLARESOLVERR_TIMEOUT='.$badTimeout);
    rejected(function() use ($success) { new_agent(json_encode($success)); }, 'FTR_FLARESOLVERR_TIMEOUT');
}
putenv('FTR_FLARESOLVERR_TIMEOUT=');
rejected(function() use ($article) { new_agent('not JSON')->get($article); }, 'Invalid JSON response');
rejected(function() use ($article) { new_agent('{"status":"error","message":"Challenge timed out"}')->get($article); }, 'Challenge timed out');
rejected(function() use ($article) { new_agent('{"status":"ok"}')->get($article); }, 'no solution');
foreach (array(403, 404, 503, null, 'invalid') as $badStatus) {
    $fixture = $success;
    $fixture['solution']['status'] = $badStatus;
    rejected(function() use ($fixture, $article) { new_agent(json_encode($fixture))->get($article); }, 'article HTTP status');
}
$fixture = $success;
$fixture['solution']['response'] = '';
$agent = new_agent(json_encode($fixture));
rejected(function() use ($agent, $article) { $agent->get($article); }, 'empty article HTML');
$agent->fixture = json_encode($success);
check($agent->get($article)['body'] === $html, 'failed downloads not retained in memory');
$fixture = $success;
$fixture['solution']['url'] = 'file:///etc/passwd';
rejected(function() use ($fixture, $article) { new_agent(json_encode($fixture))->get($article); }, 'invalid final URL');

echo 'Passed '.$checks." FlareSolverr checks.\n";
