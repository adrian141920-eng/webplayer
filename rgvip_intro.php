<?php
session_start();
$videoPath = 'splash_rgvip/intro.mp4';
$videoDir = dirname($videoPath);

// Crear directorio si no existe
if (!file_exists($videoDir)) {
    mkdir($videoDir, 0755, true);
}

// Procesar subida de video
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['video'])) {
    $file = $_FILES['video'];
    
    // Validar tipo de archivo
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (strpos($mimeType, 'mp4') !== false) {
        if (move_uploaded_file($file['tmp_name'], $videoPath)) {
            $_SESSION['video_message'] = ['type' => 'success', 'text' => '✅ Video subido correctamente'];
        } else {
            $_SESSION['video_message'] = ['type' => 'danger', 'text' => '❌ Error al guardar el video'];
        }
    } else {
        $_SESSION['video_message'] = ['type' => 'warning', 'text' => '⚠ Solo se permiten archivos MP4'];
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$timestamp = file_exists($videoPath) ? filemtime($videoPath) : 0;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrar Video Introductorio</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #121212;
            color: #ffffff;
            font-family: 'Helvetica Neue', Arial, sans-serif;
            padding: 20px;
        }
        .video-container {
            max-width: 800px;
            margin: 0 auto;
            background: #1e1e1e;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.6);
            position: relative;
        }
        video {
            width: 100%;
            max-height: 400px;
            background: #000;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .btn-close-window {
            position: absolute;
            top: 15px;
            right: 15px;
            color: white;
            background-color: #343a40;
            border: none;
        }
        .btn-close-window:hover {
            background-color: #495057;
        }
        .form-label, .alert {
            color: #ffffff;
        }
        .alert-warning {
            background-color: #664d03;
            border-color: #664d03;
        }
        .alert-success {
            background-color: #1f513f;
            border-color: #1f513f;
        }
        .alert-danger {
            background-color: #58151c;
            border-color: #58151c;
        }
        .alert-warning,
        .alert-success,
        .alert-danger {
            color: #fff;
        }
    </style>
</head>
<body>
    <div class="video-container">
        <button onclick="window.close()" class="btn btn-close-window">
            <i class="fas fa-times"></i> Cerrar
        </button>

        <h2 class="mb-4"><i class="fas fa-film me-2"></i>Video Introductorio</h2>

        <?php if (isset($_SESSION['video_message'])): ?>
            <div class="alert alert-<?= $_SESSION['video_message']['type'] ?>">
                <?= $_SESSION['video_message']['text'] ?>
            </div>
            <?php unset($_SESSION['video_message']); ?>
        <?php endif; ?>

        <?php if (file_exists($videoPath)): ?>
            <video controls>
                <source src="<?= $videoPath ?>?v=<?= $timestamp ?>" type="video/mp4">
                Tu navegador no soporta video HTML5.
            </video>
        <?php else: ?>
            <div class="alert alert-warning mb-4">
                No hay video cargado actualmente
            </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
            <div class="mb-3">
                <label for="videoFile" class="form-label">Seleccionar archivo MP4:</label>
                <input class="form-control bg-dark text-white" type="file" id="videoFile" name="video" accept="video/mp4" required>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-upload me-2"></i> Subir Video
            </button>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>