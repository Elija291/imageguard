<?php
declare(strict_types=1);
try { require '/opt/imageguard/app/bootstrap.php'; }
catch (Throwable $ex) { http_response_code(503); exit('Service temporarily unavailable.'); }
$error = ''; $result = null;
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf'], field('csrf'))) {
            http_response_code(403); throw new RuntimeException('Request expired or invalid. Reload the page and try again.');
        }
        $action=field('action');
        if ($action === 'login') {
            $ip=$_SERVER['REMOTE_ADDR'] ?? 'unknown';
            if (!rateLimit('login:'.$ip, 10, 900) || !rateLimit('login:global', 100, 900)) {
                http_response_code(429); throw new RuntimeException('Too many sign-in attempts. Try again in 15 minutes.');
            }
            if (!password_verify(field('password'), $config['password_hash'])) {
                http_response_code(401); throw new RuntimeException('Incorrect password.');
            }
            session_regenerate_id(true); $_SESSION['auth']=true; $_SESSION['csrf']=bin2hex(random_bytes(32));
            header('Location: /', true, 303); exit;
        }
        if (empty($_SESSION['auth'])) { http_response_code(401); throw new RuntimeException('Please sign in.'); }
        if ($action === 'logout') {
            $_SESSION=[]; session_regenerate_id(true); header('Location: /', true, 303); exit;
        }
        if ($action === 'clear') {
            db()->prepare('DELETE FROM analyses WHERE owner=?')->execute([$_SESSION['owner']]);
            header('Location: /', true, 303); exit;
        }
        if ($action !== 'analyse') { http_response_code(400); throw new RuntimeException('Unknown action.'); }
        if (field('consent') !== 'yes') { throw new RuntimeException('Consent is required before sending your image to Sightengine.'); }
        if ($config['api_user'] === '' || $config['api_secret'] === '') { throw new RuntimeException('Add Sightengine API credentials to enable automated detection.'); }
        $file=$_FILES['image'] ?? null;
        if (!$file || !isset($file['error']) || !is_int($file['error']) || $file['error'] !== UPLOAD_ERR_OK ||
            !isset($file['tmp_name']) || !is_string($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Upload failed. Choose one JPEG, PNG or WebP image up to 8 MiB.');
        }
        try {
            $info=inspectImage($file['tmp_name']);
            if (!rateLimit('analysis:'.($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 5, 60) ||
                !rateLimit('analysis:global', $config['daily_limit'], 86400)) {
                http_response_code(429); throw new RuntimeException('Analysis limit reached. Please try later.');
            }
            $score=detectImage($file['tmp_name'], $info['mime'], $config);
            $id=bin2hex(random_bytes(16));
            db()->prepare('INSERT INTO analyses(id,owner,sha256,mime,width,height,score,verdict) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$id,$_SESSION['owner'],$info['sha256'],$info['mime'],$info['width'],$info['height'],$score,verdict($score)]);
            db()->exec('DELETE FROM analyses WHERE created_at < UTC_TIMESTAMP() - INTERVAL 7 DAY');
            db()->prepare('DELETE FROM rate_limits WHERE expires < ?')->execute([time()]);
        } finally { if (isset($file['tmp_name']) && is_file($file['tmp_name'])) { unlink($file['tmp_name']); } }
        header('Location: /?result='.$id, true, 303); exit;
    }
    if (!empty($_SESSION['auth']) && isset($_GET['result']) && is_string($_GET['result']) && preg_match('/\A[a-f0-9]{32}\z/', $_GET['result'])) {
        $s=db()->prepare('SELECT * FROM analyses WHERE id=? AND owner=?'); $s->execute([$_GET['result'],$_SESSION['owner']]);
        $result=$s->fetch() ?: null;
    }
} catch (PDOException $ex) { $error='Database temporarily unavailable.'; http_response_code(503); }
catch (RuntimeException $ex) { $error=$ex->getMessage(); }
catch (Throwable $ex) { error_log('ImageGuard internal failure: '.get_class($ex)); $error='Service temporarily unavailable. No result could be saved.'; http_response_code(503); }
$history=[];
if (!empty($_SESSION['auth'])) {
    try { $s=db()->prepare('SELECT * FROM analyses WHERE owner=? ORDER BY created_at DESC LIMIT 10'); $s->execute([$_SESSION['owner']]); $history=$s->fetchAll(); }
    catch (Throwable $ex) { $error='History temporarily unavailable.'; }
}
$ready=$config['api_user'] !== '' && $config['api_secret'] !== '';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>ImageGuard | Image authenticity</title><link rel="stylesheet" href="/style.css"></head><body>
<header><a class="brand" href="/">◈ ImageGuard</a><span>IMAGE AUTHENTICITY LAB</span><?php if (!empty($_SESSION['auth'])): ?><form method="post"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><button class="quiet" name="action" value="logout">Sign out</button></form><?php endif ?></header>
<main><p class="eyebrow">LOOK CLOSER. QUESTION THE SOURCE.</p><h1>A clearer picture<br>of what’s real.</h1><p class="intro">Check an image for signs of AI generation. Understand the evidence before drawing a conclusion.</p>
<?php if ($error): ?><div role="alert" class="notice error"><?=e($error)?></div><?php endif ?>
<?php if (empty($_SESSION['auth'])): ?>
<section class="card login"><p class="eyebrow">PRIVATE WORKSPACE</p><h2>Sign in to analyse</h2><p>Enter the password configured by your administrator.</p><form method="post"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><label for="password">Workspace password</label><input id="password" name="password" type="password" required maxlength="256" autocomplete="current-password"><button name="action" value="login">Open workspace →</button></form></section>
<?php else: ?>
<div class="grid"><section class="card"><div class="heading"><h2>01 / Upload an image</h2><span class="badge"><?=$ready?'Detector connected*':'Setup needed'?></span></div>
<?php if (!$ready): ?><div class="notice">Automated detection needs Sightengine API credentials. See README.md. No results are fabricated when the detector is unavailable.</div><?php endif ?>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="MAX_FILE_SIZE" value="8388608"><label class="upload" for="image"><strong>Choose a picture</strong><span>JPEG, PNG or WebP · up to 8 MiB<br>8 megapixels maximum</span><input id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp" required></label><label class="consent"><input type="checkbox" name="consent" value="yes" required> <span>I agree to send this image to Sightengine for analysis. Its privacy and retention policies apply.</span></label><button name="action" value="analyse" <?=$ready?'':'disabled'?>>Analyse image →</button></form><p class="small">* Credentials are configured; availability is checked when you submit. The original upload is deleted after the request.</p></section>
<section class="card result"><h2>02 / Read the evidence</h2><?php if ($result): ?><p class="eyebrow">SIGHTENGINE · GENAI</p><h3><?=e($result['verdict'])?></h3><div class="score"><?=e(number_format((float)$result['score']*100,1))?><span>%</span></div><p>Provider AI-generation score</p><meter min="0" max="1" value="<?=e($result['score'])?>">AI score</meter><p class="small">Thresholds: ≤20% likely non-AI, ≥80% likely AI-generated; between these, uncertain. These thresholds are project choices, not a validated accuracy guarantee.</p><dl><dt>Dimensions</dt><dd><?=e($result['width'])?> × <?=e($result['height'])?></dd><dt>File type</dt><dd><?=e($result['mime'])?></dd><dt>SHA-256</dt><dd class="hash"><?=e($result['sha256'])?></dd></dl><?php else: ?><div class="empty">◈</div><h3>Evidence starts here.</h3><p>Your result will appear after analysis. A low AI score does not prove that a photograph is authentic.</p><?php endif ?></section></div>
<section class="card"><div class="heading"><h2>Recent checks</h2><form method="post"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><button class="quiet" name="action" value="clear">Clear my history</button></form></div><p class="small">This browser session only · last 10 checks · records older than 7 days are pruned on a successful analysis.</p><?php if (!$history): ?><p>No checks yet.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Time (UTC)</th><th>Result</th><th>AI score</th><th>View</th></tr></thead><tbody><?php foreach ($history as $row): ?><tr><td><?=e($row['created_at'])?></td><td><?=e($row['verdict'])?></td><td><?=e(number_format((float)$row['score']*100,1))?>%</td><td><a href="/?result=<?=e($row['id'])?>">Details →</a></td></tr><?php endforeach ?></tbody></table></div><?php endif ?></section>
<?php endif ?>
<section class="notes"><div><p class="eyebrow">A SECOND KIND OF EVIDENCE</p><h2>Verify a SynthID watermark</h2><p>SynthID checks for an embedded watermark from participating generators. This app does not decode SynthID or equate metadata with a verified watermark.</p><a href="https://synthid.com/" target="_blank" rel="noopener noreferrer">Open Google’s SynthID Detector ↗</a><p class="small">Upload the original there yourself. No image is transferred by this link.</p></div><div><p class="eyebrow">KNOW THE LIMITS</p><h2>Detection is an estimate.</h2><p>Compression, editing and unfamiliar generators can cause mistakes. Missing watermarks do not establish human authorship. Review provenance and context alongside these results.</p></div></section>
</main><footer>ImageGuard / Raw PHP · Ubuntu · Apache · MySQL <span>Built for evidence, not certainty.</span></footer></body></html>
