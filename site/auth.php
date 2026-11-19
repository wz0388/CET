<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        // 走 HTTPS 时给 Cookie 加 Secure，避免被降级到 http 时泄露
        'secure'   => (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off'),
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* 安全响应头（nginx 那边没配，这里由 PHP 补上，改起来不用动 vhost） */
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    $__https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off');
    if ($__https) {
        header('Strict-Transport-Security: max-age=31536000');   // 一年内强制 https
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');                        // 防点击劫持（后台页面不能被别的站套框）
    header('Referrer-Policy: same-origin');
}


/** 密码指纹：一旦改密，旧会话立即失效（按用户生效，不影响别人） */
function pass_fp(string $hash): string
{
    return substr(hash('sha256', $hash), 0, 16);
}

/** 当前登录用户（数组），未登录或会话失效返回 null */
function current_user(): ?array
{
    static $cached = false;
    static $user = null;
    if ($cached) {
        return $user;
    }
    $cached = true;

    if (empty($_SESSION['uid'])) {
        return $user = null;
    }
    // 版本不一致（改过密码 / 主动踢下线）则视为未登录
    if ((int)($_SESSION['v'] ?? 0) !== SESSION_VERSION) {
        $_SESSION = [];
        return $user = null;
    }

    $row = user_find((int)$_SESSION['uid']);
    if (!$row || (int)$row['active'] !== 1) {
        $_SESSION = [];
        return $user = null;
    }
    // 密码被改过（指纹对不上）→ 这个会话立即失效，其它端一并踢下线
    if (empty($_SESSION['ph']) || !hash_equals((string)$_SESSION['ph'], pass_fp((string)$row['pass_hash']))) {
        $_SESSION = [];
        return $user = null;
    }
    unset($row['pass_hash']);
    return $user = $row;
}

function user_name(): string
{
    $u = current_user();
    if (!$u) {
        return '';
    }
    return $u['display_name'] !== '' ? $u['display_name'] : $u['username'];
}

function require_login(): void
{
    if (!current_user()) {
        header('Location: login.php');
        exit;
    }
}

function require_admin(): void
{
    require_login();
    $u = current_user();
    if ((int)$u['is_admin'] !== 1) {
        http_response_code(403);
        echo '<!doctype html><meta charset="utf-8"><title>403</title>'
           . '<div style="font-family:system-ui;padding:60px;text-align:center">'
           . '<h1 style="font-size:20px">403 无权访问</h1>'
           . '<p><a href="index.php">返回题库</a></p></div>';
        exit;
    }
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** 页脚备案信息 */
function site_footer(): void
{
    echo '<footer class="site-footer">'
        . '<a href="' . ICP_URL . '" target="_blank" rel="noopener noreferrer">' . ICP_BEIAN . '</a>'
        . '</footer>';
}

/** 读取试卷数据 */
function load_papers(): array
{
    $raw = @file_get_contents(__DIR__ . '/data/papers.json');
    if ($raw === false) {
        return ['papers' => []];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : ['papers' => []];
}

function find_paper(string $id): ?array
{
    foreach (load_papers()['papers'] as $p) {
        if ($p['id'] === $id) {
            return $p;
        }
    }
    return null;
}

/** 读取答案表：{"s1":{"1":"A",...},"s2":{...}} */
function load_answers(): array
{
    $raw = @file_get_contents(__DIR__ . '/data/answers.json');
    if ($raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/** 读取听力原文：{"s1":{"a1":{"turns":["..."],"q":{"1":"..."}}}} */
function load_transcripts(): array
{
    $raw = @file_get_contents(__DIR__ . '/data/transcripts.json');
    if ($raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * 随机密码字符集（已排除易混淆字符：0 o O 1 i I l L q 9 g）
 */
function password_charset(): array
{
    return [
        'lower'   => 'abcdefhjkmnprstuvwxyz',      // 去 o i l q g
        'upper'   => 'ABCDEFGHJKMNPQRSTUVWXYZ',    // 去 O I L
        'digits'  => '2345678',                    // 去 0 1 9
        'symbols' => '!@#$%^&*_-+=?',
    ];
}

/** 随机密码：保证同时包含大小写、数字、符号，长度 6–32 */
function random_password(int $len = 12): string
{
    $len = max(6, min(32, $len));
    $c   = password_charset();
    $pool = $c['lower'] . $c['upper'] . $c['digits'] . $c['symbols'];

    $chars = [
        $c['lower'][random_int(0, strlen($c['lower']) - 1)],
        $c['upper'][random_int(0, strlen($c['upper']) - 1)],
        $c['digits'][random_int(0, strlen($c['digits']) - 1)],
        $c['symbols'][random_int(0, strlen($c['symbols']) - 1)],
    ];
    while (count($chars) < $len) {
        $chars[] = $pool[random_int(0, strlen($pool) - 1)];
    }
    shuffle($chars);
    return implode('', $chars);
}


/* ------------------------------------------------------------------
 * 海外流量拦截（多源交叉判定，至少 3 个源都说海外才拦）
 * ------------------------------------------------------------------ */
function block_foreign_visitor(): void
{
    if (!BLOCK_FOREIGN) {
        return;
    }
    $ip = client_ip();
    if (in_array($ip, FOREIGN_ALLOW_IPS, true)) {
        return;
    }

    // 会话内缓存判定结果，避免每页重复查询
    $v = $_SESSION['geo_v'] ?? null;
    if (!is_array($v) || !isset($v['verdict'])) {
        try {
            $v = geo_verdict($ip);
        } catch (Throwable $e) {
            return;   // 判定异常时放行
        }
        $_SESSION['geo_v'] = $v;
    }

    if (($v['verdict'] ?? 'unknown') !== 'foreign') {
        return;   // cn / unknown 都放行
    }

    $label = country_label((string)($v['label'] ?? ''));

    // 记录拦截（同一 IP 每小时一条，避免刷表）
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM logs WHERE action = 'block' AND ip = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $st->execute([$ip]);
        if ((int)$st->fetchColumn() === 0) {
            log_action(null, 'block', $label . ' ' . (string)($_SERVER['REQUEST_URI'] ?? '/'));
        }
    } catch (Throwable $e) {
    }

    http_response_code(403);
    $isApi = basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'api.php';
    if ($isApi) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'msg' => '本站仅限中国大陆访问'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="zh-CN"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>403 仅限中国大陆访问</title><style>'
       . 'body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f5f6fb;'
       . 'font-family:-apple-system,"PingFang SC","Microsoft YaHei",sans-serif;color:#0f1729}'
       . '.box{text-align:center;padding:40px;max-width:420px}'
       . 'h1{font-size:20px;margin:0 0 10px}p{color:#6b7789;font-size:13.5px;margin:6px 0;line-height:1.8}'
       . '.code{font-family:monospace;background:#eef0ff;color:#3f49d9;border-radius:8px;padding:2px 8px;font-size:12px}'
       . '</style></head><body><div class="box">'
       . '<h1>本站仅限中国大陆访问</h1>'
       . '<p>检测到当前网络来自非中国大陆地区（' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '），访问已被拦截。</p>'
       . '<p>如果你在国内使用代理/VPN，请关闭后重试。</p>'
       . '<p class="code">403 Forbidden</p>'
       . '</div></body></html>';
    exit;
}

block_foreign_visitor();

/* ------------------------------------------------------------------
 * 自动记录页面访问（仅 GET 页面；接口与埋点单独处理）
 * ------------------------------------------------------------------ */
if (PHP_SAPI !== 'cli') {
    $__script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $__skip   = ['api.php', 'track.php'];
    if (!in_array($__script, $__skip, true) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        try {
            $__u = current_user();
            visit_log(
                $__u ? (int)$__u['id'] : null,
                (string)($_SERVER['REQUEST_URI'] ?? '/'),
                'GET',
                (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
                (string)($_SERVER['HTTP_REFERER'] ?? '')
            );
        } catch (Throwable $__e) {
            // 记录失败不影响页面渲染
        }
    }
}
