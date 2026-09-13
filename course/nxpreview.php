<?php
/**
 * Live question preview for the imathas-nx authoring UI. (Fork-only file.)
 *
 * The new app owns question authoring; to SHOW the teacher what a question looks like it must
 * run the engine, so it POSTs the unsaved fields here and embeds the result in an iframe. This
 * renders them through assess2/AssessStandalone — the same engine testquestion2.php uses — and
 * returns a self-contained page (no header chrome) with just the scripts a rendered question
 * needs. It authorises with the shared HMAC token (NX_HANDOFF_SECRET), never a login, and it
 * only ever renders content the caller supplied — it reads nothing from imas_questionset.
 */
$init_skip_csrfp = true;
require_once '../init_without_validate.php';
require_once '../assess2/AssessStandalone.php';

$secret = getenv('NX_HANDOFF_SECRET');
if (empty($secret)) { http_response_code(500); exit('Preview not configured.'); }

function nx_b64url_decode($s) { return base64_decode(strtr($s, '-_', '+/')); }

$token = $_POST['token'] ?? '';
$dot = strpos($token, '.');
if ($dot === false || $dot === 0) { http_response_code(400); exit('Bad token.'); }
$body = substr($token, 0, $dot);
$expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $secret, true)), '+/', '-_'), '=');
if (!hash_equals($expected, substr($token, $dot + 1))) { http_response_code(401); exit('Bad token signature.'); }
$payload = json_decode(nx_b64url_decode($body), true);
if (!is_array($payload) || ($payload['exp'] ?? 0) < time() || ($payload['purpose'] ?? '') !== 'preview') {
    http_response_code(401); exit('Token expired or wrong purpose.');
}

// Build a full imas_questionset row from the posted fields. Every column is defaulted so the
// engine (which expects a SELECT * row) never hits an undefined key — a stray warning here
// prints before the headers and corrupts the response.
$s = fn($k) => (string) ($_POST[$k] ?? '');
$data = array_merge([
    'id' => 0, 'uniqueid' => 0, 'adddate' => 0, 'lastmoddate' => 0, 'ownerid' => 0,
    'author' => '', 'userights' => 2, 'license' => 1, 'description' => '',
    'qtype' => 'multipart', 'control' => '', 'qcontrol' => '', 'qtext' => '',
    'answer' => '', 'solution' => '', 'extref' => '', 'hasimg' => 0, 'deleted' => 0,
    'avgtime' => 0, 'ancestors' => '', 'ancestorauthors' => '', 'otherattribution' => '',
    'importuid' => '', 'replaceby' => 0, 'broken' => 0, 'solutionopts' => 0,
    'sourceinstall' => '', 'meantimen' => 0, 'meantime' => 0, 'vartime' => 0,
    'meanscoren' => 0, 'meanscore' => 0, 'varscore' => 0, 'isrand' => 0,
    'a11yalt' => 0, 'a11yalttype' => 0, 'a11ystatus' => 0, 'points' => 1
], array_filter([
    'qtype' => $s('qtype') !== '' ? $s('qtype') : null,
    'control' => $_POST['control'] ?? null,
    'qcontrol' => $_POST['qcontrol'] ?? null,
    'qtext' => $_POST['qtext'] ?? null,
    'answer' => $_POST['answer'] ?? null,
    'solution' => $_POST['solution'] ?? null,
    'extref' => $_POST['extref'] ?? null
], fn($v) => $v !== null));

// The engine's macro parser reads a few globals a login would normally set. This is a
// teacher-only, HMAC-authorised preview, so give it instructor-level context.
$GLOBALS['myrights'] = 100;
$GLOBALS['userid'] = 0;
$myrights = 100;
// Display prefs the math/graph filter reads. graphdisp=1 = SVG graphs; mathdisp=6 =
// KaTeX (the renderer this page wires below). Assigning $_SESSION without a session
// is fine — preview needs no persistence.
$_SESSION['graphdisp'] = 1;
$_SESSION['mathdisp'] = 6;

