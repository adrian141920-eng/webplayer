<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_logged'])) {
    header('Location: index.php');
    exit;
}

$username = $_SESSION['username'];
$password = $_SESSION['password'] ?? '';
$server_url = $_SESSION['server_url'] ?? '';
$server_name = $_SESSION['server_name'];

function getAccountExpiration($username, $password, $server_url) {
    $cache_file = 'cache/account_' . md5($username . $server_url) . '.json';
    
    if (file_exists($cache_file) && (time() - filemtime($cache_file) < 21600)) {
        $data = json_decode(file_get_contents($cache_file), true);
        return $data['exp_date'] ?? null;
    }
    
    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36';
    $url = rtrim($server_url, '/') . '/player_api.php?username=' . urlencode($username) . '&password=' . urlencode($password);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($response && $http_code == 200) {
        $data = json_decode($response, true);
        $exp_date = $data['user_info']['exp_date'] ?? null;
        
        if (!is_dir('cache')) {
            mkdir('cache', 0777, true);
        }
        file_put_contents($cache_file, json_encode(['exp_date' => $exp_date, 'cached_at' => time()]));
        
        return $exp_date;
    }
    
    return null;
}

function checkExpirationWarning($exp_date) {
    if (empty($exp_date) || $exp_date == 0) {
        return false;
    }
    
    $expiration = new DateTime();
    $expiration->setTimestamp($exp_date);
    $now = new DateTime();
    $diff = $now->diff($expiration);
    
    $totalHours = ($diff->days * 24) + $diff->h;
    
    if ($totalHours <= 48 && $expiration > $now) {
        return [
            'show' => true,
            'days' => $diff->days,
            'hours' => $diff->h,
            'total_hours' => $totalHours,
            'is_critical' => $totalHours <= 24
        ];
    }
    
    return false;
}

$exp_date = getAccountExpiration($username, $password, $server_url);
$expiration_warning = checkExpirationWarning($exp_date);
?>
<link rel="stylesheet" href="rgvipcss/header-styles.css">

<!-- Botón Toggle visible en PC y Móvil -->
<button class="mobile-toggle" onclick="toggleSidebar()">☰</button>

<!-- Sidebar -->
<nav class="sidebar collapsed" id="sidebar">
    <!-- Brand Section -->
    <div class="brand-section">
        <a href="dashboard.php" class="brand">
            <div class="brand-logo">
                <i class="fas fa-tv"></i>
            </div>
            <div>
                <div class="brand-tex>PRTV PREMIUM</div>
                <div class="brand-subtitle">PRTV PREMIUM</div>
            </div>
        </a>
    </div>

    <!-- Search Section -->
    <div class="search-section">
        <div class="search-container">
            <form action="search.php" method="GET">
                <i class="fas fa-search search-icon" id="searchIcon"></i>
                <i class="fas fa-spinner search-spinner" id="searchSpinner" style="display: none;"></i>
                <input type="text" name="q" class="search-box" placeholder="Buscar contenido..." 
                       value="<?= isset($_GET['q']) ? htmlspecialchars($_GET['q']) : '' ?>" 
                       minlength="2" id="searchInput">
            </form>
        </div>
    </div>

    <!-- Main Menu -->
    <div class="menu-section">
        <h3 class="menu-title">Principal</h3>
        <ul class="menu-list">
            <li class="menu-item">
                <a href="dashboard.php" class="menu-link">
                    <div class="menu-icon">
                        <i class="fas fa-home"></i>
                    </div>
                    <span>Inicio</span>
                </a>
            </li>
            <li class="menu-item">
                <a href="movies.php" class="menu-link">
                    <div class="menu-icon">
                        <i class="fas fa-film"></i>
                    </div>
                    <span>Películas</span>
                </a>
            </li>
            <li class="menu-item">
                <a href="series.php" class="menu-link">
                    <div class="menu-icon">
                        <i class="fas fa-tv"></i>
                    </div>
                    <span>Series</span>
                </a>
            </li>
        </ul>
    </div>

    <!-- Live TV Section -->
    <div class="menu-section">
        <h3 class="menu-title">En Vivo</h3>
        <ul class="menu-list">
            <li class="menu-item">
                <a href="livetv.php" class="menu-link">
                    <div class="menu-icon">
                        <i class="fas fa-broadcast-tower"></i>
                    </div>
                    <span>TV en Vivo</span>
                    <span class="menu-badge badge-live">Live</span>
                </a>
            </li>
            <li class="menu-item">
                <a href="multiscreen.php" class="menu-link">
                    <div class="menu-icon">
                        <i class="fas fa-th-large"></i>
                    </div>
                    <span>Multiscreen</span>
                    <span class="menu-badge badge-new">New</span>
                </a>
            </li>
            <li class="menu-item">
                <a href="sports.php" class="menu-link">
                    <div class="menu-icon">
                        <i class="fas fa-futbol"></i>
                    </div>
                    <span>Deportes</span>
                </a>
            </li>
        </ul>
    </div>

    <!-- Account Section -->
    <div class="menu-section">
        <h3 class="menu-title">Mi Cuenta</h3>
        <ul class="menu-list">
            <li class="menu-item">
                <a href="settings.php" class="menu-link">
                    <div class="menu-icon">
                        <i class="fas fa-cog"></i>
                    </div>
                    <span>Configuración</span>
                </a>
            </li>
        </ul>
    </div>

    <!-- User Section -->
    <div class="user-section">
        <!-- Server Info -->
        <div class="server-info">
            <div class="server-name">
                <i class="fas fa-server"></i>
                <span><?= htmlspecialchars($server_name) ?></span>
            </div>
        </div>

        <!-- Expiration Warning -->
        <?php if ($expiration_warning): ?>
        <div class="expiration-alert <?= $expiration_warning['is_critical'] ? 'critical' : '' ?>">
            <i class="fas fa-<?= $expiration_warning['is_critical'] ? 'exclamation-triangle' : 'clock' ?>"></i>
            <div class="expiration-text">
                Expira en: <?= $expiration_warning['days'] ?>d <?= $expiration_warning['hours'] ?>h
            </div>
        </div>
        <?php endif; ?>

        <!-- User Profile -->
        <div class="user-profile">
            <div class="user-avatar">
                <?= strtoupper(substr($username, 0, 1)) ?>
            </div>
            <div class="user-info">
                <div class="username"><?= htmlspecialchars($username) ?></div>
                <div class="user-status">
                    <span class="status-dot"></span>
                    <span>En línea</span>
                </div>
            </div>
            <a href="logout.php" class="logout-btn" title="Cerrar Sesión">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </div>
