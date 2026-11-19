<?php
/**
 * 联想搜索：代理有道 suggest 接口，按查询词缓存。
 * 用法：suggest.php?q=aband
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!current_user()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => '未登录']);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
// 允许英文、空格、连字符、撇号，以及中文（用中文也能反查）
$q = preg_replace('/[^\x{4e00}-\x{9fa5}A-Za-z0-9\'\- ]/u', '', $q);
$q = trim($q);
if ($q === '' || strlen($q) > 40) {
    echo json_encode(['ok' => true, 'q' => $q, 'list' => []]);
    exit;
}

$dir = __DIR__ . '/data/cache/suggest';
if (!is_dir($dir)) {
    @mkdir($dir, 0777, true);
}
$file = $dir . '/' . md5(strtolower($q)) . '.json';

if (is_file($file) && filemtime($file) > time() - 86400 * 7) {
    header('X-Suggest-Cache: hit');
    readfile($file);
    exit;
}

function http_get(string $url, int $timeout = 5): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return (is_string($body) && $code > 0 && $code < 400) ? $body : null;
    }
    $ctx = stream_context_create([
        'http' => ['timeout' => $timeout, 'header' => "User-Agent: Mozilla/5.0\r\n"],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    return is_string($body) ? $body : null;
}

$out = ['ok' => true, 'q' => $q, 'list' => []];
$url = 'https://dict.youdao.com/suggest?num=8&ver=3.0&doctype=json&cache=false&le=en&q=' . rawurlencode($q);
$raw = http_get($url);
if ($raw !== null) {
    $j = json_decode($raw, true);
    foreach (($j['data']['entries'] ?? []) as $e) {
        $w = trim((string)($e['entry'] ?? ''));
        $t = trim(preg_replace('/\s+/u', ' ', (string)($e['explain'] ?? '')));
        if ($w !== '' && $w !== $q) {
            $out['list'][] = ['w' => $w, 't' => $t];
        }
        if (count($out['list']) >= 8) {
            break;
        }
    }
}

$json = json_encode($out, JSON_UNESCAPED_UNICODE);
@file_put_contents($file, $json);
echo $json;
