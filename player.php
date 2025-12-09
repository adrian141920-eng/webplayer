<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('memory_limit', '512M'); // o 256M si prefieres
session_start();

if (!isset($_SESSION['user_logged'])) {
    header('Location: index.php');
    exit;
}

require_once 'config.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : null;
$slug = $_GET['slug'] ?? 'movie';
$ext = isset($_GET['ext']) ? $_GET['ext'] : 'mkv'; // Por defecto mkv
$series_id = isset($_GET['series_id']) ? intval($_GET['series_id']) : null;

if (!$id || !in_array($slug, ['movie', 'series'])) {
    die("Invalid parameters");
}

$username = $_SESSION['username'];
$password = $_SESSION['password'];
$server_url = rtrim($_SESSION['server_url'], '/');

$config = loadConfig();
$selected_player = $config['video_player'];
$autoplay = $config['autoplay'];
$save_position = $config['save_position'];
$auto_next_episode = $config['auto_next_episode'];

function getContentInfo($id, $slug, $server_url, $username, $password, $series_id = null) {
    if ($slug === 'movie') {
        $url = $server_url . "/player_api.php?username=$username&password=$password&action=get_vod_info&vod_id=$id";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0'
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        return json_decode($response, true);
    } else {
        if (!$series_id) return [];
        $url = $server_url . "/player_api.php?username=$username&password=$password&action=get_series_info&series_id=$series_id";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0'
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($response, true);
        return $data;
    }
}

$info_data = getContentInfo($id, $slug, $server_url, $username, $password, $series_id);
$info = $info_data['info'] ?? [];

