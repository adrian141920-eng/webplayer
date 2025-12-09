<?php
// search_ajax.php - Archivo SOLO para búsquedas AJAX
session_start();

// Verificar que sea una petición POST válida
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['search_query'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Petición inválida']);
    exit;
}

// Verificar que el usuario esté logueado
if (!isset($_SESSION['user_logged'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

// Headers para JSON
header('Content-Type: application/json');
header('Cache-Control: no-cache');

// Variables de sesión
$username = $_SESSION['username'];
$password = $_SESSION['password'] ?? '';
$server_url = $_SESSION['server_url'] ?? '';

$query = trim($_POST['search_query']);
$results = [];

if (strlen($query) >= 2 && !empty($server_url) && !empty($username) && !empty($password)) {
    
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.36';
    
    // Función para hacer API calls
    function makeApiCall($url, $userAgent) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            error_log('Error CURL: ' . curl_error($ch));
            curl_close($ch);
            return [];
        }
        
        curl_close($ch);
        
        if ($httpCode !== 200) {
            error_log("Error API: HTTP " . $httpCode);
            return [];
        }
        
        return json_decode($response, true) ?? [];
    }
    
    // Función para obtener películas
    function getMovies($username, $password, $server_url, $userAgent) {
        $cache_file = 'cache/movies_' . md5($username . $server_url) . '.json';
        
        // Verificar cache
        if (file_exists($cache_file) && (time() - filemtime($cache_file) < 3600)) {
            return json_decode(file_get_contents($cache_file), true) ?? [];
        }
        
        // Llamada a la API
        $url = rtrim($server_url, '/') . "/player_api.php?username=" . urlencode($username) . "&password=" . urlencode($password) . "&action=get_vod_streams";
        $data = makeApiCall($url, $userAgent);
        
        // Guardar en cache
        if (!empty($data) && is_array($data)) {
            if (!is_dir('cache')) {
                mkdir('cache', 0777, true);
            }
            file_put_contents($cache_file, json_encode($data));
        }
        
        return $data;
    }
    
    // Función para obtener series
    function getSeries($username, $password, $server_url, $userAgent) {
        $cache_file = 'cache/series_' . md5($username . $server_url) . '.json';
        
        // Verificar cache
        if (file_exists($cache_file) && (time() - filemtime($cache_file) < 3600)) {
            return json_decode(file_get_contents($cache_file), true) ?? [];
        }
        
        // Llamada a la API
        $url = rtrim($server_url, '/') . "/player_api.php?username=" . urlencode($username) . "&password=" . urlencode($password) . "&action=get_series";
        $data = makeApiCall($url, $userAgent);
        
        // Guardar en cache
        if (!empty($data) && is_array($data)) {
            if (!is_dir('cache')) {
                mkdir('cache', 0777, true);
            }
            file_put_contents($cache_file, json_encode($data));
        }
        
        return $data;
    }
    
    $search_lower = strtolower($query);
    
    try {
        // Buscar en películas
        $movies_api = getMovies($username, $password, $server_url, $userAgent);
        
        if (is_array($movies_api)) {
            foreach ($movies_api as $movie) {
                if (isset($movie['name']) && stripos(strtolower($movie['name']), $search_lower) !== false) {
                    $results[] = [
                        'id' => $movie['stream_id'] ?? '',
                        'title' => htmlspecialchars($movie['name'] ?? ''),
                        'type' => 'movie'
                    ];
                    if (count($results) >= 5) break;
                }
            }
        }
        
        // Buscar en series si aún hay espacio
        if (count($results) < 8) {
            $series_api = getSeries($username, $password, $server_url, $userAgent);
            
            if (is_array($series_api)) {
                foreach ($series_api as $series) {
                    if (isset($series['name']) && stripos(strtolower($series['name']), $search_lower) !== false) {
                        $results[] = [
                            'id' => $series['series_id'] ?? '',
                            'title' => htmlspecialchars($series['name'] ?? ''),
                            'type' => 'series'
                        ];
                        if (count($results) >= 8) break;
                    }
                }
            }
        }
        
    } catch (Exception $e) {
        error_log("Error en búsqueda: " . $e->getMessage());
        echo json_encode(['error' => 'Error interno del servidor']);
        exit;
    }
}

// Respuesta final
echo json_encode([
    'success' => true, 
    'results' => $results, 
    'total' => count($results),
    'query' => $query
]);
?>