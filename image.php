<?php

$config = require __DIR__ . '/config.php';

try {
    $redis = new Redis();
    $redis->connect($config['redis_host'], $config['redis_port']);
    $image = $redis->get($config['redis_frame_key']);
} catch (Throwable $exception) {
    header('HTTP/1.1 503 Service Unavailable');
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Camera storage is unavailable.';
    exit;
}

if ($image !== false && $image !== '') {
    header('Content-Type: image/jpeg');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $image;
    exit;
}

header('HTTP/1.1 503 Service Unavailable');
header('Content-Type: text/plain; charset=UTF-8');
echo 'Camera frame is unavailable.';
