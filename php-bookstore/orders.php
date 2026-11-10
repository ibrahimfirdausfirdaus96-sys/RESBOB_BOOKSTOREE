<?php

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

session_start();

require __DIR__ . '/store.php';
require_once __DIR__ . '/payment-proof-functions.php';
require_once __DIR__ . '/return-functions.php';

/*
|--------------------------------------------------------------------------
| Persiapan data pelanggan
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION['customer_orders'])
    || !is_array($_SESSION['customer_orders'])
) {
    $_SESSION['customer_orders'] = [];
}

$customerProfile = is_array($_SESSION['customer'] ?? null)
    ? $_SESSION['customer']
    : null;

$accountOrderCodes = $customerProfile
    ? customer_order_codes($customerProfile)
    : [];

$notice = is_string($_SESSION['order_flash'] ?? null)
    ? $_SESSION['order_flash']
    : '';

unset($_SESSION['order_flash']);

$error = '';
$action = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (is_string($_POST['action'] ?? null)) {
        $action = $_POST['action'];
    }
}



/*
|--------------------------------------------------------------------------
| Lacak pesanan dengan kode dan nomor HP
|--------------------------------------------------------------------------
*/

if ($action === 'track_order') {
    verify_csrf();

    $rawCode = is_string($_POST['code'] ?? null)
        ? $_POST['code']
        : '';

    $rawPhone = is_string($_POST['phone'] ?? null)
        ? $_POST['phone']
        : '';

    $code = strtoupper(trim($rawCode));
    $phone = normalize_phone($rawPhone);

    $order = $code !== ''
        ? find_order($code)
        : null;

    if (
        !$order
        || $phone === ''
        || !hash_equals(
            normalize_phone((string)$order['customer']['phone']),
            $phone
        )
    ) {
        $error = 'Kode pesanan atau nomor HP tidak cocok.';
    } else {
        $_SESSION['customer_orders'][] = $order['code'];

        $_SESSION['customer_orders'] = array_values(
            array_unique($_SESSION['customer_orders'])
        );

        $_SESSION['order_flash'] =
            'Pesanan ditemukan. Status terbaru sudah ditampilkan.';

        header(
            'Location: orders.php?code='
            . urlencode($order['code'])
        );
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Sinkronisasi pembayaran QRIS otomatis
|--------------------------------------------------------------------------
*/

if ($action === 'sync_payment') {
    verify_csrf();

    $rawCode = is_string($_POST['code'] ?? null)
        ? $_POST['code']
        : '';

    $code = strtoupper(trim($rawCode));

    $accessibleCodes = array_values(
        array_unique(
            array_merge(
                $_SESSION['customer_orders'],
                $accountOrderCodes
            )
        )
    );

    if (!in_array($code, $accessibleCodes, true)) {
        $error = 'Pesanan ini tidak tersedia pada perangkat ini.';
    } else {
        try {
            $result = sync_midtrans_payment_status($code);

            $message = match ($result) {
                'paid' =>
                    'Pembayaran berhasil diterima. '
                    . 'Status pesanan sudah diperbarui.',

                'cancelled' =>
                    'Pembayaran QRIS sudah kedaluwarsa atau dibatalkan.',

                'ignored' =>
                    'Status pembayaran memerlukan pemeriksaan. '
                    . 'Silakan hubungi toko.',

                default =>
                    'Pembayaran belum diterima. Jika baru membayar, '
                    . 'tunggu sebentar lalu cek lagi.',
            };

            $_SESSION['order_flash'] = $message;

            header(
                'Location: orders.php?code='
                . urlencode($code)
            );
            exit;
        } catch (Throwable $exception) {
            error_log(
                'Gagal memeriksa pembayaran pesanan '
                . $code
                . ': '
                . $exception->getMessage()
            );

            $error =
                'Status pembayaran belum dapat diperiksa. '
                . 'Silakan coba lagi atau hubungi toko.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Upload bukti pembayaran manual
|--------------------------------------------------------------------------
*/

if ($action === 'upload_payment_proof') {
    verify_csrf();

    $rawCode = is_string($_POST['code'] ?? null)
        ? $_POST['code']
        : '';

    $code = strtoupper(trim($rawCode));

    $accessibleCodes = array_values(
        array_unique(
            array_merge(
                $_SESSION['customer_orders'],
                $accountOrderCodes
            )
        )
    );

    if (!in_array($code, $accessibleCodes, true)) {
        $error = 'Pesanan ini tidak tersedia pada perangkat ini.';
    } else {
        try {
            save_payment_proof(
                $code,
                $_FILES['payment_proof'] ?? []
            );

            $_SESSION['order_flash'] =
                'Bukti pembayaran berhasil dikirim. '
                . 'Tunggu admin memeriksanya.';

            header(
                'Location: orders.php?code='
                . urlencode($code)
            );
            exit;
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log(
                'Gagal upload bukti pembayaran: '
                . $exception->getMessage()
            );

            $error =
                'Bukti belum dapat disimpan. Silakan coba lagi.';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Customer mengonfirmasi barang diterima
|--------------------------------------------------------------------------
*/

if ($action === 'confirm_delivery_received') {
    verify_csrf();

    $code = strtoupper(trim((string)($_POST['code'] ?? '')));
    $accessibleCodes = array_values(array_unique(array_merge(
        $_SESSION['customer_orders'],
        $accountOrderCodes
    )));

    if (!in_array($code, $accessibleCodes, true)) {
        $error = 'Pesanan ini tidak tersedia pada perangkat ini.';
    } else {
        try {
            confirm_delivery_received($code);
            $_SESSION['order_flash'] = 'Terima kasih. Pesanan ditandai sudah sampai dan selesai.';
            header('Location: orders.php?code=' . urlencode($code));
            exit;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

if ($action === 'submit_return') {
    verify_csrf();
    $code = strtoupper(trim((string)($_POST['code'] ?? '')));
    $accessibleCodes = array_values(array_unique(array_merge($_SESSION['customer_orders'], $accountOrderCodes)));

    if (!in_array($code, $accessibleCodes, true)) {
        $error = 'Pesanan ini tidak tersedia pada perangkat ini.';
    } else {
        try {
            $quantities = is_array($_POST['return_qty'] ?? null) ? $_POST['return_qty'] : [];
            $selectedItemIds = is_array($_POST['return_selected'] ?? null) ? $_POST['return_selected'] : [];
            if ($selectedItemIds) {
                $selectedItemIds = array_flip(array_map('strval', $selectedItemIds));
                $quantities = array_intersect_key($quantities, $selectedItemIds);
            } elseif (count($quantities) !== 1) {
                // Untuk pesanan lebih dari satu buku, pelanggan wajib menandai bukunya.
                $quantities = [];
            }

            create_return_request(
                $code,
                $quantities,
                (string)($_POST['return_reason'] ?? ''),
                (string)($_POST['return_description'] ?? ''),
                $_FILES['return_evidence'] ?? []
            );
            $_SESSION['order_flash'] = 'Pengajuan retur berhasil dikirim dan sedang diperiksa admin.';
            header('Location: orders.php?code=' . urlencode($code));
            exit;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Daftar pesanan yang dapat diakses
|--------------------------------------------------------------------------
*/

$customerOrders = array_values(
    array_unique(
        array_filter(
            array_merge(
                $_SESSION['customer_orders'],
                $accountOrderCodes
            ),
            fn($code) => is_string($code) && $code !== ''
        )
    )
);

$myOrders = [];

foreach (array_reverse($customerOrders) as $code) {
    $order = find_order($code);

    if ($order) {
        $myOrders[] = $order;
    }
}

$rawRequestedCode = is_string($_GET['code'] ?? null)
    ? $_GET['code']
    : '';

$requestedCode = strtoupper(trim($rawRequestedCode));

$selectedOrder = in_array(
    $requestedCode,
    $customerOrders,
    true
)
    ? find_order($requestedCode)
    : null;

if (!$selectedOrder && $myOrders) {
    $selectedOrder = $myOrders[0];
}

$returnRequest = $selectedOrder
    ? find_return_request_by_order_code((string)$selectedOrder['code'])
    : null;

$storeWhatsapp = store_whatsapp_number();

$customerName = is_string($_SESSION['user'] ?? null)
    ? $_SESSION['user']
    : '';

/*
|--------------------------------------------------------------------------
| Tampilan status pesanan
|--------------------------------------------------------------------------
*/

function status_step(string $status): int {
    return match ($status) {
        'Pesanan baru' => 1,
        'Diproses' => 2,
        'Selesai' => 3,
        default => 0,
    };
}

function status_message(string $status): string {
    return match ($status) {
        'Diproses' =>
            'Admin sedang menyiapkan pesanan kamu.',

        'Selesai' =>
            'Pesanan telah selesai diproses. '
            . 'Terima kasih sudah belanja di RESBOB.',

        'Dibatalkan' =>
            'Pesanan dibatalkan. '
            . 'Hubungi toko bila kamu membutuhkan bantuan.',

        default =>
            'Pesanan sudah diterima dan menunggu admin memprosesnya.',
    };
}

function delivery_step(string $status): int {
    return match ($status) {
        'Menunggu pengemasan' => 1,
        'Dikemas' => 2,
        'Dikirim' => 3,
        'Sampai' => 4,
        default => 1,
    };
}

?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>Pesanan Saya - RESBOB Book Store</title>

    <link rel="stylesheet" href="style.css?v=20261110-1130">

    <style>
        .payment-proof-box {
            background: #f8faf7;
            border: 1px solid #d9e2db;
            border-radius: 8px;
            margin-top: 20px;
            padding: 16px;
        }

        .payment-proof-box h3 {
            font-size: 17px;
            margin-bottom: 8px;
        }

        .payment-proof-box p {
            font-size: 13px;
            line-height: 1.5;
        }

        .payment-proof-box form {
            display: grid;
            gap: 10px;
        }

        .payment-proof-box label {
            color: #35463e;
            display: grid;
            font-size: 13px;
            font-weight: 800;
            gap: 7px;
        }

        .payment-proof-box input[type="file"] {
            padding: 9px;
        }

        .payment-proof-box small {
            color: #63736a;
            line-height: 1.4;
        }

        .payment-proof-box button {
            width: 100%;
        }
    </style>
</head>

<body class="orders-page">
    <header class="orders-topbar">
        <div class="orders-topbar-inner">
            <a class="brand" href="index.php">
                RESBOB <span>Book Store</span>
            </a>

            <a class="back-to-store" href="index.php">
                <span aria-hidden="true">&larr;</span>
                Kembali ke toko
            </a>
        </div>
    </header>

    <main class="orders-shell">
        <header class="orders-heading">
            <div>
                <p class="eyebrow dark">Pesanan pelanggan</p>

                <h1>Pesanan saya</h1>

                <p>
                    Periksa progres pesanan dan invoice dari satu halaman.
                </p>
            </div>

            <?php if ($customerName !== ''): ?>
                <span class="customer-name">
                    <?= clean_text($customerName) ?>
                </span>
            <?php endif; ?>
        </header>

        <section
            class="order-lookup"
            aria-labelledby="lookup-title"
        >
            <div>
                <p class="eyebrow dark">Lacak pesanan</p>

                <h2 id="lookup-title">Punya kode pesanan?</h2>

                <p>
                    Gunakan kode dan nomor HP checkout untuk membuka
                    pesanan dari perangkat lain.
                </p>
            </div>

            <form method="post" class="order-lookup-form">
                <?= csrf_field() ?>

                <input
                    type="hidden"
                    name="action"
                    value="track_order"
                >

                <label>
                    Kode pesanan

                    <input
                        name="code"
                        value="<?= clean_text($requestedCode) ?>"
                        placeholder="RB-20260903-ABC123"
                        autocomplete="off"
                        required
                    >
                </label>

                <label>
                    Nomor HP

                    <input
                        type="tel"
                        name="phone"
                        inputmode="tel"
                        autocomplete="tel"
                        placeholder="08xxxxxxxxxx"
                        required
                    >
                </label>

                <button type="submit">Cek</button>
            </form>
        </section>

        <?php if ($notice !== ''): ?>
            <p class="notice success" role="status">
                <?= clean_text($notice) ?>
            </p>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <p class="notice danger" role="alert">
                <?= clean_text($error) ?>
            </p>
        <?php endif; ?>

        <?php if ($selectedOrder): ?>
            <?php
            $step = status_step(
                (string)$selectedOrder['status']
            );

            $isCancelled =
                $selectedOrder['status'] === 'Dibatalkan';

            $isPaid =
                $selectedOrder['payment_status'] === 'Sudah dibayar';

            $canShowPayment = !$isCancelled && !$isPaid;

            $isManualPayment = in_array(
                $selectedOrder['payment_method'],
                ['QRIS', 'DANA', 'GoPay', 'OVO'],
                true
            );

            $deliveryStatus = (string)(
                $selectedOrder['delivery_status'] ?? 'Menunggu pengemasan'
            );
            $deliveryStep = delivery_step($deliveryStatus);
            $deliveryCopy = match ($deliveryStatus) {
                'Dikemas' => [
                    'Paket sedang dikemas',
                    'Penjual sedang menyiapkan paketmu untuk diserahkan ke kurir.',
                ],
                'Dikirim' => [
                    'Paket sedang dalam perjalanan',
                    'Pantau nomor resi di bawah dan konfirmasi saat paket sudah diterima.',
                ],
                'Sampai' => [
                    'Pesanan sudah diterima',
                    'Terima kasih sudah berbelanja di RESBOB Book Store.',
                ],
                default => [
                    'Pesanan sedang disiapkan',
                    'Pembayaran sudah diterima. Admin akan mulai menyiapkan paketmu.',
                ],
            };
            ?>

            <section class="order-detail" aria-label="Detail pesanan dan pengiriman">

                <?php if (!$isCancelled && $isPaid): ?>
                    <section class="delivery-tracking" aria-labelledby="delivery-tracking-title">
                        <div class="delivery-tracking-head">
                            <div>
                                <p class="eyebrow dark">Lacak pengiriman</p>
                                <h3 id="delivery-tracking-title"><?= clean_text($deliveryCopy[0]) ?></h3>
                                <p><?= clean_text($deliveryCopy[1]) ?></p>
                            </div>
                            <span class="delivery-status-pill"><?= clean_text($deliveryStatus) ?></span>
                        </div>
                        <ol class="delivery-progress" aria-label="Tahap pengiriman">
                            <?php foreach (['Menunggu pengemasan', 'Dikemas', 'Dikirim', 'Sampai'] as $index => $label): ?>
                                <?php
                                $position = $index + 1;
                                $stepClass = $deliveryStep > $position
                                    ? 'is-complete'
                                    : ($deliveryStep === $position ? 'is-active' : '');
                                ?>
                                <li class="<?= $stepClass ?>">
                                    <span><?= $position ?></span>
                                    <strong><?= clean_text($label) ?></strong>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                        <?php if (!empty($selectedOrder['delivery_courier']) || !empty($selectedOrder['tracking_number'])): ?>
                            <div class="delivery-meta">
                                <div>
                                    <span>Kurir</span>
                                    <strong><?= clean_text($selectedOrder['delivery_courier'] ?: '-') ?></strong>
                                </div>
                                <div>
                                    <span>Nomor resi</span>
                                    <strong><?= clean_text($selectedOrder['tracking_number'] ?: '-') ?></strong>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if ($deliveryStatus === 'Dikirim'): ?>
                            <form method="post" class="delivery-confirm-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="confirm_delivery_received">
                                <input type="hidden" name="code" value="<?= clean_text($selectedOrder['code']) ?>">
                                <button type="submit" onclick="return confirm('Pastikan barang benar-benar sudah diterima.');">Pesanan sudah saya terima</button>
                            </form>
                        <?php endif; ?>
                    </section>
                <?php elseif (!$isCancelled): ?>
                    <p class="delivery-waiting">Status pengiriman akan tampil setelah pembayaran dikonfirmasi.</p>
                <?php endif; ?>

                <div class="order-detail-grid">
                    <section
                        class="order-line-items"
                        aria-labelledby="order-items-title"
                    >
                        <div class="order-section-heading">
                            <h3 id="order-items-title">
                                Buku yang dipesan
                            </h3>

                            <span>
                                <?= count($selectedOrder['items']) ?> item
                            </span>
                        </div>

                        <?php foreach ($selectedOrder['items'] as $item): ?>
                            <div class="order-line-row">
                                <div>
                                    <strong>
                                        <?= clean_text($item['title']) ?>
                                    </strong>

                                    <span>
                                        <?= (int)$item['quantity'] ?>
                                        x
                                        <?= rupiah((int)$item['price']) ?>
                                    </span>
                                </div>

                                <strong>
                                    <?= rupiah((int)$item['line_total']) ?>
                                </strong>
                            </div>
                        <?php endforeach; ?>
                    </section>

                    <aside class="order-recap">
                        <h3>Ringkasan</h3>

                        <p>
                            <span>Subtotal</span>
                            <strong>
                                <?= rupiah((int)$selectedOrder['subtotal']) ?>
                            </strong>
                        </p>

                        <p>
                            <span>Diskon</span>
                            <strong>
                                -<?= rupiah((int)$selectedOrder['discount']) ?>
                            </strong>
                        </p>

                        <p>
                            <span>Ongkir</span>

                            <strong>
                                <?= (int)$selectedOrder['shipping'] === 0
                                    ? 'Gratis'
                                    : rupiah((int)$selectedOrder['shipping']) ?>
                            </strong>
                        </p>

                        <p class="order-grand-total">
                            <span>Total</span>

                            <strong>
                                <?= rupiah((int)$selectedOrder['total']) ?>
                            </strong>
                        </p>

                        <div class="payment-summary">
                            <span>Metode pembayaran</span>

                            <strong>
                                <?= clean_text($selectedOrder['payment_method']) ?>
                            </strong>

                            <small
                                class="payment-state <?= $isPaid ? 'is-paid' : '' ?>"
                            >
                                <?= clean_text($selectedOrder['payment_status']) ?>
                            </small>
                        </div>

                        <?php if (
                            $canShowPayment
                            && is_midtrans_qris_order($selectedOrder)
                        ): ?>
                            <section
                                class="qris-payment"
                                aria-labelledby="qris-title"
                            >
                                <p class="eyebrow dark">
                                    QRIS otomatis
                                </p>

                                <h4 id="qris-title">
                                    Scan QR pesanan ini
                                </h4>

                                <span class="qris-amount-label">
                                    Nominal pesanan
                                </span>

                                <strong class="qris-amount">
                                    <?= rupiah((int)$selectedOrder['total']) ?>
                                </strong>

                                <?php if (!empty($selectedOrder['payment_qr_url'])): ?>
                                    <img
                                        src="qris.php?code=<?= urlencode($selectedOrder['code']) ?>"
                                        alt="Kode QRIS otomatis untuk pesanan <?= clean_text($selectedOrder['code']) ?>"
                                    >

                                    <p class="qris-help">
                                        QR ini dibuat khusus untuk pesanan ini.
                                        Setelah membayar, tunggu konfirmasi
                                        pembayaran. Bila status belum berubah,
                                        klik tombol cek status.
                                    </p>

                                    <form
                                        method="post"
                                        class="payment-sync-form"
                                    >
                                        <?= csrf_field() ?>

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="sync_payment"
                                        >

                                        <input
                                            type="hidden"
                                            name="code"
                                            value="<?= clean_text($selectedOrder['code']) ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="button secondary"
                                        >
                                            Cek status pembayaran
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <p class="qris-help">
                                        QR pembayaran belum berhasil dibuat.
                                        Hubungi admin dan jangan gunakan QR lain
                                        untuk pesanan ini.
                                    </p>
                                <?php endif; ?>
                            </section>

                        <?php elseif (
                            $canShowPayment
                            && $selectedOrder['payment_method'] === 'QRIS'
                        ): ?>
                            <section
                                class="qris-payment"
                                aria-labelledby="qris-title"
                            >
                                <p class="eyebrow dark">
                                    Pembayaran QRIS
                                </p>

                                <h4 id="qris-title">
                                    Scan untuk membayar
                                </h4>

                                <span class="qris-amount-label">
                                    Nominal yang harus dibayar
                                </span>

                                <strong class="qris-amount">
                                    <?= rupiah((int)$selectedOrder['total']) ?>
                                </strong>

                                <img
                                    src="assets/payments/qris-resbob.jpeg"
                                    alt="Kode QRIS untuk pembayaran RESBOB Book Store"
                                >

                                <p class="qris-help">
                                    Scan QRIS, lalu masukkan nominal persis
                                    seperti total di atas. Setelah membayar,
                                    unggah bukti pembayaran di bawah agar
                                    admin dapat memeriksanya.
                                </p>
                            </section>
                        <?php endif; ?>

                        <?php if ($isCancelled && !$isPaid): ?>
                            <p class="notice warning">
                                Pesanan ini dibatalkan. Jangan melakukan
                                pembayaran sebelum menghubungi toko.
                            </p>
                        <?php endif; ?>

                        <?php if ($canShowPayment && $isManualPayment): ?>
                            <section class="payment-proof-box">
                                <h3>Bukti pembayaran</h3>

                                <?php if (!empty($selectedOrder['payment_proof'])): ?>
                                    <p class="notice">
                                        Bukti sudah dikirim dan menunggu
                                        pemeriksaan admin. Jika bukti salah,
                                        silakan hubungi toko.
                                    </p>
                                <?php else: ?>
                                    <p class="muted">
                                        Setelah membayar, unggah foto bukti
                                        pembayaran di sini. Bukti hanya dapat
                                        dikirim sekali.
                                    </p>

                                    <form
                                        method="post"
                                        enctype="multipart/form-data"
                                    >
                                        <?= csrf_field() ?>

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="upload_payment_proof"
                                        >

                                        <input
                                            type="hidden"
                                            name="code"
                                            value="<?= clean_text($selectedOrder['code']) ?>"
                                        >

                                        <label>
                                            Foto bukti pembayaran

                                            <input
                                                type="file"
                                                name="payment_proof"
                                                accept="image/jpeg,image/png"
                                                required
                                            >
                                        </label>

                                        <small>
                                            JPG atau PNG, maksimal 3 MB.
                                        </small>

                                        <button type="submit">
                                            Kirim bukti pembayaran
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </section>
                        <?php endif; ?>

                        <div class="delivery-note">
                            <span>Alamat kirim</span>

                            <strong>
                                <?= clean_text(
                                    $selectedOrder['customer']['address']
                                ) ?>
                            </strong>
                        </div>

                        <div class="order-actions">
                            <a
                                class="button"
                                href="invoice.php?code=<?= urlencode($selectedOrder['code']) ?>"
                            >
                                Lihat invoice
                            </a>

                            <?php if ($storeWhatsapp): ?>
                                <?php
                                $contactMessage =
                                    'Halo RESBOB, saya ingin menanyakan pesanan '
                                    . $selectedOrder['code']
                                    . '.';
                                ?>

                                <a
                                    class="button secondary"
                                    href="<?= clean_text(
                                        whatsapp_link(
                                            $storeWhatsapp,
                                            $contactMessage
                                        )
                                    ) ?>"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    Hubungi toko
                                </a>
                            <?php else: ?>
                                <span class="contact-unavailable">
                                    Nomor WhatsApp toko belum diatur.
                                </span>
                            <?php endif; ?>
                        </div>
                    </aside>
                </div>

                <?php if ($selectedOrder['status'] === 'Selesai' && $selectedOrder['delivery_status'] === 'Sampai'): ?>
                    <section class="return-request-card" aria-labelledby="return-title">
                        <div>
                            <p class="eyebrow dark">Pusat bantuan</p>
                            <h3 id="return-title">Retur barang</h3>
                            <p>Ajukan retur bila barang rusak atau tidak sesuai. Hanya barang dari pesanan ini yang dapat dipilih.</p>
                        </div>
                        <?php if ($returnRequest): ?>
                            <div class="return-status-box">
                                <strong>Status retur: <?= clean_text($returnRequest['status']) ?></strong>
                                <span><?= clean_text($returnRequest['reason']) ?></span>
                                <?php if (!empty($returnRequest['admin_note'])): ?><p>Catatan admin: <?= clean_text($returnRequest['admin_note']) ?></p><?php endif; ?>
                            </div>
                        <?php else: ?>
                            <form method="post" enctype="multipart/form-data" class="return-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="submit_return">
                                <input type="hidden" name="code" value="<?= clean_text($selectedOrder['code']) ?>">
                                <div class="return-items">
                                    <?php foreach ($selectedOrder['items'] as $item): ?>
                                        <label class="return-item-row">
                                            <input class="return-item-check" type="checkbox" name="return_selected[]" value="<?= (int)$item['id'] ?>" <?= count($selectedOrder['items']) === 1 ? 'checked' : '' ?>>
                                            <span class="return-item-copy">
                                                <strong><?= clean_text($item['title']) ?></strong>
                                                <small>Dibeli <?= (int)$item['quantity'] ?> buku</small>
                                            </span>
                                            <span class="return-quantity">
                                                <small>Jumlah retur</small>
                                                <input type="number" name="return_qty[<?= (int)$item['id'] ?>]" min="1" max="<?= (int)$item['quantity'] ?>" value="1">
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <label>Alasan retur
                                    <select name="return_reason" required><option value="">Pilih alasan</option><option>Barang rusak</option><option>Barang tidak sesuai</option><option>Barang kurang</option><option>Lainnya</option></select>
                                </label>
                                <label>Kronologi
                                    <textarea name="return_description" minlength="15" maxlength="1000" placeholder="Jelaskan kondisi barang dan alasan retur." required></textarea>
                                </label>
                                <label>Foto bukti (JPG/PNG, maksimal 3 foto, total 5 MB)
                                    <input type="file" name="return_evidence[]" accept="image/jpeg,image/png" multiple required>
                                </label>
                                <button type="submit">Ajukan retur</button>
                            </form>
                        <?php endif; ?>
                    </section>
                <?php elseif (!$isCancelled): ?>
                    <section class="return-request-card is-locked" aria-labelledby="return-title">
                        <div>
                            <p class="eyebrow dark">Pusat bantuan</p>
                            <h3 id="return-title">Retur barang</h3>
                            <p>Butuh bantuan karena barang rusak atau tidak sesuai? Pengajuan retur akan aktif setelah paket berstatus Sampai.</p>
                        </div>
                        <div class="return-locked-box">
                            <strong>Retur belum dapat diajukan</strong>
                            <span>Konfirmasikan “Pesanan sudah saya terima” setelah barang benar-benar sampai untuk membuka form retur.</span>
                            <button type="button" disabled>Ajukan retur</button>
                        </div>
                    </section>
                <?php endif; ?>
            </section>

        <?php else: ?>
            <section class="orders-empty-state">
                <p class="eyebrow dark">Belum ada pesanan</p>

                <h2>
                    Belum ada pesanan untuk ditampilkan.
                </h2>

                <p>
                    Setelah checkout, pesanan otomatis muncul di halaman ini.
                </p>

                <a class="button" href="index.php#catalog">
                    Belanja buku
                </a>
            </section>
        <?php endif; ?>

        <section
            class="order-history"
            aria-labelledby="history-title"
        >
            <div class="order-history-head">
                <div>
                    <p class="eyebrow dark">
                        <?= $customerProfile
                            ? 'Riwayat akun'
                            : 'Riwayat browser ini' ?>
                    </p>

                    <h2 id="history-title">
                        Pesanan tersimpan
                    </h2>
                </div>

                <span>
                    <?= count($myOrders) ?> pesanan
                </span>
            </div>

            <?php if (!$myOrders): ?>
                <p class="history-empty">
                    Belum ada riwayat pesanan yang dapat ditampilkan.
                </p>
            <?php else: ?>
                <div class="history-list">
                    <?php foreach ($myOrders as $order): ?>
                        <?php
                        $isSelected = $selectedOrder
                            && $selectedOrder['code'] === $order['code'];

                        $historyCancelled =
                            $order['status'] === 'Dibatalkan';
                        ?>

                        <a
                            class="history-row <?= $isSelected ? 'is-selected' : '' ?>"
                            href="orders.php?code=<?= urlencode($order['code']) ?>"
                        >
                            <span class="history-code">
                                <strong>
                                    <?= clean_text($order['code']) ?>
                                </strong>

                                <small>
                                    <?= clean_text($order['created_at']) ?>
                                </small>
                            </span>

                            <span
                                class="history-status <?= $historyCancelled ? 'is-cancelled' : '' ?>"
                            >
                                <?= clean_text($order['status']) ?>
                            </span>

                            <strong class="history-total">
                                <?= rupiah((int)$order['total']) ?>
                            </strong>

                            <span class="history-link">
                                Detail
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <footer class="site-footer">
        <div class="site-footer-inner">
            <strong>RESBOB Book Store</strong>

            <span>
                &copy; <?= date('Y') ?> RESBOB
            </span>

            <a href="index.php">
                Kembali ke toko
            </a>
        </div>
    </footer>
</body>
</html>
