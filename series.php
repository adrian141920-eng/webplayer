<?php
session_start();

if (!isset($_SESSION['user_logged'])) {
    header('Location: index.php');
    exit;
}

$username = $_SESSION['username'];
$password = $_SESSION['password'];
$server_url = $_SESSION['server_url'];
$server_name = $_SESSION['server_name'];

function makeApiRequest($url, $action, $params = []) {
    global $username, $password, $server_url;
    
    $base_params = [
        'username' => $username,
        'password' => $password,
        'action' => $action
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

$categories = makeApiRequest($server_url, 'get_series_categories') ?: [];
$selected_category = $_GET['cat'] ?? '';
$sort_order = $_GET['sort'] ?? '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

$series = [];
$total_series = 0;
$featured_series = [];

if ($selected_category) {
    $all_series = makeApiRequest($server_url, 'get_series', ['category_id' => $selected_category]) ?: [];
} else {
    $all_series = makeApiRequest($server_url, 'get_series') ?: [];
}

// Banner con backdrop_path real para series
if (!empty($all_series)) {
    $banner_series = $all_series;
    shuffle($banner_series);
    
    $featured_series = [];
    foreach ($banner_series as $serie) {
        if (count($featured_series) >= 10) break; // Cambiar aquí: 3, 5, 10, etc.
        
        if (!empty($serie['name']) && !empty($serie['series_id'])) {
            // Obtener datos completos de la serie para el backdrop_path
            $serie_details = makeApiRequest($server_url, 'get_series_info', ['series_id' => $serie['series_id']]);
            $serie_info = $serie_details['info'] ?? [];
            
            // Buscar backdrop_path en los datos completos
            $backdrop_url = '';
            if (!empty($serie_info['backdrop_path'])) {
                if (is_array($serie_info['backdrop_path'])) {
                    $backdrop_url = $serie_info['backdrop_path'][0] ?? '';
                } else {
                    $backdrop_url = $serie_info['backdrop_path'];
                }
            }
            
            // Si no tiene backdrop_path, usar cover o cover_big
            if (empty($backdrop_url)) {
                $backdrop_url = $serie_info['cover_big'] ?? $serie_info['cover'] ?? $serie['cover'] ?? '';
            }
            
            if (!empty($backdrop_url)) {
                $featured_series[] = [
                    'series_id' => $serie['series_id'] ?? '',
                    'name' => $serie_info['name'] ?? $serie['name'] ?? '',
                    'backdrop_url' => $backdrop_url,
                    'plot' => $serie_info['plot'] ?? $serie_info['description'] ?? 'Una serie increíble.',
                    'year' => $serie_info['releaseDate'] ? date('Y', strtotime($serie_info['releaseDate'])) : ($serie['year'] ?? ''),
                    'rating' => $serie_info['rating'] ?? $serie['rating'] ?? $serie['tmdb_rating'] ?? '',
                    'genre' => $serie_info['genre'] ?? $serie['genre'] ?? '',
                    'episode_run_time' => $serie_info['episode_run_time'] ?? ''
                ];
            }
        }
    }
}

if (!empty($all_series)) {
    switch ($sort_order) {
        case 'name_asc':
            usort($all_series, function($a, $b) {
                return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
            });
            break;
        case 'name_desc':
            usort($all_series, function($a, $b) {
                return strcasecmp($b['name'] ?? '', $a['name'] ?? '');
            });
            break;
        case 'recent':
            usort($all_series, function($a, $b) {
                $dateA = strtotime($a['last_modified'] ?? $a['added'] ?? '0');
                $dateB = strtotime($b['last_modified'] ?? $b['added'] ?? '0');
                return $dateB - $dateA;
            });
            break;
        case 'rating':
            usort($all_series, function($a, $b) {
                $ratingA = floatval($a['rating'] ?? $a['tmdb_rating'] ?? 0);
                $ratingB = floatval($b['rating'] ?? $b['tmdb_rating'] ?? 0);
                return $ratingB <=> $ratingA;
            });
            break;
    }
}

$total_series = count($all_series);
$series = array_slice($all_series, $offset, $limit);
$has_more = ($offset + $limit) < $total_series;

if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $formatted_series = [];
    foreach ($series as $serie) {
        $formatted_series[] = [
            'series_id' => $serie['series_id'] ?? '',
            'name' => $serie['name'] ?? '',
            'cover' => $serie['cover'] ?? '',
            'year' => $serie['year'] ?? '',
            'rating' => $serie['rating'] ?? '',
            'tmdb_rating' => $serie['tmdb_rating'] ?? '',
            'genre' => $serie['genre'] ?? '',
            'plot' => $serie['plot'] ?? '',
            'episode_run_time' => $serie['episode_run_time'] ?? '',
            'last_modified' => $serie['last_modified'] ?? '',
            'added' => $serie['added'] ?? ''
        ];
    }
    echo json_encode(['series' => $formatted_series, 'hasMore' => $has_more, 'page' => $page]);
    exit;
}
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Series - IPTV Pro</title>
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
        }

        .main-content {
            margin-left: 280px;
            background: #0f171e;
        }

        /* BANNER */
        .banner {
            position: relative;
            height: 50vh;
            min-height: 400px;
            overflow: hidden;
        }

        .banner-slide {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            transition: opacity 2s ease;
        }

        .banner-slide.active {
            opacity: 1;
        }

        .banner-bg {
            width: 100%;
            height: 100%;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            filter: brightness(0.4);
        }

        .banner-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, 
                rgba(15,23,30,0.9) 0%, 
                rgba(15,23,30,0.7) 25%, 
                rgba(15,23,30,0.4) 50%, 
                rgba(15,23,30,0.2) 75%, 
                rgba(15,23,30,0.1) 90%,
                transparent 100%);
            z-index: 5;
        }

        .banner-overlay::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 30%;
            background: linear-gradient(to top, rgba(15,23,30,1) 0%, transparent 100%);
        }

        .banner-content {
            position: absolute;
            left: 5%;
            bottom: 15%;
            max-width: 50%;
            z-index: 10;
        }

        .banner-title {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 15px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.8);
        }

        .banner-meta {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }

        .meta-item {
            background: rgba(0,0,0,0.6);
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .rating {
            background: #ffa500;
            color: #000;
        }

        .banner-description {
            font-size: 1.1rem;
            line-height: 1.5;
            margin-bottom: 20px;
            color: rgba(255,255,255,0.9);
            text-shadow: 1px 1px 2px rgba(0,0,0,0.8);
        }

        .banner-buttons {
            display: flex;
            gap: 15px;
        }

        .btn {
            padding: 12px 25px;
            border: none;
            border-radius: 6px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-play {
            background: #fff;
            color: #000;
        }

        .btn-play:hover {
            background: #f0f0f0;
        }

        .btn-info {
            background: rgba(255,255,255,0.2);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.4);
        }

        .btn-info:hover {
            background: rgba(255,255,255,0.3);
        }

        .banner-dots {
            position: absolute;
            bottom: 30px;
            left: 5%;
            display: flex;
            gap: 8px;
            z-index: 15;
        }

        .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: rgba(255,255,255,0.4);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .dot.active {
            background: #fff;
        }

        /* BOTÓN FLOTANTE DE FILTROS */
        .filters-floating {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 100;
        }

        .filters-trigger {
            background: rgba(0,0,0,0.8);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.2);
            color: #fff;
            padding: 12px 20px;
            border-radius: 25px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
            font-weight: 600;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }

        .filters-trigger:hover {
            background: rgba(0,0,0,0.9);
            border-color: rgba(255,255,255,0.4);
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.4);
        }

        .filters-trigger.active {
            background: #fff;
            color: #000;
            border-color: #fff;
        }

        .filters-panel {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 10px;
            background: rgba(20,20,20,0.95);
            backdrop-filter: blur(25px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 16px;
            min-width: 320px;
            max-width: 400px;
            padding: 20px;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
        }

        .filters-floating:hover .filters-panel,
        .filters-floating.active .filters-panel {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        .filter-group {
            margin-bottom: 20px;
        }

        .filter-group:last-child {
            margin-bottom: 0;
        }

        .filter-title {
            font-size: 0.85rem;
            font-weight: 700;
            color: rgba(255,255,255,0.9);
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .categories-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            max-height: 200px;
            overflow-y: auto;
            padding-right: 5px;
        }

        .categories-grid::-webkit-scrollbar {
            width: 4px;
        }

        .categories-grid::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.1);
            border-radius: 2px;
        }

        .categories-grid::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.3);
            border-radius: 2px;
        }

        .category-item {
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.15);
            color: rgba(255,255,255,0.8);
            padding: 8px 12px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: center;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .category-item:hover {
            background: rgba(255,255,255,0.15);
            border-color: rgba(255,255,255,0.3);
            color: #fff;
        }

        .category-item.active {
            background: #fff;
            border-color: #fff;
            color: #000;
            font-weight: 600;
        }

        .sort-options {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .sort-item {
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.15);
            color: rgba(255,255,255,0.8);
            padding: 10px 16px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .sort-item:hover {
            background: rgba(255,255,255,0.15);
            border-color: rgba(255,255,255,0.3);
            color: #fff;
        }

        .sort-item.active {
            background: #fff;
            border-color: #fff;
            color: #000;
            font-weight: 600;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .filters-floating {
                top: 15px;
                right: 15px;
            }
            
            .filters-panel {
                min-width: 280px;
                max-width: calc(100vw - 40px);
                right: -10px;
            }
            
            .categories-grid {
                grid-template-columns: 1fr;
            }
        }

        /* GRID DE SERIES PROFESIONAL */
        .series {
            padding: 30px 5%;
        }

        .series-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 20px;
        }

        .series-card {
            position: relative;
            border-radius: 8px;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
            background: transparent;
        }

        .series-card:hover {
            transform: scale(1.05);
            z-index: 10;
        }

        .series-poster {
            position: relative;
            width: 100%;
            height: 270px;
            overflow: hidden;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }

        .series-poster img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: all 0.4s ease;
        }

        .series-card:hover .series-poster img {
            transform: scale(1.1);
            filter: brightness(0.7);
        }

        /* Overlay con información encima del poster */
        .series-overlay {
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            background: linear-gradient(to top, 
                rgba(0,0,0,0.9) 0%, 
                rgba(0,0,0,0.7) 40%, 
                transparent 100%);
            padding: 15px 12px 12px;
            transform: translateY(100%);
            transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
        }

        .series-card:hover .series-overlay {
            transform: translateY(0);
        }

        .series-title {
            font-size: 0.9rem;
            font-weight: 700;
            margin-bottom: 6px;
            color: #fff;
            line-height: 1.2;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .series-quick-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .series-year {
            color: rgba(255,255,255,0.8);
            font-size: 0.75rem;
            font-weight: 500;
        }

        .series-rating {
            background: rgba(255,193,7,0.9);
            color: #000;
            padding: 2px 6px;
            border-radius: 10px;
            font-size: 0.7rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 2px;
        }

        .series-genre {
            color: rgba(255,255,255,0.7);
            font-size: 0.7rem;
            font-weight: 500;
        }

        /* Badges flotantes sobre el poster */
        .quality-badge {
            position: absolute;
            top: 8px;
            left: 8px;
            background: rgba(0,0,0,0.8);
            backdrop-filter: blur(4px);
            color: #fff;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.65rem;
            font-weight: 600;
            text-transform: uppercase;
            border: 1px solid rgba(255,255,255,0.2);
        }

        .duration-badge {
            position: absolute;
            top: 8px;
            right: 8px;
            background: rgba(0,0,0,0.8);
            backdrop-filter: blur(4px);
            color: #fff;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.65rem;
            font-weight: 500;
            border: 1px solid rgba(255,255,255,0.2);
        }

        /* Botón de play que aparece en hover */
        .play-button {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 50px;
            height: 50px;
            background: rgba(255,255,255,0.9);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .play-button i {
            color: #000;
            font-size: 1.2rem;
            margin-left: 2px;
        }

        .series-card:hover .play-button {
            opacity: 1;
            transform: translate(-50%, -50%) scale(1.1);
        }

        .play-button:hover {
            background: #fff;
            transform: translate(-50%, -50%) scale(1.2) !important;
        }

        .loading {
            text-align: center;
            padding: 40px;
            color: #999;
        }

        /* RESPONSIVE */
        @media (max-width: 992px) {
            .main-content { margin-left: 0; }
            .banner { height: 40vh; min-height: 300px; }
            .banner-title { font-size: 2.5rem; }
            .banner-content { max-width: 70%; }
        }

        @media (max-width: 768px) {
            .banner { height: 35vh; min-height: 250px; }
            .banner-content { max-width: 85%; left: 20px; bottom: 10%; }
            .banner-title { font-size: 2rem; }
            .banner-buttons { flex-direction: column; gap: 10px; }
            .btn { width: 200px; justify-content: center; }
            .filters { flex-direction: column; align-items: center; }
            .filter-select { min-width: 100%; max-width: 300px; }
            .series-grid { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px; }
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="main-content">
        <!-- BANNER -->
        <?php if (!empty($featured_series)): ?>
        <div class="banner">
            <?php foreach ($featured_series as $index => $serie): ?>
            <div class="banner-slide <?= $index === 0 ? 'active' : '' ?>">
                <div class="banner-bg" style="background-image: url('<?= htmlspecialchars($serie['backdrop_url']) ?>');"></div>
                <div class="banner-overlay"></div>
                
                <div class="banner-content">
                    <h1 class="banner-title"><?= htmlspecialchars($serie['name']) ?></h1>
                    
                    <div class="banner-meta">
                        <?php if (!empty($serie['rating'])): ?>
                        <span class="meta-item rating">★ <?= number_format(floatval($serie['rating']), 1) ?></span>
                        <?php endif; ?>
                        
                        <?php if (!empty($serie['year'])): ?>
                        <span class="meta-item"><?= htmlspecialchars($serie['year']) ?></span>
                        <?php endif; ?>
                        
                        <?php if (!empty($serie['genre'])): ?>
                        <span class="meta-item"><?= htmlspecialchars($serie['genre']) ?></span>
                        <?php endif; ?>

                        <?php if (!empty($serie['episode_run_time'])): ?>
                        <span class="meta-item"><?= htmlspecialchars($serie['episode_run_time']) ?> min/ep</span>
                        <?php endif; ?>
                    </div>
                    
                    <p class="banner-description">
                        <?= htmlspecialchars(substr($serie['plot'], 0, 150)) ?>...
                    </p>
                    
                    <div class="banner-buttons">
                        <button class="btn btn-play" onclick="openSeries('<?= $serie['series_id'] ?>')">
                            <i class="fas fa-play"></i> Ver Serie
                        </button>
                        <button class="btn btn-info" onclick="openSeries('<?= $serie['series_id'] ?>')">
                            <i class="fas fa-info-circle"></i> Más Info
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if (count($featured_series) > 1): ?>
            <div class="banner-dots">
                <?php for($i = 0; $i < count($featured_series); $i++): ?>
                <div class="dot <?= $i === 0 ? 'active' : '' ?>" onclick="goToSlide(<?= $i ?>)"></div>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- FILTROS FLOTANTES -->
        <div class="filters-floating" id="filtersFloating">
            <div class="filters-trigger">
                <i class="fas fa-filter"></i>
                Categorías
                <i class="fas fa-chevron-down"></i>
            </div>
            
            <div class="filters-panel">
                <div class="filter-group">
                    <div class="filter-title">Categorías</div>
                    <div class="categories-grid">
                        <div class="category-item <?= empty($selected_category) ? 'active' : '' ?>" 
                             onclick="filterCategory('')">Todas</div>
                        <?php foreach ($categories as $category): ?>
                        <div class="category-item <?= $selected_category == $category['category_id'] ? 'active' : '' ?>" 
                             onclick="filterCategory('<?= htmlspecialchars($category['category_id']) ?>')">
                            <?= htmlspecialchars($category['category_name']) ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="filter-group">
                    <div class="filter-title">Ordenar por</div>
                    <div class="sort-options">
                        <div class="sort-item <?= empty($sort_order) ? 'active' : '' ?>" 
                             onclick="sortSeries('')">
                            <span>Sin Ordenar</span>
                            <?= empty($sort_order) ? '<i class="fas fa-check"></i>' : '' ?>
                        </div>
                        <div class="sort-item <?= $sort_order == 'name_asc' ? 'active' : '' ?>" 
                             onclick="sortSeries('name_asc')">
                            <span>A → Z</span>
                            <?= $sort_order == 'name_asc' ? '<i class="fas fa-check"></i>' : '' ?>
                        </div>
                        <div class="sort-item <?= $sort_order == 'name_desc' ? 'active' : '' ?>" 
                             onclick="sortSeries('name_desc')">
                            <span>Z → A</span>
                            <?= $sort_order == 'name_desc' ? '<i class="fas fa-check"></i>' : '' ?>
                        </div>
                        <div class="sort-item <?= $sort_order == 'recent' ? 'active' : '' ?>" 
                             onclick="sortSeries('recent')">
                            <span>Más Recientes</span>
                            <?= $sort_order == 'recent' ? '<i class="fas fa-check"></i>' : '' ?>
                        </div>
<div class="sort-item <?= $sort_order == 'rating' ? 'active' : '' ?>" 
                             onclick="sortSeries('rating')">
                            <span>Mejor Valoradas</span>
                            <?= $sort_order == 'rating' ? '<i class="fas fa-check"></i>' : '' ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- SERIES -->
        <div class="series">
            <div class="series-grid" id="seriesGrid">
                <?php foreach ($series as $serie): ?>
                <div class="series-card" onclick="openSeries(<?= $serie['series_id'] ?>)">
                    <div class="series-poster">
                        <?php if (!empty($serie['cover'])): ?>
                            <img src="<?= htmlspecialchars($serie['cover']) ?>" 
                                 alt="<?= htmlspecialchars($serie['name']) ?>"
                                 onerror="this.style.display='none';">
                        <?php endif; ?>

                        <div class="quality-badge">SERIE</div>

                        <?php if (!empty($serie['episode_run_time'])): ?>
                            <div class="duration-badge"><?= htmlspecialchars($serie['episode_run_time']) ?> min</div>
                        <?php endif; ?>

                        <div class="play-button" onclick="event.stopPropagation(); openSeries('<?= $serie['series_id'] ?>')">
                            <i class="fas fa-play"></i>
                        </div>

                        <div class="series-overlay">
                            <div class="series-title"><?= htmlspecialchars($serie['name']) ?></div>
                            
                            <div class="series-quick-info">
                                <span class="series-year"><?= !empty($serie['year']) ? htmlspecialchars($serie['year']) : 'N/A' ?></span>
                                
                                <?php 
                                $rating = $serie['rating'] ?? $serie['tmdb_rating'] ?? '';
                                if ($rating && $rating != '0'): ?>
                                <span class="series-rating">★ <?= number_format(floatval($rating), 1) ?></span>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($serie['genre'])): ?>
                            <div class="series-genre"><?= htmlspecialchars($serie['genre']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <?php if ($has_more): ?>
            <div class="loading" id="loading">
                <i class="fas fa-spinner fa-spin"></i> Cargando más series...
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        let currentSlide = 0;
        let currentPage = <?= $page ?>;
        let hasMore = <?= $has_more ? 'true' : 'false' ?>;
        let loading = false;
        const featuredSeries = <?= json_encode($featured_series) ?>;
        const selectedCategory = <?= !empty($selected_category) ? "'" . addslashes($selected_category) . "'" : 'null' ?>;
        const sortOrder = <?= !empty($sort_order) ? "'" . addslashes($sort_order) . "'" : 'null' ?>;

        function goToSlide(index) {
            const slides = document.querySelectorAll('.banner-slide');
            const dots = document.querySelectorAll('.dot');
            
            slides[currentSlide].classList.remove('active');
            dots[currentSlide].classList.remove('active');
            
            currentSlide = index;
            
            slides[currentSlide].classList.add('active');
            dots[currentSlide].classList.add('active');
        }

        function openSeries(seriesId) {
            window.location.href = `series-detail.php?id=${seriesId}`;
        }

        function filterCategory(categoryId) {
            let url = 'series.php';
            let params = [];
            if (categoryId) params.push(`cat=${categoryId}`);
            if (sortOrder) params.push(`sort=${sortOrder}`);
            if (params.length > 0) url += '?' + params.join('&');
            window.location.href = url;
        }

        function sortSeries(sort) {
            let url = 'series.php';
            let params = [];
            if (selectedCategory) params.push(`cat=${selectedCategory}`);
            if (sort) params.push(`sort=${sort}`);
            if (params.length > 0) url += '?' + params.join('&');
            window.location.href = url;
        }

        // Cerrar panel al hacer click fuera
        document.addEventListener('click', function(e) {
            const filtersFloating = document.getElementById('filtersFloating');
            if (!filtersFloating.contains(e.target)) {
                filtersFloating.classList.remove('active');
            }
        });

        // Toggle panel con click
        document.querySelector('.filters-trigger').addEventListener('click', function(e) {
            e.stopPropagation();
            document.getElementById('filtersFloating').classList.toggle('active');
        });

        // Auto-slide
        if (featuredSeries && featuredSeries.length > 1) {
            setInterval(() => {
                goToSlide((currentSlide + 1) % featuredSeries.length);
            }, 8000);
        }

        // Scroll infinito
        window.addEventListener('scroll', () => {
            if ((window.innerHeight + window.scrollY) >= document.body.offsetHeight - 500 && hasMore && !loading) {
                loading = true;
                document.getElementById('loading').style.display = 'block';
                
                fetch(`series.php?page=${currentPage + 1}&ajax=1${selectedCategory ? '&cat=' + selectedCategory : ''}${sortOrder ? '&sort=' + sortOrder : ''}`)
                    .then(response => response.json())
                    .then(data => {
                        const grid = document.getElementById('seriesGrid');
                        data.series.forEach(serie => {
                            const card = document.createElement('div');
                            card.className = 'series-card';
                            card.onclick = () => openSeries(serie.series_id);
                            
                            const rating = serie.rating || serie.tmdb_rating || '';
                            
                            card.innerHTML = `
                                <div class="series-poster">
                                    ${serie.cover ? 
                                        `<img src="${serie.cover}" alt="${serie.name}" onerror="this.style.display='none';">` : ''
                                    }
                                    
                                    <div class="quality-badge">SERIE</div>
                                    ${serie.episode_run_time ? `<div class="duration-badge">${serie.episode_run_time} min</div>` : ''}
                                    
                                    <div class="play-button" onclick="event.stopPropagation(); openSeries('${serie.series_id}')">
                                        <i class="fas fa-play"></i>
                                    </div>

                                    <div class="series-overlay">
                                        <div class="series-title">${serie.name}</div>
                                        
                                        <div class="series-quick-info">
                                            <span class="series-year">${serie.year || 'N/A'}</span>
                                            ${rating ? `<span class="series-rating">★ ${parseFloat(rating).toFixed(1)}</span>` : ''}
                                        </div>

                                        ${serie.genre ? `<div class="series-genre">${serie.genre}</div>` : ''}
                                    </div>
                                </div>
                            `;
                            grid.appendChild(card);
                        });
                        currentPage++;
                        hasMore = data.hasMore;
                        loading = false;
                        document.getElementById('loading').style.display = hasMore ? 'block' : 'none';
                    })
                    .catch(error => {
                        console.error('Error loading more series:', error);
                        loading = false;
                        document.getElementById('loading').style.display = 'none';
                    });
            }
        });
    </script>
</body>
</html>