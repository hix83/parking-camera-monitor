<?php

$config = [
    // RTSP-адрес камеры. Именно этот поток читает ffmpeg для превью и архива.
    'rtsp_url' => 'rtsp://camera.local:554/stream1',

    // Хост Redis для хранения последнего кадра и служебных меток состояния.
    'redis_host' => '127.0.0.1',

    // Порт Redis.
    'redis_port' => 6379,

    // Ключ Redis, в котором хранится последний JPEG-кадр для веб-страницы.
    'redis_frame_key' => 'camera_frame',

    // Ключ Redis со временем последнего успешного получения кадра.
    'redis_frame_updated_at_key' => 'camera_frame_updated_at',

    // Ключ Redis с hash последнего кадра для контроля зависания картинки.
    'redis_frame_hash_key' => 'camera_frame_hash',

    // Ключ Redis со временем последнего реального изменения картинки.
    'redis_frame_changed_at_key' => 'camera_frame_changed_at',

    // Префикс ключей Redis для throttling Telegram-уведомлений.
    'redis_alert_prefix' => 'camera_alert',

    // Пауза между циклами сервиса в секундах.
    'capture_interval' => 5,

    // Максимальное время одного запуска ffmpeg для получения одиночного кадра.
    'capture_timeout_seconds' => 30,

    // Через сколько секунд без новых кадров считаем камеру недоступной.
    'stale_frame_threshold' => 120,

    // Через сколько секунд без изменения картинки считаем, что камера зависла.
    'frozen_frame_threshold' => 600,

    // Минимальный интервал между одинаковыми уведомлениями в Telegram.
    'telegram_cooldown_seconds' => 7200,

    // Путь для хранения видеоархива.
    'storage_path' => '/md/samba/video/camera',

    // Минимально допустимый остаток свободного места на диске архива.
    'minimum_free_disk_bytes' => 20 * 1024 * 1024 * 1024,

    // Файл лога сервиса.
    'log_file' => '/var/log/camera_recorder.log',

    // Сколько дней хранить архивные mp4-файлы перед удалением.
    'max_storage_days' => 7,

    // Токен Telegram-бота. Обычно задается в config.local.php и не коммитится в git.
    'telegram_bot_token' => '',

    // ID чата или пользователя для получения уведомлений.
    'telegram_chat_id' => '',

    // Файл с PID фонового ffmpeg-процесса, который пишет архив.
    'pid_file' => '/tmp/ffmpeg_camera1.pid',
];

$localConfigPath = __DIR__ . '/config.local.php';
if (is_file($localConfigPath)) {
    $localConfig = require $localConfigPath;
    if (is_array($localConfig)) {
        $config = array_merge($config, $localConfig);
    }
}

return $config;
