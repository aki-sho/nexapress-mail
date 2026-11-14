<?php
declare(strict_types=1);

$root = __DIR__;
$manifest = json_decode(file_get_contents($root . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$version = $manifest['version'];
if (!is_string($version) || !preg_match('/\A\d+\.\d+\.\d+\z/', $version)) {
    throw new RuntimeException('配布バージョンが不正です。');
}

$files = [
    'manifest.json', 'bootstrap.php', 'composer.json', 'composer.lock', 'img.png',
    'README.md', 'CHANGELOG.md', 'SECURITY.md', 'LICENSE', 'admin/dashboard.php',
    'assets/css/mail.css', 'assets/js/mail.js', 'bin/sync.php',
    'config/limits.php', 'config/mail.php', 'config/security.php', 'config/storage.php',
    'vendor/autoload.php', 'vendor/phpmailer/phpmailer/LICENSE', 'vendor/phpmailer/phpmailer/VERSION',
    'vendor/composer/ClassLoader.php', 'vendor/composer/InstalledVersions.php', 'vendor/composer/LICENSE',
    'vendor/composer/installed.php', 'vendor/composer/installed.json', 'vendor/composer/platform_check.php',
];
foreach (['autoload_real.php', 'autoload_static.php', 'autoload_classmap.php', 'autoload_namespaces.php', 'autoload_psr4.php'] as $file) {
    $files[] = 'vendor/composer/' . $file;
}

foreach (['src', 'views', 'vendor/phpmailer/phpmailer/src', 'vendor/phpmailer/phpmailer/language'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        if ($file->isFile() && preg_match('#\A(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+\.php\z#', $relative)
            && !str_starts_with($file->getFilename(), '.')) {
            $files[] = $relative;
        }
    }
}

$rootPath = str_replace('\\', '/', realpath($root));
foreach ($files as $relative) {
    $file = $root . '/' . $relative;
    $resolved = realpath($file);
    if (!$resolved || !is_file($file) || str_replace('\\', '/', $resolved) !== $rootPath . '/' . $relative) {
        throw new RuntimeException('配布ファイルが通常のパスにありません: ' . $relative);
    }
}

$dist = $root . '/dist';
if ((!is_dir($dist) && !mkdir($dist, 0775)) || str_replace('\\', '/', realpath($dist)) !== $rootPath . '/dist') {
    throw new RuntimeException('配布先ディレクトリが不正です。');
}
$target = $dist . '/nexapress-mail-' . $version . '.zip';
$temporary = $dist . '/.build-' . bin2hex(random_bytes(12)) . '.zip';
$prefix = 'nexapress-mail/';

try {
    $zip = new ZipArchive();
    if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        throw new RuntimeException('ZIPを作成できません。');
    }
    foreach ($files as $relative) {
        if (!$zip->addFile($root . '/' . $relative, $prefix . $relative)) {
            throw new RuntimeException('配布ファイルを追加できません: ' . $relative);
        }
    }
    if (!$zip->close()) { throw new RuntimeException('ZIPを保存できません。'); }

    $check = new ZipArchive();
    if ($check->open($temporary, ZipArchive::CHECKCONS) !== true) {
        throw new RuntimeException('作成したZIPを検証できません。');
    }
    try {
        if ($check->numFiles !== count($files)) { throw new RuntimeException('ZIPのファイル数が一致しません。'); }
        foreach ($files as $index => $relative) {
            if ($check->getNameIndex($index) !== $prefix . $relative) {
                throw new RuntimeException('ZIPの収録ファイルが一致しません。');
            }
        }
    } finally {
        $check->close();
    }
    if (!rename($temporary, $target)) { throw new RuntimeException('検証済みのZIPを配置できません。'); }
} finally {
    if (is_file($temporary)) { unlink($temporary); }
}

echo $target . PHP_EOL . count($files) . ' entries verified' . PHP_EOL;
