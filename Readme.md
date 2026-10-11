# Docker Image for fivefilters Full-Text RSS service

[![Build, test and push Docker images](https://github.com/heussd/fivefilters-full-text-rss-docker/actions/workflows/build-and-push.yml/badge.svg)](https://github.com/heussd/fivefilters-full-text-rss-docker/actions/workflows/build-and-push.yml)
![Last build date](https://img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fapi.github.com%2Frepos%2Fheussd%2Ffivefilters-full-text-rss-docker%2Factions%2Fworkflows%2F4311790%2Fruns%3Fstatus%3Dcompleted%26per_page%3D1&query=%24.workflow_runs%5B0%5D.run_started_at&label=last%20build)
![Number of Image Pulls](https://img.shields.io/docker/pulls/heussd/fivefilters-full-text-rss)
![Image size](https://img.shields.io/docker/image-size/heussd/fivefilters-full-text-rss/latest)

This is a containerized version of [fivefilters full-text-rss](https://www.fivefilters.org/full-text-rss/), which retrieves the full-text of individual articles or complete full-text RSS feeds.

Not affiliated with [fivefilters.org](http://fivefilters.org/). The Dockerfile is licensed under [MIT conditions](LICENSE).

The Docker image includes [site-specific article extraction rules](https://github.com/fivefilters/ftr-site-config) and is [rebuilt weekly](https://github.com/heussd/fivefilters-full-text-rss-docker/blob/master/.github/workflows/build-and-push.yml#L8) with the latest rules. For best results, pull the updated image regularly.

## User Guide

- Use the following [compose.yml](compose.yml)

```yaml
services:
  fullfeedrss:
    image: "heussd/fivefilters-full-text-rss:latest"
    mem_limit: 2G
    restart: always
    environment:
      # Leave empty to disable admin section
      - FTR_ADMIN_PASSWORD=
    volumes:
      - "rss-cache:/var/www/html/cache/rss"
      - "./custom:/var/www/html/site_config/custom"
    ports:
      - "80:80"
volumes:
  rss-cache:
```

- Start it with `docker compose up`
- Visit [http://localhost:80](http://localhost:80) for the integrated web UI

![](webui.png)

- Interesting endpoints (see tab [Request & Response](http://localhost/#request)):
  - Article extraction: `http://localhost/extract.php?url=[url]`
  - Feed conversion: `http://localhost/makefulltextfeed.php?url=[url]`
- See [calls.http](calls.http) for example calls

## Optional FlareSolverr article fetching

This fork can fetch article HTML through an existing [FlareSolverr](https://github.com/FlareSolverr/FlareSolverr) service before FiveFilters extracts it. Enable it with `FTR_FLARESOLVERR_URL`. This applies to `/extract.php`, which FreshRSS' Readable extension uses, and keeps the same JSON API. The normal feed conversion endpoint continues to use FiveFilters' native downloader.

Before building, test the browser-to-extractor path using your current containers:

```sh
cd ~/docker/freshrss
docker exec freshrss curl -fsS --connect-timeout 5 --max-time 75 \
  -H 'Content-Type: application/json' \
  --data '{"cmd":"request.get","url":"https://www.phoronix.com/news/Linux-WA-TLBI-Erratum-1718","maxTimeout":60000}' \
  'http://192.168.0.104:8191/v1' |
docker compose exec -T fivefilters php -r '
$r = json_decode(stream_get_contents(STDIN), true);
if (!isset($r["status"], $r["solution"]["status"], $r["solution"]["response"]) ||
    $r["status"] !== "ok" || $r["solution"]["status"] < 200 ||
    $r["solution"]["status"] >= 300 || trim($r["solution"]["response"]) === "") {
    fwrite(STDERR, "FlareSolverr failed: ".(isset($r["message"]) ? $r["message"] : "invalid response")."\n");
    exit(1);
}
chdir("/var/www/html");
$_GET = array("url" => $argv[1]);
$_POST = array("inputhtml" => $r["solution"]["response"]);
$_SERVER["SCRIPT_NAME"] = "/extract.php";
require "extract.php";
' 'https://www.phoronix.com/news/Linux-WA-TLBI-Erratum-1718'
```

A title and nonempty `content` confirm that FlareSolverr can supply article HTML which your current extractor accepts.

Build this branch locally, then use the resulting image in your existing Compose service:

```sh
git clone --branch feat/flaresolverr https://github.com/R8s6/fivefilters-full-text-rss-docker.git
docker build -t fivefilters-flaresolverr:local fivefilters-full-text-rss-docker
```

```yaml
services:
  fivefilters:
    image: fivefilters-flaresolverr:local
    environment:
      - FTR_ADMIN_PASSWORD=
      - FTR_FLARESOLVERR_URL=http://192.168.0.104:8191
      # Browser challenge timeout in milliseconds; default 60000.
      - FTR_FLARESOLVERR_TIMEOUT=60000
    volumes:
      - rss-cache:/var/www/html/cache/rss
    ports:
      - "8012:80"
    restart: always
volumes:
  rss-cache:
```

After saving your Compose changes, run `docker compose up -d --no-deps fivefilters`. Keep Readable's FiveFilters host set to `http://fivefilters:80`.

The endpoint can be a base URL or end in `/v1`. The browser fetches every uncached article submitted to `/extract.php`, including extra pages requested by extraction rules. Requests are sequential within an extraction, and each uses a temporary FlareSolverr session. Browser fetching costs more time and memory than native HTTP fetching. Site configuration still controls extraction; FlareSolverr controls the browser user agent and HTTP headers. FiveFilters' URL and access checks still run before fetching. Browser results use a separate cache key from native results. API errors, reported challenge failures and non-2xx article statuses produce an HTTP 502 JSON error and are not saved as successful extraction results. Supplying `inputhtml` still uses the supplied HTML directly.

Remove `FTR_FLARESOLVERR_URL` to return to native downloading. A manually installed user-agent override is unnecessary for this integration and can be removed from `site_config/custom` if it was only installed for testing.

Test an uncached article from your Compose directory:

```sh
docker compose exec -T -e ftr_caching=false fivefilters php -r '
chdir("/var/www/html");
$_GET = array("url" => $argv[1]);
$_SERVER["SCRIPT_NAME"] = "/extract.php";
require "extract.php";
' 'https://www.phoronix.com/news/Linux-WA-TLBI-Erratum-1718'
```

Add `"debug" => "1"` to `$_GET` to see the browser fetch and final article status. A successful JSON response contains a title and nonempty `content`. The image build runs the fixture checks in `flaresolverr/test.php` against its pinned FiveFilters HTTP agent. Those checks do not contact a live FlareSolverr service.

