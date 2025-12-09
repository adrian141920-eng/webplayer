<?php
session_start();
if (!isset($_SESSION['user_logged'])) {
    http_response_code(401);
    exit(json_encode(['success' => false, 'error' => 'Unauthorized']));
}

$username = $_SESSION['username'];
$password = $_SESSION['password'];
$server_url = rtrim($_SESSION['server_url'], '/');
$channel_id = $_GET['id'] ?? '';

if (!$channel_id) {
    http_response_code(400);
    exit(json_encode(['success' => false, 'error' => 'Channel ID required']));
}

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
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

// Obtener zona horaria del usuario
$user_timezone = $_GET['tz'] ?? 'UTC';
$valid_timezones = timezone_identifiers_list();
if (!in_array($user_timezone, $valid_timezones)) {
    $user_timezone = 'UTC';
}
$userTimezone = new DateTimeZone($user_timezone);
$utcTimezone = new DateTimeZone('UTC');

// Obtener EPG data
$epg_data = getAPI('get_short_epg', ['stream_id' => $channel_id]);

$response = ['success' => false, 'program' => null];

if (!empty($epg_data) && isset($epg_data['epg_listings']) && !empty($epg_data['epg_listings'])) {
    $programs = $epg_data['epg_listings'];
    
    // Ordenar por inicio
    usort($programs, function($a, $b) {
        return strtotime($a['start']) - strtotime($b['start']);
    });
    
    // Buscar programa actual
    $current_time = new DateTime('now', $userTimezone);
    $currentProgram = null;
    
    foreach ($programs as $program) {
        $start_datetime = new DateTime($program['start'], $utcTimezone);
        $start_datetime->setTimezone($userTimezone);
        
        // Calcular end time
        $end_datetime = null;
        if (!empty($program['stop'])) {
            $end_datetime = new DateTime($program['stop'], $utcTimezone);
            $end_datetime->setTimezone($userTimezone);
        }
        
        // Verificar si es el programa actual
        if ($start_datetime <= $current_time && ($end_datetime === null || $end_datetime >= $current_time)) {
            $currentProgram = $program;
            $currentProgram['start_datetime'] = $start_datetime;
            $currentProgram['end_datetime'] = $end_datetime;
            break;
        }
    }
    
    if ($currentProgram) {
        $title = isset($currentProgram['title']) ? base64_decode($currentProgram['title']) : 'Programa actual';
        $formatted_start = $currentProgram['start_datetime']->format('H:i');
        $formatted_end = $currentProgram['end_datetime'] ? $currentProgram['end_datetime']->format('H:i') : '';
        $time = $formatted_end ? "$formatted_start - $formatted_end" : $formatted_start;
        
        $response = [
            'success' => true,
            'program' => [
                'title' => $title,
                'time' => $time,
                'is_current' => true
            ]
        ];
    } else {
        // Si no hay programa actual, devolver el primer programa
        if (!empty($programs)) {
            $firstProgram = $programs[0];
            $title = isset($firstProgram['title']) ? base64_decode($firstProgram['title']) : 'Próximo programa';
            
            $start_datetime = new DateTime($firstProgram['start'], $utcTimezone);
            $start_datetime->setTimezone($userTimezone);
            $formatted_start = $start_datetime->format('H:i');
            
            $response = [
                'success' => true,
                'program' => [
                    'title' => $title,
                    'time' => $formatted_start,
                    'is_current' => false
                ]
            ];
        }
    }
}

header('Content-Type: application/json');
echo json_encode($response);
?>