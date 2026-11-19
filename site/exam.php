<?php
require_once __DIR__ . '/auth.php';
require_login();
$paper = find_paper($_GET['p'] ?? '');
if (!$paper) {
    header('Location: index.php');
    exit;
}
$answers = load_answers()[$paper['id']] ?? [];
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>考试模式 · <?= e($paper['short']) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= ASSET_VER ?>">
</head>
<body class="app-body">
<header class="topbar">
    <a class="brand-simple" href="index.php">考试模式</a>
    <div class="topbar-right">
        <span class="mode-switch">
            <a href="practice.php?p=<?= e($paper['id']) ?>">练习</a>
            <a class="active" href="exam.php?p=<?= e($paper['id']) ?>">考试</a>
        </span>
        <a class="link-accent" href="words.php">背单词</a>
        <span class="timer" id="timer">30:00</span>
        <span class="sync-state" id="syncState">—</span>
        <a class="link-muted" href="account.php"><?= e(user_name()) ?></a>
        <a class="link-muted" href="logout.php">退出</a>
    </div>
</header>

<main class="exam-layout">
    <div class="exam-main">
        <div class="exam-head">
            <h1><?= e($paper['title']) ?></h1>
            <p>听力理解 · 25 题 · 录音只播放一次，请在播放过程中作答</p>
        </div>

        <div class="exam-player" id="examPlayer">
            <div class="exam-player-label"><span class="dot"></span><span id="examStatus">录音未开始</span></div>
            <div class="seek" id="examSeek">
                <div class="seek-fill" id="examFill"></div>
                <div class="seek-knob" id="examKnob"></div>
            </div>
            <div class="exam-controls">
                <button class="play-btn small" id="examPlay" title="开始 / 暂停">
                    <svg viewBox="0 0 24 24" class="ico-play"><path d="M8 5v14l11-7z"/></svg>
                    <svg viewBox="0 0 24 24" class="ico-pause"><path d="M6 5h4v14H6zM14 5h4v14h-4z"/></svg>
                </button>
                <span class="time-row inline"><span id="examCur">0:00</span> / <span id="examDur">0:00</span></span>
            </div>
        </div>

        <div id="examSections"></div>
    </div>

    <aside class="exam-side">
        <div class="card card-sticky">
            <h3>答题卡</h3>
            <div class="answer-grid" id="answerGrid"></div>
            <div class="answer-stat"><span id="answeredCount">已答 0 / 25</span></div>
            <button class="btn btn-primary btn-block" id="submitBtn">交卷</button>
            <a class="btn btn-ghost btn-block mt-8" href="practice.php?p=<?= e($paper['id']) ?>">去练习模式</a>
        </div>
    </aside>
</main>

<div class="modal" id="startModal">
    <div class="modal-card">
        <h2>准备好开始了吗？</h2>
        <ul class="modal-list">
            <li>听力录音约 <?= round($paper['duration'] / 60) ?> 分钟，开始后连续播放</li>
            <li>共 25 题，限时 30 分钟，可随时暂停或交卷</li>
            <li>建议佩戴耳机，选择安静环境</li>
        </ul>
        <button class="btn btn-primary btn-block" id="startExam">开始播放听力</button>
        <a class="btn btn-ghost btn-block mt-8" href="index.php">返回题库</a>
    </div>
</div>

<div class="modal" id="resultModal">
    <div class="modal-card">
        <h2 id="resultTitle">已交卷</h2>
        <div class="result-score" id="resultScore"></div>
        <div class="result-detail" id="resultDetail"></div>
        <div class="result-actions">
            <a class="btn btn-primary" href="index.php">回到题库</a>
            <button class="btn btn-ghost" id="closeResult">看看答题卡</button>
        </div>
    </div>
</div>

<script>
window.__PAPER__ = <?= json_encode($paper, JSON_UNESCAPED_UNICODE) ?>;
window.__ANSWERS__ = <?= json_encode((object)$answers, JSON_UNESCAPED_UNICODE) ?>;
window.__LIMIT__ = 30 * 60;
</script>
<script src="assets/app.js?v=<?= ASSET_VER ?>"></script>
<script>App.initExam();</script>
</body>
</html>
