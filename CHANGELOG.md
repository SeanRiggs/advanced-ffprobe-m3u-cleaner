# Changelog

## 2026-10-25

- Discover both M3U/M3U8 extensions, case-insensitively; exclude the configured output.
- Pass stream URLs directly to FFprobe without a shell. Restrict stream protocols and apply both network and process deadlines.
- Replace the HEAD precheck and substring matching with FFprobe's structured stream metadata.
- Preserve original entry metadata and directives; keep first accepted duplicate by exact title/URL.
- Retain previous output on all-invalid/empty/error runs, and replace successful output atomically under a process lock.
- Stop recording channel URLs/FFprobe diagnostics in logs.
- Refresh Docker to PHP 8.4, install FFprobe, use a non-root default, and remove unused runtime Composer dependencies.
- Add local-media regression tests, architecture checks, and publication of the exact tested images.

### Existing users

Keep your input/output/log mounts. Re-pull the published image or rebuild from source.
The container now defaults to a non-root user; match its UID/GID to your writable host folders.
A failed/empty validation run retains the old output and exits nonzero; update schedulers that previously assumed an empty output meant success.
Only HTTP(S) channel URLs are supported. Per-player directives are preserved, but not applied to FFprobe requests.
