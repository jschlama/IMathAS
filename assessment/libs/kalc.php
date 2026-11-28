<?php
// Kalc question library (fork-only). The Kovalna Kalc graphing calculator in a
// question, written from the question's control code.
//
// ── USAGE ────────────────────────────────────────────────────────────────
//   loadlibrary("kalc")
//   $g = kalc_graph($thisq, ["window"=>[-10,10,-10,10], "input"=>true])
//   $g = kalc_add($g, "$target", ["hidden"=>true])          // an author row
//   $g = kalc_state($g, $stuanswers, $thisq, 1)             // part 1 keeps the graph
//   $g = kalc_bind($g, $thisq, 0)                           // the student's row -> part 0
//   $gr = kalc_render($g)                                   // put $gr in the question text
//
//   $st = kalc_read($stuanswers, $thisq, 1)                 // what the student drew
//   kalc_rows($st)                 -> the student's expressions, as typed
//   kalc_matches($st, "$target")   -> true when one of them is the target curve
//
// kalc_graph(thisq, options)
//   window  [xmin, xmax, ymin, ymax]   (default [-10,10,-10,10])
//   input   true: the student can type expressions (the Kalc's question mode;
//           an expression in x graphs without "y =") / false: a graph to read
//   height  pixels of graph (default 400)
//   lock    true: no pan or zoom
//   suffix  for a second graph in one question: kalc_graph($thisq, [..., "suffix"=>"b"])
//
// kalc_add(g, expression, options) — an author row, in the Kalc's own syntax
//   ("2(x-1)^2+3", "y=2x+1", "x^2+y^2=9", "(3,4)", "y<x+1", "r=2cos(theta)").
//   hidden  true: drawn but not listed (a target the student matches)
//   show    false: listed but not drawn (the reveal idiom: show it after a correct answer)
//   color   blue red green purple gold black orange pink teal gray, or #rrggbb
//   style   solid dashed dotted
//   latex   true when the expression is LaTeX rather than Kalc syntax
//   editable true: the student may hide, retype, delete or drag this row. By
//           default an author row is FIXED — listed and drawn, never changed.
//
// kalc_state(g, $stuanswers, $thisq, part) — the graph remembers itself.
//   A question page keeps only its answers, so the student's rows live in an
//   answer part: make that part a "string" (or "essay") part with weight 0 and
//   put its $answerbox[part] anywhere in the question text — the graph hides
//   it, writes the student's rows into it on every change, and draws them
//   again from it when the question is shown again (after Check, on review,
//   in the gradebook). What is kept is the student's rows and the window; the
//   author's rows always come from the control code, so a reveal still works.
//   The text is compressed (k1:...) to stay under a text box's 8000 characters.
//   Single-part question: no state part is possible — use a multipart.
//
// kalc_bind(g, $thisq, part) — the student's latest row is copied into that
//   answer part as they type (a leading "y =" dropped), so the box is graded
//   as usual. part = -1 on a single-part question.
//
// kalc_read($stuanswers, $thisq, part) — the state part, read back:
//   ["rows" => [["expr"=>"y=(x+4)*(x-2)^2", "latex"=>"...", "visible"=>true], ...],
//    "window" => [xmin, xmax, ymin, ymax]]   (empty rows before anything is typed)
// kalc_rows(state)  — the expressions alone.
// kalc_matches(state, target, [xmin, xmax]) — true when some row of the
//   student's, as y = f(x), agrees with the target f(x) at 40 points of
//   [xmin, xmax] (default the window) where both are defined.

global $allowedmacros;
array_push($allowedmacros, 'kalc_graph', 'kalc_add', 'kalc_state', 'kalc_bind', 'kalc_render',
    'kalc_read', 'kalc_rows', 'kalc_matches');

// The answer box id of a part: qn<thisq-1> on a single-part question,
// qn<thisq*1000+part> on a multipart one.
function _kalc_qnid($thisq, $part) {
    $thisq = (int) $thisq;
    if ($part === null || $part === '' || (int) $part < 0) { return $thisq - 1; }
    return $thisq * 1000 + (int) $part;
}

function _kalc_bool($v) { return $v === true || $v === 'true' || $v === 1 || $v === '1'; }

