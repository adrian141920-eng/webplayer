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
$category_id = $_GET['category_id'] ?? ($categories[0]['category_id'] ?? '');
$channels = $category_id ? getAPI('get_live_streams', ['category_id' => $category_id]) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Smart TV</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
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
            touch-action: manipulation;
        }

        /* Contenedor principal */
        .tv-container {
            margin-left: 0;
            height: 100vh;
            position: relative;
            background: #000;
        }

        /* Área del reproductor */
        .player-area {
            width: 100%;
            height: 100%;
            position: relative;
            background: #000;
        }

        /* Video.js styling */
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

        .video-js:hover .vjs-control-bar {
            opacity: 1;
        }

        /* Controles del reproductor */
        .player-controls {
            position: absolute;
            bottom: 80px;
            right: 20px;
            display: flex;
            gap: 15px;
            z-index: 200;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .player-controls.show {
            opacity: 1;
        }

        .control-btn {
            background: rgba(0, 0, 0, 0.7);
            border: none;
            color: white;
            padding: 15px;
            border-radius: 50%;
            cursor: pointer;
            transition: all 0.3s;
            font-size: 1.2rem;
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255, 255, 255, 0.2);
        }

        .control-btn:hover {
            background: rgba(255, 255, 255, 0.2);
            transform: scale(1.1);
        }

        /* Banner de información del canal */
        .channel-banner {
            position: absolute;
            bottom: 80px;
            left: 0;
            right: 0;
            background: linear-gradient(transparent, rgba(0, 0, 0, 0.8));
            padding: 40px 30px 20px;
            transform: translateY(150%);
            transition: transform 0.4s ease;
            z-index: 100;
            opacity: 0;
        }

        .channel-banner.show {
            transform: translateY(0);
            opacity: 1;
        }

        .banner-content {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .channel-logo {
            width: 80px;
            height: 60px;
            object-fit: contain;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.1);
            padding: 8px;
        }

        .channel-info {
            flex: 1;
        }

        .channel-name {
            font-size: 1.8rem;
            font-weight: 300;
            margin-bottom: 8px;
        }

        .current-program {
            font-size: 1.1rem;
            color: #fff;
            margin-bottom: 4px;
            font-weight: 500;
        }

        .program-time {
            font-size: 0.9rem;
            color: #aaa;
        }

        .live-indicator {
            background: #ff4444;
            color: white;
            padding: 6px 12px;
            border-radius: 15px;
            font-size: 0.8rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .live-dot {
            width: 6px;
            height: 6px;
            background: white;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.3; }
        }

        /* Selector de canales */
        .channel-selector {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 90%;
            max-width: 1000px;
            height: 80%;
            max-height: 600px;
            background: rgba(0, 0, 0, 0.9);
            border-radius: 15px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }

        .channel-selector.hidden {
            display: none;
        }

        /* Panel de categorías */
        .categories-panel {
            width: 100%;
            height: 80px;
            background: rgba(0, 0, 0, 0.7);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            overflow-x: auto;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        .categories-panel::-webkit-scrollbar {
            display: none;
        }

        .categories-list {
            display: flex;
            padding: 10px;
            gap: 10px;
            min-width: 100%;
        }

        .category-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 15px;
            cursor: pointer;
            transition: all 0.3s;
            border-radius: 20px;
            white-space: nowrap;
            background: rgba(255, 255, 255, 0.1);
            flex-shrink: 0;
        }

        .category-item:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .category-item.active {
            background: rgba(255, 255, 255, 0.3);
        }

        .category-icon {
            width: 8px;
            height: 8px;
            background: #fff;
            border-radius: 50%;
        }

        .category-name {
            font-size: 0.9rem;
            font-weight: 400;
            color: #fff;
        }

        /* Panel de canales */
        .channels-panel {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: rgba(0, 0, 0, 0.8);
            overflow: hidden;
        }

        .channels-header {
            padding: 15px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(0, 0, 0, 0.7);
        }

        .channels-title {
            font-size: 1.2rem;
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

        .channel-number {
            color: #aaa;
            font-size: 0.9rem;
            font-weight: 500;
            min-width: 30px;
        }

        .channel-icon {
            width: 40px;
            height: 30px;
            object-fit: contain;
            border-radius: 4px;
            background: rgba(255, 255, 255, 0.05);
        }

        /* Placeholder inicial */
        .tv-placeholder {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            color: #666;
            width: 90%;
            max-width: 500px;
        }

        .tv-placeholder i {
            font-size: 4rem;
            margin-bottom: 20px;
            color: #333;
        }

        .tv-placeholder h2 {
            font-size: 1.8rem;
            margin-bottom: 10px;
            font-weight: 300;
            color: #666;
        }

        .tv-placeholder p {
            font-size: 1rem;
            color: #888;
        }

        /* Loading */
        .loading-state {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            color: #fff;
        }

        .loading-spinner {
            width: 50px;
            height: 50px;
            border: 3px solid #333;
            border-top: 3px solid #fff;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Scrollbars */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        ::-webkit-scrollbar-track {
            background: transparent;
        }

        ::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.3);
            border-radius: 3px;
        }

        /* EPG Overlay */
        .epg-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.95);
            z-index: 1500;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .epg-overlay.hidden {
            display: none;
        }

        .epg-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(0, 0, 0, 0.8);
        }

        .epg-title {
            font-size: 1.2rem;
            font-weight: 300;
            color: #fff;
        }

        .epg-content {
            flex: 1;
            overflow-y: auto;
            padding: 15px;
        }

        /* Media Queries para diferentes tamaños */
        @media (min-width: 768px) {
            /* Estilos para tablet en adelante */
            .channel-selector {
                flex-direction: row;
                height: 70%;
            }
            
            .categories-panel {
                width: 250px;
                height: 100%;
                flex-direction: column;
                overflow-y: auto;
                overflow-x: hidden;
                border-right: 1px solid rgba(255, 255, 255, 0.1);
                border-bottom: none;
            }
            
            .categories-list {
                flex-direction: column;
                padding: 10px 0;
                min-width: auto;
            }
            
            .category-item {
                border-radius: 0;
                border-left: 3px solid transparent;
                background: transparent;
                white-space: normal;
            }
            
            .category-item.active {
                border-left-color: #fff;
                background: rgba(255, 255, 255, 0.15);
            }
            
            .channel-name {
                font-size: 1.8rem;
            }
            
            .current-program {
                font-size: 1.1rem;
            }
        }

        @media (min-width: 992px) {
            /* Estilos para pantallas más grandes */
            .channel-selector {
                width: 80%;
                max-width: 1200px;
            }
            
            .channel-banner {
                padding: 40px 30px 20px;
            }
        }

        /* Soporte para pantallas muy pequeñas */
        @media (max-width: 480px) {
            .player-controls {
                bottom: 20px;
                right: 10px;
                gap: 10px;
            }
            
            .control-btn {
                padding: 12px;
                font-size: 1rem;
            }
            
            .channel-banner {
                padding: 20px 15px 10px;
            }
            
            .channel-logo {
                width: 60px;
                height: 45px;
            }
            
            .channel-name {
                font-size: 1.4rem;
            }
            
            .current-program {
                font-size: 0.9rem;
            }
            
            .live-indicator {
                font-size: 0.7rem;
                padding: 4px 8px;
            }
            
            .tv-placeholder i {
                font-size: 3rem;
            }
            
            .tv-placeholder h2 {
                font-size: 1.5rem;
            }
            
            .tv-placeholder p {
                font-size: 0.9rem;
            }
        }

        /* Soporte para orientación horizontal en móviles */
        @media (max-width: 768px) and (orientation: landscape) {
            .channel-selector {
                height: 90%;
                max-height: none;
            }
            
            .categories-panel {
                height: 60px;
            }
            
            .categories-list {
                flex-direction: row;
            }
        }
        @media screen and (max-width: 768px) and (orientation: portrait) {
    .tv-container {
        display: flex;
        flex-direction: column;
        height: 100vh;
    }

    .player-area {
        height: 40vh !important; /* Reproductor ocupa parte superior */
        flex-shrink: 0;
    }

    .channel-selector {
        position: relative;
        top: 0;
        left: 0;
        transform: none;
        width: 100%;
        height: 60vh !important;
        max-height: none;
        border-radius: 0;
        z-index: auto;
        display: flex !important;
        flex-direction: column;
    }

    .channel-selector.hidden {
        display: flex !important;
    }

    .categories-panel {
        height: 60px;
        flex-shrink: 0;
    }

    .channels-panel {
        flex: 1;
        overflow-y: auto;
    }

    .player-controls {
        display: none !important; /* Oculta los controles flotantes si estorban */
    }

    .channel-banner {
        display: none !important; /* Opcional: oculta el banner para más espacio */
    }

    .tv-placeholder {
        display: none;
    }
}

    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    
    <div class="tv-container">
        <!-- Área del reproductor -->
        <div class="player-area" id="playerArea">
            <!-- Placeholder inicial -->
            <div class="tv-placeholder" id="tvPlaceholder">
                <i class="fas fa-tv"></i>
                <h2>Smart TV</h2>
                <p>Presiona ENTER para abrir el selector de canales</p>
            </div>
        </div>

        <!-- EPG Overlay -->
        <div class="epg-overlay hidden" id="epgOverlay">
            <div class="epg-header">
                <div class="epg-title" id="epgTitle">Guía de Programas</div>
                <button class="close-btn" onclick="closeEPGOverlay()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="epg-content" id="epgContent">
                <!-- EPG se carga aquí -->
            </div>
        </div>

        <!-- Controles del reproductor -->
        <div class="player-controls" id="playerControls">
            <button class="control-btn" onclick="toggleChannelSelector()" title="Selector de canales">
                <i class="fas fa-list"></i>
            </button>
            <button class="control-btn" onclick="openEPG()" title="Guía de programas">
                <i class="fas fa-calendar"></i>
            </button>
        </div>

        <!-- Banner de información del canal -->
        <div class="channel-banner" id="channelBanner">
            <div class="banner-content">
                <img id="channelLogoImg" class="channel-logo" src="" alt="Logo del canal" style="display: none;">
                <div class="channel-info">
                    <div class="channel-name" id="currentChannelName">Canal</div>
                    <div class="current-program" id="currentProgram">Programa actual</div>
                    <div class="program-time" id="programTime"></div>
                </div>
                <div class="live-indicator">
                    <div class="live-dot"></div>
                    EN VIVO
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
                        <div class="category-item <?= ($cat['category_id'] == $category_id) ? 'active' : '' ?>" 
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
                    <?php if (!empty($channels)): ?>
                        <?php foreach($channels as $index => $channel): ?>
                            <div class="channel-item" 
                                 onclick="playChannel(<?= $channel['stream_id'] ?>, <?= $index + 1 ?>, '<?= htmlspecialchars($channel['name']) ?>', '<?= htmlspecialchars($channel['stream_icon'] ?? '') ?>')">
                                <div class="channel-number"><?= $index + 1 ?></div>
                                <?php if (!empty($channel['stream_icon'])): ?>
                                    <img src="<?= htmlspecialchars($channel['stream_icon']) ?>" 
                                         class="channel-icon" 
                                         alt="<?= htmlspecialchars($channel['name']) ?>"
                                         onerror="this.style.display='none';">
                                <?php endif; ?>
                                <div class="channel-name"><?= htmlspecialchars($channel['name']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        let currentPlayer = null;
        let currentChannelId = null;
        let currentChannelName = null;
        let currentChannelIcon = null;
        let currentChannelIndex = 0;
        let allCategories = <?= json_encode($categories) ?>;
        let currentCategoryChannels = <?= json_encode($channels) ?>;
        let bannerTimeout;
        let controlsTimeout;
        let mouseTimer;

        // Inicialización
        document.addEventListener('DOMContentLoaded', function() {
            showChannelSelector();
            setupMouseMovement();
            setupKeyboardNavigation();
        });

        // Detectar movimiento del mouse para mostrar controles y banner
        function setupMouseMovement() {
            document.addEventListener('mousemove', function() {
                if (!currentPlayer) return;
                
                clearTimeout(mouseTimer);
                showPlayerControls();
                showChannelBanner();
                
                mouseTimer = setTimeout(() => {
                    hidePlayerControls();
                    hideChannelBanner();
                }, 3000);
            });

            // Ocultar cuando el mouse sale del área
            document.addEventListener('mouseleave', function() {
                clearTimeout(mouseTimer);
                setTimeout(() => {
                    hidePlayerControls();
                    hideChannelBanner();
                }, 1000);
            });
        }

        // Navegación con teclado
        function setupKeyboardNavigation() {
            document.addEventListener('keydown', function(e) {
                const selectorVisible = !document.getElementById('channelSelector').classList.contains('hidden');
                
                if (!selectorVisible && currentPlayer) {
                    switch(e.key) {
                        case 'ArrowLeft':
                            e.preventDefault();
                            previousChannel();
                            break;
                        case 'ArrowRight':
                            e.preventDefault();
                            nextChannel();
                            break;
                        case 'Enter':
                            e.preventDefault();
                            toggleChannelSelector();
                            break;
                        case 'g':
                        case 'G':
                            openEPG();
                            break;
                    }
                }
                
                if (e.key === 'Escape') {
                    closeChannelSelector();
                }
            });
        }

        // Seleccionar categoría SIN AJAX - recargando página como tu código original
        function selectCategory(categoryId, element) {
            // Simplemente recargar la página con la nueva categoría
            window.location.href = `?category_id=${categoryId}`;
        }

        // Renderizar canales en lista
        function renderChannels() {
            const list = document.getElementById('channelsList');
            list.innerHTML = '';

            currentCategoryChannels.forEach((channel, index) => {
                const channelItem = document.createElement('div');
                channelItem.className = 'channel-item';
                channelItem.onclick = () => playChannel(channel.stream_id, index + 1, channel.name, channel.stream_icon || '');
                
                channelItem.innerHTML = `
                    <div class="channel-number">${index + 1}</div>
                    ${channel.stream_icon ? `<img src="${channel.stream_icon}" class="channel-icon" alt="${channel.name}" onerror="this.style.display='none';">` : ''}
                    <div class="channel-name">${channel.name}</div>
                `;
                
                list.appendChild(channelItem);
            });
        }

        // Función para limpiar intervalos al cambiar de canal
        function cleanupChannelResources() {
            if (window.epgUpdateInterval) {
                clearInterval(window.epgUpdateInterval);
                window.epgUpdateInterval = null;
            }
        }

        // Cargar EPG del canal - VERSIÓN MEJORADA
        function loadChannelEPG(channelId) {
            fetch(`load_epg.php?id=${channelId}&t=${new Date().getTime()}`)
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    
                    // Buscar el programa actual (con clase 'now')
                    const currentProgram = doc.querySelector('.epg-card.now');
                    
                    if (currentProgram) {
                        // Extraer título del programa
                        const titleElement = currentProgram.querySelector('.epg-title');
                        const title = titleElement ? titleElement.textContent.trim() : 'Programa actual';
                        
                        // Extraer horario del programa
                        const timeElement = currentProgram.querySelector('.epg-time');
                        let timeText = '';
                        if (timeElement) {
                            // Obtener solo el texto del horario, sin el "EN VIVO"
                            const timeNodes = timeElement.childNodes;
                            for (let node of timeNodes) {
                                if (node.nodeType === Node.TEXT_NODE) {
                                    timeText += node.textContent.trim();
                                }
                            }
                            // Limpiar espacios extra
                            timeText = timeText.replace(/\s+/g, ' ').trim();
                        }
                        
                        // Actualizar banner con información del programa actual
                        document.getElementById('currentProgram').textContent = title;
                        document.getElementById('programTime').textContent = timeText;
                        
                        console.log('Programa actual encontrado:', title, timeText);
                    } else {
                        // Si no hay programa actual, buscar el próximo programa
                        const nextProgram = doc.querySelector('.epg-card');
                        
                        if (nextProgram) {
                            const titleElement = nextProgram.querySelector('.epg-title');
                            const title = titleElement ? titleElement.textContent.trim() : 'Próximo programa';
                            
                            const timeElement = nextProgram.querySelector('.epg-time');
                            const timeText = timeElement ? timeElement.textContent.trim() : '';
                            
                            document.getElementById('currentProgram').textContent = `Próximo: ${title}`;
                            document.getElementById('programTime').textContent = timeText;
                            
                            console.log('Próximo programa encontrado:', title, timeText);
                        } else {
                            // No hay información de EPG
                            document.getElementById('currentProgram').textContent = 'Sin información de programa';
                            document.getElementById('programTime').textContent = '';
                            console.log('No se encontró información de EPG');
                        }
                    }
                })
                .catch(error => {
                    console.error('Error loading EPG:', error);
                    document.getElementById('currentProgram').textContent = 'Sin información';
                    document.getElementById('programTime').textContent = '';
                });
        }

        // Actualizar información del canal - VERSIÓN MEJORADA
        function updateChannelInfo() {
            // Actualizar nombre del canal
            document.getElementById('currentChannelName').textContent = currentChannelName;
            
            // Actualizar logo del canal
            if (currentChannelIcon) {
                const logoImg = document.getElementById('channelLogoImg');
                logoImg.src = currentChannelIcon;
                logoImg.style.display = 'block';
                logoImg.onerror = function() { this.style.display = 'none'; };
            }
            
            // Cargar información del EPG
            if (currentChannelId) {
                loadChannelEPG(currentChannelId);
                
                // Actualizar EPG cada 2 minutos para mantener la información fresca
                if (window.epgUpdateInterval) {
                    clearInterval(window.epgUpdateInterval);
                }
                
                window.epgUpdateInterval = setInterval(() => {
                    if (currentChannelId) {
                        loadChannelEPG(currentChannelId);
                        console.log('EPG actualizado automáticamente');
                    }
                }, 120000); // 2 minutos
            }
        }

        // Reproducir canal - VERSIÓN ACTUALIZADA
        function playChannel(channelId, channelNum, channelName, channelIcon) {
            currentChannelId = channelId;
            currentChannelName = channelName;
            currentChannelIcon = channelIcon;
            currentChannelIndex = channelNum - 1;

            console.log('Playing channel:', channelId, channelName);

            // Limpiar recursos del canal anterior
            cleanupChannelResources();

            // Cerrar selector
            closeChannelSelector();

            // Limpiar player anterior
            if (currentPlayer) {
                try {
                    currentPlayer.dispose();
                    currentPlayer = null;
                } catch(e) {
                    console.log('Error disposing player:', e);
                }
            }

            // Mostrar loading
            showLoading(`Cargando ${channelName}...`);

            // Cargar player
            const timestamp = new Date().getTime();
            fetch(`load_player.php?id=${channelId}&t=${timestamp}`)
                .then(response => {
                    if (!response.ok) throw new Error('Player load failed');
                    return response.text();
                })
                .then(html => {
                    document.getElementById('playerArea').innerHTML = html;
                    addCustomElements();
                    updateChannelInfo(); // Esta función ahora carga automáticamente el EPG
                    
                    setTimeout(() => {
                        initializePlayer(channelId);
                    }, 200);
                })
                .catch(error => {
                    console.error('Error loading player:', error);
                    showError('Error al cargar el canal');
                });
        }
