#!/usr/bin/php
<?php

$config = require __DIR__ . '/config.php';

function logMessage($message)
{
    global $config;

    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($config['log_file'], "[$timestamp] $message\n", FILE_APPEND);
}

function sendTelegramMessage($message)
{
    global $config;

    if ($config['telegram_bot_token'] === '' || $config['telegram_chat_id'] === '') {
        logMessage('Telegram settings are empty. Notification skipped.');
        return;
    }

    $url = "https://api.telegram.org/bot{$config['telegram_bot_token']}/sendMessage";
    $postData = [
        'chat_id' => $config['telegram_chat_id'],
        'text' => $message,
    ];
    $options = ['http' => [
        'method' => 'POST',
        'timeout' => 10,
        'header' => "Content-type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($postData),
    ]];

    $result = @file_get_contents($url, false, stream_context_create($options));
    if ($result === false) {
        logMessage('Telegram notification failed to send.');
    }
}

function notifyWithThrottle(Redis $redis, $alertKey, $message, $cooldownSeconds)
{
    global $config;

    $redisKey = $config['redis_alert_prefix'] . ':' . $alertKey;
    if ($redis->set($redisKey, time(), ['nx', 'ex' => $cooldownSeconds])) {
        logMessage("Alert sent: {$alertKey}");
        sendTelegramMessage($message);
    }
}

function startRecording()
{
    global $config;

    $cmd = sprintf(
        'ffmpeg -rtsp_transport tcp -i "%s" -r 5 -c:v copy -f segment -reset_timestamps 1 -segment_time 300 -strftime 1 "%s/%%Y-%%m-%%d_%%H-%%M-%%S.mp4" > /dev/null 2>&1 & echo $! > %s',
        $config['rtsp_url'],
        $config['storage_path'],
        $config['pid_file']
    );

    shell_exec($cmd);
    logMessage('ffmpeg recording started.');
}

function stopRecording()
{
    global $config;

    if (!file_exists($config['pid_file'])) {
        return;
    }

    $pid = (int) file_get_contents($config['pid_file']);
    if ($pid > 0 && posix_kill($pid, 0)) {
        posix_kill($pid, SIGTERM);
        logMessage("ffmpeg recording stopped for PID {$pid}.");
    }

    @unlink($config['pid_file']);
}

function checkRecording()
{
    global $config;

    if (!file_exists($config['pid_file'])) {
        logMessage('ffmpeg PID file not found.');
        return false;
    }

    $pid = (int) file_get_contents($config['pid_file']);
    if ($pid > 0 && posix_kill($pid, 0)) {
        return true;
    }

    logMessage("ffmpeg process not found for PID {$pid}.");
    return false;
}

function cleanupOldVideos()
{
    global $config;

    $files = glob($config['storage_path'] . '/*.mp4');
    $now = time();

    foreach ($files as $file) {
        if (is_file($file) && ($now - filemtime($file)) > ($config['max_storage_days'] * 86400)) {
            unlink($file);
            logMessage('Deleted old video: ' . basename($file));
        }
    }
}

function getLatestVideoTimestamp($storagePath)
{
    $files = glob($storagePath . '/*.mp4');
    if ($files === false || $files === []) {
        return null;
    }

    $latestTimestamp = null;
    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }

        $fileTimestamp = filemtime($file);
        if ($fileTimestamp === false) {
            continue;
        }

        if ($latestTimestamp === null || $fileTimestamp > $latestTimestamp) {
            $latestTimestamp = $fileTimestamp;
        }
    }

    return $latestTimestamp;
}

function isRecordingFresh($storagePath, $staleThreshold, $pidFile, $startupGraceSeconds)
{
    $latestTimestamp = getLatestVideoTimestamp($storagePath);
    if ($latestTimestamp === null) {
        if (!file_exists($pidFile)) {
            return false;
        }

        $pidFileTimestamp = filemtime($pidFile);
        if ($pidFileTimestamp === false) {
            return false;
        }

        return (time() - $pidFileTimestamp) <= $startupGraceSeconds;
    }

    return (time() - $latestTimestamp) <= $staleThreshold;
}

function isFrameFresh(Redis $redis, $frameUpdatedAtKey, $staleFrameThreshold)
{
    $lastFrameAt = (int) $redis->get($frameUpdatedAtKey);
    if ($lastFrameAt <= 0) {
        return false;
    }

    return (time() - $lastFrameAt) <= $staleFrameThreshold;
}