if ($slug === 'movie') {
    $title = $info['name'] ?? 'Movie';
    $series_title = '';
    $video_url = "$server_url/movie/$username/$password/$id.$ext";
} else {
    $title = 'Episode';
    $series_title = $info['name'] ?? 'Series';
    $video_url = '';
    // Buscar el episodio correcto y usar direct_source si existe
    if (!empty($info_data['episodes']) && is_array($info_data['episodes'])) {
        foreach ($info_data['episodes'] as $season) {
            foreach ($season as $episode) {
                if (isset($episode['id']) && $episode['id'] == $id) {
                    if (!empty($episode['direct_source'])) {
                        $video_url = $episode['direct_source'];
                    } else {
                        $video_url = "$server_url/series/$username/$password/$id.$ext";
                    }
                    break 2;
                }
            }
        }
    }
    if (!$video_url) {
        $video_url = "$server_url/series/$username/$password/$id.$ext";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($title) ?> - IPTV Player</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <?php if ($selected_player === 'videojs'): ?>
    <link href="https://vjs.zencdn.net/7.20.3/video-js.css" rel="stylesheet">
    <?php elseif ($selected_player === 'plyr'): ?>
    <link rel="stylesheet" href="https://cdn.plyr.io/3.7.8/plyr.css" />
    <?php endif; ?>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #000; color: #fff; font-family: 'Helvetica Neue', Arial, sans-serif; overflow: hidden; }
        .player-container { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: #000; z-index: 9999; }
        .video-player { position: absolute; top: 0; left: 0; width: 100% !important; height: 100% !important; background: #000; }
        .plyr { width: 100% !important; height: 100% !important; }
        .plyr__video-wrapper { background: #000; }
        .plyr--video { background: #000; }
        .plyr__controls { background: linear-gradient(transparent, rgba(0,0,0,0.8)) !important; color: white !important; }
        .plyr__control--overlaid { background: rgba(0,0,0,0.7) !important; color: white !important; }
        .plyr-style::-webkit-media-controls-panel { background: linear-gradient(transparent, rgba(0,0,0,0.8)); }
        .plyr-style::-webkit-media-controls-play-button, .plyr-style::-webkit-media-controls-mute-button, .plyr-style::-webkit-media-controls-fullscreen-button { background: rgba(0,0,0,0.7); border-radius: 6px; margin: 0 4px; }
        .plyr-style::-webkit-media-controls-timeline { background: rgba(255,255,255,0.3); border-radius: 2px; height: 6px; }
        .plyr-style::-webkit-media-controls-current-time-display, .plyr-style::-webkit-media-controls-time-remaining-display { background: rgba(0,0,0,0.7); border-radius: 4px; padding: 2px 6px; font-size: 12px; }
        .clapper-style::-webkit-media-controls-panel { background: linear-gradient(transparent, rgba(0,0,0,0.6)); }
        .clapper-style::-webkit-media-controls-play-button, .clapper-style::-webkit-media-controls-mute-button, .clapper-style::-webkit-media-controls-fullscreen-button { background: transparent; color: white; opacity: 0.8; }
        .clapper-style::-webkit-media-controls-play-button:hover, .clapper-style::-webkit-media-controls-mute-button:hover, .clapper-style::-webkit-media-controls-fullscreen-button:hover { opacity: 1; background: rgba(255,255,255,0.1); border-radius: 50%; }
        .clapper-style::-webkit-media-controls-timeline { background: rgba(255,255,255,0.2); height: 4px; border-radius: 2px; }
        .clapper-style::-webkit-media-controls-current-time-display, .clapper-style::-webkit-media-controls-time-remaining-display { color: white; font-size: 11px; opacity: 0.9; }
        .top-controls { position: fixed; top: 0; left: 0; right: 0; z-index: 10000; background: linear-gradient(180deg, rgba(0,0,0,0.8) 0%, transparent 100%); padding: 20px 30px; display: flex; align-items: center; justify-content: space-between; transition: opacity 0.3s; }
        .top-left { display: flex; align-items: center; gap: 20px; }
        .back-button { background: rgba(42,42,42,.6); backdrop-filter: blur(10px); border: none; color: white; padding: 12px 16px; border-radius: 50%; cursor: pointer; font-size: 18px; transition: all 0.3s; width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; }
        .back-button:hover { background: rgba(42,42,42,.8); transform: scale(1.1); }
        .video-info { display: flex; flex-direction: column; gap: 5px; }
        .video-title { font-size: 20px; font-weight: 600; }
        .video-series { font-size: 14px; color: #ccc; }
        .top-right { display: flex; align-items: center; gap: 15px; }
        .settings-button { background: rgba(42,42,42,.6); backdrop-filter: blur(10px); border: none; color: white; padding: 10px 15px; border-radius: 8px; cursor: pointer; font-size: 14px; transition: all 0.3s; display: flex; align-items: center; gap: 8px; text-decoration: none; }
        .player-indicator { background: rgba(42,42,42,.6); backdrop-filter: blur(10px); padding: 8px 12px; border-radius: 6px; font-size: 12px; color: #ccc; }
        .hide-ui .top-controls { opacity: 0; pointer-events: none; }
        .hide-cursor { cursor: none; }
        @media (max-width: 768px) {
            .top-controls { padding: 15px 20px; }
            .back-button { width: 45px; height: 45px; font-size: 16px; }
            .video-title { font-size: 18px; }
        }
    </style>
</head>
<body>
    <div class="top-controls" id="topControls">
        <div class="top-left">
            <button class="back-button" onclick="goBack()" title="Back">
                <i class="fas fa-arrow-left"></i>
            </button>
            <div class="video-info">
                <div class="video-title"><?= htmlspecialchars($title) ?></div>
                <?php if ($series_title): ?>
                <div class="video-series"><?= htmlspecialchars($series_title) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="top-right">
            <div class="player-indicator">
                <?= strtoupper($selected_player) ?>
            </div>
            <a href="settings.php" class="settings-button" title="Settings">
                <i class="fas fa-cog"></i>
                <span>Settings</span>
            </a>
        </div>
    </div>
    
    <div class="player-container">
        <?php if ($selected_player === 'videojs'): ?>
        <video id="videoPlayer" 
               class="video-js vjs-default-skin video-player"
               controls 
               <?= $autoplay ? 'autoplay' : '' ?>
               preload="auto"
               data-setup='{}'>
            <source src="<?= htmlspecialchars($video_url) ?>" type="<?= $ext === 'm3u8' ? 'application/x-mpegURL' : ($ext === 'ts' ? 'video/mp2t' : 'video/mp4') ?>">
        </video>
        
        <?php elseif ($selected_player === 'plyr'): ?>
        <video id="videoPlayer" 
               class="video-player"
               controls 
               <?= $autoplay ? 'autoplay' : '' ?>
               preload="auto"
               playsinline>
            <source src="<?= htmlspecialchars($video_url) ?>" type="<?= $ext === 'm3u8' ? 'application/x-mpegURL' : ($ext === 'ts' ? 'video/mp2t' : 'video/mp4') ?>">
        </video>
        
        <?php else: ?>
        <video id="videoPlayer" 
               class="video-player native-player <?= $selected_player ?>-style"
               controls 
               <?= $autoplay ? 'autoplay' : '' ?>
               preload="auto"
               controlslist="nodownload">
            <source src="<?= htmlspecialchars($video_url) ?>" type="<?= $ext === 'm3u8' ? 'application/x-mpegURL' : ($ext === 'ts' ? 'video/mp2t' : 'video/mp4') ?>">
        </video>
        <?php endif; ?>
    </div>
    <?php if ($selected_player === 'videojs'): ?>
    <script src="https://vjs.zencdn.net/7.20.3/video.min.js"></script>
    <script>
        let player = videojs('videoPlayer', {
            controls: true,
            autoplay: <?= $autoplay ? 'true' : 'false' ?>,
            preload: 'auto',
            playbackRates: [0.5, 0.75, 1, 1.25, 1.5, 2],
            fluid: false,
            responsive: false,
            language: 'en',
            techOrder: ['html5'],
            html5: {
                vhs: { overrideNative: true }
            }
        });
        player.ready(function() {
            initializePlayer();
        });
    </script>
    <?php elseif ($selected_player === 'plyr'): ?>
    <script src="https://cdn.plyr.io/3.7.8/plyr.polyfilled.js"></script>
    <script>
        const player = new Plyr('#videoPlayer', {
            controls: ['play-large', 'play', 'progress', 'current-time', 'mute', 'volume', 'settings', 'fullscreen'],
            settings: ['quality', 'speed'],
            speed: { selected: 1, options: [0.5, 0.75, 1, 1.25, 1.5, 2] },
            autoplay: <?= $autoplay ? 'true' : 'false' ?>,
            hideControls: true
        });
        initializePlayer();
    </script>
    <?php elseif ($selected_player === 'clapper'): ?>
    <script src="https://cdn.jsdelivr.net/npm/@clapper-app/clapper@latest/dist/clapper.js"></script>
    <script>
        const player = new Clapper('#videoPlayer', {
            src: '<?= htmlspecialchars($video_url) ?>',
            autoplay: <?= $autoplay ? 'true' : 'false' ?>,
            controls: true,
            fullscreen: true
        });
        initializePlayer();
    </script>
    <?php endif; ?>
    <script>
        const id = '<?= $id ?>';
        const slug = '<?= $slug ?>';
        const series_id = '<?= $series_id ?>';
        const selectedPlayer = '<?= $selected_player ?>';
        const config = {
            autoplay: <?= $autoplay ? 'true' : 'false' ?>,
            savePosition: <?= $save_position ? 'true' : 'false' ?>,
            autoNextEpisode: <?= $auto_next_episode ? 'true' : 'false' ?>
        };
        let uiTimer;
        
        function initializePlayer() {
            if (config.savePosition) {
                const lastPosition = parseFloat(localStorage.getItem('videoLastPosition_' + id)) || 0;
                if (lastPosition > 0) {
                    setTimeout(() => {
                        setCurrentTime(lastPosition);
                    }, 1000);
                }
            }
            if (config.savePosition) {
                setInterval(() => {
                    const currentTime = getCurrentTime();
                    if (currentTime > 0) {
                        localStorage.setItem('videoLastPosition_' + id, currentTime);
                    }
                }, 5000);
            }
            showUI();
        }
        function getCurrentTime() {
            <?php if ($selected_player === 'videojs'): ?>
            return player && player.currentTime ? player.currentTime() : 0;
            <?php elseif ($selected_player === 'plyr'): ?>
            return player && player.currentTime !== undefined ? player.currentTime : 0;
            <?php else: ?>
            const videoElement = document.getElementById('videoPlayer');
            return videoElement ? videoElement.currentTime : 0;
            <?php endif; ?>
        }
        function setCurrentTime(time) {
            try {
                <?php if ($selected_player === 'videojs'): ?>
                if (player && player.currentTime) player.currentTime(time);
                <?php elseif ($selected_player === 'plyr'): ?>
                if (player && player.currentTime !== undefined) player.currentTime = time;
                <?php else: ?>
                const videoElement = document.getElementById('videoPlayer');
                if (videoElement) videoElement.currentTime = time;
                <?php endif; ?>
            } catch (e) { }
        }
        function isPlayerPaused() {
            try {
                <?php if ($selected_player === 'videojs'): ?>
                return player ? player.paused() : true;
                <?php elseif ($selected_player === 'plyr'): ?>
                return player ? player.paused : true;
                <?php else: ?>
                const videoElement = document.getElementById('videoPlayer');
                return videoElement ? videoElement.paused : true;
                <?php endif; ?>
            } catch (e) { return true; }
        }
        function showUI() {
            document.body.classList.remove('hide-ui', 'hide-cursor');
            clearTimeout(uiTimer);
            uiTimer = setTimeout(hideUI, 4000);
        }
        function hideUI() {
            if (!isPlayerPaused()) {
                document.body.classList.add('hide-ui', 'hide-cursor');
            }
        }
        function goBack() {
            if (config.savePosition) {
                const currentTime = getCurrentTime();
                if (currentTime > 0) {
                    localStorage.setItem('videoLastPosition_' + id, currentTime);
                }
            }
            if (slug === 'series' && series_id) {
                window.location.href = `series-detail.php?id=${series_id}`;
            } else if (slug === 'movie') {
                window.location.href = `movie-detail.php?id=${id}`;
            } else {
                window.history.back();
            }
        }
        document.addEventListener('keydown', function(e) {
            switch(e.key) {
                case 'f':
                case 'F':
                    if (document.fullscreenElement) {
                        document.exitFullscreen();
                    } else {
                        document.documentElement.requestFullscreen();
                    }
                    break;
                case ' ':
                    e.preventDefault();
                    <?php if ($selected_player === 'videojs'): ?>
                    if (player) {
                        if (player.paused()) {
                            player.play();
                        } else {
                            player.pause();
                        }
                    }
                    <?php elseif ($selected_player === 'plyr'): ?>
                    if (player) {
                        if (player.paused) {
                            player.play();
                        } else {
                            player.pause();
                        }
                    }
                    <?php else: ?>
                    const videoElement = document.getElementById('videoPlayer');
                    if (videoElement) {
                        if (videoElement.paused) {
                            videoElement.play();
                        } else {
                            videoElement.pause();
                        }
                    }
                    <?php endif; ?>
                    break;
                case 's':
                case 'S':
                    window.location.href = 'settings.php';
                    break;
                case 'Escape':
                    goBack();
                    break;
                case 'ArrowLeft':
                    e.preventDefault();
                    setCurrentTime(getCurrentTime() - 10);
                    break;
                case 'ArrowRight':
                    e.preventDefault();
                    setCurrentTime(getCurrentTime() + 10);
                    break;
            }
        });
        document.addEventListener('mousemove', showUI);
        document.addEventListener('mouseenter', showUI);
        document.addEventListener('click', function(e) {
            if (!e.target.closest('button') && !e.target.closest('.vjs-control-bar') && !e.target.closest('.plyr__controls')) {
                showUI();
            }
        });
        document.addEventListener('touchstart', showUI);
        document.addEventListener('contextmenu', function(e) {
            e.preventDefault();
        });
        document.addEventListener('wheel', function(e) {
            if (e.ctrlKey) {
                e.preventDefault();
            }
        }, { passive: false });
    </script>
</body>
</html>
