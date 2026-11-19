/* 前端逻辑 */
(function () {
  'use strict';

  const $ = (s, r) => (r || document).querySelector(s);
  const $$ = (s, r) => Array.from((r || document).querySelectorAll(s));

  const esc = (s) => String(s).replace(/[&<>"']/g, (c) => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ));

  const fmt = (s) => {
    if (!isFinite(s) || s < 0) s = 0;
    const m = Math.floor(s / 60);
    const ss = Math.floor(s % 60);
    return m + ':' + String(ss).padStart(2, '0');
  };

  const store = {
    get(k, d) {
      try { return JSON.parse(localStorage.getItem(k)) ?? d; } catch (e) { return d; }
    },
    set(k, v) {
      try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) {}
    }
  };

  /* ---------------- 云端同步 ---------------- */
  const Cloud = (function () {
    const state = { paper: '', answers: {}, done: [], exam: {} };
    let timer = null;

    function badge(text, cls) {
      const el = document.querySelector('#syncState');
      if (!el) return;
      el.textContent = text;
      el.className = 'sync-state' + (cls ? ' ' + cls : '');
    }

    function call(action, data) {
      const url = data
        ? 'api.php?action=' + action
        : 'api.php?action=' + action + '&paper=' + encodeURIComponent(state.paper);
      const opt = data
        ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) }
        : { method: 'GET' };
      return fetch(url, Object.assign({ credentials: 'same-origin' }, opt)).then((r) => r.json());
    }

    return {
      init(paper, answers, done, exam) {
        state.paper = paper;
        state.answers = answers || {};
        state.done = done || [];
        state.exam = exam || {};
        badge('同步中…', 'busy');
        return call('progress').then((res) => {
          if (!res || !res.ok) { badge('未同步', 'err'); return null; }
          const d = res.data || {};
          state.answers = Object.assign({}, d.answers || {}, state.answers);
          state.done = Array.from(new Set([].concat(d.done || [], state.done)));
          state.exam = Object.assign({}, d.exam || {}, state.exam);
          badge(d._updated ? '云端已同步' : '云端暂无记录', 'ok');
          return { answers: state.answers, done: state.done, exam: state.exam };
        }).catch(() => { badge('离线', 'err'); return null; });
      },
      answers() { return state.answers; },
      done() { return state.done; },
      exam() { return state.exam; },
      answer(no, k) { state.answers[no] = k; this.queue(); },
      clearAnswers(nos) {
        (nos || []).forEach((n) => { delete state.answers[n]; });
        this.queue();
      },
      setDone(list) { state.done = list; this.queue(); },
      setExam(obj) { state.exam = obj; this.queue(); },
      queue() {
        badge('待同步…', 'busy');
        if (timer) clearTimeout(timer);
        timer = setTimeout(() => { this.flush(); }, 600);
      },
      flush() {
        if (!state.paper) return Promise.resolve();
        badge('同步中…', 'busy');
        return call('save_progress', {
          paper: state.paper,
          answers: state.answers,
          done: state.done,
          exam: state.exam
        }).then((r) => {
          if (r && r.ok) badge('已同步 ' + (r.saved_at || ''), 'ok');
          else badge('同步失败', 'err');
        }).catch(() => badge('同步失败', 'err'));
      },
      attempt(data) {
        return call('attempt', Object.assign({ paper: state.paper }, data)).catch(() => {});
      },
      lastUpdated() { return state.exam && state.exam.submittedAt || ''; }
    };
  })();

  /* ---------------- 音频控制器 ---------------- */
  function Controller(onTick, onState) {
    const el = new Audio();
    el.preload = 'metadata';
    const state = { range: null, playing: false, loop: false, rate: 1 };
    let pendingSeek = null;

    function seekTo(t) {
      if (el.readyState >= 1) {
        el.currentTime = t;
      } else {
        pendingSeek = t;
        el.addEventListener('loadedmetadata', function once() {
          el.removeEventListener('loadedmetadata', once);
          if (pendingSeek != null) el.currentTime = pendingSeek;
          pendingSeek = null;
          el.play().catch(() => {});
        }, { once: true });
      }
    }

    function play(range) {
      const prev = state.range;
      const same = !!prev && prev.start === range.start && prev.end === range.end && prev.src === range.src;
      state.range = range;
      if (el.dataset.src !== range.src) {
        el.dataset.src = range.src;
        el.src = range.src;
        el.load();
      }
      const inside = same && el.currentTime >= range.start && el.currentTime < range.end - 0.2;
      const at = inside ? el.currentTime : range.start;
      if (el.readyState >= 1) el.currentTime = at; else seekTo(at);
      el.playbackRate = state.rate;
      el.play().catch(() => { onState(state); });
    }

    function pause() { el.pause(); }
    function toggle(range) {
      if (state.playing) pause(); else play(range);
    }

    el.addEventListener('play', () => { state.playing = true; onState(state); });
    el.addEventListener('pause', () => { state.playing = false; onState(state); });

    el.addEventListener('timeupdate', () => {
      const r = state.range;
      if (!r) return;
      if (el.currentTime < r.start - 0.6) {
        el.currentTime = r.start;
        return;
      }
      if (el.currentTime >= r.end - 0.06) {
        if (state.loop && state.playing) {
          el.currentTime = r.start;
        } else {
          el.pause();
          el.currentTime = Math.max(r.start, r.end - 0.1);
          onTick(1, r);
          return;
        }
      }
      onTick((el.currentTime - r.start) / Math.max(0.1, r.end - r.start), r);
    });

    el.addEventListener('ended', () => {
      if (state.loop && state.range) { el.currentTime = state.range.start; el.play(); }
    });

    return {
      el, state,
      play, pause, toggle,
      seekRatio(ratio) {
        const r = state.range;
        if (!r) return;
        const t = r.start + ratio * (r.end - r.start);
        el.currentTime = Math.max(r.start, Math.min(r.end - 0.05, t));
        onTick(ratio, r);
      },
      nudge(delta) {
        const r = state.range;
        if (!r) return;
        el.currentTime = Math.max(r.start, Math.min(r.end - 0.05, el.currentTime + delta));
      },
      setRate(v) { state.rate = v; el.playbackRate = v; },
      currentRange() { return state.range; }
    };
  }

  /* ---------------- 首页 ---------------- */
  function initHome() {
    $$('.paper-progress').forEach((box) => {
      const id = box.dataset.paper;
      const ans = store.get('cet.answers.' + id, {});
      const n = Object.keys(ans).length;
      const pct = Math.min(100, Math.round((n / 25) * 100));
      $('.bar i', box).style.width = pct + '%';
      $('.ptext', box).textContent = n ? `已作答 ${n} / 25` : '尚未开始';
    });
  }

  /* ---------------- 练习模式 ---------------- */
  function initPractice() {
    const paper = window.__PAPER__;
    const known = window.__ANSWERS__ || {};
    const items = [];
    paper.sections.forEach((sec) => sec.items.forEach((it) => {
      items.push(Object.assign({}, it, { section: sec.name, sectionDesc: sec.desc }));
    }));

    const ansKey = 'cet.answers.' + paper.id;
    const doneKey = 'cet.done.' + paper.id;
    const gradedKey = 'cet.graded.' + paper.id;   // 已批改的段落（按段批改，不是逐题）
    let answers = store.get(ansKey, {});
    let doneList = store.get(doneKey, []);
    let graded = store.get(gradedKey, []);
    let current = 0;
    let mode = 'material'; // material | questions | single
    let singleNo = null;

    const el = {
      nav: $('#sideNav'),
      crumb: $('#crumb'),
      title: $('#itemTitle'),
      label: $('#playerLabel'),
      hint: $('#playerHint'),
      fill: $('#seekFill'),
      knob: $('#seekKnob'),
      cur: $('#curTime'),
      dur: $('#durTime'),
      play: $('#playBtn'),
      loop: $('#loopBtn'),
      speed: $('#speed'),
      card: $('.player-card'),
      questions: $('#questions'),
      toggleQ: $('#toggleQuestions'),
      toggleS: $('#toggleScript'),
      toggleT: $('#toggleStem'),
      panel: $('#scriptPanel'),
      scriptBody: $('#scriptBody'),
      scriptTag: $('#scriptTag'),
      sidePct: $('.side-progress'),
      sideTxt: $('.side-progress span')
    };

    // 原文 / 题干 的显示状态记在本机，下次进来保持
    let showScript = store.get('cet.showScript', false);
    let showStem = store.get('cet.showStem', false);
    const trans = window.__TRANS__ || {};

    function applyScript() {
      el.panel.hidden = !showScript;
      el.toggleS.classList.toggle('on', showScript);
      store.set('cet.showScript', showScript);
    }

    function applyStem() {
      el.questions.classList.toggle('stems-on', showStem);
      el.toggleT.classList.toggle('on', showStem);
      store.set('cet.showStem', showStem);
    }

    function renderScript(it) {
      const t = trans[it.id];
      if (!t || !t.turns || !t.turns.length) {
        el.scriptBody.innerHTML = '<p class="script-empty">本段原文还没有录入。</p>';
        el.scriptTag.textContent = '';
        return;
      }
      el.scriptBody.innerHTML = t.turns.map((x) => '<p>' + esc(x) + '</p>').join('');
      el.scriptTag.textContent = '语音识别自动转写，仅供精听参考';
    }

    const ctl = Controller((ratio, range) => {
      el.fill.style.width = (ratio * 100).toFixed(2) + '%';
      el.knob.style.left = (ratio * 100).toFixed(2) + '%';
      el.cur.textContent = fmt(ratio * (range.end - range.start));
      el.dur.textContent = fmt(range.end - range.start);
    }, (st) => {
      el.play.classList.toggle('playing', st.playing);
      el.card.classList.toggle('playing', st.playing);
    });

    function renderNav() {
      let html = '';
      paper.sections.forEach((sec) => {
        html += `<div class="side-group">${sec.name} · ${sec.desc}</div>`;
        sec.items.forEach((it) => {
          const idx = items.findIndex((x) => x.id === it.id);
          const done = doneList.includes(it.id);
          html += `<div class="side-item ${idx === current ? 'active' : ''} ${done ? 'done' : ''}" data-idx="${idx}">
              <span class="st"></span>
              <span>${it.cn}</span>
              <span class="num">${it.questions[0].no}-${it.questions[it.questions.length - 1].no}</span>
            </div>`;
        });
      });
      el.nav.innerHTML = html;
      $$('.side-item', el.nav).forEach((n) => {
        n.onclick = () => go(parseInt(n.dataset.idx, 10));
      });
      const total = items.reduce((a, b) => a + b.questions.length, 0);
      const doneCnt = Object.keys(answers).length;
      el.sideTxt.textContent = doneCnt + ' / ' + total;
      el.sidePct.style.setProperty('--p', Math.round((doneCnt / total) * 100) + '%');
    }

    /* --- 按段批改 --- */
    function itemHasKey(it) {
      return it.questions.every((q) => known[q.no]);
    }
    function isGraded(it) {
      return graded.indexOf(it.id) >= 0;
    }
    function answeredCount(it) {
      return it.questions.filter((q) => answers[q.no]).length;
    }
    // 本段题目全部答完 -> 整段一起批改（刷新页面也能恢复）
    function syncGraded(it) {
      if (isGraded(it) || !itemHasKey(it)) return;
      if (it.questions.every((q) => answers[q.no])) {
        graded.push(it.id);
        store.set(gradedKey, graded);
      }
    }

    function renderQuestions(it) {
      syncGraded(it);
      const stems = (trans[it.id] && trans[it.id].q) || {};
      const reveal = isGraded(it);
      const hasKey = itemHasKey(it);
      const total = it.questions.length;
      const ansN = answeredCount(it);

      let bar;
      if (!hasKey) {
        bar = `<div class="q-status plain">
          <span>已答 <b>${ansN}</b> / ${total}</span>
          <em>本段暂无参考答案，可对照原文自评</em>
        </div>`;
      } else if (reveal) {
        const right = it.questions.filter((q) => answers[q.no] === known[q.no]).length;
        bar = `<div class="q-status done">
          <span>本段已批改 · <b class="${right === total ? 'all' : ''}">${right}</b> / ${total} 正确</span>
          <button class="btn-xs" data-act="redo">重做本段</button>
        </div>`;
      } else {
        bar = `<div class="q-status">
          <span>已答 <b>${ansN}</b> / ${total}</span>
          <em>答完本段 ${total} 题统一批改，中途不提示对错</em>
        </div>`;
      }

      let html = bar;
      it.questions.forEach((q) => {
        const picked = answers[q.no] || '';
        const right = known[q.no];
        const stem = stems[String(q.no)] || '';
        html += `<div class="q-card${reveal ? ' graded' : ''}" data-no="${q.no}">
          <div class="q-head">
            <div class="q-no"><span>${q.no}</span> 第 ${q.no} 题</div>
            <div class="q-tools">
              <button class="q-audio" data-no="${q.no}">
                <svg viewBox="0 0 24 24"><path d="M12 3v18l-7-5H2V8h3zM16 6a6 6 0 010 12v-2a4 4 0 000-8z"/></svg>
                题目音频
              </button>
            </div>
          </div>
          ${stem ? `<div class="q-stem">${esc(stem)}</div>` : ''}
          <div class="options">
            ${Object.keys(q.options).map((k) => {
              const pick = picked === k;
              let cls = '';
              if (reveal) {
                if (k === right) cls = pick ? 'sel right' : 'right';
                else if (pick) cls = 'sel wrong';
              } else if (pick) {
                cls = 'sel';
              }
              return `<div class="opt ${cls}${reveal ? ' locked' : ''}" data-k="${k}"><span class="k">${k}</span><span>${q.options[k]}</span></div>`;
            }).join('')}
          </div>
        </div>`;
      });
      el.questions.innerHTML = html;

      $$('.opt', el.questions).forEach((o) => {
        o.onclick = () => {
          if (isGraded(it)) return;
          const card = o.closest('.q-card');
          const no = card.dataset.no;
          const k = o.dataset.k;
          if (answers[no] === k) return;
          answers[no] = k;
          store.set(ansKey, answers);
          Cloud.answer(no, k);
          markDone(it);
          renderQuestions(it);
          renderNav();
        };
      });

      const redo = $('[data-act="redo"]', el.questions);
      if (redo) redo.onclick = () => {
        const nos = it.questions.map((q) => String(q.no));
        nos.forEach((n) => { delete answers[n]; });
        store.set(ansKey, answers);
        Cloud.clearAnswers(nos);
        graded = graded.filter((x) => x !== it.id);
        store.set(gradedKey, graded);
        doneList = doneList.filter((x) => x !== it.id);
        store.set(doneKey, doneList);
        Cloud.setDone(doneList);
        renderQuestions(it);
        renderNav();
      };

      $$('.q-audio', el.questions).forEach((b) => {
        b.onclick = () => {
          const q = it.questions.find((x) => String(x.no) === b.dataset.no);
          mode = 'single'; singleNo = q.no;
          playRange(q.start, q.end, '第 ' + q.no + ' 题');
          $$('.q-audio').forEach((x) => x.classList.remove('playing'));
          b.classList.add('playing');
        };
      });
      $$('.q-audio').forEach((x) => x.classList.remove('playing'));
      if (mode === 'single' && singleNo) {
        const btn = $(`.q-audio[data-no="${singleNo}"]`, el.questions);
        if (ctl.state.playing && btn) btn.classList.add('playing');
      }
    }

    function markDone(it) {
      const all = it.questions.every((q) => answers[q.no]);
      if (all && !doneList.includes(it.id)) {
        doneList.push(it.id);
        store.set(doneKey, doneList);
        Cloud.setDone(doneList);
      }
    }

    function rangeOf(it, which) {
      if (which === 'questions') return { src: paper.audio, start: it.qstart, end: it.qend };
      return { src: paper.audio, start: it.start, end: it.end };
    }

    function playRange(start, end, label) {
      el.label.textContent = label || '听力材料';
      ctl.play({ src: paper.audio, start, end });
    }

    function go(idx) {
      current = Math.max(0, Math.min(items.length - 1, idx));
      const it = items[current];
      mode = 'material';
      ctl.pause();
      el.crumb.textContent = it.section + ' · ' + it.sectionDesc;
      el.title.textContent = it.cn + ' · ' + it.title;
      el.label.textContent = '听力材料';
      el.hint.textContent = '共 ' + fmt(it.end - it.start);
      el.cur.textContent = '0:00';
      el.dur.textContent = fmt(it.end - it.start);
      el.fill.style.width = '0%';
      el.knob.style.left = '0%';
      renderQuestions(it);
      renderScript(it);
      renderNav();
      ctl.play(rangeOf(it, 'material'));
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    el.play.onclick = () => {
      const it = items[current];
      if (mode === 'questions') ctl.toggle(rangeOf(it, 'questions'));
      else if (mode === 'single' && singleNo) {
        const q = it.questions.find((x) => x.no === singleNo);
        ctl.toggle({ src: paper.audio, start: q.start, end: q.end });
      } else ctl.toggle(rangeOf(it, 'material'));
    };

    $('#back5').onclick = () => ctl.nudge(-5);
    $('#fwd5').onclick = () => ctl.nudge(5);
    $('#playQuestions').onclick = () => {
      const it = items[current];
      mode = 'questions';
      el.label.textContent = '本段题目';
      ctl.play(rangeOf(it, 'questions'));
    };
    el.loop.onclick = () => {
      ctl.state.loop = !ctl.state.loop;
      el.loop.classList.toggle('on', ctl.state.loop);
      el.loop.textContent = '循环 ' + (ctl.state.loop ? '开' : '关');
    };
    el.speed.onchange = () => ctl.setRate(parseFloat(el.speed.value));
    el.toggleQ.onclick = () => {
      const hide = !el.toggleQ.classList.contains('on');
      $$('.q-card', el.questions).forEach((c) => c.classList.toggle('hidden-q', hide));
      el.toggleQ.textContent = hide ? '显示选项' : '隐藏选项';
      el.toggleQ.classList.toggle('on', hide);
    };
    el.toggleS.onclick = () => { showScript = !showScript; applyScript(); };
    el.toggleT.onclick = () => { showStem = !showStem; applyStem(); };

    const seek = $('#seek');
    let seeking = false;
    function ratioFromEvent(ev) {
      const r = seek.getBoundingClientRect();
      return Math.max(0, Math.min(1, (ev.clientX - r.left) / r.width));
    }
    seek.addEventListener('pointerdown', (ev) => {
      seeking = true;
      ctl.seekRatio(ratioFromEvent(ev));
      seek.setPointerCapture(ev.pointerId);
    });
    seek.addEventListener('pointermove', (ev) => { if (seeking) ctl.seekRatio(ratioFromEvent(ev)); });
    seek.addEventListener('pointerup', () => { seeking = false; });

    const goto = (d) => { ctl.pause(); go(current + d); };
    $('#prevItem').onclick = () => goto(-1);
    $('#nextItem').onclick = () => goto(1);
    $('#prevItem2').onclick = () => goto(-1);
    $('#nextItem2').onclick = () => goto(1);
    document.addEventListener('keydown', (ev) => {
      if (ev.target.tagName === 'INPUT' || ev.target.tagName === 'SELECT') return;
      if (ev.code === 'Space') { ev.preventDefault(); el.play.click(); }
      if (ev.code === 'ArrowLeft') $('#back5').click();
      if (ev.code === 'ArrowRight') $('#fwd5').click();
    });

    applyScript();
    applyStem();
    renderNav();
    go(0);

    Cloud.init(paper.id, answers, doneList, {}).then((res) => {
      if (!res) return;
      answers = res.answers;
      doneList = res.done;
      store.set(ansKey, answers);
      store.set(doneKey, doneList);
      renderQuestions(items[current]);
      renderNav();
    });
  }

  /* ---------------- 考试模式 ---------------- */
  function initExam() {
    const paper = window.__PAPER__;
    const known = window.__ANSWERS__ || {};
    const limit = window.__LIMIT__ || 1800;
    const key = 'cet.exam.' + paper.id;
    let answers = store.get(key, {});
    let submitted = false;
    let timeLeft = limit;
    let timerId = null;

    const grid = $('#answerGrid');
    const secWrap = $('#examSections');
    const questions = [];
    paper.sections.forEach((sec) => sec.items.forEach((it) => it.questions.forEach((q) => {
      questions.push(Object.assign({}, q, { section: sec.name }));
    })));

    // 题目列表
    let html = '';
    paper.sections.forEach((sec) => {
      html += `<div class="exam-section"><div class="section-title"><h2>${sec.name}</h2><span>${sec.desc}</span></div>`;
      sec.items.forEach((it) => {
        it.questions.forEach((q) => {
          html += `<div class="q-card" id="q-${q.no}" data-no="${q.no}">
            <div class="q-head"><div class="q-no"><span>${q.no}</span> 第 ${q.no} 题</div>
              <div class="q-tools muted" style="font-size:12px">${it.cn}</div></div>
            <div class="options">
              ${Object.keys(q.options).map((k) => `<div class="opt" data-k="${k}"><span class="k">${k}</span><span>${q.options[k]}</span></div>`).join('')}
            </div>
          </div>`;
        });
      });
      html += '</div>';
    });
    secWrap.innerHTML = html;

    // 答题卡
    let gh = '';
    for (let i = 1; i <= questions.length; i++) gh += `<div class="ag" data-no="${i}">${i}</div>`;
    grid.innerHTML = gh;

    function sync() {
      $$('.q-card', secWrap).forEach((card) => {
        const no = card.dataset.no;
        $$('.opt', card).forEach((o) => o.classList.toggle('sel', answers[no] === o.dataset.k));
      });
      let cnt = 0;
      $$('.ag', grid).forEach((g) => {
        const no = g.dataset.no;
        const has = !!answers[no];
        if (has) cnt++;
        g.classList.toggle('done', has);
      });
      $('#answeredCount').textContent = `已答 ${cnt} / ${questions.length}`;
      store.set(key, answers);
    }

    secWrap.addEventListener('click', (ev) => {
      if (submitted) return;
      const opt = ev.target.closest('.opt');
      if (!opt) return;
      const card = opt.closest('.q-card');
      answers[card.dataset.no] = opt.dataset.k;
      sync();
      Cloud.setExam({ answers: answers, submitted: submitted });
    });
    grid.addEventListener('click', (ev) => {
      const g = ev.target.closest('.ag');
      if (!g) return;
      const target = $('#q-' + g.dataset.no);
      if (target) target.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });

    // 音频
    const el = new Audio(paper.audio);
    el.preload = 'metadata';
    const playBtn = $('#examPlay');
    const fill = $('#examFill');
    const knob = $('#examKnob');
    let started = false;

    el.addEventListener('loadedmetadata', () => { $('#examDur').textContent = fmt(el.duration); });
    el.addEventListener('play', () => {
      playBtn.classList.add('playing');
      $('.exam-player').classList.add('playing');
      $('#examStatus').textContent = '录音播放中';
    });
    el.addEventListener('pause', () => {
      playBtn.classList.remove('playing');
      $('.exam-player').classList.remove('playing');
      $('#examStatus').textContent = el.currentTime > 0 ? '已暂停' : '录音未开始';
    });
    el.addEventListener('timeupdate', () => {
      const r = el.duration ? el.currentTime / el.duration : 0;
      fill.style.width = (r * 100).toFixed(2) + '%';
      knob.style.left = (r * 100).toFixed(2) + '%';
      $('#examCur').textContent = fmt(el.currentTime);
    });
    el.addEventListener('ended', () => { $('#examStatus').textContent = '录音已播放完毕'; });

    playBtn.onclick = () => {
      if (!started) { started = true; }
      if (el.paused) el.play().catch(() => {}); else el.pause();
    };
    $('#examSeek').onclick = (ev) => {
      const r = ev.currentTarget.getBoundingClientRect();
      const ratio = Math.max(0, Math.min(1, (ev.clientX - r.left) / r.width));
      if (el.duration) el.currentTime = ratio * el.duration;
    };

    // 开始
    $('#startExam').onclick = () => {
      $('#startModal').classList.remove('show');
      started = true;
      el.play().catch(() => {});
      startTimer();
    };

    function startTimer() {
      if (timerId) return;
      timerId = setInterval(() => {
        timeLeft--;
        $('#timer').textContent = fmt(Math.max(0, timeLeft));
        if (timeLeft <= 0) { clearInterval(timerId); submit(true); }
      }, 1000);
    }

    $('#submitBtn').onclick = () => submit(false);
    $('#closeResult').onclick = () => $('#resultModal').classList.remove('show');

    function submit(auto) {
      if (submitted) return;
      const total = questions.length;
      const answered = Object.keys(answers).length;
      if (!auto && answered < total) {
        if (!confirm(`还有 ${total - answered} 题未作答，确定交卷？`)) return;
      }
      submitted = true;
      clearInterval(timerId);
      el.pause();

      const hasKey = Object.keys(known).length >= total;
      const title = $('#resultTitle');
      const score = $('#resultScore');
      const detail = $('#resultDetail');

      let right = 0;
      if (hasKey) {
        questions.forEach((q) => {
          const mine = answers[q.no];
          const card = $('#q-' + q.no);
          if (!card) return;
          $$('.opt', card).forEach((o) => {
            if (o.dataset.k === known[q.no]) o.classList.add('right');
            if (mine === o.dataset.k && mine !== known[q.no]) o.classList.add('wrong');
            if (o.dataset.k === known[q.no]) {
              const g = $(`.ag[data-no="${q.no}"]`, grid);
              if (g) g.classList.add(mine === known[q.no] ? 'correct' : 'incorrect');
            }
          });
          if (mine === known[q.no]) right++;
        });
      } else {
        questions.forEach((q) => {
          const g = $(`.ag[data-no="${q.no}"]`, grid);
          if (g) g.classList.add('done');
        });
      }

      const used = Math.max(0, limit - timeLeft);
      Cloud.setExam({
        answers: answers,
        submitted: true,
        submittedAt: new Date().toISOString(),
        score: hasKey ? right : null,
        total: total,
        answered: answered
      });
      Cloud.attempt({
        mode: auto ? 'exam_auto' : 'exam',
        answered: answered,
        total: total,
        score: hasKey ? right : null,
        duration: used
      });

      title.textContent = auto ? '时间到，已自动交卷' : '已交卷';
      if (hasKey) {
        score.innerHTML = `${right}<small> / ${total} 题</small>`;
        detail.textContent = `正确率 ${Math.round((right / total) * 100)}%，答对 ${right} 题，答错 ${answered - right} 题，未答 ${total - answered} 题。`;
      } else {
        score.innerHTML = `${answered}<small> / ${total} 题已作答</small>`;
        detail.textContent = '题库暂未录入官方答案，本次不计算分数。答题记录已保存在本机，可对照答案自行核对。';
      }
      $('#resultModal').classList.add('show');
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    $('#timer').textContent = fmt(timeLeft);
    sync();
    $('#startModal').classList.add('show');

    Cloud.init(paper.id, {}, [], { answers: answers }).then((res) => {
      if (!res) return;
      const ex = res.exam || {};
      if (ex.answers && Object.keys(ex.answers).length) {
        answers = ex.answers;
        store.set(key, answers);
        sync();
      }
    });
  }

  window.App = { initHome, initPractice, initExam };
})();
