<?php
session_start();

if (isset($_SESSION['user_logged'])) {
   $_SESSION['login_ok'] = true;

}

// Database setup for codes
$users_db_file = 'data/users.db';
$users_db = null;

try {
    $users_db = new PDO('sqlite:' . $users_db_file);
    $users_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    // Database connection error - will continue without code functionality
}

// Load servers
$servers = [];
if (file_exists('data/servers.json')) {
    $servers = json_decode(file_get_contents('data/servers.json'), true) ?: [];
}

$error = '';
$login_type = $_GET['type'] ?? 'select'; // select, credentials, code

// Handle form submissions
if ($_POST) {
    if (isset($_POST['login_type'])) {
        $login_type = $_POST['login_type'];
        
        if ($login_type === 'credentials') {
            $username = trim($_POST['username'] ?? '');
            $password = trim($_POST['password'] ?? '');
            
            if (!$username || !$password) {
                $error = 'All fields are required';
            } else {
                $authenticated = false;
                $working_server = null;
                
                foreach ($servers as $server) {
                    $url = rtrim($server['url'], '/') . '/player_api.php?' . http_build_query([
                        'username' => $username,
                        'password' => $password,
                        'action' => 'get_account_info'
                    ]);
                    
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                    curl_setopt($ch, CURLOPT_USERAGENT, 'XtremePlayer/1.0');
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
                    
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    
                    if ($response && $httpCode == 200) {
                        $data = json_decode($response, true);
                        if (isset($data['user_info']['auth']) && $data['user_info']['auth'] == 1) {
                            $authenticated = true;
                            $working_server = $server;
                            break;
                        }
                    }
                }
                
                if ($authenticated && $working_server) {
                    $_SESSION['user_logged'] = true;
                    $_SESSION['username'] = $username;
                    $_SESSION['password'] = $password;
                    $_SESSION['server_url'] = $working_server['url'];
                    $_SESSION['server_name'] = $working_server['name'];
                   $_SESSION['login_ok'] = true;
                } else {
                    $error = 'Incorrect username or password';
                }
            }
        } 
        elseif ($login_type === 'code') {
            $access_code = trim($_POST['access_code'] ?? '');
            
            if (!$access_code) {
                $error = 'Access code required';
            } elseif (!$users_db) {
                $error = 'Code system not available';
            } else {
                try {
                    $stmt = $users_db->prepare('SELECT * FROM users WHERE code = ? AND active = 1');
                    $stmt->execute([$access_code]);
                    $user = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$user) {
                        $error = 'Invalid or inactive access code';
                    } else {
                        $server_url = null;
                        foreach ($servers as $server) {
                            if ($server['id'] == $user['server_id']) {
                                $server_url = $server['url'];
                                $server_name = $server['name'];
                                break;
                            }
                        }
                        
                        if (!$server_url) {
                            $error = 'Server not found';
                        } else {
                            $api_url = rtrim($server_url, '/') . '/player_api.php?' . http_build_query([
                                'username' => $user['username'],
                                'password' => $user['password'],
                                'action' => 'get_account_info'
                            ]);
                            
                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $api_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                            curl_setopt($ch, CURLOPT_USERAGENT, 'XtremePlayer/1.0');
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
                            
                            $response = curl_exec($ch);
                            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            curl_close($ch);
                            
                            if ($response && $httpCode == 200) {
                                $data = json_decode($response, true);
                                if (isset($data['user_info']['auth']) && $data['user_info']['auth'] == 1) {
                                    $_SESSION['user_logged'] = true;
                                    $_SESSION['username'] = $user['username'];
                                    $_SESSION['password'] = $user['password'];
                                    $_SESSION['server_url'] = $server_url;
                                    $_SESSION['server_name'] = $server_name;
                                    $_SESSION['access_code'] = $access_code;
                                    $_SESSION['login_ok'] = true;
                                } else {
                                    $error = 'User expired or invalid on server';
                                }
                            } else {
                                $error = 'Connection error with IPTV server';
                            }
                        }
                    }
                } catch (Exception $e) {
                    $error = 'System error: ' . $e->getMessage();
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>The Titantv - Secure Access</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
   <link href="rgvipcss/index.css" rel="stylesheet">
</head>
<body>
    <div class="main-container">
        <!-- Login Section - Left -->
        <div class="login-section">
            <div class="login-container">
                <div class="logo-section">
                    <div class="logo-text">PRTV PREMIUM</div>
                </div>

                <!-- Selector Screen -->
                <div class="selector-screen" id="selectorScreen" <?= $login_type !== 'select' ? 'style="display: none;"' : '' ?>>
                    <div class="welcome-text">
                        <h2 class="welcome-title">BIENVENIDO</h2>
                        <p class="welcome-subtitle">INGRESE SUS DATOS DE PRTV PREMIUM</p>
                    </div>

                    <div class="login-options">
                        <div class="login-option" onclick="showLoginForm('credentials')">
                            <div class="option-content">
                                <div class="option-icon">
                                    <i class="fas fa-user-lock"></i>
                                </div>
                                <div class="option-text">
                                    <div class="option-title">USUARIO Y CONTRASEÑA</div>
                                    <div class="option-description">INGRESAR AQUI</div>
                                </div>
                                <div class="option-arrow">
                                    <i class="fas fa-chevron-right"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Credentials Login -->
                <div class="login-screen" id="credentialsScreen" <?= $login_type === 'credentials' ? 'style="display: block;"' : '' ?>>
                    <button class="back-button" onclick="showSelector()">
                        <i class="fas fa-arrow-left"></i> Atrás
                    </button>
                    
                    <div class="welcome-text">
                        <h2 class="welcome-title">BIENVENIDO A PRTV PREMIUM</h2>
                        <p class="welcome-subtitle">INGRESE SUS DATOS DE ACCESO</p>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="login_type" value="credentials">
                        
                        <div class="form-group">
                            <input type="text" name="username" class="form-input" placeholder="Username" required 
                                   value="<?= $login_type === 'credentials' ? htmlspecialchars($_POST['username'] ?? '') : '' ?>">
                        </div>
                        
                        <div class="form-group">
                            <input type="password" name="password" class="form-input" placeholder="Password" required>
                        </div>
                        
                        <button type="submit" class="submit-button">Iniciar Sesión</button>
                    </form>

                    <?php if ($error && $login_type === 'credentials'): ?>
                    <div class="error-message"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>
                </div>

                <!-- Code Login -->
                <div class="login-screen" id="codeScreen" <?= $login_type === 'code' ? 'style="display: block;"' : '' ?>>
                    <button class="back-button" onclick="showSelector()">
                        <i class="fas fa-arrow-left"></i> Atrás
                    </button>
                    
                </div>
            </div>
        </div>

        <!-- Hero Section - Right -->
        <div class="hero-section">
            <div class="hero-background" id="heroBackground"></div>
            <div class="hero-overlay"></div>
            
            <div class="hero-content">
                <div class="hero-header">
                    <div class="brand"></div>
                </div>
                
                <div class="hero-main">
                    <h1 class="hero-title" id="heroTitle">Cargando Contenido...</h1>
                    <p class="hero-subtitle" id="heroSubtitle">Descubre entretenimiento ilimitado en calidad 4K</p>
                </div>
                
                <div class="trending-section">
                    <div class="trending-header">
                        <h3 class="trending-title"></h3>
                    </div>
                    <div class="movies-slider">
                        <div class="movies-track" id="moviesTrack"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const TMDB_API_KEY = '6b8e3eaa1a03ebb45642e9531d8a76d2';
        const TMDB_BASE_URL = 'https://api.themoviedb.org/3';
        const IMAGE_BASE_URL = 'https://image.tmdb.org/t/p/w500';
        const BACKDROP_BASE_URL = 'https://image.tmdb.org/t/p/original';
        
        let currentMovieIndex = 0;
        let movies = [];
        
        async function loadTrendingMovies() {
            try {
                const response = await fetch(`${TMDB_BASE_URL}/trending/movie/week?api_key=${TMDB_API_KEY}&language=es-MX`);
                const data = await response.json();
                movies = data.results.slice(0, 12);
                
                displayMovies();
                startHeroRotation();
            } catch (error) {
                console.error('Error loading TMDB data:', error);
                loadFallbackData();
            }
        }
        
        function loadFallbackData() {
            movies = [
                {
                    title: "Avatar: The Way of Water",
                    backdrop_path: "/s16H6tpK2utvwDtzZ8Qy4qm5Emw.jpg",
                    poster_path: "/t6HIqrRAclMCA60NsSmeqe9RmNV.jpg",
                    vote_average: 8.2,
                    overview: "A new epic adventure in Pandora that will take the Sully family to explore new regions of the planet."
                },
                {
                    title: "Top Gun: Maverick",
                    backdrop_path: "/odJ4hx6g6vBt4lBWKFD1tI8WS4x.jpg",
                    poster_path: "/62HCnUTziyWcpDaBO2i1DX17ljH.jpg",
                    vote_average: 8.7,
                    overview: "Pete 'Maverick' Mitchell returns after more than thirty years of service as an elite aviator."
                },
                {
                    title: "Black Panther: Wakanda Forever",
                    backdrop_path: "/yYrvN5WFeGYjJnRzhY0QXuo4Isw.jpg",
                    poster_path: "/sv1xJUazXeYqALzczSZ3O6nkH75.jpg",
                    vote_average: 7.5,
                    overview: "Wakanda fights to protect their nation after the death of King T'Challa."
                },
                {
                    title: "The Batman",
                    backdrop_path: "/b0PlSFdDwbyK0cf5RxwDpaOJQvQ.jpg",
                    poster_path: "/74xTEgt7R36Fpooo50r9T25onhq.jpg",
                    vote_average: 8.1,
                    overview: "When a sadistic serial killer begins murdering key political figures in Gotham, Batman is forced to investigate the city's hidden corruption."
                },
                {
                    title: "Spider-Man: No Way Home",
                    backdrop_path: "/iQFcwSGbZXMkeyKrxbPnwnRo5fl.jpg",
                    poster_path: "/1g0dhYtq4irTY1GPXvft6k4YLjm.jpg",
                    vote_average: 8.4,
                    overview: "Peter Parker is unmasked and no longer able to separate his normal life from the high-stakes of being a super-hero."
                },
                {
                    title: "Dune",
                    backdrop_path: "/jYEW5xZkZk2WTrdbMGAPFuBqbDc.jpg",
                    poster_path: "/d5NXSklXo0qyIYkgV94XAgMIckC.jpg",
                    vote_average: 8.0,
                    overview: "Paul Atreides, a brilliant and gifted young man born into a great destiny beyond his understanding."
                },
                {
                    title: "Encanto",
                    backdrop_path: "/3G1Q5xF40HkUBJXxt2DQgQzKTp5.jpg",
                    poster_path: "/4j0PNHkMr5ax3IA8tjtxcmPU3QT.jpg",
                    vote_average: 7.2,
                    overview: "The tale of an extraordinary family, the Madrigals, who live hidden in the mountains of Colombia."
                },
                {
                    title: "The Matrix Resurrections",
                    backdrop_path: "/hv7o3VgfsairBoQFAawgaQ4cR1m.jpg",
                    poster_path: "/8c4a8kE7PizaGQQnditMmI1xbRp.jpg",
                    vote_average: 6.7,
                    overview: "Plagued by strange memories, Neo's life takes an unexpected turn when he finds himself back inside the Matrix."
                }
            ];
            displayMovies();
            startHeroRotation();
        }
        
        function displayMovies() {
            const track = document.getElementById('moviesTrack');
            track.innerHTML = '';
            
            const allMovies = [...movies, ...movies];
            
            allMovies.forEach((movie, index) => {
                const movieCard = document.createElement('div');
                movieCard.className = 'movie-card';
                movieCard.innerHTML = `
                    <img class="movie-poster" 
                         src="${IMAGE_BASE_URL}${movie.poster_path}" 
                         alt="${movie.title}" 
                         onerror="this.src='https://via.placeholder.com/300x450/1a252f/0088cc?text=No+Image'">
                    <div class="movie-overlay">
                        <div class="movie-title">${movie.title}</div>
                        <div class="movie-rating">★ ${movie.vote_average?.toFixed(1) || 'N/A'}</div>
                    </div>
                `;
                
                if (index < movies.length) {
                    movieCard.addEventListener('click', () => {
                        currentMovieIndex = index;
                        updateHeroContent();
                    });
                }
                
                track.appendChild(movieCard);
            });
            
            updateHeroContent();
        }
        
        function updateHeroContent() {
            if (movies.length === 0) return;
            
            const movie = movies[currentMovieIndex];
            const heroBackground = document.getElementById('heroBackground');
            const heroTitle = document.getElementById('heroTitle');
            const heroSubtitle = document.getElementById('heroSubtitle');
            
            heroBackground.style.backgroundImage = `url(${BACKDROP_BASE_URL}${movie.backdrop_path})`;
            heroTitle.textContent = movie.title;
            heroSubtitle.textContent = movie.overview || 'Discover unlimited entertainment in 4K quality';
        }
        
        function startHeroRotation() {
            setInterval(() => {
                currentMovieIndex = (currentMovieIndex + 1) % movies.length;
                updateHeroContent();
            }, 8000);
        }

        function showLoginForm(type) {
            document.getElementById('selectorScreen').style.display = 'none';
            document.getElementById('credentialsScreen').style.display = 'none';
            document.getElementById('codeScreen').style.display = 'none';
            
            if (type === 'credentials') {
                document.getElementById('credentialsScreen').style.display = 'block';
                setTimeout(() => {
                    const usernameInput = document.querySelector('input[name="username"]');
                    if (usernameInput) usernameInput.focus();
                }, 100);
            } else if (type === 'code') {
                document.getElementById('codeScreen').style.display = 'block';
                setTimeout(() => {
                    const codeInput = document.querySelector('input[name="access_code"]');
                    if (codeInput) codeInput.focus();
                }, 100);
            }
            
            // Update URL without redirect
            const url = new URL(window.location);
            url.searchParams.set('type', type);
            window.history.pushState({}, '', url);
        }

        function showSelector() {
            document.getElementById('selectorScreen').style.display = 'block';
            document.getElementById('credentialsScreen').style.display = 'none';
            document.getElementById('codeScreen').style.display = 'none';
            
            // Update URL without redirect
            const url = new URL(window.location);
            url.searchParams.delete('type');
            window.history.pushState({}, '', url);
        }

        // Code input formatting
        document.addEventListener('DOMContentLoaded', function() {
            const codeInput = document.querySelector('input[name="access_code"]');
            if (codeInput) {
                codeInput.addEventListener('input', function(e) {
                    e.target.value = e.target.value.replace(/\D/g, '');
                    if (e.target.value.length > 6) {
                        e.target.value = e.target.value.slice(0, 6);
                    }
                });
            }

            // Initialize TMDB loading
            loadTrendingMovies();
        });
    </script>
   <?php if (isset($_SESSION['login_ok']) && $_SESSION['login_ok']): ?>
    <div id="loading" style="position:fixed;top:0;left:0;width:100vw;height:100vh;background:#000000dd;display:flex;justify-content:center;align-items:center;z-index:9999;">
        <div style="text-align:center;">
            <img src="img/logo.png" alt="Logo" style="width:250px;margin-bottom:20px;">
            <div class="spinner" style="border: 6px solid #f3f3f3;border-top: 6px solid #3498db;border-radius: 50%;width: 60px;height: 60px;animation: spin 1s linear infinite;margin:0 auto;"></div>
            <p style="color:white;margin-top:15px;">Cargando contenido...</p>
        </div>
    </div>

    <script>
        setTimeout(function() {
            window.location.href = "dashboard.php";
        }, 2000);
    </script>

    <style>
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>

    <?php unset($_SESSION['login_ok']); ?>
<?php endif; ?>


</body>
</html>