<?php
require_once __DIR__ . '/auth.php';
require_admin();

$me   = current_user();
$pdo  = db();
$tab  = $_GET['tab'] ?? 'overview';
$msg  = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf'];

function flash(string $type, string $text): void
{
    $_SESSION['flash'] = ['type' => $type, 'text' => $text];
}

function back(string $tab): void
{
    header('Location: admin.php?tab=' . $tab);
    exit;
}

/* ------------------ 表单处理 ------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        flash('err', '会话已过期，请重试');
        back('users');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'create_user') {
        $username = trim((string)($_POST['username'] ?? ''));
        $display  = trim((string)($_POST['display'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $isAdmin  = !empty($_POST['is_admin']);

        if (!preg_match('/^[A-Za-z0-9_.@-]{3,32}$/', $username)) {
            flash('err', '账号需为 3–32 位字母 / 数字 / 下划线');
            back('users');
        }
        if (user_find_by_name($username)) {
            flash('err', '账号已存在：' . e($username));
            back('users');
        }
        if ($password === '') {
            $password = random_password((int)($_POST['pass_len'] ?? 12));
        }
        user_create($username, $password, $display, $isAdmin);
        log_action((int)$me['id'], 'user_create', $username);
        flash('ok', '已创建账号 <b>' . e($username) . '</b>，初始密码：<code>' . e($password) . '</code>（请立即复制发给使用者）');
        back('users');
    }

    if ($action === 'bulk_create') {
        $prefix  = trim((string)($_POST['prefix'] ?? ''));
        $start   = max(1, (int)($_POST['start_no'] ?? 1));
        $count   = min(100, max(1, (int)($_POST['count'] ?? 10)));
        $width   = ((int)($_POST['width'] ?? 2) === 3) ? 3 : 2;
        $dprefix = trim((string)($_POST['display_prefix'] ?? ''));
        $len     = (int)($_POST['pass_len'] ?? 12);
        $isAdmin = !empty($_POST['is_admin']);

        if (!preg_match('/^[A-Za-z0-9_.-]{2,20}$/', $prefix)) {
            flash('err', '账号前缀需为 2–20 位字母 / 数字 / 下划线');
            back('users');
        }

        $created = [];
        $skipped = [];
        for ($i = 0; $i < $count; $i++) {
            $no    = $start + $i;
            $uname = $prefix . str_pad((string)$no, $width, '0', STR_PAD_LEFT);
            if (user_find_by_name($uname)) {
                $skipped[] = $uname;
                continue;
            }
            $pw = random_password($len);
            user_create($uname, $pw, $dprefix !== '' ? $dprefix . $no : '', $isAdmin);
            $created[] = ['u' => $uname, 'p' => $pw];
        }
        log_action((int)$me['id'], 'user_bulk', 'prefix=' . $prefix . ' count=' . count($created));
        $_SESSION['bulk_result'] = ['created' => $created, 'skipped' => $skipped];
        back('users');
    }

    if ($action === 'reset_pass') {
        $uid = (int)($_POST['uid'] ?? 0);
        $u = user_find($uid);
        if ($u && (int)$u['is_admin'] === 1) {
            flash('err', '管理员账号不支持在后台重置密码，请让本人到「我的账号」自行修改');
            back('users');
        }
        if ($u) {
            $password = trim((string)($_POST['password'] ?? ''));
            if ($password === '') {
                $password = random_password(10);
            }
            user_set_password($uid, $password);
            log_action((int)$me['id'], 'user_reset', $u['username']);
            flash('ok', '账号 <b>' . e($u['username']) . '</b> 的新密码：<code>' . e($password) . '</code>');
        }
        back('users');
    }

    if ($action === 'toggle_active') {
        $uid = (int)($_POST['uid'] ?? 0);
        if ($uid === (int)$me['id']) {
            flash('err', '不能停用自己');
            back('users');
        }
        $pdo->prepare('UPDATE users SET active = 1 - active WHERE id = ?')->execute([$uid]);
        log_action((int)$me['id'], 'user_active', 'uid=' . $uid);
        back('users');
    }

    if ($action === 'toggle_admin') {
        $uid = (int)($_POST['uid'] ?? 0);
        if ($uid === (int)$me['id']) {
            flash('err', '不能修改自己的角色');
            back('users');
        }
        $pdo->prepare('UPDATE users SET is_admin = 1 - is_admin WHERE id = ?')->execute([$uid]);
        log_action((int)$me['id'], 'user_admin', 'uid=' . $uid);
        back('users');
    }

    if ($action === 'delete_user') {
        $uid = (int)($_POST['uid'] ?? 0);
        if ($uid === (int)$me['id']) {
            flash('err', '不能删除自己');
            back('users');
        }
        $u = user_find($uid);
        if ($u) {
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
            $pdo->prepare('DELETE FROM progress WHERE user_id = ?')->execute([$uid]);
            $pdo->prepare('DELETE FROM attempts WHERE user_id = ?')->execute([$uid]);
            log_action((int)$me['id'], 'user_delete', $u['username']);
            flash('ok', '已删除账号 ' . e($u['username']));
        }
        back('users');
    }

    if ($action === 'save_answers') {
        $all = [];
        foreach (load_papers()['papers'] as $p) {
            $raw = (string)($_POST['key_' . $p['id']] ?? '');
            $parsed = [];
            preg_match_all('/(\d{1,2})\s*[\.:：\)]?\s*([A-Da-d])/', $raw, $m, PREG_SET_ORDER);
            foreach ($m as $row) {
                $n = (int)$row[1];
                if ($n >= 1 && $n <= 25) {
                    $parsed[(string)$n] = strtoupper($row[2]);
                }
            }
            if ($parsed) {
                $all[$p['id']] = $parsed;
            }
        }
        $json = json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (@file_put_contents(__DIR__ . '/data/answers.json', $json) === false) {
            flash('err', '写入失败：data 目录不可写');
        } else {
            flash('ok', '参考答案已保存');
        }
        back('answers');
    }

    if ($action === 'del_attempt') {
        $aid = (int)($_POST['aid'] ?? 0);
        if ($aid > 0) {
            $st = $pdo->prepare('DELETE FROM attempts WHERE id = ?');
            $st->execute([$aid]);
            log_action((int)$me['id'], 'attempt_del', 'id=' . $aid);
            flash('ok', '已删除该条交卷记录（云端答题进度不受影响）');
        }
        back('records');
    }
}

/* ------------------ 数据 ------------------ */
$stat = [
    'users'   => (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'admins'  => (int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn(),
    'today'   => (int)$pdo->query("SELECT COUNT(DISTINCT user_id) FROM logs WHERE action = 'login' AND created_at >= CURDATE()")->fetchColumn(),
    'prog'    => (int)$pdo->query('SELECT COUNT(*) FROM progress')->fetchColumn(),
    'attempt' => (int)$pdo->query('SELECT COUNT(*) FROM attempts')->fetchColumn(),
    'fail'    => (int)$pdo->query("SELECT COUNT(*) FROM logs WHERE action = 'login_fail' AND created_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)")->fetchColumn(),
];

$users = $pdo->query('SELECT * FROM users ORDER BY id ASC')->fetchAll();

// 统计每人已答题数（不依赖 MySQL JSON 函数，兼容 5.6/5.7/8.0）
$answeredByUser = [];
foreach ($pdo->query('SELECT user_id, payload FROM progress')->fetchAll() as $row) {
    $d = json_decode($row['payload'], true);
    $n = is_array($d['answers'] ?? null) ? count($d['answers']) : 0;
    $answeredByUser[(int)$row['user_id']] = ($answeredByUser[(int)$row['user_id']] ?? 0) + $n;
}

$attempts = $pdo->query('
    SELECT a.*, u.username FROM attempts a LEFT JOIN users u ON u.id = a.user_id
    ORDER BY a.id DESC LIMIT 50
')->fetchAll();

$logs = $pdo->query('
    SELECT l.*, u.username FROM logs l LEFT JOIN users u ON u.id = l.user_id
    ORDER BY l.id DESC LIMIT 40
')->fetchAll();

/* ------------------ 访问记录 ------------------ */
$vType  = in_array($_GET['vt'] ?? 'all', ['all', 'login', 'guest'], true) ? ($_GET['vt'] ?? 'all') : 'all';
$vRange = in_array($_GET['vr'] ?? 'today', ['today', '7d', '30d', 'all'], true) ? ($_GET['vr'] ?? 'today') : 'today';
$vKw    = trim((string)($_GET['vk'] ?? ''));
$vPageN = max(1, (int)($_GET['vp'] ?? 1));
$vPer   = 50;

$vWhere  = '1=1';
$vParams = [];
if ($vType === 'login') {
    $vWhere .= ' AND v.user_id IS NOT NULL';
} elseif ($vType === 'guest') {
    $vWhere .= ' AND v.user_id IS NULL';
}
if ($vRange === 'today') {
    $vWhere .= ' AND v.created_at >= CURDATE()';
} elseif ($vRange === '7d') {
    $vWhere .= ' AND v.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
} elseif ($vRange === '30d') {
    $vWhere .= ' AND v.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
}
if ($vKw !== '') {
    $vWhere .= ' AND (v.ip LIKE ? OR v.path LIKE ? OR v.ua LIKE ?)';
    $like = '%' . $vKw . '%';
    array_push($vParams, $like, $like, $like);
}

$stV = $pdo->prepare("SELECT COUNT(*) FROM visits v WHERE $vWhere");
$stV->execute($vParams);
$vTotal = (int)$stV->fetchColumn();

$vOffset = ($vPageN - 1) * $vPer;
$stV = $pdo->prepare("SELECT v.*, u.username FROM visits v LEFT JOIN users u ON u.id = v.user_id
                      WHERE $vWhere ORDER BY v.id DESC LIMIT $vPer OFFSET $vOffset");
$stV->execute($vParams);
$visits = $stV->fetchAll();

// 归属地：先查缓存，缺的现场解析（每页最多 12 个，避免拖慢）
$geoMap = geo_map(array_column($visits, 'ip'));
$missing = [];
foreach ($visits as $v) {
    if (!isset($geoMap[$v['ip']]) && $v['ip'] !== '') {
        $missing[$v['ip']] = true;
    }
}
foreach (array_slice(array_keys($missing), 0, 12) as $ip) {
    $row = geo_resolve($ip);
    if ($row) {
        $geoMap[$ip] = $row;
    }
}

$vStat = [
    'today_pv'   => (int)$pdo->query('SELECT COUNT(*) FROM visits WHERE created_at >= CURDATE()')->fetchColumn(),
    'today_uv'   => (int)$pdo->query('SELECT COUNT(DISTINCT ip) FROM visits WHERE created_at >= CURDATE()')->fetchColumn(),
    'today_auth' => (int)$pdo->query('SELECT COUNT(*) FROM visits WHERE created_at >= CURDATE() AND user_id IS NOT NULL')->fetchColumn(),
    'week_pv'    => (int)$pdo->query('SELECT COUNT(*) FROM visits WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)')->fetchColumn(),
    'blocked'    => (int)$pdo->query("SELECT COUNT(*) FROM logs WHERE action = 'block' AND created_at >= CURDATE()")->fetchColumn(),
];

$vPages = max(1, (int)ceil($vTotal / $vPer));
$vQs = function (int $page) use ($vType, $vRange, $vKw) {
    return 'admin.php?tab=visits&vt=' . urlencode($vType) . '&vr=' . urlencode($vRange)
        . '&vk=' . urlencode($vKw) . '&vp=' . $page;
};

$papers = load_papers()['papers'] ?? [];
$answersNow = load_answers();
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>后台管理</title>
<link rel="stylesheet" href="assets/style.css?v=<?= ASSET_VER ?>">
</head>
<body>
<header class="topbar">
    <a class="brand-simple" href="admin.php">后台管理</a>
    <nav class="tabs">
        <a class="<?= $tab === 'overview' ? 'on' : '' ?>" href="admin.php?tab=overview">概览</a>
        <a class="<?= $tab === 'users' ? 'on' : '' ?>" href="admin.php?tab=users">账号管理</a>
        <a class="<?= $tab === 'records' ? 'on' : '' ?>" href="admin.php?tab=records">答题记录</a>
        <a class="<?= $tab === 'visits' ? 'on' : '' ?>" href="admin.php?tab=visits">访问记录</a>
        <a class="<?= $tab === 'answers' ? 'on' : '' ?>" href="admin.php?tab=answers">参考答案</a>
    </nav>
    <div class="topbar-right">
        <a class="link-muted" href="index.php">返回题库</a>
        <a class="link-muted" href="logout.php">退出</a>
    </div>
</header>

<main class="page">
<?php if ($msg): ?>
    <div class="notice <?= $msg['type'] === 'ok' ? 'ok' : 'err' ?>"><?= $msg['text'] ?></div>
<?php endif; ?>

<?php if ($tab === 'overview'): ?>
    <h1 class="page-title">概览</h1>
    <div class="stat-grid">
        <div class="stat"><b><?= $stat['users'] ?></b><span>账号总数</span></div>
        <div class="stat"><b><?= $stat['today'] ?></b><span>今日登录人数</span></div>
        <div class="stat"><b><?= $stat['prog'] ?></b><span>云端进度记录</span></div>
        <div class="stat"><b><?= $stat['attempt'] ?></b><span>考试记录</span></div>
        <div class="stat"><b><?= $stat['admins'] ?></b><span>管理员</span></div>
        <div class="stat"><b><?= $stat['fail'] ?></b><span>近 15 分钟登录失败</span></div>
    </div>

    <div class="card mt-24">
        <h3>最近登录 / 操作</h3>
        <table class="tbl">
            <thead><tr><th>时间</th><th>账号</th><th>动作</th><th>详情</th><th>IP</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $l): ?>
                <tr>
                    <td class="nowrap muted"><?= e($l['created_at']) ?></td>
                    <td><?= e($l['username'] ?? '—') ?></td>
                    <td><span class="tag tag-<?= e($l['action']) ?>"><?= e($l['action']) ?></span></td>
                    <td class="muted"><?= e($l['detail']) ?></td>
                    <td class="muted nowrap"><?= e($l['ip']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$logs): ?><tr><td colspan="5" class="muted center">暂无记录</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($tab === 'users'): ?>
    <h1 class="page-title">账号管理</h1>
    <p class="page-desc">为使用者创建账号；密码留空会自动生成，创建后请立即复制。</p>

    <?php $bulk = $_SESSION['bulk_result'] ?? null; unset($_SESSION['bulk_result']); ?>
    <?php if ($bulk): ?>
    <div class="card">
        <div class="bulk-bar">
            <h3 style="margin:0">本次创建 <?= count($bulk['created']) ?> 个账号</h3>
            <span class="grow"></span>
            <button class="btn btn-soft btn-sm" type="button" id="copyBulk">复制全部（账号,密码）</button>
            <button class="btn btn-ghost btn-sm" type="button" id="csvBulk">下载 CSV</button>
        </div>
        <?php if (!empty($bulk['skipped'])): ?>
            <div class="notice err">以下账号已存在，已跳过：<?= e(implode('、', $bulk['skipped'])) ?></div>
        <?php endif; ?>
        <table class="tbl" id="bulkTable">
            <thead><tr><th style="width:56px">#</th><th>账号</th><th>独立随机密码</th><th style="width:80px"></th></tr></thead>
            <tbody>
            <?php foreach ($bulk['created'] as $i => $c): ?>
                <tr>
                    <td class="muted"><?= $i + 1 ?></td>
                    <td><b><?= e($c['u']) ?></b></td>
                    <td><span class="pw"><?= e($c['p']) ?></span></td>
                    <td class="right"><button class="link-btn copy-one" type="button" data-u="<?= e($c['u']) ?>" data-p="<?= e($c['p']) ?>">复制</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="hint">每个账号一个独立的随机密码（互不相同），密码只在这里显示一次，请立即复制或下载保存；忘记可在下方列表单独重置。</p>
    </div>
    <?php endif; ?>

    <div class="card">
        <form method="post" class="form-inline">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="create_user">
            <label class="fi"><span>账号</span><input name="username" placeholder="如 student01" required></label>
            <label class="fi"><span>备注 / 姓名</span><input name="display" placeholder="选填"></label>
            <label class="fi"><span>密码</span><input name="password" id="newPass" placeholder="留空自动生成"></label>
            <label class="fi"><span>长度</span>
                <select name="pass_len" id="passLen">
                    <option value="8">8 位</option>
                    <option value="12" selected>12 位</option>
                    <option value="16">16 位</option>
                    <option value="20">20 位</option>
                </select>
            </label>
            <button class="btn btn-soft fi-btn" type="button" id="genPass">随机生成</button>
            <label class="fi fi-check"><input type="checkbox" name="is_admin" value="1"><span>管理员</span></label>
            <button class="btn btn-primary btn-sm" type="submit">创建账号</button>
        </form>
        <p class="hint">自动生成的密码包含数字、大小写字母和 <code>!@#$%^&amp;*</code> 等符号，已排除易混淆字符 <code>0 o O 1 i I l L q 9 g</code>；生成后可直接修改。</p>
    </div>

    <div class="card">
        <h3>批量创建</h3>
        <form method="post" class="bulk-form">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="bulk_create">
            <label class="fi"><span>账号前缀</span><input name="prefix" value="stu" required></label>
            <label class="fi"><span>起始编号</span><input name="start_no" type="number" min="1" value="1"></label>
            <label class="fi"><span>数量</span><input name="count" type="number" min="1" max="100" value="10"></label>
            <label class="fi"><span>编号位数</span>
                <select name="width">
                    <option value="2">2 位</option>
                    <option value="3">3 位</option>
                </select>
            </label>
            <label class="fi span2"><span>备注前缀（可选）</span><input name="display_prefix" placeholder="如 高一3班"></label>
            <label class="fi span2"><span>密码长度</span>
                <select name="pass_len">
                    <option value="8">8 位</option>
                    <option value="12" selected>12 位</option>
                    <option value="16">16 位</option>
                </select>
            </label>
            <div class="bulk-actions">
                <label class="fi fi-check"><input type="checkbox" name="is_admin" value="1"><span>创建为管理员</span></label>
                <span class="grow"></span>
                <button class="btn btn-primary btn-sm" type="submit">批量创建</button>
            </div>
        </form>
        <p class="hint">账号规则：前缀 + 补零编号（如 <code>stu01</code> → <code>stu10</code>），已存在的账号会自动跳过；每个账号使用独立随机密码。</p>
    </div>

    <div class="card">
        <table class="tbl">
            <thead>
            <tr><th>#</th><th>账号</th><th>备注</th><th>角色</th><th>状态</th><th>已答</th><th>最近登录</th><th>次数</th><th class="right">操作</th></tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td class="muted"><?= (int)$u['id'] ?></td>
                    <td><b><?= e($u['username']) ?></b></td>
                    <td class="muted"><?= e($u['display_name']) ?></td>
                    <td><?= ((int)$u['is_admin'] === 1) ? '<span class="tag tag-admin">管理员</span>' : '<span class="tag">学员</span>' ?></td>
                    <td><?= ((int)$u['active'] === 1) ? '<span class="dot-ok"></span>正常' : '<span class="dot-off"></span>停用' ?></td>
                    <td><?= (int)($answeredByUser[(int)$u['id']] ?? 0) ?></td>
                    <td class="muted nowrap"><?= $u['last_login'] ? e(substr($u['last_login'], 5, 11)) : '—' ?></td>
                    <td class="muted"><?= (int)$u['login_count'] ?></td>
                    <td class="right nowrap">
                        <?php if ((int)$u['is_admin'] === 1): ?>
                            <span class="muted op-none" title="管理员账号需本人到「我的账号」修改密码">密码本人修改</span>
                        <?php else: ?>
                        <form method="post" class="inline" onsubmit="return confirm('重置该账号密码？');">
                            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="reset_pass">
                            <input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">
                            <button class="link-btn" type="submit">重置密码</button>
                        </form>
                        <?php endif; ?>
                        <?php if ((int)$u['id'] !== (int)$me['id']): ?>
                            <form method="post" class="inline">
                                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="action" value="toggle_admin">
                                <input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">
                                <button class="link-btn" type="submit"><?= ((int)$u['is_admin'] === 1) ? '取消管理' : '设为管理' ?></button>
                            </form>
                            <form method="post" class="inline">
                                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">
                                <button class="link-btn" type="submit"><?= ((int)$u['active'] === 1) ? '停用' : '启用' ?></button>
                            </form>
                            <form method="post" class="inline" onsubmit="return confirm('删除后该账号的进度也会一起删除，确定？');">
                                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="action" value="delete_user">
                                <input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">
                                <button class="link-btn danger" type="submit">删除</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($tab === 'records'): ?>
    <h1 class="page-title">答题记录</h1>
    <p class="page-desc">交卷后自动记录；练习进度按账号实时保存，账号管理里能看到每人的已答题数。</p>
    <div class="card">
        <table class="tbl">
            <thead><tr><th>时间</th><th>账号</th><th>试卷</th><th>模式</th><th>已答</th><th>得分</th><th>用时</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($attempts as $a): ?>
                <tr>
                    <td class="muted nowrap"><?= e($a['created_at']) ?></td>
                    <td><?= e($a['username'] ?? '—') ?></td>
                    <td><?= e($a['paper_id']) ?></td>
                    <td><span class="tag"><?= e($a['mode']) ?></span></td>
                    <td><?= (int)$a['answered'] ?> / <?= (int)$a['total'] ?></td>
                    <td><?= $a['score'] === null ? '—' : (int)$a['score'] ?></td>
                    <td class="muted"><?= $a['duration'] ? gmdate('i:s', (int)$a['duration']) : '—' ?></td>
                    <td class="nowrap">
                        <form method="post" onsubmit="return confirm('删除这条交卷记录？');" style="margin:0">
                            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="action" value="del_attempt">
                            <input type="hidden" name="aid" value="<?= (int)$a['id'] ?>">
                            <button class="btn btn-ghost btn-xs" type="submit">删除</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$attempts): ?><tr><td colspan="8" class="muted center">暂无交卷记录</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($tab === 'visits'): ?>
    <h1 class="page-title">访问记录</h1>
    <p class="page-desc">记录每次页面访问的来源 IP、归属地、请求路径与账号（未登录的记为「访客」）。非中国大陆 IP 已被拦截，拦截次数单独统计。</p>

    <div class="stat-grid">
        <div class="stat"><b><?= $vStat['today_pv'] ?></b><span>今日访问量 PV</span></div>
        <div class="stat"><b><?= $vStat['today_uv'] ?></b><span>今日访客 IP 数</span></div>
        <div class="stat"><b><?= $vStat['today_auth'] ?></b><span>今日登录用户访问</span></div>
        <div class="stat"><b><?= $vStat['week_pv'] ?></b><span>近 7 天访问量</span></div>
        <div class="stat"><b><?= $vStat['blocked'] ?></b><span>今日拦截非大陆访问</span></div>
    </div>

    <div class="card mt-24">
        <form method="get" class="form-inline" style="margin-bottom:14px">
            <input type="hidden" name="tab" value="visits">
            <label class="fi"><span>用户类型</span>
                <select name="vt">
                    <option value="all"   <?= $vType === 'all' ? 'selected' : '' ?>>全部</option>
                    <option value="login" <?= $vType === 'login' ? 'selected' : '' ?>>已登录</option>
                    <option value="guest" <?= $vType === 'guest' ? 'selected' : '' ?>>未登录访客</option>
                </select>
            </label>
            <label class="fi"><span>时间范围</span>
                <select name="vr">
                    <option value="today" <?= $vRange === 'today' ? 'selected' : '' ?>>今天</option>
                    <option value="7d"    <?= $vRange === '7d' ? 'selected' : '' ?>>近 7 天</option>
                    <option value="30d"   <?= $vRange === '30d' ? 'selected' : '' ?>>近 30 天</option>
                    <option value="all"   <?= $vRange === 'all' ? 'selected' : '' ?>>全部</option>
                </select>
            </label>
            <label class="fi"><span>关键字</span><input name="vk" value="<?= e($vKw) ?>" placeholder="IP / 路径 / UA"></label>
            <button class="btn btn-primary btn-sm" type="submit">筛选</button>
            <a class="btn btn-ghost btn-sm" href="admin.php?tab=visits">重置</a>
        </form>

        <div class="bulk-bar">
            <span class="muted" style="font-size:12.5px">共 <?= $vTotal ?> 条 · 第 <?= $vPageN ?> / <?= $vPages ?> 页</span>
        </div>

        <table class="tbl">
            <thead>
            <tr><th>时间</th><th>类型</th><th>账号</th><th>IP</th><th>归属地</th><th>请求路径</th><th>来源 / UA</th></tr>
            </thead>
            <tbody>
            <?php foreach ($visits as $v): ?>
                <?php
                    $g = $geoMap[$v['ip']] ?? null;
                    $place = $g ? trim(($g['country'] ?? '') . ' ' . ($g['region'] ?? '') . ' ' . ($g['city'] ?? '')) : '';
                    if ($place === '') {
                        $place = '—';
                    }
                    $ref = (string)$v['ref'];
                    $refHost = $ref !== '' ? parse_url($ref, PHP_URL_HOST) : '';
                    $ua = (string)$v['ua'];
                    if ($ua === '') {
                        $uaShort = '—';
                    } elseif (function_exists('mb_substr')) {
                        $uaShort = mb_substr($ua, 0, 34, 'UTF-8') . (mb_strlen($ua, 'UTF-8') > 34 ? '…' : '');
                    } else {
                        $uaShort = substr($ua, 0, 34) . (strlen($ua) > 34 ? '…' : '');
                    }
                ?>
                <tr>
                    <td class="muted nowrap" title="<?= e($v['created_at']) ?>"><?= e(substr($v['created_at'], 5)) ?></td>
                    <td>
                        <?php if ($v['user_id']): ?>
                            <span class="tag tag-login">已登录</span>
                        <?php else: ?>
                            <span class="tag">访客</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $v['username'] ? '<b>' . e($v['username']) . '</b>' : '<span class="muted">—</span>' ?></td>
                    <td class="mono nowrap"><?= e($v['ip']) ?></td>
                    <td>
                        <span class="ell" style="max-width:150px" title="<?= e($place . (($g && $g['isp'] !== '') ? ' · ' . $g['isp'] : '')) ?>"><?= e($place) ?></span>
                    </td>
                    <td class="mono"><span class="ell" style="max-width:205px" title="<?= e($v['path']) ?>"><?= e($v['path']) ?></span></td>
                    <td class="muted"><span class="ell" style="max-width:96px" title="<?= e($ref !== '' ? $ref . ' | ' . $ua : $ua) ?>"><?= e($refHost !== '' ? $refHost : $uaShort) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$visits): ?><tr><td colspan="7" class="muted center">暂无访问记录</td></tr><?php endif; ?>
            </tbody>
        </table>

        <?php if ($vPages > 1): ?>
        <div class="pager">
            <?php if ($vPageN > 1): ?><a class="btn btn-ghost btn-sm" href="<?= e($vQs($vPageN - 1)) ?>">上一页</a><?php endif; ?>
            <span class="muted" style="font-size:12.5px"><?= $vPageN ?> / <?= $vPages ?></span>
            <?php if ($vPageN < $vPages): ?><a class="btn btn-ghost btn-sm" href="<?= e($vQs($vPageN + 1)) ?>">下一页</a><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

<?php else: ?>
    <h1 class="page-title">参考答案</h1>
    <p class="page-desc">录入后考试模式交卷即显示得分，练习模式会标出对错。格式随意：<code>1A 2B 3C</code> 或 <code>1:A,2:B</code>。</p>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="save_answers">
        <?php foreach ($papers as $p): ?>
        <div class="card">
            <h3><?= e($p['title']) ?></h3>
            <textarea name="key_<?= e($p['id']) ?>" class="textarea" rows="4" placeholder="例：1A 2C 3B 4D 5A ..."><?php
                $cur = $answersNow[$p['id']] ?? [];
                $parts = [];
                foreach ($cur as $no => $ans) { $parts[] = $no . $ans; }
                echo e(implode(' ', $parts));
            ?></textarea>
        </div>
        <?php endforeach; ?>
        <button class="btn btn-primary" type="submit">保存答案</button>
    </form>
<?php endif; ?>
</main>

<script>
(function () {
  var cfg = <?= json_encode(password_charset(), JSON_UNESCAPED_UNICODE) ?>;
  var pass = document.getElementById('newPass');
  var lenSel = document.getElementById('passLen');
  var btn = document.getElementById('genPass');
  if (!btn || !pass || !lenSel) return;

  function pick(s) {
    var a = new Uint32Array(1);
    crypto.getRandomValues(a);
    return s[a[0] % s.length];
  }
  function gen(n) {
    var pool = cfg.lower + cfg.upper + cfg.digits + cfg.symbols;
    var out = [pick(cfg.lower), pick(cfg.upper), pick(cfg.digits), pick(cfg.symbols)];
    while (out.length < n) out.push(pick(pool));
    for (var i = out.length - 1; i > 0; i--) {
      var j = Math.floor(Math.random() * (i + 1));
      var t = out[i]; out[i] = out[j]; out[j] = t;
    }
    return out.join('');
  }

  btn.addEventListener('click', function () {
    pass.type = 'text';
    pass.value = gen(parseInt(lenSel.value, 10));
    pass.focus();
    pass.select();
    if (navigator.clipboard) { navigator.clipboard.writeText(pass.value).catch(function () {}); }
  });
})();
</script>
<script>
(function () {
  var t = document.getElementById('bulkTable');
  var copy = document.getElementById('copyBulk');
  var csv = document.getElementById('csvBulk');
  if (!t || !copy) return;

  function rows() {
    return Array.prototype.map.call(t.querySelectorAll('tbody tr'), function (tr) {
      var td = tr.querySelectorAll('td');
      return [td[1].textContent.trim(), td[2].textContent.trim()];
    });
  }

  function copyText(text, done) {
    var fallback = function () {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.setAttribute('readonly', '');
      ta.style.cssText = 'position:fixed;left:-9999px;top:0;opacity:0';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); } catch (e) {}
      document.body.removeChild(ta);
      done();
    };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done, fallback);
    } else {
      fallback();
    }
  }

  copy.addEventListener('click', function () {
    var list = rows();
    var text = list.map(function (r) { return r.join(','); }).join('\n');
    copyText(text, function () {
      copy.textContent = '已复制 ' + list.length + ' 条';
      setTimeout(function () { copy.textContent = '复制全部（账号,密码）'; }, 1800);
    });
  });

  t.addEventListener('click', function (ev) {
    var b = ev.target.closest('.copy-one');
    if (!b) return;
    ev.preventDefault();
    var line = b.dataset.u + ',' + b.dataset.p;
    var done = function () {
      var old = b.textContent;
      b.textContent = '已复制';
      setTimeout(function () { b.textContent = old; }, 1500);
    };
    copyText(line, done);
  });

  csv.addEventListener('click', function () {
    var text = '账号,密码\n' + rows().map(function (r) { return r.join(','); }).join('\n');
    var blob = new Blob(['\ufeff' + text], { type: 'text/csv;charset=utf-8' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'accounts-' + new Date().toISOString().slice(0, 10) + '.csv';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 3000);
  });
})();
</script>
</body>
</html>
