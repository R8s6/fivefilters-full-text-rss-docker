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

## Optional FlareSolverr fetching

This fork can use an existing [FlareSolverr](https://github.com/FlareSolverr/FlareSolverr) service to fetch a source feed and its article pages before FiveFilters extracts the full text. The web form includes a **FlareSolverr** checkbox alongside Include excerpt, JSON output and Debug.

Build this branch locally, then use the resulting image in your existing Compose service:

```sh
git clone --branch feat/flaresolverr-feed-ui https://github.com/R8s6/fivefilters-full-text-rss-docker.git fivefilters-feed-ui
docker build -t fivefilters-flaresolverr:local fivefilters-feed-ui
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

After saving your Compose changes, run `docker compose up -d --no-deps --force-recreate fivefilters`. The FlareSolverr endpoint can be a base URL or end in `/v1`.

Visit the FiveFilters web UI on port 8012, enter the source feed URL and check **FlareSolverr**. Start with **Max items = 2**. Leave **JSON output** and **Debug** unchecked to produce a subscribable RSS feed, then click **Create Feed**. The generated URL includes `flaresolverr=1`, so later refreshes also use FlareSolverr. Leaving the checkbox unchecked selects FiveFilters' native downloader. The checkbox is disabled when `FTR_FLARESOLVERR_URL` is empty.

For FreshRSS on the same Compose network, use `http://fivefilters:80` as the generated feed URL's host. For example:

```text
http://fivefilters:80/makefulltextfeed.php?url=https%3A%2F%2Fchinadigitaltimes.net%2Fchinese%2Ffeed%2F&max=2&flaresolverr=1
```

FreshRSS must allow requests to that internal host. This subscription receives full articles directly, so it does not require the FreshRSS FlareSolverr extension or Readable's article extraction. Browser fetches are sequential and can take longer than ordinary feed requests; adjust FreshRSS' per-feed timeout if needed.

To test the generated feed from the FreshRSS container:

```sh
docker exec freshrss curl -fsS --connect-timeout 5 --max-time 180 \
  --get \
  --data-urlencode 'url=https://chinadigitaltimes.net/chinese/feed/' \
  --data-urlencode 'max=2' \
  --data-urlencode 'flaresolverr=1' \
  'http://fivefilters:80/makefulltextfeed.php'
```

The downloader accepts raw RSS, Atom and RDF XML, and unwraps the escaped XML that Chromium returns inside a `<pre>` element. It preserves XML entities and UTF-8 text, including Chinese. Site configuration still controls article extraction; FlareSolverr controls the browser user agent and HTTP headers. FiveFilters' URL and access checks run before fetching. Browser output uses a separate result cache from native output.

API errors, challenge failures and non-2xx page statuses return HTTP 502 instead of caching a successful feed. JSON mode returns a JSON error; RSS mode returns a plain error message. Debug mode reports the browser fetch and final HTTP status. FlareSolverr does not guarantee that every site's challenge can be solved.

### Existing Readable extraction API

`/extract.php` keeps its existing behavior: when `FTR_FLARESOLVERR_URL` is configured, uncached article extraction automatically uses FlareSolverr. Readable's FiveFilters host remains `http://fivefilters:80`, and the JSON response format is unchanged. `flaresolverr=0` selects native fetching for an individual request; `flaresolverr=1` explicitly selects browser fetching. Supplied `inputhtml` is extracted directly without downloading again.

To test an uncached article from your Compose directory:

```sh
docker compose exec -T -e ftr_caching=false fivefilters php -r '
chdir("/var/www/html");
$_GET = array("url" => $argv[1]);
$_SERVER["SCRIPT_NAME"] = "/extract.php";
require "extract.php";
' 'https://www.phoronix.com/news/Linux-WA-TLBI-Erratum-1718'
```

The image build runs downloader and form checks in `flaresolverr/test.php` and complete converter checks in `flaresolverr/test-feed.php` against the pinned FiveFilters source. These use fixture responses and do not contact a live FlareSolverr service.
