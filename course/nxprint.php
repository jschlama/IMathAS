<?php
/**
 * A test on paper for the imathas-nx app (fork-only file): course/printlayoutbare.php's job —
 * the assessment's questions rendered for print, in one or more versions, with answer keys —
 * as one self-contained page the teacher prints from the browser.
 *
 * nx shows the choices and POSTs them here in a new tab. It authorises with the shared HMAC
 * token (NX_HANDOFF_SECRET), purpose 'print', which names the assessment and its course: nx
 * signs one only for a teacher of that course, and this page renders only that assessment.
 *
 * What is printlayoutbare's, kept: the questions chosen from each group once for every version;
 * the seeds by the assessment's shuffle flags (bit 2: one seed for every question, bit 4: the
 * same for every student) and each question's fixed seeds; printq2 (displayQuestion with
 * printformat, printfilter, the key from jsparams['ans'], the detailed solution when the
 * question allows it). What is new: a print CODE seeds the random choices, so printing again
 * with the same code gives the same versions and the same key.
 */
$init_skip_csrfp = true;
require_once '../init_without_validate.php';
require_once '../assess2/AssessStandalone.php';
require_once '../filter/filter.php';

$secret = getenv('NX_HANDOFF_SECRET');
if (empty($secret)) { http_response_code(500); exit('Printing not configured.'); }

function nx_b64url_decode($s) { return base64_decode(strtr($s, '-_', '+/')); }

$token = $_POST['token'] ?? '';
$dot = strpos($token, '.');
if ($dot === false || $dot === 0) { http_response_code(400); exit('Bad token.'); }
$body = substr($token, 0, $dot);
$expected = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $secret, true)), '+/', '-_'), '=');
if (!hash_equals($expected, substr($token, $dot + 1))) { http_response_code(401); exit('Bad token signature.'); }
$claims = json_decode(nx_b64url_decode($body), true);
if (!is_array($claims) || ($claims['exp'] ?? 0) < time() || ($claims['purpose'] ?? '') !== 'print') {
    http_response_code(401); exit('This print link has expired. Go back and press Print again.');
}
$aid = (int) ($claims['aid'] ?? 0);
$cid = (int) ($claims['cid'] ?? 0);

$stm = $DBH->prepare('SELECT itemorder,shuffle,defpoints,name FROM imas_assessments WHERE id=:id AND courseid=:cid');
$stm->execute([':id' => $aid, ':cid' => $cid]);
$line = $stm->fetch(PDO::FETCH_ASSOC);
if ($line === false) { http_response_code(404); exit('No such assessment.'); }

// The choices. Every one is bounded; text is only ever echoed encoded.
$copies = max(1, min(10, (int) ($_POST['versions'] ?? 1)));
$code = max(1, min(999999, (int) ($_POST['code'] ?? 1)));
$keys = $_POST['keys'] ?? 'end';          // 'none' | 'end'
$showpts = !empty($_POST['showpts']);
$detsoln = !empty($_POST['detsoln']);
$space = $_POST['space'] ?? 'some';      // 'none' | 'some' | 'lots'
$header = [
    'title' => trim((string) ($_POST['title'] ?? $line['name'])),
    'course' => trim((string) ($_POST['course'] ?? '')),
    'teacher' => trim((string) ($_POST['teacher'] ?? '')),
    'name' => !empty($_POST['nameline']),
    'other' => trim((string) ($_POST['other'] ?? ''))
];

// The random choices, from the print code: the same code, the same versions.
mt_srand($code);

// Questions: groups picked once for all versions (printlayoutbare's loop).
$questions = [];
foreach (explode(',', $line['itemorder']) as $q) {
    if ($q === '') continue;
    if (strpos($q, '~') !== false) {
        $sub = explode('~', $q);
        if (strpos($sub[0], '|') === false) {
            $questions[] = $sub[array_rand($sub, 1)];
        } else {
            $grpparts = explode('|', array_shift($sub));
            if ($grpparts[1] == 1) {
                for ($i = 0; $i < $grpparts[0]; $i++) $questions[] = $sub[array_rand($sub, 1)];
            } else {
                shuffle($sub);
                for ($i = 0; $i < min($grpparts[0], count($sub)); $i++) $questions[] = $sub[$i];
                for ($i = count($sub); $i < $grpparts[0]; $i++) $questions[] = $sub[array_rand($sub, 1)];
            }
        }
    } else {
        $questions[] = $q;
    }
}
$questions = array_values(array_map('intval', $questions));
$numq = count($questions);

$points = []; $qsid = []; $fixedseeds = [];
if ($numq > 0) {
    $ph = implode(',', array_fill(0, $numq, '?'));
    $stm = $DBH->prepare("SELECT id,points,questionsetid,fixedseeds FROM imas_questions WHERE assessmentid=? AND id IN ($ph)");
    $stm->execute(array_merge([$aid], $questions));
    while ($row = $stm->fetch(PDO::FETCH_ASSOC)) {
        $points[$row['id']] = ($row['points'] == 9999) ? $line['defpoints'] : $row['points'];
        $qsid[$row['id']] = $row['questionsetid'];
        if ($row['fixedseeds'] !== null && $row['fixedseeds'] !== '') $fixedseeds[$row['id']] = explode(',', $row['fixedseeds']);
    }
}
// A question id that isn't this assessment's never renders.
$questions = array_values(array_filter($questions, fn($q) => isset($qsid[$q])));
$numq = count($questions);

