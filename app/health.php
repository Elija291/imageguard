<?php
try {
    $c = require '/run/imageguard/config.php';
    $pdo = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=imageguard', 'imageguard', $c['db_password']);
    $pdo->query('SELECT 1');
    $ch = curl_init('http://127.0.0.1/');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>3]);
    $body=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_RESPONSE_CODE); curl_close($ch);
    exit($body !== false && $code === 200 ? 0 : 1);
} catch (Throwable $e) { exit(1); }
