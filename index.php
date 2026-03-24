<?php

$config = require __DIR__ . '/config.php';
$imageData = '';
$cameraAvailable = false;

try {
    $redis = new Redis();
    $redis->connect($config['redis_host'], $config['redis_port']);
    $image = $redis->get($config['redis_frame_key']);

    if ($image !== false && $image !== '') {
        $imageData = base64_encode($image);
        $cameraAvailable = true;
    }
} catch (Throwable $exception) {
    $cameraAvailable = false;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="style.css" rel="stylesheet" type="text/css"/>
    <title>Камера на двор 204/1</title>
    <script>
        function updateImage() {
            const img = document.getElementById('camera-frame');
            if (!img) {
                return;
            }

            const newImg = new Image();
            newImg.src = '/image.php?t=' + Date.now();
            newImg.onload = function () {
                img.src = newImg.src;
                document.body.classList.remove('camera-offline');
            };
            newImg.onerror = function () {
                document.body.classList.add('camera-offline');
            };
        }

        setInterval(updateImage, 6000);
    </script>
</head>
<body class="<?php echo $cameraAvailable ? '' : 'camera-offline'; ?>">
    <?php if ($cameraAvailable): ?>
        <img id="camera-frame" src="data:image/jpeg;base64,<?php echo $imageData; ?>" alt="Камера наблюдения" />
    <?php else: ?>
        <div class="camera-status">Камера временно недоступна</div>
    <?php endif; ?>
</body>
</html>
