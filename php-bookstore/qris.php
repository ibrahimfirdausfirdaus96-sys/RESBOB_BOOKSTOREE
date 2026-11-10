<?php
session_start();
require __DIR__ . '/store.php';

$code = strtoupper(trim((string)($_GET['code'] ?? '')));
$customerOrders = $_SESSION['customer_orders'] ?? [];

if ($code === '' || !in_array($code, $customerOrders, true)) {
    http_response_code(403);
    exit;
}

try {
    $image = midtrans_qr_image($code);
    header('Content-Type: ' . $image['content_type']);
    header('Cache-Control: private, no-store');
    echo $image['content'];
} catch (Throwable $exception) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'QR pembayaran belum tersedia.';
}