// Seeds per version (printlayoutbare's, with rand() drawn from the code).
$seeds = []; $fixedn = [];
for ($j = 0; $j < $copies; $j++) {
    $seeds[$j] = [];
    if ($line['shuffle'] & 2) {
        $seeds[$j] = array_fill(0, $numq, ($line['shuffle'] & 4) ? $aid + $j : rand(1, 9999));
    } else {
        for ($i = 0; $i < $numq; $i++) {
            $q = $questions[$i];
            if (isset($fixedseeds[$q])) {
                $n = count($fixedseeds[$q]);
                if ($line['shuffle'] & 4) {
                    $seeds[$j][] = $fixedseeds[$q][$j % $n];
                } else {
                    if (!isset($fixedn[$i])) $fixedn[$i] = rand(0, $n - 1);
                    $seeds[$j][] = $fixedseeds[$q][($fixedn[$i] + $j) % $n];
                }
            } else {
                $seeds[$j][] = ($line['shuffle'] & 4) ? $aid + $i + $j : rand(1, 9999);
            }
        }
    }
}

// The engine's print rendering: math typeset in the browser (KaTeX), graphs as images.
$GLOBALS['myrights'] = 100;
$GLOBALS['userid'] = 0;
$myrights = 100;
$_SESSION['graphdisp'] = 2;
$_SESSION['mathdisp'] = 6;
$GLOBALS['isbareprint'] = true;

$a2 = new AssessStandalone($DBH);
if ($numq > 0) {
    $ph = implode(',', array_fill(0, count($qsid), '?'));
    $stm = $DBH->prepare("SELECT * FROM imas_questionset WHERE id IN ($ph)");
    $stm->execute(array_values(array_unique($qsid)));
    while ($qdata = $stm->fetch(PDO::FETCH_ASSOC)) $a2->setQuestionData($qdata['id'], $qdata);
}

function nx_printq($qn, $qsetid, $seed) {
    global $a2;
    $a2->setState(['seeds' => [$qn => $seed], 'qsid' => [$qn => $qsetid]]);
    $res = $a2->displayQuestion($qn, ['includeans' => true, 'printformat' => true]);
    $ans = $res['jsparams']['ans'] ?? '';
    if (is_array($ans)) $ans = implode(' ~ ', $ans);
    return [
        nx_letter_choices(printfilter($res['html'])),
        printfilter(filter((string) $ans)),
        ((($res['solnopts'] ?? 0) & 5) == 5) ? printfilter(filter($res['soln'])) : ''
    ];
}

/**
 * Choices laid out in a row (displayformat horizontal: div.choice in a choicesflexrow) keep no
 * letters on paper — the engine letters only a vertical list (it turns ul.nomark into an
 * upper-alpha ol) — yet the key names them "B: 3". Letter each row's choices A, B, C… in their
 * displayed order (the order the key counts in), and drop the blank under each: the letter is
 * what a student circles.
 */
function nx_letter_choices($html) {
    return preg_replace_callback('/<div[^>]*class="[^"]*choicesflexrow[^"]*"[^>]*>.*?<\/div>\s*<\/div>/s', function ($m) {
        $n = 0;
        $row = preg_replace_callback('/<div class=choice>\s*(<label[^>]*>)/', function ($c) use (&$n) {
            return '<div class=choice>' . $c[1] . '<b>' . chr(65 + $n++) . '.</b> ';
        }, $m[0]);
        return preg_replace('/<br\/?>\s*_{2,}\s*<\/div>/', '</div>', $row);
    }, $html);
}

$enc = fn($s) => Sanitize::encodeStringForDisplay($s);
$out = [];
for ($j = 0; $j < $copies; $j++) {
    $out[$j] = [];
    for ($i = 0; $i < $numq; $i++) {
        $q = $questions[$i];
        try {
            $out[$j][$i] = nx_printq($i, $qsid[$q], $seeds[$j][$i]);
        } catch (Throwable $e) {
            $out[$j][$i] = ['<p class="err">This question could not be rendered: ' . $enc($e->getMessage()) . '</p>', '', ''];
        }
    }
}
$form = fn($j) => $copies > 1 ? ' — Version ' . chr(65 + $j) : '';