function kalc_graph($thisq, $opts = array()) {
    if (!is_array($opts)) { $opts = array(); }
    $w = isset($opts['window']) && is_array($opts['window']) && count($opts['window']) == 4
        ? array_map('floatval', array_values($opts['window'])) : array(-10, 10, -10, 10);
    if ($w[0] >= $w[1] || $w[2] >= $w[3]) { echo 'kalc_graph: window must be [xmin, xmax, ymin, ymax]. '; $w = array(-10, 10, -10, 10); }
    $suffix = isset($opts['suffix']) ? preg_replace('/[^A-Za-z0-9_]/', '', (string) $opts['suffix']) : '';
    return array(
        'kalc' => 1,
        'id' => 'kalc' . (int) $thisq . $suffix,
        'window' => $w,
        'input' => isset($opts['input']) ? _kalc_bool($opts['input']) : false,
        'height' => isset($opts['height']) ? max(160, min(1200, (int) $opts['height'])) : 400,
        'lock' => isset($opts['lock']) ? _kalc_bool($opts['lock']) : false,
        'rows' => array(),
    );
}

function kalc_add($g, $expr, $opts = array()) {
    if (!is_array($g) || empty($g['kalc'])) { echo 'kalc_add: pass the graph from kalc_graph. '; return $g; }
    if (!is_array($opts)) { $opts = array(); }
    $expr = trim((string) $expr);
    if ($expr === '') { return $g; }
    $row = array('tag' => 'kq:' . count($g['rows']));
    if (isset($opts['latex']) && _kalc_bool($opts['latex'])) { $row['latex'] = $expr; } else { $row['eq'] = $expr; }
    if (isset($opts['hidden'])) { $row['ghost'] = _kalc_bool($opts['hidden']); }
    // A row the question gives is fixed: listed and drawn, never hidden, retyped,
    // deleted or dragged by the student (it would come back on the next render
    // anyway). "editable"=>true hands it to the student.
    $row['fixed'] = !(isset($opts['editable']) && _kalc_bool($opts['editable']));
    if (isset($opts['show'])) { $row['visible'] = _kalc_bool($opts['show']); }
    if (isset($opts['color'])) {
        $c = strtolower(trim((string) $opts['color']));
        if (preg_match('/^(#[0-9a-f]{6}|blue|red|green|purple|gold|black|orange|pink|teal|gray|grey)$/', $c)) { $row['color'] = $c; }
        else { echo "kalc_add: unknown color \"$c\". "; }
    }
    if (isset($opts['style'])) {
        $s = strtolower(trim((string) $opts['style']));
        if (in_array($s, array('solid', 'dashed', 'dotted'), true)) { $row['style'] = $s; }
    }
    $g['rows'][] = $row;
    return $g;
}

function kalc_state($g, $stuanswers, $thisq, $part) {
    if (!is_array($g) || empty($g['kalc'])) { echo 'kalc_state: pass the graph from kalc_graph. '; return $g; }
    if ($part === null || (int) $part < 0) { echo 'kalc_state: the state needs its own part of a multipart question. '; return $g; }
    $g['state'] = array('qn' => _kalc_qnid($thisq, $part), 'saved' => (string) _kalc_stuans($stuanswers, $thisq, $part));
    return $g;
}

function kalc_bind($g, $thisq, $part) {
    if (!is_array($g) || empty($g['kalc'])) { echo 'kalc_bind: pass the graph from kalc_graph. '; return $g; }
    $g['bind'] = array('qn' => _kalc_qnid($thisq, $part));
    return $g;
}

