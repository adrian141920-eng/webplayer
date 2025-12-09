<?php
session_start();

// Verificar que el usuario esté logueado
if (!isset($_SESSION['user_logged'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

// Configurar headers para JSON
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Obtener la URL del parámetro GET
$url = $_GET['url'] ?? '';

if (empty($url)) {
    http_response_code(400);
    echo json_encode(['error' => 'URL requerida']);
    exit;
}

// Validar que la URL sea de TheSportsDB
if (strpos($url, 'thesportsdb.com') === false) {
    http_response_code(400);
    echo json_encode(['error' => 'URL no permitida']);
    exit;
}

// Configuración de cache
$cacheDir = 'cache/';
$cacheFile = $cacheDir . md5($url) . '.json';
$cacheTime = 3600; // 1 hora

// Crear directorio cache si no existe
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}

// Verificar cache
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
    $data = file_get_contents($cacheFile);
    echo $data;
    exit;
}

// Función para obtener datos con múltiples métodos
function fetchData($url) {
    // Método 1: cURL
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; IPTVSports/1.0)');
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        
        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($data && $httpCode == 200 && empty($error)) {
            return $data;
        }
    }
    
    // Método 2: file_get_contents con contexto
    if (ini_get('allow_url_fopen')) {
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'Mozilla/5.0 (compatible; IPTVSports/1.0)',
                'follow_location' => true,
                'max_redirects' => 3
            ]
        ]);
        
        $data = @file_get_contents($url, false, $context);
        if ($data !== false) {
            return $data;
        }
    }
    
    return false;
}

// Obtener datos
$data = fetchData($url);

if ($data === false) {
    http_response_code(500);
    echo json_encode(['error' => 'No se pudo obtener los datos']);
    exit;
}

// Validar que sea JSON válido
$jsonData = json_decode($data, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(500);
    echo json_encode(['error' => 'Datos inválidos recibidos']);
    exit;
}

// Guardar en cache
file_put_contents($cacheFile, $data);

// Devolver datos
echo $data;
?>