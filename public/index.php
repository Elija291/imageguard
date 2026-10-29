<?php
declare(strict_types=1);
try { require '/opt/imageguard/app/bootstrap.php'; }
catch (Throwable $ex) { http_response_code(503); exit('Service temporarily unavailable.'); }
$error = ''; $result = null; $user = null; $users = []; $hasMoreUsers = false;
$adminPage = isset($_GET['admin']) && $_GET['admin'] === '1';
$registerPage = isset($_GET['register']) && $_GET['register'] === '1';
$page = isset($_GET['page']) && is_string($_GET['page']) ? max(1, min(100000, (int)$_GET['page'])) : 1;
try {
    $user = currentAccount();
    if ($adminPage && (!$user || $user['role'] !== 'admin')) {
        http_response_code(403); throw new RuntimeException('Administrator access required.');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf'], field('csrf'))) {
            http_response_code(403); throw new RuntimeException('Request expired or invalid. Reload the page and try again.');
        }
        $action=field('action');
        if ($action === 'register' || $action === 'login') {
            if ($user) { http_response_code(400); throw new RuntimeException('Sign out before using another account.'); }
            $ip=$_SERVER['REMOTE_ADDR'] ?? 'unknown';
            if ($action === 'register') {
                $registerPage = true;
                if (!rateLimit('register:'.$ip, 3, 3600) || !rateLimit('register:global', 30, 3600)) {
                    http_response_code(429); throw new RuntimeException('Registration limit reached. Please try later.');
                }
                registerAccount(field('username'), field('password'), field('password_confirm'));
                resetAccountSession();
                header('Location: /?registered=1', true, 303); exit;
            }
            if (!rateLimit('login:'.$ip, 10, 900) || !rateLimit('login:global', 100, 900)) {
                http_response_code(429); throw new RuntimeException('Too many sign-in attempts. Try again later.');
            }
            $account = findLoginAccount(field('username'), field('password'));
            if (!$account) {
                http_response_code(401); throw new RuntimeException('Incorrect credentials or account unavailable.');
            }
            resetAccountSession();
            $_SESSION['user_id'] = $account['id'];
            $_SESSION['user_version'] = (string)$account['session_version'];
            header('Location: /', true, 303); exit;
        }
        if (!$user) { http_response_code(401); throw new RuntimeException('Please sign in.'); }
        if ($action === 'logout') {
            resetAccountSession(); header('Location: /', true, 303); exit;
        }
        if (in_array($action, ['block_user','unblock_user','delete_user'], true)) {
            manageAccount($user, $action, field('user_id'), field('confirm_delete') === 'yes');
            header('Location: /?admin=1&updated=1', true, 303); exit;
        }
        if ($action === 'clear') {
            db()->prepare('DELETE FROM analyses WHERE owner=?')->execute([$user['id']]);
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
            $pdo = db();
            $pdo->beginTransaction();
            try {
                lockActiveAccount($pdo, $user);
                $pdo->prepare('INSERT INTO analyses(id,owner,sha256,mime,width,height,score,verdict) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([$id,$user['id'],$info['sha256'],$info['mime'],$info['width'],$info['height'],$score,verdict($score)]);
                $pdo->commit();
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                throw $ex;
            }
            db()->exec('DELETE FROM analyses WHERE created_at < UTC_TIMESTAMP() - INTERVAL 7 DAY');
            db()->prepare('DELETE FROM rate_limits WHERE expires < ?')->execute([time()]);
        } finally { if (isset($file['tmp_name']) && is_file($file['tmp_name'])) { unlink($file['tmp_name']); } }
        header('Location: /?result='.$id, true, 303); exit;
    }
    if ($user !== null && isset($_GET['result']) && is_string($_GET['result']) && preg_match('/\A[a-f0-9]{32}\z/', $_GET['result'])) {
        $s=db()->prepare('SELECT * FROM analyses WHERE id=? AND owner=?'); $s->execute([$_GET['result'],$user['id']]);
        $result=$s->fetch() ?: null;
    }
    if ($adminPage && $user && $user['role'] === 'admin') {
        $offset = ($page - 1) * 50;
        $s = db()->query('SELECT id,username,role,blocked,created_at FROM users ORDER BY created_at DESC,id LIMIT 51 OFFSET '.$offset);
        $users = $s->fetchAll();
        $hasMoreUsers = count($users) > 50;
        if ($hasMoreUsers) { array_pop($users); }
    }
} catch (PDOException $ex) { $error='Database temporarily unavailable.'; http_response_code(503); }
catch (RuntimeException $ex) { $error=$ex->getMessage(); }
catch (Throwable $ex) { error_log('ImageGuard internal failure: '.get_class($ex)); $error='Service temporarily unavailable. No result could be saved.'; http_response_code(503); }
$history=[];
if ($user !== null && !$adminPage) {
    try { $s=db()->prepare('SELECT * FROM analyses WHERE owner=? ORDER BY created_at DESC LIMIT 10'); $s->execute([$user['id']]); $history=$s->fetchAll(); }
    catch (Throwable $ex) { $error='History temporarily unavailable.'; }
}
$ready=$config['api_user'] !== '' && $config['api_secret'] !== '';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>ImageGuard | Image authenticity</title><link rel="stylesheet" href="/style.css"></head><body>
<header><a class="brand" href="/">◈ ImageGuard</a><span>IMAGE AUTHENTICITY LAB</span><?php if ($user !== null): ?><a href="/">My workspace</a><?php if ($user['role'] === 'admin'): ?><a href="/?admin=1">Manage users</a><?php endif ?><span class="account-name"><?=e($user['username'])?></span><form method="post" action="/"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><button class="quiet" name="action" value="logout">Sign out</button></form><?php endif ?></header>
<main><p class="eyebrow">LOOK CLOSER. QUESTION THE SOURCE.</p><h1>A clearer picture<br>of what’s real.</h1><p class="intro">Check an image for signs of AI generation. Understand the evidence before drawing a conclusion.</p>
<?php if ($error): ?><div role="alert" class="notice error"><?=e($error)?></div><?php endif ?>
<?php if ($user === null): ?>
<?php if (isset($_GET['registered']) && $_GET['registered'] === '1'): ?><div class="notice">Account created. Sign in with your username and password.</div><?php endif ?>
<section class="card login"><p class="eyebrow">YOUR ACCOUNT</p><h2><?=$registerPage?'Create an account':'Sign in to analyse'?></h2>
<form method="post" action="<?=$registerPage?'/?register=1':'/'?>">
<input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>">
<label for="username">Username</label><input id="username" name="username" type="text" required minlength="3" maxlength="32" pattern="[A-Za-z0-9_]{3,32}" autocomplete="username" value="<?=e(field('username'))?>">
<?php if ($registerPage): ?><p class="small">3–32 letters, numbers or underscores. Usernames are not case-sensitive.</p><?php endif ?>
<label for="password">Password</label><input id="password" name="password" type="password" required <?=$registerPage?'minlength="16"':''?> maxlength="72" autocomplete="<?=$registerPage?'new-password':'current-password'?>">
<?php if ($registerPage): ?><p class="small">16–72 bytes; use a long, unique password. Non-English characters may use multiple bytes.</p><label for="password_confirm">Confirm password</label><input id="password_confirm" name="password_confirm" type="password" required minlength="16" maxlength="72" autocomplete="new-password"><?php endif ?>
<button name="action" value="<?=$registerPage?'register':'login'?>"><?=$registerPage?'Create account':'Sign in'?> →</button></form>
<p><?=$registerPage?'Already registered?':'New here?'?> <a href="<?=$registerPage?'/':'/?register=1'?>"><?=$registerPage?'Sign in':'Create an account'?></a></p></section>
<?php elseif ($adminPage): ?>
<?php if ($user['role'] === 'admin'): ?>
<section class="card"><h2>Manage users</h2>
<?php if (isset($_GET['updated']) && $_GET['updated'] === '1'): ?><div class="notice">Account updated.</div><?php endif ?>
<p>Blocking prevents sign-in and invalidates existing sessions. Unblocking requires a fresh sign-in. Deleting removes the account and its saved history.</p>
<p class="small">A deleted username can be registered again. To keep an account disabled, block it instead. New accounts can still be registered with other usernames.</p>
<div class="table-wrap"><table><thead><tr><th>Username</th><th>Role</th><th>Status</th><th>Created (UTC)</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($users as $member): ?><tr><td><?=e($member['username'])?></td><td><?=e($member['role'])?></td><td><?=(int)$member['blocked']?'Blocked':'Active'?></td><td><?=e($member['created_at'])?></td><td>
<?php if ($member['role'] !== 'admin'): ?>
<form method="post" action="/?admin=1" class="account-action"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="user_id" value="<?=e($member['id'])?>"><button name="action" value="<?=(int)$member['blocked']?'unblock_user':'block_user'?>"><?=(int)$member['blocked']?'Unblock':'Block'?></button></form>
<details><summary>Delete account</summary><form method="post" action="/?admin=1" class="account-action"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="user_id" value="<?=e($member['id'])?>"><label><input type="checkbox" name="confirm_delete" value="yes" required> Permanently delete <?=e($member['username'])?> and their history</label><button name="action" value="delete_user">Delete user</button></form></details>
<?php else: ?>Protected administrator<?php endif ?></td></tr><?php endforeach ?>
</tbody></table></div><p>Page <?=e($page)?> <?php if ($page > 1): ?><a href="/?admin=1&amp;page=<?=e($page-1)?>">Previous</a><?php endif ?> <?php if ($hasMoreUsers): ?><a href="/?admin=1&amp;page=<?=e($page+1)?>">Next</a><?php endif ?></p></section>
<?php endif ?>
<?php else: ?>
<div class="grid"><section class="card"><div class="heading"><h2>01 / Upload an image</h2><span class="badge"><?=$ready?'Detector connected*':'Setup needed'?></span></div>
<?php if (!$ready): ?><div class="notice">Automated detection needs Sightengine API credentials. See README.md. No results are fabricated when the detector is unavailable.</div><?php endif ?>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="MAX_FILE_SIZE" value="8388608"><label class="upload" for="image"><strong>Choose a picture</strong><span>JPEG, PNG or WebP · up to 8 MiB<br>8 megapixels maximum</span><input id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp" required></label><label class="consent"><input type="checkbox" name="consent" value="yes" required> <span>I agree to send this image to Sightengine for analysis. Its privacy and retention policies apply.</span></label><button name="action" value="analyse" <?=$ready?'':'disabled'?>>Analyse image →</button></form><p class="small">* Credentials are configured; availability is checked when you submit. The original upload is deleted after the request.</p></section>
<section class="card result"><h2>02 / Read the evidence</h2><?php if ($result): ?><p class="eyebrow">SIGHTENGINE · GENAI</p><h3><?=e($result['verdict'])?></h3><div class="score"><?=e(number_format((float)$result['score']*100,1))?><span>%</span></div><p>Provider AI-generation score</p><meter min="0" max="1" value="<?=e($result['score'])?>">AI score</meter><p class="small">Thresholds: ≤20% likely non-AI, ≥80% likely AI-generated; between these, uncertain. These thresholds are project choices, not a validated accuracy guarantee.</p><dl><dt>Dimensions</dt><dd><?=e($result['width'])?> × <?=e($result['height'])?></dd><dt>File type</dt><dd><?=e($result['mime'])?></dd><dt>SHA-256</dt><dd class="hash"><?=e($result['sha256'])?></dd></dl><?php else: ?><div class="empty">◈</div><h3>Evidence starts here.</h3><p>Your result will appear after analysis. A low AI score does not prove that a photograph is authentic.</p><?php endif ?></section></div>
<section class="card"><div class="heading"><h2>Recent checks</h2><form method="post"><input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><button class="quiet" name="action" value="clear">Clear my history</button></form></div><p class="small">Your account only · last 10 checks · records older than 7 days are pruned on a successful analysis.</p><?php if (!$history): ?><p>No checks yet.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Time (UTC)</th><th>Result</th><th>AI score</th><th>View</th></tr></thead><tbody><?php foreach ($history as $row): ?><tr><td><?=e($row['created_at'])?></td><td><?=e($row['verdict'])?></td><td><?=e(number_format((float)$row['score']*100,1))?>%</td><td><a href="/?result=<?=e($row['id'])?>">Details →</a></td></tr><?php endforeach ?></tbody></table></div><?php endif ?></section>
<?php endif ?>
<section class="notes"><div><p class="eyebrow">A SECOND KIND OF EVIDENCE</p><h2>Verify a SynthID watermark</h2><p>SynthID checks for an embedded watermark from participating generators. This app does not decode SynthID or equate metadata with a verified watermark.</p><a href="https://synthid.com/" target="_blank" rel="noopener noreferrer">Open Google’s SynthID Detector ↗</a><p class="small">Upload the original there yourself. No image is transferred by this link.</p></div><div><p class="eyebrow">KNOW THE LIMITS</p><h2>Detection is an estimate.</h2><p>Compression, editing and unfamiliar generators can cause mistakes. Missing watermarks do not establish human authorship. Review provenance and context alongside these results.</p></div></section>
</main><footer>ImageGuard / Raw PHP · Ubuntu · Apache · MySQL <span>Built for evidence, not certainty.</span></footer></body></html>
