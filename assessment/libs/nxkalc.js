/**
 * nxkalc.js — the Kovalna Kalc as a question's graphing calculator, behind
 * Desmos's calculator API. (Fork-only file.)
 *
 * A question loads this file and calls NXKalc.GraphingCalculator(elt, options)
 * the way it called Desmos.GraphingCalculator; unless the page already has a
 * real Desmos, `window.Desmos` is this object too, so a Desmos question runs
 * with only its <script src> changed. The calculator itself is the Kalc
 * (/kalc/index.html on this server, built from KOVALNA's tools-src/kalc), in a
 * frame, driven through its postMessage API — which is why every call here
 * is asynchronous underneath and the read calls (getExpressions, getState)
 * answer from a copy this file keeps in step with the Kalc's own list.
 *
 * Supported (Phase 1 of the nx Kalc plan):
 *   setState, getState, setBlank, setExpression, setExpressions,
 *   removeExpression, removeExpressions, getExpressions,
 *   observe('change'|..) / observeEvent('change'), unobserve*, setMathBounds,
 *   updateSettings (lockViewport only), resize, destroy;
 *   expression fields: id, latex, color, hidden, secret, readonly (the Kalc's
 *   fixed row), lineStyle/style, lineWidth; options: expressions (the student's expression list),
 *   lockViewport.
 * Tables: columns become the Kalc's data lists L1..L6 under their own names
 * (x_1 -> L1), the first two drawn as fixed points; a student regresses as in
 * Desmos (y_1 ~ m x_1 + b).
 * FourFunctionCalculator / ScientificCalculator: the Kalc's calculator mode.
 * Clicks on the graph reach the question's element as click events whose
 * position pixelsToMath maps to the graph point (questions that ask "click").
 * setState takes a state object or its JSON text.
 * Not yet (they warn once and do nothing): HelperExpression, sliders
 * (sliderBounds), notes, folders, images.
 */