function kalc_render($g) {
    if (!is_array($g) || empty($g['kalc'])) { echo 'kalc_render: pass the graph from kalc_graph. '; return ''; }
    $cfg = array(
        'el' => $g['id'], 'window' => $g['window'], 'input' => $g['input'], 'height' => $g['height'],
        'lock' => $g['lock'], 'rows' => $g['rows'],
    );
    if (isset($g['state'])) { $cfg['state'] = $g['state']; }
    if (isset($g['bind'])) { $cfg['bind'] = $g['bind']; }
    // JSON_HEX_*: nothing in an expression can close the script block.
    $json = json_encode($cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $src = $GLOBALS['staticroot'] . '/assessment/libs/nxkalc.js';
    return '<div id="' . $g['id'] . '" class="nx-kalc" style="width:100%;max-width:820px;"></div>'
        . '<script type="text/javascript" src="' . $src . '"></script>'
        . '<script type="text/javascript">NXKalc.mount(' . $json . ');</script>';
}

// ── reading the state back ───────────────────────────────────────────────

function _kalc_stuans($stuanswers, $thisq, $part) {
    if (!is_array($stuanswers) || !isset($stuanswers[$thisq])) { return ''; }
    $a = $stuanswers[$thisq];
    if (is_array($a)) { return isset($a[(int) $part]) ? $a[(int) $part] : ''; }
    return (int) $part === 0 ? $a : '';
}

// "k1:" + base64(deflate-raw(json)) or "k0:" + json.
function _kalc_decode($s) {
    $s = trim((string) $s);
    if (strncmp($s, 'k1:', 3) === 0) {
        $bin = base64_decode(substr($s, 3), true);
        $json = $bin === false ? false : @gzinflate($bin);
    } elseif (strncmp($s, 'k0:', 3) === 0) {
        $json = substr($s, 3);
    } else {
        return null;
    }
    if ($json === false) { return null; }
    $d = json_decode($json, true);
    return is_array($d) ? $d : null;
}

function kalc_read($stuanswers, $thisq, $part) {
    $d = _kalc_decode(_kalc_stuans($stuanswers, $thisq, $part));
    $out = array('rows' => array(), 'window' => null);
    if (!$d) { return $out; }
    if (isset($d['rows']) && is_array($d['rows'])) {
        foreach ($d['rows'] as $r) {
            if (!is_array($r) || !isset($r['expr'])) { continue; }
            $out['rows'][] = array(
                'expr' => (string) $r['expr'],
                'latex' => isset($r['latex']) ? (string) $r['latex'] : '',
                'visible' => !isset($r['visible']) || $r['visible'] !== false,
            );
        }
    }
    if (isset($d['window']) && is_array($d['window']) && count($d['window']) == 4) {
        $out['window'] = array_map('floatval', $d['window']);
    }
    return $out;
}

function kalc_rows($state) {
    if (!is_array($state) || !isset($state['rows'])) { return array(); }
    $out = array();
    foreach ($state['rows'] as $r) { $out[] = $r['expr']; }
    return $out;
}

function kalc_matches($state, $target, $range = null) {
    if (!function_exists('makeMathFunction')) { require_once __DIR__ . '/../mathparser.php'; }
    $rows = kalc_rows($state);
    if (!$rows) { return false; }
    $tf = makeMathFunction(_kalc_rhs($target), 'x', array(), '', true);
    if (!$tf) { echo 'kalc_matches: the target did not parse. '; return false; }
    if (is_array($range) && count($range) == 2) { $lo = (float) $range[0]; $hi = (float) $range[1]; }
    elseif (is_array($state['window'] ?? null)) { $lo = $state['window'][0]; $hi = $state['window'][1]; }
    else { $lo = -10; $hi = 10; }
    foreach ($rows as $expr) {
        $fn = @makeMathFunction(_kalc_rhs($expr), 'x', array(), '', true);
        if (!$fn) { continue; }
        $tested = 0; $ok = true;
        for ($i = 0; $i < 40; $i++) {
            $x = $lo + ($hi - $lo) * ($i + 0.37) / 40;
            $a = @$fn(array('x' => $x)); $b = @$tf(array('x' => $x));
            if (!is_numeric($a) || !is_numeric($b) || !is_finite($a) || !is_finite($b)) { continue; }
            $tested++;
            if (abs($a - $b) > 1e-6 * max(1, abs($b))) { $ok = false; break; }
        }
        if ($ok && $tested >= 10) { return true; }
    }
    return false;
}

// "y = f(x)" or "f(x) = ..." -> the right-hand side; anything else as it is.
function _kalc_rhs($expr) {
    $e = trim((string) $expr);
    if (preg_match('/^\s*(y|[a-zA-Z]\s*\(\s*x\s*\))\s*=(.*)$/', $e, $m) && strpos($m[2], '=') === false) { return trim($m[2]); }
    return $e;
}
