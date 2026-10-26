# Advanced FFprobe M3U Cleaner

**Keep the channels that pass the probe. Keep the metadata that makes your playlist useful.**

A one-shot Docker tool for cleaning HTTP(S) IPTV channel lists with FFprobe. It reads `.m3u` and `.m3u8` files, checks for audio or video streams, removes duplicate channel entries, and writes a cleaned playlist without rebuilding away guide IDs, logos, groups, or custom entry directives.

[![Tests and publishing](https://github.com/SeanRiggs/advanced-ffprobe-m3u-cleaner/actions/workflows/docker.yml/badge.svg)](https://github.com/SeanRiggs/advanced-ffprobe-m3u-cleaner/actions/workflows/docker.yml)

## What's new

- `.m3u` and `.m3u8` discovery, including uppercase extensions.
- Direct FFprobe execution without a shell, plus network and overall per-stream deadlines.
- Original EXTINF attributes and entry directives retained instead of rewritten as bare channel names.
- No HEAD-request gate that rejects providers which only support GET.
- Atomic output replacement, overlapping-run protection, and preservation of previous output when no channels pass or an input cannot be fully read.
- PHP 8.4 image with FFprobe, no runtime Composer packages, and a non-root default user.
- Automated tests using local WAV audio, HLS video, redirects, invalid media, stalled streams, and controlled process fixtures.

## Quick start with Docker Compose

Install Docker with the Compose v2 plugin, then:

```bash
git clone https://github.com/SeanRiggs/advanced-ffprobe-m3u-cleaner.git
cd advanced-ffprobe-m3u-cleaner
mkdir -p m3u output logs
docker compose pull
```

Place your channel-list files in `m3u/`. On Linux, match the container user to the owner of your host folders:

```bash
PUID=$(id -u) PGID=$(id -g) docker compose run --rm advanced-ffprobe-m3u-cleaner
```

On Docker Desktop, start with:

```bash
docker compose run --rm advanced-ffprobe-m3u-cleaner
```

The Compose defaults use UID/GID 1000. Adjust `PUID` and `PGID` for your filesystem if needed. The input mount is read-only. Results appear in `output/cleaned_playlist.m3u`; logs appear in `logs/validator.log`. This is a batch job: it exits after processing, not a web service.

**Check the image tag/build status before assuming a cached image contains this refresh.** Run `docker compose pull` to fetch the published update. To use the checked-out source immediately, build locally as described below.

## Docker run alternative

For Linux hosts:

```bash
docker pull seanriggs/advanced-ffprobe-m3u-cleaner:latest
docker run --rm --user "$(id -u):$(id -g)" \
  -v "$PWD/m3u:/app/m3u:ro" \
  -v "$PWD/output:/app/output" \
  -v "$PWD/logs:/var/log" \
  seanriggs/advanced-ffprobe-m3u-cleaner:latest
```

On Windows, use absolute Windows host paths in Docker Desktop's volume settings and the same container paths. Do not make the output or log mounts read-only. A numeric user override controls filesystem permissions; it does not change ownership of existing host files automatically.

## What is preserved?

Accepted entries retain their original EXTINF duration, title, attributes (including `tvg-id`, `tvg-name`, `tvg-logo`, and `group-title`), and attached comment/directive lines such as `#EXTVLCOPT` and `#KODIPROP`. UTF-8 BOMs and CRLF line endings are normalized. Blank lines are omitted. The first input header is retained; headers from multiple providers are not merged.

Files are processed in sorted filename order. Entries with the same exact title and URL are treated as duplicates, keeping the first accepted entry and its metadata. Distinct titles using the same URL remain separate channels. Probe results are reused for repeated URLs within that run.

This tool preserves directives in the output but **does not translate player-specific headers, DRM, cookies, or directives into FFprobe options**. A channel requiring those may fail validation even when it plays in a configured client.

## Input and validation limits

- Input files are channel lists, not individual HLS media manifests. A channel's stream URL may point to an HLS manifest; putting that HLS manifest itself in the input directory is rejected.
- Only HTTP and HTTPS stream URLs are supported. Local files and other protocols are rejected; FFprobe's nested protocol access is restricted as well.
- Subdirectories are not scanned. A configured output file inside the input directory is excluded from discovery, but separate input/output folders are recommended.
- FFprobe must exit successfully and return structured JSON containing an audio or video stream. This checks stream detection, **not sustained playback, decoding quality, bandwidth, or future availability**.
- No provider credentials or channel URLs are written to the cleaner's logs. Output playlists still contain the original stream URLs, so protect them appropriately.
- A timeout skips that channel and continues. Total run time depends on channel count and the configured per-stream limit.

## Configuration

Pass settings as container environment variables. Paths are inside the container.

| Variable | Default | Purpose |
| --- | --- | --- |
| `M3U_DIRECTORY` | `/app/m3u` | Channel-list input directory. |
| `OUTPUT_FILE` | `/app/output/cleaned_playlist.m3u` | Cleaned output file. |
| `LOG_FILE` | `/var/log/validator.log` | Append-only summary and skip log. |
| `PROBE_TIMEOUT_SECONDS` | `15` | Per-stream wall-clock/network limit; integer 1–600. |
| `FFPROBE_PATH` | `ffprobe` | Local FFprobe executable, primarily for custom deployments/testing. Not a shell command. |

Compose also accepts `PUID` and `PGID` for the container user, defaulting to 1000. For example, a longer deadline:

```bash
PUID=$(id -u) PGID=$(id -g) PROBE_TIMEOUT_SECONDS=30 \
  docker compose run --rm advanced-ffprobe-m3u-cleaner
```

Log rotation is up to the host/scheduler; this tool appends to its configured log.

## Output and exit status

| Exit code | Meaning |
| --- | --- |
| `0` | A nonempty cleaned playlist was written. Some channels may have been skipped. |
| `1` | Configuration, directory, process, lock, or output error; inspect the console. |
| `2` | No channels passed, or an input could not be fully read; no replacement was published. |

Previous output is retained on empty/failed validation instead of being overwritten with an empty list. That retained file can be stale: a scheduler must check the exit code rather than treating file existence as success. A `.lock` file beside the output is normal and remains after a run; the operating-system lock, not its mere presence, prevents simultaneous writes.

The cleaner probes provider streams. Schedule runs at a frequency appropriate for your provider's connection and request limits; it is not designed to evade them.

## Build from source

```bash
docker build -t advanced-ffprobe-m3u-cleaner:dev .
PUID=$(id -u) PGID=$(id -g) docker compose -f docker-compose.dev.yml run --build --rm advanced-ffprobe-m3u-cleaner
```

The development Compose file mounts `validator.php` read-only so edits can be tested without reinstalling packages. The PHP program is self-contained; Composer is not required to build or run it. `composer.json` now documents PHP requirements only.

## Tests and publishing

Build the image, then run the integration suite with Python 3 on Linux:

```bash
docker build -t cleaner-test:local .
CLEANER_IMAGE=cleaner-test:local python3 -m unittest discover -s tests -v
```

The tests use a disposable local HTTP server and generated media, not live third-party playlists. They cover extension discovery, metadata, duplicates, redirects, HLS/audio detection, invalid media, timeouts (including forced termination), literal URL argument handling, redacted logs, missing dependencies, output preservation, locking, and output failures.

CI builds and tests AMD64 and ARM64 on native runners, and ARMv7 using emulation. The publishing job loads and pushes those **same tested image archives**, then assembles `latest` and a commit-specific `sha-<commit>` tag. It does not rebuild different images after testing. Docker Hub publication requires an Actions repository secret named `DOCKERHUB_TOKEN` or `FFMPEG` containing a Docker Hub token with write access to `seanriggs/advanced-ffprobe-m3u-cleaner`. If both are set, `DOCKERHUB_TOKEN` takes precedence. Missing credentials are explicitly reported as skipped publication.

## Contributing

Good improvements include provider-independent test fixtures, clearer failure reporting, playlist-format edge cases, and tested performance work. Open a focused pull request describing the behavior before and after, and the tests you ran. Use synthetic URLs and fixtures rather than committing private playlists or tokens. Preserve existing copyright/license notices.

See [GitHub issues](https://github.com/SeanRiggs/advanced-ffprobe-m3u-cleaner/issues) for bugs and feature requests. Licensed under the [MIT license](LICENSE).