$sr = $staticroot;
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');
echo '<!doctype html><html><head><meta charset="utf-8"><title>' . $enc($header['title']) . '</title>';
echo '<link rel="stylesheet" href="' . $sr . '/katex/katex.min.css">';
echo '<script src="' . $sr . '/javascript/jquery.min.js"></script>';
echo '<script>var AMTcgiloc = "' . ($mathimgurl ?? '') . '";</script>';
echo '<script src="' . $sr . '/javascript/ASCIIMathTeXImg_min.js?ver=061426"></script>';
echo '<script src="' . $sr . '/katex/katex.min.js"></script>';
echo '<script src="' . $sr . '/katex/auto-render.js?v=111025"></script>';
echo '<script>setupKatexAutoRender();</script>';
$gap = ['none' => '0', 'some' => '1.6in', 'lots' => '3.2in'][$space] ?? '1.6in';
?>
<style>
	@page { size: letter; margin: 0.6in; }
	body { font-family: Georgia, 'Times New Roman', serif; font-size: 12pt; color: #000; background: #fff; margin: 0 auto; max-width: 7.3in; padding: 0.4in 0; }
	.bar { font-family: system-ui, sans-serif; font-size: 11pt; background: #f2f4f7; border: 1px solid #d0d5dd; border-radius: 6px; padding: 0.6rem 0.9rem; margin-bottom: 1.2rem; display: flex; gap: 1rem; align-items: center; flex-wrap: wrap; }
	.bar button { font: inherit; padding: 0.35rem 0.9rem; cursor: pointer; }
	.version { page-break-after: always; break-after: page; }
	.version:last-of-type { page-break-after: auto; break-after: auto; }
	.head { border-bottom: 1.5px solid #000; padding-bottom: 0.5rem; margin-bottom: 1rem; }
	.head h1 { font-size: 16pt; margin: 0 0 0.2rem; }
	.head .sub { font-size: 10.5pt; }
	.head .fill { display: flex; gap: 2rem; margin-top: 0.7rem; flex-wrap: wrap; }
	.head .fill span { white-space: nowrap; }
	.q { display: flex; gap: 0.5rem; page-break-inside: avoid; break-inside: avoid; margin-bottom: <?php echo $gap; ?>; }
	.q .n { font-weight: bold; min-width: 1.6rem; }
	.q .pts { font-size: 10pt; font-style: italic; }
	.q .body { flex: 1; min-width: 0; }
	.q img { max-width: 100%; }
	.key { page-break-before: always; break-before: page; }
	.key h2 { font-size: 14pt; }
	.key ol { padding-left: 1.6rem; }
	.key li { margin-bottom: 0.4rem; page-break-inside: avoid; }
	.key .soln { font-size: 10.5pt; margin: 0.25rem 0 0.5rem; }
	.err { color: #b42317; }
	.code { font-size: 9pt; color: #555; margin-top: 0.6rem; }
	@media print { .bar { display: none; } body { padding: 0; max-width: none; } }
</style>
<?php
echo '</head><body>';
echo '<div class="bar"><button type="button" onclick="window.print()">Print</button><span>' . $copies . ' version' . ($copies === 1 ? '' : 's') . ', ' . $numq . ' question' . ($numq === 1 ? '' : 's') . ($keys === 'end' ? ', answer key' . ($copies === 1 ? '' : 's') . ' at the end' : '') . '. Print code <strong>' . $code . '</strong> prints these same versions again.</span></div>';
for ($j = 0; $j < $copies; $j++) {
    echo '<section class="version">';
    echo '<div class="head"><h1>' . $enc($header['title']) . $enc($form($j)) . '</h1>';
    $sub = array_filter([$header['course'], $header['teacher']]);
    if ($sub) echo '<div class="sub">' . $enc(implode(' · ', $sub)) . '</div>';
    if ($header['name'] || $header['other'] !== '') {
        echo '<div class="fill">';
        if ($header['name']) echo '<span>Name: ______________________________</span>';
        if ($header['other'] !== '') echo '<span>' . $enc($header['other']) . ': ________________</span>';
        echo '</div>';
    }
    echo '</div>';
    for ($i = 0; $i < $numq; $i++) {
        echo '<div class="q"><div class="n">' . ($i + 1) . '.</div><div class="body">';
        if ($showpts) echo '<div class="pts">(' . $enc((string) $points[$questions[$i]]) . ' pt' . ($points[$questions[$i]] == 1 ? '' : 's') . ')</div>';
        echo $out[$j][$i][0] . '</div></div>';
    }
    echo '</section>';
}
if ($keys === 'end') {
    for ($j = 0; $j < $copies; $j++) {
        echo '<section class="key"><h2>Answer key — ' . $enc($header['title']) . $enc($form($j)) . '</h2><ol>';
        for ($i = 0; $i < $numq; $i++) {
            $a = trim(strip_tags($out[$j][$i][1])) === '' ? '— <i>(no single answer: grade by hand)</i>' : Sanitize::outgoingHTML($out[$j][$i][1]);
            echo '<li>' . $a;
            if ($detsoln && $out[$j][$i][2] !== '') echo '<div class="soln">' . $out[$j][$i][2] . '</div>';
            echo '</li>';
        }
        echo '</ol><p class="code">Print code ' . $code . '</p></section>';
    }
}
echo '</body></html>';
