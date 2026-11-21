<?php
// KalcGraph Integration Library, Version 0.1 (KalcGraph Phase 3), 2026
// Nakoval Learning Systems LLC
//
// Interactive graphing for IMathAS questions, served by the KalcGraph core
// (a static asset pair: assessment/libs/kalcgraph.js + kalcgraph.css, built
// from the @kovalna/kalcgraph-core package — see the provenance headers in
// those files). This thin macro library is the only KalcGraph code that lives
// in the IMathAS tree (license boundary, spec D10).
//
// ── USAGE ────────────────────────────────────────────────────────────────
//   loadlibrary("KALCGRAPH")
//   $g = KalcGraph_create("calc$thisq", ["bounds"=>[-12,12,-20,20]])
//   $g = KalcGraph_addFunction($g, ["rule"=>"((x-2)(3x-3)(x+4))/((x-2)(x-5)(x+3))"])
//   $g = KalcGraph_addLine($g, ["eqn"=>"x=5","style"=>"dashed","hidden"=>$t1])
//   $g = KalcGraph_addPoint($g, ["at"=>[2,-1.2],"style"=>"open","hidden"=>$t2])
//   $g = KalcGraph_addText($g, ["at"=>[4,10],"text"=>"note"])
//   $gr = KalcGraph_render($g)
// then place $gr in the question text.
//
// NAMESPACING: always pass "calc$thisq" as the id (unique per question on the
// page); append a suffix for multi-board questions ("calc{$thisq}b").
//
// RULE SYNTAX IS ENGINE SYNTAX, NOT LATEX. Implicit multiplication like
// (x-2)(x+4), 3x and 2sin(x) is accepted. NOT accepted (write the * out):
//   2e^x  -> 2*e^x       3 x -> 3*x       pi x -> pi*x       xy -> not allowed
// (Authority: the Phase 2 parser probe table, packages/kalcgraph-core/
//  test/triageRegression.test.js in the Kovalna repo.)
//
// THE REVEAL IDIOM (spec §2.5): elements render hidden until a control-flag
// flips on re-render. In the question control section:
//   $t1 = "true"
//   if ($scoreiscorrect[$thisq][3]==1) { $t1 = "false" }
// then pass ["hidden"=>$t1] — string "true"/"false" flags are accepted.
//
// ── v1.1 ANSWERBOX-WIRED GRAPHING ────────────────────────────────────────
// The answer box is always the graded input; the board is a display.
//
// LIVE-INVESTIGATE idiom (type an equation, watch it graph):
//   $g = KalcGraph_create("calc$thisq", ["bounds"=>[-10,10,-10,10]])
//   $g = KalcGraph_bindAnswerbox($g, ["qn"=>$thisq])
//   $gr = KalcGraph_render($g)
// The student's answer box live-plots (debounced) as they type; unparsable
// input shows a subtle "not graphable yet" chip. Grading is the box itself.
//
// ATTEMPTS-MATCH idiom (match the mystery graph across attempts):
//   $g = KalcGraph_create("calc$thisq", ["bounds"=>[-10,10,-10,10]])
//   $g = KalcGraph_addFunction($g, ["rule"=>"$target"])          // the mystery curve
//   $g = KalcGraph_addAttempt($g, $stuanswers[$thisq],
//          ["correct"=>$scoreiscorrect[$thisq]])                 // most recent attempt
//   $gr = KalcGraph_render($g)
// The prior attempt plots in ember; when correct it plots GREEN. Garbage
// input plots nothing (never breaks the board). No live graphing.
//
// EXPLORE (either idiom, or standalone): ["explore"=>true] on create renders
// an "add a curve" drawer under the board — ungraded, ephemeral scratch work.
//
// LAYOUT: $gr is plain HTML — place it anywhere in the question text ($gr
// before $answerbox puts the box below the graph). For side-by-side, wrap
// qtext content in the responsive helper (stacks on narrow screens):
//   <div class="kg-cols">
//     <div><p>prompt...</p><p>`y =` $answerbox</p></div>
//     <div>$gr</div>
//   </div>
//
// Mounted boards are also registered for author JS at
// KalcGraph._boards["kg-<id>"] (handles expose setHidden, setLiveExpression,
// addElement/updateElement/removeElement).

