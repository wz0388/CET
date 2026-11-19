<?php
require_once __DIR__ . '/auth.php';
db();   // 首次访问自动建表并写入管理员

if (current_user()) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $p = (string)($_POST['password'] ?? '');
    if ($u === '' || $p === '') {
        $error = '请输入账号和密码';
    } else {
        $th = login_throttle($u);
        if ($th['blocked']) {
            $error = $th['msg'];
        } else {
            $row = user_find_by_name($u);
            // 账号不存在时也跑一次哈希校验，避免用响应快慢判断账号是否存在
            $hash   = $row ? (string)$row['pass_hash'] : DUMMY_PASS_HASH;
            $passOk = password_verify($p, $hash);

            if ($row && (int)$row['active'] === 1 && $passOk) {
                // 默认算法升级后自动迁移旧哈希
                if (password_needs_rehash($row['pass_hash'], PASSWORD_DEFAULT)) {
                    $st = db()->prepare('UPDATE users SET pass_hash = ? WHERE id = ?');
                    $st->execute([password_hash($p, PASSWORD_DEFAULT), (int)$row['id']]);
                }
                session_regenerate_id(true);
                $_SESSION['uid'] = (int)$row['id'];
                $_SESSION['v']   = SESSION_VERSION;
                // 记录当前密码指纹（若上面刚做过 rehash，要读新的哈希）
                $fpRow = user_find((int)$row['id']);
                $_SESSION['ph'] = pass_fp((string)($fpRow['pass_hash'] ?? ''));

                $st = db()->prepare('UPDATE users SET last_login = NOW(), last_ip = ?, login_count = login_count + 1 WHERE id = ?');
                $st->execute([client_ip(), $row['id']]);
                log_action((int)$row['id'], 'login', '登录');

                header('Location: index.php');
                exit;
            }
            $error = '账号或密码不正确';
            log_action($row['id'] ?? null, 'login_fail', $u);
            usleep(max(200000, (int)$th['delay_us']));
        }
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>登录</title>
<link rel="stylesheet" href="assets/style.css?v=<?= ASSET_VER ?>">
</head>
<body class="login-body">
<div class="login-wrap">
    <div class="login-card">
        <form class="login-form" method="post" autocomplete="off">
            <h2>账号登录</h2>
            <p class="muted">请输入分配给你的账号与密码</p>
            <label class="field">
                <span>账号</span>
                <input type="text" name="username" value="<?= e($_POST['username'] ?? '') ?>" placeholder="用户名" autofocus>
            </label>
            <label class="field">
                <span>密码</span>
                <input type="password" name="password" placeholder="密码">
            </label>
            <?php if ($error): ?>
                <div class="form-error"><?= e($error) ?></div>
            <?php endif; ?>
            <button class="btn btn-primary btn-block" type="submit">登录</button>
            <p class="login-tip">仅限授权用户使用 · 公共电脑请及时退出</p>
        </form>
    </div>
</div>
<?php site_footer(); ?>
</body>
</html>
