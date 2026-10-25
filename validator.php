<?php

declare(strict_types=1);

// This CLI intentionally has no Composer dependencies. Preserve playlist text
// instead of rebuilding EXTINF records and losing provider-specific metadata.
function logEvent(string $message, string $logFile): void
{
    $line = date('[Y-m-d H:i:s] ') . str_replace(["\r", "\n"], ' ', $message) . PHP_EOL;
    if (file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX) === false) {
        throw new RuntimeException('Cannot write the configured log file.');
    }
    fwrite(STDOUT, $line);
}

function ensureDirectory(string $directory): void
{
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create a configured output or log directory.');
    }
}

/** Run a direct child, draining bounded output and enforcing a wall-clock limit. */
function runProcess(array $arguments, float $timeout): array
{
    $process = @proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start FFprobe.');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $started = hrtime(true);
    $stdout = '';
    $stderr = '';
    $timedOut = false;
    $exitCode = -1;
    try {
        while (true) {
            foreach ([1, 2] as $index) {
                $chunk = stream_get_contents($pipes[$index]);
                if ($chunk !== false) {
                    if ($index === 1) {
                        $stdout .= substr($chunk, 0, max(0, 1048576 - strlen($stdout)));
                    } else {
                        $stderr .= substr($chunk, 0, max(0, 65536 - strlen($stderr)));
                    }
                }
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];
                // Capture the final bytes after the child exits.
                $stdout .= substr((string) stream_get_contents($pipes[1]), 0, max(0, 1048576 - strlen($stdout)));
                break;
            }
            if ((hrtime(true) - $started) / 1e9 >= $timeout) {
                $timedOut = true;
                proc_terminate($process);
                $grace = hrtime(true);
                while (proc_get_status($process)['running'] && (hrtime(true) - $grace) / 1e9 < 0.25) {
                    usleep(10000);
                }
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, 9);
                }
                break;
            }
            usleep(10000);
        }
    } finally {
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = proc_close($process);
        if ($exitCode < 0 && $closed >= 0) {
            $exitCode = $closed;
        }
    }
    return ['code' => $exitCode, 'stdout' => $stdout, 'timedOut' => $timedOut];
}

function supportedUrl(string $url): bool
{
    if (preg_match('/[\x00-\x20\x7f]/', $url)) {
        return false;
    }
    $parts = parse_url($url);
    return is_array($parts) && isset($parts['host']) && $parts['host'] !== ''
        && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true);
}

function probeStream(string $url, string $binary, int $timeout): string
{
    if (!supportedUrl($url)) {
        return 'unsupported URL';
    }
    $result = runProcess([
        $binary, '-v', 'error', '-rw_timeout', (string) ($timeout * 1000000),
        '-protocol_whitelist', 'http,https,tcp,tls,crypto,httpproxy',
        '-show_entries', 'stream=codec_type', '-of', 'json', '-i', $url,
    ], $timeout);
    if ($result['timedOut']) {
        return 'timeout';
    }
    if ($result['code'] !== 0) {
        return 'FFprobe failure';
    }
    $json = json_decode($result['stdout'], true);
    foreach (is_array($json) ? ($json['streams'] ?? []) : [] as $stream) {
        if (in_array($stream['codec_type'] ?? '', ['video', 'audio'], true)) {
            return 'valid';
        }
    }
    return 'no audio/video stream';
}

/** Split the title at the first comma outside quoted EXTINF attributes. */
function entryTitle(array $lines): string
{
    foreach ($lines as $line) {
        if (strncasecmp($line, '#EXTINF:', 8) !== 0) {
            continue;
        }
        $quoted = false;
        for ($index = 8, $length = strlen($line); $index < $length; $index++) {
            if ($line[$index] === '"') {
                $quoted = !$quoted;
            } elseif ($line[$index] === ',' && !$quoted) {
                return substr($line, $index + 1);
            }
        }
    }
    return '';
}

/** Read an extended channel list without interpreting/removing custom tags. */
function readPlaylist(string $file, ?string &$header): Generator
{
    $handle = @fopen($file, 'r');
    if ($handle === false) {
        throw new RuntimeException('Cannot read playlist.');
    }
    $pending = [];
    $firstLine = true;
    try {
        while (($line = fgets($handle, 2097153)) !== false) {
            if (strlen($line) >= 2097152 && !str_ends_with($line, "\n")) {
                throw new RuntimeException('Playlist line is too long.');
            }
            $line = rtrim($line, "\r\n");
            if ($firstLine) {
                $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
                $firstLine = false;
            }
            if (trim($line) === '') {
                continue;
            }
            if (str_starts_with($line, '#EXTM3U')) {
                $header ??= $line;
            } elseif (str_starts_with($line, '#EXT-X-')) {
                throw new RuntimeException('HLS manifests belong in stream URLs, not channel-list inputs.');
            } elseif (str_starts_with($line, '#')) {
                $pending[] = $line;
            } else {
                $url = trim($line);
                yield ['url' => $url, 'title' => entryTitle($pending), 'text' => implode("\n", [...$pending, $url])];
                $pending = [];
            }
        }
        if (!feof($handle)) {
            throw new RuntimeException('Error reading playlist.');
        }
    } finally {
        fclose($handle);
    }
}

