<?php

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

if (!isset($_SESSION['admin'])) {
    http_response_code(403);
    exit('Akses bukti retur hanya untuk admin.');
}

require __DIR__ . '/database.php';
require_once __DIR__ . '/return-functions.php';

$requestId = (int)($_GET['request'] ?? 0);
$photoIndex = (int)($_GET['photo'] ?? -1);

if ($requestId < 1 || $photoIndex < 0) {
    http_response_code(404);
    exit('Bukti retur tidak ditemukan.');
}

$statement = db()->prepare('SELECT evidence FROM return_requests WHERE id = :id LIMIT 1');
$statement->execute([':id' => $requestId]);
$evidence = json_decode((string)$statement->fetchColumn(), true);
$filename = is_array($evidence) ? ($evidence[$photoIndex] ?? '') : '';

if (!is_string($filename) || !preg_match('/^[a-f0-9]{40}\.(jpg|png)$/', $filename)) {
    http_response_code(404);
    exit('Bukti retur tidak ditemukan.');
}

$path = return_evidence_directory() . DIRECTORY_SEPARATOR . $filename;
if (!is_file($path)) {
    http_response_code(404);
    exit('File bukti retur tidak tersedia.');
}

$mime = strtolower((string)mime_content_type($path));
if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
    http_response_code(415);
    exit('Format bukti tidak didukung.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="bukti-retur-' . ($photoIndex + 1) . '.' . ($mime === 'image/png' ? 'png' : 'jpg') . '"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

readfile($path);
