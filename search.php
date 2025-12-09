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
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
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

function searchContent($search_query) {
    $search_query = strtolower(trim($search_query));
    
    $movies_api = makeApiRequest('', 'get_vod_streams') ?: [];
    $series_api = makeApiRequest('', 'get_series') ?: [];
    
    $movie_results = [];
    $series_results = [];
    
    foreach ($movies_api as $movie) {
        if (stripos(strtolower($movie['name']), $search_query) !== false) {
            $movie_results[] = [
                'title' => htmlspecialchars($movie['name']),
                'id' => htmlspecialchars($movie['stream_id']),
                'poster' => filter_var($movie['stream_icon'], FILTER_VALIDATE_URL) ? $movie['stream_icon'] : "https://via.placeholder.com/300x450/232f3e/00a8e1?text=No+Image",
                'rating' => isset($movie['rating']) ? floatval($movie['rating']) : 0,
                'year' => isset($movie['year']) ? intval($movie['year']) : 0,
                'description' => isset($movie['plot']) ? htmlspecialchars($movie['plot']) : '',
                'genre' => isset($movie['genre']) ? htmlspecialchars($movie['genre']) : '',
                'duration' => isset($movie['duration']) ? htmlspecialchars($movie['duration']) : '',
                'quality' => isset($movie['container_extension']) ? strtoupper($movie['container_extension']) : 'HD'
            ];
        }
    }
    
    foreach ($series_api as $series) {
        if (stripos(strtolower($series['name']), $search_query) !== false) {
            $series_results[] = [
                'title' => htmlspecialchars($series['name']),
                'id' => htmlspecialchars($series['series_id']),
                'poster' => filter_var($series['cover'], FILTER_VALIDATE_URL) ? $series['cover'] : "https://via.placeholder.com/300x450/232f3e/00a8e1?text=No+Image",
                'rating' => isset($series['rating']) ? floatval($series['rating']) : 0,
                'year' => isset($series['year']) ? intval($series['year']) : 0,
                'description' => isset($series['plot']) ? htmlspecialchars($series['plot']) : '',
                'genre' => isset($series['genre']) ? htmlspecialchars($series['genre']) : ''
            ];
        }
    }
    
    return ['movies' => $movie_results, 'series' => $series_results];
}

$search_query = isset($_GET['q']) ? trim($_GET['q']) : '';
$search_results = [];

