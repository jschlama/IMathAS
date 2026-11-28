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
 *   expression fields: id, latex, color, hidden, secret, lineStyle/style,
 *   lineWidth; options: expressions (the student's expression list),
 *   lockViewport.
 * Not yet (they warn once and do nothing): HelperExpression, sliders
 * (sliderBounds), tables, notes, folders, images, FourFunctionCalculator.
 */
(function () {
  'use strict';
  if (window.NXKalc) return;

  var KALC_VERSION = '102';

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
    if (!studentList) q.push('display=1', 'minimal=1', 'keepTrace=1', 'showControls=1');
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
    if (studentList) {
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
    function onMessage(ev) {
      if (ev.source !== frame.contentWindow || !ev.data || typeof ev.data !== 'object') return;
      if (ev.data.type === 'kovalna-ready') {
        flush();
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
    post({ type: 'kovalna-watch', tag: '*', replyTo: watchType });
    if (options.lockViewport) post({ type: 'kovalna-freeze', pan: true, zoom: true });

    function tagOf(def) { return 'd:' + String(def.id != null ? def.id : 'auto' + (++seq)); }

    function addDef(def) {
      if (!def) return;
      var type = def.type || 'expression';
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
      post(msg);
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
      lastState = state || null;
      declared = [];
      firstSeen = {};
      post({ type: 'kovalna-clear' });
      var g = (state && state.graph) || {};
      if (g.viewport) setWindow(g.viewport);
      if (toBool(g.polarMode)) warnOnce('polar', 'polar mode is not supported yet');
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
    this.updateSettings = function (s) {
      s = s || {};
      if ('lockViewport' in s) {
        lockViewport = toBool(s.lockViewport);
        post({ type: lockViewport ? 'kovalna-freeze' : 'kovalna-thaw', pan: true, zoom: true });
      }
      Object.keys(s).forEach(function (k) { if (k !== 'lockViewport') warnOnce('setting:' + k, 'setting "' + k + '" is ignored'); });
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
    FourFunctionCalculator: unsupported('FourFunctionCalculator'),
    ScientificCalculator: unsupported('ScientificCalculator'),
    HelperExpression: unsupported('HelperExpression'),
    Styles: { SOLID: 'SOLID', DASHED: 'DASHED', DOTTED: 'DOTTED', POINT: 'POINT', OPEN: 'OPEN', CROSS: 'CROSS' },
    Colors: DESMOS_COLORS,
    DragModes: { X: 'X', Y: 'Y', XY: 'XY', NONE: 'NONE', AUTO: 'AUTO' },
    LabelSizes: { SMALL: 'SMALL', MEDIUM: 'MEDIUM', LARGE: 'LARGE' },
    LabelOrientations: { DEFAULT: 'default', CENTER: 'center', ABOVE: 'above', BELOW: 'below', LEFT: 'left', RIGHT: 'right' },
    supportedLanguages: ['en'],
    nxkalc: true,
    _tidyLatex: tidyLatex // test seam
  };
  window.NXKalc = api;
  if (!window.Desmos) window.Desmos = api;
})();
