<?php
$segment = (string) (request()->getSegment(1) ?? '');

// URL of a public asset with a cache-busting ?v= (file modification time).
$asset = static function (string $file): string {
    $path = defined('PUBLIC_PATH') ? PUBLIC_PATH . '/' . $file : '';
    $version = ($path !== '' && is_file($path)) ? '?v=' . filemtime($path) : '';

    return esc(url($file)) . $version;
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light dark">
    <meta name="description" content="<?= esc($description ?? 'Kyaaaa-PHP is a small PHP 8.3 framework with a router, a safe query builder, sessions, CSRF protection and a mailer.') ?>">
    <?= csrf()->metaTag() ?>
    <title><?= esc($title ?? 'Kyaaaa-PHP') ?></title>
    <script>try{var t=localStorage.getItem('kyaaaa-theme');if(t==='dark'||t==='light'){document.documentElement.setAttribute('data-theme',t)}}catch(e){}</script>
    <link href="<?= $asset('assets/css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= $asset('assets/css/kyaaaa.css') ?>" rel="stylesheet">
    <script src="<?= $asset('assets/js/turbolinks.min.js') ?>"></script>
    <script src="<?= $asset('assets/js/kyaaaa.js') ?>" defer></script>
</head>
<body class="k-body">
    <a class="k-skip" href="#main">Skip to content</a>

    <header class="k-nav">
        <div class="k-wrap k-nav-inner">
            <a class="k-brand" href="<?= esc(url()) ?>" aria-label="Kyaaaa-PHP home">Kyaaaa<span>~</span></a>
            <button class="k-burger" type="button" data-bs-toggle="collapse" data-bs-target="#kMenu" aria-controls="kMenu" aria-expanded="false" aria-label="Toggle menu"><span></span></button>
            <nav class="collapse k-menu" id="kMenu" aria-label="Main">
                <a href="<?= esc(url()) ?>"<?= $segment === '' ? ' aria-current="page"' : '' ?>>Home</a>
                <a href="<?= esc(url('docs')) ?>"<?= $segment === 'docs' ? ' aria-current="page"' : '' ?>>Docs</a>
                <a href="https://github.com/naufkia/kyaaaa-php" rel="noopener">GitHub</a>
                <button class="k-theme" type="button" data-theme-toggle aria-label="Switch between light and dark theme"><span class="k-theme-dot"></span></button>
            </nav>
        </div>
    </header>

    <main id="main"><?= $content /* already rendered HTML of the view */ ?></main>

    <footer class="k-footer">
        <div class="k-wrap k-footer-inner">
            <span>Kyaaaa-PHP is open source (MIT). Take your coffee before you start.</span>
            <nav aria-label="Footer">
                <a href="<?= esc(url('docs')) ?>">Docs</a>
                <a href="https://github.com/naufkia/kyaaaa-php" rel="noopener">GitHub</a>
                <a href="https://nauf.space/donate" rel="noopener">Buy me a coffee</a>
            </nav>
        </div>
    </footer>

    <script src="<?= $asset('assets/js/bootstrap.bundle.min.js') ?>"></script>
</body>
</html>