global $allowedmacros;
$allowedmacros[] = "KalcGraph_create";
$allowedmacros[] = "KalcGraph_addFunction";
$allowedmacros[] = "KalcGraph_addLine";
$allowedmacros[] = "KalcGraph_addPoint";
$allowedmacros[] = "KalcGraph_addText";
$allowedmacros[] = "KalcGraph_bindAnswerbox";
$allowedmacros[] = "KalcGraph_addAttempt";
$allowedmacros[] = "KalcGraph_evalAt";
$allowedmacros[] = "KalcGraph_render";

define('KALCGRAPH_VERSION', '0.1.0-5bafc118');   // single source for ?v= cache busting

// ── internal helpers ─────────────────────────────────────────────────────

// Emits the graph assets. The JS src tags go out once per request via the
// assessment script queue (doc-order, blocks on src), so the bundle is loaded
// before any mount line — and window.KalcGraph persists across re-renders.
// The CSS is different: an inline <link> dies when a Check re-render replaces
// the question html, and the score request already burned the once-per-request
// guard during the scoring phase (its render output is discarded) — the board
// then remounts UNSTYLED (position:static, zoom buttons in-flow over the text
// below). So the css goes to document.head via a tiny guarded script emitted
// on EVERY render — head survives question re-renders.
function _kalcgraph_assets() {
	static $emitted = false;
	$v = KALCGRAPH_VERSION;
	// Same URL pattern the jsxgraph.php lib uses for jsxgraphcore.js
	$base = $GLOBALS['staticroot'] . '/assessment/libs';
	$out  = '<script type="text/javascript">(function(){if(!document.getElementById("kg-css")){var l=document.createElement("link");l.id="kg-css";l.rel="stylesheet";l.href="' . $base . '/kalcgraph.css?v=' . $v . '";document.head.appendChild(l);}})();</script>';
	if ($emitted) { return $out; }
	$emitted = true;
	$out .= '<script type="text/javascript" src="' . $base . '/jsxgraphcore.js?v=' . $v . '"></script>';
	$out .= '<script type="text/javascript" src="' . $base . '/kalcgraph.js?v=' . $v . '"></script>';
	return $out;
}

function _kalcgraph_id($id) {
	$clean = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $id);
	if ($clean === '') {
		echo 'KalcGraph_create: id is empty after sanitizing - pass something like "calc$thisq". ';
	}
	return $clean;
}

function _kalcgraph_color($c, $context) {
	// Named set -> canonical theme hexes (from the parity-demo palette);
	// "black" means default ink, which on the dark theme is the light text color.
	static $named = array(
		'blue' => '#4c8fd6', 'gray' => '#8a8aa0', 'grey' => '#8a8aa0',
		'red' => '#ef4444', 'green' => '#10b981', 'orange' => '#f59e0b',
		'black' => '#e2e2f0',
	);
	$c = strtolower(trim((string) $c));
	if (isset($named[$c])) { return $named[$c]; }
	if (preg_match('/^#[0-9a-f]{6}$/', $c)) { return $c; }
	echo "$context: unrecognized color \"$c\" - using blue. ";
	return $named['blue'];
}

function _kalcgraph_bool($v, $default = false) {
	if ($v === true || $v === 'true' || $v === 1 || $v === '1') { return true; }
	if ($v === false || $v === 'false' || $v === 0 || $v === '0' || $v === null) { return false; }
	return (bool) ($v === null ? $default : $v);
}