(function () {
  'use strict';
  if (window.NXKalc) return;

  var KALC_VERSION = '106';

  // Where the Kalc lives: this file's own server. nx's question pages load
  // engine scripts by full address and set window.staticroot to the engine.
  var ROOT = (function () {
    var s = document.currentScript && document.currentScript.src;
    if (s && /\/assessment\/libs\/nxkalc\.js/.test(s)) {
      return s.replace(/\/assessment\/libs\/nxkalc\.js.*$/, '');
    }
    if (typeof window.staticroot === 'string') return window.staticroot.replace(/\/+$/, '');
    if (typeof window.imasroot === 'string') return window.imasroot.replace(/\/+$/, '');
    return '';
  })();

  var seq = 0;
  var warned = {};
  function warnOnce(key, msg) {
    if (warned[key]) return;
    warned[key] = true;
    if (window.console) console.warn('[nxkalc] ' + msg);
  }

  var DESMOS_COLORS = {
    RED: '#c74440', BLUE: '#2d70b3', GREEN: '#388c46', ORANGE: '#fa7e19',
    PURPLE: '#6042a6', BLACK: '#000000'
  };
  // Desmos line styles -> the Kalc's style names.
  var LINE_STYLES = { SOLID: 'solid', DASHED: 'dashed', DOTTED: 'dotted' };

  function toBool(v) { return v === true || v === 'true' || v === 1; }

  // A space in LaTeX means nothing, except that it ends a command word
  // (\pi x is not \pix). Authors' LaTeX is full of them ("9 x^2", from
  // makexxpretty); the Kalc's parser reads "9 x^2" as two things, not a
  // product. So: drop every run of space, but keep one after a command word
  // that a letter follows.
  function tidyLatex(s) {
    return String(s).replace(/(\\[A-Za-z]+)?\s+(?=([\s\S]))/g, function (m, cmd, next) {
      return cmd ? cmd + (/[A-Za-z]/.test(next) ? ' ' : '') : '';
    }).replace(/\s+$/, '');
  }

  function Calculator(elt, options, kind) {
    if (!elt) throw new Error('nxkalc: no element for the calculator');
    options = options || {};
    var self = this;
    var id = 'nxk' + (++seq);
    var studentList = toBool(options.expressions);
    var frame = document.createElement('iframe');
    // q= scopes everything the Kalc keeps in the browser (its variables, its
    // history) to this one calculator, and exam=1 keeps it in sessionStorage:
    // a question's "a = 1" must not reach the next question, or the next
    // student on a shared machine.
    var scope = 'nx' + Date.now().toString(36) + Math.floor(Math.random() * 1e9).toString(36);
    var q = ['v=' + KALC_VERSION, 'embedded=1', 'q=' + scope, 'exam=1'];
    // No expression list: the display slot — a clean graph that keeps the
    // trace and the zoom buttons. With one: the Kalc's question mode — the
    // expression input and the student's rows over the graph, where an
    // expression in x graphs without "y =", as in Desmos.
    if (kind === 'calc') q.push('calc=1');
    else if (!studentList) q.push('display=1', 'minimal=1', 'keepTrace=1', 'showControls=1');
    else q.push('question=1');
    frame.src = ROOT + '/kalc/index.html?' + q.join('&');
    frame.title = 'Graphing calculator';
    frame.setAttribute('allow', 'fullscreen');
    frame.style.cssText = 'border:0;width:100%;height:100%;min-height:240px;display:block;';
    // A question sizes its div (Desmos fills it); the Kalc fills the frame.
    // Keep the author's height, but never wider than the column.
    elt.style.maxWidth = '100%';
    if (!elt.style.height && elt.clientHeight < 50) elt.style.height = '400px';
    // With the expression list, the Kalc's input and the student's row sit
    // above the graph (~90px): grow the box by that much so the author's
    // graph area stays the size they set.
    if (studentList && kind !== 'calc') {
      var h = elt.getBoundingClientRect().height || 400;
      elt.style.height = Math.round(h + 90) + 'px';
    }
    elt.innerHTML = '';
    elt.appendChild(frame);

    var ready = false;
    var queue = [];
    var listeners = { change: [] };
    var lastState = null;
    var lockViewport = toBool(options.lockViewport);
    var bounds = null;

    function post(msg) {
      if (!ready) { queue.push(msg); return; }
      try { frame.contentWindow.postMessage(msg, '*'); } catch (e) {}
    }
    function flush() {
      if (ready) return;
      ready = true;
      var q2 = queue; queue = [];
      for (var i = 0; i < q2.length; i++) post(q2[i]);
    }
    // Desmos's list, as a question reads it. The author's rows in the order
    // they were set; an author row left EMPTY (Desmos's blank row, the one a
    // student types in) is a slot the student's rows fill, in order — the
    // Kalc adds a typed row as a new expression, Desmos edits the blank one.
    // The Kalc wants "y=" to graph a bare expression; Desmos never shows it,
    // so a student row's leading "y=" is dropped here.
    var declared = []; // [{ id, empty, latex }] in the order the author set them
    var lastList = []; // the Kalc's own list, as its last report gave it
    function declare(def) {
      var did = String(def.id != null ? def.id : '');
      if (!did) return;
      if (def.type === 'table') {
        for (var t = 0; t < declared.length; t++) if (declared[t].id === did) { declared[t].table = def.columns; return; }
        declared.push({ id: did, empty: false, latex: '', table: def.columns || [] });
        return;
      }
      var empty = def.latex == null || String(def.latex).trim() === '';
      var latex = empty ? '' : String(def.latex);
      for (var i = 0; i < declared.length; i++) if (declared[i].id === did) { declared[i].empty = empty; declared[i].latex = latex; return; }
      declared.push({ id: did, empty: empty, latex: latex });
    }
    function undeclare(did) { declared = declared.filter(function (d) { return d.id !== String(did); }); }
    // The Kalc rewrites what it is given (\cdot for a product; "y=" before a
    // bare expression in question mode), and a question copies getExpressions
    // into its answer box. So an author's row reads back as the author set it
    // until the student edits it in the Kalc; an edited row, or a student's
    // own, reads back as the Kalc has it, less a leading "y=" the author's
    // row did not have.
    var firstSeen = {}; // tag -> the Kalc's latex when first reported
    function shape(e, did) {
      var latex = e.latex || e.expr || '';
      var d = null;
      for (var i = 0; i < declared.length; i++) if (declared[i].id === did) { d = declared[i]; break; }
      if (e.tag != null) {
        if (!(e.tag in firstSeen)) firstSeen[e.tag] = latex;
        if (d && !d.empty && latex === firstSeen[e.tag]) latex = d.latex;
      }
      var hadY = d && /^\s*y\s*=/.test(d.latex || '');
      if (!hadY && latex !== (d && d.latex)) latex = latex.replace(/^\s*y\s*=\s*/, '');
      return { id: did, type: 'expression', latex: latex, color: e.color || undefined, hidden: e.visible === false, secret: !!e.ghost };
    }
    function buildMirror(list) {
      var byTag = {}; var student = [];
      list.forEach(function (e) {
        if (e.tag != null && /^d:/.test(e.tag)) byTag[e.tag.slice(2)] = e;
        else student.push(e);
      });
      var out = [];
      declared.forEach(function (d) {
        if (d.table) { delete byTag[d.id]; out.push({ id: d.id, type: 'table', columns: d.table }); return; }
        if (byTag[d.id]) { out.push(shape(byTag[d.id], d.id)); delete byTag[d.id]; }
        else if (!d.empty) {
          // set, but the Kalc has not reported it yet (or could not draw it):
          // Desmos answers at once, with what was set
          out.push({ id: d.id, type: 'expression', latex: d.latex });
        } else {
          var s = student.shift();
          out.push(s ? shape(s, d.id) : { id: d.id, type: 'expression', latex: '' });
        }
      });
      Object.keys(byTag).forEach(function (k) { out.push(shape(byTag[k], k)); });
      student.forEach(function (s) { out.push(shape(s, 'k' + s.id)); });
      return out;
    }
    var watchType = id + ':all';
    var clickType = id + ':click';
    // Graph coordinates <-> pixels of the question's element (Desmos's
    // pixelsToMath / mathToPixels): the window this file set, over the box.
    function curBounds() { return bounds || { xmin: -10, xmax: 10, ymin: -10, ymax: 10 }; }
    function pixelsToMath(p) {
      var b = curBounds(), w = elt.clientWidth || 1, h = elt.clientHeight || 1;
      return { x: b.xmin + (Number(p.x) / w) * (b.xmax - b.xmin), y: b.ymax - (Number(p.y) / h) * (b.ymax - b.ymin) };
    }
    function mathToPixels(m) {
      var b = curBounds(), w = elt.clientWidth || 1, h = elt.clientHeight || 1;
      return { x: (Number(m.x) - b.xmin) / (b.xmax - b.xmin) * w, y: (b.ymax - Number(m.y)) / (b.ymax - b.ymin) * h };
    }
    function onMessage(ev) {
      if (ev.source !== frame.contentWindow || !ev.data || typeof ev.data !== 'object') return;
      if (ev.data.type === 'kovalna-ready') {
        flush();
      } else if (ev.data.type === clickType && isFinite(ev.data.x) && isFinite(ev.data.y)) {
        var px = mathToPixels({ x: Number(ev.data.x), y: Number(ev.data.y) });
        var r = elt.getBoundingClientRect();
        try {
          elt.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, clientX: r.left + px.x, clientY: r.top + px.y }));
        } catch (e) {}
      } else if (ev.data.type === watchType && Array.isArray(ev.data.expressions)) {
        lastList = ev.data.expressions;
        var cbs = listeners.change.slice();
        for (var i = 0; i < cbs.length; i++) { try { cbs[i](); } catch (err) { if (window.console) console.error(err); } }
      }
    }
    window.addEventListener('message', onMessage);
    // An older Kalc build sends no ready: the frame's load is late enough.
    frame.addEventListener('load', function () { setTimeout(flush, 50); });

    // Every expression change comes back as the full list (watch '*').
    if (kind !== 'calc') {
      post({ type: 'kovalna-watch', tag: '*', replyTo: watchType });
      post({ type: 'kovalna-clicks', on: true, replyTo: clickType });
    }
    if (options.lockViewport) post({ type: 'kovalna-freeze', pan: true, zoom: true });

    function tagOf(def) { return 'd:' + String(def.id != null ? def.id : 'auto' + (++seq)); }

    function addDef(def) {
      if (!def) return;
      var type = def.type || 'expression';
      if (type === 'table') { addTable(def); return; }
      if (type !== 'expression') { warnOnce('type:' + type, 'expression type "' + type + '" is not supported yet'); return; }
      declare(def);
      if (def.sliderBounds) warnOnce('slider', 'sliders (sliderBounds) are not supported yet; ' + (def.latex || '') + ' is drawn as a plain expression');
      var msg = { type: 'kovalna-add', tag: tagOf(def) };
      delete firstSeen[msg.tag]; // the author set it again: what the Kalc reports next is the author's
      if (def.latex != null && String(def.latex).trim() !== '') msg.latex = tidyLatex(def.latex);
      else { post({ type: 'kovalna-remove', tag: msg.tag }); return; }
      if (def.color) msg.color = String(def.color);
      var ls = def.lineStyle || def.style;
      if (ls && LINE_STYLES[String(ls).toUpperCase()]) msg.style = LINE_STYLES[String(ls).toUpperCase()];
      if (def.lineWidth != null) msg.weight = Number(def.lineWidth);
      if (def.hidden != null) msg.visible = !toBool(def.hidden);
      if (def.secret != null) msg.ghost = toBool(def.secret);
      // Desmos's readonly row: the Kalc's fixed row (shown, drawn, never changed)
      if (def.readonly != null) msg.fixed = toBool(def.readonly);
      post(msg);
    }

    // A Desmos table: each column becomes one of the Kalc's data lists (L1..L6,
    // in the order tables and columns come), and the column's own name (x_1,
    // and x1 as the Kalc's converter writes it) is given to that list, so a
    // student's regression reads the way it did in Desmos: y_1 ~ m x_1 + b.
    // The first two columns are drawn as a fixed points row.
    var nextList = 1, listNames = {};
    function addTable(def) {
      declare(def);
      var cols = (def.columns || []).slice(0, 7 - nextList);
      if (cols.length < (def.columns || []).length) warnOnce('lists', 'the Kalc has six data lists; columns past L6 are left out');
      var nums = cols.map(function (c) {
        return (c.values || []).map(function (v) { return Number(v); });
      });
      cols.forEach(function (c, i) {
        var L = 'L' + nextList++;
        var vals = nums[i].filter(function (v) { return isFinite(v); });
        post({ type: 'kovalna-add', tag: 'd:' + def.id + ':' + L, eq: L + '=[' + vals.join(',') + ']' });
        var name = String(c.latex || '').trim();
        if (/^[A-Za-z](_\{?[A-Za-z0-9]+\}?)?$/.test(name)) {
          listNames[name.replace(/[{}]/g, '')] = L;
          listNames[name.replace(/[_{}]/g, '')] = L;
        }
      });
      post({ type: 'kovalna-listnames', names: listNames });
      if (nums.length >= 2) {
        var pts = [];
        for (var k = 0; k < Math.min(nums[0].length, nums[1].length); k++) {
          if (isFinite(nums[0][k]) && isFinite(nums[1][k])) pts.push('(' + nums[0][k] + ',' + nums[1][k] + ')');
        }
        if (pts.length) {
          var m = { type: 'kovalna-add', tag: 'd:' + def.id, eq: pts.join(' '), fixed: true };
          var col = (cols[1] && cols[1].color) || def.color;
          if (col) m.color = String(col);
          post(m);
        }
      }
    }

    function setWindow(b) {
      if (!b) return;
      var x = [Number(b.left != null ? b.left : b.xmin), Number(b.right != null ? b.right : b.xmax)];
      var y = [Number(b.bottom != null ? b.bottom : b.ymin), Number(b.top != null ? b.top : b.ymax)];
      if ([x[0], x[1], y[0], y[1]].some(function (n) { return !isFinite(n); })) return;
      bounds = { xmin: x[0], xmax: x[1], ymin: y[0], ymax: y[1] };
      // Desmos keeps the author's box as given (no equal units).
      post({ type: 'kovalna-window', x: x, y: y, aspect: 'free' });
    }

    this.setState = function (state) {
      if (typeof state === 'string') {
        try { state = JSON.parse(state); } catch (e) { warnOnce('state-json', 'setState: the state text is not JSON'); return; }
      }
      lastState = state || null;
      declared = [];
      firstSeen = {};
      nextList = 1; listNames = {};
      post({ type: 'kovalna-listnames', names: {} });
      post({ type: 'kovalna-clear' });
      var g = (state && state.graph) || {};
      if (g.viewport) setWindow(g.viewport);
      // Desmos's polar mode is the Kalc's polar grid (its r = f(theta) curves
      // draw either way). After the clear, which turns the grid off.
      if (toBool(g.polarMode)) post({ type: 'kovalna-window', polar: true });
      var list = (state && state.expressions && state.expressions.list) || [];
      for (var i = 0; i < list.length; i++) addDef(list[i]);
    };
    this.setBlank = function () { lastState = null; post({ type: 'kovalna-clear' }); };
    this.getState = function () {
      var s = lastState ? JSON.parse(JSON.stringify(lastState)) : { version: 9, graph: {}, expressions: { list: [] } };
      s.graph = s.graph || {};
      if (bounds) s.graph.viewport = bounds;
      s.expressions = { list: self.getExpressions() };
      return s;
    };
    this.setExpression = function (def) { addDef(def); };
    this.setExpressions = function (defs) { (defs || []).forEach(addDef); };
    this.removeExpression = function (def) {
      if (def && def.id != null) { undeclare(def.id); post({ type: 'kovalna-remove', tag: tagOf(def) }); }
    };
    this.removeExpressions = function (defs) { (defs || []).forEach(self.removeExpression); };
    this.getExpressions = function () { return buildMirror(lastList); };
    this.setMathBounds = function (b) { setWindow(b); };
    this.pixelsToMath = pixelsToMath;
    this.mathToPixels = mathToPixels;
    this.updateSettings = function (s) {
      s = s || {};
      if ('lockViewport' in s) {
        lockViewport = toBool(s.lockViewport);
        post({ type: lockViewport ? 'kovalna-freeze' : 'kovalna-thaw', pan: true, zoom: true });
      }
      // Display-only settings the Kalc has no use for pass without a word.
      var quiet = { brailleMode: 1, projectorMode: 1, fontSize: 1, invertedColors: 1, language: 1 };
      Object.keys(s).forEach(function (k) { if (k !== 'lockViewport' && !quiet[k]) warnOnce('setting:' + k, 'setting "' + k + '" is ignored'); });
    };
    // Desmos: observe('expressionAnalysis'|'graphpaperBounds'|..., cb) and
    // observeEvent('change', cb). Every one of them is "something changed".
    this.observe = function (name, cb) { if (typeof cb === 'function') listeners.change.push(cb); };
    this.observeEvent = function (name, cb) { if (typeof cb === 'function') listeners.change.push(cb); };
    this.unobserve = function () { listeners.change = []; };
    this.unobserveEvent = function () { listeners.change = []; };
    this.resize = function () { post({ type: 'kovalna-render' }); };
    this.focusFirstExpression = function () {};
    this.HelperExpression = unsupported('HelperExpression');
    this.destroy = function () {
      window.removeEventListener('message', onMessage);
      if (frame.parentNode) frame.parentNode.removeChild(frame);
      listeners.change = [];
    };
    Object.defineProperty(this, 'graphpaperBounds', {
      get: function () {
        var b = bounds || { xmin: -10, xmax: 10, ymin: -10, ymax: 10 };
        return { mathCoordinates: { left: b.xmin, right: b.xmax, bottom: b.ymin, top: b.ymax, width: b.xmax - b.xmin, height: b.ymax - b.ymin } };
      }
    });
    this._kalcFrame = frame; // test seam
    // for mount() below
    this._post = post;
    this._list = function () { return lastList; };
    this._onChange = function (cb) { listeners.change.push(cb); };
    this._setWindow = setWindow;
  }

  // ── The state a question keeps (kalc.php's kalc_state) ──────────────────
  // "k1:" + base64(deflate-raw(json)) where the browser can compress, else
  // "k0:" + json. kalc.php reads both.
  function toB64(bytes) {
    var s = '';
    for (var i = 0; i < bytes.length; i += 0x8000) s += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
    return btoa(s);
  }
  function fromB64(b64) {
    var s = atob(b64), out = new Uint8Array(s.length);
    for (var i = 0; i < s.length; i++) out[i] = s.charCodeAt(i);
    return out;
  }
  function pipe(bytes, stream) {
    var w = stream.writable.getWriter(); w.write(bytes); w.close();
    return new Response(stream.readable).arrayBuffer().then(function (b) { return new Uint8Array(b); });
  }
  function encodeState(obj) {
    var json = JSON.stringify(obj);
    if (typeof CompressionStream === 'undefined') return Promise.resolve('k0:' + json);
    return pipe(new TextEncoder().encode(json), new CompressionStream('deflate-raw'))
      .then(function (b) { return 'k1:' + toB64(b); }, function () { return 'k0:' + json; });
  }
  function decodeState(s) {
    s = String(s || '').trim();
    try {
      if (s.indexOf('k0:') === 0) return Promise.resolve(JSON.parse(s.slice(3)));
      if (s.indexOf('k1:') === 0 && typeof DecompressionStream !== 'undefined') {
        return pipe(fromB64(s.slice(3)), new DecompressionStream('deflate-raw'))
          .then(function (b) { return JSON.parse(new TextDecoder().decode(b)); })
          .catch(function () { return null; });
      }
    } catch (e) {}
    return Promise.resolve(null);
  }

  // Set an answer box's value the way a student's typing would, so the page
  // (IMathAS, or nx's player and its autosave) takes it as an answer.
  function setAnswer(qn, latex, text) {
    var mqEl = document.getElementById('mqinput-qn' + qn);
    if (mqEl && window.MQ && latex != null) {
      try { window.MQ(mqEl).latex(latex); return true; } catch (e) {}
    }
    var el = document.getElementById('qn' + qn);
    if (!el) return false;
    el.value = text;
    ['input', 'change'].forEach(function (t) {
      try { el.dispatchEvent(new Event(t, { bubbles: true })); } catch (e) {}
    });
    return true;
  }

  /**
   * NXKalc.mount(cfg) — the graph kalc.php's kalc_render writes. cfg:
   *   el, window [xmin,xmax,ymin,ymax], input, height, lock,
   *   rows: [{ tag:'kq:N', eq | latex, ghost?, visible?, color?, style? }]
   *   state: { qn, saved }   the answer box that keeps the student's rows
   *   bind:  { qn }          the answer box the student's latest row goes to
   * The author's rows come from cfg every time; only the student's rows (and
   * the window) are kept in, and restored from, the state box.
   */
  function mount(cfg) {
    var elt = document.getElementById(cfg.el);
    if (!elt || elt.getAttribute('data-nxkalc')) return null;
    elt.setAttribute('data-nxkalc', '1');
    elt.style.height = (cfg.height || 400) + 'px';
    var calc = new Calculator(elt, { expressions: !!cfg.input, lockViewport: !!cfg.lock }, 'graphing');
    var w = cfg.window || [-10, 10, -10, 10];
    var win = { xmin: w[0], xmax: w[1], ymin: w[2], ymax: w[3] };
    calc._post({ type: 'kovalna-clear' });
    calc._setWindow(win);
    (cfg.rows || []).forEach(function (r) {
      var m = { type: 'kovalna-add', tag: r.tag };
      if (r.latex != null) m.latex = tidyLatex(r.latex); else m.eq = String(r.eq);
      ['ghost', 'visible', 'color', 'style', 'fixed'].forEach(function (k) { if (r[k] != null) m[k] = r[k]; });
      calc._post(m);
    });

    function studentRows() {
      return calc._list().filter(function (e) { return !(e.tag && /^(kq:|d:)/.test(e.tag)); });
    }

    var stateQn = cfg.state && cfg.state.qn;
    if (stateQn != null) {
      // The box that keeps the graph is not for the student to see or type in.
      ['qn', 'mqinput-qn', 'pbtn', 'qnwrap'].forEach(function (p) {
        var n = document.getElementById(p + stateQn);
        if (n && p !== 'qnwrap') { n.style.display = 'none'; n.setAttribute('aria-hidden', 'true'); }
      });
    }

    // Restore first; write only once the restored rows are back (or after a
    // grace), so a slow frame can never blank a saved graph.
    var restoring = 0, writing = false, pending = null, lastWritten = null;
    var saved = cfg.state ? cfg.state.saved : '';
    var restored = (saved ? decodeState(saved) : Promise.resolve(null)).then(function (st) {
      if (!st) return;
      if (st.window && st.window.length === 4) calc._setWindow({ xmin: st.window[0], xmax: st.window[1], ymin: st.window[2], ymax: st.window[3] });
      (st.rows || []).forEach(function (r, i) {
        if (!r || !r.expr) return;
        restoring++;
        var m = { type: 'kovalna-add', tag: 'ks:' + i, eq: String(r.expr) };
        if (r.visible === false) m.visible = false;
        calc._post(m);
      });
      lastWritten = saved;
    });
    var graceOver = false;
    setTimeout(function () { graceOver = true; }, 4000);

    function writeState() {
      if (stateQn == null) return;
      var rows = studentRows().map(function (e) {
        var r = { expr: e.expr || '', latex: e.latex || '' };
        if (e.visible === false) r.visible = false;
        return r;
      });
      var obj = { v: 1, rows: rows, window: [win.xmin, win.xmax, win.ymin, win.ymax] };
      if (writing) { pending = true; return; }
      writing = true;
      encodeState(obj).then(function (s) {
        writing = false;
        if (s !== lastWritten) {
          var box = document.getElementById('qn' + stateQn);
          if (box && box.tagName === 'INPUT' && s.length > 7900) {
            warnOnce('state-size', 'the graph is too big to keep in a text box (' + s.length + ' characters): use an essay part for kalc_state');
          } else if (setAnswer(stateQn, null, s)) {
            lastWritten = s;
          }
        }
        if (pending) { pending = null; writeState(); } // changed while encoding: write the newest
      });
    }

    calc._onChange(function () {
      restored.then(function () {
        var rows = studentRows();
        if (restoring && rows.length < restoring && !graceOver) return; // the restore is still landing
        restoring = 0;
        writeState();
        if (cfg.bind && rows.length) {
          var last = rows[rows.length - 1];
          var latex = (last.latex || '').replace(/^\s*y\s*=\s*/, '');
          var text = (last.expr || '').replace(/^\s*y\s*=\s*/, '');
          setAnswer(cfg.bind.qn, latex || null, text);
        }
      });
    });
    return calc;
  }

  function unsupported(name) {
    return function () {
      warnOnce('ctor:' + name, name + ' is not supported yet');
      return {
        observe: function () {}, unobserve: function () {}, numericValue: NaN, listValue: [],
        setExpression: function () {}, setState: function () {}, getExpressions: function () { return []; },
        destroy: function () {}
      };
    };
  }

  var api = {
    GraphingCalculator: function (elt, options) { return new Calculator(elt, options, 'graphing'); },
    // A side calculator: the Kalc's calculator mode (input over the answers).
    // The Kalc is scientific, so both get it; its settings are ignored.
    FourFunctionCalculator: function (elt, options) { return new Calculator(elt, options, 'calc'); },
    ScientificCalculator: function (elt, options) { return new Calculator(elt, options, 'calc'); },
    HelperExpression: unsupported('HelperExpression'),
    Styles: { SOLID: 'SOLID', DASHED: 'DASHED', DOTTED: 'DOTTED', POINT: 'POINT', OPEN: 'OPEN', CROSS: 'CROSS' },
    Colors: DESMOS_COLORS,
    DragModes: { X: 'X', Y: 'Y', XY: 'XY', NONE: 'NONE', AUTO: 'AUTO' },
    LabelSizes: { SMALL: 'SMALL', MEDIUM: 'MEDIUM', LARGE: 'LARGE' },
    LabelOrientations: { DEFAULT: 'default', CENTER: 'center', ABOVE: 'above', BELOW: 'below', LEFT: 'left', RIGHT: 'right' },
    supportedLanguages: ['en'],
    nxkalc: true,
    mount: mount,
    _tidyLatex: tidyLatex, // test seams
    _encodeState: encodeState,
    _decodeState: decodeState
  };
  window.NXKalc = api;
  if (!window.Desmos) window.Desmos = api;
})();
