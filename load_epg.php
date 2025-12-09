<?php
session_start();
if (!isset($_SESSION['user_logged'])) exit('Unauthorized');

$username   = $_SESSION['username'];
$password   = $_SESSION['password'];
$server_url = rtrim($_SESSION['server_url'], '/');
$channel_id = $_GET['id'] ?? '';
if (!$channel_id) exit('Channel ID required');

function getAPI($action, $params = []) {
    global $username, $password, $server_url;
    $params['username'] = $username;
    $params['password'] = $password;
    $params['action'] = $action;
    $url = $server_url . "/player_api.php?" . http_build_query($params);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

// Detecta zona del usuario
$user_timezone = (isset($_COOKIE['user_timezone']) && in_array($_COOKIE['user_timezone'], timezone_identifiers_list()))
    ? $_COOKIE['user_timezone'] : 'UTC';
$userTzObj = new DateTimeZone($user_timezone);
$utcTzObj = new DateTimeZone('UTC');

// Petición EPG
$epg_data = getAPI('get_short_epg', ['stream_id' => $channel_id]);

// Nombre de canal
$channel_name = 'Canal';
$channels = getAPI('get_live_streams');
if (is_array($channels)) {
    foreach ($channels as $ch) {
        if ((string)($ch['stream_id'] ?? '') === (string)$channel_id) {
            $channel_name = $ch['name'] ?? 'Canal';
            break;
        }
    }
}
if ($channel_name === 'Canal' && !empty($epg_data['channel_name'])) {
    $channel_name = $epg_data['channel_name'];
}

header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// CONVERSIÓN AUTOMÁTICA DE ZONA HORARIA
$progs = $epg_data['epg_listings'] ?? [];
$offset_horas = 0;
$detection_info = '';

if (!empty($progs)) {
    // Hora actual del usuario
    $now_user = new DateTime('now', $userTzObj);
    $user_hour = (int)$now_user->format('H');
    $user_minute = (int)$now_user->format('i');
    $user_total_minutes = ($user_hour * 60) + $user_minute;
    
    // Obtener el primer programa para detectar zona horaria del EPG
    $first_prog = null;
    foreach ($progs as $prog) {
        if (!empty($prog['start'])) {
            $first_prog = $prog;
            break;
        }
    }
    
    if ($first_prog) {
        // Obtener hora original del EPG (asumiendo UTC)
        $epg_time_utc = new DateTime($first_prog['start'], $utcTzObj);
        
        // Convertir a hora del usuario
        $epg_time_user = clone $epg_time_utc;
        $epg_time_user->setTimezone($userTzObj);
        
        $epg_hour = (int)$epg_time_user->format('H');
        $epg_minute = (int)$epg_time_user->format('i');
        $epg_total_minutes = ($epg_hour * 60) + $epg_minute;
        
        // La diferencia entre UTC convertido a user timezone vs hora real del usuario
        // nos dice si el EPG está en una zona diferente
        $minute_diff = $user_total_minutes - $epg_total_minutes;
        
        // Convertir diferencia a horas (redondeando)
        $hour_diff = round($minute_diff / 60);
        
        // Pero necesitamos detectar la zona real del EPG
        // Vamos a asumir que el EPG viene en una zona específica y calcular
        
        // Probar zonas horarias comunes para servicios IPTV
        $common_timezones = [
            'UTC' => 'UTC',
            'Europe/London' => 'GMT',
            'Europe/Madrid' => 'CET',
            'America/New_York' => 'EST',
            'America/Chicago' => 'CST',
            'America/Denver' => 'MST',
            'America/Los_Angeles' => 'PST',
            'America/Mexico_City' => 'CST-MX',
            'America/Cancun' => 'EST-MX'
        ];
        
        $best_timezone = 'UTC';
        $best_diff = 999;
        
        foreach ($common_timezones as $tz_name => $tz_label) {
            try {
                $test_tz = new DateTimeZone($tz_name);
                
                // Convertir el tiempo del EPG desde esta zona a la zona del usuario
                $epg_in_test_tz = new DateTime($first_prog['start'], $test_tz);
                $epg_in_user_tz = clone $epg_in_test_tz;
                $epg_in_user_tz->setTimezone($userTzObj);
                
                $converted_hour = (int)$epg_in_user_tz->format('H');
                $converted_total_minutes = ($converted_hour * 60) + (int)$epg_in_user_tz->format('i');
                
                // Calcular diferencia con hora actual
                $test_diff = abs($user_total_minutes - $converted_total_minutes);
                
                // Si la diferencia es menor a 4 horas (240 minutos), podría ser la zona correcta
                if ($test_diff < $best_diff && $test_diff < 240) {
                    $best_diff = $test_diff;
                    $best_timezone = $tz_name;
                }
            } catch (Exception $e) {
                continue;
            }
        }
        
        // Ahora convertir todos los programas usando la mejor zona detectada
        $epg_timezone = new DateTimeZone($best_timezone);
        $offset_horas = 0; // No necesitamos offset manual, haremos conversión directa
        
        $detection_info = "EPG en {$common_timezones[$best_timezone]} → {$user_timezone}";
        
        // Guardar la zona detectada para usar en la conversión
        $detected_epg_timezone = $epg_timezone;
    } else {
        $detected_epg_timezone = $utcTzObj;
        $detection_info = 'EPG en UTC (predeterminado)';
    }
} else {
    $detected_epg_timezone = $utcTzObj;
    $detection_info = 'Sin datos de EPG';
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>EPG Canal <?= htmlspecialchars($channel_name) ?></title>
    <style>
    body {
        background:#181818;
        color:#fff;
        font-family:sans-serif;
        margin: 0;
        padding: 0;
    }
    
    .main-container {
        background: transparent;
        margin: 0;
        padding: 0;
        box-shadow: none;
        border-radius: 0;
    }
    
    .info-box {
        display: none;
    }
    
    .current-time {
        display: none;
    }
    
    .epg-list {
        max-height: 100%;
        overflow-y: auto;
        padding: 10px 20px 0 20px;
    }
    
    .epg-card {
        background: rgba(255,255,255,0.05);
        border-left: 3px solid #ffa502;
        padding: 12px;
        margin-bottom: 8px;
        border-radius: 6px;
        transition: all 0.3s ease;
        position: relative;
    }
    
    .epg-card:hover {
        background: rgba(255,255,255,0.1);
    }
    
    .epg-card.now {
        background: rgba(229, 9, 20, 0.2);
        border-left-color: #e50914;
        box-shadow: 0 0 15px rgba(229, 9, 20, 0.4);
    }
    
    .epg-time {
        font-size: 1rem;
        color: #ffa502;
        font-weight: bold;
        margin-bottom: 8px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .epg-card.now .epg-time {
        color: #e50914;
    }
    
    .epg-title {
        font-size: 0.9rem;
        color: #fff;
        font-weight: 600;
        margin-bottom: 5px;
        line-height: 1.3;
    }
    
    .epg-desc {
        font-size: 0.75rem;
        color: #ccc;
        line-height: 1.4;
        max-height: 60px;
        overflow: hidden;
    }
    
    .live-badge {
        background: #e50914;
        color: white;
        padding: 4px 12px;
        border-radius: 15px;
        font-size: 0.7rem;
        margin-left: auto;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        animation: pulse 2s ease-in-out infinite;
    }
    
    @keyframes pulse {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.7; }
    }
    
    .debug-info {
        display: none;
    }
    
    .no-epg {
        text-align: center;
        padding: 30px 20px;
        color: #666;
    }
    </style>
    <script>
    if (!document.cookie.match(/user_timezone=/)) {
        var tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
        document.cookie = "user_timezone=" + encodeURIComponent(tz) + ";path=/";
        location.reload();
    }
    </script>
</head>
<body>
<div class="main-container">
    <div class="epg-list">
<?php
if (!empty($progs)):
    $now_timestamp = time();
    $current_program_index = -1;
    $current_best_start = 0;
    
    // Preparar todos los programas con conversión automática de zona horaria
    $all_programs = [];
    foreach ($progs as $i => $prog) {
        if (empty($prog['start'])) continue;
        
        $title = isset($prog['title']) ? htmlspecialchars(base64_decode($prog['title'])) : 'Sin título';
        $desc = isset($prog['description']) && strlen($prog['description']) > 1
                ? htmlspecialchars(base64_decode($prog['description'])) : '';
        
        // CONVERSIÓN AUTOMÁTICA: EPG timezone → User timezone
        $epg_time = new DateTime($prog['start'], $detected_epg_timezone);
        $user_time = clone $epg_time;
        $user_time->setTimezone($userTzObj);
        
        $start_timestamp = $user_time->getTimestamp();
        $time_display = $user_time->format('H:i');
        
        $all_programs[] = [
            'index' => $i,
            'title' => $title,
            'desc' => $desc,
            'time_display' => $time_display,
            'start_timestamp' => $start_timestamp
        ];
    }
    
    // Encontrar programa actual (el que empezó más recientemente pero ya empezó)
    foreach ($all_programs as $prog) {
        if ($prog['start_timestamp'] <= $now_timestamp && $prog['start_timestamp'] > $current_best_start) {
            $current_program_index = $prog['index'];
            $current_best_start = $prog['start_timestamp'];
        }
    }
    
    // Mostrar programas
    foreach ($all_programs as $prog):
        $is_now = ($prog['index'] === $current_program_index);
        $time_diff = round(($now_timestamp - $prog['start_timestamp']) / 60);
?>
        <div class="epg-card<?= $is_now ? ' now' : '' ?>">
            <div class="epg-time">
                <?= $prog['time_display'] ?>
                <?php if($is_now): ?>
                    <span class="live-badge">🔴 EN VIVO</span>
                <?php endif; ?>
            </div>
            <div class="epg-title"><?= $prog['title'] ?></div>
            <?php if ($prog['desc']): ?>
                <div class="epg-desc"><?= $prog['desc'] ?></div>
            <?php endif; ?>
        </div>
<?php
    endforeach;
else: ?>
        <div class="no-epg">
            <div>No se encontró información de EPG para este canal.</div>
        </div>
<?php endif; ?>
    </div>
</div>
</body>
</html>