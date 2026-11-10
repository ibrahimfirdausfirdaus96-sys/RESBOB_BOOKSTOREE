<?php
session_start();

require __DIR__ . '/store.php';

$books = all_books();
$paymentMethods = payment_methods();

$_SESSION['cart'] ??= [];
$_SESSION['user'] ??= null;
$_SESSION['customer'] ??= null;
$_SESSION['customer_orders'] ??= [];

if (!is_array($_SESSION['customer']) || empty($_SESSION['customer']['name']) || empty($_SESSION['customer']['identity'])) {
    $_SESSION['customer'] = null;
    $_SESSION['user'] = null;
} else {
    $_SESSION['user'] = $_SESSION['customer']['name'];
}

$message = '';
$authMessage = '';
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$authName = trim((string)($_POST['name'] ?? ''));
$authIdentity = trim((string)($_POST['identity'] ?? ''));
$authMode = $action === 'register_customer' ? 'register' : 'login';

if ($action === 'login_customer' || $action === 'register_customer') {
    verify_csrf();
    try {
        $customer = $action === 'register_customer'
            ? register_customer_account($authName, $authIdentity, (string)($_POST['password'] ?? ''))
            : authenticate_customer_account($authIdentity, (string)($_POST['password'] ?? ''));
        session_regenerate_id(true);
        $_SESSION['customer'] = $customer;
        $_SESSION['user'] = $customer['name'];
        $_SESSION['customer_orders'] ??= [];
        header('Location: index.php');
        exit;
    } catch (Throwable $exception) {
        $authMessage = $exception->getMessage();
    }
}

if ($action === 'logout') {
    $_SESSION['user'] = null;
    $_SESSION['customer'] = null;
    $_SESSION['cart'] = [];
    $_SESSION['customer_orders'] = [];
    header('Location: index.php');
    exit;
}

if ($action === 'add') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $book = find_book($books, $id);
    if ($book && $book['stock'] > 0) {
        $current = $_SESSION['cart'][$id] ?? 0;
        $_SESSION['cart'][$id] = min($current + 1, $book['stock']);
    }
    header('Location: index.php#catalog');
    exit;
}

if ($action === 'remove') {
    verify_csrf();
    $id = (int)($_POST['id'] ?? 0);
    if (isset($_SESSION['cart'][$id])) {
        $_SESSION['cart'][$id]--;
        if ($_SESSION['cart'][$id] <= 0) unset($_SESSION['cart'][$id]);
    }
    header('Location: index.php#checkout');
    exit;
}

$query = strtolower(trim($_GET['q'] ?? ''));
$category = $_GET['category'] ?? 'Semua';
$categories = array_map(
    fn($category) => $category['name'],
    all_categories()
);

$filtered = array_values(array_filter($books, function ($book) use ($query, $category) {
    $matchesCategory = $category === 'Semua' || $book['category'] === $category;
    $haystack = strtolower($book['title'] . ' ' . $book['author'] . ' ' . $book['category']);
    $matchesQuery = $query === '' || str_contains($haystack, $query);
    return $matchesCategory && $matchesQuery;
}));

$cartCount = array_sum($_SESSION['cart']);
$subtotal = 0;
foreach ($_SESSION['cart'] as $id => $quantity) {
    $book = find_book($books, (int)$id);
    if ($book) $subtotal += $book['price'] * $quantity;
}
$shipping = $cartCount > 0 && $subtotal < 300000 ? 18000 : 0;
$discount = $subtotal >= 300000 ? (int)round($subtotal * 0.12) : 0;
$grandTotal = $subtotal + $shipping - $discount;
$customerProfile = is_array($_SESSION['customer']) ? $_SESSION['customer'] : null;
$defaultPhone = $customerProfile && $customerProfile['identity_type'] === 'phone'
    ? '0' . substr((string)$customerProfile['identity'], 2)
    : '';

