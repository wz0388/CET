<?php
require_once __DIR__ . '/config.php';

/** 数据库连接（首次自动建表 + 初始化管理员） */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    try {
        // 同时探测新表，便于已有库平滑升级（install_schema 里都是 IF NOT EXISTS）
        $pdo->query('SELECT 1 FROM users LIMIT 1');
        $pdo->query('SELECT 1 FROM visits LIMIT 1');
        $pdo->query('SELECT 1 FROM ip_geo LIMIT 1');
        $pdo->query('SELECT 1 FROM ip_verdict LIMIT 1');
    } catch (Throwable $e) {
        install_schema($pdo);
    }

    return $pdo;
}

function install_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        username VARCHAR(64) NOT NULL,
        pass_hash VARCHAR(255) NOT NULL,
        display_name VARCHAR(64) NOT NULL DEFAULT '',
        is_admin TINYINT(1) NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL,
        last_login DATETIME NULL DEFAULT NULL,
        last_ip VARCHAR(64) NOT NULL DEFAULT '',
        login_count INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY uk_username (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS progress (
        user_id INT UNSIGNED NOT NULL,
        paper_id VARCHAR(32) NOT NULL,
        payload MEDIUMTEXT NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (user_id, paper_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS attempts (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        paper_id VARCHAR(32) NOT NULL,
        mode VARCHAR(16) NOT NULL DEFAULT 'exam',
        answered INT UNSIGNED NOT NULL DEFAULT 0,
        total INT UNSIGNED NOT NULL DEFAULT 0,
        score INT NULL DEFAULT NULL,
        duration INT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_user (user_id),
        KEY idx_time (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS logs (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NULL DEFAULT NULL,
        action VARCHAR(32) NOT NULL,
        detail VARCHAR(255) NOT NULL DEFAULT '',
        ip VARCHAR(64) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_time (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS visits (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NULL DEFAULT NULL,
        ip VARCHAR(64) NOT NULL DEFAULT '',
        path VARCHAR(255) NOT NULL DEFAULT '',
        method VARCHAR(8) NOT NULL DEFAULT 'GET',
        ua VARCHAR(255) NOT NULL DEFAULT '',
        ref VARCHAR(255) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_time (created_at),
        KEY idx_ip (ip),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ip_geo (
        ip VARCHAR(64) NOT NULL,
        country VARCHAR(64) NOT NULL DEFAULT '',
        region VARCHAR(64) NOT NULL DEFAULT '',
        city VARCHAR(64) NOT NULL DEFAULT '',
        isp VARCHAR(96) NOT NULL DEFAULT '',
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (ip)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS ip_verdict (
        ip VARCHAR(64) NOT NULL,
        verdict VARCHAR(16) NOT NULL DEFAULT 'unknown',
        label VARCHAR(64) NOT NULL DEFAULT '',
        votes VARCHAR(255) NOT NULL DEFAULT '',
        checked_at DATETIME NOT NULL,
        PRIMARY KEY (ip),
        KEY idx_checked (checked_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count === 0) {
        $st = $pdo->prepare('INSERT INTO users (username, pass_hash, display_name, is_admin, active, created_at)
                             VALUES (?, ?, ?, 1, 1, NOW())');
        $st->execute([
            SEED_ADMIN_USER,
            password_hash(SEED_ADMIN_PASS, PASSWORD_DEFAULT),
            '管理员',
        ]);
    }
}

/* ---------------- 用户 ---------------- */

function user_find_by_name(string $username): ?array
{
    $st = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $st->execute([$username]);
    $row = $st->fetch();
    return $row ?: null;
}

function user_find(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    return $row ?: null;
}

function user_create(string $username, string $password, string $display = '', bool $isAdmin = false): int
{
    $st = db()->prepare('INSERT INTO users (username, pass_hash, display_name, is_admin, active, created_at)
                         VALUES (?, ?, ?, ?, 1, NOW())');
    $st->execute([$username, password_hash($password, PASSWORD_DEFAULT), $display, $isAdmin ? 1 : 0]);
    return (int)db()->lastInsertId();
}

function user_set_password(int $id, string $password): void
{
    $st = db()->prepare('UPDATE users SET pass_hash = ? WHERE id = ?');
    $st->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
}

/* ---------------- 进度 ---------------- */

function progress_get(int $uid, string $paper): array
{
    $st = db()->prepare('SELECT payload, updated_at FROM progress WHERE user_id = ? AND paper_id = ? LIMIT 1');
    $st->execute([$uid, $paper]);
    $row = $st->fetch();
    if (!$row) {
        return [];
    }
    $data = json_decode($row['payload'], true);
    if (!is_array($data)) {
        $data = [];
    }
    $data['_updated'] = $row['updated_at'];
    return $data;
}

function progress_save(int $uid, string $paper, array $payload): void
{
    $st = db()->prepare('INSERT INTO progress (user_id, paper_id, payload, updated_at)
                         VALUES (?, ?, ?, NOW())
                         ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_at = NOW()');
    $st->execute([$uid, $paper, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
}

function progress_all(int $uid): array
{
    $st = db()->prepare('SELECT paper_id, payload, updated_at FROM progress WHERE user_id = ?');
    $st->execute([$uid]);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $d = json_decode($row['payload'], true);
        $out[$row['paper_id']] = [
            'answered'   => is_array($d['answers'] ?? null) ? count($d['answers']) : 0,
            'updated_at' => $row['updated_at'],
        ];
    }
    return $out;
}

/* ---------------- 记录 ---------------- */

function log_action(?int $uid, string $action, string $detail = ''): void
{
    if (function_exists('mb_substr')) {
        $detail = mb_substr($detail, 0, 250);
    } else {
        $detail = substr($detail, 0, 250);
    }
    $st = db()->prepare('INSERT INTO logs (user_id, action, detail, ip, created_at) VALUES (?, ?, ?, ?, NOW())');
    $st->execute([$uid, $action, $detail, client_ip()]);
}

/**
 * 登录保护：统计窗口内同一账号 / 同一 IP 的失败次数
 * 返回 ['blocked' => bool, 'msg' => string, 'delay_us' => int]
 * 说明：被拦截时不再写 login_fail，否则攻击者持续请求会让计数永远不衰减
 */
function login_throttle(string $username): array
{
    $out = ['blocked' => false, 'msg' => '', 'delay_us' => 400000];
    if (!LOGIN_GUARD) {
        return $out;
    }
    $ip = client_ip();
    if (in_array($ip, LOGIN_EXEMPT_IPS, true)) {
        return $out;
    }

    $win = max(1, (int)LOGIN_WINDOW_MIN);
    try {
        $st = db()->prepare("SELECT COUNT(*) FROM logs
                             WHERE action = 'login_fail' AND detail = ?
                               AND created_at >= DATE_SUB(NOW(), INTERVAL {$win} MINUTE)");
        $st->execute([$username]);
        $byUser = (int)$st->fetchColumn();

        $st = db()->prepare("SELECT COUNT(*) FROM logs
                             WHERE action = 'login_fail' AND ip = ?
                               AND created_at >= DATE_SUB(NOW(), INTERVAL {$win} MINUTE)");
        $st->execute([$ip]);
        $byIp = (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return $out;   // 统计出错时放行，避免把所有人锁在门外
    }

    if ($byUser >= LOGIN_MAX_PER_USER || $byIp >= LOGIN_MAX_PER_IP) {
        $out['blocked'] = true;
        $out['msg']     = '该账号或当前网络登录失败次数过多，请 ' . $win . ' 分钟后再试';
        return $out;
    }

    // 失败越多越慢，最高 1.8 秒
    $out['delay_us'] = 300000 + min(10, $byUser) * 150000;
    return $out;
}

/**
 * 记录一次页面访问（GET），user_id 为空即未登录访客
 */
function visit_log(?int $uid, string $path, string $method = 'GET', string $ua = '', string $ref = ''): void
{
    $path = substr($path, 0, 250);
    $ua   = substr($ua, 0, 250);
    $ref  = substr($ref, 0, 250);
    $st = db()->prepare('INSERT INTO visits (user_id, ip, path, method, ua, ref, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, NOW())');
    $st->execute([$uid, client_ip(), $path, substr($method, 0, 8), $ua, $ref]);

    // 极低概率清理 90 天前的记录，避免表无限增长
    if (random_int(1, 400) === 1) {
        db()->exec('DELETE FROM visits WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)');
    }
}

function is_private_ip(string $ip): bool
{
    if ($ip === '' || $ip === '0.0.0.0') {
        return true;
    }
    return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

/** 读取已缓存的归属地 */
function geo_map(array $ips): array
{
    $ips = array_values(array_unique(array_filter($ips)));
    if (!$ips) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ips), '?'));
    $st = db()->prepare("SELECT ip, country, region, city, isp FROM ip_geo WHERE ip IN ($in)");
    $st->execute($ips);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $out[$row['ip']] = $row;
    }
    return $out;
}

/** 解析并缓存一个 IP 的归属地（内网直接标记） */
function geo_resolve(string $ip): ?array
{
    if ($ip === '') {
        return null;
    }
    if (is_private_ip($ip)) {
        $row = ['ip' => $ip, 'country' => '内网', 'region' => '', 'city' => '', 'isp' => ''];
        $st = db()->prepare('INSERT INTO ip_geo (ip, country, region, city, isp, updated_at) VALUES (?,?,?,?,?,NOW())
                             ON DUPLICATE KEY UPDATE updated_at = NOW()');
        $st->execute([$ip, $row['country'], '', '', '']);
        return $row;
    }

    $url = 'http://ip-api.com/json/' . urlencode($ip) . '?lang=zh-CN&fields=status,country,regionName,city,isp';
    $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return null;
    }
    $j = json_decode($raw, true);
    if (!is_array($j) || ($j['status'] ?? '') !== 'success') {
        return null;
    }
    $row = [
        'ip'      => $ip,
        'country' => (string)($j['country'] ?? ''),
        'region'  => (string)($j['regionName'] ?? ''),
        'city'    => (string)($j['city'] ?? ''),
        'isp'     => (string)($j['isp'] ?? ''),
    ];
    $st = db()->prepare('INSERT INTO ip_geo (ip, country, region, city, isp, updated_at) VALUES (?,?,?,?,?,NOW())
                         ON DUPLICATE KEY UPDATE country=VALUES(country), region=VALUES(region), city=VALUES(city), isp=VALUES(isp), updated_at=NOW()');
    $st->execute([$row['ip'], $row['country'], $row['region'], $row['city'], $row['isp']]);
    return $row;
}

/**
 * 多源 IP 归属判定
 * 规则：逐个源查询，任一源判定为中国大陆即放行；
 *       返回结果 >= 3 个且全部为海外，才判定为“需要拦截”。
 *       可用源不足 3 个（接口失败）时放行，避免误伤。
 */
function geo_sources(): array
{
    return [
        'ip.sb'  => 'https://api.ip.sb/geoip/%s',
        'ipinfo' => 'https://ipinfo.io/%s/json',
        'ip-api' => 'http://ip-api.com/json/%s?lang=zh-CN&fields=status,country,countryCode',
        'ipwho'  => 'https://ipwho.is/%s?fields=success,country,country_code',
    ];
}

/** 从某个源取回原始国家值，失败返回 null */
function geo_fetch_country(string $source, string $url, string $ip): ?string
{
    $ctx = stream_context_create([
        'http' => ['timeout' => 2, 'ignore_errors' => true, 'header' => "User-Agent: Mozilla/5.0\r\n"],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') {
        return null;
    }
    return geo_parse_country($source, $raw);
}

/** 解析各源返回的 JSON */
function geo_parse_country(string $source, string $raw): ?string
{
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        return null;
    }
    switch ($source) {
        case 'ip.sb':
            $c = $j['country'] ?? ($j['country_code'] ?? '');
            return $c !== '' ? (string)$c : null;
        case 'ipinfo':
            $c = $j['country'] ?? '';
            return $c !== '' ? (string)$c : null;
        case 'ip-api':
            if (($j['status'] ?? '') !== 'success') {
                return null;
            }
            $c = $j['country'] ?? ($j['countryCode'] ?? '');
            return $c !== '' ? (string)$c : null;
        case 'ipwho':
            if (empty($j['success'])) {
                return null;
            }
            $c = $j['country'] ?? ($j['country_code'] ?? '');
            return $c !== '' ? (string)$c : null;
    }
    return null;
}

/** 是否中国大陆：true 大陆 / false 明确非大陆 / null 无法判断 */
function country_is_mainland(string $c): ?bool
{
    $c = strtolower(trim($c));
    if ($c === '') {
        return null;
    }
    if (in_array($c, ['cn', 'chn', 'china', '中国', '中华人民共和国'], true)) {
        return true;
    }
    if (in_array($c, ['hk', 'hkg', 'hong kong', 'hongkong', '香港', '中国香港'], true)) {
        return false;
    }
    if (in_array($c, ['mo', 'mac', 'macao', 'macau', '澳门', '中国澳门'], true)) {
        return false;
    }
    if (in_array($c, ['tw', 'twn', 'taiwan', '台湾', '中国台湾'], true)) {
        return false;
    }
    return false;
}

/** 并发查询多个源（curl_multi），失败回退顺序请求 */
function geo_fetch_many(array $urls, float $timeout = 2.0): array
{
    $out = [];
    if (!$urls) {
        return $out;
    }
    if (!function_exists('curl_multi_init')) {
        foreach ($urls as $name => $url) {
            $c = geo_fetch_country($name, $url, '');
            if ($c !== null) {
                $out[$name] = $c;
            }
        }
        return $out;
    }

    $mh = curl_multi_init();
    $handles = [];
    foreach ($urls as $name => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int)ceil($timeout),
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$name] = $ch;
    }

    $running = null;
    do {
        curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 0.2);
        }
    } while ($running > 0);

    foreach ($handles as $name => $ch) {
        $body = curl_multi_getcontent($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        if (!is_string($body) || $body === '') {
            continue;
        }
        $c = geo_parse_country($name, $body);
        if ($c !== null) {
            $out[$name] = $c;
        }
    }
    curl_multi_close($mh);
    return $out;
}

/** 写入判定缓存并返回 */
function geo_store(string $ip, string $verdict, string $label, array $votes): array
{
    $parts = [];
    foreach ($votes as $k => $v) {
        $parts[] = $k . ':' . $v;
    }
    $votesStr = substr(implode(' | ', $parts), 0, 250);

    $st = db()->prepare('INSERT INTO ip_verdict (ip, verdict, label, votes, checked_at) VALUES (?,?,?,?,NOW())
                         ON DUPLICATE KEY UPDATE verdict=VALUES(verdict), label=VALUES(label), votes=VALUES(votes), checked_at=NOW()');
    $st->execute([$ip, $verdict, $label, $votesStr]);

    return ['verdict' => $verdict, 'label' => $label, 'votes' => $votesStr];
}

/** 国家值 → 中文展示名（用于拦截页与日志） */
function country_label(string $c): string
{
    $raw = trim($c);
    $key = strtolower($raw);
    $map = [
        'cn' => '中国', 'china' => '中国', '中国' => '中国',
        'hk' => '中国香港', 'hong kong' => '中国香港', '香港' => '中国香港', '中国香港' => '中国香港',
        'mo' => '中国澳门', 'macao' => '中国澳门', 'macau' => '中国澳门', '澳门' => '中国澳门', '中国澳门' => '中国澳门',
        'tw' => '中国台湾', 'taiwan' => '中国台湾', '台湾' => '中国台湾', '中国台湾' => '中国台湾',
        'us' => '美国', 'united states' => '美国', '美国' => '美国',
        'jp' => '日本', 'japan' => '日本', '日本' => '日本',
        'kr' => '韩国', 'south korea' => '韩国', 'korea' => '韩国', '韩国' => '韩国',
        'sg' => '新加坡', 'singapore' => '新加坡', '新加坡' => '新加坡',
        'gb' => '英国', 'uk' => '英国', 'united kingdom' => '英国', '英国' => '英国',
        'de' => '德国', 'germany' => '德国', '德国' => '德国',
        'fr' => '法国', 'france' => '法国', '法国' => '法国',
        'ru' => '俄罗斯', 'russia' => '俄罗斯', '俄罗斯' => '俄罗斯',
        'ca' => '加拿大', 'canada' => '加拿大', '加拿大' => '加拿大',
        'au' => '澳大利亚', 'australia' => '澳大利亚', '澳大利亚' => '澳大利亚',
        'in' => '印度', 'india' => '印度', '印度' => '印度',
        'th' => '泰国', 'thailand' => '泰国', '泰国' => '泰国',
        'vn' => '越南', 'vietnam' => '越南', '越南' => '越南',
        'my' => '马来西亚', 'malaysia' => '马来西亚', '马来西亚' => '马来西亚',
        'ph' => '菲律宾', 'philippines' => '菲律宾', '菲律宾' => '菲律宾',
        'id' => '印度尼西亚', 'indonesia' => '印度尼西亚', '印度尼西亚' => '印度尼西亚',
        'nl' => '荷兰', 'netherlands' => '荷兰', '荷兰' => '荷兰',
        'tr' => '土耳其', 'turkey' => '土耳其', '土耳其' => '土耳其',
        'ae' => '阿联酋', 'united arab emirates' => '阿联酋', '阿联酋' => '阿联酋',
        'br' => '巴西', 'brazil' => '巴西', '巴西' => '巴西',
    ];
    // 繁体写法兜底（部分接口会返回繁体中文）
    $trad = [
        '美國' => '美国', '澳大利亞' => '澳大利亚', '澳洲' => '澳大利亚',
        '德國' => '德国', '法國' => '法国', '俄羅斯' => '俄罗斯', '韓國' => '韩国',
        '馬來西亞' => '马来西亚', '菲律賓' => '菲律宾', '印度尼西亞' => '印度尼西亚',
        '荷蘭' => '荷兰', '土耳其' => '土耳其', '阿聯酋' => '阿联酋', '巴西' => '巴西',
        '加拿大' => '加拿大', '英國' => '英国', '日本' => '日本', '印度' => '印度',
        '泰國' => '泰国', '越南' => '越南', '新加坡' => '新加坡', '中國' => '中国',
        '中國香港' => '中国香港', '中國澳門' => '中国澳门', '中國台灣' => '中国台湾',
    ];
    if (isset($map[$key])) {
        return $map[$key];
    }
    if (isset($trad[$raw])) {
        return $trad[$raw];
    }
    if (preg_match('/香港|Hong\s*Kong/i', $raw)) {
        return '中国香港';
    }
    if (preg_match('/澳门|Macao|Macau/i', $raw)) {
        return '中国澳门';
    }
    if (preg_match('/台湾|Taiwan/i', $raw)) {
        return '中国台湾';
    }
    return $raw;
}

/** 取判定结果（30 天内缓存）：verdict = cn / foreign / unknown */
function geo_verdict(string $ip): array
{
    if ($ip === '' || is_private_ip($ip)) {
        return ['verdict' => 'cn', 'label' => '内网', 'votes' => ''];
    }

    $st = db()->prepare('SELECT verdict, label, votes FROM ip_verdict
                         WHERE ip = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) LIMIT 1');
    $st->execute([$ip]);
    $cached = $st->fetch();
    if ($cached) {
        return $cached;
    }

    $sources = geo_sources();
    $votes = [];

    // 第一阶段：先问最快的 ip.sb，国内 IP 约 300ms 即可放行
    if (isset($sources['ip.sb'])) {
        $c = geo_fetch_country('ip.sb', sprintf($sources['ip.sb'], urlencode($ip)), $ip);
        unset($sources['ip.sb']);
        if ($c !== null) {
            $votes['ip.sb'] = $c;
            if (country_is_mainland($c) === true) {
                return geo_store($ip, 'cn', '中国', $votes);
            }
        }
    }

    // 第二阶段：其余源并发查询
    $urls = [];
    foreach ($sources as $name => $tpl) {
        $urls[$name] = sprintf($tpl, urlencode($ip));
    }
    foreach (geo_fetch_many($urls, 2.0) as $name => $c) {
        $votes[$name] = $c;
    }

    // 任一源判定大陆 → 放行
    foreach ($votes as $c) {
        if (country_is_mainland($c) === true) {
            return geo_store($ip, 'cn', '中国', $votes);
        }
    }

    // 至少 3 个源返回且全部为海外 → 拦截；否则放行（避免误伤）
    if (count($votes) >= 3) {
        $label = country_label((string)($votes['ip-api'] ?? reset($votes)));
        return geo_store($ip, 'foreign', $label, $votes);
    }

    return geo_store($ip, 'unknown', '', $votes);
}

/**
 * 判断某个地址是否为可信代理（本机 / 内网 / 配置里登记的代理出口）
 */
function proxy_is_trusted(string $ip): bool
{
    if ($ip === '') {
        return false;
    }
    if (in_array($ip, TRUSTED_PROXIES, true)) {
        return true;
    }
    return is_private_ip($ip);   // 本机 / 内网段一律视为可信反代
}

/**
 * 客户端真实 IP
 * X-Forwarded-For 完全由客户端控制，不能无条件采信：
 *   1. 只有 REMOTE_ADDR 是可信任的代理时才看这个头；
 *   2. 取值必须从右往左（最右侧才是最近一跳代理写入的真实地址，最左侧是攻击者伪造的）。
 */
function client_ip(): string
{
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $xff    = trim((string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));

    if ($xff === '' || !proxy_is_trusted($remote)) {
        return substr($remote !== '' ? $remote : '0.0.0.0', 0, 60);
    }

    foreach (array_reverse(explode(',', $xff)) as $hop) {
        $hop = trim($hop);
        if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
            continue;
        }
        if (proxy_is_trusted($hop)) {
            continue;
        }
        return substr($hop, 0, 60);
    }
    return substr($remote, 0, 60);
}
