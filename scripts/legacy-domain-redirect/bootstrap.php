<?php
// Missing/invalid release data always falls back to the untouched legacy application.
if (PHP_SAPI === 'cli' || headers_sent()) {
    return;
}
$lavkaMapFile = __DIR__ . '/mapping.json';
if (!is_readable($lavkaMapFile)) {
    return;
}
$lavkaMap = json_decode(file_get_contents($lavkaMapFile), true);
require_once __DIR__ . '/router.php';
$lavkaTarget = lavka_legacy_target($_SERVER, $_GET, $lavkaMap, $_COOKIE);
unset($lavkaMap, $lavkaMapFile);
if ($lavkaTarget !== null) {
    header('Cache-Control: private, no-store');
    header('Vary: Cookie', false);
    header('Location: ' . $lavkaTarget, true, 301);
    exit;
}
unset($lavkaTarget);
