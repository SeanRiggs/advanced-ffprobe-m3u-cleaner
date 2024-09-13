<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require 'vendor/autoload.php';

use M3uParser\M3uParser;

// Configuration
$config = [
    'm3uDirectory' => getenv('M3U_DIRECTORY') ?: '/app/m3u',
    'outputFile' => getenv('OUTPUT_FILE') ?: '/app/output/cleaned_playlist.m3u',
    'ffprobeCommand' => 'ffprobe -v error -show_entries stream=codec_type -of csv=p=0',
    'logFile' => getenv('LOG_FILE') ?: '/var/log/validator.log',
];

function logEvent($message) {
    global $config;
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    file_put_contents($config['logFile'], $logMessage, FILE_APPEND);
    echo $logMessage; // Also output to console
}

try {
    logEvent("Script started");
    logEvent("M3U Directory: " . $config['m3uDirectory']);
    logEvent("Output File: " . $config['outputFile']);
    logEvent("Log File: " . $config['logFile']);

    $parser = new M3uParser();
    $parser->addDefaultTags();
    $files = glob($config['m3uDirectory'] . '/*.m3u');
    
    logEvent("Found " . count($files) . " M3U files");
    
    if (empty($files)) {
        throw new Exception("No M3U files found in directory: " . $config['m3uDirectory']);
    }

    $playlist = [];
    $uniqueEntries = [];
    $totalInitialStreams = 0;
    $totalSkippedStreams = 0;
    $totalEntries = 0;
    $totalDuplicates = 0;

    foreach ($files as $file) {
        logEvent("Processing file: $file");
        $parsed = $parser->parseFile($file);
        logEvent("File parsed. Total entries: " . count($parsed));
        
        foreach ($parsed as $entry) {
            $totalEntries++;
            $url = $entry->getPath();
            $channelName = 'Unknown Channel';

            foreach ($entry->getExtTags() as $extTag) {
                if ($extTag instanceof \M3uParser\Tag\ExtInf) {
                    $channelName = $extTag->getTitle();
                    break;
                }
            }

            logEvent("Processing entry: $channelName - $url");

            $uniqueKey = $channelName . '|' . $url;
            if (isset($uniqueEntries[$uniqueKey])) {
                logEvent("Skipping duplicate: $channelName");
                $totalDuplicates++;
                continue;
            }

            if (quickUrlCheck($url)) {
                if (validateWithFFprobe($url)) {
                    $playlist[] = "#EXTINF:-1," . $channelName . "\n" . $url;
                    $uniqueEntries[$uniqueKey] = true;
                    $totalInitialStreams++;
                    logEvent("Added to playlist: $channelName");
                } else {
                    logEvent("Failed FFprobe validation: $url");
                    $totalSkippedStreams++;
                }
            } else {
                logEvent("Failed quick URL check: $url");
                $totalSkippedStreams++;
            }
        }
    }

    // Write the cleaned playlist
    $outputDir = dirname($config['outputFile']);
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0777, true);
        logEvent("Created output directory: $outputDir");
    }
    
    $playlistContent = "#EXTM3U\n" . implode("\n", $playlist);
    $bytesWritten = file_put_contents($config['outputFile'], $playlistContent);
    
    if ($bytesWritten === false) {
        throw new Exception("Failed to write playlist to file: " . $config['outputFile']);
    }

    logEvent("Playlist written to: " . $config['outputFile'] . " (Bytes written: $bytesWritten)");
    logEvent("Total entries processed: $totalEntries");
    logEvent("Total duplicate entries removed: $totalDuplicates");
    logEvent("Total streams after validation: $totalInitialStreams");
    logEvent("Total skipped streams: $totalSkippedStreams");

} catch (Exception $e) {
    logEvent("Error: " . $e->getMessage());
    exit(1);
}

// Quick URL check with curl
function quickUrlCheck($url) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $httpCode >= 200 && $httpCode < 400;
}

// Validate media presence with ffprobe
function validateWithFFprobe($url) {
    global $config;
    $command = $config['ffprobeCommand'] . " \"$url\" 2>&1";
    exec($command, $output, $returnVar);
    
    // Check if the output contains valid stream information
    $validOutput = false;
    foreach ($output as $line) {
        if (strpos($line, 'video') !== false || strpos($line, 'audio') !== false) {
            $validOutput = true;
            break;
        }
    }
    
    // Ignore specific errors and consider the stream valid if we found video or audio
    $ignoredErrors = [
        'Invalid data found when processing input',
        'non-existing SPS 0 referenced in buffering period'
    ];
    
    foreach ($ignoredErrors as $error) {
        $output = array_filter($output, function($line) use ($error) {
            return strpos($line, $error) === false;
        });
    }
    
    return $validOutput && (empty($output) || $returnVar === 0);
}

logEvent("Script finished");
