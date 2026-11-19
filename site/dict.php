<?php
/**
 * 单词词典接口：拉取有道释义 / 例句 / 短语 / 近义词，并缓存到本地。
 * 用法：dict.php?w=cancel
 */
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!current_user()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'msg' => '未登录']);
    exit;
}

$w = trim((string)($_GET['w'] ?? ''));
$w = str_replace('_', ' ', $w);
$w = preg_replace("/[^A-Za-z'\\- ]/", '', $w);
$w = trim($w);
if ($w === '' || strlen($w) > 40) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => '参数错误']);
    exit;
}

$dir = __DIR__ . '/data/cache/dict';
if (!is_dir($dir)) {
    @mkdir($dir, 0777, true);
}
$file = $dir . '/v3_' . strtolower(str_replace(' ', '_', $w)) . '.json';

if (is_file($file) && filesize($file) > 8) {
    $raw = (string)@file_get_contents($file);
    $hit = json_decode($raw, true);
    $ttl = (is_array($hit) && !empty($hit['ok'])) ? 86400 * 90 : 86400 * 3;
    if (is_array($hit) && filemtime($file) > time() - $ttl) {
        header('X-Dict-Cache: hit');
        echo json_encode(clean_out($hit), JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/** 简易 HTTP GET */
function http_get(string $url, int $timeout = 6): ?string
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
            CURLOPT_HTTPHEADER     => ['Accept: application/json,text/plain,*/*'],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (is_string($body) && $body !== '' && $code > 0 && $code < 400) {
            return $body;
        }
        return null;
    }
    $ctx = stream_context_create([
        'http' => ['timeout' => $timeout, 'header' => "User-Agent: Mozilla/5.0\r\n"],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    return is_string($body) && $body !== '' ? $body : null;
}

/** 把 trs / tr 里千奇百怪的结构摊平成字符串数组 */
function flatten($node, array &$out, int $depth = 0): void
{
    if ($depth > 8 || $node === null) {
        return;
    }
    if (is_string($node)) {
        $s = trim(strip_tags($node));
        if ($s !== '') {
            $out[] = $s;
        }
        return;
    }
    if (!is_array($node)) {
        return;
    }
    if (isset($node['i'])) {
        flatten($node['i'], $out, $depth + 1);
        return;
    }
    if (isset($node['l'])) {
        flatten($node['l'], $out, $depth + 1);
        return;
    }
    if (isset($node['tr'])) {
        flatten($node['tr'], $out, $depth + 1);
        return;
    }
    foreach ($node as $k => $v) {
        if (is_int($k)) {
            flatten($v, $out, $depth + 1);
        }
    }
}

function clean_out(array $d): array
{
    if (!empty($d['defs'])) {
        $d['defs'] = array_values(array_filter($d['defs'], function ($x) {
            return strpos($x, '人名') === false;
        }));
        $d['defs'] = array_slice($d['defs'], 0, 4);
    }
    return $d;
}

function fetch_dict(string $w): array
{
    $url = 'https://dict.youdao.com/jsonapi?q=' . rawurlencode($w);
    $raw = http_get($url);
    $out = ['ok' => false, 'w' => $w, 'us' => '', 'uk' => '', 'defs' => [], 'ex' => [], 'phrs' => [], 'syn' => []];
    if ($raw === null) {
        $out['err'] = 'network';
        return $out;
    }
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        $out['err'] = 'json';
        return $out;
    }
    if (!empty($_GET['debug'])) {
        $out['_len'] = strlen($raw);
        $out['_keys'] = array_slice(array_keys($j), 0, 20);
        $out['_ecword'] = isset($j['ec']['word'][0]) ? array_keys($j['ec']['word'][0]) : null;
    }

    $ecw = $j['ec']['word'][0] ?? [];
    if (is_array($ecw)) {
        $out['us'] = (string)($ecw['usphone'] ?? '');
        $out['uk'] = (string)($ecw['ukphone'] ?? '');
        foreach (($ecw['trs'] ?? []) as $tr) {
            $lines = [];
            flatten($tr, $lines);
            if ($lines) {
                $out['defs'][] = implode('；', $lines);
            }
        }
    }

    // 去掉「（人名）」这类对背单词没用的义项
    $out['defs'] = array_values(array_filter($out['defs'], function ($d) {
        return strpos($d, '人名') === false;
    }));
    $out['defs'] = array_slice($out['defs'], 0, 4);

    if (!$out['defs'] && isset($j['simple']['word'][0]['trs'])) {
        foreach (($j['simple']['word'][0]['trs'] ?? []) as $tr) {
            $lines = [];
            flatten($tr, $lines);
            if ($lines) {
                $out['defs'][] = implode('；', $lines);
            }
        }
    }

    foreach (($j['blng_sents_part']['sentence-pair'] ?? []) as $p) {
        $en = trim(strip_tags((string)($p['sentence'] ?? '')));
        $zh = trim(strip_tags((string)($p['sentence-translation'] ?? '')));
        if ($en !== '') {
            $out['ex'][] = ['en' => $en, 'zh' => $zh];
        }
        if (count($out['ex']) >= 3) {
            break;
        }
    }

    foreach (($j['phrs']['phrs'] ?? []) as $p) {
        $phr = $p['phr'] ?? [];
        $head = trim(strip_tags((string)($phr['headword']['l']['i'] ?? '')));
        $lines = [];
        flatten($phr['trs'] ?? [], $lines);
        if ($head !== '' && $lines) {
            $out['phrs'][] = ['w' => $head, 't' => implode('；', $lines)];
        }
        if (count($out['phrs']) >= 4) {
            break;
        }
    }

    foreach (($j['syno']['synos'] ?? []) as $s) {
        $syn = $s['syno'] ?? [];
        $ws = [];
        foreach (($syn['ws'] ?? []) as $x) {
            $t = trim((string)($x['w'] ?? ''));
            if ($t !== '') {
                $ws[] = $t;
            }
        }
        $tran = trim(strip_tags((string)($syn['tran'] ?? '')));
        if ($ws) {
            $out['syn'][] = ['pos' => trim((string)($syn['pos'] ?? '')), 'w' => array_slice($ws, 0, 4), 't' => $tran];
        }
        if (count($out['syn']) >= 3) {
            break;
        }
    }

    $out['ok'] = (bool)($out['defs'] || $out['ex']);
    return $out;
}

$data = clean_out(fetch_dict($w));
$json = json_encode($data, JSON_UNESCAPED_UNICODE);
@file_put_contents($file, $json);
echo $json;
