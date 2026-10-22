<?php
declare(strict_types=1);
$db = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE DATABASE IF NOT EXISTS imageguard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$db->exec("USE imageguard");
$db->exec("CREATE TABLE IF NOT EXISTS analyses (
 id CHAR(32) PRIMARY KEY, owner CHAR(64) NOT NULL, sha256 CHAR(64) NOT NULL,
 mime VARCHAR(32) NOT NULL, width INT UNSIGNED NOT NULL, height INT UNSIGNED NOT NULL,
 score DOUBLE NULL, verdict VARCHAR(32) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX owner_time(owner, created_at), INDEX created_time(created_at)
)");
$db->exec("CREATE TABLE IF NOT EXISTS rate_limits (
 bucket CHAR(64) PRIMARY KEY, hits INT UNSIGNED NOT NULL, expires BIGINT NOT NULL, INDEX expiry(expires)
)");
$password = bin2hex(random_bytes(32));
$db->exec("CREATE USER IF NOT EXISTS 'imageguard'@'localhost' IDENTIFIED BY '$password'");
$db->exec("ALTER USER 'imageguard'@'localhost' IDENTIFIED BY '$password'");
$db->exec("GRANT SELECT, INSERT, DELETE ON imageguard.analyses TO 'imageguard'@'localhost'");
$db->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON imageguard.rate_limits TO 'imageguard'@'localhost'");
$old = is_file('/var/lib/mysql/imageguard-rate-key') ? trim(file_get_contents('/var/lib/mysql/imageguard-rate-key')) : bin2hex(random_bytes(32));
file_put_contents('/var/lib/mysql/imageguard-rate-key', $old); chmod('/var/lib/mysql/imageguard-rate-key', 0600);
$config = [
 'db_password' => $password, 'password_hash' => password_hash(getenv('APP_PASSWORD'), PASSWORD_DEFAULT),
 'rate_key' => $old, 'api_user' => getenv('SIGHTENGINE_API_USER') ?: '',
 'api_secret' => getenv('SIGHTENGINE_API_SECRET') ?: '', 'secure_cookie' => getenv('COOKIE_SECURE') === '1',
 'daily_limit' => max(1, min(10000, (int)(getenv('DAILY_API_LIMIT') ?: 100)))
];
file_put_contents('/run/imageguard/config.php', '<?php return '.var_export($config, true).';');
chgrp('/run/imageguard/config.php', 'www-data'); chmod('/run/imageguard/config.php', 0640);
echo "Database and private configuration ready.\n";
