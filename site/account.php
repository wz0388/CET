<?php
require_once __DIR__ . '/auth.php';
require_login();

$me = current_user();
$error = '';
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old  = (string)($_POST['old'] ?? '');
    $new  = (string)($_POST['new'] ?? '');
    $new2 = (string)($_POST['new2'] ?? '');

    $row = user_find((int)$me['id']);
    if (!$row || !password_verify($old, $row['pass_hash'])) {
        $error = '原密码不正确';
    } elseif (strlen($new) < 10) {
        $error = '新密码至少 10 位';
    } elseif ($new !== $new2) {
        $error = '两次输入的新密码不一致';
    } else {
        user_set_password((int)$me['id'], $new);
        // 改密后旧会话全部失效，但当前这台设备要留下来
        $fresh = user_find((int)$me['id']);
        if ($fresh) {
            $_SESSION['ph'] = pass_fp((string)$fresh['pass_hash']);
        }
        log_action((int)$me['id'], 'pass_change', '修改密码');
        $done = true;
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>我的账号</title>
<link rel="stylesheet" href="assets/style.css?v=<?= ASSET_VER ?>">
</head>
<body>
<header class="topbar">
    <a class="brand-simple" href="index.php">我的账号</a>
    <div class="topbar-right">
        <a class="link-muted" href="index.php">返回题库</a>
        <a class="link-muted" href="logout.php">退出</a>
    </div>
</header>

<main class="page narrow">
    <div class="card">
        <h3>账号信息</h3>
        <table class="tbl kv">
            <tr><th>账号</th><td><?= e($me['username']) ?></td></tr>
            <tr><th>备注 / 姓名</th><td><?= e($me['display_name'] ?: '—') ?></td></tr>
            <tr><th>角色</th><td><?= ((int)$me['is_admin'] === 1) ? '管理员' : '学员' ?></td></tr>
            <tr><th>最近登录</th><td><?= e($me['last_login'] ?? '—') ?></td></tr>
            <tr><th>登录次数</th><td><?= (int)$me['login_count'] ?></td></tr>
        </table>
    </div>

    <div class="card">
        <h3>修改密码</h3>
        <?php if ($done): ?>
            <div class="notice ok">密码已修改，下次登录请使用新密码</div>
        <?php elseif ($error): ?>
            <div class="notice err"><?= e($error) ?></div>
        <?php endif; ?>
        <form method="post" class="form-stack">
            <label class="field"><span>原密码</span><input type="password" name="old" required></label>
            <label class="field"><span>新密码（至少 6 位）</span><input type="password" name="new" required></label>
            <label class="field"><span>确认新密码</span><input type="password" name="new2" required></label>
            <button class="btn btn-primary" type="submit">保存</button>
        </form>
        <p class="hint">忘记密码可联系管理员在后台重置。</p>
    </div>
</main>

</body>
</html>
