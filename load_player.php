<?php
session_start();
if (!isset($_SESSION['user_logged'])) {
    exit('Unauthorized');
}

$username = $_SESSION['username'];
$password = $_SESSION['password'];
$server_url = rtrim($_SESSION['server_url'], '/');
$channel_id = $_GET['id'] ?? '';

if (!$channel_id) {
    exit('Channel ID required');
}

// Build stream URL
$stream_url = $server_url . "/live/" . $username . "/" . $password . "/" . $channel_id . ".m3u8";
?>

<video
    id="player-<?= $channel_id ?>"
    class="video-js vjs-default-skin"
    controls
    preload="auto"
    width="100%"
    height="100%"
    poster=""
    webkit-playsinline="true"
    playsinline="true"
    crossorigin="anonymous"
    data-setup='{}'>
    <source src="<?= htmlspecialchars($stream_url) ?>" type="application/x-mpegURL" />
    <p class="vjs-no-js">
        To view this video you need to enable JavaScript or a browser that supports it.
    </p>
</video>

<script>
// This script will be executed by the main page's initializeVideoPlayer function
// No need for immediate initialization here
console.log('Player HTML loaded for channel: <?= $channel_id ?>');
</script>