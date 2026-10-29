<?php
// Uses a new, randomly named database and drops only that database on completion.
// Run as the container's local root: php /opt/imageguard/tests/accounts.php
// No production accounts, history, credentials or provider API are used.
declare(strict_types=1);
require dirname(__DIR__).'/app/accounts.php';
$dsn = getenv('IMAGEGUARD_TEST_DSN') ?: 'mysql:unix_socket=/run/mysqld/mysqld.sock';
$pdo = new PDO($dsn, 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
$database = 'imageguard_test_'.bin2hex(random_bytes(8));
$pdo->exec('CREATE DATABASE `'.$database.'`');
$pdo->exec('USE `'.$database.'`');
function db(): PDO { global $pdo; return $pdo; }
$config = ['dummy_password_hash'=>password_hash('dummy-password-for-tests', PASSWORD_DEFAULT)];
$count=0;
function check(bool $ok, string $label): void { global $count; if (!$ok) { throw new RuntimeException('FAIL '.$label); } ++$count; echo 'PASS '.$label."\n"; }
function rejects(callable $fn, string $label): void { try { $fn(); } catch (RuntimeException $ex) { check(true, $label); return; } check(false,$label); }
function account(string $name): array { $s=db()->prepare('SELECT * FROM users WHERE username=?'); $s->execute([$name]); return $s->fetch() ?: []; }
// Buffer output because session invalidation sends headers.
ob_start();
$sessionDir = sys_get_temp_dir().'/'.$database;
mkdir($sessionDir, 0700);
session_save_path($sessionDir);
if (!session_start()) { throw new RuntimeException('Cannot start test session.'); }
try {
    $pdo->exec('CREATE TABLE analyses(id CHAR(32) PRIMARY KEY, owner CHAR(64) NOT NULL) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO analyses VALUES ('legacy', 'old-browser-owner')");
    $password = 'user-password-for-tests'; $adminPassword='admin-password-for-tests';
    initializeAccounts($pdo, $adminPassword);
    $admin = account('admin');
    check($admin['role']==='admin' && password_verify($adminPassword,$admin['password_hash']), 'admin seeded with configured password');
    check($pdo->query("SELECT COUNT(*) FROM analyses WHERE id='legacy'")->fetchColumn()==1,'migration retains old analyses');
    initializeAccounts($pdo, $adminPassword);
    check(account('admin')['id']===$admin['id'] && account('admin')['session_version']===$admin['session_version'],'restart keeps admin identity and sessions when password unchanged');
    check(accountUsername(' Alice_12 ')==='alice_12','username normalization');
    foreach (['ab',str_repeat('a',33),'<script>',"user' OR 1=1",'with space'] as $name) {
        rejects(fn()=>accountUsername($name),'invalid username rejected');
    }
    foreach (['short',str_repeat('a',73),"long-password\0not-allowed"] as $value) {
        rejects(fn()=>checkAccountPassword($value),'invalid password rejected');
    }
    checkAccountPassword(str_repeat('a',16)); checkAccountPassword(str_repeat('a',72));
    check(true,'password length boundaries accepted');
    rejects(fn()=>registerAccount('alice',$password,'different'),'password confirmation checked');
    rejects(fn()=>registerAccount('ADMIN',$password,$password),'admin username reserved');
    registerAccount('Alice',$password,$password);
    registerAccount('bob',$password,$password);
    $alice=account('alice'); $bob=account('bob');
    check($alice['role']==='user' && $alice['password_hash']!==$password && password_verify($password,$alice['password_hash']),'registered password hashed and role ordinary user');
    rejects(fn()=>registerAccount('ALICE',$password,$password),'case-insensitive duplicate rejected');
    check(findLoginAccount('ALICE',$password)['id']===$alice['id'],'case-insensitive login');
    check(findLoginAccount('alice','wrong')===null && findLoginAccount('missing',$password)===null,'invalid credentials rejected');
    check(findLoginAccount("alice' OR 1=1 --",$password)===null,'login SQL injection rejected');
    $_SESSION=['auth'=>true,'owner'=>$alice['id']];
    check(currentAccount()===null,'legacy shared-password session grants no account access');
    $_SESSION=['user_id'=>$alice['id'],'user_version'=>$alice['session_version']];
    check(currentAccount()['id']===$alice['id'],'valid session resolves to account');
    rejects(fn()=>manageAccount($alice,'block_user',$bob['id'],false),'ordinary user cannot manage users');
    check((int)account('bob')['blocked']===0,'denied admin action has no side effects');
    foreach (['block_user','unblock_user','delete_user'] as $action) {
        rejects(fn()=>manageAccount($admin,$action,$admin['id'],true),'administrator protected from '.$action);
    }
    $pdo->prepare('INSERT INTO analyses VALUES (?,?)')->execute(['alice-result',$alice['id']]);
    $pdo->prepare('INSERT INTO analyses VALUES (?,?)')->execute(['bob-result',$bob['id']]);
    manageAccount($admin,'block_user',$alice['id'],false);
    check(findLoginAccount('alice',$password)===null,'blocked account cannot log in');
    check(currentAccount()===null && !isset($_SESSION['user_id']),'blocked account session invalidated');
    $pdo->beginTransaction();
    rejects(fn()=>lockActiveAccount($pdo,$alice),'in-flight blocked account cannot save result');
    $pdo->rollBack();
    manageAccount($admin,'unblock_user',$alice['id'],false);
    $_SESSION=['user_id'=>$alice['id'],'user_version'=>$alice['session_version']];
    check(currentAccount()===null,'unblocking does not revive stale sessions');
    check(findLoginAccount('alice',$password)!==null,'unblocked account can log in again');
    $alice=account('alice');
    $_SESSION=['user_id'=>$alice['id'],'user_version'=>$alice['session_version']];
    check(currentAccount()!==null,'fresh session accepted after unblock');
    rejects(fn()=>manageAccount($admin,'delete_user',$alice['id'],false),'deletion needs explicit confirmation');
    check(account('alice')!==[],'unconfirmed deletion retains account');
    manageAccount($admin,'delete_user',$alice['id'],true);
    check(account('alice')===[] && currentAccount()===null,'deletion removes account and invalidates session');
    check($pdo->query("SELECT COUNT(*) FROM analyses WHERE id='alice-result'")->fetchColumn()==0,'deletion removes own history');
    check($pdo->query("SELECT COUNT(*) FROM analyses WHERE id='bob-result'")->fetchColumn()==1,'deletion preserves other users history');
    $pdo->beginTransaction(); rejects(fn()=>lockActiveAccount($pdo,$alice),'deleted in-flight account rejected'); $pdo->rollBack();
    registerAccount('alice',$password,$password);
    check(account('alice')['id']!==$alice['id'],'re-registration receives new identity');
    $_SESSION=['user_id'=>$alice['id'],'user_version'=>$alice['session_version']];
    check(currentAccount()===null,'deleted session cannot enter re-registered account');
    initializeAccounts($pdo, 'replacement-admin-password');
    check(findLoginAccount('admin',$adminPassword)===null && findLoginAccount('admin','replacement-admin-password')!==null,'environment password change updates admin login');
    $_SESSION=['user_id'=>$admin['id'],'user_version'=>$admin['session_version']];
    check(currentAccount()===null,'admin password rotation invalidates old sessions');
    check(password_verify($password,account('bob')['password_hash']),'startup preserves ordinary user password');
    echo "$count account checks passed. No external API calls were made.\n";
} finally {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $pdo->exec('DROP DATABASE `'.$database.'`');
    session_destroy();
    foreach (glob($sessionDir.'/sess_*') as $file) { unlink($file); }
    rmdir($sessionDir);
    ob_end_flush();
}
