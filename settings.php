<?php
session_start();

if (!isset($_SESSION['user_logged'])) {
    header('Location: index.php');
    exit;
}

require_once 'config.php';

$username = $_SESSION['username'];
$password = $_SESSION['password'];
$server_url = $_SESSION['server_url'];
$server_name = $_SESSION['server_name'];

// Solo las funciones que NO están en header.php
function getAccountInfo($username, $password, $server_url) {
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
    
    $url = rtrim($server_url, '/') . '/player_api.php?username=' . urlencode($username) . '&password=' . urlencode($password);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
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

$account_info = getAccountInfo($username, $password, $server_url);

if ($_POST && isset($_POST['video_player'])) {
    updateConfig('video_player', $_POST['video_player']);
    $success_message = "Player changed successfully";
}

$config = loadConfig();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ajustes - IPTV Player</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
   <link href="rgvipcss/settings.css" rel="stylesheet">
</head>
<body>

<?php include 'header.php'; ?>

<!-- Contenido específico de settings dentro del main-content -->
<div class="settings-container">
    <div class="settings-header">
        <h1 class="settings-title">
            <i class="fas fa-cog"></i>
            Ajustes
        </h1>
        <p class="settings-subtitle">Administra tu cuenta y preferencias</p>
    </div>
    
    <!-- Warning específico para settings (diferente al del sidebar) -->
    <?php if (isset($expiration_warning) && $expiration_warning): ?>
    <div class="settings-expiration-warning <?= $expiration_warning['is_critical'] ? 'critical' : '' ?>">
        <div class="settings-warning-icon">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="settings-warning-title">
            <?= $expiration_warning['is_critical'] ? 'ACCOUNT EXPIRES SOON!' : 'Expiration Notice' ?>
        </div>
        <div class="settings-warning-message">
            Su suscripción expira en:
        </div>
        <div class="settings-warning-time">
            <?php if ($expiration_warning['days'] > 0): ?>
                <?= $expiration_warning['days'] ?> day<?= $expiration_warning['days'] > 1 ? 's' : '' ?>
                <?php if ($expiration_warning['hours'] > 0): ?>
                    and <?= $expiration_warning['hours'] ?> hour<?= $expiration_warning['hours'] > 1 ? 's' : '' ?>
                <?php endif; ?>
            <?php else: ?>
                <?= $expiration_warning['hours'] ?> hour<?= $expiration_warning['hours'] > 1 ? 's' : '' ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($account_info): ?>
    <div class="account-info">
        <h3 class="account-title">
            <i class="fas fa-user-circle"></i>
            Información De La Cuenta
        </h3>
        
        <div class="account-grid">
            <div class="account-item">
                <div class="account-item-icon">
                    <i class="fas fa-user"></i>
                </div>
                <div class="account-item-label">User</div>
                <div class="account-item-value"><?= htmlspecialchars($username) ?></div>
            </div>
            
            <div class="account-item">
                <div class="account-item-icon">
                    <i class="fas fa-server"></i>
                </div>
                <div class="account-item-label">Server</div>
                <div class="account-item-value"><?= htmlspecialchars($server_name) ?></div>
            </div>
            
            <?php if (isset($account_info['user_info']['status'])): ?>
            <div class="account-item">
                <div class="account-item-icon">
                    <i class="fas fa-circle"></i>
                </div>
                <div class="account-item-label">Status</div>
                <div class="account-item-value <?= $account_info['user_info']['status'] == 'Active' ? 'status-active' : 'status-expired' ?>">
                    <?= $account_info['user_info']['status'] == 'Active' ? 'Active' : 'Inactive' ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (isset($account_info['user_info']['exp_date']) && $account_info['user_info']['exp_date'] != 0): ?>
            <div class="account-item">
                <div class="account-item-icon">
                    <i class="fas fa-calendar-alt"></i>
                </div>
                <div class="account-item-label">Expires</div>
                <div class="account-item-value <?= (isset($expiration_warning) && $expiration_warning) ? 'status-warning' : '' ?>">
                    <?= date('m/d/Y', $account_info['user_info']['exp_date']) ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (isset($account_info['user_info']['max_connections'])): ?>
            <div class="account-item">
                <div class="account-item-icon">
                    <i class="fas fa-plug"></i>
                </div>
                <div class="account-item-label">Connections</div>
                <div class="account-item-value">
                    <?= $account_info['user_info']['active_cons'] ?? 0 ?> / <?= $account_info['user_info']['max_connections'] ?>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (isset($account_info['user_info']['created_at'])): ?>
            <div class="account-item">
                <div class="account-item-icon">
                    <i class="fas fa-calendar-plus"></i>
                </div>
                <div class="account-item-label">Created</div>
                <div class="account-item-value">
                    <?= date('m/d/Y', $account_info['user_info']['created_at']) ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (isset($success_message)): ?>
    <div class="success-message">
        <i class="fas fa-check-circle"></i>
        <?= $success_message ?>
        <script>
            setTimeout(() => {
                window.history.back();
            }, 1500);
        </script>
    </div>
    <?php endif; ?>
    
    <form method="POST" class="settings-form">
        <h3 class="section-title">
            <i class="fas fa-play-circle"></i>
            Video Player
        </h3>
        
        <div class="player-option <?= $config['video_player'] === 'videojs' ? 'selected' : '' ?>" 
             onclick="selectPlayer('videojs')">
            <div class="player-radio"></div>
            <div class="player-info">
                <div class="player-name">Video.js</div>
                <div class="player-description">Professional player (recommended for IPTV)</div>
            </div>
        </div>
        
        <div class="player-option <?= $config['video_player'] === 'plyr' ? 'selected' : '' ?>" 
             onclick="selectPlayer('plyr')">
            <div class="player-radio"></div>
            <div class="player-info">
                <div class="player-name">Plyr Style</div>
                <div class="player-description">Native player with modern style</div>
            </div>
        </div>
        
        <div class="player-option <?= $config['video_player'] === 'clapper' ? 'selected' : '' ?>" 
             onclick="selectPlayer('clapper')">
            <div class="player-radio"></div>
            <div class="player-info">
                <div class="player-name">Clapper Style</div>
                <div class="player-description">Native player with minimalist style</div>
            </div>
        </div>
        
        <div class="player-option <?= $config['video_player'] === 'jwplayer' ? 'selected' : '' ?>" 
             onclick="selectPlayer('jwplayer')">
            <div class="player-radio"></div>
            <div class="player-info">
                <div class="player-name">JW Player</div>
                <div class="player-description">Professional streaming player with advanced features</div>
            </div>
        </div>
        
        <input type="hidden" name="video_player" id="video_player" value="<?= $config['video_player'] ?>">
        
        <button type="submit" class="save-button">
            <i class="fas fa-save"></i>
            Save Settings
        </button>
    </form>
</div>

<script>
function selectPlayer(playerType) {
    document.querySelectorAll('.player-option').forEach(option => {
        option.classList.remove('selected');
    });
    
    event.currentTarget.classList.add('selected');
    
    document.getElementById('video_player').value = playerType;
}

document.addEventListener('DOMContentLoaded', function() {
    // Set active menu item for settings
    const settingsLink = document.querySelector('a[href="settings.php"]');
    if (settingsLink) {
        settingsLink.classList.add('active');
    }
});
</script>

</body>
</html>