function main(): int
{
    $input = getenv('M3U_DIRECTORY') ?: '/app/m3u';
    $output = getenv('OUTPUT_FILE') ?: '/app/output/cleaned_playlist.m3u';
    $log = getenv('LOG_FILE') ?: '/var/log/validator.log';
    $binary = getenv('FFPROBE_PATH') ?: 'ffprobe';
    $timeoutValue = getenv('PROBE_TIMEOUT_SECONDS');
    $timeout = $timeoutValue === false || $timeoutValue === '' ? 15 : filter_var($timeoutValue, FILTER_VALIDATE_INT);
    $lock = null;
    $temporary = null;
    try {
        if ($timeout === false || $timeout < 1 || $timeout > 600) {
            throw new RuntimeException('PROBE_TIMEOUT_SECONDS must be an integer from 1 to 600.');
        }
        if (!is_dir($input) || !is_readable($input)) {
            throw new RuntimeException('Input directory is missing or unreadable.');
        }
        ensureDirectory(dirname($output));
        ensureDirectory(dirname($log));
        $outputPath = realpath($output);
        if ($output === $log || ($outputPath !== false && $outputPath === realpath($log))) {
            throw new RuntimeException('Output and log must be different files.');
        }
        $lock = @fopen($output . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Output is locked by another run or is not writable.');
        }
        logEvent('Cleaner started. Stream URLs and FFprobe diagnostics are omitted from logs.', $log);
        $files = [];
        foreach (new DirectoryIterator($input) as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), ['m3u', 'm3u8'], true)
                && ($outputPath === false || $file->getRealPath() !== $outputPath)) {
                $files[] = $file->getPathname();
            }
        }
        sort($files, SORT_STRING);
        if (!$files) {
            throw new RuntimeException('No input .m3u or .m3u8 playlists found. Existing output retained.');
        }
        if (runProcess([$binary, '-version'], 5)['code'] !== 0) {
            throw new RuntimeException('FFprobe is unavailable. Existing output retained.');
        }
        $entries = [];
        $seen = [];
        $cache = [];
        $header = null;
        $processed = $duplicates = $skipped = $parseErrors = 0;
        foreach ($files as $file) {
            try {
                foreach (readPlaylist($file, $header) as $entry) {
                    $processed++;
                    // Preserve first accepted entry for the same exact title and URL.
                    $key = hash('sha256', serialize([$entry['title'], $entry['url']]));
                    if (isset($seen[$key])) {
                        $duplicates++;
                        continue;
                    }
                    $result = $cache[$entry['url']] ??= probeStream($entry['url'], $binary, $timeout);
                    if ($result !== 'valid') {
                        $skipped++;
                        logEvent('Entry ' . $processed . ' skipped: ' . $result . '.', $log);
                        continue;
                    }
                    $seen[$key] = true;
                    $entries[] = $entry['text'];
                }
            } catch (RuntimeException $error) {
                $parseErrors++;
                logEvent('An input playlist could not be fully read. ' . $error->getMessage(), $log);
            }
        }
        logEvent("Processed: $processed; accepted: " . count($entries) . "; duplicates: $duplicates; skipped: $skipped; input errors: $parseErrors.", $log);
        // Never publish partial input reads or replace a good playlist with an empty one.
        if ($parseErrors || !$entries) {
            logEvent('No replacement written; existing output retained.', $log);
            return 2;
        }
        $temporary = tempnam(dirname($output), '.playlist-');
        if ($temporary === false) {
            throw new RuntimeException('Cannot create temporary output.');
        }
        $text = ($header ?? '#EXTM3U') . "\n" . implode("\n", $entries) . "\n";
        if (file_put_contents($temporary, $text) !== strlen($text)) {
            throw new RuntimeException('Cannot write complete output.');
        }
        chmod($temporary, 0666 & ~umask());
        if (!@rename($temporary, $output)) {
            throw new RuntimeException('Cannot replace output file.');
        }
        $temporary = null;
        logEvent('Cleaned playlist written successfully.', $log);
        return 0;
    } catch (Throwable $error) {
        // Exceptions here describe local operations only, never probe output/URLs.
        fwrite(STDERR, 'Cleaner failed: ' . $error->getMessage() . PHP_EOL);
        return 1;
    } finally {
        if (is_string($temporary)) {
            @unlink($temporary);
        }
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

exit(main());
