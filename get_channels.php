<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('memory_limit', '512M'); // o 256M si prefieres
session_start();

if (!isset($_SESSION['user_logged'])) {
    http_response_code(401);
    exit('Unauthorized');
}

$username = $_SESSION['username'];
$password = $_SESSION['password'];
$server_url = rtrim($_SESSION['server_url'], '/');
$category_id = $_GET['category_id'] ?? '';

if (!$category_id) {
    http_response_code(400);
    exit('Category ID required');
}

function getAPI($action, $params = []) {
    global $username, $password, $server_url;
    $params['username'] = $username;
    $params['password'] = $password;
    $params['action'] = $action;
    $url = $server_url . "/player_api.php?" . http_build_query($params);
    $data = @file_get_contents($url);
    return $data ? json_decode($data, true) : [];
}

$channels = getAPI('get_live_streams', ['category_id' => $category_id]);

header('Content-Type: application/json');
echo json_encode($channels);
?>