if (!empty($search_query)) {
    $search_results = searchContent($search_query);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Results - IPTV Pro</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .content-area {
            padding: 0;
            margin: 0;
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            overflow-y: auto;
        }
        
        .summary-cards {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin: 0;
            padding: 10px 20px;
        }
        
        .summary-card {
            background: rgba(0, 168, 225, 0.1);
            border: 1px solid rgba(0, 168, 225, 0.3);
            padding: 15px 25px;
            border-radius: 10px;
            text-align: center;
            min-width: 120px;
        }
        
        .summary-number {
            font-size: 2rem;
            font-weight: bold;
            color: #00a8e1;
            display: block;
            margin-bottom: 5px;
        }
        
        .summary-text {
            font-size: 0.9rem;
            color: #8eacc2;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .tab-navigation {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin: 5px 0 10px 0;
            border-bottom: 1px solid #37475a;
            padding: 0 20px 10px 20px;
        }

        .tab-btn {
            background: none;
            border: none;
            color: #8eacc2;
            padding: 12px 25px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .tab-btn.active {
            color: #00a8e1;
            border-bottom-color: #00a8e1;
        }

        .tab-btn:hover {
            color: #fff;
        }

        .tab-panel {
            display: none;
        }

        .tab-panel.active {
            display: block;
        }
        
        .movie-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 25px;
            margin: 10px 20px 30px 20px;
        }
        
        .movie-item {
            background: #232f3e;
            border-radius: 12px;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 1px solid #37475a;
        }
        
        .movie-item:hover {
            transform: translateY(-8px);
            box-shadow: 0 15px 35px rgba(0, 168, 225, 0.3);
            border-color: #00a8e1;
        }
        
        .movie-poster {
            position: relative;
            width: 100%;
            height: 320px;
            background: linear-gradient(135deg, #37475a, #485769);
            overflow: hidden;
        }
        
        .movie-poster img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }
        
        .movie-item:hover .movie-poster img {
            transform: scale(1.05);
        }
        
        .quality-tag {
            position: absolute;
            top: 10px;
            left: 10px;
            background: #00a8e1;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: bold;
        }
        
        .rating-tag {
            position: absolute;
            top: 10px;
            right: 10px;
            background: rgba(0,0,0,0.8);
            color: #ffd700;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.7rem;
            font-weight: bold;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .movie-details {
            padding: 16px;
        }
        
        .movie-title {
            font-weight: bold;
            margin-bottom: 8px;
            font-size: 1rem;
            line-height: 1.3;
            color: white;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .movie-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            color: #8eacc2;
            font-size: 0.85rem;
        }
        
        .movie-genre {
            color: #00a8e1;
            font-size: 0.8rem;
            margin-bottom: 8px;
        }
        
        .movie-description {
            color: #8eacc2;
            font-size: 0.8rem;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .empty-message {
            text-align: center;
            padding: 60px 20px;
            color: #8eacc2;
        }
        
        .empty-message i {
            font-size: 3rem;
            color: #37475a;
            margin-bottom: 15px;
        }
        
        .empty-message h3 {
            font-size: 1.3rem;
            color: #fff;
            margin-bottom: 8px;
        }
        
        @media (max-width: 768px) {
            .content-area {
                padding: 15px;
            }
            
            .summary-cards {
                flex-direction: column;
                gap: 15px;
            }

            .tab-navigation {
                gap: 5px;
            }

            .tab-btn {
                padding: 10px 15px;
                font-size: 0.9rem;
            }
            
            .movie-grid {
                grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
                gap: 15px;
            }
            
            .movie-poster {
                height: 240px;
            }
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="content-area">
        <!-- Header con icono y texto -->
        <div style="background: #0f171e; padding: 15px 20px 0 20px; display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 0;">
            <i class="fas fa-search" style="color: #00a8e1; font-size: 1.5rem;"></i>
            <h1 style="color: #ffffff; font-size: 1.8rem; font-weight: 600; margin: 0;">Resultados de Búsqueda</h1>
            <?php if (!empty($search_query)): ?>
                <span style="color: #8eacc2; font-size: 1rem; margin-left: 10px;">"<?= htmlspecialchars($search_query) ?>"</span>
            <?php endif; ?>
        </div>

        <?php if (!empty($search_query) && (!empty($search_results['movies']) || !empty($search_results['series']))): ?>
            
            <!-- Summary Cards -->
            <div class="summary-cards">
                <div class="summary-card">
                    <span class="summary-number"><?= count($search_results['movies']) ?></span>
                    <span class="summary-text">Películas</span>
                </div>
                <div class="summary-card">
                    <span class="summary-number"><?= count($search_results['series']) ?></span>
                    <span class="summary-text">Series</span>
                </div>
                <div class="summary-card">
                    <span class="summary-number"><?= count($search_results['movies']) + count($search_results['series']) ?></span>
                    <span class="summary-text">Total</span>
                </div>
            </div>

            <!-- Tab Navigation -->
            <div class="tab-navigation">
                <button class="tab-btn active" data-tab="movies">
                    <i class="fas fa-film"></i>
                    Películas (<?= count($search_results['movies']) ?>)
                </button>
                <button class="tab-btn" data-tab="series">
                    <i class="fas fa-tv"></i>
                    Series (<?= count($search_results['series']) ?>)
                </button>
            </div>

            <!-- Movies Tab -->
            <div id="movies-panel" class="tab-panel active">
                <?php if (!empty($search_results['movies'])): ?>
                    <div class="movie-grid">
                        <?php foreach ($search_results['movies'] as $movie): ?>
                            <div class="movie-item" onclick="openMovie(<?= $movie['id'] ?>)">
                                <div class="movie-poster">
                                    <img src="<?= $movie['poster'] ?>" 
                                         alt="<?= $movie['title'] ?>"
                                         onerror="this.src='https://via.placeholder.com/300x450/232f3e/00a8e1?text=No+Image'">
                                    
                                    <?php if (!empty($movie['quality'])): ?>
                                        <div class="quality-tag"><?= $movie['quality'] ?></div>
                                    <?php endif; ?>
                                    
                                    <?php if ($movie['rating'] > 0): ?>
                                        <div class="rating-tag">
                                            <i class="fas fa-star"></i>
                                            <?= number_format($movie['rating'], 1) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="movie-details">
                                    <div class="movie-title"><?= $movie['title'] ?></div>
                                    
                                    <div class="movie-meta">
                                        <span><?= $movie['year'] > 0 ? $movie['year'] : 'N/A' ?></span>
                                        <?php if (!empty($movie['duration'])): ?>
                                            <span><?= $movie['duration'] ?></span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <?php if (!empty($movie['genre'])): ?>
                                        <div class="movie-genre"><?= $movie['genre'] ?></div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($movie['description'])): ?>
                                        <div class="movie-description"><?= $movie['description'] ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-message">
                        <i class="fas fa-film"></i>
                        <h3>No hay películas</h3>
                        <p>No se encontraron películas para "<?= htmlspecialchars($search_query) ?>"</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Series Tab -->
            <div id="series-panel" class="tab-panel">
                <?php if (!empty($search_results['series'])): ?>
                    <div class="movie-grid">
                        <?php foreach ($search_results['series'] as $series): ?>
                            <div class="movie-item" onclick="openSeries(<?= $series['id'] ?>)">
                                <div class="movie-poster">
                                    <img src="<?= $series['poster'] ?>" 
                                         alt="<?= $series['title'] ?>"
                                         onerror="this.src='https://via.placeholder.com/300x450/232f3e/00a8e1?text=No+Image'">
                                    
                                    <div class="quality-tag">SERIES</div>
                                    
                                    <?php if ($series['rating'] > 0): ?>
                                        <div class="rating-tag">
                                            <i class="fas fa-star"></i>
                                            <?= number_format($series['rating'], 1) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="movie-details">
                                    <div class="movie-title"><?= $series['title'] ?></div>
                                    
                                    <div class="movie-meta">
                                        <span><?= $series['year'] > 0 ? $series['year'] : 'N/A' ?></span>
                                        <span><i class="fas fa-tv"></i> Series</span>
                                    </div>
                                    
                                    <?php if (!empty($series['genre'])): ?>
                                        <div class="movie-genre"><?= $series['genre'] ?></div>
                                    <?php endif; ?>
                                    
                                    <?php if (!empty($series['description'])): ?>
                                        <div class="movie-description"><?= $series['description'] ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-message">
                        <i class="fas fa-tv"></i>
                        <h3>No hay series</h3>
                        <p>No se encontraron series para "<?= htmlspecialchars($search_query) ?>"</p>
                    </div>
                <?php endif; ?>
            </div>

        <?php elseif (!empty($search_query)): ?>
            <div class="empty-message">
                <i class="fas fa-search"></i>
                <h3>Sin resultados</h3>
                <p>No se encontró contenido para "<?= htmlspecialchars($search_query) ?>"</p>
            </div>
        <?php else: ?>
            <div class="empty-message">
                <i class="fas fa-search"></i>
                <h3>Buscar contenido</h3>
                <p>Usa la búsqueda para encontrar películas y series</p>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function openMovie(movieId) {
            window.location.href = `movie-detail.php?id=${movieId}`;
        }

        function openSeries(seriesId) {
            window.location.href = `series-detail.php?id=${seriesId}`;
        }

        document.addEventListener('DOMContentLoaded', function() {
            const tabBtns = document.querySelectorAll('.tab-btn');
            const tabPanels = document.querySelectorAll('.tab-panel');

            tabBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    const targetTab = this.getAttribute('data-tab');
                    
                    tabBtns.forEach(b => b.classList.remove('active'));
                    tabPanels.forEach(p => p.classList.remove('active'));
                    
                    this.classList.add('active');
                    document.getElementById(targetTab + '-panel').classList.add('active');
                });
            });

            // Add loading effect to movie items
            const movieItems = document.querySelectorAll('.movie-item');
            movieItems.forEach(item => {
                item.addEventListener('click', function() {
                    this.style.opacity = '0.7';
                    this.style.pointerEvents = 'none';
                });
            });
        });
    </script>
</body>
</html>