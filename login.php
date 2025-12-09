<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Intro</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        html, body {
            margin: 0;
            padding: 0;
            height: 100%;
            background: black;
            overflow: hidden;
        }

        body {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        video {
            position: absolute;
            top: 0;
            left: 0;
            width: 100vw;
            height: 100vh;
            object-fit: cover;
        }

        .skip-btn, .unmute-btn {
            position: absolute;
            z-index: 100;
            background: rgba(0, 0, 0, 0.6);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
        }

        .skip-btn {
            bottom: 20px;
            right: 20px;
        }

        .unmute-btn {
            bottom: 20px;
            left: 20px;
        }

        @media (orientation: portrait) {
            .skip-btn, .unmute-btn {
                padding: 10px 16px;
                font-size: 14px;
            }
        }
    </style>
</head>
<body>
    <video id="introVideo" autoplay playsinline>
        <source src="splash_rgvip/intro.mp4?v=<?php echo filemtime('splash_rgvip/intro.mp4'); ?>" type="video/mp4">
        Tu navegador no soporta el video HTML5.
    </video>

    <button class="skip-btn" onclick="skipIntro()">Saltar Intro</button>
    <button class="unmute-btn" onclick="toggleMute()" id="muteBtn">Silenciar</button>

    <script>
        const video = document.getElementById('introVideo');
        const muteBtn = document.getElementById('muteBtn');

        video.muted = true;
        muteBtn.textContent = 'Activar Sonido';

        video.onended = () => window.location.href = "index.php";

        function skipIntro() {
            video.pause();
            window.location.href = "index.php";
        }

        function toggleMute() {
            video.muted = !video.muted;
            muteBtn.textContent = video.muted ? 'Activar Sonido' : 'Silenciar';
        }

        document.addEventListener('click', function () {
            if (video.muted) {
                video.muted = false;
                video.play();
                muteBtn.textContent = 'Silenciar';
            }
        }, { once: true });

        document.addEventListener('DOMContentLoaded', function () {
            video.play().catch(e => {
                const playBtn = document.createElement('button');
                playBtn.textContent = 'Reproducir Video';
                Object.assign(playBtn.style, {
                    position: 'absolute',
                    top: '50%',
                    left: '50%',
                    transform: 'translate(-50%, -50%)',
                    zIndex: 100,
                    padding: '15px 30px',
                    fontSize: '18px'
                });
                playBtn.onclick = function () {
                    video.play();
                    video.muted = false;
                    muteBtn.textContent = 'Silenciar';
                    this.remove();
                };
                document.body.appendChild(playBtn);
            });
        });
    </script>
</body>
</html>
