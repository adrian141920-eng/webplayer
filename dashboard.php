<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

if (!isset($_SESSION['user_logged'])) {
    header('Location: index.php');
    exit;
}

$username = $_SESSION['username'];
$password = $_SESSION['password'];
$server_url = $_SESSION['server_url'];
$server_name = $_SESSION['server_name'];

function makeApiRequest($action, $params = []) {
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

// Obtener datos
$all_movies = makeApiRequest('get_vod_streams') ?: [];
$all_series = makeApiRequest('get_series') ?: [];

// Películas destacadas - OPTIMIZADO
$featured_movies = [];
shuffle($all_movies);
$movies_processed = 0;
foreach ($all_movies as $movie) {
    if (count($featured_movies) >= 15 || $movies_processed >= 25) break; // Límite de procesamiento
    
    if (!empty($movie['name']) && !empty($movie['stream_id'])) {
        $movies_processed++;
        
        // Usar datos básicos primero
        $backdrop_url = $movie['stream_icon'] ?? '';
        $plot = 'Disfruta de esta increíble película.';
        $rating = $movie['rating'] ?? $movie['tmdb_rating'] ?? '';
        $year = $movie['year'] ?? '';
        $genre = $movie['genre'] ?? '';
        
        // Solo hacer llamada API si no tiene imagen básica
        if (empty($backdrop_url) || $movies_processed <= 10) {
            $movie_details = makeApiRequest('get_vod_info', ['vod_id' => $movie['stream_id']]);
            $movie_info = $movie_details['info'] ?? [];
            
            if (!empty($movie_info['backdrop_path'])) {
                if (is_array($movie_info['backdrop_path'])) {
                    $backdrop_url = $movie_info['backdrop_path'][0] ?? $backdrop_url;
                } else {
                    $backdrop_url = $movie_info['backdrop_path'];
                }
            }
            
            if (empty($backdrop_url)) {
                $backdrop_url = $movie_info['cover_big'] ?? $movie_info['movie_image'] ?? $movie['stream_icon'] ?? '';
            }
            
            // Actualizar datos si están disponibles
            $plot = $movie_info['plot'] ?? $plot;
            $rating = $movie_info['rating'] ?? $rating;
            $year = isset($movie_info['releaseDate']) ? date('Y', strtotime($movie_info['releaseDate'])) : $year;
            $genre = $movie_info['genre'] ?? $genre;
        }
        
        if (!empty($backdrop_url)) {
            $featured_movies[] = [
                'stream_id' => $movie['stream_id'],
                'name' => $movie['name'],
                'backdrop_url' => $backdrop_url,
                'plot' => $plot,
                'year' => $year,
                'rating' => $rating,
                'genre' => $genre,
                'container_extension' => $movie['container_extension'] ?? 'mp4',
                'type' => 'movie'
            ];
        }
    }
}

// Series destacadas - OPTIMIZADO
$featured_series = [];
shuffle($all_series);
$series_processed = 0;
foreach ($all_series as $serie) {
    if (count($featured_series) >= 15 || $series_processed >= 25) break; // Límite de procesamiento
    
    if (!empty($serie['name']) && !empty($serie['series_id'])) {
        $series_processed++;
        
        // Usar datos básicos primero
        $backdrop_url = $serie['cover'] ?? '';
        $plot = 'Disfruta de esta increíble serie.';
        $rating = $serie['rating'] ?? $serie['tmdb_rating'] ?? '';
        $year = $serie['year'] ?? '';
        $genre = $serie['genre'] ?? '';
        
        // Solo hacer llamada API si no tiene imagen básica o es de los primeros
        if (empty($backdrop_url) || $series_processed <= 10) {
            $serie_details = makeApiRequest('get_series_info', ['series_id' => $serie['series_id']]);
            $serie_info = $serie_details['info'] ?? [];
            
            if (!empty($serie_info['backdrop_path'])) {
                if (is_array($serie_info['backdrop_path'])) {
                    $backdrop_url = $serie_info['backdrop_path'][0] ?? $backdrop_url;
                } else {
                    $backdrop_url = $serie_info['backdrop_path'];
                }
            }
            
            if (empty($backdrop_url)) {
                $backdrop_url = $serie_info['cover_big'] ?? $serie_info['cover'] ?? $serie['cover'] ?? '';
            }
            
            // Actualizar datos si están disponibles
            $plot = $serie_info['plot'] ?? $plot;
            $rating = $serie_info['rating'] ?? $rating;
            $year = $serie_info['releaseDate'] ? date('Y', strtotime($serie_info['releaseDate'])) : $year;
            $genre = $serie_info['genre'] ?? $genre;
        }
        
        if (!empty($backdrop_url)) {
            $featured_series[] = [
                'series_id' => $serie['series_id'],
                'name' => $serie['name'],
                'backdrop_url' => $backdrop_url,
                'plot' => $plot,
                'year' => $year,
                'rating' => $rating,
                'genre' => $genre,
                'episode_run_time' => $serie_info['episode_run_time'] ?? '',
                'type' => 'series'
            ];
        }
    }
}

// Banner content - usar lo que ya tenemos
$banner_content = array_merge(
    array_slice($featured_movies, 0, 4),
    array_slice($featured_series, 0, 4)
);
shuffle($banner_content);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inicio - IPTV Pro</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="rgvipcss/dashb.css" rel="stylesheet">
</head>
<body>
    <?php include 'header.php'; ?>

    <div class="main-content">
        <!-- BANNER PRINCIPAL -->
        <?php if (!empty($banner_content)): ?>
        <div class="main-banner">
            <?php foreach ($banner_content as $index => $item): ?>
            <div class="banner-slide <?= $index === 0 ? 'active' : '' ?>">
                <div class="banner-bg" style="background-image: url('<?= htmlspecialchars($item['backdrop_url']) ?>');"></div>
                <div class="banner-overlay"></div>
                
                <div class="banner-content">
                    <h1 class="banner-title"><?= htmlspecialchars($item['name']) ?></h1>
                    
                    <div class="banner-meta">
                        <span class="meta-item type"><?= $item['type'] === 'movie' ? 'Película' : 'Serie' ?></span>
                        
                        <?php if (!empty($item['rating']) && $item['rating'] != '0'): ?>
                        <span class="meta-item rating">★ <?= number_format(floatval($item['rating']), 1) ?></span>
                        <?php endif; ?>
                        
                        <?php if (!empty($item['year'])): ?>
                        <span class="meta-item"><?= htmlspecialchars($item['year']) ?></span>
                        <?php endif; ?>
                        
                        <?php if (!empty($item['genre'])): ?>
                        <span class="meta-item"><?= htmlspecialchars($item['genre']) ?></span>
                        <?php endif; ?>
                    </div>
                    
                    <p class="banner-description">
                        <?= htmlspecialchars($item['plot']) ?>
                    </p>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if (count($banner_content) > 1): ?>
            <div class="banner-dots">
                <?php for($i = 0; $i < count($banner_content); $i++): ?>
                <div class="dot <?= $i === 0 ? 'active' : '' ?>" onclick="goToSlide(<?= $i ?>)"></div>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- SECCIONES DE CONTENIDO -->
        <div class="content-sections">
            <!-- SECCIÓN DE PELÍCULAS -->
            <?php if (!empty($featured_movies)): ?>
            <div class="section">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-film section-icon"></i>
                        Películas Destacadas
                    </h2>
                    <a href="movies.php" class="view-all">
                        Ver todas <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
                
                <div class="content-grid">
                    <div class="content-row">
                        <?php foreach (array_slice($featured_movies, 4, 5) as $movie): ?>
                        <div class="content-card" onclick="openMovie(<?= $movie['stream_id'] ?>)">
                            <div class="card-background" style="background-image: url('<?= htmlspecialchars($movie['backdrop_url']) ?>');"></div>
                            
                            <div class="type-badge movie">Película</div>
                            
                            <div class="play-button" onclick="event.stopPropagation(); playMovie(<?= $movie['stream_id'] ?>, '<?= $movie['container_extension'] ?>')">
                                <i class="fas fa-play"></i>
                            </div>
                            
                            <div class="card-overlay">
                                <div class="card-title"><?= htmlspecialchars($movie['name']) ?></div>
                                
                                <div class="card-meta">
                                    <span class="card-year"><?= !empty($movie['year']) ? htmlspecialchars($movie['year']) : 'N/A' ?></span>
                                    
                                    <?php if (!empty($movie['rating']) && $movie['rating'] != '0'): ?>
                                    <span class="card-rating">★ <?= number_format(floatval($movie['rating']), 1) ?></span>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($movie['genre'])): ?>
                                <div class="card-genre"><?= htmlspecialchars($movie['genre']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="content-row">
                        <?php foreach (array_slice($featured_movies, 9, 5) as $movie): ?>
                        <div class="content-card" onclick="openMovie(<?= $movie['stream_id'] ?>)">
                            <div class="card-background" style="background-image: url('<?= htmlspecialchars($movie['backdrop_url']) ?>');"></div>
                            
                            <div class="type-badge movie">Película</div>
                            
                            <div class="play-button" onclick="event.stopPropagation(); playMovie(<?= $movie['stream_id'] ?>, '<?= $movie['container_extension'] ?>')">
                                <i class="fas fa-play"></i>
                            </div>
                            
                            <div class="card-overlay">
                                <div class="card-title"><?= htmlspecialchars($movie['name']) ?></div>
                                
                                <div class="card-meta">
                                    <span class="card-year"><?= !empty($movie['year']) ? htmlspecialchars($movie['year']) : 'N/A' ?></span>
                                    
                                    <?php if (!empty($movie['rating']) && $movie['rating'] != '0'): ?>
                                    <span class="card-rating">★ <?= number_format(floatval($movie['rating']), 1) ?></span>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($movie['genre'])): ?>
                                <div class="card-genre"><?= htmlspecialchars($movie['genre']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- SECCIÓN DE SERIES -->
            <?php if (!empty($featured_series)): ?>
            <div class="section">
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-tv section-icon"></i>
                        Series Destacadas
                    </h2>
                    <a href="series.php" class="view-all">
                        Ver todas <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
                
                <div class="content-grid">
                    <div class="content-row">
                        <?php foreach (array_slice($featured_series, 4, 5) as $serie): ?>
                        <div class="content-card" onclick="openSeries(<?= $serie['series_id'] ?>)">
                            <div class="card-background" style="background-image: url('<?= htmlspecialchars($serie['backdrop_url']) ?>');"></div>
                            
                            <div class="type-badge series">Serie</div>
                            
                            <div class="play-button" onclick="event.stopPropagation(); openSeries(<?= $serie['series_id'] ?>)">
                                <i class="fas fa-play"></i>
                            </div>
                            
                            <div class="card-overlay">
                                <div class="card-title"><?= htmlspecialchars($serie['name']) ?></div>
                                
                                <div class="card-meta">
                                    <span class="card-year"><?= !empty($serie['year']) ? htmlspecialchars($serie['year']) : 'N/A' ?></span>
                                    
                                    <?php if (!empty($serie['rating']) && $serie['rating'] != '0'): ?>
                                    <span class="card-rating">★ <?= number_format(floatval($serie['rating']), 1) ?></span>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($serie['genre'])): ?>
                                <div class="card-genre"><?= htmlspecialchars($serie['genre']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="content-row">
                        <?php foreach (array_slice($featured_series, 9, 5) as $serie): ?>
                        <div class="content-card" onclick="openSeries(<?= $serie['series_id'] ?>)">
                            <div class="card-background" style="background-image: url('<?= htmlspecialchars($serie['backdrop_url']) ?>');"></div>
                            
                            <div class="type-badge series">Serie</div>
                            
                            <div class="play-button" onclick="event.stopPropagation(); openSeries(<?= $serie['series_id'] ?>)">
                                <i class="fas fa-play"></i>
                            </div>
                            
                            <div class="card-overlay">
                                <div class="card-title"><?= htmlspecialchars($serie['name']) ?></div>
                                
                                <div class="card-meta">
                                    <span class="card-year"><?= !empty($serie['year']) ? htmlspecialchars($serie['year']) : 'N/A' ?></span>
                                    
                                    <?php if (!empty($serie['rating']) && $serie['rating'] != '0'): ?>
                                    <span class="card-rating">★ <?= number_format(floatval($serie['rating']), 1) ?></span>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($serie['genre'])): ?>
                                <div class="card-genre"><?= htmlspecialchars($serie['genre']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        let currentSlide = 0;
        const bannerContent = <?= json_encode($banner_content) ?>;

        function goToSlide(index) {
            const slides = document.querySelectorAll('.banner-slide');
            const dots = document.querySelectorAll('.dot');
            
            if (slides[currentSlide]) {
                slides[currentSlide].classList.remove('active');
            }
            if (dots[currentSlide]) {
                dots[currentSlide].classList.remove('active');
            }
            
            currentSlide = index;
            
            if (slides[currentSlide]) {
                slides[currentSlide].classList.add('active');
            }
            if (dots[currentSlide]) {
                dots[currentSlide].classList.add('active');
            }
        }

        function openMovie(movieId) {
            window.location.href = `movie-detail.php?id=${movieId}`;
        }

        function playMovie(movieId, extension) {
            window.location.href = `player.php?id=${movieId}&slug=movie&ext=${extension}`;
        }

        function openSeries(seriesId) {
            window.location.href = `series-detail.php?id=${seriesId}`;
        }

        // Auto-slide para el banner
        console.log('Banner content length:', bannerContent ? bannerContent.length : 0);
        
        if (bannerContent && bannerContent.length > 1) {
            console.log('Iniciando auto-slide...');
            setInterval(function() {
                const nextSlide = (currentSlide + 1) % bannerContent.length;
                console.log('Cambiando de slide', currentSlide, 'a', nextSlide);
                goToSlide(nextSlide);
            }, 4500);
        } else {
            console.log('No hay suficiente contenido para auto-slide');
        }

        // Animación de entrada para las cards
        window.addEventListener('load', function() {
            const cards = document.querySelectorAll('.content-card');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(30px)';
                
                setTimeout(() => {
                    card.style.transition = 'all 0.6s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 50);
            });
        });
    </script>
</body>
</html>