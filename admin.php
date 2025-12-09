<?php
session_start();

if (!isset($_SESSION['admin_logged'])) {
    if ($_POST && isset($_POST['admin_password'])) {
        if ($_POST['admin_password'] === 'admin123') {
            $_SESSION['admin_logged'] = true;
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $admin_error = 'Incorrect password';
        }
    }
    if (!isset($_SESSION['admin_logged'])) {
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Admin Login - IPTV Panel</title>
            <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
            <link href="rgvipcss/admin1.css" rel="stylesheet">
        </head>
        <body>
            <div class="login-container">
                <div class="login-icon"><i class="fas fa-shield-alt"></i></div>
                <h2>Administrador Web Player</h2>
                <p class="subtitle">Propiedad Grupo Full IPTV</p>
                <form method="POST">
                    <div class="input-group">
                        <i class="fas fa-lock"></i>
                        <input type="password" name="admin_password" placeholder="Administrator password" required>
                    </div>
                    <button type="submit" class="login-btn">
                        <i class="fas fa-sign-in-alt" style="margin-right: 8px;"></i>
                        Access Panel
                    </button>
                </form>
                <?php if (isset($admin_error)): ?>
                <div class="error">
                    <i class="fas fa-exclamation-triangle" style="margin-right: 8px;"></i>
                    <?= $admin_error ?>
                </div>
                <?php endif; ?>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}

$data_dir = 'data';
if (!is_dir($data_dir)) {
    mkdir($data_dir, 0755, true);
}

if (!file_exists('images.json')) {
    file_put_contents('images.json', json_encode(['images' => []], JSON_PRETTY_PRINT));
}

$last_check_file = $data_dir . '/last_auto_check.txt';

function autoCleanExpiredUsers($users_db, $servers, $last_check_file) {
    $now = time();
    $last_check = 0;
    
    if (file_exists($last_check_file)) {
        $last_check = intval(file_get_contents($last_check_file));
    }
    
    if (($now - $last_check) >= 86400) {
        try {
            $expired_count = 0;
            $stmt = $users_db->query('SELECT * FROM users');
            $all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($all_users as $user) {
                $server = null;
                foreach ($servers as $s) {
                    if ($s['id'] == $user['server_id']) {
                        $server = $s;
                        break;
                    }
                }
                
                if ($server) {
                    $verification = verifyUserWithXtreme($server['url'], $user['username'], $user['password']);
                    
                    if (!$verification['success'] || 
                        ($verification['expiry_date'] && new DateTime($verification['expiry_date']) < new DateTime())) {
                        
                        $delete_stmt = $users_db->prepare('DELETE FROM users WHERE id = ?');
                        $delete_stmt->execute([$user['id']]);
                        $expired_count++;
                    } else {
                        $update_stmt = $users_db->prepare('UPDATE users SET expiry_date = ?, status = ? WHERE id = ?');
                        $update_stmt->execute([
                            $verification['expiry_date'],
                            $verification['status'],
                            $user['id']
                        ]);
                    }
                }
            }
            
            file_put_contents($last_check_file, $now);
            
            $log_message = date('Y-m-d H:i:s') . " - Automatic cleanup completed. $expired_count users removed.\n";
            file_put_contents($data_dir . '/auto_cleanup.log', $log_message, FILE_APPEND);
            
            return $expired_count;
            
        } catch (Exception $e) {
            $error_log = date('Y-m-d H:i:s') . " - Automatic cleanup error: " . $e->getMessage() . "\n";
            file_put_contents($data_dir . '/auto_cleanup_errors.log', $error_log, FILE_APPEND);
            return false;
        }
    }
    
    return null;
}

$users_db_file = $data_dir . '/users.db';
try {
    $users_db = new PDO('sqlite:' . $users_db_file);
    $users_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $users_db->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code VARCHAR(6) UNIQUE NOT NULL,
        username TEXT NOT NULL,
        password TEXT NOT NULL,
        server_id INTEGER NOT NULL,
        server_name TEXT NOT NULL,
        expiry_date TEXT,
        status TEXT DEFAULT 'active',
        created DATETIME DEFAULT CURRENT_TIMESTAMP,
        active INTEGER DEFAULT 1
    )");
    
} catch (Exception $e) {
    die("Error creating user database: " . $e->getMessage());
}

$servers_file = $data_dir . '/servers.json';
$servers = [];
if (file_exists($servers_file)) {
    $content = file_get_contents($servers_file);
    $decoded = json_decode($content, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $servers = $decoded;
    }
}
if (empty($servers) || !is_array($servers)) {
    $servers = [];
    file_put_contents($servers_file, json_encode([], JSON_PRETTY_PRINT));
}

$auto_cleanup_result = autoCleanExpiredUsers($users_db, $servers, $last_check_file);

$images_content = file_get_contents('images.json');
$images_data = json_decode($images_content, true);

if (json_last_error() !== JSON_ERROR_NONE || !isset($images_data['images']) || !is_array($images_data['images'])) {
    $images = [];
    file_put_contents('images.json', json_encode(['images' => []], JSON_PRETTY_PRINT));
} else {
    $images = $images_data['images'];
}

$users = [];
try {
    $stmt = $users_db->query('SELECT * FROM users ORDER BY created DESC');
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $users = [];
}

$expiring_users = [];
$now = new DateTime();
foreach ($users as $user) {
    if ($user['expiry_date']) {
        try {
            $expiry = new DateTime($user['expiry_date']);
            $diff = $now->diff($expiry);
            
            if ($diff->days <= 3) {
                $expiring_users[] = $user;
            }
        } catch (Exception $e) {
            $expiring_users[] = $user;
        }
    }
}