// v1 line grammar, same regex family as the core package's state.js
function _kalcgraph_valideqn($eqn) {
	$s = trim((string) $eqn);
	return preg_match('/^x\s*=\s*-?\d+(\.\d+)?$/', $s)
		|| preg_match('/^y\s*=\s*-?\d+(\.\d+)?$/', $s)
		|| preg_match('/^y\s*=\s*-?(\d+(\.\d+)?)?\s*x\s*([+-]\s*\d+(\.\d+)?)?$/', $s);
}

function _kalcgraph_style($s, $context) {
	$s = strtolower(trim((string) $s));
	if ($s === '' || $s === 'solid') { return 'solid'; }
	if ($s === 'dashed' || $s === 'dotted') { return $s; }
	echo "$context: style must be solid, dashed or dotted (got \"$s\") - using solid. ";
	return 'solid';
}

function _kalcgraph_warn_unknown($ops, $known, $context) {
	foreach ($ops as $k => $v) {
		if (!in_array($k, $known, true)) {
			echo "$context: unknown option \"$k\" ignored. ";
		}
	}
}

function _kalcgraph_nextid($g, $ops) {
	if (isset($ops['id']) && $ops['id'] !== '') { return (string) $ops['id']; }
	return (string) count($g['elements']);
}

// ── macro API (spec §2.2) ────────────────────────────────────────────────

// KalcGraph_create(id, options)
// Required: bounds=[xmin,xmax,ymin,ymax]. Optional (defaults):
//   size=[500,420], grid=true, xstep=0, ystep=0 (0 = auto), square=false,
//   projector=false, trace=true, zoom=true, zoombuttons=true, lock=false,
//   explore=false
function KalcGraph_create($id, $ops = array()) {
	$known = array('bounds','size','grid','xstep','ystep','square','projector',
		'trace','zoom','zoombuttons','lock','explore');
	_kalcgraph_warn_unknown($ops, $known, 'KalcGraph_create');

	$id = _kalcgraph_id($id);
	$b = isset($ops['bounds']) ? $ops['bounds'] : null;
	if (!is_array($b) || count($b) !== 4 || !is_numeric($b[0]) || !is_numeric($b[1])
			|| !is_numeric($b[2]) || !is_numeric($b[3]) || $b[0] >= $b[1] || $b[2] >= $b[3]) {
		echo 'KalcGraph_create: bounds=[xmin,xmax,ymin,ymax] is required with xmin<xmax, ymin<ymax. ';
		$b = array(-10, 10, -10, 10);
	}
	$size = isset($ops['size']) && is_array($ops['size']) && count($ops['size']) === 2
		? array((int) $ops['size'][0], (int) $ops['size'][1]) : array(500, 420);

	return array(
		'id' => $id,
		'size' => $size,
		'graph' => array(
			'viewport' => array('xmin' => $b[0] + 0, 'xmax' => $b[1] + 0, 'ymin' => $b[2] + 0, 'ymax' => $b[3] + 0),
			'showGrid' => _kalcgraph_bool(isset($ops['grid']) ? $ops['grid'] : true, true),
			'showXAxis' => true, 'showYAxis' => true,
			'xAxisStep' => isset($ops['xstep']) ? $ops['xstep'] + 0 : 0,
			'yAxisStep' => isset($ops['ystep']) ? $ops['ystep'] + 0 : 0,
			'xAxisLabel' => '', 'yAxisLabel' => '',
			'squareAxes' => _kalcgraph_bool(isset($ops['square']) ? $ops['square'] : false),
			'projectorMode' => _kalcgraph_bool(isset($ops['projector']) ? $ops['projector'] : false),
		),
		'options' => array(
			'explore' => _kalcgraph_bool(isset($ops['explore']) ? $ops['explore'] : false),
			'trace' => _kalcgraph_bool(isset($ops['trace']) ? $ops['trace'] : true, true),
			'zoom' => _kalcgraph_bool(isset($ops['zoom']) ? $ops['zoom'] : true, true),
			'lockViewport' => _kalcgraph_bool(isset($ops['lock']) ? $ops['lock'] : false),
			'poi' => false,
			'zoomButtons' => _kalcgraph_bool(isset($ops['zoombuttons']) ? $ops['zoombuttons'] : true, true),
		),
		'elements' => array(),
	);
}

