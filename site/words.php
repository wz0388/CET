<?php
require_once __DIR__ . '/auth.php';
require_login();

$me = current_user();
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>背单词</title>
<link rel="stylesheet" href="assets/style.css?v=<?= ASSET_VER ?>">
<link rel="stylesheet" href="assets/words.css?v=<?= ASSET_VER ?>">
</head>
<body class="app-body">

<header class="topbar">
    <a class="brand-simple" href="words.php">背单词</a>
    <div class="topbar-right">
        <a class="link-accent" href="index.php">听力题库</a>
        <span class="sync-state" id="syncState">—</span>
        <a class="link-muted" href="account.php"><?= e(user_name()) ?></a>
        <a class="link-muted" href="logout.php">退出</a>
    </div>
</header>

<main class="w-layout">

    <aside class="w-side">
        <div class="w-book">
            <h3>CET-6 核心词汇</h3>
            <p id="bookMeta">加载中…</p>
        </div>

        <div class="w-today">
            <div class="w-today-item"><b id="todayN">0</b><span>今日词数</span></div>
            <div class="w-today-item"><b id="todayAcc">—</b><span>今日正确率</span></div>
            <div class="w-today-item"><b id="totalN">0</b><span>累计练习</span></div>
        </div>

        <div class="w-side-head">
            <span>章节</span>
            <span id="doneCount">0 / 0</span>
        </div>
        <div class="w-chapters" id="chapterGrid"></div>

        <button class="w-wrongbook" id="btnWrongBook" disabled>
            <span>错词本</span>
            <em id="wrongCount">0 词</em>
        </button>

        <div class="w-books">
            <div class="w-book-row">
                <button class="w-book-main" id="btnFav">
                    <span>收藏本 <kbd>\</kbd></span>
                    <em id="favCount">0 词</em>
                </button>
                <button class="w-book-manage" data-book="fav" title="管理收藏本">⋯</button>
            </div>
            <div class="w-book-row">
                <button class="w-book-main" id="btnKnown">
                    <span>熟悉本 <kbd>/</kbd></span>
                    <em id="knownCount">0 词</em>
                </button>
                <button class="w-book-manage" data-book="known" title="管理熟悉本">⋯</button>
            </div>
        </div>
    </aside>

    <section class="w-main">
        <div class="w-topbar">
            <div class="w-chapter-info">
                <div class="w-ch-title" id="chTitle">第 1 章</div>
                <div class="w-ch-bar"><i id="chBar"></i></div>
                <div class="w-ch-num" id="chNum">0 / 20</div>
            </div>
            <div class="w-tools">
                <button class="chip-btn on" id="btnSound" title="答对后自动朗读单词">发音 开</button>
                <button class="chip-btn on" id="btnSfx" title="打字的对错音效">音效 开</button>
                <button class="chip-btn on" id="btnLog" title="显示 / 隐藏最右侧的云端进度">进度 开</button>
                <button class="chip-btn" id="btnReset" title="从本章第一个词重新开始">重练本章</button>
            </div>
        </div>

        <div class="w-card" id="wCard">
            <div class="w-loading" id="wLoading">正在加载词库…</div>

            <div class="w-body" id="wBody" hidden>
                <div class="w-stage s1" id="stageBadge">第 1 遍 · 跟打</div>
                <div class="w-marks" id="wMarks">
                    <span class="w-mark" id="markFav" title="按 \ 加入 / 移出收藏本">★</span>
                    <span class="w-mark" id="markKnown" title="按 / 加入 / 移出熟悉本">✓</span>
                </div>
                <div class="w-toast" id="wToast" hidden></div>

                <div class="w-cn" id="wCn"></div>

                <div class="w-sub" id="wSub">
                    <span class="w-phone" id="wPhone"></span>
                    <button class="w-speak" id="wSpeak" title="朗读这个单词（空格键也可）">
                        <svg viewBox="0 0 24 24"><path d="M12 3v18l-7-5H2V8h3zM16 6a6 6 0 010 12v-2a4 4 0 000-8z"/></svg>
                    </button>
                </div>

                <div class="w-audio-only" id="wAudioOnly" hidden>
                    <button class="w-big-speak" id="wBigSpeak" title="再听一遍（空格键）">
                        <svg viewBox="0 0 24 24"><path d="M12 3v18l-7-5H2V8h3zM16 6a6 6 0 010 12v-2a4 4 0 000-8z"/></svg>
                    </button>
                    <span>点喇叭可重听</span>
                </div>

                <div class="w-target" id="wTarget" hidden></div>

                <div class="w-typing" id="wTyping"></div>
                <div class="w-hint" id="wHint">直接敲键盘输入英文，打错按退格改</div>

                <div class="w-peek" id="wPeek" hidden>
                    <kbd>Tab</kbd><span>按住看提示 · 首次不判错，第二次作废</span>
                </div>

                <div class="w-examples" id="wExamples" hidden></div>

                <div class="w-kb" id="vKeyboard" aria-hidden="true"></div>

                <div class="w-combo" id="wCombo" hidden></div>

                <input id="typeInput" class="w-input" type="text" autocomplete="off"
                       autocorrect="off" autocapitalize="off" spellcheck="false" inputmode="latin">
            </div>
        </div>

        <div class="w-foot">
            <button class="btn btn-ghost btn-sm" id="btnSkip">跳过（Esc）</button>
            <button class="btn btn-ghost btn-sm" id="btnNextCh">下一章</button>
        </div>
    </section>

    <aside class="w-dict" id="dictPanel">
        <div class="w-dict-empty" id="dictEmpty">
            <div class="w-dict-empty-icon">Aa</div>
            <p>答对一个词后<br>这里会出现音标、释义和例句</p>
        </div>

        <div class="w-dict-body" id="dictBody" hidden>
            <div class="w-dict-head">
                <div class="w-dict-title">
                    <h3 id="dWord">—</h3>
                    <div class="w-dict-phone" id="dPhone"></div>
                </div>
                <button class="w-speak" id="dSpeak" title="播放发音（空格键）">
                    <svg viewBox="0 0 24 24"><path d="M12 3v18l-7-5H2V8h3zM16 6a6 6 0 010 12v-2a4 4 0 000-8z"/></svg>
                </button>
            </div>

            <div class="w-dict-defs" id="dDefs"></div>

            <section class="w-dict-sec" id="dExWrap">
                <h4>例句</h4>
                <ul class="w-ex-list" id="dEx"></ul>
            </section>

            <section class="w-dict-sec" id="dPhrWrap">
                <h4>短语</h4>
                <ul class="w-phr-list" id="dPhr"></ul>
            </section>

            <section class="w-dict-sec" id="dSynWrap">
                <h4>近义词</h4>
                <div class="w-syn" id="dSyn"></div>
            </section>
        </div>
    </aside>

    <aside class="w-log" id="logPanel">
        <div class="w-log-head">
            <span>云端进度</span>
            <em id="logCount">最近 0 条</em>
        </div>
        <div class="w-log-list" id="logList"></div>
    </aside>

