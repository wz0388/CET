<?php
require_once __DIR__ . '/auth.php';
require_login();
$paper = find_paper($_GET['p'] ?? '');
if (!$paper) {
    header('Location: index.php');
    exit;
}
$answers = load_answers()[$paper['id']] ?? [];
$trans   = load_transcripts()[$paper['id']] ?? [];
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>练习模式 · <?= e($paper['short']) ?></title>
<link rel="stylesheet" href="assets/style.css?v=<?= ASSET_VER ?>">
</head>
<body class="app-body">
<header class="topbar">
    <a class="brand-simple" href="index.php">练习模式</a>
    <div class="topbar-right">
        <span class="mode-switch">
            <a class="active" href="practice.php?p=<?= e($paper['id']) ?>">练习</a>
            <a href="exam.php?p=<?= e($paper['id']) ?>">考试</a>
        </span>
        <a class="link-accent" href="words.php">背单词</a>
        <span class="sync-state" id="syncState">—</span>
        <a class="link-muted" href="account.php"><?= e(user_name()) ?></a>
        <a class="link-muted" href="logout.php">退出</a>
    </div>
</header>

<div class="practice-layout">
    <aside class="side">
        <div class="side-head">
            <h3><?= e($paper['short']) ?></h3>
            <div class="side-progress"><i></i><span>0 / 25</span></div>
        </div>
        <nav class="side-nav" id="sideNav"></nav>
        <div class="side-foot">
            <a class="btn btn-ghost btn-block" href="exam.php?p=<?= e($paper['id']) ?>">切换到考试模式</a>
        </div>
    </aside>

    <main class="main">
        <div class="item-head">
            <div>
                <div class="crumb" id="crumb">Section A · 长对话</div>
                <h1 id="itemTitle">Conversation 1</h1>
            </div>
            <div class="item-nav">
                <button class="btn btn-ghost btn-sm" id="prevItem">上一段</button>
                <button class="btn btn-ghost btn-sm" id="nextItem">下一段</button>
            </div>
        </div>

        <section class="player-card">
            <div class="player-top">
                <div class="player-label">
                    <span class="dot"></span>
                    <span id="playerLabel">听力材料</span>
                    <em id="playerHint">共 0:00</em>
                </div>
                <div class="player-tools">
                    <button class="chip-btn" id="toggleScript" title="显示 / 隐藏本段听力原文">听力原文</button>
                    <button class="chip-btn" id="toggleStem" title="显示 / 隐藏题干">题干</button>
                    <button class="chip-btn" id="toggleQuestions" title="隐藏选项，专注精听">隐藏选项</button>
                    <button class="chip-btn" id="loopBtn" title="循环播放本段">循环 关</button>
                    <select class="speed" id="speed" title="播放速度">
                        <option value="0.75">0.75×</option>
                        <option value="1" selected>1.0×</option>
                        <option value="1.25">1.25×</option>
                        <option value="1.5">1.5×</option>
                    </select>
                </div>
            </div>

            <div class="seek" id="seek">
                <div class="seek-buffer"></div>
                <div class="seek-fill" id="seekFill"></div>
                <div class="seek-knob" id="seekKnob"></div>
            </div>
            <div class="time-row">
                <span id="curTime">0:00</span>
                <span id="durTime">0:00</span>
            </div>

            <div class="controls">
                <button class="icon-btn" id="back5" title="后退 5 秒">−5s</button>
                <button class="play-btn" id="playBtn" title="播放 / 暂停">
                    <svg viewBox="0 0 24 24" class="ico-play"><path d="M8 5v14l11-7z"/></svg>
                    <svg viewBox="0 0 24 24" class="ico-pause"><path d="M6 5h4v14H6zM14 5h4v14h-4z"/></svg>
                </button>
                <button class="icon-btn" id="fwd5" title="前进 5 秒">+5s</button>
                <button class="btn btn-soft btn-sm" id="playQuestions" title="播放本段全部题目">听题目</button>
            </div>
        </section>

        <section class="script-panel" id="scriptPanel" hidden>
            <div class="script-head">
                <span>听力原文</span>
                <em id="scriptTag">语音识别自动转写，仅供精听参考</em>
            </div>
            <div class="script-body" id="scriptBody"></div>
        </section>

        <section class="questions" id="questions"></section>

        <div class="bottom-nav">
            <button class="btn btn-ghost" id="prevItem2">← 上一段</button>
            <button class="btn btn-primary" id="nextItem2">下一段 →</button>
        </div>
    </main>
</div>

<script>
window.__PAPER__ = <?= json_encode($paper, JSON_UNESCAPED_UNICODE) ?>;
window.__ANSWERS__ = <?= json_encode((object)$answers, JSON_UNESCAPED_UNICODE) ?>;
window.__TRANS__ = <?= json_encode((object)$trans, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="assets/app.js?v=<?= ASSET_VER ?>"></script>
<script>App.initPractice();</script>
</body>
</html>
