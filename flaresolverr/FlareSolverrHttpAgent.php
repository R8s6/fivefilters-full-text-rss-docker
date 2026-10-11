<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Browser-backed downloader for FiveFilters' article extraction endpoint.

class FlareSolverrException extends RuntimeException {}

class FlareSolverrHttpAgent extends HumbleHttpAgent
{
    protected $flaresolverrUrl;
    protected $maxTimeout;

    public function __construct($requestOptions = null, $method = null)
    {
        parent::__construct($requestOptions, $method);
        $base = rtrim(trim(getenv('FTR_FLARESOLVERR_URL')), '/');
        $parts = parse_url($base);
        if (!$this->validateUrl($base) || !$parts ||
            isset($parts['query']) || isset($parts['fragment']) ||
            isset($parts['user']) || isset($parts['pass'])) {
            throw new FlareSolverrException('FTR_FLARESOLVERR_URL must be an HTTP(S) base URL or /v1 endpoint.');
        }
        $this->flaresolverrUrl = substr($base, -3) === '/v1' ? $base : $base.'/v1';
        $timeout = trim(getenv('FTR_FLARESOLVERR_TIMEOUT'));
        if ($timeout === '') $timeout = '60000';
        if (!ctype_digit($timeout) || (float)$timeout < 1000 || (float)$timeout > 120000) {
            throw new FlareSolverrException('FTR_FLARESOLVERR_TIMEOUT must be 1000 to 120000 milliseconds.');
        }
        $this->maxTimeout = (int)$timeout;
    }

    public function parallelSupport()
    {
        return false;
    }

    public function fetchAllOnce(array $urls, $isRedirect = false)
    {
        if (!$isRedirect) $urls = array_unique($urls);
        foreach ($urls as $original => $url) {
            if (!$isRedirect) $original = $url;
            unset($this->redirectQueue[$original]);
            if (isset($this->requests[$original])) continue;

            $requestUrl = $this->convertIdn($this->rewriteUrls($url));
            if ($this->rewriteHashbangFragment) $requestUrl = $this->rewriteHashbangFragment($requestUrl);
            $requestUrl = $this->removeFragment($requestUrl);
            if (!$this->validateUrl($requestUrl)) {
                throw new FlareSolverrException('Invalid article URL for FlareSolverr.');
            }
            $this->debug('Fetching article with FlareSolverr: '.$requestUrl);
            $payload = json_encode(array(
                'cmd' => 'request.get',
                'url' => $requestUrl,
                'maxTimeout' => $this->maxTimeout
            ));
            $result = json_decode($this->postJson($payload), true);
            if (!is_array($result) || !isset($result['status']) || $result['status'] !== 'ok') {
                $message = is_array($result) && isset($result['message']) && is_string($result['message'])
                    ? $result['message'] : 'Invalid JSON response';
                throw new FlareSolverrException('FlareSolverr failed: '.$message);
            }
            if (!isset($result['solution']) || !is_array($result['solution'])) {
                throw new FlareSolverrException('FlareSolverr returned no solution.');
            }
            $solution = $result['solution'];
            $status = isset($solution['status']) ? filter_var($solution['status'], FILTER_VALIDATE_INT) : false;
            if ($status === false || $status < 200 || $status >= 300) {
                throw new FlareSolverrException('FlareSolverr article HTTP status: '.($status === false ? 'missing' : $status));
            }
            if (!isset($solution['response']) || !is_string($solution['response']) || trim($solution['response']) === '') {
                throw new FlareSolverrException('FlareSolverr returned empty article HTML.');
            }
            if (!isset($solution['url']) || !is_string($solution['url']) || !$this->validateUrl($solution['url'])) {
                throw new FlareSolverrException('FlareSolverr returned an invalid final URL.');
            }
            $this->debug('FlareSolverr article HTTP status: '.$status.'; final URL: '.$solution['url']);
            $this->requests[$original] = array(
                'original_url' => $original,
                'effective_url' => $solution['url'],
                'status_code' => $status,
                // response is a rendered, decoded DOM, not the original wire body.
                // Original compression, length and charset headers would corrupt it.
                'headers' => "Content-Type: text/html; charset=UTF-8\r\n",
                'body' => $solution['response'],
                'method' => 'GET'
            );
        }
    }

    protected function postJson($payload)
    {
        $timeoutSeconds = (int)ceil($this->maxTimeout / 1000) + 10;
        if (function_exists('curl_init')) {
            $handle = curl_init($this->flaresolverrUrl);
            curl_setopt_array($handle, array(
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Accept: application/json'),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => $timeoutSeconds
            ));
            $body = curl_exec($handle);
            $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
            $error = curl_error($handle);
            curl_close($handle);
            if ($body === false) {
                throw new FlareSolverrException('Cannot reach FlareSolverr: '.$error);
            }
        } else {
            $context = stream_context_create(array('http' => array(
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $payload,
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'follow_location' => 0
            )));
            $body = @file_get_contents($this->flaresolverrUrl, false, $context);
            if ($body === false) throw new FlareSolverrException('Cannot reach FlareSolverr.');
            $status = 0;
            if (isset($http_response_header)) {
                foreach ($http_response_header as $header) {
                    if (preg_match('!^HTTP/\S+\s+(\d{3})!', $header, $match)) $status = (int)$match[1];
                }
            }
        }
        if ($status < 200 || $status >= 300) {
            throw new FlareSolverrException('FlareSolverr API HTTP status: '.$status);
        }
        return $body;
    }
}
