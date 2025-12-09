<?php
session_start();

if (!isset($_SESSION['user_logged'])) {
    header('Location: index.php');
    exit;
}

$username = $_SESSION['username'];
$password = $_SESSION['password'];
$server_url = $_SESSION['server_url'];

define('TMDB_API_KEY', '6b8e3eaa1a03ebb45642e9531d8a76d2');
define('TMDB_API_URL', 'https://api.themoviedb.org/3');
define('TMDB_IMG_URL', 'https://image.tmdb.org/t/p/');

function tmdbRequest($endpoint, $params = []) {
    $params['api_key'] = TMDB_API_KEY;
    $params['language'] = 'en-US';
    $url = TMDB_API_URL . $endpoint . '?' . http_build_query($params);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return $response ? json_decode($response, true) : null;
}

function makeApiRequest($action, $params = []) {
    global $username, $password, $server_url;

    $base_params = [
        'username' => $username,
        'password' => $password,
        'action'   => $action
    ];

    $all_params = array_merge($base_params, $params);
    $query_string = http_build_query($all_params);
    $full_url = rtrim($server_url, '/') . '/player_api.php?' . $query_string;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $full_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'XtremePlayer/1.0');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response && $http_code == 200) {
        return json_decode($response, true);
    }

    return false;
}

function checkImageExists($url) {
    if (strpos($url, 'data:') === 0) return true;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    
    return ($httpCode >= 200 && $httpCode < 300 && strpos($contentType, 'image') !== false);
}

function cleanTitle($title) {
    if (empty($title)) return '';
    
    $title = preg_replace('/\s*\(\d{4}\)\s*$/', '', $title);
    $title = preg_replace('/\s*(SD|HD|4K|1080p|720p|480p)\s*$/i', '', $title);
    $title = preg_replace('/\s*\[[^\]]*\]\s*$/', '', $title);
    $title = trim($title);
    
    return $title;
}

$series_id = $_GET['id'] ?? null;
if (!$series_id) {
    header('Location: series.php');
    exit;
}

$series_info = makeApiRequest('get_series_info', ['series_id' => $series_id]);

if (!$series_info || !isset($series_info['info'])) {
    header('Location: series.php');
    exit;
}

$info = $series_info['info'];
$seasons = $series_info['episodes'] ?? [];

$clean_title = cleanTitle($info['name'] ?? '');

$tmdb_data = null;
$tmdb_id = null;

if (!empty($clean_title)) {
    $clean_name = preg_replace('/\s*\(\d{4}\)\s*/', '', $clean_title);
    $clean_name = trim($clean_name);
    
    $search_results = tmdbRequest('/search/tv', ['query' => $clean_name]);
    
    if (empty($search_results['results'])) {
        $search_parts = explode(' ', $clean_name);
        if (count($search_parts) > 1) {
            $search_results = tmdbRequest('/search/tv', ['query' => $search_parts[0]]);
        }
    }
    
    if (!empty($search_results['results'])) {
        foreach ($search_results['results'] as $result) {
            if (!empty($result['backdrop_path'])) {
                $tmdb_id = $result['id'];
                $tmdb_data = tmdbRequest('/tv/' . $tmdb_id, ['append_to_response' => 'credits,images,videos']);
                break;
            }
        }
        
        if (!$tmdb_data && !empty($search_results['results'])) {
            $tmdb_id = $search_results['results'][0]['id'];
            $tmdb_data = tmdbRequest('/tv/' . $tmdb_id, ['append_to_response' => 'credits,images,videos']);
        }
    }
}

$backdrop_sources = [];
$poster_sources = [];

if ($tmdb_data) {
    if (!empty($tmdb_data['backdrop_path'])) {
        $backdrop_sources[] = [
            'url' => TMDB_IMG_URL . 'original' . $tmdb_data['backdrop_path'],
            'type' => 'tmdb_backdrop_original'
        ];
        $backdrop_sources[] = [
            'url' => TMDB_IMG_URL . 'w1280' . $tmdb_data['backdrop_path'],
            'type' => 'tmdb_backdrop_large'
        ];
    }
    
    if (!empty($tmdb_data['images']['backdrops'])) {
        foreach (array_slice($tmdb_data['images']['backdrops'], 0, 3) as $img) {
            $backdrop_sources[] = [
                'url' => TMDB_IMG_URL . 'original' . $img['file_path'],
                'type' => 'tmdb_backdrop_alt'
            ];
        }
    }
    
    if (!empty($tmdb_data['poster_path'])) {
        $poster_sources[] = [
            'url' => TMDB_IMG_URL . 'w500' . $tmdb_data['poster_path'],
            'type' => 'tmdb_poster'
        ];
    }
}

