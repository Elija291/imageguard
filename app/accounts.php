<?php
declare(strict_types=1);

function accountUsername(string $username): string {
    $username = strtolower(trim($username));
    if (!preg_match('/\A[a-z0-9_]{3,32}\z/', $username)) {
        throw new RuntimeException('Use 3–32 letters, numbers or underscores for your username.');
    }
    return $username;
}

function checkAccountPassword(string $password): void {
    // PHP 8.3 PASSWORD_DEFAULT uses bcrypt: reject rather than silently truncate.
    if (strlen($password) < 16 || strlen($password) > 72 || str_contains($password, "\0")) {
        throw new RuntimeException('Use a password between 16 and 72 bytes (16–72 characters for plain English text).');
    }
}

// Called only by the privileged startup process, never by a web request.
function initializeAccounts(PDO $pdo, string $adminPassword): void {
    checkAccountPassword($adminPassword);
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
        username VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('user','admin') NOT NULL DEFAULT 'user',
        blocked TINYINT UNSIGNED NOT NULL DEFAULT 0,
        session_version BIGINT UNSIGNED NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    $s = $pdo->prepare('SELECT * FROM users WHERE username=?');
    $s->execute(['admin']);
    $admin = $s->fetch(PDO::FETCH_ASSOC);
    if (!$admin) {
        $pdo->prepare("INSERT INTO users(id,username,password_hash,role) VALUES (?,?,?,'admin')")
            ->execute([bin2hex(random_bytes(32)), 'admin', password_hash($adminPassword, PASSWORD_DEFAULT)]);
    } elseif ($admin['role'] !== 'admin') {
        throw new RuntimeException('Reserved admin username is already in use.');
    } elseif (!password_verify($adminPassword, $admin['password_hash'])) {
        $pdo->prepare('UPDATE users SET password_hash=?, session_version=session_version+1 WHERE id=?')
            ->execute([password_hash($adminPassword, PASSWORD_DEFAULT), $admin['id']]);
    }
}

function registerAccount(string $username, string $password, string $confirmation): void {
    $username = accountUsername($username);
    checkAccountPassword($password);
    if ($password !== $confirmation) { throw new RuntimeException('Passwords do not match.'); }
    if ($username === 'admin') { throw new RuntimeException('That username is unavailable.'); }
    try {
        db()->prepare("INSERT INTO users(id,username,password_hash,role) VALUES (?,?,?,'user')")
            ->execute([bin2hex(random_bytes(32)), $username, password_hash($password, PASSWORD_DEFAULT)]);
    } catch (PDOException $ex) {
        if (($ex->errorInfo[1] ?? null) === 1062) { throw new RuntimeException('That username is unavailable.'); }
        throw $ex;
    }
}

function findLoginAccount(string $username, string $password): ?array {
    global $config;
    $username = strtolower(trim($username));
    if (strlen($username) > 32 || strlen($password) > 72 || str_contains($password, "\0")) { return null; }
    $s = db()->prepare('SELECT * FROM users WHERE username=?');
    $s->execute([$username]);
    $user = $s->fetch();
    // Verify even unknown usernames against a valid hash to reduce timing differences.
    $valid = password_verify($password, $user['password_hash'] ?? $config['dummy_password_hash']);
    if (!$user || !$valid || (int)$user['blocked'] !== 0) { return null; }
    unset($user['password_hash']);
    return $user;
}

function resetAccountSession(): void {
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $_SESSION['last_seen'] = time();
}

function currentAccount(): ?array {
    if (!isset($_SESSION['user_id'], $_SESSION['user_version'])) { return null; }
    $s = db()->prepare('SELECT id,username,role,blocked,session_version,created_at FROM users WHERE id=?');
    $s->execute([$_SESSION['user_id']]);
    $user = $s->fetch();
    if (!$user || (int)$user['blocked'] !== 0 || (string)$user['session_version'] !== (string)$_SESSION['user_version']) {
        resetAccountSession();
        return null;
    }
    return $user;
}

// Hold the user's row lock while writing results. Deletion/blocking and result
// writes then serialize, so an in-flight analysis cannot recreate deleted history.
function lockActiveAccount(PDO $pdo, array $user): void {
    $s = $pdo->prepare('SELECT role,blocked,session_version FROM users WHERE id=? FOR UPDATE');
    $s->execute([$user['id']]);
    $fresh = $s->fetch();
    if (!$fresh || (int)$fresh['blocked'] !== 0 || (string)$fresh['session_version'] !== (string)$user['session_version']) {
        http_response_code(401);
        throw new RuntimeException('Your session is no longer valid. Please sign in again.');
    }
}

function manageAccount(array $actor, string $action, string $targetId, bool $confirmed): void {
    if ($actor['role'] !== 'admin') {
        http_response_code(403); throw new RuntimeException('Administrator access required.');
    }
    if (!in_array($action, ['block_user','unblock_user','delete_user'], true) ||
        !preg_match('/\A[a-f0-9]{64}\z/', $targetId)) {
        http_response_code(400); throw new RuntimeException('Invalid account action.');
    }
    if ($action === 'delete_user' && !$confirmed) {
        throw new RuntimeException('Confirm deletion of the user and their saved history.');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        lockActiveAccount($pdo, $actor);
        // Recheck the role from the database, never trust a submitted role.
        $s = $pdo->prepare('SELECT role FROM users WHERE id=?');
        $s->execute([$actor['id']]);
        if ($s->fetchColumn() !== 'admin') { http_response_code(403); throw new RuntimeException('Administrator access required.'); }
        $s = $pdo->prepare('SELECT role FROM users WHERE id=? FOR UPDATE');
        $s->execute([$targetId]);
        if ($s->fetchColumn() !== 'user') {
            http_response_code(400); throw new RuntimeException('User not found or account is protected.');
        }
        if ($action === 'delete_user') {
            $pdo->prepare('DELETE FROM analyses WHERE owner=?')->execute([$targetId]);
            $pdo->prepare("DELETE FROM users WHERE id=? AND role='user'")->execute([$targetId]);
        } else {
            $pdo->prepare("UPDATE users SET blocked=?,session_version=session_version+1 WHERE id=? AND role='user'")
                ->execute([$action === 'block_user' ? 1 : 0, $targetId]);
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $ex;
    }
}