// KalcGraph_addFunction(g, ["rule"=>..., "color"=>"blue", "style"=>"solid",
//   "hidden"=>false, "domain"=>[min,max]|null, "id"=>auto])
function KalcGraph_addFunction($g, $ops = array()) {
	$known = array('rule','color','style','hidden','domain','id','optional','format');
	_kalcgraph_warn_unknown($ops, $known, 'KalcGraph_addFunction');
	$optional = _kalcgraph_bool(isset($ops['optional']) ? $ops['optional'] : false);
	if (!isset($ops['rule']) || trim((string) $ops['rule']) === '') {
		// optional (E11): template values may interpolate to empty for some
		// seeds — skip silently, exactly like the legacy empty-latex entries.
		if (!$optional) { echo 'KalcGraph_addFunction: rule is required. '; }
		return $g;
	}
	$domain = null;
	if (isset($ops['domain']) && is_array($ops['domain']) && count($ops['domain']) === 2) {
		$domain = array($ops['domain'][0] + 0, $ops['domain'][1] + 0);
	}
	$g['elements'][] = array(
		'id' => _kalcgraph_nextid($g, $ops),
		'kind' => 'function',
		'rule' => (string) $ops['rule'],
		'color' => _kalcgraph_color(isset($ops['color']) ? $ops['color'] : 'blue', 'KalcGraph_addFunction'),
		'lineStyle' => _kalcgraph_style(isset($ops['style']) ? $ops['style'] : 'solid', 'KalcGraph_addFunction'),
		'hidden' => _kalcgraph_bool(isset($ops['hidden']) ? $ops['hidden'] : false),
		'domain' => $domain,
	);
	if ($optional) { $g['elements'][count($g['elements']) - 1]['optional'] = true; }
	// format:"latex" (E12): rule values that arrive latex-ified (makexxpretty
	// output) — the mount translates via latexToRule before parsing.
	if (isset($ops['format']) && strtolower(trim((string) $ops['format'])) === 'latex') {
		$g['elements'][count($g['elements']) - 1]['format'] = 'latex';
	}
	return $g;
}

// KalcGraph_addLine(g, ["eqn"=>"x=5"|"y=3"|"y=-2x+1", "style"=>"solid",
//   "color"=>"gray", "hidden"=>false, "id"=>auto])
function KalcGraph_addLine($g, $ops = array()) {
	$known = array('eqn','style','color','hidden','id');
	_kalcgraph_warn_unknown($ops, $known, 'KalcGraph_addLine');
	$eqn = isset($ops['eqn']) ? trim((string) $ops['eqn']) : '';
	if (!_kalcgraph_valideqn($eqn)) {
		echo "KalcGraph_addLine: eqn must match x=&lt;num&gt;, y=&lt;num&gt; or y=&lt;m&gt;x+&lt;b&gt; (got \"" . htmlspecialchars($eqn) . "\") - element skipped. ";
		return $g;
	}
	$g['elements'][] = array(
		'id' => _kalcgraph_nextid($g, $ops),
		'kind' => 'line',
		'eqn' => $eqn,
		'lineStyle' => _kalcgraph_style(isset($ops['style']) ? $ops['style'] : 'solid', 'KalcGraph_addLine'),
		'color' => _kalcgraph_color(isset($ops['color']) ? $ops['color'] : 'gray', 'KalcGraph_addLine'),
		'hidden' => _kalcgraph_bool(isset($ops['hidden']) ? $ops['hidden'] : false),
	);
	return $g;
}