</main>

<div class="ws-panel" id="searchPanel" hidden>
    <div class="ws-box">
        <div class="ws-head">
            <svg class="ws-icon" viewBox="0 0 24 24"><path d="M10 4a6 6 0 104.47 10L20 19.5 21.5 18l-5.53-5.53A6 6 0 0010 4zm0 2a4 4 0 110 8 4 4 0 010-8z"/></svg>
            <input id="wsInput" type="text" autocomplete="off" autocorrect="off" spellcheck="false"
                   placeholder="输入英文或中文，支持联网联想">
            <kbd class="ws-kbd">'</kbd>
            <button class="ws-close" id="wsClose" title="关闭（' 或 Esc）">×</button>
        </div>

        <div class="ws-body">
            <div class="ws-list" id="wsList"></div>

            <div class="ws-detail" id="wsDetail" hidden>
                <button class="ws-back" id="wsBack">← 返回列表</button>
                <div class="ws-d-head">
                    <div>
                        <h3 id="wsWord">—</h3>
                        <div class="ws-d-phone" id="wsPhone"></div>
                    </div>
                    <button class="w-speak" id="wsSpeak" title="播放发音">
                        <svg viewBox="0 0 24 24"><path d="M12 3v18l-7-5H2V8h3zM16 6a6 6 0 010 12v-2a4 4 0 000-8z"/></svg>
                    </button>
                </div>
                <div class="ws-d-defs" id="wsDefs"></div>
                <ul class="ws-d-ex" id="wsEx"></ul>
                <div class="ws-d-actions">
                    <button class="btn btn-ghost btn-sm" id="wsFav">★ 收藏本</button>
                    <button class="btn btn-ghost btn-sm" id="wsKnown">✓ 熟悉本</button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="w-modal" id="bookModal" hidden>
    <div class="w-modal-card w-book-modal">
        <h2 id="bmTitle">收藏单词本</h2>
        <div class="w-bm-add">
            <input id="bmInput" type="text" autocomplete="off" spellcheck="false" placeholder="输入单词回车添加，如 abandon">
            <button class="btn btn-primary btn-sm" id="bmAdd">添加</button>
        </div>
        <div class="w-bm-tip" id="bmTip"></div>
        <ul class="w-bm-list" id="bmList"></ul>
        <div class="w-modal-actions">
            <button class="btn btn-primary" id="bmPractice">开始练习</button>
            <button class="btn btn-ghost" id="bmClose">关闭</button>
        </div>
    </div>
</div>

<div class="w-modal" id="wModal" hidden>
    <div class="w-modal-card">
        <div class="w-modal-icon" id="mIcon">✓</div>
        <h2 id="mTitle">本章完成</h2>
        <div class="w-modal-stats">
            <div><b id="mRight">0</b><span>答对</span></div>
            <div><b id="mWrong">0</b><span>出错</span></div>
            <div><b id="mAcc">0%</b><span>正确率</span></div>
            <div><b id="mTime">0:00</b><span>用时</span></div>
        </div>
        <div class="w-modal-actions">
            <button class="btn btn-primary" id="mNext">下一章</button>
            <button class="btn btn-ghost" id="mRedo">重练错词</button>
            <button class="btn btn-ghost" id="mClose">留在本章</button>
        </div>
    </div>
</div>

<script src="assets/words.js?v=<?= ASSET_VER ?>"></script>
</body>
</html>