function verifyUserWithXtreme($server_url, $username, $password) {
    $api_url = rtrim($server_url, '/') . '/player_api.php?' . http_build_query([
        'username' => $username,
        'password' => $password,
        'action' => 'get_account_info'
    ]);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_USERAGENT, 'XtremePlayer/1.0');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($response && $http_code == 200) {
        $data = json_decode($response, true);
        
        if (isset($data['user_info']) && $data['user_info']['auth'] == 1) {
            $expiry_timestamp = $data['user_info']['exp_date'] ?? null;
            $status = $data['user_info']['status'] ?? 'unknown';
            $is_trial = $data['user_info']['is_trial'] ?? '0';
            $max_connections = $data['user_info']['max_connections'] ?? '1';
            
            $expiry_date = null;
            if ($expiry_timestamp && $expiry_timestamp != '0') {
                $expiry_date = date('Y-m-d H:i:s', $expiry_timestamp);
            }
            
            return [
                'success' => true,
                'expiry_date' => $expiry_date,
                'status' => $status,
                'is_trial' => $is_trial,
                'max_connections' => $max_connections,
                'raw_data' => $data['user_info']
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Invalid user or unauthorized',
                'raw_data' => $data
            ];
        }
    } else {
        return [
            'success' => false,
            'message' => 'Connection error with server (HTTP: ' . $http_code . ')',
            'raw_data' => null
        ];
    }
}

// FUNCIÓN MEJORADA PARA PRUEBAS DE SERVIDOR
function testServerConnection($server_url, $timeout = 5) {
    $test_url = rtrim($server_url, '/') . '/player_api.php';
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $test_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_NOBODY => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_USERAGENT => 'IPTV-Panel/1.0',
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_FRESH_CONNECT => true,
        CURLOPT_FORBID_REUSE => true,
        CURLOPT_TCP_NODELAY => true,
        CURLOPT_BUFFERSIZE => 128,
    ]);
    
    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    curl_close($ch);
    
    // Mapear errores comunes a mensajes más amigables
    $error_messages = [
        CURLE_COULDNT_CONNECT => 'Cannot connect to server',
        CURLE_OPERATION_TIMEDOUT => 'Connection timeout',
        CURLE_COULDNT_RESOLVE_HOST => 'Cannot resolve hostname',
        CURLE_SSL_CONNECT_ERROR => 'SSL connection error',
        CURLE_RECV_ERROR => 'Server connection interrupted',
        CURLE_SEND_ERROR => 'Failed to send data to server',
        CURLE_GOT_NOTHING => 'No response from server',
        56 => 'Connection reset by server',
    ];
    
    if ($curl_error && $curl_errno) {
        $friendly_error = $error_messages[$curl_errno] ?? 'Connection error';
        
        // Para errores de "connection reset", intentar una segunda vez con configuración diferente
        if ($curl_errno == 56 || strpos($curl_error, 'reset') !== false) {
            // Segundo intento con configuración más conservadora
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $test_url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_NOBODY => true,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; IPTV-Panel)',
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_0,
                CURLOPT_TCP_KEEPALIVE => 1,
                CURLOPT_TCP_KEEPIDLE => 600,
                CURLOPT_TCP_KEEPINTVL => 60,
            ]);
            
            $result = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);
            
            if (!$curl_error && $http_code > 0) {
                $status = ($http_code >= 200 && $http_code < 500) ? 'online' : 'limited';
                return [
                    'success' => true,
                    'status' => $status,
                    'http_code' => $http_code,
                    'message' => $status === 'online' ? 'Server responding' : 'Limited response'
                ];
            }
        }
        
        return [
            'success' => false,
            'status' => 'offline',
            'message' => $friendly_error,
            'technical_error' => $curl_error
        ];
    }
    
    // Evaluar el código HTTP
    if ($http_code == 0) {
        return [
            'success' => false,
            'status' => 'offline',
            'message' => 'No response from server'
        ];
    }
    
    // Códigos HTTP válidos (incluso errores del servidor indican que está online)
    if ($http_code >= 200 && $http_code < 500) {
        $status = 'online';
        $message = 'Server responding normally';
    } elseif ($http_code >= 500) {
        $status = 'limited';
        $message = 'Server has issues but is reachable';
    } else {
        $status = 'offline';
        $message = 'Unexpected response';
    }
    
    return [
        'success' => true,
        'status' => $status,
        'http_code' => $http_code,
        'message' => $message
    ];
}

function generateUniqueCode($db) {
    $max_attempts = 1000;
    $attempts = 0;
    
    do {
        $code = str_pad(mt_rand(0, 999999), 6, '0', STR_PAD_LEFT);
        
        $stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE code = ?');
        $stmt->execute([$code]);
        $exists = $stmt->fetchColumn() > 0;
        
        $attempts++;
        if ($attempts >= $max_attempts) {
            throw new Exception('Could not generate unique code after ' . $max_attempts . ' attempts');
        }
        
    } while ($exists);
    
    return $code;
}

