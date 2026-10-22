<?php
declare(strict_types=1);

function verdict(float $score): string {
    if (!is_finite($score) || $score < 0 || $score > 1) {
        throw new RuntimeException('Invalid detector score.');
    }
    return $score >= 0.80 ? 'Likely AI-generated' : ($score <= 0.20 ? 'Likely non-AI' : 'Uncertain');
}

function parseDetectorResponse(string $json): float {
    try { $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException $e) { throw new RuntimeException('Detector returned an invalid response.'); }
    $score = $data['type']['ai_generated'] ?? null;
    if (($data['status'] ?? '') !== 'success' || (!is_float($score) && !is_int($score))) {
        throw new RuntimeException('Detector did not return a valid result.');
    }
    verdict((float)$score);
    return (float)$score;
}

function inspectImage(string $path): array {
    $size = filesize($path);
    if ($size === false || $size < 1 || $size > 8 * 1024 * 1024) {
        throw new RuntimeException('Choose an image no larger than 8 MiB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $types = ['image/jpeg' => IMAGETYPE_JPEG, 'image/png' => IMAGETYPE_PNG, 'image/webp' => IMAGETYPE_WEBP];
    if (!isset($types[$mime])) { throw new RuntimeException('Only JPEG, PNG and WebP images are accepted.'); }
    $info = @getimagesize($path);
    if (!$info || $info[2] !== $types[$mime] || $info[0] < 1 || $info[1] < 1 ||
        $info[0] > 6000 || $info[1] > 6000 || $info[0] * $info[1] > 8000000) {
        throw new RuntimeException('Invalid image or dimensions exceed 6,000 pixels per side / 8 megapixels.');
    }
    // Decode only after dimension and size checks. Originals are never served or executed.
    $image = @imagecreatefromstring(file_get_contents($path));
    if ($image === false) { throw new RuntimeException('The image could not be decoded.'); }
    imagedestroy($image);
    return ['mime' => $mime, 'width' => $info[0], 'height' => $info[1], 'sha256' => hash_file('sha256', $path)];
}

function detectImage(string $path, string $mime, array $config): float {
    if ($config['api_user'] === '' || $config['api_secret'] === '') {
        throw new RuntimeException('Detection is not configured. Ask the administrator to add Sightengine API credentials.');
    }
    $response = '';
    $ch = curl_init('https://api.sightengine.com/1.0/check.json');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['media' => new CURLFile($path, $mime, 'image'), 'models' => 'genai',
            'api_user' => $config['api_user'], 'api_secret' => $config['api_secret']],
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 25,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$response): int {
            if (strlen($response) + strlen($chunk) > 65536) { return 0; }
            $response .= $chunk; return strlen($chunk);
        },
    ]);
    $ok = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($ok === false || $status !== 200) {
        throw new RuntimeException('Detection service unavailable or credentials/quota rejected. No verdict was produced.');
    }
    return parseDetectorResponse($response);
}
