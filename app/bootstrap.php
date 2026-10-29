<?php
declare(strict_types=1);
require_once __DIR__.'/detector.php';
require_once __DIR__.'/accounts.php';
$config = require '/run/imageguard/config.php';
function db(): PDO {
    global $config;
    static $pdo;
    if (!$pdo) {
        $pdo = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=imageguard;charset=utf8mb4', 'imageguard', $config['db_password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}
function e(mixed $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function field(string $key): string { return isset($_POST[$key]) && is_string($_POST[$key]) ? $_POST[$key] : ''; }
function rateLimit(string $scope, int $limit, int $seconds): bool {
    global $config;
    $now = time(); $slot = intdiv($now, $seconds);
    $key = hash_hmac('sha256', $scope.':'.$slot, $config['rate_key']);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT IGNORE INTO rate_limits(bucket,hits,expires) VALUES (?,0,?)');
        $stmt->execute([$key, ($slot+1)*$seconds]);
        $stmt = $pdo->prepare('SELECT hits FROM rate_limits WHERE bucket=? FOR UPDATE');
        $stmt->execute([$key]); $hits = (int)$stmt->fetchColumn();
        if ($hits >= $limit) { $pdo->rollBack(); return false; }
        $pdo->prepare('UPDATE rate_limits SET hits=hits+1 WHERE bucket=?')->execute([$key]);
        $pdo->commit(); return true;
    } catch (Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $e; }
}
header('Cache-Control: no-store');
session_name('imageguard_session');
session_set_cookie_params(['httponly'=>true, 'secure'=>$config['secure_cookie'], 'samesite'=>'Strict', 'path'=>'/']);
session_start();
if (isset($_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > 1800) {
    $_SESSION=[]; session_regenerate_id(true);
}
$_SESSION['last_seen']=time();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
// Legacy shared-password sessions do not authorize access to user accounts.
unset($_SESSION['auth'], $_SESSION['owner']);
