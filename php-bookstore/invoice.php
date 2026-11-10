<?php
session_start();
require __DIR__ . '/store.php';

$code = trim($_GET['code'] ?? '');
$isAdmin = isset($_SESSION['admin']);
$customerOrders = $_SESSION['customer_orders'] ?? [];
$canView = $code !== '' && ($isAdmin || in_array($code, $customerOrders, true));
$order = $canView ? find_order($code) : null;
$accessDenied = $code !== '' && !$canView;
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice <?= clean_text($code ?: 'Pesanan') ?> - RESBOB Book Store</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="invoice-page">
    <main class="invoice-shell">
        <?php if ($accessDenied): ?>
            <section class="admin-card">
                <h1>Invoice terkunci</h1>
                <p class="muted">Buka invoice dari halaman Pesanan saya, atau cek pesanan terlebih dahulu dengan kode dan nomor HP.</p>
                <a class="button" href="orders.php">Buka pesanan saya</a>
            </section>
        <?php elseif (!$order): ?>
            <section class="admin-card">
                <h1>Pesanan tidak ditemukan</h1>
                <p class="muted">Periksa kembali kode pesanan kamu.</p>
                <a class="button" href="orders.php">Kembali ke pesanan</a>
            </section>
        <?php else: ?>
            <section class="invoice">
                <div class="invoice-head">
                    <div>
                        <p class="eyebrow dark">Invoice</p>
                        <h1>RESBOB Book Store</h1>
                        <p class="muted">Kode: <?= clean_text($order['code']) ?></p>
                        <p class="muted">Tanggal: <?= clean_text($order['created_at']) ?></p>
                    </div>
                    <span class="badge"><?= clean_text($order['status'] ?? 'Pesanan baru') ?></span>
                </div>

                <div class="invoice-grid">
                    <div>
                        <h3>Pelanggan</h3>
                        <p><strong><?= clean_text($order['customer']['name'] ?? '-') ?></strong></p>
                        <p><?= clean_text($order['customer']['phone'] ?? '-') ?></p>
                        <p><?= clean_text($order['customer']['address'] ?? '-') ?></p>
                    </div>
                    <div>
                        <h3>Catatan</h3>
                        <p><?= clean_text($order['customer']['note'] ?: 'Tidak ada catatan.') ?></p>
                    </div>
                    <div class="invoice-payment">
                        <h3>Pembayaran</h3>
                        <p><strong><?= clean_text($order['payment_method']) ?></strong></p>
                        <p><span class="payment-state <?= $order['payment_status'] === 'Sudah dibayar' ? 'is-paid' : '' ?>"><?= clean_text($order['payment_status']) ?></span></p>
                    </div>
                </div>

                <table class="invoice-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Harga</th>
                            <th>Qty</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (($order['items'] ?? []) as $item): ?>
                            <tr>
                                <td><?= clean_text($item['title'] ?? '-') ?></td>
                                <td><?= rupiah((int)($item['price'] ?? 0)) ?></td>
                                <td><?= (int)($item['quantity'] ?? 0) ?></td>
                                <td><?= rupiah((int)($item['line_total'] ?? 0)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="invoice-total">
                    <p><span>Subtotal</span><strong><?= rupiah((int)$order['subtotal']) ?></strong></p>
                    <p><span>Diskon</span><strong>-<?= rupiah((int)$order['discount']) ?></strong></p>
                    <p><span>Ongkir</span><strong><?= (int)$order['shipping'] === 0 ? 'Gratis' : rupiah((int)$order['shipping']) ?></strong></p>
                    <p class="total"><span>Total</span><strong><?= rupiah((int)$order['total']) ?></strong></p>
                </div>

                <div class="invoice-actions">
                    <button type="button" onclick="window.print()">Print invoice</button>
                    <a class="button secondary" href="orders.php">Kembali</a>
                </div>
            </section>
        <?php endif; ?>
    </main>
</body>
</html>
