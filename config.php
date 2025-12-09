<?php
// config.php - Funciones para manejar configuración JSON

function getConfigFile() {
    return __DIR__ . '/config.json';
}

function loadConfig() {
    $configFile = getConfigFile();
    
    if (!file_exists($configFile)) {
        // Crear configuración por defecto
        $defaultConfig = [
            'video_player' => 'videojs',
            'autoplay' => true,
            'save_position' => true,
            'auto_next_episode' => true,
            'theme' => 'dark'
        ];
        saveConfig($defaultConfig);
        return $defaultConfig;
    }
    
    $json = file_get_contents($configFile);
    $config = json_decode($json, true);
    
    // Si hay error en JSON, usar configuración por defecto
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'video_player' => 'videojs',
            'autoplay' => true,
            'save_position' => true,
            'auto_next_episode' => true,
            'theme' => 'dark'
        ];
    }
    
    return $config;
}

function saveConfig($config) {
    $configFile = getConfigFile();
    $json = json_encode($config, JSON_PRETTY_PRINT);
    return file_put_contents($configFile, $json);
}

function updateConfig($key, $value) {
    $config = loadConfig();
    $config[$key] = $value;
    return saveConfig($config);
}

function getConfig($key, $default = null) {
    $config = loadConfig();
    return $config[$key] ?? $default;
}
?>