// KalcGraph_addPoint(g, ["at"=>[x,y], "style"=>"filled"|"open", "label"=>"",
//   "color"=>"blue", "hidden"=>false, "id"=>auto])
function KalcGraph_addPoint($g, $ops = array()) {
	$known = array('at','style','label','color','hidden','id');
	_kalcgraph_warn_unknown($ops, $known, 'KalcGraph_addPoint');
	$at = isset($ops['at']) ? $ops['at'] : null;
	if (!is_array($at) || count($at) !== 2 || !is_numeric($at[0]) || !is_numeric($at[1])) {
		echo 'KalcGraph_addPoint: at=[x,y] is required. ';
		return $g;
	}
	$style = isset($ops['style']) ? strtolower(trim((string) $ops['style'])) : 'filled';
	if ($style !== 'filled' && $style !== 'open') {
		echo "KalcGraph_addPoint: style must be filled or open - using filled. ";
		$style = 'filled';
	}
	$g['elements'][] = array(
		'id' => _kalcgraph_nextid($g, $ops),
		'kind' => 'point',
		'at' => array($at[0] + 0, $at[1] + 0),
		'pointStyle' => $style,
		'label' => isset($ops['label']) ? (string) $ops['label'] : '',
		'color' => _kalcgraph_color(isset($ops['color']) ? $ops['color'] : 'blue', 'KalcGraph_addPoint'),
		'hidden' => _kalcgraph_bool(isset($ops['hidden']) ? $ops['hidden'] : false),
	);
	return $g;
}

// KalcGraph_addText(g, ["at"=>[x,y], "text"=>"note"])  — JSXG_addText parity
function KalcGraph_addText($g, $ops = array()) {
	$known = array('at','text','id');
	_kalcgraph_warn_unknown($ops, $known, 'KalcGraph_addText');
	$at = isset($ops['at']) ? $ops['at'] : null;
	if (!is_array($at) || count($at) !== 2 || !is_numeric($at[0]) || !is_numeric($at[1])
			|| !isset($ops['text'])) {
		echo 'KalcGraph_addText: at=[x,y] and text are required. ';
		return $g;
	}
	$g['elements'][] = array(
		'id' => _kalcgraph_nextid($g, $ops),
		'kind' => 'text',
		'at' => array($at[0] + 0, $at[1] + 0),
		'text' => (string) $ops['text'],
	);
	return $g;
}

// KalcGraph_bindAnswerbox(g, ["qn"=>$thisq, "color"=>"blue", "debounce"=>300])
// v1.1 live-investigate: wires the question's answer box to the board — it
// live-plots as the student types. The box remains the graded input.
function KalcGraph_bindAnswerbox($g, $ops = array()) {
	$known = array('qn','input','color','debounce','starter','live');
	_kalcgraph_warn_unknown($ops, $known, 'KalcGraph_bindAnswerbox');
	// "qn"=>$thisq is the normal path — $thisq is 1-based but answer boxes are
	// qn0, qn1, … (0-based), so the macro binds qn($thisq - 1). "input" is a
	// raw element-id override for composite boxes (multipart parts, custom ids).
	if (isset($ops['input']) && trim((string) $ops['input']) !== '') {
		$input = preg_replace('/[^A-Za-z0-9_\-{}$]/', '', (string) $ops['input']);
	} elseif (isset($ops['qn']) && is_numeric($ops['qn'])) {
		$input = 'qn' . (intval($ops['qn']) - 1);
	} else {
		echo 'KalcGraph_bindAnswerbox: pass "qn"=>$thisq (or a raw "input" id). ';
		return $g;
	}
	$g['bind'] = array(
		'input' => $input,
		'color' => _kalcgraph_color(isset($ops['color']) ? $ops['color'] : 'blue', 'KalcGraph_bindAnswerbox'),
		'debounce' => isset($ops['debounce']) ? intval($ops['debounce']) : 300,
	);
	// "starter": authored scaffold prefilled into an EMPTY box on first visit
	// (never clobbers a restored answer). "live"=>false keeps the prefill but
	// skips live plotting (attempts-style questions).
	if (isset($ops['starter']) && trim((string) $ops['starter']) !== '') {
		$g['bind']['starter'] = substr(trim((string) $ops['starter']), 0, 160);
	}
	if (isset($ops['live']) && ($ops['live'] === false || $ops['live'] === 0 || $ops['live'] === 'false' || $ops['live'] === '0')) {
		$g['bind']['live'] = false;
	}
	return $g;
}

