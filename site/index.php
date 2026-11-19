<?php
require_once __DIR__ . '/auth.php';
require_login();

$me     = current_user();
$papers = load_papers()['papers'] ?? [];
$prog   = progress_all((int)$me['id']);
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>题库</title>
<link rel="stylesheet" href="assets/style.css?v=<?= ASSET_VER ?>">
</head>
<body>
<header class="topbar">
    <a class="brand-simple" href="index.php">题库</a>
    <div class="topbar-right">
        <a class="link-accent" href="words.php">背单词</a>
        <?php if ((int)$me['is_admin'] === 1): ?>
            <a class="link-accent" href="admin.php">后台管理</a>
        <?php endif; ?>
        <a class="link-muted" href="account.php"><?= e(user_name()) ?></a>
        <a class="link-muted" href="logout.php">退出</a>
    </div>
</header>

<main class="page">
    <section class="hero">
        <h1>选择一套试卷开始</h1>
        <p>练习模式可以逐段反复精听，考试模式按真实流程完整播放一次听力。作答进度会实时同步到云端。</p>
    </section>

    <section class="paper-grid">
        <?php foreach ($papers as $p): ?>
        <?php
            $qcount = 0;
            $itemcount = 0;
            foreach ($p['sections'] as $s) {
                $itemcount += count($s['items']);
                foreach ($s['items'] as $it) {
                    $qcount += count($it['questions']);
                }
            }
            $mins = round($p['duration'] / 60);
            $mine = $prog[$p['id']] ?? null;
            $answered = $mine['answered'] ?? 0;
            $pct = $qcount ? min(100, (int)round($answered / $qcount * 100)) : 0;
        ?>
        <article class="paper-card">
            <div class="paper-head">
                <span class="paper-tag"><?= e($p['short']) ?></span>
                <h2><?= e($p['title']) ?></h2>
            </div>
            <div class="paper-meta">
                <span><b><?= $qcount ?></b> 道听力题</span>
                <span><b><?= $itemcount ?></b> 段音频</span>
                <span><b><?= $mins ?></b> 分钟</span>
            </div>
            <div class="paper-progress">
                <div class="bar"><i style="width:<?= $pct ?>%"></i></div>
                <span class="ptext">
                    <?php if ($mine): ?>
                        已作答 <?= $answered ?> / <?= $qcount ?> · <?= e(substr($mine['updated_at'], 5, 11)) ?>
                    <?php else: ?>
                        尚未开始
                    <?php endif; ?>
                </span>
            </div>
            <div class="paper-actions">
                <a class="btn btn-primary" href="practice.php?p=<?= e($p['id']) ?>">练习模式</a>
                <a class="btn btn-ghost" href="exam.php?p=<?= e($p['id']) ?>">考试模式</a>
            </div>
        </article>
        <?php endforeach; ?>
    </section>

    <section class="tips">
        <div class="tip"><b>怎么练最高效？</b> 先用练习模式把每一段听 2–3 遍，做题时点「题目音频」听清问的是什么，最后用考试模式完整过一遍。</div>
        <div class="tip"><b>进度保存在云端</b> 换电脑或手机登录同一账号，作答记录会自动恢复；考试记录可在后台查看。</div>
    </section>
</main>

</body>
</html>
