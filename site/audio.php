<?php
/**
 * 单词发音：优先用本地缓存，缺失时向有道拉取并落盘。
 * 用法：audio.php?w=cancel&t=us|uk
 */
require_once __DIR__ . '/auth.php';

$w = trim((string)($_GET['w'] ?? ''));
$w = str_replace('_', ' ', $w);
$w = preg_replace("/[^A-Za-z'\\- ]/", '', $w);
$w = trim($w);
if ($w === '' || strlen($w) > 40) {
    http_response_code(400);
    exit;
}
$type = (($_GET['t'] ?? 'us') === 'uk') ? 1 : 2;

$dir = __DIR__ . '/data/cache/audio';
if (!is_dir($dir)) {
    @mkdir($dir, 0777, true);
}
$file = $dir . '/' . strtolower(str_replace(' ', '_', $w)) . '_' . $type . '.mp3';

$ok = is_file($file) && filesize($file) > 400;
if (!$ok) {
    $url = 'https://dict.youdao.com/dictvoice?audio=' . rawurlencode($w) . '&type=' . $type;
    $bin = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        ]);
        $bin = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 6, 'header' => "User-Agent: Mozilla/5.0\r\n"]]);
        $bin = @file_get_contents($url, false, $ctx);
    }
    if (!is_string($bin) || strlen($bin) < 400) {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'msg' => '发音获取失败']);
        exit;
    }
    @file_put_contents($file, $bin);
    $ok = true;
}

header('Content-Type: audio/mpeg');
header('Accept-Ranges: none');
header('Cache-Control: public, max-age=31536000, immutable');
header('Content-Length: ' . filesize($file));
readfile($file);
