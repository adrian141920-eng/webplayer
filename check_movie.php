<?php
session_start();
header('Content-Type: application/json');

// Verificar que el usuario esté logueado
if (!isset($_SESSION['user_logged'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

// Obtener datos del POST
$input = json_decode(file_get_contents('php://input'), true);
$tmdb_id = $input['tmdb_id'] ?? null;

if (!$tmdb_id) {
    http_response_code(400);
    echo json_encode(['error' => 'ID de película requerido']);
    exit;
}

try {
    // Obtener información de la película desde TMDB en ambos idiomas
    $tmdb_api_key = '6b8e3eaa1a03ebb45642e9531d8a76d2';
    
    // Obtener en español
    $tmdb_url_es = "https://api.themoviedb.org/3/movie/{$tmdb_id}?api_key={$tmdb_api_key}&language=es-ES";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $tmdb_url_es);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $tmdb_response_es = curl_exec($ch);
    curl_close($ch);
    
    // Obtener en inglés
    $tmdb_url_en = "https://api.themoviedb.org/3/movie/{$tmdb_id}?api_key={$tmdb_api_key}&language=en-US";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $tmdb_url_en);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $tmdb_response_en = curl_exec($ch);
    curl_close($ch);
    
    $movie_data_es = json_decode($tmdb_response_es, true);
    $movie_data_en = json_decode($tmdb_response_en, true);
    
    if ((!$movie_data_es || isset($movie_data_es['success']) && !$movie_data_es['success']) &&
        (!$movie_data_en || isset($movie_data_en['success']) && !$movie_data_en['success'])) {
        echo json_encode(['exists' => false, 'error' => 'Película no encontrada en TMDB']);
        exit;
    }
    
    // Recopilar todos los títulos posibles
    $all_titles = [];
    
    if ($movie_data_es && !isset($movie_data_es['success'])) {
        $all_titles[] = $movie_data_es['title'];
        $all_titles[] = $movie_data_es['original_title'];
    }
    
    if ($movie_data_en && !isset($movie_data_en['success'])) {
        $all_titles[] = $movie_data_en['title'];
        $all_titles[] = $movie_data_en['original_title'];
    }
    
    // Usar datos en inglés como principal si están disponibles
    $movie_data = $movie_data_en ?: $movie_data_es;
    $year = substr($movie_data['release_date'] ?? '', 0, 4);
    
    // Remover duplicados
    $all_titles = array_unique(array_filter($all_titles));
    
    error_log("DEBUG - All titles found: " . json_encode($all_titles));
    
    // Buscar en el catálogo IPTV del usuario
    $username = $_SESSION['username'];
    $password = $_SESSION['password'] ?? '';
    $server_url = $_SESSION['server_url'] ?? $_SESSION['server_name'] ?? '';
    
    // Debug - mostrar qué variables tenemos
    error_log("DEBUG - Username: " . $username);
    error_log("DEBUG - Password: " . (empty($password) ? 'EMPTY' : 'SET'));
    error_log("DEBUG - Server: " . $server_url);
    
    // Si no tienes contraseña en sesión, usar username como password (común en algunos sistemas)
    if (empty($password)) {
        $password = $username;
    }
    
    // Si no tienes la URL completa, construirla
    if (!str_contains($server_url, 'http')) {
        $server_url = 'http://' . $server_url;
    }
    
    // Obtener lista de películas del servidor IPTV
    $iptv_api_url = "{$server_url}/player_api.php?username={$username}&password={$password}&action=get_vod_streams";
    
    error_log("DEBUG - API URL: " . $iptv_api_url);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $iptv_api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $iptv_response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);
    
    error_log("DEBUG - HTTP Code: " . $http_code);
    error_log("DEBUG - Response length: " . strlen($iptv_response));
    error_log("DEBUG - First 500 chars: " . substr($iptv_response, 0, 500));
    
    if ($http_code !== 200 || !$iptv_response) {
        echo json_encode([
            'exists' => false, 
            'error' => 'No se pudo conectar al servidor IPTV',
            'debug' => [
                'url' => $iptv_api_url,
                'http_code' => $http_code,
                'curl_error' => $curl_error,
                'response' => substr($iptv_response, 0, 200),
                'session_vars' => [
                    'username' => $username,
                    'has_password' => !empty($_SESSION['password']),
                    'server_url' => $_SESSION['server_url'] ?? 'NOT_SET',
                    'server_name' => $_SESSION['server_name'] ?? 'NOT_SET'
                ]
            ]
        ]);
        exit;
    }
    
    $iptv_movies = json_decode($iptv_response, true);
    
    if (!$iptv_movies || !is_array($iptv_movies)) {
        echo json_encode([
            'exists' => false, 
            'error' => 'Respuesta inválida del servidor IPTV',
            'debug' => [
                'response_type' => gettype($iptv_movies),
                'json_error' => json_last_error_msg(),
                'raw_response' => substr($iptv_response, 0, 1000)
            ]
        ]);
        exit;
    }
    
    error_log("DEBUG - Total movies found: " . count($iptv_movies));
    error_log("DEBUG - First movie: " . json_encode($iptv_movies[0] ?? 'NONE'));
    
    // Buscar la película en el catálogo IPTV
    $found_movie = null;
    
    // Crear términos de búsqueda con TODOS los títulos (español e inglés)
    $search_terms = [];
    
    foreach ($all_titles as $title) {
        if (empty($title)) continue;
        
        $search_terms[] = $title;
        $search_terms[] = $title . " (" . $year . ")";
        $search_terms[] = preg_replace('/[^A-Za-z0-9\s]/', '', $title); // Sin caracteres especiales
        
        // Variaciones comunes
        $search_terms[] = str_replace(':', '', $title); // Sin dos puntos
        $search_terms[] = str_replace(' ', '', $title); // Sin espacios
    }
    
    // Remover duplicados y vacíos
    $search_terms = array_unique(array_filter($search_terms));
    
    error_log("DEBUG - All search terms: " . json_encode($search_terms));
    
    foreach ($iptv_movies as $iptv_movie) {
        $iptv_title = $iptv_movie['name'] ?? '';
        
        foreach ($search_terms as $search_term) {
            if (empty($search_term)) continue;
            
            // Búsqueda exacta
            if (strcasecmp($iptv_title, $search_term) === 0) {
                $found_movie = $iptv_movie;
                error_log("DEBUG - EXACT MATCH: " . $iptv_title . " = " . $search_term);
                break 2;
            }
            
            // Búsqueda parcial
            if (stripos($iptv_title, $search_term) !== false) {
                $found_movie = $iptv_movie;
                error_log("DEBUG - PARTIAL MATCH: " . $iptv_title . " contains " . $search_term);
                break 2;
            }
        }
    }
    
    if ($found_movie) {
        echo json_encode([
            'exists' => true,
            'stream_id' => $found_movie['stream_id'],
            'movie_name' => $found_movie['name'],
            'container_extension' => $found_movie['container_extension'] ?? 'mp4'
        ]);
    } else {
        echo json_encode([
            'exists' => false,
            'searched_terms' => $search_terms,
            'total_movies' => count($iptv_movies),
            'sample_titles' => array_slice(array_column($iptv_movies, 'name'), 0, 5)
        ]);
    }
    
} catch (Exception $e) {
    error_log("Error en check_movie.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Error interno del servidor',
        'message' => $e->getMessage()
    ]);
}
?>