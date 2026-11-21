/* 背单词：打字记忆（参考 TypeWords 的交互） */
(function () {
  'use strict';

  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));
  const PER = 20;                       // 每章词数
  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ));
  const todayStr = () => new Date().toISOString().slice(0, 10);
  const fmtDur = (ms) => {
    const s = Math.max(0, Math.round(ms / 1000));
    return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
  };

  const el = {
    grid: $('#chapterGrid'), bookMeta: $('#bookMeta'), doneCount: $('#doneCount'),
    chTitle: $('#chTitle'), chBar: $('#chBar'), chNum: $('#chNum'),
    cn: $('#wCn'), phone: $('#wPhone'), typing: $('#wTyping'), hint: $('#wHint'),
    input: $('#typeInput'), card: $('#wCard'), body: $('#wBody'), loading: $('#wLoading'),
    todayN: $('#todayN'), todayAcc: $('#todayAcc'), totalN: $('#totalN'),
    wrongBook: $('#btnWrongBook'), wrongCount: $('#wrongCount'),
    sound: $('#btnSound'), sfx: $('#btnSfx'), reset: $('#btnReset'), skip: $('#btnSkip'), nextCh: $('#btnNextCh'),
    speak: $('#wSpeak'), modal: $('#wModal'),
    mIcon: $('#mIcon'), mTitle: $('#mTitle'), mRight: $('#mRight'), mWrong: $('#mWrong'),
    mAcc: $('#mAcc'), mTime: $('#mTime'), mNext: $('#mNext'), mRedo: $('#mRedo'), mClose: $('#mClose'),
    sync: $('#syncState'), kb: $('#vKeyboard'), combo: $('#wCombo'),
    stageBadge: $('#stageBadge'), target: $('#wTarget'), audioOnly: $('#wAudioOnly'),
    fav: $('#btnFav'), known: $('#btnKnown'), favCount: $('#favCount'), knownCount: $('#knownCount'),
    markFav: $('#markFav'), markKnown: $('#markKnown'), toast: $('#wToast'),
    bookModal: $('#bookModal'), bmTitle: $('#bmTitle'), bmInput: $('#bmInput'), bmAdd: $('#bmAdd'),
    bmTip: $('#bmTip'), bmList: $('#bmList'), bmPractice: $('#bmPractice'), bmClose: $('#bmClose'),
    layout: $('.w-layout'), logPanel: $('#logPanel'), logList: $('#logList'), logCount: $('#logCount'),
    btnLog: $('#btnLog'),
    searchPanel: $('#searchPanel'), wsInput: $('#wsInput'), wsList: $('#wsList'), wsDetail: $('#wsDetail'),
    wsWord: $('#wsWord'), wsPhone: $('#wsPhone'), wsDefs: $('#wsDefs'), wsEx: $('#wsEx'),
    wsFav: $('#wsFav'), wsKnown: $('#wsKnown'), wsSpeak: $('#wsSpeak'), wsBack: $('#wsBack'),
    wsClose: $('#wsClose'),
    examples: $('#wExamples'), sub: $('#wSub'), bigSpeak: $('#wBigSpeak'), peek: $('#wPeek'),
    dictEmpty: $('#dictEmpty'), dictBody: $('#dictBody'), dWord: $('#dWord'), dPhone: $('#dPhone'),
    dDefs: $('#dDefs'), dExWrap: $('#dExWrap'), dEx: $('#dEx'),
    dPhrWrap: $('#dPhrWrap'), dPhr: $('#dPhr'),
    dSynWrap: $('#dSynWrap'), dSyn: $('#dSyn'), dSpeak: $('#dSpeak'),
    dFav: $('#dFav'), dKnown: $('#dKnown'),
    btnExport: $('#btnExport'), btnImport: $('#btnImport'), importFile: $('#importFile')
  };

  let words = [];
  let chapters = [];
  let state = null;

  let session = { list: [], i: 0, title: '', ch: -1, startTs: 0, right: 0, wrong: 0 };
  let cur = null;
  let typed = '';
  let errThis = false;
  let locked = false;
  let soundOn = true;
  let combo = 0;
  let comboTimer = null;
  let peekCount = 0;
  let peeking = false;

  /* ---------------- 云端进度 ---------------- */
  const Cloud = {
    timer: null,
    badge(text, cls) {
      if (!el.sync) return;
      el.sync.textContent = text;
      el.sync.className = 'sync-state' + (cls ? ' ' + cls : '');
    },
    load() {
      this.badge('同步中…', 'busy');
      return fetch('api.php?action=words', { credentials: 'same-origin' })
        .then((r) => r.json())
        .then((res) => {
          this.badge(res && res.ok ? '云端已连接' : '未同步', res && res.ok ? 'ok' : 'err');
          return (res && res.ok && res.data && res.data.state) || null;
        })
        .catch(() => { this.badge('离线', 'err'); return null; });
    },
    save() {
      this.badge('待同步…', 'busy');
      clearTimeout(this.timer);
      // 连续作答时 debounce 会被不断重置，超过 6 秒强制落盘一次
      if (!this.lastFlush || Date.now() - this.lastFlush > 6000) {
        this.lastFlush = Date.now();
        this.flush();
        return;
      }
      this.timer = setTimeout(() => this.flush(), 1200);
    },
    flush() {
      this.lastFlush = Date.now();
      this.badge('同步中…', 'busy');
      return fetch('api.php?action=save_words', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ state: state })
      }).then((r) => r.json())
        .then((res) => this.badge(res && res.ok ? '已同步 ' + (res.saved_at || '') : '同步失败',
          res && res.ok ? 'ok' : 'err'))
        .catch(() => this.badge('同步失败', 'err'));
    }
  };

  /* ---------------- 状态 ---------------- */
  function blankState() {
    return {
      v: 1, ch: 0, pos: 0, done: {}, wrong: {}, words: {}, fav: {}, known: {}, log: [],
      day: { d: todayStr(), n: 0, right: 0 },
      total: { n: 0, right: 0 }
    };
  }

  function normalize(st) {
    const s = Object.assign(blankState(), st || {});
    // PHP 会把空 map 序列化成 []，数组上写字符串键会被 JSON.stringify 丢掉
    const isMap = (v) => v && typeof v === 'object' && !Array.isArray(v);
    s.done = isMap(s.done) ? s.done : {};
    s.wrong = isMap(s.wrong) ? s.wrong : {};
    s.words = isMap(s.words) ? s.words : {};
    s.fav = isMap(s.fav) ? s.fav : {};
    s.known = isMap(s.known) ? s.known : {};
    s.log = Array.isArray(s.log) ? s.log.filter((x) => x && x.w) : [];
    s.total = s.total && typeof s.total === 'object' ? s.total : { n: 0, right: 0 };
    if (!s.day || s.day.d !== todayStr()) s.day = { d: todayStr(), n: 0, right: 0 };
    if (typeof s.ch !== 'number' || s.ch < 0) s.ch = 0;
    if (typeof s.pos !== 'number' || s.pos < 0) s.pos = 0;
    return s;
  }

  function bumpToday(right) {
    if (!state.day || state.day.d !== todayStr()) state.day = { d: todayStr(), n: 0, right: 0 };
    state.day.n += 1;
    if (right) state.day.right += 1;
    state.total.n += 1;
    if (right) state.total.right += 1;
  }

  /* ---------------- 四遍记忆阶段 ---------------- */
  const STAGE_NAME = { 1: '跟打', 2: '例句挖空', 3: '只听发音', 4: '只看中文', 5: '已掌握' };
  const requeued = new Set();
  let exSeq = 0;

  function stageOf(w) {
    const v = state && state.words ? state.words[w] : 0;
    return v >= 1 && v <= 5 ? v : 1;
  }

  function setStage(w, v) {
    if (!state.words) state.words = {};
    state.words[w] = Math.max(1, Math.min(5, v));
  }

  // 出错时本遍作废：把词排到本轮末尾重来（同一个词只补一次）
  function requeue(word) {
    if (requeued.has(word)) return;
    requeued.add(word);
    const item = session.list.find((x) => x.w === word);
    if (item && session.list.length < 240) session.list.push(item);
  }

  function maskWord(text, word) {
    if (!word) return esc(text || '');
    const parts = String(text || '').split(new RegExp('(' + escRe(word) + ')', 'ig'));
    const mask = '▁'.repeat(Math.min(word.length, 12));
    return parts.map((p, i) => (i % 2 ? '<span class="mask">' + mask + '</span>' : esc(p))).join('');
  }

  function renderExamples(word, st) {
    if (st !== 1 && st !== 2) {
      el.examples.hidden = true;
      el.examples.innerHTML = '';
      return;
    }
    el.examples.hidden = false;
    el.examples.innerHTML = '<div class="w-skel" style="width:72%"></div>';
    const seq = ++exSeq;
    fetchDict(word).then((d) => {
      if (seq !== exSeq) return;
      if (!d || !d.ok || !d.ex || !d.ex.length) {
        el.examples.hidden = true;
        return;
      }
      el.examples.innerHTML = d.ex.slice(0, 2).map((e) => {
        const en = st === 2 ? maskWord(e.en, word) : markWord(e.en, word);
        const zh = st === 2 ? maskWord(e.zh, word) : esc(e.zh || '');
        return `<div class="w-ex"><div class="en">${en}</div><div class="zh">${zh}</div></div>`;
      }).join('');
    });
  }

  function baseHint(st) {
    return st === 1 ? '照着上面的单词打一遍，打错按退格改'
      : st === 2 ? '例句里挖空的就是它，凭记忆打出来'
        : st === 3 ? '听发音拼写，空格键可重听'
          : '只看中文释义，写出对应的英文';
  }

  // Tab 按住看提示：第一次不判错，第二次本遍作废
  function startPeek() {
    if (!cur || locked) return;
    const st = stageOf(cur.w);
    if (st === 1) return;
    if (!peeking) {
      peekCount += 1;
      peeking = true;
      if (peekCount >= 2) errThis = true;
    }
    el.target.hidden = false;
    el.target.textContent = cur.w;
    el.target.classList.add('peeking');
    el.peek.className = 'w-peek' + (peekCount >= 2 ? ' bad' : ' used');
    el.hint.className = 'w-hint' + (peekCount >= 2 ? ' warn' : '');
    el.hint.textContent = peekCount >= 2 ? '第二次看提示，本遍作废' : '提示已显示 · 本次不判错';
  }

  function endPeek() {
    if (!peeking) return;
    peeking = false;
    if (!cur) return;
    const st = stageOf(cur.w);
    el.target.classList.remove('peeking');
    if (locked) { el.target.hidden = true; el.target.textContent = ''; return; }
    el.target.hidden = st !== 1;
    el.target.textContent = st === 1 ? cur.w : '';
    el.hint.className = 'w-hint' + (peekCount >= 2 ? ' warn' : '');
    el.hint.textContent = baseHint(st) + (peekCount >= 2 ? ' · 本遍已作废' : '');
  }

  function renderPrompt() {
    const w = cur.w;
    const st = stageOf(w);
    el.stageBadge.textContent = st >= 5 ? '已掌握 · 再巩固' : `第 ${st} 遍 · ${STAGE_NAME[st]}`;
    el.stageBadge.className = 'w-stage s' + Math.min(st, 5);

    const showSub = st <= 2;      // 音标 + 小喇叭只在 1、2 遍
    const showSpeak = st !== 4;   // 第 4 遍只给中文

    // 一到四遍都显示中文释义
    el.cn.hidden = false;
    el.cn.textContent = cur.t;
    el.sub.hidden = !showSub;
    el.phone.textContent = showSub && cur.p ? '/' + cur.p + '/' : '';
    el.speak.hidden = !showSpeak;
    el.audioOnly.hidden = st !== 3;
    el.target.hidden = st !== 1;
    el.target.textContent = st === 1 ? w : '';

    el.hint.className = 'w-hint' + (peekCount >= 2 ? ' warn' : '');
    el.hint.textContent = baseHint(st) + (peekCount >= 2 ? ' · 本遍已作废' : '');
    el.peek.hidden = st === 1;
    // 键盘四遍都显示；只有第 1 遍才会高亮「下一个该按的键」
    el.kb.hidden = false;
    el.peek.className = 'w-peek' + (peekCount >= 2 ? ' bad' : peekCount === 1 ? ' used' : '');
    el.target.classList.remove('peeking');
    renderExamples(w, st);
  }

  /* ---------------- 渲染 ---------------- */
  function renderStats() {
    const day = state.day || { n: 0, right: 0 };
    el.todayN.textContent = day.n;
    el.todayAcc.textContent = day.n ? Math.round((day.right / day.n) * 100) + '%' : '—';
    el.totalN.textContent = state.total.n;
    const wrongN = Object.keys(state.wrong).length;
    el.wrongCount.textContent = wrongN + ' 词';
    el.wrongBook.disabled = wrongN === 0;
  }

  function renderGrid() {
    let html = '';
    chapters.forEach((list, i) => {
      html += `<button data-ch="${i}" title="第 ${i + 1} 章 · ${list.length} 词">${i + 1}</button>`;
    });
    el.grid.innerHTML = html;
    paintGrid();
  }

  function paintGrid() {
    const doneN = Object.keys(state.done).length;
    Array.from(el.grid.children).forEach((b, i) => {
      b.className = state.done[i] ? 'done' : (i === state.ch ? 'cur' : '');
    });
    el.doneCount.textContent = doneN + ' / ' + chapters.length;
    el.bookMeta.textContent = `${words.length} 词 · 每章 ${PER} 词 · 已完成 ${doneN} 章`;
  }

  function renderHead() {
    const total = session.list.length || PER;
    const idx = Math.min(session.i, total);
    el.chTitle.textContent = session.title;
    el.chNum.textContent = `${idx} / ${total}`;
    el.chBar.style.width = total ? Math.round((idx / total) * 100) + '%' : '0%';
  }

  /* ---------------- 打字区渲染 / 虚拟键盘 ---------------- */
  const KB_ROWS = [
    ['q', 'w', 'e', 'r', 't', 'y', 'u', 'i', 'o', 'p'],
    ['a', 's', 'd', 'f', 'g', 'h', 'j', 'k', 'l'],
    ['z', 'x', 'c', 'v', 'b', 'n', 'm', '-', "'", '\u232b'],
  ];
  let typingSpans = [];
  let tailSpan = null;
  const keyMap = new Map();

  function buildKeyboard() {
    if (!el.kb) return;
    el.kb.innerHTML = '';
    keyMap.clear();
    KB_ROWS.forEach((row, ri) => {
      const line = document.createElement('div');
      line.className = 'w-kb-row' + (ri === 1 ? ' row-2' : ri === 2 ? ' row-3' : '');
      row.forEach((k) => {
        const key = document.createElement('span');
        key.className = 'w-key' + (k === '\u232b' ? ' wide' : '');
        key.dataset.k = k;
        key.textContent = k;
        line.appendChild(key);
        keyMap.set(k, key);
      });
      el.kb.appendChild(line);
    });
  }

  function buildTyping() {
    el.typing.innerHTML = '';
    typingSpans = [];
    const target = cur ? cur.w : '';
    for (let i = 0; i < target.length; i++) {
      const sp = document.createElement('span');
      sp.className = 'rest';
      sp.textContent = '\u00b7';
      el.typing.appendChild(sp);
      typingSpans.push(sp);
    }
    tailSpan = document.createElement('span');
    tailSpan.className = 'tail';
    el.typing.appendChild(tailSpan);
    paintTyping();
  }

  function paintTyping() {
    if (!cur) return;
    const target = cur.w;
    for (let i = 0; i < typingSpans.length; i++) {
      const sp = typingSpans[i];
      if (i < typed.length) {
        sp.textContent = typed[i];
        sp.className = typed[i].toLowerCase() === target[i].toLowerCase() ? 'ok' : 'bad';
      } else {
        sp.textContent = '\u00b7';
        sp.className = 'rest' + (i === typed.length ? ' caret' : '');
      }
    }
    tailSpan.className = 'tail' + (typed.length >= target.length ? ' caret' : '');
    markNextKey();
  }

  // 高亮「下一个该按的键」
  function markNextKey() {
    keyMap.forEach((k) => k.classList.remove('next'));
    if (!cur) return;
    if (stageOf(cur.w) !== 1) return;   // 2/3/4 遍不给键位提示
    const ch = cur.w[typed.length];
    if (!ch) return;
    const k = keyMap.get(ch.toLowerCase());
    if (k) k.classList.add('next');
  }

  function flashKey(ch, ok) {
    const k = keyMap.get(String(ch).toLowerCase());
    if (!k) return;
    const cls = ok ? 'hit' : 'miss';
    k.classList.add(cls);
    setTimeout(() => k.classList.remove(cls), 150);
  }

  /* ---------------- 发音（真人音频 + 浏览器语音兜底） ---------------- */
  const audioPool = new Map();

  function audioUrl(w) {
    return 'audio.php?w=' + encodeURIComponent(w);
  }

  function getAudio(w) {
    let a = audioPool.get(w);
    if (a) return a;
    a = new Audio();
    a.preload = 'auto';
    a.addEventListener('error', () => { a.dataset.fail = '1'; });
    a.src = audioUrl(w);
    audioPool.set(w, a);
    if (audioPool.size > 18) {
      const first = audioPool.keys().next().value;
      audioPool.delete(first);
    }
    return a;
  }

  function tts(w) {
    if (!window.speechSynthesis) return;
    try {
      window.speechSynthesis.cancel();
      const u = new SpeechSynthesisUtterance(w);
      u.lang = 'en-US';
      u.rate = 0.95;
      window.speechSynthesis.speak(u);
    } catch (e) {}
  }

  // 浏览器在用户交互前会拦掉自动播放：记下来，等第一次按键再补播
  let retryWord = '';

  function playAudio(w) {
    if (!soundOn || !w) return;
    const a = getAudio(w);
    if (a.dataset.fail) { tts(w); return; }
    try {
      a.currentTime = 0;
      const p = a.play();
      if (p && p.catch) {
        p.catch((err) => {
          if (err && err.name === 'NotAllowedError') retryWord = w;
          else tts(w);
        });
      }
    } catch (e) { tts(w); }
  }

  /* ---------------- 打字音效（WebAudio 实时合成，无音频文件） ---------------- */
  let sfxOn = true;
  try { sfxOn = localStorage.getItem('cet.sfx') !== '0'; } catch (e) {}
  let sfxCtx = null;

  function sfxReady() {
    if (!sfxOn) return null;
    try {
      const AC = window.AudioContext || window.webkitAudioContext;
      if (!AC) return null;
      if (!sfxCtx) sfxCtx = new AC();
      if (sfxCtx.state === 'suspended') sfxCtx.resume();
      return sfxCtx;
    } catch (e) { return null; }
  }

  // ok: 敲对 - 清脆短促；bad: 敲错 - 低沉短音；done: 整词完成 - 两声上行
  function beep(kind) {
    const ctx = sfxReady();
    if (!ctx) return;
    const t0 = ctx.currentTime;
    if (kind === 'done') {
      [880, 1318].forEach((hz, i) => {
        const o = ctx.createOscillator();
        const g = ctx.createGain();
        const st = t0 + i * 0.075;
        o.type = 'sine';
        o.frequency.value = hz;
        o.connect(g); g.connect(ctx.destination);
        g.gain.setValueAtTime(0.0001, st);
        g.gain.exponentialRampToValueAtTime(0.055, st + 0.012);
        g.gain.exponentialRampToValueAtTime(0.0001, st + 0.17);
        o.start(st); o.stop(st + 0.19);
      });
      return;
    }
    const o = ctx.createOscillator();
    const g = ctx.createGain();
    if (kind === 'bad') {
      const f = ctx.createBiquadFilter();
      f.type = 'lowpass';
      f.frequency.value = 820;
      o.type = 'square';
      o.frequency.setValueAtTime(196, t0);
      o.frequency.exponentialRampToValueAtTime(112, t0 + 0.12);
      o.connect(f); f.connect(g); g.connect(ctx.destination);
      g.gain.setValueAtTime(0.0001, t0);
      g.gain.exponentialRampToValueAtTime(0.07, t0 + 0.008);
      g.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.14);
      o.start(t0); o.stop(t0 + 0.15);
    } else {
      o.type = 'sine';
      o.frequency.value = 1180 + Math.random() * 90;
      o.connect(g); g.connect(ctx.destination);
      g.gain.setValueAtTime(0.0001, t0);
      g.gain.exponentialRampToValueAtTime(0.05, t0 + 0.004);
      g.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.055);
      o.start(t0); o.stop(t0 + 0.06);
    }
  }

  function applySfx() {
    if (!el.sfx) return;
    el.sfx.classList.toggle('on', sfxOn);
    el.sfx.textContent = '音效 ' + (sfxOn ? '开' : '关');
  }

  /* ---------------- 词典面板 ---------------- */
  const dictCache = new Map();
  const dictPending = new Map();
  let dictSeq = 0;

  function escRe(s) {
    return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }

  function markWord(en, word) {
    if (!word) return esc(en);
    const parts = String(en).split(new RegExp('(' + escRe(word) + ')', 'ig'));
    return parts.map((p, i) => (i % 2 ? '<b>' + esc(p) + '</b>' : esc(p))).join('');
  }

  function fetchDict(word) {
    if (!word) return Promise.resolve(null);
    if (dictCache.has(word)) return Promise.resolve(dictCache.get(word));
    if (dictPending.has(word)) return dictPending.get(word);
    const p = fetch('dict.php?w=' + encodeURIComponent(word), { credentials: 'same-origin' })
      .then((r) => r.json())
      .then((d) => { dictCache.set(word, d); dictPending.delete(word); return d; })
      .catch(() => { dictPending.delete(word); return null; });
    dictPending.set(word, p);
    return p;
  }

  function prefetch(words) {
    words.forEach((w) => {
      if (!w) return;
      fetchDict(w);
      getAudio(w);
    });
  }

  function dictSkeleton() {
    el.dPhone.textContent = '';
    el.dDefs.innerHTML = '<div class="w-skel" style="width:88%"></div><div class="w-skel" style="width:62%"></div>';
    el.dExWrap.hidden = false;
    el.dEx.innerHTML = '<li><div class="w-skel" style="width:94%"></div><div class="w-skel" style="width:66%"></div></li>';
    el.dPhrWrap.hidden = true;
    el.dSynWrap.hidden = true;
  }

  let dictWord = '';

  // 词典栏里的收藏 / 熟悉按钮（与键盘 \ 和 / 等价，这里只做屏幕入口）
  function updateDictActs() {
    if (!el.dFav || !el.dKnown) return;
    const fav = !!dictWord && inBook('fav', dictWord);
    const known = !!dictWord && inBook('known', dictWord);
    el.dFav.classList.toggle('on', fav);
    el.dKnown.classList.toggle('on', known);
    const ft = el.dFav.querySelector('.tx');
    const kt = el.dKnown.querySelector('.tx');
    if (ft) ft.textContent = fav ? '已收藏' : '收藏';
    if (kt) kt.textContent = known ? '已熟悉' : '熟悉';
  }

  function showDict(word) {
    if (!word) return;
    dictWord = word;
    updateDictActs();
    if (el.dictEmpty) el.dictEmpty.hidden = true;
    if (el.dictBody) el.dictBody.hidden = false;
    el.dWord.textContent = word;
    dictSkeleton();
    const seq = ++dictSeq;
    fetchDict(word).then((d) => { if (seq === dictSeq) renderDict(d, word); });
  }

  function glossOf(w) {
    const hit = words.find((x) => x.w === w);
    return hit ? hit.t : '';
  }

  function renderDict(d, word) {
    if (!d || !d.ok) {
      el.dPhone.textContent = '';
      el.dDefs.innerHTML = '<p>' + esc(glossOf(word) || '释义暂缺，可点击发音或换个词试试。') + '</p>';
      el.dExWrap.hidden = true;
      el.dPhrWrap.hidden = true;
      el.dSynWrap.hidden = true;
      return;
    }
    el.dWord.textContent = d.w || word;
    const ph = [];
    if (d.us) ph.push('US /' + d.us + '/');
    if (d.uk && d.uk !== d.us) ph.push('UK /' + d.uk + '/');
    el.dPhone.textContent = ph.join('   ');

    el.dDefs.innerHTML = (d.defs && d.defs.length)
      ? d.defs.map((x) => '<p>' + esc(x) + '</p>').join('')
      : '<p>' + esc(d.w || word) + '</p>';

    if (d.ex && d.ex.length) {
      el.dExWrap.hidden = false;
      el.dEx.innerHTML = d.ex.map((e) => `<li>
          <div class="en">${markWord(e.en, d.w || word)}</div>
          <div class="zh">${esc(e.zh || '')}</div>
        </li>`).join('');
    } else {
      el.dExWrap.hidden = true;
    }

    if (d.phrs && d.phrs.length) {
      el.dPhrWrap.hidden = false;
      el.dPhr.innerHTML = d.phrs.map((p) => `<li><span class="w">${esc(p.w)}</span><span class="t">${esc(p.t)}</span></li>`).join('');
    } else {
      el.dPhrWrap.hidden = true;
    }

    if (d.syn && d.syn.length) {
      el.dSynWrap.hidden = false;
      el.dSyn.innerHTML = d.syn.map((s) => `<div class="row">
          <span class="pos">${esc(s.pos || '')}</span>${s.w.map((x) => `<span class="w">${esc(x)}</span>`).join('')}
        </div>`).join('');
    } else {
      el.dSynWrap.hidden = true;
    }
  }

  function showCombo(n) {
    if (!el.combo) return;
    el.combo.textContent = n + ' 连击';
    el.combo.hidden = false;
    el.combo.style.animation = 'none';
    void el.combo.offsetWidth;
    el.combo.style.animation = '';
    clearTimeout(comboTimer);
    comboTimer = setTimeout(() => { el.combo.hidden = true; }, 820);
  }

  /* ---------------- 云端进度（最右一列，最近 10 条） ---------------- */
  let logOn = true;
  try { logOn = localStorage.getItem('cet.logOn') !== '0'; } catch (e) {}

  function fmtClock(ts) {
    const d = new Date((ts || 0) * 1000);
    const p = (n) => String(n).padStart(2, '0');
    return p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
  }

  function pushLog(word, stage, ok) {
    if (!state.log) state.log = [];
    state.log.unshift({ t: Math.floor(Date.now() / 1000), w: word, s: stage, ok: ok ? 1 : 0 });
    if (state.log.length > 20) state.log.length = 20;
    renderLog();
  }

  function renderLog() {
    if (!el.logList) return;
    const items = (state.log || []).slice(0, 10);
    if (el.logCount) el.logCount.textContent = '最近 ' + items.length + ' 条';
    if (!items.length) {
      el.logList.innerHTML = '<div class="wl-empty">还没有记录<br>答完一个词就会出现在这里</div>';
      return;
    }
    el.logList.innerHTML = items.map((it) => `<div class="wl-item ${it.ok ? 'ok' : 'bad'}">
        <div class="wl-time">${fmtClock(it.t)}</div>
        <div class="wl-main">
          <span class="wl-w">${esc(it.w)}</span>
          <span class="wl-s">第 ${it.s} 遍</span>
          <span class="wl-r">${it.ok ? '正确' : '错误'}</span>
        </div>
      </div>`).join('');
  }

  function applyLog() {
    if (!el.logPanel || !el.layout) return;
    el.layout.classList.toggle('log-off', !logOn);
    if (el.btnLog) {
      el.btnLog.classList.toggle('on', logOn);
      el.btnLog.textContent = '进度 ' + (logOn ? '开' : '关');
    }
  }

  /* ---------------- 联网搜词 ---------------- */
  let searchRows = [];
  let searchTimer = null;
  let searchSeq = 0;
  let wsWord = '';

  function localMatches(q) {
    const s = q.toLowerCase();
    const pre = [];
    const mid = [];
    for (let i = 0; i < words.length; i++) {
      const x = words[i];
      const lw = x.w.toLowerCase();
      if (lw.indexOf(s) === 0) {
        pre.push(x);
        if (pre.length >= 8) break;
      } else if (mid.length < 8 && (lw.indexOf(s) > 0 || (q && x.t.indexOf(q) >= 0))) {
        mid.push(x);
      }
    }
    return pre.concat(mid).slice(0, 8).map((x) => ({ w: x.w, t: x.t, net: false }));
  }

  function paintSearch(placeholder) {
    if (!searchRows.length) {
      el.wsList.innerHTML = '<div class="ws-empty">' + (placeholder || '没有找到，换个关键词试试') + '</div>';
      return;
    }
    el.wsList.innerHTML = searchRows.map((x, i) => `<div class="ws-row" data-i="${i}">
        <span class="w">${esc(x.w)}</span>
        <span class="t">${esc(x.t || '')}</span>
        <span class="src${x.net ? ' net' : ''}">${x.net ? '联网' : '词库'}</span>
      </div>`).join('');
    $$('.ws-row', el.wsList).forEach((row) => {
      row.onclick = () => showDetail(searchRows[+row.dataset.i].w);
    });
  }

  function onSearchInput() {
    const q = (el.wsInput.value || '').trim();
    clearTimeout(searchTimer);
    if (!q) {
      searchRows = [];
      paintSearch('输入英文或中文开始联想，支持联网');
      return;
    }
    searchRows = localMatches(q);
    if (searchRows.length) paintSearch();
    else paintSearch('本地词库没匹配，正在联网搜索…');

    const seq = ++searchSeq;
    searchTimer = setTimeout(() => {
      fetch('suggest.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then((r) => r.json())
        .then((d) => {
          if (seq !== searchSeq) return;
          const seen = new Set(searchRows.map((x) => x.w.toLowerCase()));
          ((d && d.list) || []).forEach((x) => {
            if (!seen.has(x.w.toLowerCase())) {
              seen.add(x.w.toLowerCase());
              searchRows.push({ w: x.w, t: x.t, net: true });
            }
          });
          paintSearch('没有找到，换个关键词试试');
        })
        .catch(() => { if (seq === searchSeq) paintSearch('联网失败，只能搜本地词库'); });
    }, 220);
  }

  function updateDetailButtons() {
    const local = byWord.get(wsWord.toLowerCase());
    const can = !!local;
    [[el.wsFav, 'fav'], [el.wsKnown, 'known']].forEach(([btn, k]) => {
      if (!btn) return;
      btn.disabled = !can;
      btn.classList.toggle('on', can && inBook(k, local.w));
      btn.title = can ? '点一下加入 / 移出' : '不在词库里，只能查看';
    });
  }

  function showDetail(w) {
    wsWord = w;
    el.wsList.hidden = true;
    el.wsDetail.hidden = false;
    el.wsWord.textContent = w;
    el.wsPhone.textContent = '';
    const local = byWord.get(w.toLowerCase());
    el.wsDefs.innerHTML = local ? '<p>' + esc(local.t) + '</p>'
      : '<div class="w-skel" style="width:70%"></div>';
    el.wsEx.innerHTML = '';
    updateDetailButtons();
    fetchDict(w).then((d) => {
      if (wsWord !== w) return;
      if (!d || !d.ok) {
        if (!local) el.wsDefs.innerHTML = '<p>没查到这个单词</p>';
        return;
      }
      el.wsWord.textContent = d.w || w;
      const ph = [];
      if (d.us) ph.push('US /' + d.us + '/');
      if (d.uk && d.uk !== d.us) ph.push('UK /' + d.uk + '/');
      el.wsPhone.textContent = ph.join('   ');
      if (d.defs && d.defs.length) {
        el.wsDefs.innerHTML = d.defs.map((x) => '<p>' + esc(x) + '</p>').join('');
      }
      el.wsEx.innerHTML = (d.ex || []).slice(0, 2).map((e) => `<li>
          <div class="en">${markWord(e.en, d.w || w)}</div>
          <div class="zh">${esc(e.zh || '')}</div></li>`).join('');
      updateDetailButtons();
    });
  }

  function openSearch() {
    el.searchPanel.hidden = false;
    el.wsInput.value = '';
    el.wsList.hidden = false;
    el.wsDetail.hidden = true;
    searchRows = [];
    paintSearch('输入英文或中文开始联想，支持联网');
    setTimeout(() => { try { el.wsInput.focus(); } catch (e) {} }, 40);
  }

  // 关闭时清空搜索框
  function closeSearch() {
    el.searchPanel.hidden = true;
    el.wsInput.value = '';
    searchRows = [];
    el.wsList.innerHTML = '';
    el.wsDetail.hidden = true;
    el.wsList.hidden = false;
    clearTimeout(searchTimer);
    searchSeq++;
    focusInput();
  }

  /* ---------------- 收藏本 / 熟悉本 ---------------- */
  const BOOKS = {
    fav: { key: 'fav', name: '收藏本', mark: '★' },
    known: { key: 'known', name: '熟悉本', mark: '✓' },
  };
  let byWord = new Map();
  let toastTimer = null;
  let bookWhich = 'fav';

  function inBook(which, w) {
    return !!(state[which] && state[which][w]);
  }

  function bookSize(which) {
    return state[which] ? Object.keys(state[which]).length : 0;
  }

  function showToast(text) {
    if (!el.toast) return;
    el.toast.textContent = text;
    el.toast.hidden = false;
    el.toast.style.animation = 'none';
    void el.toast.offsetWidth;
    el.toast.style.animation = '';
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { el.toast.hidden = true; }, 1100);
  }

  function renderBooks() {
    if (el.favCount) el.favCount.textContent = bookSize('fav') + ' 词';
    if (el.knownCount) el.knownCount.textContent = bookSize('known') + ' 词';
    if (el.fav) el.fav.disabled = false;
    if (el.known) el.known.disabled = false;
    if (el.markFav) el.markFav.classList.toggle('on', !!cur && inBook('fav', cur.w));
    if (el.markKnown) el.markKnown.classList.toggle('on', !!cur && inBook('known', cur.w));
  }

  // 手动加/删：只在这两个本里生效，不做任何自动增删
  function toggleBook(which, word) {
    if (!word) return;
    if (!state[which]) state[which] = {};
    const on = !inBook(which, word);
    if (on) state[which][word] = 1;
    else delete state[which][word];
    Cloud.save();
    renderBooks();
    renderBookList();
    updateDictActs();
    showToast((on ? '已加入' : '已移出') + BOOKS[which].name + ' · ' + word);
  }

  function renderBookList() {
    if (!el.bmList || el.bookModal.hidden) return;
    const keys = Object.keys(state[bookWhich] || {});
    if (!keys.length) {
      el.bmList.innerHTML = '<li class="w-bm-empty">还没有单词，在上面的输入框添加，或练习时按 '
        + (bookWhich === 'fav' ? '\\' : '/') + ' 加入</li>';
      if (el.bmPractice) el.bmPractice.disabled = true;
      return;
    }
    if (el.bmPractice) el.bmPractice.disabled = false;
    el.bmList.innerHTML = keys.map((w) => {
      const obj = byWord.get(w);
      return `<li><span class="w">${esc(w)}</span><span class="t">${esc(obj ? obj.t : '')}</span>
        <button class="x" data-w="${esc(w)}" title="移出">×</button></li>`;
    }).join('');
    $$('.x', el.bmList).forEach((b) => {
      b.onclick = () => toggleBook(bookWhich, b.dataset.w);
    });
  }

  function openBook(which) {
    bookWhich = which;
    el.bmTitle.textContent = BOOKS[which].name.replace('本', '单词本');
    el.bmTip.textContent = '';
    el.bmTip.className = 'w-bm-tip';
    el.bmInput.value = '';
    el.bookModal.hidden = false;
    renderBookList();
    setTimeout(() => el.bmInput.focus(), 60);
  }

  function addFromInput() {
    const raw = (el.bmInput.value || '').trim().toLowerCase();
    if (!raw) return;
    const obj = byWord.get(raw);
    if (!obj) {
      el.bmTip.textContent = '词库里没有「' + raw + '」，换个拼写试试';
      el.bmTip.className = 'w-bm-tip err';
      return;
    }
    if (inBook(bookWhich, obj.w)) {
      el.bmTip.textContent = '这个词已经在' + BOOKS[bookWhich].name + '里了';
      el.bmTip.className = 'w-bm-tip err';
      return;
    }
    toggleBook(bookWhich, obj.w);
    el.bmInput.value = '';
    el.bmTip.textContent = '已加入 ' + obj.w;
    el.bmTip.className = 'w-bm-tip';
  }

  function startBook(which) {
    const list = Object.keys(state[which] || {})
      .map((w) => byWord.get(w))
      .filter(Boolean);
    if (!list.length) {
      showToast(BOOKS[which].name + '还是空的');
      return;
    }
    el.bookModal.hidden = true;
    startSession(list, BOOKS[which].name, -1, 0);
  }

  /* ---------------- 导出 / 恢复（Excel 备份） ---------------- */
  function stampNow() {
    const d = new Date();
    const p = (n) => String(n).padStart(2, '0');
    return d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate()) + '-' + p(d.getHours()) + p(d.getMinutes());
  }

  // 导出：第一张表是整本词书（每个词标注练到第几遍），第二张表存章节/收藏/错词等元信息
  function exportBook() {
    if (!words.length) { showToast('词库还没加载完'); return; }
    if (!window.XlsxLite) { showToast('导出模块没加载出来，刷新页面试试'); return; }

    const rows = [['单词', '中文释义', '进度（第几遍）', '收藏', '熟悉', '错词次数']];
    let progressed = 0;
    words.forEach((x) => {
      const st = stageOf(x.w);
      if (st > 1) progressed += 1;
      rows.push([
        x.w,
        x.t,
        st,
        inBook('fav', x.w) ? '★' : '',
        inBook('known', x.w) ? '✓' : '',
        (state.wrong && state.wrong[x.w]) || '',
      ]);
    });

    const meta = [
      ['format', 'cet6-words-backup'],
      ['version', 1],
      ['exported_at', new Date().toISOString()],
      ['note', '第一张表是词书：改「进度（第几遍）」列后可以再用「恢复」导回来；这张表只存整章/错词等附加信息'],
      ['total', words.length],
      ['per_chapter', PER],
      ['ch', state.ch],
      ['pos', state.pos],
      ['wrong', JSON.stringify(state.wrong || {})],
      ['done', JSON.stringify(state.done || {})],
      ['log', JSON.stringify((state.log || []).slice(0, 20))],
    ];

    const blob = XlsxLite.build([
      { name: '词书', rows, widths: [20, 52, 15, 8, 8, 10] },
      { name: '说明', rows: meta, widths: [14, 70] },
    ]);

    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'cet6-words-' + stampNow() + '.xlsx';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(() => URL.revokeObjectURL(url), 1500);
    showToast('已导出 ' + words.length + ' 词，其中 ' + progressed + ' 词有进度');
  }

  function metaMap(sheets) {
    const sh = sheets.filter((x) => x.name === '说明')[0] || sheets[1];
    const map = {};
    if (!sh || !sh.rows) return map;
    sh.rows.forEach((r) => { if (r && r[0]) map[String(r[0]).trim()] = r[1]; });
    return map;
  }

  function readBookSheet(sheets) {
    const sh = sheets.filter((x) => x.name === '词书')[0] || sheets[0];
    const out = { list: [], unknown: 0, hasHeader: false };
    if (!sh || !sh.rows || !sh.rows.length) return out;

    let header = sh.rows[0] || [];
    let from = 1;
    if (String(header[0] || '').indexOf('单词') < 0) { header = []; from = 0; }
    out.hasHeader = header.length > 0;

    const pick = (kw, def) => {
      const i = header.findIndex((h) => String(h || '').indexOf(kw) >= 0);
      return i >= 0 ? i : def;
    };
    const cW = pick('单词', 0);
    const cS = pick('进度', 2);
    const cF = pick('收藏', 3);
    const cK = pick('熟悉', 4);

    for (let i = from; i < sh.rows.length; i++) {
      const r = sh.rows[i] || [];
      const raw = String(r[cW] == null ? '' : r[cW]).trim();
      if (!raw) continue;
      const obj = byWord.get(raw.toLowerCase());
      if (!obj) { out.unknown += 1; continue; }
      out.list.push({
        w: obj.w,
        s: Math.max(1, Math.min(5, parseInt(r[cS], 10) || 1)),
        fav: !!(r[cF] && String(r[cF]).trim()),
        known: !!(r[cK] && String(r[cK]).trim()),
      });
    }
    return out;
  }

  async function importBook(file) {
    if (!file) return;
    let list = [];
    let unknown = 0;
    let meta = {};
    let fav = null;
    let known = null;

    try {
      if (/\.json$/i.test(file.name)) {
        // 兼容之前导出的 json 备份
        const d = JSON.parse(await file.text());
        if (!d || d.format !== 'cet6-words-backup' || !Array.isArray(d.words)) {
          throw new Error('不认识的 json 结构');
        }
        d.words.forEach((it) => {
          const w = typeof it === 'string' ? it : (it && it.w);
          if (!w) return;
          const obj = byWord.get(String(w).toLowerCase());
          if (!obj) { unknown += 1; return; }
          const st = Math.max(1, Math.min(5, parseInt(typeof it === 'string' ? 1 : it.s, 10) || 1));
          list.push({ w: obj.w, s: st, fav: false, known: false });
        });
        const p = d.progress || {};
        fav = p.fav || null;
        known = p.known || null;
        meta = { ch: p.ch, pos: p.pos, wrong: JSON.stringify(p.wrong || {}), done: JSON.stringify(p.done || {}), log: JSON.stringify(p.log || []) };
      } else {
        const sheets = await XlsxLite.parse(await file.arrayBuffer());
        const book = readBookSheet(sheets);
        list = book.list;
        unknown = book.unknown;
        meta = metaMap(sheets);
        if (!list.length) throw new Error('第一张表里没读到单词');
        fav = {};
        known = {};
        list.forEach((it) => { if (it.fav) fav[it.w] = 1; if (it.known) known[it.w] = 1; });
      }
    } catch (e) {
      showToast('读取失败：' + (e && e.message ? e.message : e));
      return;
    }

    const map = {};
    list.forEach((it) => { if (it.s > 1) map[it.w] = it.s; });
    const progressed = Object.keys(map).length;
    const latest = meta.exported_at ? String(meta.exported_at).slice(0, 16).replace('T', ' ') : '未知';

    if (!confirm('将用这份备份覆盖当前进度：\n\n· 备份时间：' + latest
      + '\n· 读到的词：' + list.length + ' 个，其中 ' + progressed + ' 个有进度'
      + (unknown ? '\n· 词库里没有的词：' + unknown + ' 个（已跳过）' : '')
      + '\n\n收藏本 / 熟悉本 / 错词本 / 当前章节也会一起恢复，继续吗？')) return;

    state.words = map;
    const isMap = (v) => v && typeof v === 'object' && !Array.isArray(v);
    if (isMap(fav)) state.fav = fav;
    if (isMap(known)) state.known = known;
    try {
      const w = JSON.parse(meta.wrong || '{}');
      if (isMap(w)) state.wrong = w;
      const dn = JSON.parse(meta.done || '{}');
      if (isMap(dn)) state.done = dn;
      const lg = JSON.parse(meta.log || '[]');
      if (Array.isArray(lg)) state.log = lg.slice(0, 20);
    } catch (e) { /* 元信息损坏就跳过，不影响单词进度 */ }
    if (meta.ch != null && !isNaN(parseInt(meta.ch, 10))) state.ch = Math.max(0, parseInt(meta.ch, 10));
    if (meta.pos != null && !isNaN(parseInt(meta.pos, 10))) state.pos = Math.max(0, parseInt(meta.pos, 10));

    renderStats();
    renderBooks();
    renderLog();
    showToast('正在写入云端…');
    Cloud.flush().then(() => {
      showToast('已恢复 ' + progressed + ' 个词的进度，正在刷新');
      setTimeout(() => location.reload(), 700);
    });
  }

  /* ---------------- 流程 ---------------- */
  function startSession(list, title, ch, pos) {
    requeued.clear();
    session = {
      list: list, i: Math.max(0, Math.min(pos || 0, list.length)),
      title: title, ch: ch, startTs: Date.now(), right: 0, wrong: 0
    };
    renderHead();
    next(true);
  }

  function next(fresh) {
    if (session.i >= session.list.length) { finish(); return; }
    cur = session.list[session.i];
    typed = '';
    errThis = false;
    locked = false;
    peekCount = 0;
    peeking = false;
    el.input.value = '';
    el.typing.classList.remove('flash', 'shake');
    el.card.classList.remove('success');
    el.loading.hidden = true;
    el.body.hidden = false;
    renderPrompt();
    buildTyping();
    renderHead();
    renderBooks();
    if (fresh !== false) focusInput();
    // 进词时自动发音（受工具栏「发音」开关控制）；第 4 遍只看中文，不发音
    if (soundOn && stageOf(cur.w) !== 4) {
      const enter = cur.w;
      setTimeout(() => { if (cur && cur.w === enter) playAudio(enter); }, 200);
    }
    // 预取当前词与后两个词的词典和音频，答题后瞬间出结果
    prefetch(session.list.slice(session.i, session.i + 3).map((x) => x.w));
  }

  function focusInput() {
    try { el.input.focus({ preventScroll: true }); } catch (e) {}
  }

  function onInput() {
    if (locked || !cur) return;
    if (retryWord) {
      const w = retryWord;
      retryWord = '';
      if (w === cur.w) playAudio(w);
    }
    const before = typed.length;
    let v = el.input.value.replace(/[^A-Za-z'\-]/g, '');
    if (v.length > cur.w.length) v = v.slice(0, cur.w.length);
    if (v !== el.input.value) el.input.value = v;

    if (v.length > typed.length) {
      for (let i = typed.length; i < v.length; i++) {
        if (v[i].toLowerCase() !== cur.w[i].toLowerCase()) errThis = true;
      }
    }
    typed = v;
    paintTyping();

    // 逐字反馈：新敲的字母弹一下，对应按键闪绿 / 闪红
    if (typed.length > before) {
      for (let i = before; i < typed.length; i++) {
        const ok = typed[i].toLowerCase() === cur.w[i].toLowerCase();
        const sp = typingSpans[i];
        if (sp) {
          sp.classList.add('pop');
          if (!ok) sp.classList.add('shake');
          setTimeout(() => sp.classList.remove('pop', 'shake'), 300);
        }
        flashKey(cur.w[i], ok);
        beep(ok ? 'ok' : 'bad');
      }
    }

    if (typed.length === cur.w.length) {
      if (typed.toLowerCase() === cur.w.toLowerCase()) {
        succeed();
      } else {
        el.typing.classList.remove('shake');
        void el.typing.offsetWidth;
        el.typing.classList.add('shake');
        el.hint.className = 'w-hint warn';
        el.hint.textContent = '有字母不对，按退格删掉重打';
      }
    }
  }

  function succeed() {
    locked = true;
    const firstTry = !errThis;
    const st = stageOf(cur.w);
    if (firstTry) {
      setStage(cur.w, st + 1);
    } else {
      requeue(cur.w);
    }
    bumpToday(firstTry);
    session.right += 1;
    if (!firstTry) session.wrong += 1;

    if (session.ch >= 0) {
      if (!firstTry) state.wrong[cur.w] = (state.wrong[cur.w] || 0) + 1;
      state.ch = session.ch;
      state.pos = session.i + 1;
    } else {
      if (firstTry) {
        const n = (state.wrong[cur.w] || 0) - 1;
        if (n > 0) state.wrong[cur.w] = n; else delete state.wrong[cur.w];
      } else {
        state.wrong[cur.w] = (state.wrong[cur.w] || 0) + 1;
      }
    }
    trimWrong();
    pushLog(cur.w, st, firstTry);
    renderStats();
    beep('done');
    showDict(cur.w);
    el.typing.classList.add('flash');
    el.card.classList.add('success');
    setTimeout(() => el.card.classList.remove('success'), 520);
    if (firstTry) {
      combo += 1;
      if (combo >= 3) showCombo(combo);
    } else {
      combo = 0;
    }
    el.hint.className = 'w-hint';
    el.hint.textContent = firstTry
      ? (st >= 4 ? '✓ 全对，四次都过了' : `✓ 进入第 ${st + 1} 遍`)
      : '✗ 本遍无效，稍后再练';
    Cloud.save();
    setTimeout(() => {
      session.i += 1;
      renderHead();
      next();
    }, firstTry ? 460 : 900);
  }

  function skip() {
    if (locked || !cur) return;
    locked = true;
    bumpToday(false);
    combo = 0;
    requeue(cur.w);
    session.wrong += 1;
    state.wrong[cur.w] = (state.wrong[cur.w] || 0) + 1;
    trimWrong();
    if (session.ch >= 0) { state.ch = session.ch; state.pos = session.i + 1; }
    renderStats();
    Cloud.save();
    el.hint.className = 'w-hint warn';
    el.hint.textContent = '已跳过：' + cur.w;
    pushLog(cur.w, stageOf(cur.w), false);
    beep('bad');
    showDict(cur.w);
    setTimeout(() => { session.i += 1; renderHead(); next(); }, 1400);
  }

  function trimWrong() {
    const keys = Object.keys(state.wrong);
    if (keys.length <= 400) return;
    keys.slice(0, keys.length - 400).forEach((k) => { delete state.wrong[k]; });
  }

  function finish() {
    const ms = Date.now() - session.startTs;
    const total = session.right + session.wrong;
    const acc = total ? Math.round((session.right / total) * 100) : 0;

    if (session.ch >= 0) {
      state.done[session.ch] = { right: session.right, wrong: session.wrong, ms: ms };
      state.ch = Math.min(session.ch + 1, chapters.length - 1);
      state.pos = 0;
    }
    renderStats();
    paintGrid();
    renderHead();
    Cloud.save();

    el.mIcon.textContent = acc >= 80 ? '✓' : '!';
    el.mIcon.style.background = acc >= 80 ? 'var(--ok-soft)' : '#fff6e6';
    el.mIcon.style.color = acc >= 80 ? 'var(--ok)' : '#b7791f';
    el.mTitle.textContent = session.ch >= 0 ? `${session.title} 完成` : '错词复习完成';
    el.mRight.textContent = session.right;
    el.mWrong.textContent = session.wrong;
    el.mAcc.textContent = acc + '%';
    el.mTime.textContent = fmtDur(ms);
    el.mRedo.hidden = Object.keys(state.wrong).length === 0;
    el.modal.hidden = false;
  }

  function startChapter(idx) {
    if (!chapters[idx]) return;
    const prev = state.ch;
    const pos = (idx === prev && !state.done[idx])
      ? Math.min(state.pos || 0, chapters[idx].length) : 0;
    state.ch = idx;
    state.pos = pos;
    startSession(chapters[idx], `第 ${idx + 1} 章`, idx, pos);
    paintGrid();
    Cloud.save();
  }

  function startWrongBook() {
    const list = Object.keys(state.wrong)
      .map((w) => words.find((x) => x.w === w))
      .filter(Boolean)
      .slice(0, PER * 2);
    if (!list.length) return;
    // 洗牌
    for (let i = list.length - 1; i > 0; i--) {
      const j = Math.floor(Math.random() * (i + 1));
      [list[i], list[j]] = [list[j], list[i]];
    }
    startSession(list, '错词本', -1, 0);
  }

  /* ---------------- 事件 ---------------- */
  el.input.addEventListener('input', onInput);
  el.card.addEventListener('click', (e) => {
    if (e.target.closest('.w-speak')) return;
    focusInput();
  });
  el.speak.addEventListener('click', () => { if (cur) playAudio(cur.w); });
  if (el.dFav) el.dFav.addEventListener('click', () => { if (dictWord) toggleBook('fav', dictWord); });
  if (el.dKnown) el.dKnown.addEventListener('click', () => { if (dictWord) toggleBook('known', dictWord); });

  el.wsInput.addEventListener('input', onSearchInput);
  el.wsInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      if (searchRows.length) showDetail(searchRows[0].w);
    }
  });
  el.wsClose.addEventListener('click', closeSearch);
  el.wsBack.addEventListener('click', () => { el.wsDetail.hidden = true; el.wsList.hidden = false; });
  el.searchPanel.addEventListener('mousedown', (e) => { if (e.target === el.searchPanel) closeSearch(); });
  el.wsSpeak.addEventListener('click', () => { if (wsWord) playAudio(wsWord); });
  el.wsFav.addEventListener('click', () => {
    const l = byWord.get(wsWord.toLowerCase());
    if (l) { toggleBook('fav', l.w); updateDetailButtons(); }
  });
  el.wsKnown.addEventListener('click', () => {
    const l = byWord.get(wsWord.toLowerCase());
    if (l) { toggleBook('known', l.w); updateDetailButtons(); }
  });

  if (el.bigSpeak) el.bigSpeak.addEventListener('click', () => { if (cur) playAudio(cur.w); });
  if (el.kb) {
    el.kb.addEventListener('click', (e) => {
      const key = e.target.closest('.w-key');
      if (!key || locked || !cur) return;
      const k = key.dataset.k;
      let v = el.input.value;
      v = (k === '\u232b') ? v.slice(0, -1) : v + k;
      el.input.value = v;
      onInput();
      focusInput();
    });
  }
  el.dSpeak.addEventListener('click', () => {
    const w = el.dWord.textContent;
    if (w && w !== '—') playAudio(w);
  });
  if (el.btnExport) el.btnExport.addEventListener('click', exportBook);
  if (el.btnImport) el.btnImport.addEventListener('click', () => el.importFile && el.importFile.click());
  if (el.importFile) {
    el.importFile.addEventListener('change', () => {
      const f = el.importFile.files && el.importFile.files[0];
      el.importFile.value = '';
      if (f) importBook(f);
    });
  }

  applyLog();
  if (el.btnLog) {
    el.btnLog.addEventListener('click', () => {
      logOn = !logOn;
      try { localStorage.setItem('cet.logOn', logOn ? '1' : '0'); } catch (e) {}
      applyLog();
      if (logOn) renderLog();
    });
  }
  applySfx();
  el.sfx.addEventListener('click', () => {
    sfxOn = !sfxOn;
    try { localStorage.setItem('cet.sfx', sfxOn ? '1' : '0'); } catch (e) {}
    applySfx();
    if (sfxOn) beep('ok');
  });
  el.sound.addEventListener('click', () => {
    soundOn = !soundOn;
    el.sound.classList.toggle('on', soundOn);
    el.sound.textContent = soundOn ? '发音 开' : '发音 关';
  });
  el.skip.addEventListener('click', skip);
  el.reset.addEventListener('click', () => {
    if (!confirm('从本章第一个词重新开始？')) return;
    if (session.ch >= 0) {
      state.pos = 0;
      startSession(chapters[session.ch], session.title, session.ch, 0);
      Cloud.save();
    }
  });
  el.nextCh.addEventListener('click', () => {
    const n = Math.min((session.ch >= 0 ? session.ch : state.ch) + 1, chapters.length - 1);
    startChapter(n);
  });
  el.wrongBook.addEventListener('click', startWrongBook);
  el.fav.addEventListener('click', () => {
    if (bookSize('fav')) startBook('fav'); else openBook('fav');
  });
  el.known.addEventListener('click', () => {
    if (bookSize('known')) startBook('known'); else openBook('known');
  });
  $$('.w-book-manage').forEach((b) => {
    b.addEventListener('click', () => openBook(b.dataset.book));
  });
  el.bmAdd.addEventListener('click', addFromInput);
  el.bmInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); addFromInput(); }
    e.stopPropagation();
  });
  el.bmPractice.addEventListener('click', () => startBook(bookWhich));
  el.bmClose.addEventListener('click', () => { el.bookModal.hidden = true; });

  el.grid.addEventListener('click', (e) => {
    const b = e.target.closest('button[data-ch]');
    if (b) startChapter(parseInt(b.dataset.ch, 10));
  });

  el.mNext.addEventListener('click', () => {
    el.modal.hidden = true;
    startChapter(Math.min(state.ch, chapters.length - 1));
  });
  el.mRedo.addEventListener('click', () => { el.modal.hidden = true; startWrongBook(); });
  el.mClose.addEventListener('click', () => {
    el.modal.hidden = true;
    startChapter(Math.min(state.ch, chapters.length - 1));
  });

  document.addEventListener('keydown', (e) => {
    if (el.searchPanel && !el.searchPanel.hidden) {
      if (e.key === "'" || e.key === 'Escape') { e.preventDefault(); closeSearch(); }
      return;
    }
    if (el.bookModal && !el.bookModal.hidden) {
      if (e.key === 'Escape') el.bookModal.hidden = true;
      return;
    }
    if (!el.modal.hidden) {
      if (e.key === 'Escape') el.mClose.click();
      return;
    }
    if (e.key === "'") { e.preventDefault(); openSearch(); return; }
    if (e.key === '\\') { e.preventDefault(); if (cur && !locked) toggleBook('fav', cur.w); return; }
    if (e.key === '/') { e.preventDefault(); if (cur && !locked) toggleBook('known', cur.w); return; }
    if (e.code === 'Tab') { e.preventDefault(); if (!e.repeat) startPeek(); return; }
    if (e.code === 'Space') {
      e.preventDefault();
      if (cur && stageOf(cur.w) !== 4) playAudio(cur.w);
      return;
    }
    if (e.key === 'Escape') { e.preventDefault(); skip(); }
  });

  document.addEventListener('keyup', (e) => {
    if (e.code === 'Tab') { e.preventDefault(); endPeek(); }
  });
  window.addEventListener('blur', () => endPeek());

  if (el.peek) {
    el.peek.addEventListener('pointerdown', (e) => { e.preventDefault(); startPeek(); });
    ['pointerup', 'pointerleave', 'pointercancel'].forEach((ev) => {
      el.peek.addEventListener(ev, () => endPeek());
    });
  }

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') Cloud.flush();
  });

  /* ---------------- 启动 ---------------- */
  function boot() {
    buildKeyboard();
    const local = blankState();
    Cloud.load()
      .then((remote) => {
        state = normalize(remote || local);
        return fetch('data/cet6.json')
          .then((r) => r.json())
          .then((data) => {
            words = data;
            byWord = new Map(words.map((x) => [x.w.toLowerCase(), x]));
            chapters = [];
            for (let i = 0; i < words.length; i += PER) chapters.push(words.slice(i, i + PER));
            if (state.ch >= chapters.length) state.ch = 0;
            renderStats();
            renderBooks();
            renderLog();
            renderGrid();
            const pos = Math.min(state.pos || 0, chapters[state.ch].length);
            startSession(chapters[state.ch], `第 ${state.ch + 1} 章`, state.ch, pos);
            paintGrid();
          });
      })
      .catch(() => {
        el.loading.textContent = '词库加载失败，请刷新重试';
      });
  }

  boot();
})();