// Añadir elementos personalizados después de cargar player
        function addCustomElements() {
            const controlsHTML = `
                <div class="player-controls" id="playerControls">
                    <button class="control-btn" onclick="toggleChannelSelector()" title="Selector de canales">
                        <i class="fas fa-list"></i>
                    </button>
                    <button class="control-btn" onclick="openEPG()" title="Guía de programas">
                        <i class="fas fa-calendar"></i>
                    </button>
                </div>
            `;
            
            const bannerHTML = `
                <div class="channel-banner" id="channelBanner">
                    <div class="banner-content">
                        <img id="channelLogoImg" class="channel-logo" src="" alt="Logo del canal" style="display: none;">
                        <div class="channel-info">
                            <div class="channel-name" id="currentChannelName">${currentChannelName}</div>
                            <div class="current-program" id="currentProgram">Cargando programa...</div>
                            <div class="program-time" id="programTime"></div>
                        </div>
                        <div class="live-indicator">
                            <div class="live-dot"></div>
                            EN VIVO
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('playerArea').insertAdjacentHTML('beforeend', controlsHTML);
            document.getElementById('playerArea').insertAdjacentHTML('beforeend', bannerHTML);
        }

        // Inicializar Video.js
        function initializePlayer(channelId) {
            if (typeof videojs === 'undefined') {
                setTimeout(() => initializePlayer(channelId), 100);
                return;
            }

            const playerId = 'player-' + channelId;
            const playerElement = document.getElementById(playerId);

            if (!playerElement) {
                console.error('Player element not found:', playerId);
                return;
            }

            try {
                currentPlayer = videojs(playerId, {
                    fluid: true,
                    responsive: true,
                    controls: true,
                    preload: 'auto',
                    autoplay: true,
                    muted: false,
                    playsinline: true,
                    liveui: true
                });

                currentPlayer.ready(function() {
                    console.log('Player ready for channel:', channelId);
                    
                    if (this.bigPlayButton) {
                        this.bigPlayButton.hide();
                    }
                    
                    const playPromise = this.play();
                    
                    if (playPromise !== undefined) {
                        playPromise.then(() => {
                            console.log('Autoplay successful');
                            hideLoading();
                        }).catch(error => {
                            console.log('Autoplay prevented:', error);
                            hideLoading();
                        });
                    }
                });

                currentPlayer.on('error', function() {
                    const error = this.error();
                    console.error('Player error:', error);
                    showError('Error de reproducción');
                });

            } catch (error) {
                console.error('Error initializing player:', error);
                showError('Error al inicializar reproductor');
            }
        }

        // Navegación de canales
        function nextChannel() {
            if (currentCategoryChannels.length === 0) return;
            
            const nextIndex = (currentChannelIndex + 1) % currentCategoryChannels.length;
            const nextChannel = currentCategoryChannels[nextIndex];
            playChannel(nextChannel.stream_id, nextIndex + 1, nextChannel.name, nextChannel.stream_icon || '');
        }

        function previousChannel() {
            if (currentCategoryChannels.length === 0) return;
            
            const prevIndex = currentChannelIndex > 0 ? currentChannelIndex - 1 : currentCategoryChannels.length - 1;
            const prevChannel = currentCategoryChannels[prevIndex];
            playChannel(prevChannel.stream_id, prevIndex + 1, prevChannel.name, prevChannel.stream_icon || '');
        }

        // Control del selector
        function showChannelSelector() {
            document.getElementById('channelSelector').classList.remove('hidden');
        }

        function closeChannelSelector() {
            document.getElementById('channelSelector').classList.add('hidden');
        }

        function toggleChannelSelector() {
            const selector = document.getElementById('channelSelector');
            if (selector.classList.contains('hidden')) {
                showChannelSelector();
            } else {
                closeChannelSelector();
            }
        }

        // Control de controles del reproductor
        function showPlayerControls() {
            const controls = document.getElementById('playerControls');
            if (controls) {
                controls.classList.add('show');
            }
        }

        function hidePlayerControls() {
            const controls = document.getElementById('playerControls');
            if (controls) {
                controls.classList.remove('show');
            }
        }

        // Control del banner
        function showChannelBanner() {
            const banner = document.getElementById('channelBanner');
            if (banner) {
                banner.classList.add('show');
            }
        }

        function hideChannelBanner() {
            const banner = document.getElementById('channelBanner');
            if (banner) {
                banner.classList.remove('show');
            }
        }

        // Abrir EPG - VERIFICAR que usa el canal correcto
        function openEPG() {
            console.log('=== OPENING EPG ===');
            console.log('Current Channel ID:', currentChannelId);
            console.log('Current Channel Name:', currentChannelName);
            console.log('==================');
            
            if (!currentChannelId) {
                console.log('No current channel ID');
                alert('Selecciona un canal primero');
                return;
            }
            
            // Buscar el overlay
            const epgOverlay = document.getElementById('epgOverlay');
            
            if (!epgOverlay) {
                console.error('EPG overlay element not found!');
                alert('Error: No se encontró el overlay del EPG');
                return;
            }
            
            // Mostrar overlay
            epgOverlay.classList.remove('hidden');
            console.log('EPG overlay shown for channel:', currentChannelId, currentChannelName);
            
            // Actualizar título con el canal CORRECTO
            const epgTitle = document.getElementById('epgTitle');
            if (epgTitle) {
                epgTitle.textContent = `Guía - ${currentChannelName} (ID: ${currentChannelId})`;
            }
            
            // Mostrar loading
            const epgContent = document.getElementById('epgContent');
            if (epgContent) {
                epgContent.innerHTML = `
                    <div style="text-align: center; padding: 50px; color: #fff;">
                        <div style="
                            width: 40px;
                            height: 40px;
                            border: 3px solid #333;
                            border-top: 3px solid #fff;
                            border-radius: 50%;
                            animation: spin 1s linear infinite;
                            margin: 0 auto 20px;
                        "></div>
                        Cargando guía para: ${currentChannelName}<br>
                        <small>ID: ${currentChannelId}</small>
                    </div>
                `;
                
                // Cargar EPG del canal CORRECTO
                const epgUrl = `load_epg.php?id=${currentChannelId}&t=${new Date().getTime()}`;
                console.log('Loading EPG from URL:', epgUrl);
                
                fetch(epgUrl)
                    .then(response => {
                        console.log('EPG response received:', response.status, response.url);
                        return response.text();
                    })
                    .then(html => {
                        console.log('EPG HTML loaded for channel:', currentChannelId);
                        console.log('EPG HTML preview:', html.substring(0, 200));
                        epgContent.innerHTML = html;
                    })
                    .catch(error => {
                        console.error('Error loading EPG:', error);
                        epgContent.innerHTML = `
                            <div style="text-align: center; padding: 50px; color: #ff4444;">
                                <i class="fas fa-exclamation-triangle" style="font-size: 2rem; margin-bottom: 15px;"></i><br>
                                Error al cargar EPG para: ${currentChannelName}<br>
                                <small>ID: ${currentChannelId}</small><br>
                                <small>${error.message}</small>
                            </div>
                        `;
                    });
            }
        }

        function closeEPGOverlay() {
            console.log('Closing EPG overlay');
            const epgOverlay = document.getElementById('epgOverlay');
            if (epgOverlay) {
                epgOverlay.classList.add('hidden');
                console.log('EPG overlay hidden');
            } else {
                console.error('EPG overlay not found when trying to close');
            }
        }

        function showEPGModal() {
            const modalHTML = `
                <div id="epgModal" style="
                    position: fixed;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background: rgba(0, 0, 0, 0.9);
                    z-index: 2000;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                ">
                    <div style="
                        background: rgba(0, 0, 0, 0.95);
                        border-radius: 15px;
                        width: 85%;
                        height: 85%;
                        padding: 20px;
                        position: relative;
                        overflow: hidden;
                        border: 1px solid rgba(255, 255, 255, 0.1);
                        backdrop-filter: blur(20px);
                    ">
                        <button onclick="closeEPGModal()" style="
                            position: absolute;
                            top: 20px;
                            right: 20px;
                            background: none;
                            border: none;
                            color: white;
                            font-size: 1.5rem;
                            cursor: pointer;
                            padding: 10px;
                            border-radius: 50%;
                            background: rgba(255, 255, 255, 0.1);
                        ">
                            <i class="fas fa-times"></i>
                        </button>
                        <div id="epgContent" style="
                            height: 100%;
                            overflow-y: auto;
                            padding-right: 20px;
                        ">
                            <div style="text-align: center; padding: 50px; color: #fff;">
                                <div style="
                                    width: 40px;
                                    height: 40px;
                                    border: 3px solid #333;
                                    border-top: 3px solid #fff;
                                    border-radius: 50%;
                                    animation: spin 1s linear infinite;
                                    margin: 0 auto 20px;
                                "></div>
                                Cargando guía de programas...
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            document.body.insertAdjacentHTML('beforeend', modalHTML);
            
            // Cargar EPG completo
            fetch(`load_epg.php?id=${currentChannelId}&t=${new Date().getTime()}`)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('epgContent').innerHTML = html;
                })
                .catch(error => {
                    console.error('Error loading EPG:', error);
                    document.getElementById('epgContent').innerHTML = `
                        <div style="text-align: center; padding: 50px; color: #ff4444;">
                            Error al cargar la guía de programas
                        </div>
                    `;
                });
        }

        function closeEPGModal() {
            const modal = document.getElementById('epgModal');
            if (modal) {
                modal.remove();
            }
        }

        // Estados de loading y error
        function showLoading(message) {
            document.getElementById('playerArea').innerHTML = `
                <div class="loading-state">
                    <div class="loading-spinner"></div>
                    <div style="font-size: 1.2rem; margin-top: 10px;">${message}</div>
                </div>
            `;
        }

        function hideLoading() {
            // Se oculta automáticamente cuando se carga el player
        }

        function showError(message) {
            document.getElementById('playerArea').innerHTML = `
                <div class="tv-placeholder">
                    <i class="fas fa-exclamation-triangle" style="color: #ff4444;"></i>
                    <h2 style="color: #ff4444;">Error</h2>
                    <p>${message}</p>
                    <button onclick="showChannelSelector()" style="
                        margin-top: 20px;
                        padding: 12px 24px;
                        background: rgba(255, 255, 255, 0.1);
                        color: white;
                        border: 1px solid rgba(255, 255, 255, 0.2);
                        border-radius: 8px;
                        cursor: pointer;
                        font-size: 1rem;
                        transition: all 0.3s;
                    " onmouseover="this.style.background='rgba(255,255,255,0.2)'" onmouseout="this.style.background='rgba(255,255,255,0.1)'">
                        Seleccionar otro canal
                    </button>
                </div>
            `;
        }

        // Eventos adicionales
        document.addEventListener('keydown', function(e) {
            // ESC para cerrar EPG overlay
            if (e.key === 'Escape') {
                const epgOverlay = document.getElementById('epgOverlay');
                if (epgOverlay && !epgOverlay.classList.contains('hidden')) {
                    closeEPGOverlay();
                    e.preventDefault();
                }
            }
        });

        // Limpiar recursos al salir - ACTUALIZADO
        window.addEventListener('beforeunload', function() {
            cleanupChannelResources();
            if (currentPlayer) {
                try {
                    currentPlayer.dispose();
                } catch(e) {}
            }
        });

        console.log('Smart TV interface ready - Versión final con EPG mejorado');
    </script>
</body>
</html>