<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/csrf.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/mark.php';

auth_boot();

$currentUser = auth_user();
if ($currentUser !== null) {
    redirect_to(auth_dashboard_for($currentUser['role']));
}

$error = '';
$loginValue = '';
if (
    $_SERVER['REQUEST_METHOD'] !== 'POST'
    && isset($_GET['idle'])
    && (string) $_GET['idle'] === '1'
) {
    $error = 'You were signed out after a period of inactivity. Sign in again to continue.';
}
$setupNotice = '';
if (
    $_SERVER['REQUEST_METHOD'] !== 'POST'
    && isset($_GET['setup'])
    && (string) $_GET['setup'] === '1'
) {
    $setupNotice = 'Your password is set. Sign in with your username and the password you chose.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginRaw = $_POST['login'] ?? '';
    $password = $_POST['password'] ?? '';
    $token = $_POST['csrf_token'] ?? null;
    $remember = isset($_POST['remember']) && (string) $_POST['remember'] === '1';

    $loginValue = is_string($loginRaw) ? trim($loginRaw) : '';
    if (strlen($loginValue) > 255) {
        $loginValue = substr($loginValue, 0, 255);
    }

    if (!is_string($token) || !csrf_verify($token)) {
        $error = 'Your session has expired. Refresh the page and try again.';
    } elseif (!is_string($password)) {
        $error = 'Enter a valid username or email and your password.';
    } else {
        $result = auth_attempt($loginValue, $password, $remember);
        if ($result['ok'] === true) {
            redirect_to(auth_dashboard_for($result['role']));
        }
        $error = $result['error'];
    }
}

