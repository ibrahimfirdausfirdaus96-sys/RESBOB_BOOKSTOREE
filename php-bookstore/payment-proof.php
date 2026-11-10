<?php

session_start();

require __DIR__ . '/store.php';
require_once __DIR__ . '/payment-proof-functions.php';

// Hanya admin yang sudah login boleh melihat bukti.
if (!isset($_SESSION['admin'])) {
    http_response_code(403);
    exit('Akses ditolak. Silakan login sebagai admin.');
}

$code = strtoupper(trim(
    is_string($_GET['code'] ?? null) ? $_GET['code'] : ''
));

$statement = db()->prepare("
    SELECT payment_proof
    FROM orders
    WHERE code = :code
    LIMIT 1
");

$statement->execute([
    ':code' => $code,
]);

$filename = $statement->fetchColumn();

if (
    !is_string($filename)
    || !preg_match('/\A[a-f0-9]{40}\.(jpg|png)\z/', $filename)
) {
    http_response_code(404);
    exit('Bukti pembayaran tidak ditemukan.');
}

$path = payment_proof_directory()
    . DIRECTORY_SEPARATOR
    . $filename;

if (!is_file($path)) {
    http_response_code(404);
    exit('File bukti pembayaran tidak ditemukan.');
}

$extension = pathinfo($filename, PATHINFO_EXTENSION);

header(
    'Content-Type: '
    . ($extension === 'png' ? 'image/png' : 'image/jpeg')
);
header('X-Content-Type-Options: nosniff');
header(
    'Content-Disposition: inline; filename="bukti-pembayaran.'
    . $extension
    . '"'
);
header('Cache-Control: private, no-store');

readfile($path);
exit;