// KalcGraph_addAttempt(g, $stuanswers[$thisq], ["correct"=>$scoreiscorrect[$thisq]])
// v1.1 attempts-match: plots the student's most recent attempt in ember —
// GREEN when correct. Tolerant by construction: no prior answer or an
// unparsable one plots nothing (the board never breaks on student input).
function KalcGraph_addAttempt($g, $stuanswer, $ops = array()) {
	$known = array('correct','color','style','id');
	_kalcgraph_warn_unknown($ops, $known, 'KalcGraph_addAttempt');
	$raw = is_array($stuanswer) ? reset($stuanswer) : $stuanswer;
	if ($raw === null || trim((string) $raw) === '') { return $g; }
	$correct = _kalcgraph_bool(isset($ops['correct']) ? $ops['correct'] : false);
	$color = $correct ? '#10b981'
		: _kalcgraph_color(isset($ops['color']) ? $ops['color'] : 'orange', 'KalcGraph_addAttempt');
	$g['elements'][] = array(
		'id' => isset($ops['id']) && $ops['id'] !== '' ? (string) $ops['id'] : 'attempt',
		'kind' => 'function',
		'rule' => (string) $raw,
		'color' => $color,
		'lineStyle' => _kalcgraph_style(isset($ops['style']) ? $ops['style'] : 'solid', 'KalcGraph_addAttempt'),
		'hidden' => false,
		'domain' => null,
		'optional' => true, // student-supplied — skip instead of fail on bad parse (E11)
	);
	return $g;
}

// KalcGraph_evalAt(rule, x) — evaluate a rule at x in the CONTROL (e.g. to
// place a point on a defined curve: ["at"=>[$nc, KalcGraph_evalAt($eq2,$nc)]]).
// Thin allowed wrapper over the core mathparser (which is not control-callable).
function KalcGraph_evalAt($rule, $x) {
	if (!function_exists('makeMathFunction')) { require_once __DIR__ . '/../mathparser.php'; }
	$fn = makeMathFunction((string) $rule, 'x', array(), '', true);
	if ($fn === false || $fn === null) {
		echo 'KalcGraph_evalAt: rule did not parse. ';
		return 0;
	}
	$y = $fn(array('x' => $x + 0));
	return is_numeric($y) ? $y + 0 : 0;
}

// KalcGraph_render(g) — RETURNS the embeddable string; place it in the
// question text. Emits the shared assets once per request, the board div,
// and the mount call (delivered via the assessment script queue).
function KalcGraph_render($g) {
	if (!is_array($g) || !isset($g['id']) || !isset($g['elements'])) {
		echo 'KalcGraph_render: pass the array returned by KalcGraph_create. ';
		return '';
	}
	$state = array(
		'version' => 1,
		'graph' => $g['graph'],
		'options' => $g['options'],
		'elements' => $g['elements'],
	);
	// JSON_HEX_TAG is mandatory: a rule containing </script> must not be able
	// to break out of the script block.
	$flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
	$json = json_encode($state, $flags);
	$hooks = isset($g['bind']) ? ', ' . json_encode(array('bind' => $g['bind']), $flags) : '';
	$divid = 'kg-' . $g['id'];
	$w = (int) $g['size'][0];
	$h = (int) $g['size'][1];

	$out  = _kalcgraph_assets();
	$out .= '<div id="' . $divid . '" class="kalcgraph" data-theme="dark" style="width:' . $w . 'px;height:' . $h . 'px"></div>';
	$out .= '<script type="text/javascript">KalcGraph._boards = KalcGraph._boards || {}; ';
	$out .= 'KalcGraph._boards["' . $divid . '"] = KalcGraph.mount("' . $divid . '", ' . $json . $hooks . ');</script>';
	return $out;
}
