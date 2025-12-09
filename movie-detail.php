<?php
session_start();

if (!isset($_SESSION['user_logged'])) {
    header('Location: index.php');
    exit;
}

$movie_id = $_GET['id'] ?? null;
if (!$movie_id) {
    header('Location: movies.php');
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

$movie_info = makeApiRequest('get_vod_info', ['vod_id' => $movie_id]);

if (!$movie_info || !isset($movie_info['info'])) {
    header('Location: movies.php');
    exit;
}

$movie = $movie_info['info'];
$movie_data = $movie_info['movie_data'] ?? null;

$tmdb_data = null;
$tmdb_id = null;

$movie_title = $movie_data['name'] ?? 'Untitled';

$movie_title = preg_replace('/\s*\|\s*.*$/', '', $movie_title);
$movie_title = preg_replace('/\s*\(\d{4}\)\s*/', '', $movie_title);
$movie_title = preg_replace('/\s*\(.*?\)\s*/', '', $movie_title);
$movie_title = trim($movie_title);

if (!empty($movie_title)) {
    $clean_name = $movie_title;
    
    $search_results = tmdbRequest('/search/movie', ['query' => $clean_name, 'language' => 'en-US']);
    
    if (empty($search_results['results'])) {
        $english_title = '';
        if (strpos($movie_data['name'], '|') !== false) {
            $parts = explode('|', $movie_data['name']);
            if (isset($parts[1])) {
                $english_title = trim($parts[1]);
                $english_title = preg_replace('/\s*\(\d{4}\)\s*/', '', $english_title);
                $english_title = preg_replace('/\s*\(.*?\)\s*/', '', $english_title);
                $english_title = trim($english_title);
            }
        }
        
        if ($english_title) {
            $search_results = tmdbRequest('/search/movie', ['query' => $english_title, 'language' => 'en-US']);
        }
    }
    
    if (empty($search_results['results'])) {
        $search_results = tmdbRequest('/search/movie', ['query' => $clean_name, 'language' => 'en-US']);
    }
    
    if (!empty($search_results['results'])) {
        $tmdb_id = $search_results['results'][0]['id'];
        $tmdb_data = tmdbRequest('/movie/' . $tmdb_id, [
            'append_to_response' => 'credits,videos,images', 
            'language' => 'en-US'
        ]);
    }
}

$backdrop_url = null;
if (!empty($movie['backdrop_path'][0])) {
    $backdrop_url = $movie['backdrop_path'][0];
} elseif ($tmdb_data && !empty($tmdb_data['backdrop_path'])) {
    $backdrop_url = TMDB_IMG_URL . 'original' . $tmdb_data['backdrop_path'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($movie['name'] ?? 'Movie') ?> - IPTV Pro</title>
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
        }
        
        .main-content {
            margin-left: 280px;
            background: #0f171e;
            overflow: hidden;
        }
        
        .hero {
            position: relative;
            height: 100vh;
            width: 100%;
            overflow: hidden;
            display: flex;
            align-items: center;
        }
        
        .hero-bg {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url('<?= htmlspecialchars($backdrop_url) ?>');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            filter: brightness(0.3);
            transition: opacity 0.3s ease;
            z-index: 1;
        }
        
        /* Video background for trailer */
        .hero-video-bg {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 2;
            opacity: 0;
            transition: opacity 0.5s ease;
        }
        
        .hero-video-bg.show {
            opacity: 1;
        }
        
        .hero-video-bg iframe {
            position: absolute;
            top: 50%;
            left: 50%;
            width: 100vw;
            height: 56.25vw;
            min-height: 100vh;
            min-width: 177.77vh;
            transform: translate(-50%, -50%);
            pointer-events: none;
            filter: brightness(0.4);
        }
        
        .hero-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(
                90deg,
                rgba(15,23,30,0.9) 0%,
                rgba(15,23,30,0.7) 40%,
                rgba(15,23,30,0.4) 70%,
                transparent 100%
            );
            z-index: 3;
        }
        
        .hero-content {
            position: relative;
            z-index: 10;
            padding: 0 60px;
            max-width: 55%;
            height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 20px;
        }
        
        .movie-main-title {
            font-size: clamp(2.5rem, 4vw, 4rem);
            font-weight: 700;
            line-height: 1.1;
            margin-bottom: 15px;
            text-shadow: 2px 2px 8px rgba(0,0,0,0.8);
            color: #fff;
            max-height: 200px;
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
        }
        
        .metadata {
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 16px;
            flex-wrap: wrap;
            margin-bottom: 20px;
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
        
        .duration {
            background: rgba(255,255,255,0.2);
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            border: 1px solid rgba(255,255,255,0.3);
        }
        
        .quality-badge {
            background: #007acc;
            color: white;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
        }
        
        .synopsis {
            font-size: 18px;
            line-height: 1.5;
            text-shadow: 1px 1px 4px rgba(0,0,0,0.8);
            color: rgba(255,255,255,0.95);
            margin-bottom: 25px;
            display: -webkit-box;
            -webkit-line-clamp: 8;
            -webkit-box-orient: vertical;
            overflow: hidden;
            max-height: 308px;
        }
        
        .genres {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 25px;
        }
        
        .genre {
            color: rgba(255,255,255,0.9);
            font-size: 15px;
            font-weight: 500;
            text-shadow: 1px 1px 3px rgba(0,0,0,0.8);
        }
        
        .button-layer {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }
        
        .play-button {
            background: #fff;
            border: none;
            border-radius: 8px;
            color: #000;
            display: flex;
            align-items: center;
            font-size: 16px;
            font-weight: 600;
            padding: 14px 28px;
            text-decoration: none;
            transition: all 0.3s ease;
            gap: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        }
        
        .play-button:hover {
            background: rgba(255,255,255,0.9);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0,0,0,0.4);
        }
        
        .hero-cast-section {
            max-height: 140px;
            overflow: hidden;
        }
        
        .hero-cast-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 15px;
            color: rgba(255,255,255,0.95);
            text-shadow: 1px 1px 4px rgba(0,0,0,0.8);
        }
        
        .hero-cast-grid {
            display: flex;
            gap: 15px;
            overflow-x: auto;
            padding-bottom: 8px;
            scrollbar-width: thin;
            scrollbar-color: rgba(255,255,255,0.3) transparent;
        }
        
        .hero-cast-grid::-webkit-scrollbar {
            height: 4px;
        }
        
        .hero-cast-grid::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.1);
            border-radius: 2px;
        }
        
        .hero-cast-grid::-webkit-scrollbar-thumb {
            background: rgba(255,255,255,0.3);
            border-radius: 2px;
        }
        
        .hero-cast-member {
            flex-shrink: 0;
            width: 80px;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .hero-cast-member:hover {
            transform: translateY(-5px);
        }
        
        .hero-cast-photo {
            width: 80px;
            height: 100px;
            border-radius: 10px;
            object-fit: cover;
            background: rgba(255,255,255,0.1);
            margin-bottom: 8px;
            border: 2px solid rgba(255,255,255,0.2);
            box-shadow: 0 4px 15px rgba(0,0,0,0.5);
            transition: all 0.3s ease;
        }
        
        .hero-cast-member:hover .hero-cast-photo {
            border-color: rgba(255,255,255,0.4);
            box-shadow: 0 8px 25px rgba(0,0,0,0.6);
        }
        
        .hero-cast-name {
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
        
        .hero-cast-character {
            font-size: 10px;
            color: rgba(255,255,255,0.7);
            text-shadow: 1px 1px 3px rgba(0,0,0,0.8);
            line-height: 1.2;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        /* RESPONSIVE */
        @media (max-width: 992px) {
            .main-content { 
                margin-left: 0; 
            }
            
            .hero-content {
                padding: 0 40px;
                max-width: 65%;
            }
            
            .movie-main-title {
                font-size: clamp(2.2rem, 3.5vw, 3.5rem);
            }
        }
        
        @media (max-width: 768px) {
            .hero-content {
                padding: 0 25px;
                max-width: 85%;
                gap: 15px;
            }
            
            .movie-main-title {
                font-size: clamp(1.8rem, 3vw, 2.8rem);
                max-height: 150px;
            }
            
            .synopsis {
                font-size: 16px;
                -webkit-line-clamp: 3;
                max-height: 72px;
            }
            
            .metadata {
                font-size: 14px;
                gap: 10px;
            }
            
            .button-layer {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            
            .play-button {
                width: 100%;
                max-width: 280px;
                justify-content: center;
                padding: 12px 24px;
            }
            
            .hero-cast-grid {
                gap: 12px;
            }
            
            .hero-cast-member {
                width: 70px;
            }
            
            .hero-cast-photo {
                width: 70px;
                height: 90px;
            }
            
            .hero-cast-section {
                max-height: 120px;
            }
        }
        
        @media (max-width: 480px) {
            .hero-content {
                max-width: 95%;
                gap: 12px;
            }
            
            .movie-main-title {
                font-size: clamp(1.5rem, 2.5vw, 2.2rem);
                max-height: 120px;
            }
            
            .synopsis {
                font-size: 15px;
                -webkit-line-clamp: 2;
                max-height: 45px;
            }
            
            .hero-cast-section {
                max-height: 100px;
            }
            
            .hero-cast-title {
                font-size: 18px;
            }
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    
    <div class="main-content">
        <div class="hero">
            <div class="hero-bg"></div>
            
            <!-- Video background for trailer -->
            <?php if ($tmdb_data && !empty($tmdb_data['videos']['results'])): ?>
                <?php 
                $trailer = null;
                foreach ($tmdb_data['videos']['results'] as $video) {
                    if ($video['type'] === 'Trailer' && $video['site'] === 'YouTube') {
                        $trailer = $video;
                        break;
                    }
                }
                ?>
                <?php if ($trailer): ?>
                <div class="hero-video-bg" id="heroVideoBg">
                    <iframe id="heroTrailerIframe" src="" allow="autoplay"></iframe>
                </div>
                
                <script>
                    // Trailer background configuration
                    const trailerKey = '<?= $trailer['key'] ?>';
                    let trailerTimeout;
                    let showTimeout;
                    let fadeoutTimeout;
                    let hasUserInteracted = false;
                    
                    // Start trailer preload immediately when page loads
                    document.addEventListener('DOMContentLoaded', function() {
                        startTrailerPreload();
                        
                        // Listen for any user interaction to enable audio
                        document.addEventListener('click', handleUserInteraction);
                        document.addEventListener('keydown', handleUserInteraction);
                        document.addEventListener('touchstart', handleUserInteraction);
                    });
                    
                    function handleUserInteraction() {
                        if (!hasUserInteracted) {
                            hasUserInteracted = true;
                            // Reload iframe with audio if trailer is currently playing
                            const iframe = document.getElementById('heroTrailerIframe');
                            const videoBg = document.getElementById('heroVideoBg');
                            
                            if (videoBg.classList.contains('show') && iframe.src) {
                                // Reload with audio enabled
                                const currentSrc = iframe.src;
                                if (currentSrc.includes('mute=1')) {
                                    iframe.src = currentSrc.replace('mute=1', 'mute=0');
                                }
                            }
                        }
                    }
                    
                    function startTrailerPreload() {
                        const videoBg = document.getElementById('heroVideoBg');
                        const iframe = document.getElementById('heroTrailerIframe');
                        
                        // Start with muted to allow autoplay, then unmute after user interaction
                        iframe.src = `https://www.youtube.com/embed/${trailerKey}?autoplay=1&controls=0&modestbranding=1&rel=0&showinfo=0&fs=0&iv_load_policy=3&start=5&end=45&mute=1&loop=0`;
                        
                        // Show trailer after 2 seconds
                        showTimeout = setTimeout(() => {
                            videoBg.classList.add('show');
                            
                            // Try to enable audio after a short delay if user has interacted
                            setTimeout(() => {
                                if (hasUserInteracted) {
                                    iframe.src = iframe.src.replace('mute=1', 'mute=0');
                                }
                            }, 1000);
                            
                            // Start audio fadeout 3 seconds before hiding trailer (at 37 seconds)
                            fadeoutTimeout = setTimeout(() => {
                                startAudioFadeout();
                            }, 37000);
                            
                            // Hide trailer after 40 seconds
                            trailerTimeout = setTimeout(() => {
                                stopTrailerBackground();
                            }, 40000);
                        }, 2000);
                    }
                    
                    function startAudioFadeout() {
                        const iframe = document.getElementById('heroTrailerIframe');
                        // Gradually mute the video at the end
                        setTimeout(() => {
                            if (iframe.src && iframe.src.includes('mute=0')) {
                                iframe.src = iframe.src.replace('mute=0', 'mute=1');
                            }
                        }, 2000); // Mute 1 second before video ends
                    }
                    
                    function stopTrailerBackground() {
                        const videoBg = document.getElementById('heroVideoBg');
                        const iframe = document.getElementById('heroTrailerIframe');
                        
                        // Hide video background with smooth transition
                        videoBg.classList.remove('show');
                        
                        setTimeout(() => {
                            iframe.src = ''; // Stop video completely
                        }, 500);
                        
                        clearTimeout(trailerTimeout);
                        clearTimeout(showTimeout);
                        clearTimeout(fadeoutTimeout);
                    }
                    
                    // Clean up
                    window.addEventListener('beforeunload', function() {
                        clearTimeout(trailerTimeout);
                        clearTimeout(showTimeout);
                        clearTimeout(fadeoutTimeout);
                    });
                </script>
                <?php endif; ?>
            <?php endif; ?>
            
            <div class="hero-overlay"></div>
            
            <div class="hero-content">
                <h1 class="movie-main-title"><?= htmlspecialchars($movie_title) ?></h1>
                
                <div class="metadata">
                    <?php if ($tmdb_data && !empty($tmdb_data['vote_average'])): ?>
                        <span class="match-score"><?= round($tmdb_data['vote_average'] * 10) ?>% match</span>
                    <?php endif; ?>
                    
                    <?php if (!empty($movie['year'])): ?>
                        <span class="year"><?= htmlspecialchars($movie['year']) ?></span>
                    <?php endif; ?>
                    
                    <?php if ($tmdb_data && !empty($tmdb_data['vote_average'])): ?>
                        <span class="rating">★ <?= number_format($tmdb_data['vote_average'], 1) ?></span>
                    <?php endif; ?>
                    
                    <?php if (!empty($movie['duration'])): ?>
                        <span class="duration"><?= htmlspecialchars($movie['duration']) ?></span>
                    <?php endif; ?>
                    
                    <span class="quality-badge">HD</span>
                </div>
                
                <div class="synopsis">
                    <?= htmlspecialchars($tmdb_data['overview'] ?? $movie['plot'] ?? 'Enjoy this incredible movie with the best picture and sound quality available.') ?>
                </div>
                
                <?php if ($tmdb_data && !empty($tmdb_data['genres'])): ?>
                <div class="genres">
                    <?php foreach (array_slice($tmdb_data['genres'], 0, 4) as $i => $genre): ?>
                        <span class="genre"><?= htmlspecialchars($genre['name']) ?><?= $i < 3 && $i < count($tmdb_data['genres']) - 1 ? ' • ' : '' ?></span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <div class="button-layer">
                    <a href="player.php?id=<?= urlencode($movie_id) ?>&slug=movie&ext=<?= htmlspecialchars($movie_data['container_extension'] ?? 'mp4') ?>" class="play-button">
                        <i class="fas fa-play"></i>
                        Play
                    </a>
                </div>
                
                <?php if ($tmdb_data && !empty($tmdb_data['credits']['cast'])): ?>
                <div class="hero-cast-section">
                    <h3 class="hero-cast-title">Main Cast</h3>
                    <div class="hero-cast-grid">
                        <?php foreach (array_slice($tmdb_data['credits']['cast'], 0, 12) as $actor): ?>
                            <?php if (!empty($actor['profile_path'])): ?>
                            <div class="hero-cast-member">
                                <img class="hero-cast-photo" 
                                     src="<?= TMDB_IMG_URL ?>w185<?= $actor['profile_path'] ?>" 
                                     alt="<?= htmlspecialchars($actor['name']) ?>"
                                     loading="lazy"
                                     onerror="this.style.display='none';">
                                <div class="hero-cast-name"><?= htmlspecialchars($actor['name']) ?></div>
                                <div class="hero-cast-character"><?= htmlspecialchars($actor['character']) ?></div>
                            </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        const movieTitle = <?= json_encode($movie_title) ?>;
        
        // Smooth entry animation for content
        window.addEventListener('load', function() {
            const heroContent = document.querySelector('.hero-content');
            if (heroContent) {
                heroContent.style.opacity = '0';
                heroContent.style.transform = 'translateY(30px)';
                
                setTimeout(() => {
                    heroContent.style.transition = 'all 0.8s ease';
                    heroContent.style.opacity = '1';
                    heroContent.style.transform = 'translateY(0)';
                }, 100);
            }
        });
        
        // Improve navigation experience
        document.addEventListener('DOMContentLoaded', function() {
            // Preload background image to avoid flickering
            const heroBackground = document.querySelector('.hero-bg');
            if (heroBackground) {
                const backgroundImage = window.getComputedStyle(heroBackground).backgroundImage;
                if (backgroundImage && backgroundImage !== 'none') {
                    const img = new Image();
                    img.src = backgroundImage.slice(5, -2); // Extract URL from url("...")
                }
            }
            
            // Improve scroll experience on touch devices
            if ('ontouchstart' in window) {
                document.body.style.webkitOverflowScrolling = 'touch';
            }
        });
    </script>
</body>
</html>