</nav>

<!-- Main Content Area -->
<div class="main-content">
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Activar item actual
    const currentPage = window.location.pathname.split('/').pop();
    const menuLinks = document.querySelectorAll('.menu-link');
    
    menuLinks.forEach(link => {
        const linkPage = link.getAttribute('href');
        if (linkPage === currentPage || (currentPage === '' && linkPage === 'dashboard.php')) {
            link.classList.add('active');
        }
    });

    // Funcionalidad de búsqueda
    const searchInput = document.querySelector('#searchInput');
    const searchIcon = document.querySelector('#searchIcon');
    const searchSpinner = document.querySelector('#searchSpinner');
    let searchTimeout;

    function showSearchLoading() {
        if (searchIcon && searchSpinner && searchInput) {
            searchIcon.style.display = 'none';
            searchSpinner.style.display = 'block';
        }
    }

    function hideSearchLoading() {
        if (searchIcon && searchSpinner && searchInput) {
            searchIcon.style.display = 'block';
            searchSpinner.style.display = 'none';
        }
    }

    if (searchInput) {
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim();
            
            if (query.length >= 2) {
                showSearchLoading();
                searchTimeout = setTimeout(() => {
                    this.form.submit();
                }, 500);
            } else if (query.length === 0) {
                hideSearchLoading();
                window.location.href = 'dashboard.php';
            } else {
                hideSearchLoading();
            }
        });

        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                if (this.value.trim().length >= 2) {
                    showSearchLoading();
                    this.form.submit();
                }
            }
        });

        searchInput.closest('form').addEventListener('submit', function() {
            showSearchLoading();
        });
    }

    // Cerrar sidebar al hacer clic fuera (en móvil)
    const sidebar = document.getElementById('sidebar');
    const mobileToggle = document.querySelector('.mobile-toggle');

    document.addEventListener('click', function(e) {
        if (window.innerWidth <= 768 && sidebar.classList.contains('open')) {
            if (!sidebar.contains(e.target) && !mobileToggle.contains(e.target)) {
                sidebar.classList.remove('open');
            }
        }
    });
});

// Alternar visibilidad del sidebar
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.querySelector('.main-content');

    sidebar.classList.toggle('open');
    sidebar.classList.toggle('collapsed');
    mainContent.classList.toggle('collapsed');
}
</script>