function saveServers($servers, $file) {
    $result = file_put_contents($file, json_encode($servers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $result !== false;
}

function saveImages($images) {
    $data = ['images' => $images];
    $result = file_put_contents('images.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $result !== false;
}

function isValidUrl($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['action'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Action not specified']);
        exit;
    }
    
    $action = $_POST['action'];
    
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, must-revalidate');
    
    try {
        switch ($action) {
            case 'add_user':
                $username = trim($_POST['username'] ?? '');
                $password = trim($_POST['password'] ?? '');
                $server_id = intval($_POST['server_id'] ?? 0);
                
                if (!$username || !$password || !$server_id) {
                    echo json_encode(['success' => false, 'message' => 'All fields are required']);
                    exit;
                }
                
                $stmt = $users_db->prepare('SELECT COUNT(*) FROM users WHERE username = ? AND password = ? AND server_id = ?');
                $stmt->execute([$username, $password, $server_id]);
                if ($stmt->fetchColumn() > 0) {
                    echo json_encode(['success' => false, 'message' => 'A user with these credentials already exists on this server']);
                    exit;
                }
                
                $server = null;
                foreach ($servers as $s) {
                    if ($s['id'] == $server_id) {
                        $server = $s;
                        break;
                    }
                }
                
                if (!$server) {
                    echo json_encode(['success' => false, 'message' => 'Server not found']);
                    exit;
                }
                
                $verification = verifyUserWithXtreme($server['url'], $username, $password);
                
                if (!$verification['success']) {
                    echo json_encode([
                        'success' => false, 
                        'message' => 'Error verifying user: ' . $verification['message'],
                        'debug' => $verification['raw_data']
                    ]);
                    exit;
                }
                
                $code = generateUniqueCode($users_db);
                
                $stmt = $users_db->prepare('INSERT INTO users (code, username, password, server_id, server_name, expiry_date, status) VALUES (?, ?, ?, ?, ?, ?, ?)');
                if ($stmt->execute([
                    $code, 
                    $username, 
                    $password, 
                    $server_id, 
                    $server['name'],
                    $verification['expiry_date'],
                    $verification['status']
                ])) {
                    $new_user = [
                        'id' => $users_db->lastInsertId(),
                        'code' => $code,
                        'username' => $username,
                        'password' => $password,
                        'server_id' => $server_id,
                        'server_name' => $server['name'],
                        'expiry_date' => $verification['expiry_date'],
                        'status' => $verification['status'],
                        'created' => date('Y-m-d H:i:s'),
                        'active' => 1,
                        'verification_info' => $verification
                    ];
                    echo json_encode([
                        'success' => true, 
                        'message' => 'User verified and created successfully', 
                        'user' => $new_user
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error creating user in database']);
                }
                break;
                
            case 'delete_user':
                $id = intval($_POST['id'] ?? 0);
                if ($id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
                    exit;
                }
                
                $stmt = $users_db->prepare('DELETE FROM users WHERE id = ?');
                if ($stmt->execute([$id])) {
                    echo json_encode(['success' => true, 'message' => 'User deleted successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error deleting user']);
                }
                break;
                
            case 'toggle_user':
                $id = intval($_POST['id'] ?? 0);
                if ($id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
                    exit;
                }
                
                $stmt = $users_db->prepare('UPDATE users SET active = NOT active WHERE id = ?');
                if ($stmt->execute([$id])) {
                    echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error updating status']);
                }
                break;

            case 'check_expired':
                $expired_count = 0;
                $stmt = $users_db->query('SELECT * FROM users');
                $all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($all_users as $user) {
                    $server = null;
                    foreach ($servers as $s) {
                        if ($s['id'] == $user['server_id']) {
                            $server = $s;
                            break;
                        }
                    }
                    
                    if ($server) {
                        $verification = verifyUserWithXtreme($server['url'], $user['username'], $user['password']);
                        
                        if (!$verification['success'] || 
                            ($verification['expiry_date'] && new DateTime($verification['expiry_date']) < new DateTime())) {
                            
                            $delete_stmt = $users_db->prepare('DELETE FROM users WHERE id = ?');
                            $delete_stmt->execute([$user['id']]);
                            $expired_count++;
                        } else {
                            $update_stmt = $users_db->prepare('UPDATE users SET expiry_date = ?, status = ? WHERE id = ?');
                            $update_stmt->execute([
                                $verification['expiry_date'],
                                $verification['status'],
                                $user['id']
                            ]);
                        }
                    }
                }
                
                echo json_encode([
                    'success' => true, 
                    'message' => "Verification completed. $expired_count expired users removed.",
                    'expired_count' => $expired_count
                ]);
                break;
                
            case 'save_images':
                if (!isset($_POST['images']) || !is_array($_POST['images'])) {
                    echo json_encode(['success' => false, 'message' => 'Invalid image data']);
                    exit;
                }
                
                if (saveImages($_POST['images'])) {
                    echo json_encode(['success' => true, 'message' => 'Images saved successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error saving images']);
                }
                break;
                
            case 'add_image':
                $name = trim($_POST['name'] ?? '');
                $url = trim($_POST['url'] ?? '');
                
                if (!$name || !$url) {
                    echo json_encode(['success' => false, 'message' => 'Name and URL are required']);
                    exit;
                }
                
                if (!filter_var($url, FILTER_VALIDATE_URL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid URL']);
                    exit;
                }
                
                $current_images = json_decode(file_get_contents('images.json'), true)['images'] ?? [];
                
                $current_images[] = ['name' => $name, 'url' => $url];
                
                if (saveImages($current_images)) {
                    echo json_encode(['success' => true, 'message' => 'Image added successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error adding image']);
                }
                break;
                
            case 'delete_image':
                $index = intval($_POST['index'] ?? -1);
                
                if ($index < 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid index']);
                    exit;
                }
                
                $current_images = json_decode(file_get_contents('images.json'), true)['images'] ?? [];
                
                if (!isset($current_images[$index])) {
                    echo json_encode(['success' => false, 'message' => 'Image not found']);
                    exit;
                }
                
                array_splice($current_images, $index, 1);
                
                if (saveImages($current_images)) {
                    echo json_encode(['success' => true, 'message' => 'Image deleted successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error deleting image']);
                }
                break;
                
            case 'edit_image':
                $index = intval($_POST['index'] ?? -1);
                $name = trim($_POST['name'] ?? '');
                $url = trim($_POST['url'] ?? '');
                
                if ($index < 0 || !$name || !$url) {
                    echo json_encode(['success' => false, 'message' => 'Invalid data']);
                    exit;
                }
                
                if (!filter_var($url, FILTER_VALIDATE_URL)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid URL']);
                    exit;
                }
                
                $current_images = json_decode(file_get_contents('images.json'), true)['images'] ?? [];
                
                if (!isset($current_images[$index])) {
                    echo json_encode(['success' => false, 'message' => 'Image not found']);
                    exit;
                }
                
                $current_images[$index] = ['name' => $name, 'url' => $url];
                
                if (saveImages($current_images)) {
                    echo json_encode(['success' => true, 'message' => 'Image updated successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error updating image']);
                }
                break;

            case 'add_server':
                $name = trim($_POST['name'] ?? '');
                $dns = trim($_POST['dns'] ?? '');
                
                if (!$name || !$dns) {
                    echo json_encode(['success' => false, 'message' => 'Name and DNS are required']);
                    exit;
                }
                
                if (!isValidUrl($dns)) {
                    echo json_encode(['success' => false, 'message' => 'Invalid URL']);
                    exit;
                }
                
                foreach ($servers as $server) {
                    if ($server['url'] === rtrim($dns, '/')) {
                        echo json_encode(['success' => false, 'message' => 'A server with this URL already exists']);
                        exit;
                    }
                }
                
                $new_id = count($servers) > 0 ? max(array_column($servers, 'id')) + 1 : 1;
                $new_server = [ 
                    'id' => $new_id, 
                    'name' => $name, 
                    'url' => rtrim($dns, '/') 
                ];
                
                $servers[] = $new_server;
                
                if (saveServers($servers, $servers_file)) {
                    echo json_encode([
                        'success' => true, 
                        'message' => 'Server added successfully', 
                        'server' => $new_server
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error saving server']);
                }
                break;
                
            case 'delete_server':
                $id = intval($_POST['id'] ?? 0);
                
                if ($id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid server ID']);
                    exit;
                }
                
                $stmt = $users_db->prepare('SELECT COUNT(*) FROM users WHERE server_id = ?');
                $stmt->execute([$id]);
                $user_count = $stmt->fetchColumn();
                
                if ($user_count > 0) {
                    echo json_encode([
                        'success' => false, 
                        'message' => "Cannot delete: there are $user_count user(s) using this server"
                    ]);
                    exit;
                }
                
                $original_count = count($servers);
                $servers = array_filter($servers, function($s) use ($id) { 
                    return $s['id'] != $id; 
                });
                $servers = array_values($servers);
                
                if (count($servers) < $original_count && saveServers($servers, $servers_file)) {
                    echo json_encode(['success' => true, 'message' => 'Server deleted successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error deleting server']);
                }
                break;
                
            case 'test_server':
                $id = intval($_POST['id'] ?? 0);
                
                if ($id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid server ID']);
                    exit;
                }
                
                $server = null;
                foreach ($servers as $s) {
                    if ($s['id'] == $id) { 
                        $server = $s; 
                        break; 
                    }
                }
                
                if (!$server) {
                    echo json_encode(['success' => false, 'message' => 'Server not found']);
                    exit;
                }
                
                $test_result = testServerConnection($server['url']);
                
                echo json_encode([
                    'success' => $test_result['success'],
                    'status' => $test_result['status'],
                    'http_code' => $test_result['http_code'] ?? 0,
                    'message' => $test_result['message'],
                    'server_name' => $server['name']
                ]);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $action]);
                break;
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false, 
            'message' => 'Server error: ' . $e->getMessage()
        ]);
    }
    
    exit;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Admin Panel - Gestión DNS </title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.4.1/css/bootstrap.min.css">
    <link href="rgvipcss/admin2.css" rel="stylesheet">
</head>
<body>
    <div class="header">
        <h1><i class="fas fa-server"></i> DNS Administration Panel</h1>
        <div class="stats">
            <span><i class="fas fa-database"></i> Servidores: <?= count($servers) ?></span>
            <span><i class="fas fa-users"></i> Usuarios: <?= count($users) ?></span>
            <span><i class="fas fa-exclamation-triangle" style="color: #ffc107;"></i> Vencimiento: <?= count($expiring_users) ?></span>
            <?php if ($auto_cleanup_result !== null): ?>
                <span><i class="fas fa-broom" style="color: #46D369;"></i> Limpieza automática: 
                    <?php if ($auto_cleanup_result !== false): ?>
                        <?= $auto_cleanup_result ?> Eliminado hoy
                    <?php else: ?>
                        Error de limpieza
                    <?php endif; ?>
                </span>
            <?php endif; ?>
            <span><i class="fas fa-circle" style="color: #46D369;"></i> En línea: <span id="onlineCount">-</span></span>
            <span><i class="fas fa-circle" style="color: #E50914;"></i> Desconectado: <span id="offlineCount">-</span></span>
            <!--<span><i class="fas fa-bullhorn"></i> Ads: <?= count($images) ?></span>-->
            <a href="?logout=1" class="logout">
                <i class="fas fa-sign-out-alt"></i> Cerrar sesión
            </a>
        </div>
    </div>
    <div class="container">
        <div id="message"></div>
        <div class="tabs-container">
            <div class="tabs">
                <button class="tab-btn active" onclick="showTab('servers')"><i class="fas fa-server"></i> <span class="tab-text">Servidores DNS</span></button>
                <button class="tab-btn" onclick="showTab('users')"><i class="fas fa-users"></i> <span class="tab-text">Users</span></button>
                <button class="tab-btn" onclick="showTab('expiring')"><i class="fas fa-exclamation-triangle"></i> <span class="tab-text">Vencimiento (<?= count($expiring_users) ?>)</span></button>
                <!--<button class="tab-btn" onclick="showTab('ads')"><i class="fas fa-bullhorn"></i> <span class="tab-text">Ads</span></button>-->
                <button class="tab-btn" onclick="window.open('rgvip_intro.php', '_blank', 'width=600,height=700')">
    <i class="fas fa-film"></i>
    <span class="tab-text">Intro Video</span>
</button>
            </div>
        </div>
        
        <div id="tab-servers" class="tab-content active">
            <div class="add-form">
                <h3><i class="fas fa-plus-circle"></i> Agregar Nuevo DNS</h3>
                <form id="addServerForm">
                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-tag"></i> Nombre</label>
                            <input type="text" id="serverName" placeholder="Premium IPTV" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-link"></i> DNS</label>
                            <input type="text" id="serverDns" placeholder="http://server.com:8080" required>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Agregar
                        </button>
                    </div>
                </form>
            </div>
            <div class="servers-table">
                <div class="table-header servers">
                    <span><i class="fas fa-circle"></i> Estado</span>
                    <span><i class="fas fa-tag"></i> Nombre</span>
                    <span><i class="fas fa-link"></i> DNS</span>
                    <span><i class="fas fa-cogs"></i> Actions</span>
                </div>
                <div id="serversList">
                    <?php if (empty($servers)): ?>
                    <div class="empty-state">
                        <i class="fas fa-server"></i>
                        <h3>No hay servidores configurados</h3>
                        <p>Añade tu primer servidor usando el formulario de arriba</p>
                    </div>
                    <?php else: ?>
                        <?php foreach ($servers as $server): ?>
                        <div class="server-row" data-id="<?= $server['id'] ?>">
                            <div class="status" id="status-<?= $server['id'] ?>"></div>
                            <div><?= htmlspecialchars($server['name']) ?></div>
                            <div class="server-url"><?= htmlspecialchars($server['url']) ?></div>
                            <div class="actions">
                                <button class="btn btn-info" onclick="testServer(<?= $server['id'] ?>)">
                                    <i class="fas fa-wifi"></i> Test
                                </button>
                                <button class="btn btn-danger" onclick="deleteServer(<?= $server['id'] ?>)">
                                    <i class="fas fa-trash"></i> Eliminar
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div id="tab-users" class="tab-content">
            <div class="add-form">
                <h3><i class="fas fa-user-plus"></i> Agregar Nuevo Usuario</h3>
                <form id="addUserForm">
                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Nombre De Usuario</label>
                            <input type="text" id="userName" placeholder="user123" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-key"></i> Contraseña</label>
                            <input type="text" id="userPassword" placeholder="password123" required>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-server"></i> Servidores DNS</label>
                            <select id="userServer" required>
                                <option value="">Seleccionar Servidor...</option>
                                <?php foreach ($servers as $server): ?>
                                <option value="<?= $server['id'] ?>"><?= htmlspecialchars($server['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Crear Usuario
                        </button>
                    </div>
                </form>
            </div>
            
            <div class="servers-table">
                <div class="table-header users">
                    <span><i class="fas fa-hashtag"></i> Codigo</span>
                    <span><i class="fas fa-user"></i> Nombre De Usuario</span>
                    <span><i class="fas fa-key"></i> Contraseña</span>
                    <span><i class="fas fa-server"></i> Servidor</span>
                    <span><i class="fas fa-calendar-alt"></i> Vencimiento</span>
                    <span><i class="fas fa-toggle-on"></i> Estado</span>
                    <span><i class="fas fa-cogs"></i> Actions</span>
                </div>
                <div id="usersList">
                    <?php if (empty($users)): ?>
                    <div class="empty-state">
                        <i class="fas fa-users"></i>
                        <h3>No hay usuarios configurados</h3>
                        <p>Añade tu primer usuario utilizando el formulario de arriba</p>
                    </div>
                    <?php else: ?>
                        <?php foreach ($users as $user): ?>
                        <div class="user-row" data-id="<?= $user['id'] ?>">
                            <div class="code-display"><?= $user['code'] ?></div>
                            <div><?= htmlspecialchars($user['username']) ?></div>
                            <div><?= htmlspecialchars($user['password']) ?></div>
                            <div><?= htmlspecialchars($user['server_name']) ?></div>
                            <div>
                                <?php if ($user['expiry_date']): ?>
                                    <?php 
                                    $expiry = new DateTime($user['expiry_date']);
                                    $now = new DateTime();
                                    $is_expired = $expiry < $now;
                                    $days_left = $now->diff($expiry)->days;
                                    ?>
                                    <span style="color: <?= $is_expired ? '#E50914' : ($days_left <= 7 ? '#ffc107' : '#46D369') ?>; font-size: 12px; font-weight: bold;">
                                        <?= $expiry->format('m/d/Y') ?><br>
                                        <small>(<?= $is_expired ? 'Expired' : $days_left . ' days' ?>)</small>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #666; font-size: 12px;">No data</span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <span class="status-badge <?= $user['active'] ? 'active' : 'inactive' ?>">
                                    <?= $user['active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </div>
                            <div class="actions">
                                <button class="btn btn-<?= $user['active'] ? 'warning' : 'success' ?>" onclick="toggleUser(<?= $user['id'] ?>)">
                                    <i class="fas fa-toggle-<?= $user['active'] ? 'off' : 'on' ?>"></i>
                                    <?= $user['active'] ? 'Deactivate' : 'Activate' ?>
                                </button>
                                <button class="btn btn-danger" onclick="deleteUser(<?= $user['id'] ?>)">
                                    <i class="fas fa-trash"></i> Eliminar
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div id="tab-expiring" class="tab-content">
            <div class="add-form">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h3><i class="fas fa-exclamation-triangle"></i> Usuarios que expiran (3 días o menos)</h3>
                    <div>
                        <button class="btn btn-info" onclick="viewCleanupLogs()" style="margin-right: 10px;">
                            <i class="fas fa-history"></i> Ver registros
                        </button>
                        <button class="btn btn-warning" onclick="checkExpiredUsers()">
                            <i class="fas fa-sync-alt"></i> Comprobar Manualmente
                        </button>
                    </div>
                </div>
                <div style="background: #333; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
                    <h5 style="color: #46D369; margin-bottom: 10px;">
                        <i class="fas fa-robot"></i> Sistema de limpieza automática activo
                    </h5>
                    <p style="color: #B3B3B3; margin-bottom: 10px;">
                        <i class="fas fa-clock"></i> <strong>Frecuencia:</strong> Cada 24 horas automáticamente<br>
                        <i class="fas fa-trash-alt"></i> <strong>Acción:</strong> Elimina usuarios caducados o inexistentes<br>
                        <i class="fas fa-key"></i> <strong>Resultado:</strong> Códigos liberados automáticamente
                    </p>
                    <?php
                    $last_check_timestamp = 0;
                    if (file_exists($last_check_file)) {
                        $last_check_timestamp = intval(file_get_contents($last_check_file));
                    }
                    $next_check = $last_check_timestamp + 86400;
                    $hours_remaining = max(0, ceil(($next_check - time()) / 3600));
                    ?>
                    <p style="color: #ffc107; font-size: 14px;">
                        <i class="fas fa-timer"></i> 
                        <?php if ($last_check_timestamp > 0): ?>
                            Última limpieza: <?= date('m/d/Y H:i', $last_check_timestamp) ?> | 
                            Siguiente en: <?= $hours_remaining ?> horas
                        <?php else: ?>
                            La primera limpieza se realizará en las próximas 24 horas.
                        <?php endif; ?>
                    </p>
                </div>
                <p style="color: #B3B3B3; margin-bottom: 20px;">
                    <i class="fas fa-info-circle"></i> Los usuarios que se muestran aquí caducan en los próximos 3 días.. 
                    El sistema los verificará y los eliminará automáticamente si están vencidos..
                </p>
            </div>
            
            <div class="servers-table">
                <div class="table-header users">
                    <span><i class="fas fa-hashtag"></i> Código</span>
                    <span><i class="fas fa-user"></i> Nombre De Usuario</span>
                    <span><i class="fas fa-key"></i> Contraseña</span>
                    <span><i class="fas fa-server"></i> Servidor</span>
                    <span><i class="fas fa-calendar-alt"></i> Vencimiento</span>
                    <span><i class="fas fa-toggle-on"></i> Estado</span>
                    <span><i class="fas fa-cogs"></i> Actions</span>
                </div>
                <div id="expiringUsersList">
                    <?php if (empty($expiring_users)): ?>
                    <div class="empty-state">
                        <i class="fas fa-check-circle" style="color: #46D369;"></i>
                        <h3>Excelente!</h3>
                        <p>No hay usuarios que expiren pronto</p>
                    </div>
                    <?php else: ?>
                        <?php foreach ($expiring_users as $user): ?>
                        <div class="user-row" data-id="<?= $user['id'] ?>">
                            <div class="code-display"><?= $user['code'] ?></div>
                            <div><?= htmlspecialchars($user['username']) ?></div>
                            <div><?= htmlspecialchars($user['password']) ?></div>
                            <div><?= htmlspecialchars($user['server_name']) ?></div>
                            <div>
                                <?php if ($user['expiry_date']): ?>
                                    <?php 
                                    $expiry = new DateTime($user['expiry_date']);
                                    $now = new DateTime();
                                    $is_expired = $expiry < $now;
                                    $diff = $now->diff($expiry);
                                    $days_left = $diff->days;
                                    
                                    if ($is_expired) {
                                        $color = '#E50914';
                                        $text = 'EXPIRED';
                                    } elseif ($days_left == 0) {
                                        $color = '#E50914';
                                        $text = 'Today';
                                    } elseif ($days_left <= 1) {
                                        $color = '#E50914';
                                        $text = $days_left . ' day';
                                    } elseif ($days_left <= 3) {
                                        $color = '#ffc107';
                                        $text = $days_left . ' days';
                                    } else {
                                        $color = '#46D369';
                                        $text = $days_left . ' days';
                                    }
                                    ?>
                                    <span style="color: <?= $color ?>; font-size: 12px; font-weight: bold;">
                                        <?= $expiry->format('m/d/Y') ?><br>
                                        <small>(<?= $text ?>)</small>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #E50914; font-size: 12px; font-weight: bold;">No data</span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <span class="status-badge <?= $user['active'] ? 'active' : 'inactive' ?>">
                                    <?= $user['active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </div>
                            <div class="actions">
                                <button class="btn btn-<?= $user['active'] ? 'warning' : 'success' ?>" onclick="toggleUser(<?= $user['id'] ?>)">
                                    <i class="fas fa-toggle-<?= $user['active'] ? 'off' : 'on' ?>"></i>
                                    <?= $user['active'] ? 'Deactivate' : 'Activate' ?>
                                </button>
                                <button class="btn btn-danger" onclick="deleteUser(<?= $user['id'] ?>)">
                                    <i class="fas fa-trash"></i> Eliminar
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div id="tab-ads" class="tab-content">
            <div class="add-form">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h3><i class="fas fa-plus-circle"></i> Gestión De Anuncios</h3>
                    <button class="btn btn-primary" data-toggle="modal" data-target="#addImageModal">
                        <i class="fas fa-plus"></i> Añadir Imagen
                    </button>
                </div>
            </div>

            <div class="image-grid">
                <?php if (empty($images)): ?>
                <div class="empty-state">
                    <i class="fas fa-images"></i>
                    <h3>No hay imágenes configuradas</h3>
                    <p>Añade tu primera imagen usando el botón de arriba</p>
                </div>
                <?php else: ?>
                    <?php foreach ($images as $index => $image): ?>
                    <div class="image-card">
                        <img src="<?= htmlspecialchars($image['url']) ?>" class="image-preview" alt="<?= htmlspecialchars($image['name']) ?>" onerror="this.style.display='none'">
                        <div class="image-info">
                            <h5><?= htmlspecialchars($image['name']) ?></h5>
                            <small class="text-muted"><?= htmlspecialchars($image['url']) ?></small>
                            <div class="image-actions">
                                <button class="btn btn-info btn-sm" 
                                        onclick="editImage(<?= $index ?>, '<?= htmlspecialchars(addslashes($image['name'])) ?>', '<?= htmlspecialchars(addslashes($image['url'])) ?>')">
                                    <i class="fas fa-edit"></i> Editar
                                </button>
                                <button class="btn btn-danger btn-sm" onclick="deleteImage(<?= $index ?>)">
                                    <i class="fas fa-trash"></i> Eliminar
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="modal fade" id="addImageModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Agregar nueva imagen</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <form id="imageForm">
                        <div class="form-group">
                            <label>Nombre:</label>
                            <input type="text" class="form-control" id="imageName" required>
                        </div>
                        <div class="form-group">
                            <label>URL:</label>
                            <input type="url" class="form-control" id="imageUrl" required>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" onclick="addImage()">Agregar</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="editImageModal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Editar Imagen</h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body">
                    <form id="editImageForm">
                        <input type="hidden" id="editImageIndex">
                        <div class="form-group">
                            <label>Nombre:</label>
                            <input type="text" class="form-control" id="editImageName" required>
                        </div>
                        <div class="form-group">
                            <label>URL:</label>
                            <input type="url" class="form-control" id="editImageUrl" required>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" onclick="updateImage()">Save</button>
                </div>
            </div>
        </div>
    </div>
<!-- Modal -->
<div class="modal fade" id="introModal" tabindex="-1" role="dialog" aria-labelledby="introModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header bg-dark text-white">
        <h5 class="modal-title"><i class="fas fa-film"></i> Subir Video Intro</h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Cerrar">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body">
        <div class="text-center">Cargando contenido...</div>
      </div>
    </div>
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
  $('#introModal').on('show.bs.modal', function () {
    const modalBody = this.querySelector('.modal-body');
    modalBody.innerHTML = '<div class="text-center p-3">Cargando contenido...</div>';

    fetch('rgvip_intro.php')
      .then(res => res.text())
      .then(html => {
        modalBody.innerHTML = html;
      })
      .catch(() => {
        modalBody.innerHTML = '<div class="alert alert-danger">Error al cargar el contenido.</div>';
      });
  });
});
</script>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.4.1/jquery.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/1.16.0/umd/popper.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.4.1/js/bootstrap.min.js"></script>

    <script>
        let processingRequest = false;
        
        // FUNCIÓN MEJORADA PARA MOSTRAR MENSAJES (FILTRA ERRORES DE CONNECTION RESET)
        function showMessage(text, type) {
            // Filtrar mensajes de "connection reset" ya que son comunes y no críticos
            if (type === 'error' && (
                text.toLowerCase().includes('reset') || 
                text.toLowerCase().includes('recv failure') || 
                text.toLowerCase().includes('connection interrupted')
            )) {
                console.log('Connection error suppressed:', text);
                return; // No mostrar estos errores
            }
            
            const messageEl = document.getElementById('message');
            const icon = type === 'success' ? 'fas fa-check-circle' : 'fas fa-exclamation-triangle';
            
            messageEl.innerHTML = `
                <div class="message ${type}">
                    <i class="${icon}"></i>
                    ${text}
                </div>
            `;
            
            setTimeout(() => {
                const msg = messageEl.querySelector('.message');
                if (msg) {
                    msg.style.opacity = '0';
                    setTimeout(() => {
                        messageEl.innerHTML = '';
                    }, 300);
                }
            }, 5000);
        }

        function makeRequest(data, successCallback, errorCallback) {
            if (processingRequest) {
                console.log('Request in progress, ignoring new request');
                return;
            }
            
            processingRequest = true;
            
            $.ajax({
                type: 'POST',
                url: window.location.href,
                data: data,
                dataType: 'json',
                timeout: 15000,
                success: function(response) {
                    processingRequest = false;
                    if (response.success) {
                        if (successCallback) successCallback(response);
                    } else {
                        showMessage(response.message || 'Unknown error', 'error');
                        if (errorCallback) errorCallback(response);
                    }
                },
                error: function(xhr, status, error) {
                    processingRequest = false;
                    let errorMsg = 'Connection error';
                    if (status === 'timeout') {
                        errorMsg = 'Timeout: Server took too long to respond';
                    } else if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMsg = xhr.responseJSON.message;
                    }
                    showMessage(errorMsg, 'error');
                    if (errorCallback) errorCallback({ message: errorMsg });
                }
            });
        }

        // FUNCIÓN MEJORADA PARA PROBAR SERVIDORES
        function testServer(id) {
            const statusEl = $('#status-' + id);
            const serverRow = $(`.server-row[data-id="${id}"]`);
            const testBtn = serverRow.find('button:contains("Test")');
            
            // Cambiar estado visual
            statusEl.removeClass('online offline limited').addClass('testing');
            testBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Testing...');
            
            makeRequest({
                action: 'test_server',
                id: id
            }, 
            function(response) {
                statusEl.removeClass('testing').addClass(response.status);
                
                // Solo mostrar mensaje para errores críticos (no connection reset)
                if (!response.success && response.status === 'offline') {
                    if (response.message && 
                        !response.message.toLowerCase().includes('reset') &&
                        !response.message.toLowerCase().includes('interrupted')) {
                        showMessage(`${response.server_name}: ${response.message}`, 'error');
                    }
                }
                
                updateOnlineCount();
                testBtn.prop('disabled', false).html('<i class="fas fa-wifi"></i> Test');
            },
            function(error) {
                statusEl.removeClass('testing').addClass('offline');
                
                // Solo mostrar errores que no sean "connection reset"
                if (error.message && 
                    !error.message.toLowerCase().includes('reset') &&
                    !error.message.toLowerCase().includes('interrupted')) {
                    showMessage(`Server test failed: ${error.message}`, 'error');
                }
                
                updateOnlineCount();
                testBtn.prop('disabled', false).html('<i class="fas fa-wifi"></i> Test');
            });
        }

        // FUNCIÓN MEJORADA PARA PRUEBAS AUTOMÁTICAS AL CARGAR
        function autoTestServers() {
            $('.server-row').each(function(index) {
                const id = $(this).data('id');
                if (id) {
                    // Escalonar las pruebas para evitar sobrecargar
                    setTimeout(() => {
                        testServer(id);
                    }, index * 1000 + Math.random() * 500);
                }
            });
        }

        // FUNCIÓN MEJORADA PARA CONTAR SERVIDORES ONLINE/OFFLINE
        function updateOnlineCount() {
            let online = 0;
            let offline = 0;
            let limited = 0;
            
            $('.status').each(function() {
                if ($(this).hasClass('online')) {
                    online++;
                } else if ($(this).hasClass('offline')) {
                    offline++;
                } else if ($(this).hasClass('limited')) {
                    limited++;
                }
            });
            
            $('#onlineCount').text(online + (limited > 0 ? ` (+${limited} limited)` : ''));
            $('#offlineCount').text(offline);
        }

        $('#addUserForm').on('submit', function(e) {
            e.preventDefault();
            
            const username = $('#userName').val().trim();
            const password = $('#userPassword').val().trim();
            const server_id = $('#userServer').val();
            
            if (!username || !password || !server_id) {
                showMessage('All fields are required', 'error');
                return;
            }
            
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying with server...';
            submitBtn.disabled = true;
            
            makeRequest({
                action: 'add_user',
                username: username,
                password: password,
                server_id: server_id
            }, 
            function(response) {
                let message = response.message;
                if (response.user && response.user.expiry_date) {
                    message += '<br><strong>Expiration:</strong> ' + response.user.expiry_date;
                }
                if (response.user && response.user.verification_info) {
                    const info = response.user.verification_info;
                    message += '<br><strong>Server status:</strong> ' + info.status;
                    if (info.is_trial === '1') {
                        message += ' (Trial Account)';
                    }
                    if (info.max_connections) {
                        message += '<br><strong>Max connections:</strong> ' + info.max_connections;
                    }
                }
                message += '<br><strong>Generated code:</strong> ' + response.user.code;
                showMessage(message, 'success');
                
                $('#userName').val('');
                $('#userPassword').val('');
                $('#userServer').val('');
                
                setTimeout(() => location.reload(), 3000);
            },
            function() {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
            
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }, 15000);
        });

        function deleteUser(id) {
            if (!confirm('Are you sure you want to delete this user? This action cannot be undone.')) {
                return;
            }
            
            makeRequest({
                action: 'delete_user',
                id: id
            }, 
            function(response) {
                showMessage(response.message, 'success');
                setTimeout(() => location.reload(), 1000);
            });
        }

        function toggleUser(id) {
            makeRequest({
                action: 'toggle_user',
                id: id
            }, 
            function(response) {
                showMessage(response.message, 'success');
                setTimeout(() => location.reload(), 1000);
            });
        }

        function viewCleanupLogs() {
            const modal = document.createElement('div');
            modal.style.cssText = `
                position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
                background: rgba(0,0,0,0.8); z-index: 1000; display: flex; 
                align-items: center; justify-content: center;
            `;
            
            modal.innerHTML = `
                <div style="background: #222; padding: 30px; border-radius: 8px; max-width: 80%; max-height: 80%; overflow-y: auto; color: white;">
                    <h3 style="margin-bottom: 20px;"><i class="fas fa-file-alt"></i> Automatic Cleanup Logs</h3>
                    <div id="logContent" style="background: #000; padding: 15px; border-radius: 4px; font-family: monospace; white-space: pre-wrap; margin-bottom: 20px;">
                        Loading logs...
                    </div>
                    <button onclick="this.parentElement.parentElement.remove()" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Close
                    </button>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            $.get('data/auto_cleanup.log')
                .done(function(data) {
                    document.getElementById('logContent').textContent = data || 'No logs available yet.';
                })
                .fail(function() {
                    document.getElementById('logContent').textContent = 'Could not load logs.';
                });
        }

        function checkExpiredUsers() {
            if (!confirm('Are you sure you want to check and remove expired users? This action will automatically delete users that no longer exist on the server or are expired.')) {
                return;
            }
            
            showMessage('Checking users... This may take a few moments.', 'success');
            
            makeRequest({
                action: 'check_expired'
            }, 
            function(response) {
                showMessage(response.message, 'success');
                setTimeout(() => location.reload(), 3000);
            });
        }

        function addImage() {
            const name = $('#imageName').val().trim();
            const url = $('#imageUrl').val().trim();
            
            if (!name || !url) {
                showMessage('Complete all fields', 'error');
                return;
            }

            makeRequest({
                action: 'add_image',
                name: name,
                url: url
            }, 
            function(response) {
                $('#addImageModal').modal('hide');
                showMessage(response.message, 'success');
                setTimeout(() => location.reload(), 1000);
            });
        }

        function editImage(index, name, url) {
            $('#editImageIndex').val(index);
            $('#editImageName').val(name);
            $('#editImageUrl').val(url);
            $('#editImageModal').modal('show');
        }

        function updateImage() {
            const index = parseInt($('#editImageIndex').val());
            const name = $('#editImageName').val().trim();
            const url = $('#editImageUrl').val().trim();
            
            if (!name || !url) {
                showMessage('Complete all fields', 'error');
                return;
            }

            makeRequest({
                action: 'edit_image',
                index: index,
                name: name,
                url: url
            }, 
            function(response) {
                $('#editImageModal').modal('hide');
                showMessage(response.message, 'success');
                setTimeout(() => location.reload(), 1000);
            });
        }

        function deleteImage(index) {
            if (!confirm('Are you sure you want to delete this image?')) {
                return;
            }
            
            makeRequest({
                action: 'delete_image',
                index: index
            }, 
            function(response) {
                showMessage(response.message, 'success');
                setTimeout(() => location.reload(), 1000);
            });
        }

        function showTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(tab => { 
                tab.classList.remove('active'); 
            });
            document.querySelectorAll('.tab-btn').forEach(btn => { 
                btn.classList.remove('active'); 
            });
            document.getElementById('tab-' + tabName).classList.add('active');
            event.target.classList.add('active');
        }

        $('#addServerForm').on('submit', function(e) {
            e.preventDefault();
            
            const name = $('#serverName').val().trim();
            const dns = $('#serverDns').val().trim();
            
            if (!name || !dns) {
                showMessage('Name and DNS are required', 'error');
                return;
            }
            
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
            submitBtn.disabled = true;
            
            makeRequest({
                action: 'add_server',
                name: name,
                dns: dns
            }, 
            function(response) {
                showMessage(response.message, 'success');
                $('#serverName').val('');
                $('#serverDns').val('');
                setTimeout(() => location.reload(), 1000);
            },
            function() {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
            
            setTimeout(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            }, 10000);
        });

        function deleteServer(id) {
            if (!confirm('Are you sure you want to delete this server? Cannot delete if it has associated users.')) {
                return;
            }
            
            makeRequest({
                action: 'delete_server',
                id: id
            }, 
            function(response) {
                showMessage(response.message, 'success');
                setTimeout(() => location.reload(), 1000);
            });
        }

        // INICIALIZACIÓN AL CARGAR LA PÁGINA
        $(document).ready(function() {
            // Esperar un momento antes de iniciar las pruebas automáticas
            setTimeout(autoTestServers, 1000);
            
            $('.modal').on('hidden.bs.modal', function() {
                $(this).find('form')[0]?.reset();
            });
            
            // Actualizar contadores después de que las pruebas terminen
            setTimeout(updateOnlineCount, 10000);
        });
    </script>
</body>
</html>