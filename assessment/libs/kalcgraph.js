var KalcGraph = function(exports) {
  "use strict";
  const ExpressionType = {
    Y_EQUALS: "y=",
    X_EQUALS: "x=",
    IMPLICIT: "implicit",
    POINT: "point"
  };
  const DisplayMode = {
    FULL: "full",
    EQUATION_MATCH_LIVE: "equation-match-live",
    EQUATION_MATCH_VALIDATE: "equation-match-validate",
    DRAW_TO_MATCH: "draw-to-match",
    DRAW_FREE: "draw-free",
    STATIC: "static"
  };
  const TickMode = {
    INTEGER: "integer",
    PI_6: "pi/6",
    PI_4: "pi/4",
    PI_3: "pi/3",
    PI_2: "pi/2",
    PI: "pi",
    CUSTOM: "custom"
  };
  const ScaleMode = {
    LINEAR: "linear",
    ALGEBRA: "algebra",
    TRIG: "trig"
  };
  const Theme = {
    DARK: "dark",
    LIGHT: "light",
    AUTO: "auto"
  };
  const ALL_CURVE_TYPES = ["line", "parabola", "cubic", "sine", "cosine", "exp", "ln", "logistic"];
  const CurveType = {
    LINE: "line",
    PARABOLA: "parabola",
    CUBIC: "cubic",
    SINE: "sine",
    COSINE: "cosine",
    EXP: "exp",
    LN: "ln",
    LOGISTIC: "logistic"
  };
  const GradingMode = {
    NONE: "none",
    CURVE_SHAPE: "curve-shape",
    STRICT: "strict"
  };
  const SpecialPointType = {
    X_INTERCEPT: "x-intercept",
    Y_INTERCEPT: "y-intercept",
    MAXIMUM: "maximum",
    MINIMUM: "minimum",
    INFLECTION: "inflection",
    ASYMPTOTE: "asymptote",
    DISCONTINUITY: "discontinuity"
  };
  const LINE_STYLE_CONFIG = {
    thin: { width: 2, dash: false },
    medium: { width: 3, dash: false },
    thick: { width: 4.5, dash: false },
    dashed: { width: 3, dash: true },
    dotted: { width: 3, dash: true }
  };
  const POINT_STYLE = {
    FILLED: "filled",
    OPEN: "open"
  };
  const COLORS_DARK = [
    "#3B82F6",
    "#F43F5E",
    "#10B981",
    "#F59E0B",
    "#8B5CF6",
    "#EC4899",
    "#06B6D4",
    "#84CC16",
    "#F97316",
    "#6366F1",
    "#14B8A6",
    "#E11D48"
  ];
  const COLORS_LIGHT = [
    "#2563EB",
    "#DC2626",
    "#059669",
    "#D97706",
    "#7C3AED",
    "#DB2777",
    "#0891B2",
    "#65A30D",
    "#EA580C",
    "#4F46E5",
    "#0D9488",
    "#BE123C"
  ];
  const THEME_VARS = {
    dark: {
      "--gl-bg": "#0a0a0c",
      "--gl-surface": "#131316",
      "--gl-border": "#1e1e24",
      "--gl-text": "#e8e6e0",
      "--gl-text-muted": "#74726c",
      "--gl-canvas": "#0d0d10",
      "--gl-danger": "#ef4444",
      // grid/axis aligned with the approved kalcgraph.css values (Phase 2.5
      // carry-along item 6; grid brightness is John's live-approved tweak,
      // axis 0.35 is the canonical mockup value).
      "--gl-grid": "rgba(255,255,255,0.14)",
      "--gl-grid-minor": "rgba(255,255,255,0.06)",
      "--gl-axis": "rgba(255,255,255,0.35)",
      "--gl-tick-label": "#e2e2f0"
    },
    light: {
      "--gl-bg": "#ffffff",
      "--gl-surface": "#f8f8f8",
      "--gl-border": "#e0e0e0",
      "--gl-text": "#1a1a1a",
      "--gl-text-muted": "#888888",
      "--gl-canvas": "#ffffff",
      "--gl-danger": "#dc2626",
      "--gl-grid": "rgba(0,0,0,0.18)",
      "--gl-grid-minor": "rgba(0,0,0,0.08)",
      "--gl-axis": "rgba(0,0,0,0.7)",
      "--gl-tick-label": "#333333"
    }
  };
  const DEFAULT_VIEWPORT = { xMin: -10, xMax: 10, yMin: -7, yMax: 7 };
  const DEFAULT_CONFIG = {
    displayMode: DisplayMode.FULL,
    theme: "auto",
    allowThemeToggle: true,
    maxExpressions: 6,
    allowedTypes: ["y=", "x=", "implicit", "point"],
    allowPiecewise: false,
    allowDomainRestrictions: true,
    allowColorChange: true,
    allowStyleChange: true,
    showGrid: true,
    showAxisLabels: true,
    xTickMode: TickMode.INTEGER,
    xTickCustom: 1,
    yTickStep: 1,
    mode: "standard",
    lockViewport: false,
    viewport: DEFAULT_VIEWPORT,
    preloadedExpressions: [],
    showSettingsPanel: false,
    traceEnabled: false,
    traceShowExact: true,
    traceShowSlope: true,
    traceSnapToSpecial: true,
    traceShowDiscontinuities: true,
    traceSnapDistance: 0.4,
    showExactDecimalToggle: false,
    intersectionsEnabled: false,
    showIntersectionCoords: true,
    showOverlapScore: false,
    showSubmitButton: false,
    submitButtonLabel: "Submit",
    gradingMode: "none",
    gradeThreshold: 90,
    strictTolerance: 0.25,
    showComparisonAnimation: true,
    revealTargetOnSubmit: true,
    allowedCurveTypes: ["line", "parabola", "cubic", "sine", "cosine", "exp", "ln", "logistic"],
    gridStep: 1,
    lockGridStep: false,
    showEquationReadout: true,
    targetCurve: null,
    correctColor: "#10B981",
    incorrectColor: "#EF4444"
  };
  function classifyType(raw) {
    const s = raw.trim();
    if (/^\(\s*-?[\d.eπ/]+\s*,\s*-?[\d.eπ/]+\s*\)$/.test(s)) return ExpressionType.POINT;
    if (/^\s*x\s*=/i.test(s)) {
      const rhs = s.replace(/^\s*x\s*=\s*/i, "");
      if (containsVar(rhs, "y")) return ExpressionType.IMPLICIT;
      return ExpressionType.X_EQUALS;
    }
    if (s.includes("=")) {
      const [lhs, rhs] = s.split("=").map((p) => p.trim());
      if (/^\s*y\s*$/.test(lhs) && !containsVar(rhs, "y")) return ExpressionType.Y_EQUALS;
      const combined = lhs + " " + rhs;
      if (containsVar(combined, "x") && containsVar(combined, "y")) return ExpressionType.IMPLICIT;
      if (/^\s*y\s*$/.test(lhs)) return ExpressionType.Y_EQUALS;
    }
    return ExpressionType.Y_EQUALS;
  }
  function containsVar(str, v) {
    return new RegExp(`(?<![a-zA-Z])${v}(?![a-zA-Z])`, "i").test(str);
  }
  function extractDomain(domainStr) {
    const s = domainStr.trim();
    const leftInclusive = s[0] === "[";
    const rightInclusive = s[s.length - 1] === "]";
    const inner = s.slice(1, -1).trim();
    const parts = inner.split(",").map((p) => p.trim());
    if (parts.length !== 2) {
      return { range: [-Infinity, Infinity], inclusive: [true, true] };
    }
    const lo = parseEndpoint(parts[0]);
    const hi = parseEndpoint(parts[1]);
    return {
      range: [lo, hi],
      inclusive: [leftInclusive, rightInclusive]
    };
  }
  function parseEndpoint(s) {
    const t = s.trim().toLowerCase();
    if (t === "oo" || t === "inf" || t === "infinity" || t === "∞") return Infinity;
    if (t === "-oo" || t === "-inf" || t === "-infinity" || t === "-∞") return -Infinity;
    const n = parseFloat(t);
    if (isNaN(n)) return -Infinity;
    return n;
  }
  const TRIG_FUNCS = [
    "sin",
    "cos",
    "tan",
    "sec",
    "csc",
    "cot",
    "asin",
    "acos",
    "atan",
    "arcsin",
    "arccos",
    "arctan",
    "sinh",
    "cosh",
    "tanh"
  ];
  const FUNCS = [...TRIG_FUNCS, "sqrt", "cbrt", "abs", "ln", "log", "exp"];
  function friendlyError(msg) {
    if (!msg) return "Invalid expression";
    if (msg.includes("Unexpected token")) return "Check for missing or extra brackets";
    if (msg.includes("Unexpected end")) return "Expression appears incomplete";
    if (msg.includes("is not defined")) {
      const m = msg.match(/(\w+) is not defined/);
      return m ? `Unknown: ${m[1]}` : "Unknown variable";
    }
    return msg.length > 80 ? msg.substring(0, 80) + "…" : msg;
  }
  function parseExpression(raw, options = {}) {
    if (raw == null || typeof raw !== "string") return empty();
    const trimmed = raw.trim();
    if (!trimmed) return empty();
    if (trimmed.length > 500) return { ...empty(), error: "Expression too long (max 500 characters)" };
    if (/[{};]|=>|import|require|eval|Function/.test(trimmed)) return { ...empty(), error: "Expression contains invalid characters" };
    try {
      const { body, domainStr } = splitDomain(trimmed);
      if (!body.trim()) return empty();
      const type = classifyType(body);
      const funcBody = extractFuncBody(body, type);
      const normalized = normalizeDisplay(body, type);
      const jsExpr = buildJsExpr(funcBody, type);
      if (jsExpr && type !== "point" && type !== "implicit") {
        try {
          const testFn = new Function("x", `"use strict"; return (${jsExpr});`);
          const testResult = testFn(1);
          if (typeof testResult !== "number") return { type, normalized: normalizeDisplay(body, type), latex: buildLatex(funcBody, type), jsExpr: "", domain: [-Infinity, Infinity], domainInclusive: [true, true], error: "Expression does not produce a numeric value" };
        } catch (e) {
          return { type, normalized: normalizeDisplay(body, type), latex: buildLatex(funcBody, type), jsExpr: "", domain: [-Infinity, Infinity], domainInclusive: [true, true], error: `Parse error: ${friendlyError(e.message)}` };
        }
      }
      const latex = buildLatex(funcBody, type);
      const domain = domainStr ? extractDomain(domainStr) : { range: [-Infinity, Infinity], inclusive: [true, true] };
      if (options.allowedTypes && options.allowedTypes.length > 0 && !options.allowedTypes.includes(type)) {
        return { type, normalized, latex, jsExpr, domain: domain.range, domainInclusive: domain.inclusive, error: `Type "${type}" is not allowed` };
      }
      if (domainStr && options.allowDomainRestrictions === false) {
        return { type, normalized, latex, jsExpr, domain: [-Infinity, Infinity], domainInclusive: [true, true], error: "Domain restrictions not allowed" };
      }
      return { type, normalized, latex, jsExpr, domain: domain.range, domainInclusive: domain.inclusive, error: null };
    } catch (err) {
      return { type: null, normalized: trimmed, latex: trimmed, jsExpr: "", domain: [-Infinity, Infinity], domainInclusive: [true, true], error: `Parse error: ${friendlyError(err.message)}` };
    }
  }
  function empty() {
    return { type: null, normalized: "", latex: "", jsExpr: "", domain: [-Infinity, Infinity], domainInclusive: [true, true], error: null };
  }
  function splitDomain(raw) {
    const lastColon = raw.lastIndexOf(":");
    if (lastColon === -1) return { body: raw.trim(), domainStr: null };
    const after = raw.substring(lastColon + 1).trim();
    if (/^[\[\(]/.test(after)) return { body: raw.substring(0, lastColon).trim(), domainStr: after };
    return { body: raw.trim(), domainStr: null };
  }
  function extractFuncBody(body, type) {
    if (type === "y=") return body.replace(/^\s*y\s*=\s*/i, "").trim() || body;
    if (type === "x=") return body.replace(/^\s*x\s*=\s*/i, "").trim();
    return body;
  }
  function normalizeDisplay(body, type) {
    let s = body.trim();
    if (type === "y=" && !/^\s*y\s*=/i.test(s)) s = "y = " + s;
    return s.replace(/\s+/g, " ");
  }
  function buildJsExpr(funcBody, type) {
    let s = funcBody.trim();
    if (type === "point") {
      const m = s.match(/^\(\s*(-?[\d.]+(?:\/[\d.]+)?)\s*,\s*(-?[\d.]+(?:\/[\d.]+)?)\s*\)$/);
      if (m) return `[${evalFrac(m[1])}, ${evalFrac(m[2])}]`;
      return "";
    }
    s = insertImplicitMul(s);
    s = replaceConstants(s);
    s = normalizeFuncs(s);
    s = s.replace(/\|([^|]+)\|/g, "Math.abs($1)");
    s = wrapUnaryMinusPowers(s);
    s = s.replace(/\^/g, "**");
    return s;
  }
  function wrapUnaryMinusPowers(s) {
    const isIdent = (c) => /[A-Za-z0-9_.]/.test(c);
    function readToken(str, p) {
      if (p >= str.length) return -1;
      if (str[p] === "(") {
        let depth = 0;
        for (let i2 = p; i2 < str.length; i2++) {
          if (str[i2] === "(") depth++;
          else if (str[i2] === ")") {
            depth--;
            if (depth === 0) return i2 + 1;
          }
        }
        return -1;
      }
      if (!isIdent(str[p])) return -1;
      let i = p;
      while (i < str.length && isIdent(str[i])) i++;
      if (str[i] === "(") {
        let depth = 0;
        for (let j = i; j < str.length; j++) {
          if (str[j] === "(") depth++;
          else if (str[j] === ")") {
            depth--;
            if (depth === 0) return j + 1;
          }
        }
        return -1;
      }
      return i;
    }
    let out = s;
    for (let guard = 0; guard < 64; guard++) {
      let wrapped = false;
      for (let i = 0; i < out.length; i++) {
        if (out[i] !== "-") continue;
        let prev = null;
        for (let j = i - 1; j >= 0; j--) {
          if (out[j] !== " ") {
            prev = out[j];
            break;
          }
        }
        if (prev !== null && !"(,+-*/^".includes(prev)) continue;
        let p = i + 1;
        while (out[p] === " ") p++;
        const baseEnd = readToken(out, p);
        if (baseEnd === -1) continue;
        let k = baseEnd;
        while (out[k] === " ") k++;
        if (out[k] !== "^") continue;
        let end = baseEnd;
        while (out[k] === "^") {
          let m = k + 1;
          while (out[m] === " ") m++;
          if (out[m] === "-") m++;
          const e = readToken(out, m);
          if (e === -1) break;
          end = e;
          k = end;
          while (out[k] === " ") k++;
        }
        if (end === baseEnd) continue;
        out = out.slice(0, i) + "-(" + out.slice(i + 1, end) + ")" + out.slice(end);
        wrapped = true;
        break;
      }
      if (!wrapped) return out;
    }
    return out;
  }
  function replaceConstants(s) {
    s = s.replace(/\bpi\b/gi, "Math.PI");
    s = s.replace(/π/g, "Math.PI");
    s = s.replace(/\be\b(?!\w)/g, (match, offset, str) => {
      const before = str.substring(Math.max(0, offset - 3), offset);
      if (/[a-zA-Z]$/.test(before)) return match;
      return "Math.E";
    });
    return s;
  }
  const FUNC_ALT = [...FUNCS].sort((a, b) => b.length - a.length).join("|");
  function insertImplicitMul(s) {
    s = s.replace(new RegExp(`(\\d|\\))\\s+(?=(?:${FUNC_ALT})\\s*\\()`, "g"), "$1*");
    s = s.replace(/(\d)([a-zA-Z])/g, "$1*$2");
    s = s.replace(/(\d)\(/g, "$1*(");
    s = s.replace(/\)([a-zA-Z])/g, ")*$1");
    s = s.replace(/\)\(/g, ")*(");
    s = s.replace(/([a-zA-Z])(\()/g, (_, letter, paren, offset, str) => {
      const preceding = str.substring(0, offset + 1);
      for (const fn2 of FUNCS) {
        if (preceding.endsWith(fn2)) return letter + paren;
      }
      if (/Math\.[A-Z]+$/i.test(preceding)) return letter + paren;
      return letter + "*" + paren;
    });
    return s;
  }
  function normalizeFuncs(s) {
    for (const fn2 of TRIG_FUNCS) {
      s = s.replace(new RegExp(`\\b${fn2}\\s*\\(`, "g"), `Math.${fn2}(`);
      s = s.replace(new RegExp(`\\b(?<!Math\\.)${fn2}\\s+([a-zA-Z0-9.*]+)`, "g"), `Math.${fn2}($1)`);
    }
    s = s.replace(/\bsqrt\s*\(/g, "Math.sqrt(");
    s = s.replace(/\bcbrt\s*\(/g, "Math.cbrt(");
    s = s.replace(/\babs\s*\(/g, "Math.abs(");
    s = s.replace(/\blog\s*\(/g, "Math.log10(");
    s = s.replace(/\bln\s*\(/g, "Math.log(");
    s = s.replace(/\bexp\s*\(/g, "Math.exp(");
    s = s.replace(/\bsqrt\s+([a-zA-Z0-9.*]+)/g, "Math.sqrt($1)");
    s = s.replace(/\bln\s+([a-zA-Z0-9.*]+)/g, "Math.log($1)");
    s = s.replace(/\babs\s+([a-zA-Z0-9.*]+)/g, "Math.abs($1)");
    return s;
  }
  function evalFrac(s) {
    if (s.includes("/")) {
      const [n, d] = s.split("/");
      return String(Number(n) / Number(d));
    }
    return s;
  }
  function buildLatex(funcBody, type) {
    let s = funcBody.trim();
    if (type === "point") return s;
    if (type === "x=") return `x = ${texify(s)}`;
    if (type === "implicit") return texify(s);
    return `y = ${texify(s)}`;
  }
  function texify(s) {
    let t = s;
    t = t.replace(/\|([^|]+)\|/g, "\\left|$1\\right|");
    t = t.replace(/(\d+)\s*\/\s*(\d+)/g, "\\frac{$1}{$2}");
    t = t.replace(/\^(\d+)/g, "^{$1}");
    t = t.replace(/\^\(([^)]+)\)/g, "^{$1}");
    t = t.replace(/sqrt\(([^)]+)\)/g, "\\sqrt{$1}");
    for (const fn2 of TRIG_FUNCS) t = t.replace(new RegExp(`\\b${fn2}\\b`, "g"), `\\${fn2}`);
    t = t.replace(/\bln\b/g, "\\ln");
    t = t.replace(/\blog\b/g, "\\log");
    t = t.replace(/\bpi\b/gi, "\\pi");
    t = t.replace(/π/g, "\\pi");
    t = t.replace(/\*/g, " \\cdot ");
    return t;
  }
  function defaultTokenResolver(board) {
    if (typeof getComputedStyle === "undefined" || !board?.containerObj) return () => void 0;
    const cs = getComputedStyle(board.containerObj);
    return (name) => cs.getPropertyValue(name);
  }
  function resolveCanvasColor(board, resolveToken, fallback) {
    return resolveToken("--gl-canvas")?.trim() || board.containerObj?.style?.backgroundColor || fallback;
  }
  function plotExpression(board, expr, resolveToken) {
    if (!board || !expr || !expr.jsExpr || !expr.type || !expr.visible) return null;
    const resolve = resolveToken || defaultTokenResolver(board);
    const styleAttrs = getStyleAttrs(expr);
    try {
      switch (expr.type) {
        case ExpressionType.Y_EQUALS:
          return plotYEquals(board, expr, styleAttrs, resolve);
        case ExpressionType.X_EQUALS:
          return plotXEquals(board, expr, styleAttrs);
        case ExpressionType.IMPLICIT:
          return plotImplicit(board, expr, styleAttrs);
        case ExpressionType.POINT:
          return plotPoint(board, expr, styleAttrs, resolve);
        default:
          return null;
      }
    } catch (err) {
      console.warn(`[GraphLab] Plot error for "${expr.raw}":`, err.message);
      return null;
    }
  }
  function removePlot(board, elements) {
    if (!board || !elements) return;
    const arr = Array.isArray(elements) ? elements : [elements];
    arr.forEach((el) => {
      try {
        if (el && board.objectsList?.includes(el)) board.removeObject(el);
      } catch {
      }
    });
  }
  function plotYEquals(board, expr, attrs, resolve) {
    const fn2 = buildFunction(expr.jsExpr);
    if (!fn2) return null;
    const [domMin, domMax] = expr.domain;
    const hasDomain = isFinite(domMin) || isFinite(domMax);
    const wrappedFn = hasDomain ? (x) => {
      if (isFinite(domMin) && x < domMin) return NaN;
      if (isFinite(domMax) && x > domMax) return NaN;
      if (x === domMin && !expr.domainInclusive[0]) return NaN;
      if (x === domMax && !expr.domainInclusive[1]) return NaN;
      return fn2(x);
    } : fn2;
    const element = board.create("functiongraph", [wrappedFn], {
      ...attrs,
      doAdvancedPlot: true,
      doAdvancedPlotOld: false,
      recursionDepthHigh: 9
    });
    const elements = [element];
    if (hasDomain) {
      if (isFinite(domMin)) {
        const y = fn2(domMin);
        if (isFinite(y)) elements.push(createEndpointDot(board, domMin, y, expr.domainInclusive[0], expr.color, resolve));
      }
      if (isFinite(domMax)) {
        const y = fn2(domMax);
        if (isFinite(y)) elements.push(createEndpointDot(board, domMax, y, expr.domainInclusive[1], expr.color, resolve));
      }
    }
    return elements;
  }
  function plotXEquals(board, expr, attrs) {
    const val = parseFloat(expr.jsExpr);
    if (isNaN(val)) return null;
    return [board.create("line", [[val, 0], [val, 1]], { ...attrs, straightFirst: true, straightLast: true, fixed: true })];
  }
  function plotImplicit(board, expr, attrs) {
    const implicitFn = buildImplicitFunction(expr.raw);
    if (!implicitFn) return null;
    return [board.create("implicitcurve", [implicitFn], { ...attrs, resolution_outer: 30, resolution_inner: 30 })];
  }
  function plotPoint(board, expr, attrs, resolve) {
    const coords = parsePointCoords(expr.jsExpr);
    if (!coords) return null;
    if (expr.pointStyle === POINT_STYLE.OPEN) {
      const canvas = resolveCanvasColor(board, resolve, "#0d0d1a");
      return [board.create("point", coords, {
        ...attrs,
        size: 3.6,
        name: "",
        fixed: true,
        showInfobox: false,
        fillColor: canvas,
        fillOpacity: 1,
        strokeColor: expr.color,
        strokeWidth: 2.2
      })];
    }
    return [board.create("point", coords, {
      ...attrs,
      size: 4,
      name: "",
      fixed: true,
      showInfobox: false,
      fillColor: expr.color,
      fillOpacity: 1,
      strokeColor: expr.color
    })];
  }
  function buildFunction(jsExpr) {
    try {
      const fn2 = new Function("x", `"use strict"; return (${jsExpr});`);
      const test = fn2(1);
      if (typeof test !== "number") return null;
      return fn2;
    } catch {
      return null;
    }
  }
  function buildImplicitFunction(raw) {
    try {
      let lhs, rhs;
      if (raw.includes("=")) {
        const eqIdx = raw.indexOf("=");
        lhs = raw.substring(0, eqIdx).trim();
        rhs = raw.substring(eqIdx + 1).trim();
      } else {
        lhs = raw;
        rhs = "0";
      }
      const combined = `(${processImplicitExpr(lhs)}) - (${processImplicitExpr(rhs)})`;
      const fn2 = new Function("x", "y", `"use strict"; return (${combined});`);
      fn2(0, 0);
      return fn2;
    } catch {
      return null;
    }
  }
  function processImplicitExpr(s) {
    let t = s;
    t = t.replace(/\bpi\b/gi, "Math.PI");
    t = t.replace(/π/g, "Math.PI");
    t = t.replace(/(\d)([a-zA-Z])/g, "$1*$2");
    t = t.replace(/(\d)\(/g, "$1*(");
    t = t.replace(/\)([a-zA-Z])/g, ")*$1");
    t = t.replace(/\)\(/g, ")*(");
    t = t.replace(/\bsin\s*\(/g, "Math.sin(");
    t = t.replace(/\bcos\s*\(/g, "Math.cos(");
    t = t.replace(/\btan\s*\(/g, "Math.tan(");
    t = t.replace(/\bsqrt\s*\(/g, "Math.sqrt(");
    t = t.replace(/\babs\s*\(/g, "Math.abs(");
    t = t.replace(/\blog\s*\(/g, "Math.log10(");
    t = t.replace(/\bln\s*\(/g, "Math.log(");
    t = t.replace(/\bexp\s*\(/g, "Math.exp(");
    t = wrapUnaryMinusPowers(t);
    t = t.replace(/\^/g, "**");
    return t;
  }
  function parsePointCoords(jsExpr) {
    try {
      const fn2 = new Function(`"use strict"; return (${jsExpr});`);
      const result = fn2();
      if (Array.isArray(result) && result.length === 2) return result;
      return null;
    } catch {
      return null;
    }
  }
  function createEndpointDot(board, x, y, inclusive, color, resolve) {
    const bgColor = resolveCanvasColor(board, resolve, "#ffffff");
    return board.create("point", [x, y], {
      size: inclusive ? 3 : 3.5,
      name: "",
      fixed: true,
      showInfobox: false,
      strokeColor: color,
      strokeWidth: inclusive ? 0 : 2.5,
      fillColor: inclusive ? color : bgColor
    });
  }
  function getStyleAttrs(expr) {
    const sc = LINE_STYLE_CONFIG[expr.lineStyle] || LINE_STYLE_CONFIG.medium;
    const attrs = { strokeColor: expr.color, strokeWidth: sc.width, highlight: false, fixed: true, dash: 0 };
    switch (expr.lineStyle) {
      case "dashed":
        attrs.dash = 2;
        break;
      case "dotted":
        attrs.dash = 3;
        break;
      default:
        attrs.dash = 0;
        break;
    }
    return attrs;
  }
  function braceGroup(s, open) {
    let depth = 0;
    for (let i = open; i < s.length; i++) {
      if (s[i] === "{") depth++;
      else if (s[i] === "}") {
        depth--;
        if (depth === 0) return [s.slice(open + 1, i), i];
      }
    }
    return null;
  }
  function replaceCommand(s, name, argc, cb) {
    const tag = "\\" + name;
    for (let guard = 0; guard < 64; guard++) {
      const at = s.indexOf(tag);
      if (at === -1) return s;
      let i = at + tag.length;
      let opt = null;
      while (s[i] === " ") i++;
      if (s[i] === "[") {
        const close = s.indexOf("]", i);
        opt = s.slice(i + 1, close);
        i = close + 1;
      }
      const args = [];
      for (let a = 0; a < argc; a++) {
        while (s[i] === " ") i++;
        if (s[i] !== "{") return s;
        const g = braceGroup(s, i);
        if (!g) return s;
        args.push(g[0]);
        i = g[1] + 1;
      }
      s = s.slice(0, at) + cb(args, opt) + s.slice(i);
    }
    return s;
  }
  function translateCaretBraces(s) {
    for (let guard = 0; guard < 64; guard++) {
      const m = /\^\s*{/.exec(s);
      if (!m) return s;
      const open = m.index + m[0].length - 1;
      const g = braceGroup(s, open);
      if (!g) return s;
      s = s.slice(0, m.index) + "^(" + g[0] + ")" + s.slice(g[1] + 1);
    }
    return s;
  }
  function latexToRule(latex) {
    let s = String(latex);
    s = s.replace(/\\\\/g, "\\");
    s = replaceCommand(s, "frac", 2, ([a, b]) => `(${latexToRule(a)})/(${latexToRule(b)})`);
    s = replaceCommand(s, "sqrt", 1, ([a], opt) => opt ? `(${latexToRule(a)})^(1/${opt})` : `sqrt(${latexToRule(a)})`);
    s = replaceCommand(s, "operatorname", 1, ([a]) => a);
    s = translateCaretBraces(s);
    s = s.replace(/\\left|\\right/g, "");
    s = s.replace(/\\cdot/g, "*");
    s = s.replace(/\\pi/g, "pi");
    s = s.replace(/\\(arcsin|arccos|arctan|sinh|cosh|tanh|sin|cos|tan|sec|csc|cot|ln|log|exp)\b\s*/g, "$1");
    s = s.replace(/(\d)\s*(e\^)/g, "$1*$2");
    s = s.replace(/\bpi\s+(?=[A-Za-z0-9(])/g, "pi*");
    s = s.replace(/([\d)])\s+([A-Za-z(\d])/g, (m, a, b) => /\d/.test(b) && /\d/.test(a) ? m : `${a}*${b}`);
    s = s.replace(/-\s*([A-Za-z0-9.]+)\s*\^\s*(\([^()]*\)|[0-9.]+)/g, "-($1^$2)");
    s = s.replace(/\s+/g, " ").trim();
    return s;
  }
  const RE_EMPTY = /^\s*$/;
  const RE_XLINE = /^\s*x\s*=\s*(-?\d+(?:\.\d+)?(?:\/\d+(?:\.\d+)?)?)\s*$/;
  const RE_YLINE = /^\s*y\s*=\s*(-?\d+(?:\.\d+)?(?:\/\d+(?:\.\d+)?)?)\s*$/;
  const RE_FN_PREFIX = /^\s*([A-Za-z])\s*\(\s*x\s*\)\s*=\s*/;
  const RE_Y_PREFIX = /^\s*y\s*=\s*/;
  const RE_NUM = /^-?\d+(\.\d+)?$/;
  const RE_FRAC = /^(-?\d+(?:\.\d+)?)\/(-?\d+(?:\.\d+)?)$/;
  function splitTopLevelComma(s) {
    let depth = 0;
    for (let i = 0; i < s.length; i++) {
      const c = s[i];
      if (c === "(" || c === "{" || c === "[") depth++;
      else if (c === ")" || c === "}" || c === "]") depth--;
      else if (c === "," && depth === 0) return [s.slice(0, i), s.slice(i + 1)];
    }
    return null;
  }
  function evalCoord(src, env) {
    const trimmed = src.trim();
    if (RE_NUM.test(trimmed)) return Number(trimmed);
    const fr = RE_FRAC.exec(trimmed);
    if (fr) return Number(fr[1]) / Number(fr[2]);
    const js = latexToRule(trimmed).replace(/\^/g, "**").replace(/\bpi\b/g, "Math.PI").replace(/\bsqrt\(/g, "Math.sqrt(").replace(/\bln\(/g, "Math.log(");
    const names = Object.keys(env);
    const fn2 = new Function(...names, `"use strict"; return (${js});`);
    const v = fn2(...names.map((n) => env[n]));
    if (typeof v !== "number" || !isFinite(v)) throw new Error(`coordinate did not evaluate finite: "${src}"`);
    return v;
  }
  function classifyLatex(latex, env = {}) {
    const s = String(latex);
    if (RE_EMPTY.test(s)) return { kind: "empty" };
    const xl = RE_XLINE.exec(s);
    if (xl) return { kind: "line", eqn: `x=${normNum(xl[1])}` };
    const yl = RE_YLINE.exec(s);
    if (yl) return { kind: "line", eqn: `y=${normNum(yl[1])}` };
    const t = s.trim();
    if (t.startsWith("(") && t.endsWith(")")) {
      const inner = t.slice(1, -1);
      const parts = splitTopLevelComma(inner);
      if (parts) {
        return { kind: "point", at: [evalCoord(parts[0], env), evalCoord(parts[1], env)] };
      }
    }
    let body = s, name = null;
    const fp = RE_FN_PREFIX.exec(body);
    if (fp && fp[1] !== "x" && fp[1] !== "y") {
      name = fp[1];
      body = body.slice(fp[0].length);
    } else if (RE_Y_PREFIX.test(body)) {
      body = body.replace(RE_Y_PREFIX, "");
    }
    const rule = latexToRule(body);
    if (name) {
      const parsed = parseExpression(rule);
      if (!parsed.error && parsed.jsExpr) {
        env[name] = new Function("x", `"use strict"; return (${parsed.jsExpr});`);
      }
    }
    return { kind: "function", rule, name };
  }
  function normNum(v) {
    const fr = RE_FRAC.exec(v);
    if (fr) return String(Number(fr[1]) / Number(fr[2]));
    return String(Number(v));
  }
  const STATE_VERSION = 1;
  const GRAPH_DEFAULTS = {
    showGrid: true,
    showXAxis: true,
    showYAxis: true,
    xAxisStep: 0,
    yAxisStep: 0,
    xAxisLabel: "",
    yAxisLabel: "",
    squareAxes: false,
    projectorMode: false
  };
  const OPTIONS_DEFAULTS = {
    explore: false,
    trace: true,
    zoom: true,
    lockViewport: false,
    poi: false,
    // Spec erratum (raised in the Phase 1 report): the IMathAS macro emits no
    // shell, so the mount owns the +/− overlay; zoomButtons belongs in options.
    zoomButtons: true
  };
  const LINE_STYLES = ["solid", "dashed", "dotted"];
  const POINT_STYLES = ["filled", "open"];
  const ELEMENT_KINDS = ["function", "line", "point", "text"];
  const RE_X_CONST = /^x\s*=\s*-?\d+(?:\.\d+)?$/;
  const RE_Y_CONST = /^y\s*=\s*-?\d+(?:\.\d+)?$/;
  const RE_Y_LINEAR = /^y\s*=\s*-?(?:\d+(?:\.\d+)?)?\s*x\s*(?:[+-]\s*\d+(?:\.\d+)?)?$/;
  function isValidLineEqn(eqn) {
    if (typeof eqn !== "string") return false;
    const s = eqn.trim();
    return RE_X_CONST.test(s) || RE_Y_CONST.test(s) || RE_Y_LINEAR.test(s);
  }
  function coerceBool(v, fallback = false) {
    if (v === "true") return true;
    if (v === "false") return false;
    return typeof v === "boolean" ? v : fallback;
  }
  function isFiniteNum(v) {
    return typeof v === "number" && isFinite(v);
  }
  function validateState(obj) {
    const errors = [];
    if (!obj || typeof obj !== "object" || Array.isArray(obj)) {
      return { ok: false, errors: ["state must be an object"] };
    }
    if (obj.version !== STATE_VERSION) {
      return { ok: false, errors: [`unknown state version: ${obj.version} (expected ${STATE_VERSION})`] };
    }
    const vp = obj.graph?.viewport;
    if (!vp || !isFiniteNum(vp.xmin) || !isFiniteNum(vp.xmax) || !isFiniteNum(vp.ymin) || !isFiniteNum(vp.ymax)) {
      errors.push("graph.viewport with finite xmin/xmax/ymin/ymax is required");
    } else if (vp.xmin >= vp.xmax || vp.ymin >= vp.ymax) {
      errors.push("graph.viewport must have xmin < xmax and ymin < ymax");
    }
    const elements = obj.elements ?? [];
    if (!Array.isArray(elements)) {
      errors.push("elements must be an array");
    } else {
      elements.forEach((el, i) => {
        const at = `elements[${i}]`;
        if (!el || typeof el !== "object") {
          errors.push(`${at}: must be an object`);
          return;
        }
        if (!ELEMENT_KINDS.includes(el.kind)) {
          errors.push(`${at}: unknown kind "${el.kind}"`);
          return;
        }
        if (el.kind === "function" && (typeof el.rule !== "string" || !el.rule.trim())) errors.push(`${at}: function requires a rule string`);
        if (el.kind === "line" && !isValidLineEqn(el.eqn)) errors.push(`${at}: line.eqn must match x=<num> | y=<num> | y=<m>x+<b> (got ${JSON.stringify(el.eqn)})`);
        if ((el.kind === "point" || el.kind === "text") && !(Array.isArray(el.at) && el.at.length === 2 && isFiniteNum(el.at[0]) && isFiniteNum(el.at[1]))) errors.push(`${at}: ${el.kind} requires at=[x,y] numbers`);
        if (el.kind === "text" && typeof el.text !== "string") errors.push(`${at}: text requires a text string`);
        if (el.lineStyle != null && !LINE_STYLES.includes(el.lineStyle)) errors.push(`${at}: lineStyle must be one of ${LINE_STYLES.join("/")}`);
        if (el.pointStyle != null && !POINT_STYLES.includes(el.pointStyle)) errors.push(`${at}: pointStyle must be one of ${POINT_STYLES.join("/")}`);
        if (el.kind === "function" && el.domain != null && !(Array.isArray(el.domain) && el.domain.length === 2 && isFiniteNum(el.domain[0]) && isFiniteNum(el.domain[1]))) errors.push(`${at}: domain must be [min,max] numbers or null`);
      });
    }
    return { ok: errors.length === 0, errors };
  }
  function toEngineLineStyle(s) {
    return s === "dashed" || s === "dotted" ? s : "medium";
  }
  function toSchemaLineStyle(s) {
    return s === "dashed" || s === "dotted" ? s : "solid";
  }
  function hydrateState(state) {
    const { ok, errors } = validateState(state);
    if (!ok) throw new Error(`KalcGraph state invalid: ${errors.join("; ")}`);
    const graph = { viewport: { ...state.graph.viewport } };
    for (const k of Object.keys(GRAPH_DEFAULTS)) graph[k] = state.graph[k] ?? GRAPH_DEFAULTS[k];
    graph.showGrid = coerceBool(graph.showGrid, true);
    graph.showXAxis = coerceBool(graph.showXAxis, true);
    graph.showYAxis = coerceBool(graph.showYAxis, true);
    graph.squareAxes = coerceBool(graph.squareAxes, false);
    graph.projectorMode = coerceBool(graph.projectorMode, false);
    const options = {};
    for (const k of Object.keys(OPTIONS_DEFAULTS)) options[k] = coerceBool((state.options ?? {})[k], OPTIONS_DEFAULTS[k]);
    const expressions = (state.elements ?? []).map((el, i) => {
      const id = el.id != null ? String(el.id) : String(i);
      const color = el.color || COLORS_DARK[i % COLORS_DARK.length];
      const hidden = coerceBool(el.hidden, false);
      if (el.kind === "text") {
        return {
          id,
          kind: "text",
          at: [...el.at],
          text: el.text,
          raw: "",
          type: null,
          normalized: "",
          latex: "",
          jsExpr: "",
          domain: [-Infinity, Infinity],
          domainInclusive: [true, true],
          color,
          lineStyle: "medium",
          visible: true,
          locked: true,
          hidden,
          groupId: null,
          error: null
        };
      }
      let raw, kindFields;
      if (el.kind === "function") {
        raw = el.format === "latex" ? latexToRule(el.rule) : el.rule;
        kindFields = { kind: "function", rule: el.rule, domain: el.domain ? [...el.domain] : null };
      } else if (el.kind === "line") {
        raw = el.eqn;
        kindFields = { kind: "line", eqn: el.eqn };
      } else {
        raw = `(${el.at[0]}, ${el.at[1]})`;
        kindFields = { kind: "point", at: [...el.at], pointStyle: el.pointStyle || "filled", label: el.label || "" };
      }
      const parsed = parseExpression(raw);
      if (parsed.error && el.optional) return null;
      if (parsed.error) throw new Error(`KalcGraph element ${id} ("${raw}"): ${parsed.error}`);
      const domain = el.kind === "function" && el.domain ? [el.domain[0], el.domain[1]] : parsed.domain;
      return {
        id,
        ...kindFields,
        raw,
        type: parsed.type,
        normalized: parsed.normalized,
        latex: parsed.latex,
        jsExpr: parsed.jsExpr,
        domain,
        domainInclusive: parsed.domainInclusive,
        color,
        lineStyle: toEngineLineStyle(el.lineStyle),
        visible: true,
        locked: true,
        hidden,
        groupId: null,
        error: null,
        ...el.kind === "point" ? { pointStyle: el.pointStyle || "filled" } : {}
      };
    }).filter(Boolean);
    return { graph, options, expressions };
  }
  function serializeState(runtime) {
    const graph = { viewport: { ...runtime.graph.viewport } };
    for (const k of Object.keys(GRAPH_DEFAULTS)) graph[k] = runtime.graph[k] ?? GRAPH_DEFAULTS[k];
    const options = {};
    for (const k of Object.keys(OPTIONS_DEFAULTS)) options[k] = runtime.options?.[k] ?? OPTIONS_DEFAULTS[k];
    const elements = (runtime.expressions ?? []).map((e, i) => {
      const id = e.id != null ? String(e.id) : String(i);
      const kind = e.kind || (e.type === "point" ? "point" : e.type === "x=" ? "line" : "function");
      if (kind === "text") return { id, kind, at: [...e.at], text: e.text };
      if (kind === "point") {
        const at = e.at || parsePointFromJs(e.jsExpr);
        return { id, kind, at: [...at], pointStyle: e.pointStyle || "filled", label: e.label || "", color: e.color, hidden: !!e.hidden };
      }
      if (kind === "line") {
        return { id, kind, eqn: e.eqn || e.raw, lineStyle: toSchemaLineStyle(e.lineStyle), color: e.color, hidden: !!e.hidden };
      }
      return {
        id,
        kind: "function",
        rule: e.rule ?? e.raw,
        color: e.color,
        lineStyle: toSchemaLineStyle(e.lineStyle),
        hidden: !!e.hidden,
        domain: e.domain && isFinite(e.domain[0]) && isFinite(e.domain[1]) ? [e.domain[0], e.domain[1]] : null
      };
    });
    return { version: STATE_VERSION, graph, options, elements };
  }
  function parsePointFromJs(jsExpr) {
    try {
      const m = String(jsExpr).match(/^\[\s*(-?[\d.]+)\s*,\s*(-?[\d.]+)\s*\]$/);
      if (m) return [Number(m[1]), Number(m[2])];
    } catch {
    }
    return [0, 0];
  }
  function serializeMainPayload(expressions) {
    return JSON.stringify(
      (expressions ?? []).filter((e) => !e.locked).map((e) => ({ id: e.id, raw: e.raw, type: e.type, color: e.color }))
    );
  }
  function hydrateMainPayload(mainStr) {
    if (typeof mainStr !== "string" || !mainStr) return null;
    try {
      const arr = JSON.parse(mainStr);
      return Array.isArray(arr) ? arr : null;
    } catch {
      return null;
    }
  }
  function resolveJXG(hooks) {
    const JXG = hooks?.JXG ?? (typeof globalThis !== "undefined" ? globalThis.JXG : void 0);
    if (!JXG?.JSXGraph) {
      throw new Error("KalcGraph.mount: JSXGraph not found — load jsxgraphcore.js before the mount call, or pass hooks.JXG");
    }
    return JXG;
  }
  function niceStep(range, targetLines = 12) {
    const rough = Math.abs(range) / targetLines;
    if (!isFinite(rough) || rough <= 0) return 1;
    const pow = Math.pow(10, Math.floor(Math.log10(rough)));
    for (const m of [1, 2, 5, 10]) {
      if (rough <= m * pow) return m * pow;
    }
    return 10 * pow;
  }
  function fmtCoord(v) {
    const r = Math.round(v * 100) / 100;
    return Object.is(r, -0) ? "0" : String(r);
  }
  function mount(containerId, stateV1, hooks = {}) {
    if (typeof containerId !== "string" || !containerId) {
      throw new Error("KalcGraph.mount: containerId (string) is required");
    }
    if (typeof document === "undefined") throw new Error("KalcGraph.mount requires a DOM");
    const container = document.getElementById(containerId);
    if (!container) throw new Error(`KalcGraph.mount: no element with id "${containerId}"`);
    const JXG = resolveJXG(hooks);
    const runtime = hydrateState(stateV1);
    const { graph, options } = runtime;
    container.classList.add("kalcgraph");
    const boardDiv = document.createElement("div");
    boardDiv.style.cssText = "width:100%;height:100%;";
    container.appendChild(boardDiv);
    const resolve = defaultTokenResolver({ containerObj: container });
    const token = (name, fallback) => (resolve(name) || "").trim() || fallback;
    const projector = graph.projectorMode;
    const vp = graph.viewport;
    const board = JXG.JSXGraph.initBoard(boardDiv, {
      boundingbox: [vp.xmin, vp.ymax, vp.xmax, vp.ymin],
      axis: false,
      grid: false,
      showNavigation: false,
      showCopyright: false,
      // With trace on, one finger belongs to the trace — panning needs two
      // fingers on touch (pinch-zoom is two-finger anyway). Mouse pan unchanged.
      pan: { enabled: !options.lockViewport, needShift: false, needTwoFingers: options.trace },
      zoom: { enabled: !options.lockViewport && options.zoom, wheel: !options.lockViewport && options.zoom, needShift: false, factorX: 1.15, factorY: 1.15 },
      keepaspectratio: graph.squareAxes,
      registerEvents: true
    });
    const xStep = graph.xAxisStep > 0 ? graph.xAxisStep : niceStep(vp.xmax - vp.xmin);
    const yStep = graph.yAxisStep > 0 ? graph.yAxisStep : niceStep(vp.ymax - vp.ymin);
    const chrome = { grids: [], axes: [], labels: [] };
    if (graph.showGrid) {
      const gMajor = board.create("grid", [], { majorStep: [xStep, yStep], strokeColor: token("--gl-grid", "rgba(255,255,255,0.08)"), strokeWidth: 1 });
      chrome.grids.push({ el: gMajor, token: "--gl-grid", fb: "rgba(255,255,255,0.08)" });
      if (xStep >= 0.5 && yStep >= 0.5) {
        const gMinor = board.create("grid", [], { majorStep: [xStep / 2, yStep / 2], strokeColor: token("--gl-grid-minor", "rgba(255,255,255,0.035)"), strokeWidth: 0.5 });
        chrome.grids.push({ el: gMinor, token: "--gl-grid-minor", fb: "rgba(255,255,255,0.035)" });
      }
    }
    const axisColor = token("--gl-axis", "rgba(255,255,255,0.45)");
    const tickColor = token("--gl-tick-label", "#e2e2f0");
    const fontSize = projector ? 16 : 12;
    const axisWidth = projector ? 3 : 2;
    const tickAttrs = (distance, labelAttrs) => ({
      drawZero: false,
      ticksDistance: distance,
      strokeColor: axisColor,
      strokeWidth: projector ? 1.5 : 1,
      majorHeight: 10,
      minorHeight: 0,
      label: { visible: true, fontSize, fontFamily: "'DM Sans',sans-serif", color: tickColor, fontWeight: 500, ...labelAttrs }
    });
    if (graph.showXAxis) {
      const xAx = board.create("axis", [[0, 0], [1, 0]], {
        strokeColor: axisColor,
        strokeWidth: axisWidth,
        highlight: false,
        lastArrow: { type: 1, size: 6 },
        ticks: tickAttrs(xStep, { anchorX: "middle", anchorY: "top", offset: [0, -14] })
      });
      chrome.axes.push(xAx);
      const xLbl = board.create(
        "text",
        [vp.xmax - 0.02 * (vp.xmax - vp.xmin), 0.03 * (vp.ymax - vp.ymin), graph.xAxisLabel || "x"],
        { fontSize: fontSize + 2, fontFamily: "'DM Sans',sans-serif", fontWeight: 700, fontStyle: "italic", color: axisColor, fixed: true, highlight: false, anchorX: "right" }
      );
      chrome.labels.push(xLbl);
    }
    if (graph.showYAxis) {
      const yAx = board.create("axis", [[0, 0], [0, 1]], {
        strokeColor: axisColor,
        strokeWidth: axisWidth,
        highlight: false,
        lastArrow: { type: 1, size: 6 },
        ticks: tickAttrs(yStep, { anchorX: "right", anchorY: "middle", offset: [-10, 0] })
      });
      chrome.axes.push(yAx);
      const yLbl = board.create(
        "text",
        [0.02 * (vp.xmax - vp.xmin), vp.ymax - 0.03 * (vp.ymax - vp.ymin), graph.yAxisLabel || "y"],
        { fontSize: fontSize + 2, fontFamily: "'DM Sans',sans-serif", fontWeight: 700, fontStyle: "italic", color: axisColor, fixed: true, highlight: false, anchorX: "left" }
      );
      chrome.labels.push(yLbl);
    }
    function retheme() {
      const axC = token("--gl-axis", "rgba(255,255,255,0.45)");
      const tkC = token("--gl-tick-label", "#e2e2f0");
      for (const g of chrome.grids) {
        try {
          g.el.setAttribute({ strokeColor: token(g.token, g.fb) });
        } catch {
        }
      }
      for (const ax of chrome.axes) {
        try {
          ax.setAttribute({ strokeColor: axC });
          const t = ax.defaultTicks;
          if (t) {
            t.setAttribute({ strokeColor: axC, label: { color: tkC } });
            (t.labels || []).forEach((lb) => {
              if (lb) {
                try {
                  lb.setAttribute({ color: tkC });
                } catch {
                }
              }
            });
          }
        } catch {
        }
      }
      for (const l of chrome.labels) {
        try {
          l.setAttribute({ color: axC });
        } catch {
        }
      }
      board.update();
    }
    const onHostTheme = () => {
      try {
        retheme();
      } catch {
      }
    };
    if (typeof window !== "undefined") window.addEventListener("kovalna:theme", onHostTheme);
    const plots = /* @__PURE__ */ new Map();
    function plotOne(expr) {
      if (expr.hidden) return;
      if (expr.kind === "text") {
        const el = board.create(
          "text",
          [expr.at[0], expr.at[1], expr.text],
          { fontSize: projector ? 16 : 13, fontFamily: "'DM Sans',sans-serif", color: token("--gl-text", "#e8e6e0"), fixed: true, highlight: false }
        );
        plots.set(expr.id, [el]);
        return;
      }
      const els = plotExpression(board, expr, resolve);
      if (els) plots.set(expr.id, els);
    }
    runtime.expressions.forEach(plotOne);
    board.update();
    const fnCache = /* @__PURE__ */ new Map();
    const listeners = [];
    let traceDot = null, traceChip = null;
    function exprFn(expr) {
      if (!fnCache.has(expr.id)) {
        try {
          fnCache.set(expr.id, new Function("x", `"use strict"; return (${expr.jsExpr});`));
        } catch {
          fnCache.set(expr.id, null);
        }
      }
      return fnCache.get(expr.id);
    }
    function hideTrace() {
      if (traceDot) traceDot.style.display = "none";
      if (traceChip) traceChip.style.display = "none";
    }
    let lockId = null;
    let lastT = null;
    const ACQUIRE = 0.06, RELEASE = 0.2;
    function evalOnCurve(expr, wx) {
      const fn2 = exprFn(expr);
      if (!fn2) return null;
      if (isFinite(expr.domain?.[0]) && wx < expr.domain[0]) return null;
      if (isFinite(expr.domain?.[1]) && wx > expr.domain[1]) return null;
      let y;
      try {
        y = fn2(wx);
      } catch {
        return null;
      }
      return isFinite(y) ? y : null;
    }
    function nearestOnCurve(expr, wx, wy, t0, t1, xR, yR) {
      let best = null;
      const scan = (a, b, n) => {
        for (let i = 0; i <= n; i++) {
          const t = a + (b - a) * i / n;
          const y = evalOnCurve(expr, t);
          if (y == null) continue;
          const dx = (t - wx) / xR, dy = (y - wy) / yR;
          const d = Math.sqrt(dx * dx + dy * dy);
          if (!best || d < best.d) best = { t, y, d };
        }
      };
      scan(t0, t1, 140);
      if (!best) return null;
      let w = (t1 - t0) / 140;
      scan(best.t - w, best.t + w, 24);
      w /= 12;
      scan(best.t - w, best.t + w, 24);
      return best;
    }
    function showTrace(cx, cy, isTouch = false) {
      const coords = new JXG.Coords(JXG.COORDS_BY_SCREEN, [cx, cy], board);
      const [bx1, by2, bx2, by1] = board.getBoundingBox();
      const xMin = Math.min(bx1, bx2), xMax = Math.max(bx1, bx2);
      const xR = xMax - xMin || 1, yR = Math.abs(by2 - by1) || 1;
      const wx = Math.min(Math.max(coords.usrCoords[1], xMin), xMax);
      const wy = coords.usrCoords[2];
      let expr = lockId != null ? runtime.expressions.find((e) => e.id === lockId && !e.hidden) || null : null;
      let best = null;
      if (expr) {
        const anchor = lastT ?? wx;
        const pad = xR * 0.08;
        const t0 = Math.max(Math.min(anchor, wx) - pad, xMin);
        const t1 = Math.min(Math.max(anchor, wx) + pad, xMax);
        best = nearestOnCurve(expr, wx, wy, t0, t1, xR, yR);
        if (isTouch) {
          if (!best) {
            hideTrace();
            return;
          }
        } else if (!best || best.d > RELEASE) {
          expr = null;
          lockId = null;
          lastT = null;
          best = null;
        }
      }
      if (!expr) {
        for (const cand of runtime.expressions) {
          if (cand.hidden || cand.type !== "y=" || !cand.jsExpr) continue;
          const b = nearestOnCurve(cand, wx, wy, xMin, xMax, xR, yR);
          if (b && b.d < ACQUIRE && (!best || b.d < best.d)) {
            best = b;
            expr = cand;
          }
        }
        if (expr) lockId = expr.id;
      }
      if (!expr || !best) {
        hideTrace();
        return;
      }
      lastT = best.t;
      const tx = best.t, y = best.y;
      if (!traceDot) {
        traceDot = document.createElement("div");
        traceDot.className = "kg-trace-dot";
        container.appendChild(traceDot);
        traceChip = document.createElement("div");
        traceChip.className = "kg-trace-chip";
        container.appendChild(traceChip);
      }
      const pc = new JXG.Coords(JXG.COORDS_BY_USER, [tx, y], board);
      const px = pc.scrCoords[1], py = pc.scrCoords[2];
      traceDot.style.display = "block";
      traceDot.style.left = `${px}px`;
      traceDot.style.top = `${py}px`;
      traceChip.style.display = "block";
      traceChip.style.left = `${px}px`;
      traceChip.style.top = `${py}px`;
      traceChip.style.transform = isTouch ? "translate(-50%, -170%)" : "";
      traceChip.textContent = `(${fmtCoord(tx)}, ${fmtCoord(y)})`;
    }
    if (options.trace) {
      const onMove = (e) => {
        const rect = boardDiv.getBoundingClientRect();
        showTrace(e.clientX - rect.left, e.clientY - rect.top);
      };
      const onTouch = (e) => {
        if (!e.touches || e.touches.length !== 1) {
          lockId = null;
          lastT = null;
          hideTrace();
          return;
        }
        if (e.cancelable) e.preventDefault();
        const rect = boardDiv.getBoundingClientRect();
        showTrace(e.touches[0].clientX - rect.left, e.touches[0].clientY - rect.top, true);
      };
      const onTouchEnd = () => {
        lockId = null;
        lastT = null;
      };
      const onLeave = () => {
        lockId = null;
        lastT = null;
        hideTrace();
      };
      boardDiv.addEventListener("mousemove", onMove);
      boardDiv.addEventListener("touchstart", onTouch, { passive: false });
      boardDiv.addEventListener("touchmove", onTouch, { passive: false });
      boardDiv.addEventListener("touchend", onTouchEnd);
      boardDiv.addEventListener("touchcancel", onTouchEnd);
      boardDiv.addEventListener("mouseleave", onLeave);
      listeners.push(["mousemove", onMove], ["touchstart", onTouch], ["touchmove", onTouch], ["touchend", onTouchEnd], ["touchcancel", onTouchEnd], ["mouseleave", onLeave]);
    }
    function applyZoom(factor) {
      if (options.lockViewport) return;
      if (factor < 1) board.zoomIn();
      else board.zoomOut();
    }
    let zoomWrap = null;
    if (options.zoomButtons && !options.lockViewport) {
      zoomWrap = document.createElement("div");
      zoomWrap.className = "kg-zoom";
      const mkBtn = (label, fn2) => {
        const b = document.createElement("button");
        b.type = "button";
        b.textContent = label;
        b.setAttribute("aria-label", label === "+" ? "Zoom in" : "Zoom out");
        b.addEventListener("click", fn2);
        zoomWrap.appendChild(b);
      };
      mkBtn("+", () => applyZoom(0.87));
      mkBtn("−", () => applyZoom(1.15));
      container.appendChild(zoomWrap);
    }
    function syncViewport() {
      const [x1, y2, x2, y1] = board.getBoundingBox();
      runtime.graph.viewport = { xmin: x1, xmax: x2, ymin: y1, ymax: y2 };
    }
    board.on("boundingbox", syncViewport);
    function hydrateOne(el) {
      const mini = hydrateState({
        version: 1,
        graph: { viewport: { ...runtime.graph.viewport } },
        elements: [{ optional: true, ...el }]
      });
      return mini.expressions[0] || null;
    }
    function upsertElement(el) {
      const expr = hydrateOne(el);
      if (!expr) return null;
      const at = runtime.expressions.findIndex((e) => e.id === expr.id);
      const existing = plots.get(expr.id);
      if (existing) {
        removePlot(board, existing);
        plots.delete(expr.id);
      }
      if (at >= 0) runtime.expressions[at] = expr;
      else runtime.expressions.push(expr);
      plotOne(expr);
      board.update();
      return expr.id;
    }
    function removeElement(id) {
      id = String(id);
      const existing = plots.get(id);
      if (existing) {
        removePlot(board, existing);
        plots.delete(id);
      }
      const at = runtime.expressions.findIndex((e) => e.id === id);
      if (at >= 0) runtime.expressions.splice(at, 1);
      board.update();
    }
    let parseHint = null;
    function showParseHint(on) {
      if (on && !parseHint) {
        parseHint = document.createElement("div");
        parseHint.className = "kg-parse-hint";
        parseHint.textContent = "…not graphable yet";
        container.appendChild(parseHint);
      }
      if (parseHint) parseHint.style.display = on ? "block" : "none";
    }
    function inputToElement(value, id, color) {
      const r = classifyLatex(String(value ?? ""), {});
      if (r.kind === "empty") return { empty: true };
      if (r.kind === "line") return { el: { id, kind: "line", eqn: r.eqn, color, lineStyle: "solid" } };
      if (r.kind === "point") return { el: { id, kind: "point", at: r.at, color, pointStyle: "filled", label: "" } };
      return { el: { id, kind: "function", rule: r.rule, color, lineStyle: "solid", domain: null } };
    }
    const bindCleanups = [];
    if (hooks.bind && hooks.bind.input && typeof document !== "undefined") {
      const cfg = { color: "#4c8fd6", debounce: 300, live: true, starter: "", ...hooks.bind };
      const plainEl = document.getElementById(cfg.input);
      const mqSpan = document.getElementById("mqinput-" + cfg.input);
      const target = mqSpan || plainEl;
      if (!target) {
        console.warn(`[kalcgraph] bind: no input element "${cfg.input}" (or mqinput-${cfg.input}) found`);
      } else {
        let timer = null;
        const readValue = () => {
          try {
            const MQ = typeof window !== "undefined" ? window.MQ || window.MathQuill && window.MathQuill.getInterface && window.MathQuill.getInterface(2) : null;
            if (mqSpan && MQ) {
              const field = MQ(mqSpan) || MQ.MathField && MQ.MathField(mqSpan);
              if (field && field.latex) return { value: field.latex(), isLatex: true };
            }
          } catch {
          }
          return { value: plainEl ? plainEl.value : target.textContent || "", isLatex: false };
        };
        const apply = () => {
          const { value } = readValue();
          const r = inputToElement(value, "__student", cfg.color);
          if (r.empty) {
            removeElement("__student");
            showParseHint(false);
            return;
          }
          const id = upsertElement(r.el);
          showParseHint(id === null && String(value).trim() !== "");
          if (id === null) removeElement("__student");
        };
        if (cfg.starter && !String(readValue().value).trim()) {
          const starter = String(cfg.starter);
          try {
            const MQ = typeof window !== "undefined" ? window.MQ || window.MathQuill && window.MathQuill.getInterface && window.MathQuill.getInterface(2) : null;
            if (mqSpan && MQ) {
              const latex = starter.replace(/\b(arcsin|arccos|arctan|sinh|cosh|tanh|sqrt|sin|cos|tan|sec|csc|cot|ln|log)\(/g, "\\$1(");
              const field = MQ(mqSpan) || MQ.MathField && MQ.MathField(mqSpan);
              if (field && field.latex) field.latex(latex);
            }
          } catch {
          }
          if (plainEl) {
            plainEl.value = starter;
            try {
              plainEl.dispatchEvent(new Event("input", { bubbles: true }));
            } catch {
            }
          }
        }
        if (cfg.live !== false) {
          const onEdit = () => {
            clearTimeout(timer);
            timer = setTimeout(apply, cfg.debounce);
          };
          for (const evt of ["input", "keyup", "change"]) target.addEventListener(evt, onEdit);
          if (plainEl && plainEl !== target) for (const evt of ["input", "keyup", "change"]) plainEl.addEventListener(evt, onEdit);
          bindCleanups.push(() => {
            clearTimeout(timer);
            for (const evt of ["input", "keyup", "change"]) {
              target.removeEventListener(evt, onEdit);
              if (plainEl && plainEl !== target) plainEl.removeEventListener(evt, onEdit);
            }
          });
          apply();
        }
      }
    }
    let exploreWrap = null;
    if (options.explore && typeof document !== "undefined") {
      exploreWrap = document.createElement("div");
      exploreWrap.className = "kg-explore";
      const row = document.createElement("div");
      row.className = "kg-explore-row";
      const input = document.createElement("input");
      input.type = "text";
      input.placeholder = "add a curve to explore — e.g. y = x^2 - 3";
      input.setAttribute("aria-label", "Add an ungraded exploration curve");
      const btn = document.createElement("button");
      btn.type = "button";
      btn.textContent = "+";
      btn.setAttribute("aria-label", "Add curve");
      const chips = document.createElement("div");
      chips.className = "kg-explore-chips";
      row.appendChild(input);
      row.appendChild(btn);
      exploreWrap.appendChild(row);
      exploreWrap.appendChild(chips);
      container.insertAdjacentElement("afterend", exploreWrap);
      let exploreN = 0;
      const palette = ["#f59e0b", "#10b981", "#8b5cf6", "#ec4899", "#06b6d4", "#84cc16"];
      const addCurve = () => {
        const value = input.value;
        if (!String(value).trim()) return;
        const color = palette[exploreN % palette.length];
        const r = inputToElement(value, `__x${exploreN}`, color);
        if (r.empty) return;
        const id = upsertElement(r.el);
        showParseHint(id === null);
        if (id === null) return;
        exploreN++;
        const chip = document.createElement("span");
        chip.className = "kg-explore-chip";
        chip.innerHTML = `<i style="background:${color}"></i>`;
        const label = document.createElement("code");
        label.textContent = String(value).trim();
        const x = document.createElement("button");
        x.type = "button";
        x.textContent = "×";
        x.setAttribute("aria-label", "Remove curve");
        x.addEventListener("click", () => {
          removeElement(id);
          chip.remove();
        });
        chip.appendChild(label);
        chip.appendChild(x);
        chips.appendChild(chip);
        input.value = "";
        input.focus();
      };
      btn.addEventListener("click", addCurve);
      input.addEventListener("keydown", (e) => {
        if (e.key === "Enter") {
          e.preventDefault();
          addCurve();
        }
      });
    }
    let destroyed = false;
    return {
      board,
      setHidden(id, hidden) {
        if (destroyed) return;
        const expr = runtime.expressions.find((e) => e.id === String(id));
        if (!expr) return;
        expr.hidden = !!hidden;
        const existing = plots.get(expr.id);
        if (existing) {
          removePlot(board, existing);
          plots.delete(expr.id);
        }
        if (!expr.hidden) plotOne(expr);
        board.update();
      },
      getState() {
        syncViewport();
        return serializeState(runtime);
      },
      addElement(el) {
        if (destroyed) return null;
        return upsertElement(el);
      },
      updateElement(id, patch) {
        if (destroyed) return null;
        const cur = runtime.expressions.find((e) => e.id === String(id));
        if (!cur) return null;
        const base = serializeState({ graph: runtime.graph, options: runtime.options, expressions: [cur] }).elements[0];
        return upsertElement({ ...base, ...patch, id: String(id) });
      },
      removeElement(id) {
        if (destroyed) return;
        removeElement(id);
      },
      setLiveExpression(raw, color) {
        if (destroyed) return null;
        const r = inputToElement(raw, "__student", color || "#4c8fd6");
        if (r.empty) {
          removeElement("__student");
          return null;
        }
        return upsertElement(r.el);
      },
      zoomIn() {
        applyZoom(0.87);
      },
      zoomOut() {
        applyZoom(1.15);
      },
      destroy() {
        if (destroyed) return;
        destroyed = true;
        if (typeof window !== "undefined") window.removeEventListener("kovalna:theme", onHostTheme);
        for (const [type, fn2] of listeners) boardDiv.removeEventListener(type, fn2);
        for (const fn2 of bindCleanups) fn2();
        JXG.JSXGraph.freeBoard(board);
        if (zoomWrap) zoomWrap.remove();
        if (traceDot) traceDot.remove();
        if (traceChip) traceChip.remove();
        if (parseHint) parseHint.remove();
        if (exploreWrap) exploreWrap.remove();
        boardDiv.remove();
        plots.clear();
      }
    };
  }
  function fitCurve(curveType, points) {
    const f = FITTERS[curveType];
    if (!f) return null;
    try {
      return f(points);
    } catch (e) {
      return { fn: null, equation: "", latex: "", params: {}, error: e.message };
    }
  }
  function getCurvePointInfo(curveType) {
    return POINT_INFO[curveType] || { count: 2, labels: ["Point 1", "Point 2"] };
  }
  const POINT_INFO = {
    [CurveType.LINE]: { count: 2, labels: ["Any point", "Any point"] },
    [CurveType.PARABOLA]: { count: 2, labels: ["Vertex (h, k)", "Stretch point"] },
    [CurveType.CUBIC]: { count: 2, labels: ["Inflection (h, k)", "Guide point"] },
    [CurveType.SINE]: { count: 2, labels: ["Peak (crest)", "Trough (valley)"] },
    [CurveType.COSINE]: { count: 2, labels: ["Peak (crest)", "Trough (valley)"] },
    [CurveType.EXP]: { count: 2, labels: ["Point 1 (y > 0)", "Point 2 (y > 0)"] },
    [CurveType.LN]: { count: 2, labels: ["Point 1 (x > 0)", "Point 2 (x > 0)"] },
    [CurveType.LOGISTIC]: { count: 3, labels: ["Floor point", "Midpoint", "Ceiling point"] }
  };
  function fn(n) {
    if (Math.abs(n) < 1e-10) return "0";
    if (Math.abs(n - Math.round(n)) < 1e-10) return String(Math.round(n));
    return parseFloat(n.toFixed(4)).toString();
  }
  function fc(n) {
    if (Math.abs(n - 1) < 1e-10) return "";
    if (Math.abs(n + 1) < 1e-10) return "-";
    return fn(n);
  }
  function gcd$1(a, b) {
    a = Math.abs(a);
    b = Math.abs(b);
    while (b) {
      [a, b] = [b, a % b];
    }
    return a;
  }
  function fln(n) {
    if (Math.abs(n) < 1e-10) return "0";
    if (Math.abs(n - Math.round(n)) < 1e-10) return String(Math.round(n));
    for (let d = 2; d <= 12; d++) {
      const num = Math.round(n * d);
      if (Math.abs(n - num / d) < 1e-9) {
        const g = gcd$1(Math.abs(num), d), sn = num / g, sd = d / g;
        if (sd === 1) return String(sn);
        return `${sn < 0 ? "-" : ""}\\frac{${Math.abs(sn)}}{${sd}}`;
      }
    }
    return parseFloat(n.toFixed(4)).toString();
  }
  function flc(n) {
    if (Math.abs(n - 1) < 1e-10) return "";
    if (Math.abs(n + 1) < 1e-10) return "-";
    return fln(n);
  }
  function sx(h) {
    if (Math.abs(h) < 1e-10) return "x";
    return h > 0 ? `(x - ${fln(h)})` : `(x + ${fln(Math.abs(h))})`;
  }
  function va(k) {
    if (Math.abs(k) < 1e-10) return "";
    return k > 0 ? ` + ${fln(k)}` : ` - ${fln(Math.abs(k))}`;
  }
  const FITTERS = {
    [CurveType.LINE]: (pts) => {
      if (pts.length < 2) return null;
      const [p1, p2] = pts;
      if (Math.abs(p2.x - p1.x) < 1e-10) return { fn: null, equation: `x = ${fn(p1.x)}`, latex: `x = ${fln(p1.x)}`, params: { vertical: true, x: p1.x } };
      const m = (p2.y - p1.y) / (p2.x - p1.x), b = p1.y - m * p1.x;
      let eq = "y = ", tex = "y = ";
      if (Math.abs(m) > 1e-10) {
        eq += `${fc(m)}x`;
        tex += `${flc(m)}x`;
      }
      if (Math.abs(b) > 1e-10) {
        const s = b > 0 ? Math.abs(m) > 1e-10 ? " + " : "" : " - ";
        eq += `${s}${fn(Math.abs(b))}`;
        tex += `${s}${fln(Math.abs(b))}`;
      }
      if (Math.abs(m) < 1e-10 && Math.abs(b) < 1e-10) {
        eq += "0";
        tex += "0";
      }
      return { fn: (x) => m * x + b, equation: eq, latex: tex, params: { m, b } };
    },
    [CurveType.PARABOLA]: (pts) => {
      if (pts.length < 2) return null;
      const [v, s] = pts;
      const h = v.x, k = v.y, dx = s.x - h;
      if (Math.abs(dx) < 1e-10) throw new Error("Stretch point must differ from vertex x");
      const a = (s.y - k) / (dx * dx);
      return { fn: (x) => a * (x - h) ** 2 + k, equation: `y = ${fc(a)}(x − ${fn(h)})² + ${fn(k)}`, latex: `y = ${flc(a)}${sx(h)}^{2}${va(k)}`, params: { a, h, k } };
    },
    [CurveType.CUBIC]: (pts) => {
      if (pts.length < 2) return null;
      const [inf, g] = pts;
      const h = inf.x, k = inf.y, dx = g.x - h;
      if (Math.abs(dx) < 1e-10) throw new Error("Guide must differ from inflection x");
      const a = (g.y - k) / dx ** 3;
      return { fn: (x) => a * (x - h) ** 3 + k, equation: `y = ${fc(a)}(x − ${fn(h)})³ + ${fn(k)}`, latex: `y = ${flc(a)}${sx(h)}^{3}${va(k)}`, params: { a, h, k } };
    },
    [CurveType.SINE]: (pts) => {
      if (pts.length < 2) return null;
      const [pk, tr] = pts;
      const A = (pk.y - tr.y) / 2, D = (pk.y + tr.y) / 2, hp = Math.abs(tr.x - pk.x), B = Math.PI / hp, C = pk.x;
      return { fn: (x) => A * Math.cos(B * (x - C)) + D, equation: `y = ${fc(A)}sin(…) + ${fn(D)}`, latex: `y = ${flc(A)}\\cos\\left(${fBL(B)}${sx(C)}\\right)${va(D)}`, params: { A, B, C, D } };
    },
    [CurveType.COSINE]: (pts) => {
      if (pts.length < 2) return null;
      const [pk, tr] = pts;
      const A = (pk.y - tr.y) / 2, D = (pk.y + tr.y) / 2, hp = Math.abs(tr.x - pk.x), B = Math.PI / hp, C = pk.x;
      return { fn: (x) => A * Math.cos(B * (x - C)) + D, equation: `y = ${fc(A)}cos(…) + ${fn(D)}`, latex: `y = ${flc(A)}\\cos\\left(${fBL(B)}${sx(C)}\\right)${va(D)}`, params: { A, B, C, D } };
    },
    [CurveType.EXP]: (pts) => {
      if (pts.length < 2) return null;
      const [p1, p2] = pts;
      if (p1.y <= 0 || p2.y <= 0) throw new Error("Both y must be positive");
      const base = Math.pow(p2.y / p1.y, 1 / (p2.x - p1.x)), a = p1.y, h = p1.x;
      return { fn: (x) => a * Math.pow(base, x - h), equation: `y = ${fn(a)}·${fn(base)}^(x−${fn(h)})`, latex: `y = ${fln(a)} \\cdot ${fln(base)}^{${sx(h).replace(/[()]/g, "")}}`, params: { a, base, h } };
    },
    [CurveType.LN]: (pts) => {
      if (pts.length < 2) return null;
      const [p1, p2] = pts;
      if (p1.x <= 0 || p2.x <= 0) throw new Error("Both x must be positive");
      const ld = Math.log(p2.x) - Math.log(p1.x), a = (p2.y - p1.y) / ld, c = p1.y - a * Math.log(p1.x);
      return { fn: (x) => x <= 0 ? NaN : a * Math.log(x) + c, equation: `y = ${fc(a)}ln(x) + ${fn(c)}`, latex: `y = ${flc(a)}\\ln(x)${va(c)}`, params: { a, c } };
    },
    [CurveType.LOGISTIC]: (pts) => {
      if (pts.length < 3) return null;
      const [fl, mid, ceil] = pts;
      const L0 = fl.y, L = ceil.y - fl.y, x0 = mid.x, dx = Math.abs(mid.x - fl.x);
      if (Math.abs(L) < 1e-10 || dx < 1e-10) throw new Error("Invalid logistic points");
      const k = 3 / dx * Math.sign(L) * Math.sign(mid.x - fl.x);
      return { fn: (x) => L0 + L / (1 + Math.exp(-k * (x - x0))), equation: `y = ${fn(L0)} + ${fn(L)}/(1+e^(${fn(-k)}(x−${fn(x0)})))`, latex: `y = ${fln(L0)} + \\frac{${fln(L)}}{1 + e^{${flc(-k)}${sx(x0).replace(/[()]/g, "")}}}`, params: { L0, L, k, x0 } };
    }
  };
  function fBL(B) {
    const r = B / Math.PI;
    for (const d of [1, 2, 3, 4, 6]) {
      const n = Math.round(r * d);
      if (Math.abs(r - n / d) < 1e-10 && n !== 0) {
        if (d === 1) return n === 1 ? "\\pi" : `${n}\\pi`;
        return n === 1 ? `\\frac{\\pi}{${d}}` : `\\frac{${n}\\pi}{${d}}`;
      }
    }
    return fln(B);
  }
  const SAMPLE_COUNT$2 = 300;
  const VIS_TOL_FRAC = 0.015;
  function grade(studentData, targetData, gradingConfig) {
    switch (gradingConfig.gradingMode) {
      case GradingMode.CURVE_SHAPE:
        return gradeCurveShape(studentData, targetData, gradingConfig);
      case GradingMode.STRICT:
        return gradeStrict(studentData, targetData, gradingConfig);
      default:
        return { score: 0, match: false, threshold: 0 };
    }
  }
  function computeOverlap(studentFn, targetFn, viewport) {
    if (!studentFn || !targetFn) return 0;
    const yRange = viewport.yMax - viewport.yMin, tol = yRange * VIS_TOL_FRAC;
    const dx = (viewport.xMax - viewport.xMin) / SAMPLE_COUNT$2;
    let matching = 0, total = 0;
    for (let i = 0; i <= SAMPLE_COUNT$2; i++) {
      const x = viewport.xMin + i * dx, ys = studentFn(x), yt = targetFn(x);
      if (!isFinite(ys) || !isFinite(yt) || Math.abs(yt) > yRange * 3) continue;
      total++;
      if (Math.abs(ys - yt) <= tol) matching++;
    }
    return total === 0 ? 0 : matching / total * 100;
  }
  function buildTargetFromCurveConfig(targetCurve) {
    if (!targetCurve?.type || !targetCurve?.points) return null;
    const fitted = fitCurve(targetCurve.type, targetCurve.points);
    return fitted?.fn ? { fn: fitted.fn, controlPoints: targetCurve.points, equation: fitted.equation, latex: fitted.latex, params: fitted.params } : null;
  }
  function gradeCurveShape(sd, td, cfg) {
    const vp = cfg.viewport || { xMin: -10, xMax: 10, yMin: -10, yMax: 10 };
    if (!sd.fn || !td.fn) return { score: 0, match: false, threshold: cfg.gradeThreshold };
    const score = computeOverlap(sd.fn, td.fn, vp);
    return { score, match: score >= cfg.gradeThreshold, threshold: cfg.gradeThreshold, matchingPoints: Math.round(score * SAMPLE_COUNT$2 / 100), totalPoints: SAMPLE_COUNT$2, mode: "curve-shape" };
  }
  function gradeStrict(sd, td, cfg) {
    const sp = sd.controlPoints || [], tp = td.controlPoints || [], tol = cfg.strictTolerance || 0.25;
    if (tp.length === 0) return { score: 0, match: false, threshold: cfg.gradeThreshold, pointsCorrect: 0, pointsTotal: 0, allCorrect: false, deviations: [], mode: "strict" };
    const used = /* @__PURE__ */ new Set();
    const devs = [];
    let correct = 0;
    tp.forEach((t, ti) => {
      let bestD = Infinity, bestI = -1;
      sp.forEach((s, si) => {
        if (used.has(si)) return;
        const d = Math.hypot(s.x - t.x, s.y - t.y);
        if (d < bestD) {
          bestD = d;
          bestI = si;
        }
      });
      if (bestI >= 0 && bestD <= tol) {
        correct++;
        used.add(bestI);
        devs.push({ pointIndex: ti, studentIndex: bestI, distance: bestD, correct: true });
      } else {
        devs.push({ pointIndex: ti, studentIndex: bestI >= 0 ? bestI : null, distance: bestD, correct: false });
        if (bestI >= 0) used.add(bestI);
      }
    });
    const score = tp.length > 0 ? correct / tp.length * 100 : 0;
    return { score, match: correct === tp.length, threshold: cfg.gradeThreshold || 100, pointsCorrect: correct, pointsTotal: tp.length, allCorrect: correct === tp.length, deviations: devs, mode: "strict" };
  }
  function derivative(f, x, h = 5e-5) {
    const fp = f(x + h), fm = f(x - h);
    if (!isFinite(fp) || !isFinite(fm)) return NaN;
    return (fp - fm) / (2 * h);
  }
  function secondDerivative(f, x, h = 5e-4) {
    const fp = f(x + h), fc2 = f(x), fm = f(x - h);
    if (!isFinite(fp) || !isFinite(fc2) || !isFinite(fm)) return NaN;
    return (fp - 2 * fc2 + fm) / (h * h);
  }
  function newtonRefine(g, gp, x0, maxIter = 12, tol = 1e-10) {
    let x = x0;
    for (let i = 0; i < maxIter; i++) {
      const gx = g(x);
      if (Math.abs(gx) < tol) return x;
      const gpx = gp(x);
      if (Math.abs(gpx) < 1e-14) return x;
      const next = x - gx / gpx;
      if (!isFinite(next)) return x;
      if (Math.abs(next - x) < tol) return next;
      x = next;
    }
    return x;
  }
  function newtonRefineNumerical(f, x0) {
    return newtonRefine(f, (x) => derivative(f, x, 1e-4), x0, 12, 1e-10);
  }
  function checkDiscontinuity(f, x, h = 1e-3) {
    const left = f(x - h), right = f(x + h), center = f(x);
    if (!isFinite(center)) {
      if (isFinite(left) && isFinite(right) && Math.abs(left - right) > 5) return { isDiscontinuous: true, type: "asymptote" };
      if (isFinite(left) && isFinite(right)) return { isDiscontinuous: true, type: "removable" };
      return { isDiscontinuous: true, type: "asymptote" };
    }
    if (isFinite(left) && isFinite(right) && Math.abs(right - left) > 8) return { isDiscontinuous: true, type: "asymptote" };
    if (!isFinite(left) || !isFinite(right)) return { isDiscontinuous: true, type: "asymptote" };
    return { isDiscontinuous: false, type: null };
  }
  function linearInterpolateZero(x1, y1, x2, y2) {
    if (Math.abs(y2 - y1) < 1e-15) return (x1 + x2) / 2;
    return x1 - y1 * (x2 - x1) / (y2 - y1);
  }
  const TOL = 6e-3;
  const NAMED_CONSTANTS = [
    { value: 0, display: "0", latex: "0" },
    { value: Math.PI, display: "π", latex: "\\pi" },
    { value: -Math.PI, display: "-π", latex: "-\\pi" },
    { value: 2 * Math.PI, display: "2π", latex: "2\\pi" },
    { value: -2 * Math.PI, display: "-2π", latex: "-2\\pi" },
    { value: Math.E, display: "e", latex: "e" },
    { value: -Math.E, display: "-e", latex: "-e" },
    { value: Math.PI * Math.PI, display: "π²", latex: "\\pi^2" }
  ];
  const RADICALS = buildRadicalTable();
  const PI_FRACTIONS = buildPiFractionTable();
  const SIMPLE_FRACTIONS = buildSimpleFractionTable();
  function recognizeExact(value) {
    if (!isFinite(value)) return null;
    let best = null, bestD = TOL;
    const consider = (e) => {
      const d = Math.abs(value - e.value);
      if (d < bestD) {
        bestD = d;
        best = e;
      }
    };
    for (const e of NAMED_CONSTANTS) consider(e);
    if (Math.abs(value) <= 50.5) {
      const r = Math.round(value);
      consider({ value: r, display: String(r), latex: String(r) });
    }
    for (const e of RADICALS) consider(e);
    for (const e of PI_FRACTIONS) consider(e);
    for (const e of SIMPLE_FRACTIONS) consider(e);
    return best ? { display: best.display, latex: best.latex } : null;
  }
  function recognizeSlope(slope) {
    if (!isFinite(slope)) return { display: "∞", latex: "\\infty" };
    return recognizeExact(slope);
  }
  function formatValue(value, preferExact = true) {
    if (!isFinite(value)) return { display: "undefined", latex: "\\text{undef}", isExact: false };
    if (preferExact) {
      const e = recognizeExact(value);
      if (e) return { ...e, isExact: true };
    }
    const d = smartRound(value);
    return { display: d, latex: d, isExact: false };
  }
  function gcd(a, b) {
    a = Math.abs(a);
    b = Math.abs(b);
    while (b) {
      [a, b] = [b, a % b];
    }
    return a;
  }
  function buildRadicalTable() {
    const entries = [], seen = /* @__PURE__ */ new Set();
    for (const a of [1, 2, 3]) for (const b of [2, 3, 5]) for (const c of [1, 2, 3, 4, 6]) {
      const val = a * Math.sqrt(b) / c, g = gcd(a, c), da = a / g, dc = c / g;
      for (const sign of [1, -1]) {
        const v = sign * val, key = v.toFixed(8);
        if (seen.has(key)) continue;
        seen.add(key);
        const s = sign === -1 ? "-" : "", aStr = da === 1 ? "" : String(da);
        const display = dc === 1 ? `${s}${aStr}√${b}` : `${s}${aStr}√${b}/${dc}`;
        const latex = dc === 1 ? `${s}${aStr}\\sqrt{${b}}` : `${s}\\frac{${aStr}\\sqrt{${b}}}{${dc}}`;
        entries.push({ value: v, display, latex });
      }
    }
    const phi = (1 + Math.sqrt(5)) / 2;
    entries.push({ value: phi, display: "(1+√5)/2", latex: "\\frac{1+\\sqrt{5}}{2}" });
    entries.push({ value: -phi, display: "-(1+√5)/2", latex: "-\\frac{1+\\sqrt{5}}{2}" });
    return entries;
  }
  function buildPiFractionTable() {
    const entries = [], seen = /* @__PURE__ */ new Set();
    for (let n = -24; n <= 24; n++) {
      if (n === 0) continue;
      for (const d of [1, 2, 3, 4, 6, 12]) {
        const val = n * Math.PI / d, g = gcd(Math.abs(n), d), sn = n / g, sd = d / g;
        const key = val.toFixed(8);
        if (seen.has(key)) continue;
        seen.add(key);
        const sign = sn < 0 ? "-" : "", absN = Math.abs(sn);
        let display, latex;
        if (sd === 1) {
          display = absN === 1 ? `${sign}π` : `${sign}${absN}π`;
          latex = absN === 1 ? `${sign}\\pi` : `${sign}${absN}\\pi`;
        } else {
          const nStr = absN === 1 ? "" : String(absN);
          display = `${sign}${nStr}π/${sd}`;
          latex = `${sign}\\frac{${nStr}\\pi}{${sd}}`;
        }
        entries.push({ value: val, display, latex });
      }
    }
    return entries;
  }
  function buildSimpleFractionTable() {
    const entries = [], seen = /* @__PURE__ */ new Set();
    for (let d = 2; d <= 12; d++) for (let n = 1; n < d; n++) {
      if (gcd(n, d) !== 1) continue;
      for (const sign of [1, -1]) {
        const val = sign * n / d, key = val.toFixed(8);
        if (seen.has(key)) continue;
        seen.add(key);
        const s = sign === -1 ? "-" : "";
        entries.push({ value: val, display: `${s}${n}/${d}`, latex: `${s}\\frac{${n}}{${d}}` });
      }
    }
    return entries;
  }
  function smartRound(value) {
    const abs = Math.abs(value);
    if (abs >= 100) return value.toFixed(1);
    if (abs >= 10) return value.toFixed(2);
    if (abs >= 1) return value.toFixed(3);
    if (abs >= 0.01) return value.toFixed(4);
    return value.toExponential(3);
  }
  const SAMPLE_COUNT$1 = 800;
  const CROSSING_THRESHOLD = 0.01;
  const DEDUP_TOLERANCE = 0.05;
  function findIntersections(f1, f2, viewport, opts = {}) {
    const results = [];
    const xRange = viewport.xMax - viewport.xMin;
    const dx = xRange / SAMPLE_COUNT$1;
    const diff = (x) => {
      const y1 = f1(x), y2 = f2(x);
      if (!isFinite(y1) || !isFinite(y2)) return NaN;
      return y1 - y2;
    };
    let prevX = viewport.xMin, prevD = diff(prevX);
    for (let i = 1; i <= SAMPLE_COUNT$1; i++) {
      const x = viewport.xMin + i * dx, d = diff(x);
      if (isFinite(prevD) && isFinite(d)) {
        if (prevD * d < 0) {
          const g = linearInterpolateZero(prevX, prevD, x, d);
          const r = refineRoot(diff, g);
          if (r !== null) addIfNew(results, r, f1, viewport, opts);
        }
        if (Math.abs(d) < 5e-3) {
          const r = refineRoot(diff, x);
          if (r !== null) addIfNew(results, r, f1, viewport, opts);
        }
      }
      prevX = x;
      prevD = d;
    }
    return results;
  }
  function findAllIntersections(exprFunctions, viewport) {
    const all = [];
    for (let i = 0; i < exprFunctions.length; i++) {
      for (let j = i + 1; j < exprFunctions.length; j++) {
        const e1 = exprFunctions[i], e2 = exprFunctions[j];
        try {
          const pts = findIntersections(e1.fn, e2.fn, viewport, { domain1: e1.domain, domain2: e2.domain });
          for (const pt of pts) {
            const xF = formatValue(pt.x, true), yF = formatValue(pt.y, true);
            all.push({ x: pt.x, y: pt.y, xDisplay: xF.display, yDisplay: yF.display, xLatex: xF.latex, yLatex: yF.latex, xIsExact: xF.isExact, yIsExact: yF.isExact, expr1Id: e1.id, expr2Id: e2.id, id: `ix-${e1.id}-${e2.id}-${pt.x.toFixed(6)}` });
          }
        } catch (err) {
          console.warn(`[GraphLab] Intersection error:`, err.message);
        }
      }
    }
    return all;
  }
  function refineRoot(diff, guess) {
    try {
      const r = newtonRefineNumerical(diff, guess);
      if (!isFinite(r) || Math.abs(diff(r)) > CROSSING_THRESHOLD) return null;
      return r;
    } catch {
      return null;
    }
  }
  function addIfNew(results, x, f1, viewport, opts) {
    if (x < viewport.xMin - 0.01 || x > viewport.xMax + 0.01) return;
    if (opts.domain1) {
      const [lo, hi] = opts.domain1;
      if (isFinite(lo) && x < lo) return;
      if (isFinite(hi) && x > hi) return;
    }
    if (opts.domain2) {
      const [lo, hi] = opts.domain2;
      if (isFinite(lo) && x < lo) return;
      if (isFinite(hi) && x > hi) return;
    }
    const y = f1(x);
    if (!isFinite(y)) return;
    if (y < viewport.yMin - 1 || y > viewport.yMax + 1) return;
    for (const e of results) if (Math.abs(e.x - x) < DEDUP_TOLERANCE) return;
    results.push({ x, y });
  }
  const SAMPLE_COUNT = 1200;
  const JUMP_THRESHOLD = 8;
  function detectSpecialPoints(f, viewport) {
    const points = [];
    const xRange = viewport.xMax - viewport.xMin;
    const dx = xRange / SAMPLE_COUNT;
    const samples = new Float64Array(SAMPLE_COUNT + 1);
    const derivS = new Float64Array(SAMPLE_COUNT + 1);
    const deriv2S = new Float64Array(SAMPLE_COUNT + 1);
    for (let i = 0; i <= SAMPLE_COUNT; i++) {
      const x = viewport.xMin + i * dx;
      samples[i] = f(x);
      derivS[i] = derivative(f, x);
      deriv2S[i] = secondDerivative(f, x);
    }
    if (viewport.xMin <= 0 && viewport.xMax >= 0) {
      const y0 = f(0);
      if (isFinite(y0)) points.push({ type: "y-intercept", x: 0, y: y0 });
    }
    for (let i = 0; i < SAMPLE_COUNT; i++) {
      const x1 = viewport.xMin + i * dx, x2 = x1 + dx;
      const y1 = samples[i], y2 = samples[i + 1];
      const d1 = derivS[i], d2 = derivS[i + 1];
      const dd1 = deriv2S[i], dd2 = deriv2S[i + 1];
      if (!isFinite(y1) || !isFinite(y2)) {
        if (isFinite(y1) !== isFinite(y2)) {
          const xD = isFinite(y1) ? x2 : x1;
          if (!hasDup(points, xD, 0.1)) points.push({ type: "discontinuity", x: xD, y: NaN });
        }
        continue;
      }
      if (Math.abs(y2 - y1) > JUMP_THRESHOLD) {
        const xM = (x1 + x2) / 2, yM = f(xM);
        if (!isFinite(yM) || Math.abs(yM) > 1e3) {
          if (!hasDup(points, xM, 0.1)) points.push({ type: "asymptote", x: xM, y: NaN });
        }
        continue;
      }
      if (y1 * y2 < 0) {
        let xZ = linearInterpolateZero(x1, y1, x2, y2);
        xZ = newtonRefineNumerical(f, xZ);
        const yZ = f(xZ);
        if (isFinite(yZ) && Math.abs(yZ) < 0.01 && !hasDup(points, xZ, 0.05)) points.push({ type: "x-intercept", x: xZ, y: 0 });
      }
      if (Math.abs(y1) < 5e-3 && !hasDup(points, x1, 0.05)) {
        const xR = newtonRefineNumerical(f, x1);
        if (isFinite(xR) && Math.abs(f(xR)) < 1e-3) points.push({ type: "x-intercept", x: xR, y: 0 });
      }
      if (isFinite(d1) && isFinite(d2) && d1 > 0 && d2 < 0) {
        let xM = linearInterpolateZero(x1, d1, x2, d2);
        xM = newtonRefineNumerical((x) => derivative(f, x), xM);
        const yM = f(xM);
        if (isFinite(yM) && !hasDup(points, xM, 0.05)) points.push({ type: "maximum", x: xM, y: yM });
      }
      if (isFinite(d1) && isFinite(d2) && d1 < 0 && d2 > 0) {
        let xM = linearInterpolateZero(x1, d1, x2, d2);
        xM = newtonRefineNumerical((x) => derivative(f, x), xM);
        const yM = f(xM);
        if (isFinite(yM) && !hasDup(points, xM, 0.05)) points.push({ type: "minimum", x: xM, y: yM });
      }
      if (isFinite(dd1) && isFinite(dd2) && dd1 * dd2 < 0) {
        let xI = linearInterpolateZero(x1, dd1, x2, dd2);
        xI = newtonRefineNumerical((x) => secondDerivative(f, x), xI);
        const yI = f(xI);
        if (isFinite(yI) && !hasDup(points, xI, 0.05)) points.push({ type: "inflection", x: xI, y: yI });
      }
    }
    points.sort((a, b) => a.x - b.x);
    return points;
  }
  function hasDup(points, x, tol) {
    return points.some((p) => Math.abs(p.x - x) < tol);
  }
  function findNearestSpecialPoint(specialPoints, cursorX, cursorY, snapDist) {
    let best = null, bestDist = snapDist || 0.4;
    for (const sp of specialPoints) {
      const d = Math.hypot(cursorX - sp.x, cursorY - (sp.y || 0));
      if (d < bestDist) {
        bestDist = d;
        best = sp;
      }
    }
    return best ? { point: best, dist: bestDist } : null;
  }
  const NOOP_METHODS = [
    "observeEvent",
    "unobserveEvent",
    "observe",
    "unobserve",
    "screenshot",
    "asyncScreenshot",
    "updateSettings",
    "setDefaultState",
    "removeExpression",
    "removeExpressions",
    "setExpressions",
    "setBlank",
    "undo",
    "redo",
    "focusFirstExpression"
  ];
  const IGNORED_OPTIONS = {
    settingsMenu: "chrome UI — the mount has no settings menu",
    expressionsCollapsed: "expressions panel UI — v1 renders no panel",
    keypad: "input UI — not rendered",
    graphpaper: "always on",
    border: "cosmetic",
    images: "unsupported content type",
    folders: "unsupported content type",
    notes: "unsupported content type"
  };
  function toBool(v, dflt = false) {
    if (v === true || v === "true" || v === 1) return true;
    if (v === false || v === "false" || v === 0 || v == null) return false;
    return dflt;
  }
  function warnOnce(seen, key, msg) {
    if (seen.has(key)) return;
    seen.add(key);
    console.warn(`[kalcgraph-compat] ${msg}`);
  }
  function desmosStateToV1(desmosState, options = {}) {
    const g = desmosState && desmosState.graph || {};
    const vraw = g.viewport || {};
    const viewport = {
      xmin: Number(vraw.xmin ?? -10),
      xmax: Number(vraw.xmax ?? 10),
      ymin: Number(vraw.ymin ?? -10),
      ymax: Number(vraw.ymax ?? 10)
    };
    const graph = {
      viewport,
      showGrid: toBool(g.showGrid, true),
      showXAxis: toBool(g.showXAxis, true),
      showYAxis: toBool(g.showYAxis, true),
      xAxisStep: Number(g.xAxisStep || 0),
      yAxisStep: Number(g.yAxisStep || 0),
      xAxisLabel: String(g.xAxisLabel || ""),
      yAxisLabel: String(g.yAxisLabel || ""),
      squareAxes: toBool(g.squareAxes, false),
      projectorMode: toBool(g.projectorMode ?? options.projectorMode, false)
    };
    const lock = toBool(options.lockViewport, false);
    const opts = {
      explore: toBool(options.expressions, false),
      // D7: v1 renders read-only
      trace: toBool(options.trace, false),
      zoom: !lock,
      lockViewport: lock,
      poi: toBool(options.pointsOfInterest, false),
      // D8: v1 no-op
      zoomButtons: lock ? false : toBool(options.zoomButtons, true)
    };
    const env = {};
    const elements = [];
    const list = desmosState && desmosState.expressions && desmosState.expressions.list || [];
    let auto = 0;
    for (const entry of list) {
      if (!entry || entry.type && entry.type !== "expression") continue;
      const r = classifyLatex(entry.latex ?? "", env);
      if (r.kind === "empty") continue;
      const id = entry.id != null ? String(entry.id) : `c${auto}`;
      auto++;
      const hidden = toBool(entry.hidden, false);
      const style = String(entry.style || "").toUpperCase();
      const color = entry.color || void 0;
      if (r.kind === "line") {
        elements.push({ id, kind: "line", eqn: r.eqn, lineStyle: style === "DASHED" ? "dashed" : style === "DOTTED" ? "dotted" : "solid", ...color ? { color } : {}, hidden });
      } else if (r.kind === "point") {
        elements.push({ id, kind: "point", at: r.at, pointStyle: style === "OPEN" ? "open" : "filled", label: "", ...color ? { color } : {}, hidden });
      } else {
        elements.push({ id, kind: "function", rule: r.rule, lineStyle: style === "DASHED" ? "dashed" : style === "DOTTED" ? "dotted" : "solid", ...color ? { color } : {}, hidden, domain: null });
      }
    }
    return { version: 1, graph, options: opts, elements };
  }
  function installDesmosShim(target, hooks = {}) {
    if (!target) return null;
    if (target.Desmos) {
      console.warn("[kalcgraph-compat] window.Desmos already defined — shim not installed");
      return null;
    }
    const mountFn = hooks.mount || mount;
    hooks.document || target.document;
    function GraphingCalculator(elt, options = {}) {
      const seen = /* @__PURE__ */ new Set();
      const id = elt && elt.id;
      if (!id) {
        elt.id = "kg-anon-" + Math.floor(Math.random() * 1e9);
      }
      for (const k of Object.keys(options)) {
        if (k in IGNORED_OPTIONS) warnOnce(seen, "opt:" + k, `option "${k}" ignored (${IGNORED_OPTIONS[k]}) — #${elt.id}`);
      }
      if (toBool(options.expressions, false)) {
        console.warn(`[kalcgraph-compat] expressions:true → explore drawer (ungraded scratch curves; graded entry is the answer box) — #${elt.id}`);
      }
      let handle = null;
      let lastState = null;
      const api = {
        setState(state) {
          try {
            const v1 = desmosStateToV1(state, options);
            const check = validateState(v1);
            if (!check.ok) {
              console.warn(`[kalcgraph-compat] translated state invalid for #${elt.id}: ${check.errors.join("; ")}`);
              return;
            }
            if (handle) {
              try {
                handle.destroy();
              } catch {
              }
            }
            lastState = state;
            handle = mountFn(elt.id, v1, hooks);
          } catch (e) {
            console.warn(`[kalcgraph-compat] setState failed for #${elt.id}: ${e.message}`);
          }
        },
        setExpression(def = {}) {
          if (!lastState) lastState = { version: 1, graph: {}, expressions: { list: [] } };
          const list = lastState.expressions.list;
          const at = def.id != null ? list.findIndex((e) => String(e.id) === String(def.id)) : -1;
          if (at >= 0) list[at] = { ...list[at], ...def };
          else list.push({ type: "expression", ...def });
          api.setState(lastState);
        },
        getState() {
          return lastState;
        },
        _kalcgraph() {
          return handle;
        }
        // test/debug seam
      };
      for (const m of NOOP_METHODS) {
        api[m] = (...args) => warnOnce(seen, "m:" + m, `${m}() is not supported — no-op — #${elt.id}`);
      }
      return api;
    }
    target.Desmos = {
      GraphingCalculator,
      Styles: { SOLID: "SOLID", DASHED: "DASHED", DOTTED: "DOTTED", OPEN: "OPEN", POINT: "POINT", CROSS: "CROSS", HOLE: "OPEN" },
      Colors: { RED: "#c74440", BLUE: "#2d70b3", GREEN: "#388c46", ORANGE: "#fa7e19", PURPLE: "#6042a6", BLACK: "#000000" },
      supportedLanguages: ["en"]
    };
    return target.Desmos;
  }
  if (typeof window !== "undefined") {
    installDesmosShim(window);
  }
  exports.ALL_CURVE_TYPES = ALL_CURVE_TYPES;
  exports.COLORS_DARK = COLORS_DARK;
  exports.COLORS_LIGHT = COLORS_LIGHT;
  exports.CurveType = CurveType;
  exports.DEFAULT_CONFIG = DEFAULT_CONFIG;
  exports.DEFAULT_VIEWPORT = DEFAULT_VIEWPORT;
  exports.DisplayMode = DisplayMode;
  exports.ExpressionType = ExpressionType;
  exports.GradingMode = GradingMode;
  exports.LINE_STYLE_CONFIG = LINE_STYLE_CONFIG;
  exports.POINT_STYLE = POINT_STYLE;
  exports.STATE_VERSION = STATE_VERSION;
  exports.ScaleMode = ScaleMode;
  exports.SpecialPointType = SpecialPointType;
  exports.THEME_VARS = THEME_VARS;
  exports.Theme = Theme;
  exports.TickMode = TickMode;
  exports.buildTargetFromCurveConfig = buildTargetFromCurveConfig;
  exports.checkDiscontinuity = checkDiscontinuity;
  exports.classifyLatex = classifyLatex;
  exports.classifyType = classifyType;
  exports.computeOverlap = computeOverlap;
  exports.defaultTokenResolver = defaultTokenResolver;
  exports.derivative = derivative;
  exports.desmosStateToV1 = desmosStateToV1;
  exports.detectSpecialPoints = detectSpecialPoints;
  exports.extractDomain = extractDomain;
  exports.findAllIntersections = findAllIntersections;
  exports.findIntersections = findIntersections;
  exports.findNearestSpecialPoint = findNearestSpecialPoint;
  exports.fitCurve = fitCurve;
  exports.formatValue = formatValue;
  exports.getCurvePointInfo = getCurvePointInfo;
  exports.grade = grade;
  exports.hydrateMainPayload = hydrateMainPayload;
  exports.hydrateState = hydrateState;
  exports.installDesmosShim = installDesmosShim;
  exports.isValidLineEqn = isValidLineEqn;
  exports.latexToRule = latexToRule;
  exports.linearInterpolateZero = linearInterpolateZero;
  exports.mount = mount;
  exports.newtonRefine = newtonRefine;
  exports.newtonRefineNumerical = newtonRefineNumerical;
  exports.parseExpression = parseExpression;
  exports.plotExpression = plotExpression;
  exports.recognizeExact = recognizeExact;
  exports.recognizeSlope = recognizeSlope;
  exports.removePlot = removePlot;
  exports.secondDerivative = secondDerivative;
  exports.serializeMainPayload = serializeMainPayload;
  exports.serializeState = serializeState;
  exports.validateState = validateState;
  Object.defineProperty(exports, Symbol.toStringTag, { value: "Module" });
  return exports;
}({});
//# sourceMappingURL=kalcgraph.js.map
