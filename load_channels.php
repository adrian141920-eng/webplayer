<?php
session_start();
if (!isset($_SESSION['user_logged'])) {
    exit('No autorizado');
}

$username = $_SESSION['username'];
$password = $_SESSION['password'];
$server_url = rtrim($_SESSION['server_url'], '/');

function getAPI($action, $params = []) {
    global $username, $password, $server_url;
    $params['username'] = $username;
    $params['password'] = $password;
    $params['action'] = $action;
    $url = $server_url . "/player_api.php?" . http_build_query($params);
    $data = @file_get_contents($url);
    return $data ? json_decode($data, true) : [];
}

$category_id = $_GET['category_id'] ?? '';
$channels = [];

if ($category_id) {
    $channels = getAPI('get_live_streams', ['category_id' => $category_id]);
} else {
    $channels = getAPI('get_live_streams');
}

if (!is_array($channels)) {
    $channels = [];
}
?>

<?php if (empty($channels)): ?>
    <div class="loading">
        <i class="fas fa-tv"></i>
        No hay canales en esta categoría
    </div>
<?php else: ?>
    <?php foreach($channels as $ch): ?>
    <div class="ch-card" onclick="playChannel(<?= $ch['stream_id'] ?>)">
        <img src="<?= htmlspecialchars($ch['stream_icon']) ?>" 
             onerror="this.style.display='none';"
             alt="<?= htmlspecialchars($ch['name']) ?>">
        <div class="channel-name"><?= htmlspecialchars($ch['name']) ?></div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>