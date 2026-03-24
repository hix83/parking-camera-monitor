# Parking Camera Monitor

Простой и надежный RTSP-мониторинг одной IP-камеры с веб-просмотром, архивом и уведомлениями в Telegram.

Проект рассчитан на локальную сеть. Сервис получает кадры по RTSP, публикует последнее изображение через PHP, пишет архив в mp4 и уведомляет владельца, если:

- камера перестала отдавать свежие кадры
- камера зависла и долго отдает одну и ту же картинку
- был перезапущен процесс записи
- на диске стало мало свободного места

Одинаковые уведомления ограничиваются по частоте и отправляются не чаще одного раза в 2 часа.

## Схема работы

```text
IP camera -> RTSP -> ffmpeg -> PHP service.php
                             |-> Redis (last frame + health metadata)
                             |-> mp4 archive on disk
                             |-> Telegram notifications

Browser -> index.php -> image.php -> Redis -> latest JPEG frame
```

## Состав проекта

- [service.php](service.php) — основной сервис захвата кадров, записи архива и мониторинга состояния.
- [index.php](index.php) — страница просмотра камеры.
- [image.php](image.php) — отдает актуальный JPEG из Redis.
- [config.php](config.php) — основной конфиг проекта с комментариями.
- [config.local.php.example](config.local.php.example) — шаблон локального конфига с секретами.
- [capture-frame.service](capture-frame.service) — systemd-сервис.
- [capture-frame.timer](capture-frame.timer) — systemd-таймер для регулярного перезапуска.

## Требования

- Linux
- PHP CLI и PHP для веба
- расширение `php-redis`
- `ffmpeg`
- `timeout` из `coreutils`
- Redis
- systemd

## Настройка

1. Проверьте значения в [config.php](config.php).
2. Создайте локальный конфиг рядом с проектом на основе [config.local.php.example](config.local.php.example).
3. Укажите в локальном конфиге:
   - `telegram_bot_token`
   - `telegram_chat_id`
4. Убедитесь, что путь `storage_path` существует или может быть создан сервисом.
5. Убедитесь, что Redis доступен по `redis_host` и `redis_port`.
6. Убедитесь, что RTSP-поток камеры доступен с сервера.

Пример `config.local.php`:

```php
<?php

return [
    'telegram_bot_token' => 'your-bot-token',
    'telegram_chat_id' => 'your-chat-id',
];
```

## Запуск

Для ручной проверки:

```bash
php /opt/parking-camera-monitor/service.php
```

Для systemd:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now capture-frame.service
sudo systemctl enable --now capture-frame.timer
```

Перед запуском проверьте путь в `ExecStart` внутри [capture-frame.service](capture-frame.service).

## Что контролирует сервис

### 1. Нет новых кадров

Если камера не отдает новые кадры дольше, чем `stale_frame_threshold`, отправляется уведомление.

### 2. Картинка зависла

Если поток идет, но изображение не меняется дольше, чем `frozen_frame_threshold`, отправляется уведомление.

### 3. Мало места на диске

Если свободное место на диске архива становится меньше `minimum_free_disk_bytes`, отправляется уведомление.

### 4. Запись архива

Архив записывается сегментами по 5 минут. Старые файлы удаляются по `max_storage_days`.

## Подходящие камеры

Проекту подходит любая IP-камера, которая:

- умеет отдавать постоянный RTSP-поток
- стабильно работает в локальной сети
- не требует облака для локального просмотра
- желательно поддерживает H.264 или H.265
- для улицы имеет защиту не ниже IP66

Для такой архитектуры обычно лучше подходят:

- фиксированные широкоугольные PoE-камеры, если нужен постоянный обзор без поворотов
- поворотные RTSP-камеры, если важнее покрыть большую площадь одной камерой

## Лицензия

Проект распространяется под лицензией MIT. См. [LICENSE](LICENSE).
