<?php
declare(strict_types=1);

/**
 * Maintenance Mode Page Template
 *
 * Standalone PHP template that renders the maintenance page.
 * Uses minimal styling with black, white, and gray colors.
 *
 * @var array $config Configuration array with title, message, logo, etc.
 * @var string $basePath Base path for URLs
 */

// Prevent direct access
if (!isset($config)) {
    http_response_code(503);
    exit('Maintenance');
}

$title = htmlspecialchars($config['title'] ?? 'Site Under Construction', ENT_QUOTES, 'UTF-8');
$hasCustomTitle = $config['has_custom_title'] ?? false;
$message = htmlspecialchars($config['message'] ?? 'We will be back soon.', ENT_QUOTES, 'UTF-8');
$siteTitle = htmlspecialchars($config['site_title'] ?? 'Cimaise', ENT_QUOTES, 'UTF-8');
$siteLogo = $config['site_logo'] ?? null;
$showLogo = $config['show_logo'] ?? true;
$showCountdown = $config['show_countdown'] ?? true;
$basePath ??= '';

// Set response headers
http_response_code(503);
header('Retry-After: 3600');
header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="<?= $siteTitle ?> - <?= $title ?>">
    <title><?= $title ?> | <?= $siteTitle ?></title>

    <!-- Open Graph / SEO -->
    <meta property="og:title" content="<?= $title ?> | <?= $siteTitle ?>">
    <meta property="og:description" content="<?= $message ?>">
    <meta property="og:type" content="website">

    <!-- Favicon -->
    <link rel="icon" href="<?= $basePath ?>/favicon.ico" type="image/x-icon">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background-color: #ffffff;
            color: #1a1a1a;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 2rem;
            line-height: 1.6;
        }

        .container {
            max-width: 600px;
            width: 100%;
            text-align: center;
        }

        .logo {
            margin-bottom: 2.5rem;
        }

        .logo img {
            max-width: 200px;
            max-height: 80px;
            width: auto;
            height: auto;
        }

        .logo-text {
            font-size: 2rem;
            font-weight: 700;
            color: #1a1a1a;
            letter-spacing: -0.025em;
        }

        .title {
            font-size: 2.5rem;
            font-weight: 700;
            color: #1a1a1a;
            margin-bottom: 1.5rem;
            letter-spacing: -0.025em;
        }

        .subtitle {
            font-size: 1.25rem;
            font-weight: 500;
            color: #4b5563;
            margin-bottom: 1.5rem;
            letter-spacing: -0.01em;
        }

        .message {
            font-size: 1.125rem;
            color: #6b7280;
            margin-bottom: 3rem;
            max-width: 480px;
            margin-left: auto;
            margin-right: auto;
        }

        /* Loading Animation */
        .progress-container {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 2rem;
        }

        .dot {
            width: 10px;
            height: 10px;
            background-color: #9ca3af;
            border-radius: 50%;
            animation: pulse 1.5s ease-in-out infinite;
        }

        .dot:nth-child(2) {
            animation-delay: 0.2s;
        }

        .dot:nth-child(3) {
            animation-delay: 0.4s;
        }

        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
                background-color: #9ca3af;
            }
            50% {
                transform: scale(1.2);
                background-color: #1a1a1a;
            }
        }

        /* Progress Bar */
        .progress-bar {
            width: 100%;
            max-width: 300px;
            height: 4px;
            background-color: #e5e7eb;
            border-radius: 2px;
            overflow: hidden;
            margin: 0 auto 2rem;
        }

        .progress-bar-inner {
            height: 100%;
            background-color: #1a1a1a;
            border-radius: 2px;
            animation: progress 2s ease-in-out infinite;
        }

        @keyframes progress {
            0% {
                width: 0%;
                margin-left: 0%;
            }
            50% {
                width: 70%;
                margin-left: 0%;
            }
            100% {
                width: 0%;
                margin-left: 100%;
            }
        }

        .login-link {
            display: inline-block;
            margin-top: 2rem;
            padding: 0.75rem 1.5rem;
            background-color: #1a1a1a;
            color: #ffffff;
            text-decoration: none;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            transition: background-color 0.2s ease;
        }

        .login-link:hover {
            background-color: #374151;
        }

        .footer {
            position: absolute;
            bottom: 2rem;
            left: 50%;
            transform: translateX(-50%);
            font-size: 0.75rem;
            color: #9ca3af;
        }

        /* Decorative elements */
        .decoration {
            position: fixed;
            opacity: 0.03;
            z-index: -1;
        }

        .decoration-1 {
            top: 10%;
            left: 10%;
            width: 300px;
            height: 300px;
            border: 2px solid #1a1a1a;
            border-radius: 50%;
        }

        .decoration-2 {
            bottom: 15%;
            right: 10%;
            width: 200px;
            height: 200px;
            border: 2px solid #1a1a1a;
            transform: rotate(45deg);
        }

        @media (max-width: 640px) {
            .title {
                font-size: 1.75rem;
            }

            .subtitle {
                font-size: 1rem;
            }

            .message {
                font-size: 1rem;
            }

            .decoration {
                display: none;
            }
        }
    </style>
</head>
<body>
    <!-- Decorative background elements -->
    <div class="decoration decoration-1"></div>
    <div class="decoration decoration-2"></div>

    <div class="container">
        <!-- Logo or Site Name -->
        <div class="logo">
            <?php if ($showLogo && $siteLogo): ?>
                <img src="<?= $basePath ?>/media/<?= htmlspecialchars($siteLogo, ENT_QUOTES, 'UTF-8') ?>" alt="<?= $siteTitle ?>">
            <?php endif; ?>
        </div>

        <!-- Title: site name (if no logo) or custom title -->
        <?php if (!$siteLogo || !$showLogo): ?>
            <h1 class="title"><?= $siteTitle ?></h1>
        <?php endif; ?>

        <?php if ($hasCustomTitle): ?>
            <p class="subtitle"><?= $title ?></p>
        <?php endif; ?>

        <!-- Message -->
        <p class="message"><?= nl2br($message) ?></p>

        <?php if ($showCountdown): ?>
        <!-- Loading Animation -->
        <div class="progress-container">
            <div class="dot"></div>
            <div class="dot"></div>
            <div class="dot"></div>
        </div>

        <!-- Progress Bar -->
        <div class="progress-bar">
            <div class="progress-bar-inner"></div>
        </div>
        <?php endif; ?>

        <!-- Admin Login Link -->
        <a href="<?= $basePath ?>/admin/login" class="login-link"><?= htmlspecialchars($config['admin_login_text'] ?? 'Admin Login', ENT_QUOTES, 'UTF-8') ?></a>
    </div>

    <footer class="footer">
        &copy; <?= date('Y') ?> <?= $siteTitle ?>
    </footer>
</body>
</html>