$qn = 27;
$seed = isset($_POST['seed']) ? intval($_POST['seed']) : rand(0, 10000);
// JSON mode powers the authoring UI's seed-sweep: instead of the HTML page it
// returns the engine-resolved correct answer(s) + any render errors for this
// seed, so the new app can validate a draft across many randomizations.
$jsonMode = (($_POST['format'] ?? '') === 'json');
$a2 = new AssessStandalone($DBH);
$a2->setQuestionData(0, $data);
$a2->setState([
    'seeds' => [$qn => $seed],
    'qsid' => [$qn => 0],
    'stuanswers' => [], 'stuanswersval' => [],
    'scorenonzero' => [($qn + 1) => -1], 'scoreiscorrect' => [($qn + 1) => -1],
    'partattemptn' => [$qn => []], 'rawscores' => [$qn => []]
]);
// includeans populates jsparams['ans'] (Question::getCorrectAnswersForParts) — the
// sweep's oracle. Also on when the teacher asked to see the answer in the preview.
$disp = $a2->displayQuestion($qn, [
    'showans' => ($jsonMode || !empty($_POST['showans'])),
    'includeans' => ($jsonMode || !empty($_POST['showans'])),
    'showteachernotes' => true
]);

if ($jsonMode) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'seed' => intval($seed),
        'ans' => $disp['jsparams']['ans'] ?? null,
        'errors' => array_map(
            fn($e) => is_array($e) ? implode(' ', $e) : (string) $e,
            $disp['errors'] ?? []
        )
    ]);
    exit;
}

$sr = $staticroot;
$scripts = [
    "$sr/mathquill/mathquill.min.js?v=070726",
    "$sr/javascript/drawing.js?v=041920",
    "$sr/javascript/AMhelpers2.js?v=071122",
    "$sr/javascript/eqntips.js?v=041920",
    "$sr/mathquill/AMtoMQ.js?v=071122",
    "$sr/mathquill/mqeditor.js?v=021121",
    "$sr/mathquill/mqedlayout.js?v=071122",
    "$sr/javascript/assess2supp.js?v=041522"
];
header('Content-Type: text/html; charset=utf-8');
echo "<!doctype html><html><head><meta charset=\"utf-8\">";
echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
echo '<link rel="stylesheet" type="text/css" href="' . $sr . '/imascore.css">';
echo '<link rel="stylesheet" type="text/css" href="' . $sr . '/mathquill/mathquill-basic.css?v=070726">';
echo '<link rel="stylesheet" type="text/css" href="' . $sr . '/mathquill/mqeditor.css?v=020226">';
// Math rendering — mirror header.php's KaTeX branch ($_SESSION['mathdisp']==6) so
// backtick AsciiMath in the question typesets. initq() calls window.rendermathnode()
// (defined by katex/auto-render.js), which converts AsciiMath with AMTparseAMtoTeX
// from ASCIIMathTeXImg_min.js — header.php loads that for mathdisp==6, and without
// it KaTeX throws and the math stays raw. jQuery is needed by setupKatexAutoRender.
// mathparser + ASCIIsvg (graphdisp=1) load too. All before assess2supp.js runs initq.
echo '<script src="' . $sr . '/javascript/jquery.min.js"></script>';
echo '<script>var AMTcgiloc = "' . ($mathimgurl ?? '') . '";</script>';
echo '<script src="' . $sr . '/javascript/ASCIIMathTeXImg_min.js?ver=061426"></script>';
echo '<script src="' . $sr . '/katex/katex.min.js"></script>';
echo '<link rel="stylesheet" href="' . $sr . '/katex/katex.min.css">';
echo '<script src="' . $sr . '/katex/auto-render.js?v=111025"></script>';
echo '<script>setupKatexAutoRender();</script>';
echo '<script>noMathRender = false; var usingASCIIMath = true; var AMnoMathML = true; var MathJaxCompatible = true; var mathRenderer = "Katex";</script>';
echo '<script src="' . $sr . '/javascript/mathparser_min.js?v=031126"></script>';
echo '<script src="' . $sr . '/javascript/ASCIIsvg_min.js?v=070426"></script>';
echo '<script>var usingASCIISvg = true;</script>';
foreach ($scripts as $u) echo '<script src="' . $u . '"></script>';
echo '<style>body{font-family:system-ui,sans-serif;margin:1rem;background:#fff;color:#111}</style>';
echo '</head><body>';
if (!empty($disp['errors'])) {
    echo '<div style="color:#b42317"><strong>Errors:</strong><ul>';
    foreach ($disp['errors'] as $e) echo '<li>' . Sanitize::encodeStringForDisplay(is_array($e) ? implode(' ', $e) : $e) . '</li>';
    echo '</ul></div>';
}
echo '<form class="questionwrap" onsubmit="return false">';
echo $disp['html'];
echo '</form>';
echo '<p class="small subdued" style="color:#666">Seed ' . intval($seed) . '</p>';
echo '<script>if (typeof initq==="function") { try { initq(' . $qn . ',' . json_encode($disp['jsparams']) . '); } catch(e){ console.error(e); } }</script>';
echo '</body></html>';