$nonce = app_csp_nonce();
$slides = [
    ['src' => 'assets/login-poster.jpg', 'tone' => 'green', 'alt' => 'Campaign poster for Hon. Uju Kingsley Chima (Omeudo), Action Peoples Party, 2027, Federal House of Representatives, Ohaji/Egbema, Oguta and Oru West Federal Constituency.'],
    ['src' => 'assets/login-slides/omeudo-obejiri-factor.jpg', 'tone' => 'red', 'alt' => 'Omeudo, Obejiri Factor: Hon. Uju Kingsley Chima seated in front of the Action Peoples Party logo.'],
    ['src' => 'assets/login-slides/a-new-vision.jpg', 'tone' => 'red', 'alt' => 'A New Vision: vote Hon. Uju Kingsley Chima (Omeudo), Action Peoples Party, for Federal House of Representatives, Ohaji/Egbema, Oguta and Oru West Federal Constituency.'],
    ['src' => 'assets/login-slides/perfect-alliance.jpg', 'tone' => 'red', 'alt' => 'Perfect Alliance, Choice beyond party: Hon. Uju Kingsley Chima giving a thumbs up in front of the Action Peoples Party logo.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#070b16">
    <title>Sign in | Election Monitoring Portal</title>
    <link rel="icon" href="<?= e(portal_favicon_href()) ?>" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700&display=swap" rel="stylesheet">
    <style nonce="<?= e($nonce) ?>">
        :root {
            --navy: #070b16;
            --glass: rgba(17, 26, 51, 0.78);
            --line: rgba(255, 255, 255, 0.08);
            --text: #e8edf8;
            --muted: #8b95a8;
            --gold: #fbfe06;
            --danger: #fecaca;
            --danger-bg: rgba(239, 68, 68, 0.12);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            color: var(--text);
            background:
                radial-gradient(circle at top left, rgba(251, 254, 6, 0.08), transparent 28%),
                var(--navy);
            font-family: Inter, "Segoe UI", sans-serif;
        }
        .login-shell {
            min-height: 100vh;
            display: grid;
            grid-template-columns: minmax(0, 0.9fr) minmax(320px, 1fr);
        }
        .login-brand {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 14px;
            padding: 28px;
            background: #063d12;
            border-right: 1px solid var(--line);
            transition: background-color 0.6s ease;
        }
        .login-brand[data-tone="red"] { background: #5a0b0b; }
        .login-slider {
            position: relative;
            width: min(100%, 520px, calc((100vh - 120px) * 0.71));
            aspect-ratio: 723 / 1024;
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 24px 64px rgba(0, 0, 0, 0.35);
            touch-action: pan-y;
        }
        .login-slide {
            position: absolute;
            inset: 0;
            margin: 0;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.6s ease, visibility 0s linear 0.6s;
        }
        .login-slide.is-active {
            opacity: 1;
            visibility: visible;
            transition: opacity 0.6s ease;
        }
        .login-slide img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        .slider-arrow {
            position: absolute;
            top: 50%;
            width: 42px;
            height: 42px;
            margin-top: -21px;
            border: 0;
            border-radius: 50%;
            background: rgba(7, 11, 22, 0.55);
            color: #fff;
            font-size: 1.6rem;
            line-height: 1;
            cursor: pointer;
        }
        .slider-arrow:hover { background: rgba(7, 11, 22, 0.8); }
        .slider-arrow--prev { left: 10px; }
        .slider-arrow--next { right: 10px; }
        .slider-controls {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .slider-dots { display: flex; gap: 8px; }
        .slider-dot {
            width: 12px;
            height: 12px;
            padding: 0;
            border: 2px solid rgba(255, 255, 255, 0.7);
            border-radius: 50%;
            background: transparent;
            cursor: pointer;
        }
        .slider-dot[aria-current="true"] { background: var(--gold); border-color: var(--gold); }
        .slider-pause {
            min-height: 32px;
            padding: 0 12px;
            border: 1px solid rgba(255, 255, 255, 0.35);
            border-radius: 999px;
            background: rgba(7, 11, 22, 0.35);
            color: #fff;
            font: inherit;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
        }
        [hidden] { display: none !important; }
        .login-main {
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 40px 28px;
        }
        .login-card {
            width: min(100%, 440px);
            margin: 0 auto;
            padding: 36px 32px;
            background: var(--glass);
            border: 1px solid var(--line);
            border-top: 3px solid var(--gold);
            border-radius: 22px;
            box-shadow: 0 24px 64px rgba(0, 0, 0, 0.35);
        }
        .login-card h2 {
            margin: 18px 0 6px;
            font-family: Poppins, Inter, sans-serif;
            font-size: 1.8rem;
        }
        .login-lead, .remember-note, .login-footer { color: var(--muted); }
        .login-lead { margin: 0 0 22px; }
        .login-alert {
            display: flex;
            gap: 10px;
            margin-bottom: 16px;
            padding: 12px 14px;
            color: var(--danger);
            background: var(--danger-bg);
            border: 1px solid rgba(239, 68, 68, 0.35);
            border-radius: 12px;
        }
        .login-alert:focus { outline: 3px solid rgba(251, 254, 6, 0.35); }
        label { display: block; margin-bottom: 6px; font-size: 0.88rem; font-weight: 600; }
        .field { margin-bottom: 16px; }
        input[type="text"], input[type="password"] {
            width: 100%;
            min-height: 48px;
            padding: 0 14px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: rgba(0, 0, 0, 0.25);
            color: var(--text);
            font: inherit;
            font-size: 16px;
        }
        input:focus {
            outline: none;
            border-color: rgba(251, 254, 6, 0.55);
            box-shadow: 0 0 0 3px rgba(251, 254, 6, 0.18);
        }
        .password-field { position: relative; }
        .password-field input { padding-right: 84px; }
        .password-toggle {
            position: absolute;
            top: 6px;
            right: 6px;
            height: 36px;
            padding: 0 10px;
            border: 0;
            border-radius: 9px;
            background: transparent;
            color: var(--text);
            font-weight: 700;
        }
        .password-toggle:hover, .password-toggle:focus-visible { background: rgba(255, 255, 255, 0.06); }
        .remember { display: flex; gap: 10px; align-items: flex-start; margin: 4px 0 18px; }
        .remember input { width: 1.05rem; height: 1.05rem; margin-top: 0.2rem; accent-color: #1d6b45; }
        .remember label { margin: 0; font-weight: 500; }
        .remember-note { margin: 4px 0 0; font-size: 0.8rem; }
        .sign-in {
            display: flex;
            width: 100%;
            min-height: 48px;
            align-items: center;
            justify-content: center;
            gap: 8px;
            border: 0;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--gold), #c9cc05);
            color: #111;
            font: inherit;
            font-weight: 800;
            cursor: pointer;
        }
        .sign-in:disabled { opacity: 0.75; }
        .login-footer { width: min(100%, 440px); margin: 16px auto 0; text-align: center; font-size: 0.86rem; }
        button:focus-visible, a:focus-visible, input:focus-visible { outline: 3px solid rgba(251, 254, 6, 0.35); outline-offset: 2px; }
        @media (max-width: 860px) {
            .login-shell { grid-template-columns: 1fr; }
            .login-brand, .login-brand[data-tone="red"] { padding: 16px 16px 0; border-right: 0; background: var(--navy); }
            .login-slider { width: min(100%, 360px, calc(52vh * 0.71)); }
            .login-main { padding: 20px 16px 32px; }
            .login-card { padding: 24px 18px; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, .login-slide, .login-slide.is-active { transition: none !important; }
        }
    </style>
</head>
<body>
    <div class="login-shell">
        <section class="login-brand" id="login-brand" data-tone="<?= e($slides[0]['tone']) ?>" aria-roledescription="carousel" aria-label="Campaign posters">
            <div class="login-slider" id="login-slider">
                <?php foreach ($slides as $index => $slide): ?>
                <figure class="login-slide<?= $index === 0 ? ' is-active' : '' ?>" data-tone="<?= e($slide['tone']) ?>" role="group" aria-roledescription="slide" aria-label="<?= e(($index + 1) . ' of ' . count($slides)) ?>"<?= $index === 0 ? '' : ' aria-hidden="true"' ?>>
                    <img src="<?= e(asset_url($slide['src'])) ?>" alt="<?= e($slide['alt']) ?>"<?= $index === 0 ? ' fetchpriority="high"' : '' ?> decoding="async">
                </figure>
                <?php endforeach; ?>
                <button type="button" class="slider-arrow slider-arrow--prev" id="slider-prev" aria-label="Previous poster" hidden>&#8249;</button>
                <button type="button" class="slider-arrow slider-arrow--next" id="slider-next" aria-label="Next poster" hidden>&#8250;</button>
            </div>
            <div class="slider-controls" id="slider-controls" hidden>
                <button type="button" class="slider-pause" id="slider-pause" aria-pressed="false">Pause</button>
                <div class="slider-dots">
                    <?php foreach ($slides as $index => $slide): ?>
                    <button type="button" class="slider-dot" data-slide="<?= e((string) $index) ?>" aria-label="Show poster <?= e((string) ($index + 1)) ?>"<?= $index === 0 ? ' aria-current="true"' : '' ?>></button>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
        <main class="login-main">
            <section class="login-card" aria-labelledby="signin-heading">
                <?php portal_mark_svg(52); ?>
                <h2 id="signin-heading">Sign in</h2>
                <p class="login-lead">Use the username or email issued to you.</p>
                <?php if ($setupNotice !== '' && $error === ''): ?>
                    <div class="login-alert" role="status"><?= e($setupNotice) ?></div>
                <?php endif; ?>
                <?php if ($error !== ''): ?>
                    <div class="login-alert" id="login-error" role="alert" tabindex="-1"><?= e($error) ?></div>
                <?php endif; ?>
                <form id="login-form" method="post" action="<?= e(url('portal/login.php')) ?>">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <div class="field">
                        <label for="login">Username or email</label>
                        <input id="login" name="login" type="text" value="<?= e($loginValue) ?>" maxlength="255" required autocomplete="username" autocapitalize="none" autocorrect="off" spellcheck="false" <?= $error !== '' ? 'aria-invalid="true" aria-describedby="login-error"' : '' ?>>
                    </div>
                    <div class="field">
                        <label for="password">Password</label>
                        <div class="password-field">
                            <input id="password" name="password" type="password" maxlength="128" required autocomplete="current-password" <?= $error !== '' ? 'aria-invalid="true" aria-describedby="login-error"' : '' ?>>
                            <button type="button" class="password-toggle" id="toggle-password" aria-pressed="false" aria-label="Show password">Show</button>
                        </div>
                    </div>
                    <div class="remember">
                        <input id="remember" name="remember" type="checkbox" value="1">
                        <div>
                            <label for="remember">Keep me signed in on this device</label>
                            <p class="remember-note">This keeps a signed-in session for 30 days on this browser.</p>
                        </div>
                    </div>
                    <button type="submit" class="sign-in" id="sign-in-button">Sign in</button>
                </form>
            </section>
            <p class="login-footer">Access is restricted to authorized personnel.</p>
        </main>
    </div>
    <script nonce="<?= e($nonce) ?>">
        (function () {
            var brand = document.getElementById('login-brand');
            var slider = document.getElementById('login-slider');
            if (!brand || !slider) return;
            var slides = slider.querySelectorAll('.login-slide');
            if (slides.length < 2) return;
            var dots = brand.querySelectorAll('.slider-dot');
            var pause = document.getElementById('slider-pause');
            var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            var current = 0;
            var timer = null;
            var paused = reduceMotion;
            var hovering = false;

            function show(index) {
                current = (index + slides.length) % slides.length;
                for (var i = 0; i < slides.length; i++) {
                    var active = i === current;
                    slides[i].classList.toggle('is-active', active);
                    if (active) {
                        slides[i].removeAttribute('aria-hidden');
                    } else {
                        slides[i].setAttribute('aria-hidden', 'true');
                    }
                    if (dots[i]) {
                        if (active) {
                            dots[i].setAttribute('aria-current', 'true');
                        } else {
                            dots[i].removeAttribute('aria-current');
                        }
                    }
                }
                brand.setAttribute('data-tone', slides[current].getAttribute('data-tone') || 'green');
            }
            function stop() {
                if (timer !== null) {
                    window.clearInterval(timer);
                    timer = null;
                }
            }
            function start() {
                stop();
                if (paused || hovering || document.hidden) return;
                timer = window.setInterval(function () { show(current + 1); }, 5000);
            }
            function setPaused(value) {
                paused = value;
                pause.textContent = paused ? 'Play' : 'Pause';
                pause.setAttribute('aria-pressed', paused ? 'true' : 'false');
                start();
            }

            document.getElementById('slider-prev').addEventListener('click', function () { show(current - 1); start(); });
            document.getElementById('slider-next').addEventListener('click', function () { show(current + 1); start(); });
            for (var d = 0; d < dots.length; d++) {
                dots[d].addEventListener('click', function () {
                    show(parseInt(this.getAttribute('data-slide'), 10) || 0);
                    start();
                });
            }
            pause.addEventListener('click', function () { setPaused(!paused); });
            brand.addEventListener('mouseenter', function () { hovering = true; stop(); });
            brand.addEventListener('mouseleave', function () { hovering = false; start(); });
            brand.addEventListener('focusin', function () { hovering = true; stop(); });
            brand.addEventListener('focusout', function () { hovering = false; start(); });
            document.addEventListener('visibilitychange', start);

            var touchX = null;
            slider.addEventListener('touchstart', function (event) {
                touchX = event.touches.length === 1 ? event.touches[0].clientX : null;
            }, { passive: true });
            slider.addEventListener('touchend', function (event) {
                if (touchX === null || event.changedTouches.length !== 1) return;
                var delta = event.changedTouches[0].clientX - touchX;
                touchX = null;
                if (Math.abs(delta) < 40) return;
                show(delta < 0 ? current + 1 : current - 1);
                start();
            }, { passive: true });

            document.getElementById('slider-prev').hidden = false;
            document.getElementById('slider-next').hidden = false;
            document.getElementById('slider-controls').hidden = false;
            setPaused(paused);
        })();
        (function () {
            var form = document.getElementById('login-form');
            var password = document.getElementById('password');
            var toggle = document.getElementById('toggle-password');
            var error = document.getElementById('login-error');
            if (error) error.focus();
            if (toggle && password) {
                toggle.addEventListener('click', function () {
                    var visible = password.type === 'text';
                    password.type = visible ? 'password' : 'text';
                    toggle.textContent = visible ? 'Show' : 'Hide';
                    toggle.setAttribute('aria-pressed', visible ? 'false' : 'true');
                    toggle.setAttribute('aria-label', visible ? 'Show password' : 'Hide password');
                    password.focus();
                });
            }
            if (!form) return;
            form.addEventListener('submit', function () {
                if (typeof form.reportValidity === 'function' && !form.reportValidity()) return;
                var button = document.getElementById('sign-in-button');
                if (!button) return;
                window.setTimeout(function () {
                    button.disabled = true;
                    button.setAttribute('aria-busy', 'true');
                    button.textContent = 'Signing in';
                }, 0);
            });
        })();
    </script>
</body>
</html>