if (!empty($info['backdrop_path'])) {
    $backdrop_sources[] = [
        'url' => $info['backdrop_path'],
        'type' => 'iptv_backdrop'
    ];
}

if (!empty($info['cover'])) {
    $poster_sources[] = [
        'url' => $info['cover'],
        'type' => 'iptv_cover'
    ];
}

$default_image = "data:image/svg+xml;base64," . base64_encode('
<svg xmlns="http://www.w3.org/2000/svg" width="1280" height="720" viewBox="0 0 1280 720">
    <defs>
        <linearGradient id="bg" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" style="stop-color:#1a1a1a"/>
            <stop offset="50%" style="stop-color:#2d2d2d"/>
            <stop offset="100%" style="stop-color:#1a1a1a"/>
        </linearGradient>
    </defs>
    <rect width="100%" height="100%" fill="url(#bg)"/>
    <circle cx="640" cy="360" r="80" fill="#444" opacity="0.3"/>
    <polygon points="620,330 620,390 680,360" fill="#666"/>
    <text x="640" y="480" text-anchor="middle" fill="#666" font-family="Arial" font-size="24" font-weight="bold">' . 
    htmlspecialchars($clean_title ?: 'Series') . '</text>
</svg>');

$all_image_sources = array_merge($backdrop_sources, $poster_sources, [
    ['url' => $default_image, 'type' => 'default']
]);

$selected_image = null;
foreach ($all_image_sources as $source) {
    if (checkImageExists($source['url'])) {
        $selected_image = $source;
        break;
    }
}

if (!$selected_image) {
    $selected_image = ['url' => $default_image, 'type' => 'default'];
}

$backdrop_url = $selected_image['url'];
$backdrop_type = $selected_image['type'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($clean_title ?: 'Series') ?> - IPTV Pro</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, sans-serif;
            background: #0f171e;
            color: #fff;
            overflow-x: hidden;
            height: 100vh;
            position: relative;
        }
        
        /* Fondo fijo estático */
        .fixed-background {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url('<?= htmlspecialchars($backdrop_url) ?>');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            z-index: 1;
        }
        
        .fixed-background::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(
                180deg,
                rgba(15,23,30,0.2) 0%,
                rgba(15,23,30,0.6) 30%,
                rgba(15,23,30,0.8) 70%,
                rgba(15,23,30,0.95) 100%
            );
        }
        
        /* Contenedor scrolleable */
        .scrollable-content {
            position: relative;
            z-index: 2;
            min-height: 100vh;
            margin-left: 0;
        }
        
        /* Header con botón de regreso */
        .header-section {
            position: relative;
            z-index: 10;
            padding: 30px 60px 0;
        }
        
        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .back-button:hover {
            background: rgba(0,0,0,0.8);
            border-color: rgba(255,255,255,0.4);
            transform: translateY(-1px);
        }
        
        /* Sección principal de información */
        .hero-content-section {
            padding: 80px 60px 60px;
            max-width: 1200px;
        }
        
        .series-info {
            max-width: 600px;
        }
        
        .series-title {
            font-size: 3.5rem;
            font-weight: 700;
            margin-bottom: 20px;
            text-shadow: 2px 2px 8px rgba(0,0,0,0.8);
            line-height: 1.1;
        }
        
        .series-metadata {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            font-size: 16px;
            flex-wrap: wrap;
        }
        
        .match-score {
            color: #46d369;
            font-weight: 600;
        }
        
        .year {
            background: rgba(0,0,0,0.7);
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        .rating {
            background: #ffa500;
            color: #000;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
        }
        
        .seasons-count {
            background: rgba(255,255,255,0.2);
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            border: 1px solid rgba(255,255,255,0.3);
        }
        
        .series-overview {
            font-size: 18px;
            line-height: 1.6;
            margin-bottom: 35px;
            text-shadow: 1px 1px 4px rgba(0,0,0,0.8);
            color: rgba(255,255,255,0.95);
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-bottom: 35px;
            flex-wrap: wrap;
        }
        
        .play-button {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 28px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            background: #fff;
            color: #000;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        }
        
        .play-button:hover {
            background: rgba(255,255,255,0.9);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.4);
        }
        
        .info-button {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 28px;
            border: 2px solid rgba(255,255,255,0.4);
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            background: rgba(0,0,0,0.4);
            color: #fff;
            backdrop-filter: blur(10px);
        }
        
        .info-button:hover {
            background: rgba(255,255,255,0.2);
            border-color: rgba(255,255,255,0.6);
            transform: translateY(-2px);
        }
        
        .genres {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 40px;
        }
        
        .genre {
            color: rgba(255,255,255,0.9);
            font-size: 15px;
            font-weight: 500;
            text-shadow: 1px 1px 3px rgba(0,0,0,0.8);
        }
        
        /* Sección de reparto */
        .cast-section {
            margin-top: 40px;
            padding: 0 60px;
            max-width: 1200px;
        }
        
        .cast-title {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 20px;
            color: rgba(255,255,255,0.95);
            text-shadow: 1px 1px 4px rgba(0,0,0,0.8);
        }
        
        .cast-grid {
            display: flex;
            gap: 20px;
            overflow-x: auto;
            padding-bottom: 10px;
            scrollbar-width: thin;
            scrollbar-color: rgba(255,255,255,0.3) transparent;
        }
        
        .cast-grid::-webkit-scrollbar {
            height: 6px;
        }
        
        .cast-grid::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.1);
            border-radius: 3px;
        }
        
        .cast-grid::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.3);
            border-radius: 3px;
        }
        
        .cast-member {
            flex-shrink: 0;
            width: 90px;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .cast-member:hover {
            transform: translateY(-5px);
        }
        
        .cast-photo {
            width: 90px;
            height: 120px;
            border-radius: 10px;
            object-fit: cover;
            background: rgba(255,255,255,0.1);
            margin-bottom: 8px;
            border: 2px solid rgba(255,255,255,0.2);
            box-shadow: 0 4px 15px rgba(0,0,0,0.5);
            transition: all 0.3s ease;
        }
        
        .cast-member:hover .cast-photo {
            border-color: rgba(255,255,255,0.4);
            box-shadow: 0 8px 25px rgba(0,0,0,0.6);
        }
        
        .cast-name {
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 4px;
            color: rgba(255,255,255,0.95);
            text-shadow: 1px 1px 3px rgba(0,0,0,0.8);
            line-height: 1.3;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .cast-character {
            font-size: 10px;
            color: rgba(255,255,255,0.7);
            text-shadow: 1px 1px 3px rgba(0,0,0,0.8);
            line-height: 1.2;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        /* Sección de temporadas y episodios */
        .episodes-section h2 {
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 30px;
            color: rgba(255,255,255,0.95);
        }
        
        .season-selector-container {
            margin-bottom: 40px;
        }
        
        .season-selector {
            background: rgba(0,0,0,0.8);
            border: 2px solid rgba(255,255,255,0.3);
            color: white;
            padding: 14px 24px;
            font-size: 16px;
            font-weight: 600;
            border-radius: 10px;
            cursor: pointer;
            min-width: 220px;
            backdrop-filter: blur(10px);
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6,9 12,15 18,9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 16px center;
            background-size: 16px;
            padding-right: 50px;
            transition: all 0.3s ease;
        }
        
        .season-selector:focus {
            outline: none;
            border-color: rgba(255,255,255,0.6);
            background: rgba(0,0,0,0.9);
        }
        
        .season-selector:hover {
            background: rgba(0,0,0,0.9);
            border-color: rgba(255,255,255,0.5);
        }
        
        .season-selector option {
            background: #1a1a1a;
            color: white;
            padding: 15px;
        }
        
        .episodes-container {
            margin-top: 30px;
        }
        
        .episodes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 30px;
        }
        
        .episode-card {
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(15px);
            border-radius: 15px;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            border: 1px solid rgba(255,255,255,0.1);
            box-shadow: 0 8px 30px rgba(0,0,0,0.4);
        }
        
        .episode-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.6);
            border-color: rgba(255,255,255,0.3);
            background: rgba(0,0,0,0.8);
        }
        
        .episode-thumbnail {
            position: relative;
            width: 100%;
            height: 200px;
            background: #000;
            overflow: hidden;
        }
        
        .episode-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.4s ease;
        }
        
        .episode-card:hover .episode-image {
            transform: scale(1.08);
        }
        
        .episode-number {
            position: absolute;
            top: 12px;
            left: 12px;
            background: rgba(0,0,0,0.8);
            backdrop-filter: blur(8px);
            color: white;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        .episode-duration {
            position: absolute;
            bottom: 12px;
            right: 12px;
            background: rgba(0,0,0,0.8);
            backdrop-filter: blur(8px);
            color: white;
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        .play-overlay {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 70px;
            height: 70px;
            background: rgba(255,255,255,0.95);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: all 0.3s ease;
            box-shadow: 0 6px 25px rgba(0,0,0,0.4);
        }
        
        .episode-card:hover .play-overlay {
            opacity: 1;
            transform: translate(-50%, -50%) scale(1.1);
        }
        
        .play-overlay i {
            color: #000;
            font-size: 1.6rem;
            margin-left: 4px;
        }
        
        .episode-info {
            padding: 25px;
        }
        
        .episode-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 12px;
            line-height: 1.4;
            color: rgba(255,255,255,0.95);
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .episode-description {
            font-size: 14px;
            color: rgba(255,255,255,0.8);
            line-height: 1.6;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .loading {
            text-align: center;
            padding: 80px 20px;
            color: rgba(255,255,255,0.7);
        }
        
        .spinner {
            border: 3px solid rgba(255,255,255,0.2);
            border-top: 3px solid rgba(255,255,255,0.8);
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 0 auto 25px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Debug indicator */
        .image-type-indicator {
            position: fixed;
            top: 100px;
            right: 20px;
            background: rgba(0,0,0,0.8);
            color: #fff;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            z-index: 1000;
            display: none;
        }
        
        /* RESPONSIVE */
        @media (max-width: 992px) {
            .scrollable-content {
                margin-left: 0;
            }
            
            .header-section,
            .hero-content-section,
            .cast-section,
            .episodes-section {
                padding-left: 40px;
                padding-right: 40px;
            }
            
            .series-title {
                font-size: 2.8rem;
            }
        }
        
        @media (max-width: 768px) {
            .header-section,
            .hero-content-section,
            .cast-section,
            .episodes-section {
                padding-left: 25px;
                padding-right: 25px;
            }
            
            .series-title {
                font-size: 2.2rem;
            }
            
            .series-overview {
                font-size: 16px;
            }
            
            .episodes-grid {
                grid-template-columns: 1fr;
                gap: 25px;
            }
            
            .cast-grid {
                gap: 15px;
            }
            
            .cast-member {
                width: 80px;
            }
            
            .cast-photo {
                width: 80px;
                height: 105px;
            }
            
            .action-buttons {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            
            .play-button,
            .info-button {
                width: auto;
                min-width: 200px;
                justify-content: center;
            }
            
            .series-metadata {
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    
    <!-- Fondo fijo -->
    <div class="fixed-background"></div>
    
    <?php if (isset($_GET['debug'])): ?>
    <div class="image-type-indicator" style="display: block;">
        Image: <?= $backdrop_type ?>
    </div>
    <?php endif; ?>
    
    <!-- Contenido scrolleable -->
    <div class="scrollable-content">
         <!-- Sección principal de información -->
        <div class="hero-content-section">
            <?php 
            if (isset($_GET['debug'])) {
                echo '<div style="background: rgba(0,0,0,0.9); padding: 15px; margin-bottom: 30px; border-radius: 10px; font-size: 13px; border: 1px solid rgba(255,255,255,0.2);">';
                echo '<strong>DEBUG INFO:</strong><br>';
                echo 'Original Series: ' . htmlspecialchars($info['name'] ?? 'N/A') . '<br>';
                echo 'Clean Series: ' . htmlspecialchars($clean_title) . '<br>';
                echo 'TMDB ID: ' . ($tmdb_id ? $tmdb_id : 'Not found') . '<br>';
                echo 'Backdrop URL: ' . ($backdrop_url ? 'Yes' : 'No') . '<br>';
                echo 'TMDB Data: ' . ($tmdb_data ? 'Yes' : 'No') . '<br>';
                echo 'Backdrop Type: ' . $backdrop_type . '<br>';
                echo '</div>';
            }
            ?>
            
            <div class="series-info">
                <h1 class="series-title"><?= htmlspecialchars($clean_title ?: 'Series') ?></h1>
                
                <div class="series-metadata">
                    <?php if ($tmdb_data && !empty($tmdb_data['vote_average'])): ?>
                        <span class="match-score"><?= round($tmdb_data['vote_average'] * 10) ?>% coincidencia</span>
                    <?php endif; ?>
                    
                    <?php if (!empty($info['releaseDate'])): ?>
                        <span class="year"><?= htmlspecialchars(substr($info['releaseDate'], 0, 4)) ?></span>
                    <?php endif; ?>
                    
                    <?php if (!empty($info['rating'])): ?>
                        <span class="rating">★ <?= htmlspecialchars($info['rating']) ?></span>
                    <?php endif; ?>
                    
                    <span class="seasons-count"><?= count($seasons) ?> temporada<?= count($seasons) !== 1 ? 's' : '' ?></span>
                </div>
                
                <div class="series-overview">
                    <?= htmlspecialchars($tmdb_data['overview'] ?? $info['plot'] ?? 'No hay descripción disponible para esta serie.') ?>
                </div>
                
             <?php if ($tmdb_data && !empty($tmdb_data['genres'])): ?>
                <div class="genres">
                    <?php foreach (array_slice($tmdb_data['genres'], 0, 4) as $i => $genre): ?>
                        <span class="genre"><?= htmlspecialchars($genre['name']) ?><?= $i < 3 && $i < count($tmdb_data['genres']) - 1 ? ' • ' : '' ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Sección de reparto -->
        <?php if ($tmdb_data && !empty($tmdb_data['credits']['cast'])): ?>
        <div class="cast-section">
            <h3 class="cast-title">Reparto Principal</h3>
            <div class="cast-grid">
                <?php foreach (array_slice($tmdb_data['credits']['cast'], 0, 12) as $actor): ?>
                    <?php if (!empty($actor['profile_path'])): ?>
                    <div class="cast-member">
                        <img class="cast-photo" 
                             src="<?= TMDB_IMG_URL ?>w185<?= $actor['profile_path'] ?>" 
                             alt="<?= htmlspecialchars($actor['name']) ?>"
                             loading="lazy">
                        <div class="cast-name"><?= htmlspecialchars($actor['name']) ?></div>
                        <div class="cast-character"><?= htmlspecialchars($actor['character']) ?></div>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Sección de temporadas y episodios -->
        <div class="episodes-section" id="episodesSection">
            <h2>Episodios</h2>
            
            <?php if (!empty($seasons)): ?>
            <div class="season-selector-container">
                <select class="season-selector" id="seasonSelector" onchange="loadSeasonEpisodes(this.value)">
                    <?php foreach ($seasons as $season_num => $episodes): ?>
                        <option value="<?= $season_num ?>">Temporada <?= $season_num ?> (<?= count($episodes) ?> episodios)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="episodes-container" id="episodesContainer">
                <div class="loading">
                    <div class="spinner"></div>
                    <p>Cargando episodios...</p>
                </div>
            </div>
            <?php else: ?>
            <div style="text-align: center; padding: 80px 20px; color: rgba(255,255,255,0.7);">
                <i class="fas fa-film" style="font-size: 4rem; margin-bottom: 25px; opacity: 0.4;"></i>
                <h3 style="margin-bottom: 15px; font-size: 24px;">No hay episodios disponibles</h3>
                <p style="font-size: 16px;">Esta serie no tiene episodios disponibles en este momento.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const tmdbId = <?= $tmdb_id ? $tmdb_id : 'null' ?>;
        const seasons = <?= json_encode($seasons) ?>;
        const seriesFallbackImages = [
            <?php if ($tmdb_data && !empty($tmdb_data['poster_path'])): ?>
                '<?= TMDB_IMG_URL ?>w500<?= $tmdb_data['poster_path'] ?>',
            <?php endif; ?>
            <?php if ($tmdb_data && !empty($tmdb_data['backdrop_path'])): ?>
                '<?= TMDB_IMG_URL ?>w500<?= $tmdb_data['backdrop_path'] ?>',
            <?php endif; ?>
            <?php if (!empty($info['cover'])): ?>
                '<?= $info['cover'] ?>',
            <?php endif; ?>
            <?php if (!empty($info['backdrop_path'])): ?>
                '<?= $info['backdrop_path'] ?>',
            <?php endif; ?>
            '<?= $default_image ?>'
        ];
        
        let currentSeason = Object.keys(seasons)[0];
        
        document.addEventListener('DOMContentLoaded', function() {
            if (currentSeason && seasons[currentSeason]) {
                loadSeasonEpisodes(currentSeason);
            }
            
            // Smooth scroll behavior
            document.documentElement.style.scrollBehavior = 'smooth';
        });
        
        function scrollToEpisodes() {
            document.getElementById('episodesSection').scrollIntoView({ 
                behavior: 'smooth',
                block: 'start'
            });
        }
        
        function handleEpisodeImageError(img) {
            let fallbackIndex = 0;
            
            const tryNextFallback = () => {
                if (fallbackIndex < seriesFallbackImages.length) {
                    const fallbackUrl = seriesFallbackImages[fallbackIndex];
                    if (fallbackUrl && fallbackUrl !== img.src) {
                        img.src = fallbackUrl;
                        fallbackIndex++;
                        return;
                    }
                }
                fallbackIndex++;
                if (fallbackIndex < seriesFallbackImages.length) {
                    tryNextFallback();
                } else {
                    // Crear imagen placeholder final
                    createPlaceholderImage(img);
                }
            };
            
            img.onerror = tryNextFallback;
            tryNextFallback();
        }
        
        function createPlaceholderImage(img) {
            const canvas = document.createElement('canvas');
            canvas.width = 400;
            canvas.height = 225;
            const ctx = canvas.getContext('2d');
            
            // Gradiente de fondo
            const gradient = ctx.createLinearGradient(0, 0, 400, 225);
            gradient.addColorStop(0, '#2a2a2a');
            gradient.addColorStop(0.5, '#3a3a3a');
            gradient.addColorStop(1, '#2a2a2a');
            
            ctx.fillStyle = gradient;
            ctx.fillRect(0, 0, 400, 225);
            
            // Icono de play
            ctx.fillStyle = '#555';
            ctx.beginPath();
            ctx.arc(200, 112, 30, 0, 2 * Math.PI);
            ctx.fill();
            
            ctx.fillStyle = '#777';
            ctx.beginPath();
            ctx.moveTo(185, 95);
            ctx.lineTo(185, 130);
            ctx.lineTo(215, 112);
            ctx.closePath();
            ctx.fill();
            
            // Texto
            ctx.fillStyle = '#666';
            ctx.font = 'bold 14px Arial';
            ctx.textAlign = 'center';
            ctx.fillText('Episodio', 200, 170);
            
            img.src = canvas.toDataURL();
            img.onerror = null;
        }
        
        async function loadSeasonEpisodes(seasonNumber) {
            currentSeason = seasonNumber;
            const container = document.getElementById('episodesContainer');
            
            // Mostrar loading
            container.innerHTML = `
                <div class="loading">
                    <div class="spinner"></div>
                    <p>Cargando episodios de la temporada ${seasonNumber}...</p>
                </div>
            `;
            
            try {
                // Intentar obtener imágenes de episodios desde TMDB
                let tmdbEpisodes = [];
                if (tmdbId) {
                    try {
                        const response = await fetch(`https://api.themoviedb.org/3/tv/${tmdbId}/season/${seasonNumber}?api_key=<?= TMDB_API_KEY ?>&language=es-ES`);
                        if (response.ok) {
                            const seasonData = await response.json();
                            tmdbEpisodes = seasonData.episodes || [];
                        }
                    } catch (e) {
                        console.log('TMDB episode images not available:', e);
                    }
                }
                
                const episodes = seasons[seasonNumber] || [];
                let episodesHTML = '';
                
                if (episodes.length > 0) {
                    episodesHTML = '<div class="episodes-grid">';
                    
                    episodes.forEach((episode, index) => {
                        const tmdbEpisode = tmdbEpisodes[index] || {};
                        
                        // Determinar imagen del episodio
                        let episodeImage = null;
                        if (tmdbEpisode.still_path) {
                            episodeImage = `<?= TMDB_IMG_URL ?>w500${tmdbEpisode.still_path}`;
                        } else if (episode.info && episode.info.movie_image) {
                            episodeImage = episode.info.movie_image;
                        } else if (episode.stream_icon) {
                            episodeImage = episode.stream_icon;
                        } else {
                            // Usar imagen de la serie como fallback
                            episodeImage = seriesFallbackImages[0] || '<?= $default_image ?>';
                        }
                        
                        const episodeTitle = episode.title || tmdbEpisode.name || `Episodio ${episode.episode_num || index + 1}`;
                        const episodeDescription = tmdbEpisode.overview || episode.info?.plot || 'No hay descripción disponible para este episodio.';
                        const episodeDuration = episode.info?.duration || tmdbEpisode.runtime || '';
                        const episodeNumber = episode.episode_num || index + 1;
                        
                        episodesHTML += `
                            <div class="episode-card" onclick="playEpisode('${episode.id}', '<?= $series_id ?>')">
                                <div class="episode-thumbnail">
                                    <img class="episode-image" 
                                         src="${episodeImage}" 
                                         alt="${escapeHtml(episodeTitle)}" 
                                         loading="lazy"
                                         onerror="handleEpisodeImageError(this);">
                                    <div class="episode-number">${episodeNumber}</div>
                                    ${episodeDuration ? `<div class="episode-duration">${episodeDuration} min</div>` : ''}
                                    <div class="play-overlay">
                                        <i class="fas fa-play"></i>
                                    </div>
                                </div>
                                <div class="episode-info">
                                    <div class="episode-title">${escapeHtml(episodeTitle)}</div>
                                    <div class="episode-description">${escapeHtml(episodeDescription)}</div>
                                </div>
                            </div>
                        `;
                    });
                    
                    episodesHTML += '</div>';
                } else {
                    episodesHTML = `
                        <div style="text-align: center; padding: 80px 20px; color: rgba(255,255,255,0.7);">
                            <i class="fas fa-film" style="font-size: 3.5rem; margin-bottom: 25px; opacity: 0.4;"></i>
                            <h3 style="margin-bottom: 15px; font-size: 22px;">No hay episodios disponibles</h3>
                            <p style="font-size: 16px;">Esta temporada no tiene episodios disponibles en este momento.</p>
                        </div>
                    `;
                }
                
                // Añadir el contenido con una pequeña animación
                container.style.opacity = '0';
                setTimeout(() => {
                    container.innerHTML = episodesHTML;
                    container.style.opacity = '1';
                }, 200);
                
            } catch (error) {
                console.error('Error loading episodes:', error);
                container.innerHTML = `
                    <div style="text-align: center; padding: 80px 20px; color: rgba(255,255,255,0.7);">
                        <i class="fas fa-exclamation-triangle" style="font-size: 3.5rem; margin-bottom: 25px; color: #ff6b6b;"></i>
                        <h3 style="margin-bottom: 20px; font-size: 22px;">Error al cargar episodios</h3>
                        <p style="margin-bottom: 25px; font-size: 16px;">Hubo un problema al cargar los episodios de esta temporada.</p>
                        <button onclick="loadSeasonEpisodes(${seasonNumber})" 
                                style="padding: 12px 24px; background: rgba(255,255,255,0.2); border: 2px solid rgba(255,255,255,0.4); color: #fff; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 14px; transition: all 0.3s ease;"
                                onmouseover="this.style.background='rgba(255,255,255,0.3)'"
                                onmouseout="this.style.background='rgba(255,255,255,0.2)'">
                            <i class="fas fa-redo"></i> Reintentar
                        </button>
                    </div>
                `;
            }
        }
        
        function playEpisode(episodeId, seriesId) {
            window.location.href = `player.php?id=${episodeId}&slug=series&series_id=${seriesId}`;
        }
        
        function playFirstEpisode() {
            if (seasons[currentSeason] && seasons[currentSeason][0]) {
                playEpisode(seasons[currentSeason][0].id, '<?= $series_id ?>');
            } else {
                // Si no hay episodios en la temporada actual, buscar en la primera temporada disponible
                const firstSeasonKey = Object.keys(seasons)[0];
                if (firstSeasonKey && seasons[firstSeasonKey] && seasons[firstSeasonKey][0]) {
                    playEpisode(seasons[firstSeasonKey][0].id, '<?= $series_id ?>');
                } else {
                    alert('No hay episodios disponibles para reproducir.');
                }
            }
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Añadir transiciones suaves al contenedor
        document.getElementById('episodesContainer').style.transition = 'opacity 0.3s ease';
        
        // Mejorar la experiencia de scroll
        window.addEventListener('scroll', function() {
            const scrolled = window.pageYOffset;
            const background = document.querySelector('.fixed-background');
            
            // Efecto parallax sutil en el fondo
            if (background) {
                background.style.transform = `translateY(${scrolled * 0.1}px)`;
            }
        }, { passive: true });
    </script>
</body>
</html>