if ($action === 'checkout') {
    verify_csrf();
    $customer = $_SESSION['customer'];
    $orderPhone = customer_identity((string)($_POST['phone'] ?? ''));

    if (!is_array($customer)) {
        $message = 'Masuk dulu sebelum checkout.';
    } elseif (count($_SESSION['cart']) === 0) {
        $message = 'Keranjang masih kosong.';
    } elseif (!$orderPhone || $orderPhone['type'] !== 'phone' || trim($_POST['address'] ?? '') === '') {
        $message = 'Masukkan nomor HP Indonesia yang valid dan alamat pengiriman.';
    } elseif (!array_key_exists((string)($_POST['payment_method'] ?? ''), $paymentMethods)) {
        $message = 'Pilih metode pembayaran terlebih dahulu.';
    } else {
        try {
            $orderCode = create_order(
                [
                    'name' => $customer['name'],
                    'phone' => $orderPhone['value'],
                    'address' => $_POST['address'] ?? '',
                    'note' => $_POST['note'] ?? '',
                ],
                $_SESSION['cart'],
                (string)$_POST['payment_method'],
            );
            $selectedPaymentMethod = (string)$_POST['payment_method'];
            $_SESSION['customer_orders'][] = $orderCode;
            $_SESSION['customer_orders'] = array_values(array_unique($_SESSION['customer_orders']));
            try {
                remember_customer_order($customer, $orderCode);
            } catch (Throwable $historyError) {
                // The order is valid even when its account history cannot be saved.
                error_log('Customer order history could not be saved: ' . $historyError->getMessage());
            }
            $_SESSION['cart'] = [];
            if ($selectedPaymentMethod === midtrans_qris_method()) {
                try {
                    create_midtrans_qris_payment($orderCode);
                    $_SESSION['order_flash'] = 'Pesanan ' . $orderCode . ' berhasil dibuat. Scan QRIS khusus pesanan ini; status akan berubah otomatis setelah pembayaran berhasil.';
                } catch (Throwable $paymentError) {
                    $_SESSION['order_flash'] = 'Pesanan ' . $orderCode . ' sudah dibuat, tetapi QRIS otomatis belum tersedia: ' . $paymentError->getMessage() . ' Hubungi admin dan jangan membayar melalui QR lama.';
                }
            } else {
                $_SESSION['order_flash'] = 'Pesanan ' . $orderCode . ' berhasil dibuat. Stok buku sudah diperbarui dan pembayaran menunggu konfirmasi.';
            }
            header('Location: orders.php?code=' . urlencode($orderCode));
            exit;
        } catch (Throwable $exception) {
            $message = $exception->getMessage();
            $books = all_books();
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>RESBOB Book Store</title>
    <link rel="stylesheet" href="style.css?v=20261110-1035">
</head>
<body>
    <header class="hero">
        <nav class="nav">
            <a class="brand" href="index.php">RESBOB <span>Book Store</span></a>
            <div class="nav-links">
                <a href="#catalog">Koleksi</a>
                <a href="about.php">Tentang kami</a>
                <a class="nav-checkout" href="#checkout">Checkout <span class="cart-count"><?= $cartCount ?></span></a>
                <a href="orders.php">Pesanan saya</a>
                <?php if ($_SESSION['user']): ?>
                    <span class="nav-user"><?= clean_text($_SESSION['user']) ?></span>
                    <a class="pill" href="index.php?action=logout">Keluar</a>
                <?php else: ?>
                    <a class="pill" href="#account">Masuk</a>
                <?php endif; ?>
            </div>
        </nav>
        <section class="hero-grid">
            <div class="hero-copy">
                <p class="eyebrow">Pilihan buku untukmu</p>
                <h1>RESBOB Book Store</h1>
                <p class="hero-title-detail">Temukan buku yang ingin kamu baca, lalu pesan dengan proses yang jelas dan mudah dilacak.</p>
                <div class="hero-actions">
                    <a class="button" href="#catalog">Belanja sekarang</a>
                    <a class="button secondary" href="orders.php">Lacak pesanan</a>
                </div>
                <ul class="hero-highlights" aria-label="Keunggulan toko">
                    <li>Koleksi pilihan</li>
                    <li>Checkout sederhana</li>
                    <li>Status pesanan jelas</li>
                </ul>
            </div>
        </section>
    </header>

    <main>
        <section class="catalog" id="catalog">
            <div class="section-head">
                <div>
                    <p class="eyebrow dark">Koleksi utama</p>
                    <h2>Buku populer siap masuk keranjang.</h2>
                </div>
                <form class="search" method="get">
                    <input name="q" value="<?= clean_text($query) ?>" placeholder="Cari judul, penulis, kategori">
                    <select name="category">
                        <option>Semua</option>
                        <?php foreach ($categories as $item): ?>
                            <option <?= $category === $item ? 'selected' : '' ?>><?= clean_text($item) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit">Cari</button>
                </form>
            </div>

            <?php if (!$filtered): ?>
                <div class="empty catalog-empty">
                    <strong>Buku tidak ditemukan.</strong>
                    <p>Coba gunakan kata kunci atau kategori lain.</p>
                </div>
            <?php else: ?>
                <div class="grid">
                    <?php foreach ($filtered as $book): ?>
                    <article class="card">
                        <div class="cover">
                            <img src="<?= clean_text(book_cover_url($book['cover'], $book['title'])) ?>" onerror="this.onerror=null;this.src='<?= clean_text(placeholder_cover_url($book['title'])) ?>';" alt="Sampul <?= clean_text($book['title']) ?>">
                        </div>
                        <div class="card-body">
                            <span class="badge"><?= clean_text($book['badge']) ?></span>
                            <h3><?= clean_text($book['title']) ?></h3>
                            <p><?= clean_text($book['author']) ?> · <?= clean_text($book['category']) ?></p>
                            <p><?= clean_text($book['description']) ?></p>
                            <span class="stock-pill">Stok <?= (int)$book['stock'] ?> tersedia</span>
                            <strong><?= rupiah($book['price']) ?></strong>
                            <?php if ($book['old_price']): ?>
                                <del><?= rupiah($book['old_price']) ?></del>
                            <?php endif; ?>
                            <?php if ((int)$book['stock'] > 0): ?>
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="add">
                                    <input type="hidden" name="id" value="<?= $book['id'] ?>">
                                    <button type="submit">Tambah ke keranjang</button>
                                </form>
                            <?php else: ?>
                                <button class="sold-out" type="button" disabled>Stok habis</button>
                            <?php endif; ?>
                        </div>
                    </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if (!$customerProfile): ?>
            <section class="account-section" id="account" aria-labelledby="account-title">
                <div class="account-section-intro">
                    <p class="eyebrow dark">Akun RESBOB</p>
                    <h2 id="account-title">Simpan pesananmu dalam satu akun.</h2>
                    <p>Masuk sebelum checkout agar riwayat pesanan dan status pembayaran dapat kamu buka lagi kapan saja.</p>
                    <ul class="account-benefits">
                        <li>Riwayat pesanan tersimpan</li>
                        <li>Status pesanan mudah dipantau</li>
                    </ul>
                </div>
                <div class="auth-card" data-auth-card data-initial-mode="<?= clean_text($authMode) ?>">
                    <div class="auth-card-head">
                        <p class="eyebrow dark">Akun customer</p>
                        <div class="auth-tabs" role="tablist" aria-label="Pilihan akun">
                            <button type="button" class="auth-tab <?= $authMode === 'login' ? 'is-active' : '' ?>" data-auth-tab="login" role="tab" aria-selected="<?= $authMode === 'login' ? 'true' : 'false' ?>">Masuk</button>
                            <button type="button" class="auth-tab <?= $authMode === 'register' ? 'is-active' : '' ?>" data-auth-tab="register" role="tab" aria-selected="<?= $authMode === 'register' ? 'true' : 'false' ?>">Daftar</button>
                        </div>
                    </div>

                    <?php if ($authMessage): ?><p class="notice danger auth-notice"><?= clean_text($authMessage) ?></p><?php endif; ?>

                    <form method="post" class="auth-form" data-auth-form="login" <?= $authMode === 'register' ? 'hidden' : '' ?>>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="login_customer">
                        <label for="customer-login-identity">Email atau nomor HP
                            <input id="customer-login-identity" name="identity" value="<?= clean_text($authIdentity) ?>" autocomplete="username" placeholder="nama@email.com atau 08xxxxxxxxxx" required>
                        </label>
                        <label for="customer-login-password">Password
                            <input id="customer-login-password" name="password" type="password" autocomplete="current-password" required>
                        </label>
                        <button type="submit">Masuk ke akun</button>
                    </form>

                    <form method="post" class="auth-form" data-auth-form="register" <?= $authMode === 'login' ? 'hidden' : '' ?>>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="register_customer">
                        <label for="customer-register-name">Nama lengkap
                            <input id="customer-register-name" name="name" value="<?= clean_text($authName) ?>" autocomplete="name" placeholder="Nama untuk pesanan" required>
                        </label>
                        <label for="customer-register-identity">Email atau nomor HP
                            <input id="customer-register-identity" name="identity" value="<?= clean_text($authIdentity) ?>" autocomplete="username" placeholder="nama@email.com atau 08xxxxxxxxxx" required>
                        </label>
                        <label for="customer-register-password">Buat password
                            <input id="customer-register-password" name="password" type="password" autocomplete="new-password" minlength="8" required>
                        </label>
                        <p class="auth-form-note">Gunakan minimal 8 karakter.</p>
                        <button type="submit">Buat akun</button>
                    </form>
                </div>
            </section>
        <?php else: ?>
            <section class="customer-session" aria-label="Status akun customer">
                <div class="customer-session-profile">
                    <div class="customer-avatar" aria-hidden="true">
                        <?= clean_text(mb_strtoupper(mb_substr((string)$customerProfile['name'], 0, 1))) ?>
                    </div>
                    <div class="customer-session-copy">
                        <span class="customer-status"><i></i> Akun aktif</span>
                        <strong>Halo, <?= clean_text($customerProfile['name']) ?></strong>
                        <p>Pesanan dan status pembayaranmu tersimpan di akun ini.</p>
                    </div>
                </div>
                <div class="customer-session-actions">
                    <a class="button secondary" href="orders.php">Lihat pesanan</a>
                    <a class="customer-logout" href="index.php?action=logout">Keluar</a>
                </div>
            </section>
        <?php endif; ?>

        <section class="checkout" id="checkout">
            <div>
                <p class="eyebrow dark">Checkout</p>
                <h2>Keranjang belanja</h2>
                <?php if ($message): ?><p class="notice"><?= $message ?></p><?php endif; ?>
                <?php if ($cartCount === 0): ?>
                    <div class="empty">Keranjang masih kosong.</div>
                <?php else: ?>
                    <?php foreach ($_SESSION['cart'] as $id => $quantity): ?>
                        <?php $book = find_book($books, (int)$id); if (!$book) continue; ?>
                        <article class="cart-row">
                            <img src="<?= clean_text(book_cover_url($book['cover'], $book['title'])) ?>" onerror="this.onerror=null;this.src='<?= clean_text(placeholder_cover_url($book['title'])) ?>';" alt="Sampul <?= clean_text($book['title']) ?>">
                            <div>
                                <strong><?= clean_text($book['title']) ?></strong>
                                <p><?= $quantity ?> x <?= rupiah($book['price']) ?></p>
                            </div>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="id" value="<?= $book['id'] ?>">
                                <button type="submit">-</button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <aside class="summary">
                <h3>Ringkasan</h3>
                <p><span>Subtotal</span><strong><?= rupiah($subtotal) ?></strong></p>
                <p><span>Diskon</span><strong>-<?= rupiah($discount) ?></strong></p>
                <p><span>Ongkir</span><strong><?= $shipping === 0 ? 'Gratis' : rupiah($shipping) ?></strong></p>
                <p class="total"><span>Total</span><strong><?= rupiah($grandTotal) ?></strong></p>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="checkout">
                    <label class="checkout-field">Nomor HP untuk pengiriman<input name="phone" value="<?= clean_text($defaultPhone) ?>" inputmode="tel" autocomplete="tel" placeholder="08xxxxxxxxxx" required></label>
                    <label class="checkout-field">Alamat<textarea name="address" placeholder="Alamat lengkap pengiriman" required></textarea></label>
                    <label class="checkout-field">Catatan<textarea name="note" placeholder="Opsional: catatan untuk admin"></textarea></label>
                    <label class="checkout-field">Metode pembayaran
                        <select name="payment_method" required>
                            <?php foreach ($paymentMethods as $value => $label): ?>
                                <option value="<?= clean_text($value) ?>"><?= clean_text($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <p class="payment-help">Jika memilih QRIS, kode QR dan nominal final akan tampil setelah pesanan dibuat. Admin akan mengubah status menjadi sudah dibayar setelah pembayaran dikonfirmasi.</p>
                    <button type="submit">Buat pesanan</button>
                </form>
            </aside>
        </section>
    </main>
    <footer class="site-footer">
        <div class="site-footer-inner">
            <strong>RESBOB Book Store</strong>
            <span>&copy; <?= date('Y') ?> RESBOB</span>
            <a href="orders.php">Pesanan saya</a>
        </div>
    </footer>
    <script>
        const authCard = document.querySelector('[data-auth-card]');
        if (authCard) {
            const setAuthMode = (mode) => {
                authCard.querySelectorAll('[data-auth-tab]').forEach((tab) => {
                    const active = tab.dataset.authTab === mode;
                    tab.classList.toggle('is-active', active);
                    tab.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                authCard.querySelectorAll('[data-auth-form]').forEach((form) => {
                    form.hidden = form.dataset.authForm !== mode;
                });
            };
            authCard.querySelectorAll('[data-auth-tab]').forEach((tab) => {
                tab.addEventListener('click', () => setAuthMode(tab.dataset.authTab));
            });
            setAuthMode(authCard.dataset.initialMode || 'login');
        }
    </script>
</body>
</html>
