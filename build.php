<?php
declare(strict_types=1);

$root = __DIR__;
$manifest = json_decode((string)file_get_contents($root.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$name = 'nexapress-mail-' . $manifest['version'] . '.zip';
$dist = $root . '/dist';
if (!is_dir($dist)) { mkdir($dist, 0775, true); }
$target = $dist . '/' . $name;
if (is_file($target)) { unlink($target); }
$zip = new ZipArchive();
if ($zip->open($target, ZipArchive::CREATE | ZipArchive::EXCL) !== true) { throw new RuntimeException('ZIPを作成できません。'); }
$prefix = 'nexapress-mail/';
$zip->addFile($root.'/manifest.json', $prefix.'manifest.json');
$excluded = ['.git','.github','.idea','.vscode','dist','tests','storage'];
$excludedFiles = ['build.php','.editorconfig','.gitattributes','.gitignore'];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getSize() === 0) { continue; }
    $relative = str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));
    if ($relative === 'manifest.json' || in_array($relative,$excludedFiles,true)) { continue; }
    $first = explode('/',$relative,2)[0];
    if (in_array($first,$excluded,true)) { continue; }
    $zip->addFile($file->getPathname(),$prefix.$relative);
}
$zip->close();
$check = new ZipArchive(); $check->open($target);
if ($check->getNameIndex(0) !== $prefix.'manifest.json') { throw new RuntimeException('ZIP先頭がmanifest.jsonではありません。'); }
for ($i=0;$i<$check->numFiles;$i++) {
    $entry = $check->getNameIndex($i);
    if (preg_match('#(^|/)(\.git|tests|dist|storage)(/|$)|(^|/)(\.env|credential\.key)$|\.(log|zip)$#i',$entry)) {
        throw new RuntimeException('禁止ファイルを検出: '.$entry);
    }
}
$count = $check->numFiles; $check->close();
echo $target . PHP_EOL . $count . " entries verified\n";
