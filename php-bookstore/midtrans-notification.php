<?php
require __DIR__ . '/store.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['message' => 'Method not allowed']);
    exit;
}

try {
    if (!midtrans_is_configured()) {
        throw new RuntimeException('Payment gateway belum dikonfigurasi.');
    }

    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload) || ($payload['payment_type'] ?? '') !== 'qris') {
        throw new RuntimeException('Notifikasi QRIS tidak valid.');
    }

    $result = apply_midtrans_payment_status($payload, true);
    echo json_encode(['message' => 'OK', 'result' => $result]);
} catch (Throwable $exception) {
    error_log('Midtrans notification rejected: ' . $exception->getMessage());
    http_response_code(400);
    echo json_encode(['message' => 'Notification rejected']);
}
