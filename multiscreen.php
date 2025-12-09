<?php
session_start();
if (!isset($_SESSION['user_logged'])) {
    header('Location: index.php');
    exit;
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

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($response && $http_code == 200) {
        return json_decode($response, true);
    }
    
    return [];
}

$categories = getAPI('get_live_categories');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Multi-Screen TV</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://vjs.zencdn.net/7.20.3/video-js.css" rel="stylesheet" />
    <script src="https://vjs.zencdn.net/7.20.3/video.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #000;
            color: #fff;
            font-family: 'Segoe UI', sans-serif;
            overflow: hidden;
            height: 100vh;
        }

        /* Contenedor principal */
        .multiscreen-container {
            margin-left: 0;
            height: 100vh;
            position: relative;
            background: #000;
            display: grid;
            grid-template-columns: 1fr 1fr;
            grid-template-rows: 1fr 1fr;
            gap: 2px;
        }

        /* Pantalla individual */
        .screen {
            position: relative;
            background: #111;
            overflow: hidden;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .screen.active {
            border-color: #fff;
            box-shadow: 0 0 20px rgba(255,255,255,0.3);
        }

        .screen.fullscreen {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100vw !important;
            height: 100vh !important;
            z-index: 9999 !important;
            border: none !important;
        }

        /* Video player styling */
        .video-js {
            width: 100% !important;
            height: 100% !important;
            background: #000 !important;
        }

        .video-js .vjs-big-play-button {
            display: none !important;
        }

        .video-js .vjs-control-bar {
            background: rgba(0, 0, 0, 0.8) !important;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .screen:hover .video-js .vjs-control-bar {
            opacity: 1;
        }

        .screen.active .video-js .vjs-control-bar {
            opacity: 1;
        }

        /* Placeholder para pantallas vacías */
        .screen-placeholder {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            color: #666;
            z-index: 10;
        }

        .screen-placeholder i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: #333;
        }

        .screen-placeholder h3 {
            font-size: 1.2rem;
            margin-bottom: 8px;
            font-weight: 300;
            color: #666;
        }

        .screen-placeholder p {
            font-size: 0.9rem;
            color: #888;
        }

        /* Controles de pantalla */
        .screen-controls {
            position: absolute;
            top: 10px;
            right: 10px;
            display: flex;
            gap: 8px;
            z-index: 100;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .screen:hover .screen-controls {
            opacity: 1;
        }

        .screen.active .screen-controls {
            opacity: 1;
        }

        .screen-control-btn {
            background: rgba(0, 0, 0, 0.8);
            border: none;
            color: white;
            padding: 8px;
            border-radius: 50%;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 0.9rem;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .screen-control-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: scale(1.1);
        }

        .screen-control-btn.active {
            background: #007acc;
            color: white;
        }

        /* Información del canal */
        .channel-info {
            position: absolute;
            bottom: 10px;
            left: 10px;
            right: 10px;
            background: linear-gradient(transparent, rgba(0, 0, 0, 0.8));
            padding: 15px 10px 10px;
            opacity: 0;
            transition: opacity 0.3s ease;
            z-index: 50;
        }

        .screen:hover .channel-info {
            opacity: 1;
        }

        .screen.active .channel-info {
            opacity: 1;
        }

        .channel-name {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 2px;
            color: #fff;
        }

        .channel-number {
            font-size: 0.8rem;
            color: #aaa;
        }

        /* Selector de canales - IGUAL AL ORIGINAL */
        .channel-selector {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 70%;
            max-width: 1000px;
            height: 65%;
            background: rgba(0, 0, 0, 0.95);
            border-radius: 15px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            z-index: 2000;
            display: flex;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(20px);
        }

        .channel-selector.hidden {
            display: none;
        }

        /* Panel de categorías */
        .categories-panel {
            width: 250px;
            background: rgba(0, 0, 0, 0.3);
            border-right: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            flex-direction: column;
        }

        .panel-header {
            padding: 25px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .panel-title {
            font-size: 1.4rem;
            font-weight: 300;
            color: #fff;
            text-align: center;
        }

        .categories-list {
            flex: 1;
            overflow-y: auto;
            padding: 10px 0;
        }

        .category-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px 20px;
            cursor: pointer;
            transition: all 0.3s;
            border-left: 3px solid transparent;
        }

        .category-item:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .category-item.active {
            background: rgba(255, 255, 255, 0.15);
            border-left-color: #fff;
        }

        .category-icon {
            width: 12px;
            height: 12px;
            background: #fff;
            border-radius: 50%;
        }

        .category-name {
            font-size: 1rem;
            font-weight: 400;
            color: #fff;
        }

        /* Panel de canales */
        .channels-panel {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: rgba(0, 0, 0, 0.2);
        }

        .channels-header {
            padding: 25px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .channels-title {
            font-size: 1.4rem;
            font-weight: 300;
            color: #fff;
        }

        .close-btn {
            background: none;
            border: none;
            color: #aaa;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 5px;
            border-radius: 50%;
            transition: all 0.3s;
        }

        .close-btn:hover {
            color: white;
            background: rgba(255, 255, 255, 0.1);
        }

        .channels-list {
            flex: 1;
            overflow-y: auto;
            padding: 10px 0;
        }

        .channel-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 12px 20px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .channel-item:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .channel-item-number {
            color: #aaa;
            font-size: 0.9rem;
            font-weight: 500;
            min-width: 30px;
        }

        .channel-item-icon {
            width: 40px;
            height: 30px;
            object-fit: contain;
            border-radius: 4px;
            background: rgba(255, 255, 255, 0.05);
        }

        .channel-item-name {
            flex: 1;
            font-size: 1rem;
            font-weight: 400;
            color: #fff;
        }

        /* Scrollbars */
        ::-webkit-scrollbar {
            width: 4px;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }

        ::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.3);
            border-radius: 2px;
        }

        /* Loading state */
        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 150;
        }

        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 3px solid #333;
            border-top: 3px solid #fff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Responsive */
        @media (max-width: 768px) {
            .multiscreen-container {
                margin-left: 0;
                grid-template-columns: 1fr;
                grid-template-rows: 1fr 1fr;
            }
            
            .channel-selector {
                width: 95%;
                height: 85%;
                flex-direction: column;
            }
            
            .categories-panel {
                width: 100%;
                height: 150px;
                border-right: none;
                border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            }
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    
    <div class="multiscreen-container">
        <!-- Pantalla 1 -->
        <div class="screen" id="screen1" data-screen="1">
            <div class="screen-placeholder">
                <i class="fas fa-tv"></i>
                <h3>Pantalla 1</h3>
                <p>Click para seleccionar canal</p>
            </div>
            <div class="screen-controls">
                <button class="screen-control-btn" onclick="toggleFullscreen(1)" title="Pantalla completa">
                    <i class="fas fa-expand"></i>
                </button>
                <button class="screen-control-btn" onclick="togglePause(1)" title="Pausar/Reproducir">
                    <i class="fas fa-pause"></i>
                </button>
                <button class="screen-control-btn audio-btn" onclick="toggleAudio(1)" title="Audio">
                    <i class="fas fa-volume-up"></i>
                </button>
            </div>
            <div class="channel-info">
                <div class="channel-name" id="channelName1">-</div>
                <div class="channel-number" id="channelNumber1">-</div>
            </div>
        </div>

        <!-- Pantalla 2 -->
        <div class="screen" id="screen2" data-screen="2">
            <div class="screen-placeholder">
                <i class="fas fa-tv"></i>
                <h3>Pantalla 2</h3>
                <p>Click para seleccionar canal</p>
            </div>
            <div class="screen-controls">
                <button class="screen-control-btn" onclick="toggleFullscreen(2)" title="Pantalla completa">
                    <i class="fas fa-expand"></i>
                </button>
                <button class="screen-control-btn" onclick="togglePause(2)" title="Pausar/Reproducir">
                    <i class="fas fa-pause"></i>
                </button>
                <button class="screen-control-btn audio-btn" onclick="toggleAudio(2)" title="Audio">
                    <i class="fas fa-volume-off"></i>
                </button>
            </div>
            <div class="channel-info">
                <div class="channel-name" id="channelName2">-</div>
                <div class="channel-number" id="channelNumber2">-</div>
            </div>
        </div>

        <!-- Pantalla 3 -->
        <div class="screen" id="screen3" data-screen="3">
            <div class="screen-placeholder">
                <i class="fas fa-tv"></i>
                <h3>Pantalla 3</h3>
                <p>Click para seleccionar canal</p>
            </div>
            <div class="screen-controls">
                <button class="screen-control-btn" onclick="toggleFullscreen(3)" title="Pantalla completa">
                    <i class="fas fa-expand"></i>
                </button>
                <button class="screen-control-btn" onclick="togglePause(3)" title="Pausar/Reproducir">
                    <i class="fas fa-pause"></i>
                </button>
                <button class="screen-control-btn audio-btn" onclick="toggleAudio(3)" title="Audio">
                    <i class="fas fa-volume-off"></i>
                </button>
            </div>
            <div class="channel-info">
                <div class="channel-name" id="channelName3">-</div>
                <div class="channel-number" id="channelNumber3">-</div>
            </div>
        </div>

        <!-- Pantalla 4 -->
        <div class="screen" id="screen4" data-screen="4">
            <div class="screen-placeholder">
                <i class="fas fa-tv"></i>
                <h3>Pantalla 4</h3>
                <p>Click para seleccionar canal</p>
            </div>
            <div class="screen-controls">
                <button class="screen-control-btn" onclick="toggleFullscreen(4)" title="Pantalla completa">
                    <i class="fas fa-expand"></i>
                </button>
                <button class="screen-control-btn" onclick="togglePause(4)" title="Pausar/Reproducir">
                    <i class="fas fa-pause"></i>
                </button>
                <button class="screen-control-btn audio-btn" onclick="toggleAudio(4)" title="Audio">
                    <i class="fas fa-volume-off"></i>
                </button>
            </div>
            <div class="channel-info">
                <div class="channel-name" id="channelName4">-</div>
                <div class="channel-number" id="channelNumber4">-</div>
            </div>
        </div>
    </div>

    <!-- Selector de canales -->
    <div class="channel-selector hidden" id="channelSelector">
        <!-- Panel de categorías -->
        <div class="categories-panel">
            <div class="panel-header">
                <div class="panel-title">Categorías</div>
            </div>
            <div class="categories-list" id="categoriesList">
                <?php foreach($categories as $cat): ?>
                    <div class="category-item" 
                         data-category-id="<?= $cat['category_id'] ?>"
                         onclick="selectCategory('<?= $cat['category_id'] ?>', this)">
                        <div class="category-icon"></div>
                        <div class="category-name"><?= htmlspecialchars($cat['category_name']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Panel de canales -->
        <div class="channels-panel">
            <div class="channels-header">
                <div class="channels-title" id="channelsTitle">Selecciona una categoría</div>
                <button class="close-btn" onclick="closeChannelSelector()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="channels-list" id="channelsList">
                <!-- Los canales se cargan dinámicamente -->
            </div>
        </div>
    </div>

    <script>
        // Variables globales
        let currentActiveScreen = 1;
        let selectedScreen = 1;
        let players = {};
        let screenChannels = {};
        let audioScreen = 1; // Solo una pantalla puede tener audio
        let isFullscreen = false;
        let fullscreenScreen = null;

        // Datos de categorías y canales
        const allCategories = <?= json_encode($categories) ?>;
        let currentChannels = [];

        // Inicialización
        document.addEventListener('DOMContentLoaded', function() {
            setupScreenEvents();
            setupKeyboardNavigation();
            setActiveScreen(1); // Pantalla 1 activa por defecto
        });

        // Configurar eventos de las pantallas
        function setupScreenEvents() {
            for (let i = 1; i <= 4; i++) {
                const screen = document.getElementById(`screen${i}`);
                
                // Click para abrir selector
                screen.addEventListener('click', function(e) {
                    if (e.target.closest('.screen-controls')) return;
                    selectedScreen = i;
                    setActiveScreen(i);
                    showChannelSelector();
                });

                // Hover para activar audio automáticamente
                screen.addEventListener('mouseenter', function() {
                    if (screenChannels[i] && !isFullscreen) {
                        setAudioScreen(i);
                    }
                });
            }
        }

        // Navegación con teclado
        function setupKeyboardNavigation() {
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    if (isFullscreen) {
                        exitFullscreen();
                    } else {
                        closeChannelSelector();
                    }
                }
                
                // Números 1-4 para cambiar pantalla activa
                if (['1', '2', '3', '4'].includes(e.key)) {
                    const screenNum = parseInt(e.key);
                    setActiveScreen(screenNum);
                }
                
                // F para fullscreen de pantalla activa
                if (e.key === 'f' || e.key === 'F') {
                    toggleFullscreen(currentActiveScreen);
                }
                
                // Espacio para pausar pantalla activa
                if (e.key === ' ') {
                    e.preventDefault();
                    togglePause(currentActiveScreen);
                }
            });
        }

        // Establecer pantalla activa
        function setActiveScreen(screenNum) {
            // Remover clase active de todas las pantallas
            for (let i = 1; i <= 4; i++) {
                document.getElementById(`screen${i}`).classList.remove('active');
            }
            
            // Activar pantalla seleccionada
            document.getElementById(`screen${screenNum}`).classList.add('active');
            currentActiveScreen = screenNum;
            
            // Si la pantalla tiene contenido, activar su audio
            if (screenChannels[screenNum]) {
                setAudioScreen(screenNum);
            }
        }

        // Gestionar audio - solo una pantalla puede tener audio
        function setAudioScreen(screenNum) {
            // Silenciar todas las pantallas
            for (let i = 1; i <= 4; i++) {
                if (players[i]) {
                    players[i].muted(i !== screenNum);
                }
                
                // Actualizar iconos de audio
                const audioBtn = document.querySelector(`#screen${i} .audio-btn i`);
                if (audioBtn) {
                    audioBtn.className = i === screenNum ? 'fas fa-volume-up' : 'fas fa-volume-off';
                }
                
                // Actualizar clase active del botón
                const audioBtnParent = document.querySelector(`#screen${i} .audio-btn`);
                if (audioBtnParent) {
                    audioBtnParent.classList.toggle('active', i === screenNum);
                }
            }
            
            audioScreen = screenNum;
        }

        // Toggle audio de una pantalla específica
        function toggleAudio(screenNum) {
            setAudioScreen(screenNum);
        }

        // Seleccionar categoría y cargar canales
        function selectCategory(categoryId, element) {
            // Activar categoría seleccionada
            document.querySelectorAll('.category-item').forEach(item => {
                item.classList.remove('active');
            });
            element.classList.add('active');

            // Cargar canales de la categoría
            showLoading();
            
            fetch(`get_channels.php?category_id=${categoryId}`)
                .then(response => response.json())
                .then(channels => {
                    currentChannels = channels;
                    renderChannels();
                    document.getElementById('channelsTitle').textContent = element.querySelector('.category-name').textContent;
                })
                .catch(error => {
                    console.error('Error loading channels:', error);
                    document.getElementById('channelsList').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: #ff4444;">
                            Error al cargar canales
                        </div>
                    `;
                });
        }

        // Renderizar lista de canales
        function renderChannels() {
            const list = document.getElementById('channelsList');
            list.innerHTML = '';

            if (currentChannels.length === 0) {
                list.innerHTML = `
                    <div style="text-align: center; padding: 40px; color: #666;">
                        No hay canales en esta categoría
                    </div>
                `;
                return;
            }

            currentChannels.forEach((channel, index) => {
                const channelItem = document.createElement('div');
                channelItem.className = 'channel-item';
                channelItem.onclick = () => loadChannelToScreen(channel, index + 1);
                
                channelItem.innerHTML = `
                    <div class="channel-item-number">${index + 1}</div>
                    ${channel.stream_icon ? `<img src="${channel.stream_icon}" class="channel-item-icon" alt="${channel.name}" onerror="this.style.display='none';">` : ''}
                    <div class="channel-item-name">${channel.name}</div>
                `;
                
                list.appendChild(channelItem);
            });
        }

        // Cargar canal en la pantalla seleccionada
        function loadChannelToScreen(channel, channelNumber) {
            closeChannelSelector();
            
            const screenId = selectedScreen;
            const screen = document.getElementById(`screen${screenId}`);
            
            // Mostrar loading
            screen.innerHTML = `
                <div class="loading-overlay">
                    <div class="loading-spinner"></div>
                </div>
                <div class="screen-controls">
                    <button class="screen-control-btn" onclick="toggleFullscreen(${screenId})" title="Pantalla completa">
                        <i class="fas fa-expand"></i>
                    </button>
                    <button class="screen-control-btn" onclick="togglePause(${screenId})" title="Pausar/Reproducir">
                        <i class="fas fa-pause"></i>
                    </button>
                    <button class="screen-control-btn audio-btn" onclick="toggleAudio(${screenId})" title="Audio">
                        <i class="fas fa-volume-${screenId === audioScreen ? 'up' : 'off'}"></i>
                    </button>
                </div>
                <div class="channel-info">
                    <div class="channel-name" id="channelName${screenId}">${channel.name}</div>
                    <div class="channel-number" id="channelNumber${screenId}">Canal ${channelNumber}</div>
                </div>
            `;

            // Guardar información del canal
            screenChannels[screenId] = {
                id: channel.stream_id,
                name: channel.name,
                number: channelNumber,
                icon: channel.stream_icon
            };

            // Cargar reproductor
            const timestamp = new Date().getTime();
            fetch(`load_player.php?id=${channel.stream_id}&t=${timestamp}`)
                .then(response => response.text())
                .then(html => {
                    // Extraer solo el video element
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const videoElement = doc.querySelector('video');
                    
                    if (videoElement) {
                        // Limpiar loading
                        screen.querySelector('.loading-overlay').remove();
                        
                        // Insertar video
                        screen.insertAdjacentHTML('afterbegin', videoElement.outerHTML);
                        
                        // Inicializar Video.js
                        setTimeout(() => {
                            initializePlayer(screenId, channel.stream_id);
                        }, 200);
                    } else {
                        showError(screenId, 'Error al cargar el reproductor');
                    }
                })
                .catch(error => {
                    console.error('Error loading player:', error);
                    showError(screenId, 'Error de conexión');
                });
        }

        // Inicializar reproductor Video.js
        function initializePlayer(screenId, channelId) {
            const playerId = `player-${channelId}`;
            const playerElement = document.querySelector(`#screen${screenId} video`);
            
            if (!playerElement) {
                console.error('Player element not found');
                return;
            }

            // Asignar ID único
            playerElement.id = playerId;

            try {
                // Limpiar reproductor anterior si existe
                if (players[screenId]) {
                    players[screenId].dispose();
                }

                // Crear nuevo reproductor
                players[screenId] = videojs(playerId, {
                    fluid: false,
                    responsive: false,
                    controls: true,
                    preload: 'auto',
                    autoplay: true,
                    muted: screenId !== audioScreen, // Solo audio en pantalla activa
                    playsinline: true,
                    liveui: true
                });

                players[screenId].ready(function() {
                    console.log(`Player ready for screen ${screenId}`);
                    
                    // Reproducir automáticamente
                    const playPromise = this.play();
                    if (playPromise !== undefined) {
                        playPromise.catch(error => {
                            console.log('Autoplay prevented:', error);
                        });
                    }
                });

                players[screenId].on('error', function() {
                    console.error(`Player error on screen ${screenId}`);
                    showError(screenId, 'Error de reproducción');
                });

            } catch (error) {
                console.error('Error initializing player:', error);
                showError(screenId, 'Error al inicializar reproductor');
            }
        }

      // Controles de pantalla
        function toggleFullscreen(screenId) {
            const screen = document.getElementById(`screen${screenId}`);
            
            if (!isFullscreen) {
                // Entrar en fullscreen
                screen.classList.add('fullscreen');
                isFullscreen = true;
                fullscreenScreen = screenId;
                setAudioScreen(screenId);
                
                // Cambiar icono
                const fullscreenBtn = screen.querySelector('.screen-controls button:first-child i');
                if (fullscreenBtn) fullscreenBtn.className = 'fas fa-compress';
                
            } else if (fullscreenScreen === screenId) {
                // Salir de fullscreen
                exitFullscreen();
            }
        }

        function exitFullscreen() {
            if (isFullscreen && fullscreenScreen) {
                const screen = document.getElementById(`screen${fullscreenScreen}`);
                screen.classList.remove('fullscreen');
                
                // Cambiar icono
                const fullscreenBtn = screen.querySelector('.screen-controls button:first-child i');
                if (fullscreenBtn) fullscreenBtn.className = 'fas fa-expand';
                
                isFullscreen = false;
                fullscreenScreen = null;
            }
        }

        function togglePause(screenId) {
            if (players[screenId]) {
                const player = players[screenId];
                const pauseBtn = document.querySelector(`#screen${screenId} .screen-controls button:nth-child(2) i`);
                
                if (player.paused()) {
                    player.play();
                    if (pauseBtn) pauseBtn.className = 'fas fa-pause';
                } else {
                    player.pause();
                    if (pauseBtn) pauseBtn.className = 'fas fa-play';
                }
            }
        }

        // Control del selector de canales
        function showChannelSelector() {
            document.getElementById('channelSelector').classList.remove('hidden');
        }

        function closeChannelSelector() {
            document.getElementById('channelSelector').classList.add('hidden');
        }

        // Estados de loading y error
        function showLoading() {
            document.getElementById('channelsList').innerHTML = `
                <div style="text-align: center; padding: 40px; color: #fff;">
                    <div style="
                        width: 30px;
                        height: 30px;
                        border: 2px solid #333;
                        border-top: 2px solid #fff;
                        border-radius: 50%;
                        animation: spin 1s linear infinite;
                        margin: 0 auto 15px;
                    "></div>
                    Cargando canales...
                </div>
            `;
        }

        function showError(screenId, message) {
            const screen = document.getElementById(`screen${screenId}`);
            screen.innerHTML = `
                <div class="screen-placeholder">
                    <i class="fas fa-exclamation-triangle" style="color: #ff4444;"></i>
                    <h3 style="color: #ff4444;">Error</h3>
                    <p>${message}</p>
                </div>
                <div class="screen-controls">
                    <button class="screen-control-btn" onclick="toggleFullscreen(${screenId})" title="Pantalla completa">
                        <i class="fas fa-expand"></i>
                    </button>
                    <button class="screen-control-btn" onclick="togglePause(${screenId})" title="Pausar/Reproducir">
                        <i class="fas fa-pause"></i>
                    </button>
                    <button class="screen-control-btn audio-btn" onclick="toggleAudio(${screenId})" title="Audio">
                        <i class="fas fa-volume-off"></i>
                    </button>
                </div>
                <div class="channel-info">
                    <div class="channel-name" id="channelName${screenId}">Error</div>
                    <div class="channel-number" id="channelNumber${screenId}">-</div>
                </div>
            `;
            
            // Limpiar datos del canal
            delete screenChannels[screenId];
            if (players[screenId]) {
                players[screenId].dispose();
                delete players[screenId];
            }
        }

        // Limpiar recursos al salir
        window.addEventListener('beforeunload', function() {
            Object.values(players).forEach(player => {
                try {
                    player.dispose();
                } catch(e) {}
            });
        });

        console.log('Multi-Screen TV interface ready');
    </script>

   