function updateFrameState(Redis $redis, $image)
{
    global $config;

    $frameHash = hash('sha256', $image);
    $previousHash = (string) $redis->get($config['redis_frame_hash_key']);
    $now = time();

    $redis->set($config['redis_frame_key'], $image);
    $redis->set($config['redis_frame_updated_at_key'], $now);

    if ($previousHash === '' || $previousHash !== $frameHash) {
        $redis->set($config['redis_frame_hash_key'], $frameHash);
        $redis->set($config['redis_frame_changed_at_key'], $now);
        logMessage('Frame content changed.');
    }
}

function isFrameFrozen(Redis $redis, $frameChangedAtKey, $frozenFrameThreshold)
{
    $lastChangedAt = (int) $redis->get($frameChangedAtKey);
    if ($lastChangedAt <= 0) {
        return false;
    }

    return (time() - $lastChangedAt) > $frozenFrameThreshold;
}

function formatBytes($bytes)
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float) $bytes;
    $unitIndex = 0;

    while ($value >= 1024 && $unitIndex < count($units) - 1) {
        $value /= 1024;
        $unitIndex++;
    }

    return round($value, 2) . ' ' . $units[$unitIndex];
}

function getFreeDiskBytes($path)
{
    $freeBytes = @disk_free_space($path);
    if ($freeBytes === false) {
        logMessage("Failed to read free disk space for path: {$path}");
        return null;
    }

    return (int) $freeBytes;
}

$redis = new Redis();

try {
    $redis->connect($config['redis_host'], $config['redis_port']);
} catch (Exception $exception) {
    logMessage('Redis connection error: ' . $exception->getMessage());
    sendTelegramMessage('Camera service error: failed to connect to Redis.');
    exit(1);
}

if (!is_dir($config['storage_path'])) {
    mkdir($config['storage_path'], 0755, true);
    logMessage("Created video storage directory: {$config['storage_path']}");
}

logMessage('Camera service started.');
sendTelegramMessage('Camera service started.');

while (true) {
    $cmd = sprintf(
        'timeout %ds ffmpeg -rtsp_transport tcp -i "%s" -vf "crop=1920:720,scale=1870:720" -vframes 1 -q:v 2 -f image2pipe -vcodec mjpeg - 2>/dev/null',
        $config['capture_timeout_seconds'],
        $config['rtsp_url']
    );

    $image = shell_exec($cmd);
    if ($image !== null && $image !== '') {
        updateFrameState($redis, $image);
    } else {
        logMessage('Frame capture failed.');
        notifyWithThrottle(
            $redis,
            'capture_failed',
            'Camera problem: failed to capture a frame. Repeats are limited to once every 2 hours.',
            $config['telegram_cooldown_seconds']
        );
    }

    if (!checkRecording()) {
        startRecording();
        notifyWithThrottle(
            $redis,
            'recording_restarted',
            'Camera recording process was restarted.',
            $config['telegram_cooldown_seconds']
        );
    }

    if (checkRecording() && !isRecordingFresh(
        $config['storage_path'],
        $config['recording_stale_threshold'],
        $config['pid_file'],
        $config['recording_startup_grace_seconds']
    )) {
        logMessage('Recording process is alive, but video files are not updating.');
        stopRecording();
        startRecording();
        notifyWithThrottle(
            $redis,
            'recording_stale',
            'Camera recording may be stuck: ffmpeg is running, but the archive files are not updating. Recording was restarted.',
            $config['telegram_cooldown_seconds']
        );
    }

    if (!isFrameFresh($redis, $config['redis_frame_updated_at_key'], $config['stale_frame_threshold'])) {
        logMessage('Camera frame is stale.');
        notifyWithThrottle(
            $redis,
            'stale_frame',
            'Camera may be frozen: no fresh frame for more than 2 minutes. Repeats are limited to once every 2 hours.',
            $config['telegram_cooldown_seconds']
        );
    }

    if (isFrameFrozen($redis, $config['redis_frame_changed_at_key'], $config['frozen_frame_threshold'])) {
        logMessage('Camera frame appears frozen.');
        notifyWithThrottle(
            $redis,
            'frozen_frame',
            'Camera may be frozen: the image has not changed for too long. Repeats are limited to once every 2 hours.',
            $config['telegram_cooldown_seconds']
        );
    }

    $freeDiskBytes = getFreeDiskBytes($config['storage_path']);
    if ($freeDiskBytes !== null && $freeDiskBytes <= $config['minimum_free_disk_bytes']) {
        $message = sprintf(
            'Low disk space: %s left in %s. Minimum configured threshold is %s. Repeats are limited to once every 2 hours.',
            formatBytes($freeDiskBytes),
            $config['storage_path'],
            formatBytes($config['minimum_free_disk_bytes'])
        );
        logMessage($message);
        notifyWithThrottle(
            $redis,
            'low_disk_space',
            $message,
            $config['telegram_cooldown_seconds']
        );
    }

    cleanupOldVideos();
    sleep($config['capture_interval']);
}
