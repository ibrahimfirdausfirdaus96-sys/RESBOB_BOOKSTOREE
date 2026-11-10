<?php

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
]);

session_start();

require __DIR__ . '/store.php';
require_once __DIR__ . '/return-functions.php';

$error = '';

$notice = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);

$action = $_POST['action'] ?? $_GET['action'] ?? '';

$_SESSION['admin_attempts'] ??= 0;
$_SESSION['admin_locked_until'] ??= 0;

/*
|--------------------------------------------------------------------------
| Login admin
|--------------------------------------------------------------------------
*/

if ($action === 'login') {
    verify_csrf();

    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($_SESSION['admin_locked_until'] > time()) {
        $remaining = $_SESSION['admin_locked_until'] - time();

        $error = 'Terlalu banyak percobaan login. Coba lagi dalam '
            . ceil($remaining / 60)
            . ' menit.';
    } elseif (
        ($admin = find_admin_by_email($email))
        && password_verify($password, $admin['password_hash'])
    ) {
        $_SESSION['admin_attempts'] = 0;
        $_SESSION['admin_locked_until'] = 0;
        $_SESSION['admin_last_login'] = date('Y-m-d H:i:s');

        session_regenerate_id(true);

        $_SESSION['admin'] = $admin['email'];

        header('Location: admin.php');
        exit;
    } else {
        $_SESSION['admin_attempts']++;

        if ($_SESSION['admin_attempts'] >= 5) {
            $_SESSION['admin_locked_until'] = time() + 5 * 60;
            $_SESSION['admin_attempts'] = 0;
        }

        $error = 'Email atau password admin salah.';
    }
}

/*
|--------------------------------------------------------------------------
| Logout
|--------------------------------------------------------------------------
*/

if ($action === 'logout') {
    session_regenerate_id(true);
    unset($_SESSION['admin']);

    header('Location: admin.php');
    exit;
}

$isAdmin = isset($_SESSION['admin']);

/*
|--------------------------------------------------------------------------
| Ganti password admin
|--------------------------------------------------------------------------
*/

if ($isAdmin && $action === 'change_admin_password') {
    verify_csrf();

    try {
        change_admin_password(
            (string)$_SESSION['admin'],
            (string)($_POST['current_password'] ?? ''),
            (string)($_POST['new_password'] ?? ''),
            (string)($_POST['password_confirmation'] ?? '')
        );

        $_SESSION['admin_flash'] = [
            'type' => 'success',
            'text' => 'Password admin berhasil diperbarui.',
        ];
    } catch (Throwable $exception) {
        $_SESSION['admin_flash'] = [
            'type' => 'danger',
            'text' => $exception->getMessage(),
        ];
    }

    header('Location: admin.php#account-security');
    exit;
}



if ($isAdmin && $action === 'save_book') {
    verify_csrf();

    try {
        $result = save_book($_POST, $_FILES);

        $text = $result['updated']
            ? 'Data buku berhasil diperbarui.'
            : 'Buku baru berhasil ditambahkan.';

        if ($result['cover_notice']) {
            $text .= " " . $result['cover_notice'];


        }

        $_SESSION['admin_flash'] = [
            'type' => $result['cover_notice'] ? 'warning' : 'success',
            'text' => $text,
        ];
    } catch (Throwable $exception) {
        $_SESSION['admin_flash'] = [
            'type' => 'danger',
            'text' => $exception->getMessage(),
        ];
    }

    header('Location: admin.php#book-editor');
    exit;
}

/*
|--------------------------------------------------------------------------
| Kategori buku
|--------------------------------------------------------------------------
*/

if ($isAdmin && $action === 'save_category') {
    verify_csrf();

    try {
        save_category((string)($_POST['category_name'] ?? ''));
        $_SESSION['admin_flash'] = ['type' => 'success', 'text' => 'Kategori berhasil ditambahkan.'];
    } catch (Throwable $exception) {
        $_SESSION['admin_flash'] = ['type' => 'danger', 'text' => $exception->getMessage()];
    }

    header('Location: admin.php#categories');
    exit;
}

if ($isAdmin && $action === 'delete_category') {
    verify_csrf();

    try {
        delete_category((int)($_POST['id'] ?? 0));
        $_SESSION['admin_flash'] = ['type' => 'success', 'text' => 'Kategori berhasil dihapus.'];
    } catch (Throwable $exception) {
        $_SESSION['admin_flash'] = ['type' => 'danger', 'text' => $exception->getMessage()];
    }

    header('Location: admin.php#categories');
    exit;
}

/*
|--------------------------------------------------------------------------
| Hapus buku
|--------------------------------------------------------------------------
*/

if ($isAdmin && $action === 'delete_book') {
    verify_csrf();

    $deleted = delete_book((int)($_POST['id'] ?? 0));

    $_SESSION['admin_flash'] = $deleted
        ? [
            'type' => 'success',
            'text' => 'Buku berhasil dihapus dari katalog. Riwayat pesanan lama tetap tersimpan.',
        ]
        : [
            'type' => 'danger',
            'text' => 'Buku tidak ditemukan atau sudah dihapus.',
        ];

    header('Location: admin.php#inventory');
    exit;
}

/*
|--------------------------------------------------------------------------
| Update pesanan
|--------------------------------------------------------------------------
*/

if ($isAdmin && $action === 'update_order') {
    verify_csrf();

    try {
        update_order_status(
            (string)($_POST['code'] ?? ''),
            (string)($_POST['status'] ?? 'Pesanan baru'),
            (string)($_POST['payment_status'] ?? 'Menunggu pembayaran')
        );

        $_SESSION['admin_flash'] = [
            'type' => 'success',
            'text' => 'Status pesanan dan pembayaran berhasil diperbarui.',
        ];
    } catch (Throwable $exception) {
        $_SESSION['admin_flash'] = [
            'type' => 'danger',
            'text' => $exception->getMessage(),
        ];
    }

    header('Location: admin.php#orders');
    exit;
}

if ($isAdmin && $action === 'update_delivery') {
    verify_csrf();

    try {
        update_delivery(
            (string)($_POST['code'] ?? ''),
            (string)($_POST['delivery_status'] ?? 'Menunggu pengemasan'),
            (string)($_POST['delivery_courier'] ?? ''),
            (string)($_POST['tracking_number'] ?? '')
        );
        $_SESSION['admin_flash'] = [
            'type' => 'success',
            'text' => 'Status pengiriman berhasil diperbarui.',
        ];
    } catch (Throwable $exception) {
        $_SESSION['admin_flash'] = [
            'type' => 'danger',
            'text' => $exception->getMessage(),
        ];
    }

    header('Location: admin.php#orders');
    exit;
}

if ($isAdmin && $action === 'update_return') {
    verify_csrf();
    try {
        update_return_request((int)($_POST['return_id'] ?? 0), (string)($_POST['return_status'] ?? 'Diajukan'), (string)($_POST['admin_note'] ?? ''));
        $_SESSION['admin_flash'] = ['type' => 'success', 'text' => 'Status retur berhasil diperbarui.'];
    } catch (Throwable $exception) {
        $_SESSION['admin_flash'] = ['type' => 'danger', 'text' => $exception->getMessage()];
    }
    header('Location: admin.php#returns');
    exit;
}

/*
|--------------------------------------------------------------------------
| Hapus pesanan
|--------------------------------------------------------------------------
*/

if ($isAdmin && $action === 'delete_order') {
    verify_csrf();

    delete_order((string)($_POST['code'] ?? ''));

    $_SESSION['admin_flash'] = [
        'type' => 'success',
        'text' => 'Pesanan berhasil dihapus.',
    ];

    header('Location: admin.php#orders');
    exit;
}

/*
|--------------------------------------------------------------------------
| Simpan kontak toko
|--------------------------------------------------------------------------
*/

if ($isAdmin && $action === 'save_store_contact') {
    verify_csrf();

    try {
        update_store_whatsapp_number(
            (string)($_POST['store_whatsapp'] ?? '')
        );

        $_SESSION['admin_flash'] = [
            'type' => 'success',
            'text' => 'Nomor WhatsApp toko berhasil disimpan.',
        ];
    } catch (Throwable $exception) {
        $_SESSION['admin_flash'] = [
            'type' => 'danger',
            'text' => $exception->getMessage(),
        ];
    }

    header('Location: admin.php#store-contact');
    exit;
}

/*
|--------------------------------------------------------------------------
| Data dashboard
|--------------------------------------------------------------------------
*/

$books = $isAdmin ? all_books() : [];
$categories = $isAdmin ? all_categories() : [];
$customers = $isAdmin ? all_customers() : [];
$returnRequests = $isAdmin ? all_return_requests() : [];

$editingBook = $isAdmin && isset($_GET['edit'])
    ? find_book_by_id((int)$_GET['edit'])
    : null;

$statusFilter = $_GET['status'] ?? 'Semua';

$allOrders = $isAdmin ? all_orders() : [];

$orders = array_values(array_filter(
    $allOrders,
    fn($order) => $statusFilter === 'Semua'
        || ($order['status'] ?? '') === $statusFilter
));

$customCount = $isAdmin ? count(custom_books()) : 0;

$totalStock = array_sum(array_map(
    fn($book) => (int)$book['stock'],
    $books
));

$orderRevenue = array_sum(array_map(
    fn($order) => (int)($order['total'] ?? 0),
    $allOrders
));

$storeWhatsapp = $isAdmin
    ? store_whatsapp_number()
    : '';

$formBook = $editingBook ?: [
    'id' => '',
    'title' => '',
    'author' => '',
    'category' => '',
    'format' => 'Paperback',
    'badge' => 'Baru',
    'price' => 99000,
    'old_price' => null,
    'rating' => 4.5,
    'stock' => 10,
    'cover' => '',
    'description' => 'Deskripsi singkat buku untuk katalog.',
];

$formCover = book_cover_url(
    (string)$formBook['cover'],
    (string)$formBook['title']
);

?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Admin RESBOB Book Store</title>

    <link rel="stylesheet" href="style.css?v=20261110-1145">
    <link rel="stylesheet" href="admin-style.css">
</head>

<body class="admin-page">
    <header class="admin-topbar">
        <div class="admin-topbar-inner">
            <a class="brand" href="index.php">
                RESBOB <span>Book Store</span>
            </a>

            <nav class="admin-nav" aria-label="Navigasi admin">
                <a href="index.php">Lihat toko</a>

                <?php if ($isAdmin): ?>
                    <span class="admin-account">Admin aktif</span>

                    <a
                        class="button topbar-action"
                        href="admin.php?action=logout"
                    >
                        Keluar
                    </a>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <main class="admin-shell">
        <?php if (!$isAdmin): ?>

            <section class="admin-login">
                <p class="eyebrow dark">Area aman</p>

                <h1>Masuk ke admin</h1>

                <p class="muted">
                    Gunakan akun admin untuk mengatur katalog dan pesanan toko.
                </p>

                <?php if ($error): ?>
                    <p class="notice danger">
                        <?= clean_text($error) ?>
                    </p>
                <?php endif; ?>

                <form method="post" class="stack-form">
                    <?= csrf_field() ?>

                    <input type="hidden" name="action" value="login">

                    <label>
                        Email admin

                        <input
                            name="email"
                            type="email"
                            placeholder="email@toko.com"
                            autocomplete="username"
                            required
                        >
                    </label>

                    <label>
                        Password

                        <input
                            name="password"
                            type="password"
                            autocomplete="current-password"
                            required
                        >
                    </label>

                    <button type="submit">Masuk dashboard</button>
                </form>
            </section>

        <?php else: ?>

            <section class="admin-heading">
                <div>
                    <p class="eyebrow dark">Control panel</p>

                    <h1>Katalog dan pesanan.</h1>

                    <p>
                        Kelola buku RESBOB dari satu tempat.
                        Semua buku dapat diedit atau dihapus oleh admin.
                    </p>
                </div>

                <div class="admin-heading-actions">
                    <a
                        class="button secondary"
                        href="index.php"
                        target="_blank"
                        rel="noopener"
                    >
                        Buka toko
                    </a>

                    <a class="button" href="admin.php#book-editor">
                        Tambah buku
                    </a>
                </div>
            </section>

            <?php if ($notice): ?>
                <p class="notice <?= clean_text($notice['type'] ?? 'success') ?>">
                    <?= clean_text($notice['text'] ?? '') ?>
                </p>
            <?php endif; ?>

            <section
                class="stats-grid admin-stats"
                aria-label="Ringkasan toko"
            >
                <article>
                    <span>Produk aktif</span>
                    <strong><?= count($books) ?></strong>
                </article>

                <article>
                    <span>Ditambahkan admin</span>
                    <strong><?= $customCount ?></strong>
                </article>

                <article>
                    <span>Stok tersedia</span>
                    <strong><?= $totalStock ?></strong>
                </article>

                <article>
                    <span>Nilai pesanan</span>
                    <strong><?= rupiah($orderRevenue) ?></strong>
                </article>
            </section>

            <section
                class="admin-settings-grid"
                aria-label="Pengaturan toko dan akun"
            >
                <form
                    id="store-contact"
                    method="post"
                    class="admin-card store-contact-form"
                >
                    <?= csrf_field() ?>

                    <input
                        type="hidden"
                        name="action"
                        value="save_store_contact"
                    >

                    <div>
                        <p class="eyebrow dark">Kontak toko</p>

                        <h2>WhatsApp customer</h2>

                        <p>
                            Nomor tujuan tombol
                            <strong>Hubungi toko</strong>
                            pada pesanan customer.
                        </p>
                    </div>

                    <label>
                        Nomor WhatsApp toko

                        <input
                            name="store_whatsapp"
                            type="tel"
                            inputmode="tel"
                            value="<?= clean_text($storeWhatsapp) ?>"
                            placeholder="081234567890"
                        >
                    </label>

                    <button type="submit">Simpan nomor</button>
                </form>

                <form
                    id="account-security"
                    method="post"
                    class="admin-card account-security-form"
                >
                    <?= csrf_field() ?>

                    <input
                        type="hidden"
                        name="action"
                        value="change_admin_password"
                    >

                    <div>
                        <p class="eyebrow dark">Keamanan</p>

                        <h2>Ganti password admin</h2>

                        <p>
                            Gunakan password baru yang kuat
                            dan tidak mudah ditebak.
                        </p>
                    </div>

                    <div class="security-fields">
                        <label>
                            Password saat ini

                            <input
                                name="current_password"
                                type="password"
                                autocomplete="current-password"
                                required
                            >
                        </label>

                        <label>
                            Password baru

                            <input
                                name="new_password"
                                type="password"
                                autocomplete="new-password"
                                minlength="10"
                                required
                            >
                        </label>

                        <label>
                            Konfirmasi password baru

                            <input
                                name="password_confirmation"
                                type="password"
                                autocomplete="new-password"
                                minlength="10"
                                required
                            >
                        </label>
                    </div>

                    <button type="submit" class="secondary">
                        Perbarui password
                    </button>
                </form>
            </section>

            <section class="admin-workspace">
                <form
                    id="book-editor"
                    method="post"
                    enctype="multipart/form-data"
                    class="admin-card book-editor stack-form"
                >
                    <?= csrf_field() ?>

                    <input type="hidden" name="action" value="save_book">

                    <input
                        type="hidden"
                        name="id"
                        value="<?= (int)$formBook['id'] ?>"
                    >

                    <div class="editor-heading">
                        <div>
                            <p class="eyebrow dark">
                                <?= $editingBook ? 'Mode edit' : 'Katalog baru' ?>
                            </p>

                            <h2>
                                <?= $editingBook ? 'Edit buku' : 'Tambah buku' ?>
                            </h2>
                        </div>

                        <?php if ($editingBook): ?>
                            <a class="text-link" href="admin.php#book-editor">
                                Batal
                            </a>
                        <?php endif; ?>
                    </div>

                    <label>
                        Judul

                        <input
                            name="title"
                            value="<?= clean_text($formBook['title']) ?>"
                            placeholder="Judul buku"
                            required
                        >
                    </label>

                    <label>
                        Penulis

                        <input
                            name="author"
                            value="<?= clean_text($formBook['author']) ?>"
                            placeholder="Nama penulis"
                            required
                        >
                    </label>

                    <label>
                        Kategori

                        <input
                            name="category"
                            list="category-options"
                            value="<?= clean_text($formBook['category']) ?>"
                            placeholder="Fiction, Technology, Business..."
                            required
                        >
                    </label>

                    <datalist id="category-options">
                        <?php foreach ($categories as $category): ?>
                            <option value="<?= clean_text($category['name']) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>

                    <div class="form-grid">
                        <label>
                            Format

                            <input
                                name="format"
                                value="<?= clean_text($formBook['format']) ?>"
                                required
                            >
                        </label>

                        <label>
                            Label

                            <input
                                name="badge"
                                value="<?= clean_text($formBook['badge']) ?>"
                                required
                            >
                        </label>
                    </div>

                    <div class="form-grid">
                        <label>
                            Harga

                            <input
                                name="price"
                                type="number"
                                min="1000"
                                value="<?= (int)$formBook['price'] ?>"
                                required
                            >
                        </label>

                        <label>
                            Harga awal

                            <input
                                name="old_price"
                                type="number"
                                min="0"
                                value="<?= $formBook['old_price'] === null ? '' : (int)$formBook['old_price'] ?>"
                                placeholder="Opsional"
                            >
                        </label>
                    </div>

                    <div class="form-grid">
                        <label>
                            Rating

                            <input
                                name="rating"
                                type="number"
                                min="1"
                                max="5"
                                step="0.1"
                                value="<?= clean_text((string)$formBook['rating']) ?>"
                                required
                            >
                        </label>

                        <label>
                            Stok

                            <input
                                name="stock"
                                type="number"
                                min="1"
                                value="<?= (int)$formBook['stock'] ?>"
                                required
                            >
                        </label>
                    </div>

                    <div class="cover-control">
                        <img
                            id="cover-preview"
                            src="<?= clean_text($formCover) ?>"
                            onerror="this.onerror=null;this.src='<?= clean_text(placeholder_cover_url((string)$formBook['title'])) ?>';"
                            alt="Pratinjau cover"
                        >

                        <div>
                            <label>
                                Link cover

                                <input
                                    id="cover-url"
                                    name="cover"
                                    type="url"
                                    value="<?= clean_text($formBook['cover']) ?>"
                                    placeholder="https://contoh.com/cover-buku.jpg"
                                >
                            </label>

                            <small>
                                Tempel alamat gambar langsung, bukan halaman
                                pencarian Google. Jika link gagal, gunakan
                                upload gambar.
                            </small>

                            <label class="file-picker">
                                Upload cover

                                <input
                                    id="cover-upload"
                                    name="cover_upload"
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp,image/gif"
                                >
                            </label>
                        </div>
                    </div>

                    <label>
                        Deskripsi

                        <textarea
                            name="description"
                            required
                        ><?= clean_text($formBook['description']) ?></textarea>
                    </label>

                    <button type="submit">
                        <?= $editingBook ? 'Simpan perubahan' : 'Simpan buku' ?>
                    </button>
                </form>

                <section id="inventory" class="admin-card inventory-panel">
                    <div class="inventory-heading">
                        <div>
                            <p class="eyebrow dark">Inventori</p>
                            <h2>Daftar buku</h2>
                        </div>

                        <span><?= count($books) ?> buku</span>
                    </div>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Buku</th>
                                    <th>Kategori</th>
                                    <th>Harga</th>
                                    <th>Stok</th>
                                    <th>Status</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($books as $book): ?>
                                    <?php
                                    $cover = book_cover_url(
                                        $book['cover'],
                                        $book['title']
                                    );
                                    ?>

                                    <tr>
                                        <td>
                                            <div class="book-cell">
                                                <img
                                                    src="<?= clean_text($cover) ?>"
                                                    onerror="this.onerror=null;this.src='<?= clean_text(placeholder_cover_url($book['title'])) ?>';"
                                                    alt=""
                                                >

                                                <div>
                                                    <strong>
                                                        <?= clean_text($book['title']) ?>
                                                    </strong>

                                                    <span>
                                                        <?= clean_text($book['author']) ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </td>

                                        <td><?= clean_text($book['category']) ?></td>
                                        <td><?= rupiah((int)$book['price']) ?></td>
                                        <td><?= (int)$book['stock'] ?></td>

                                        <td>
                                            <span class="status-chip">
                                                <?= (int)$book['is_base'] === 1 ? 'Bawaan' : 'Admin' ?>
                                            </span>
                                        </td>

                                        <td>
                                            <div class="inventory-actions">
                                                <a
                                                    class="button secondary"
                                                    href="admin.php?edit=<?= (int)$book['id'] ?>#book-editor"
                                                >
                                                    Edit
                                                </a>

                                                <form
                                                    method="post"
                                                    onsubmit="return confirm('Hapus buku ini dari katalog?');"
                                                >
                                                    <?= csrf_field() ?>

                                                    <input
                                                        type="hidden"
                                                        name="action"
                                                        value="delete_book"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="id"
                                                        value="<?= (int)$book['id'] ?>"
                                                    >

                                                    <button
                                                        class="danger-button"
                                                        type="submit"
                                                    >
                                                        Hapus
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </section>

            <section id="categories" class="admin-card orders-admin">
                <div class="section-head compact">
                    <div>
                        <p class="eyebrow dark">Master data</p>
                        <h2>Kelola kategori buku</h2>
                    </div>
                    <strong><?= count($categories) ?> kategori</strong>
                </div>

                <form method="post" class="status-filter">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_category">
                    <label>Nama kategori
                        <input name="category_name" maxlength="80" placeholder="Contoh: Teknologi" required>
                    </label>
                    <button type="submit">Tambah kategori</button>
                </form>

                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Nama kategori</th><th>Aksi</th></tr></thead>
                        <tbody>
                            <?php foreach ($categories as $category): ?>
                                <tr>
                                    <td><?= clean_text($category['name']) ?></td>
                                    <td>
                                        <form method="post" onsubmit="return confirm('Hapus kategori ini?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_category">
                                            <input type="hidden" name="id" value="<?= (int)$category['id'] ?>">
                                            <button class="danger-button" type="submit">Hapus</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section id="customers" class="admin-card orders-admin">
                <div class="section-head compact">
                    <div>
                        <p class="eyebrow dark">Akun customer</p>
                        <h2>Daftar pelanggan terdaftar</h2>
                    </div>
                    <strong><?= count($customers) ?> pelanggan</strong>
                </div>

                <?php if (!$customers): ?>
                    <div class="empty">Belum ada pelanggan yang mendaftar setelah fitur daftar pelanggan diaktifkan.</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>Nama</th><th>Email atau nomor HP</th><th>Terdaftar</th></tr></thead>
                            <tbody>
                                <?php foreach ($customers as $customer): ?>
                                    <tr>
                                        <td><?= clean_text($customer['name']) ?></td>
                                        <td><?= clean_text($customer['identity']) ?></td>
                                        <td><?= clean_text($customer['created_at']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section id="orders" class="admin-card orders-admin">
                <div class="section-head compact">
                    <div>
                        <p class="eyebrow dark">Pesanan masuk</p>
                        <h2>Kelola pesanan pelanggan</h2>
                    </div>

                    <strong><?= rupiah($orderRevenue) ?></strong>
                </div>

                <form class="status-filter" method="get">
                    <label>
                        Status pesanan

                        <select name="status">
                            <?php foreach (
                                ['Semua', 'Pesanan baru', 'Diproses', 'Selesai', 'Dibatalkan']
                                as $status
                            ): ?>
                                <option <?= $statusFilter === $status ? 'selected' : '' ?>>
                                    <?= $status ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <button type="submit">Filter</button>
                </form>

                <?php if (!$orders): ?>
                    <div class="empty">Belum ada pesanan masuk.</div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Kode</th>
                                    <th>Pelanggan</th>
                                    <th>Item</th>
                                    <th>Total</th>
                                    <th>Pembayaran</th>
                                    <th>Status</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($orders as $order): ?>
                                    <tr>
                                        <td>
                                            <strong>
                                                <?= clean_text($order['code'] ?? '-') ?>
                                            </strong>
                                            <br>

                                            <span class="muted">
                                                <?= clean_text($order['created_at'] ?? '-') ?>
                                            </span>
                                        </td>

                                        <td>
                                            <strong>
                                                <?= clean_text($order['customer']['name'] ?? '-') ?>
                                            </strong>
                                            <br>

                                            <span class="muted">
                                                <?= clean_text($order['customer']['phone'] ?? '-') ?>
                                            </span>
                                            <br>

                                            <span class="muted">
                                                <?= clean_text($order['customer']['address'] ?? '-') ?>
                                            </span>
                                        </td>

                                        <td>
                                            <?php foreach (($order['items'] ?? []) as $item): ?>
                                                <div>
                                                    <?= clean_text($item['title'] ?? '-') ?>
                                                    x
                                                    <?= (int)($item['quantity'] ?? 0) ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </td>

                                        <td>
                                            <?= rupiah((int)($order['total'] ?? 0)) ?>
                                        </td>

                                        <td>
                                            <strong>
                                                <?= clean_text($order['payment_method'] ?? 'QRIS') ?>
                                            </strong>
                                            <br>

                                            <span class="payment-state <?= ($order['payment_status'] ?? '') === 'Sudah dibayar' ? 'is-paid' : '' ?>">
                                                <?= clean_text($order['payment_status'] ?? 'Menunggu pembayaran') ?>
                                            </span>

                                            <?php if (!empty($order['payment_proof'])): ?>
                                                <p style="margin: 10px 0 0;">
                                                    <a
                                                        class="button secondary"
                                                        href="payment-proof.php?code=<?= urlencode($order['code']) ?>"
                                                        target="_blank"
                                                        rel="noopener"
                                                    >
                                                        Lihat bukti
                                                    </a>
                                                </p>

                                            <?php elseif (!is_midtrans_qris_order($order)): ?>
                                                <p
                                                    class="muted"
                                                    style="margin: 8px 0 0;"
                                                >
                                                    Belum ada bukti
                                                </p>
                                            <?php endif; ?>
                                        </td>

                                        <td>
                                            <form method="post" class="status-form">
                                                <?= csrf_field() ?>

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="update_order"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="code"
                                                    value="<?= clean_text($order['code'] ?? '') ?>"
                                                >

                                                <select name="status">
                                                    <?php foreach (
                                                        ['Pesanan baru', 'Diproses', 'Dibatalkan']
                                                        as $status
                                                    ): ?>
                                                        <option <?= ($order['status'] ?? '') === $status ? 'selected' : '' ?>>
                                                            <?= $status ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>

                                                <select
                                                    name="payment_status"
                                                    aria-label="Status pembayaran"
                                                >
                                                    <?php foreach (payment_statuses() as $paymentStatus): ?>
                                                        <option <?= ($order['payment_status'] ?? '') === $paymentStatus ? 'selected' : '' ?>>
                                                            <?= $paymentStatus ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>

                                                <button type="submit">Simpan perubahan</button>
                                            </form>

                                            <details class="delivery-admin">
                                                <summary>Atur pengiriman</summary>
                                                <form method="post" class="delivery-admin-form">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="update_delivery">
                                                    <input type="hidden" name="code" value="<?= clean_text($order['code'] ?? '') ?>">
                                                    <label>Status pengiriman
                                                        <select name="delivery_status">
                                                            <?php foreach (['Menunggu pengemasan', 'Dikemas', 'Dikirim'] as $deliveryStatus): ?>
                                                                <option <?= ($order['delivery_status'] ?? 'Menunggu pengemasan') === $deliveryStatus ? 'selected' : '' ?>><?= clean_text($deliveryStatus) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </label>
                                                    <label>Kurir
                                                        <input name="delivery_courier" value="<?= clean_text($order['delivery_courier'] ?? '') ?>" placeholder="JNE, J&T, SiCepat">
                                                    </label>
                                                    <label>Nomor resi
                                                        <input name="tracking_number" value="<?= clean_text($order['tracking_number'] ?? '') ?>" placeholder="Contoh: JP123456789">
                                                    </label>
                                                    <button type="submit" class="secondary">Simpan pengiriman</button>
                                                    <small>Customer yang mengonfirmasi barang sudah sampai.</small>
                                                </form>
                                            </details>

                                            <div class="row-actions">
                                                <a
                                                    class="button secondary"
                                                    href="invoice.php?code=<?= urlencode($order['code'] ?? '') ?>"
                                                >
                                                    Invoice
                                                </a>

                                                <a
                                                    class="button secondary"
                                                    href="<?= clean_text(
                                                        whatsapp_link(
                                                            $order['customer']['phone'] ?? '',
                                                            'Halo '
                                                            . ($order['customer']['name'] ?? '')
                                                            . ', pesanan '
                                                            . ($order['code'] ?? '')
                                                            . ' di RESBOB Book Store statusnya: '
                                                            . ($order['status'] ?? 'Pesanan baru')
                                                            . '.'
                                                        )
                                                    ) ?>"
                                                    target="_blank"
                                                    rel="noopener"
                                                >
                                                    WA
                                                </a>

                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section id="returns" class="admin-card orders-admin">
                <div class="section-head compact"><div><p class="eyebrow dark">Layanan pelanggan</p><h2>Pengajuan retur</h2></div><strong><?= count($returnRequests) ?> pengajuan</strong></div>
                <?php if (!$returnRequests): ?>
                    <div class="empty">Belum ada pengajuan retur.</div>
                <?php else: ?>
                    <div class="table-wrap"><table><thead><tr><th>Pesanan</th><th>Alasan dan barang</th><th>Bukti</th><th>Status</th></tr></thead><tbody>
                    <?php foreach ($returnRequests as $request): ?>
                        <tr>
                            <td><strong><?= clean_text($request['code']) ?></strong><br><span class="muted"><?= clean_text($request['customer_name']) ?></span></td>
                            <td><strong><?= clean_text($request['reason']) ?></strong><br><span class="muted"><?= clean_text($request['description']) ?></span><br><?php foreach ($request['items'] as $item): ?><span><?= clean_text($item['title']) ?> x<?= (int)$item['quantity'] ?></span><br><?php endforeach; ?></td>
                            <td>
                                <?php if ($request['evidence']): ?>
                                    <div class="return-evidence-links">
                                        <?php foreach ($request['evidence'] as $photoIndex => $_filename): ?>
                                            <a href="return-evidence.php?request=<?= (int)$request['id'] ?>&amp;photo=<?= (int)$photoIndex ?>" target="_blank" rel="noopener">Foto <?= (int)$photoIndex + 1 ?></a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="muted">Tidak ada foto</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" class="status-form return-status-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_return">
                                    <input type="hidden" name="return_id" value="<?= (int)$request['id'] ?>">
                                    <label>Status pengajuan
                                        <select name="return_status">
                                            <option value="Diajukan" <?= $request['status'] === 'Diajukan' ? 'selected' : '' ?>>Diajukan customer</option>
                                            <option value="Diterima" <?= $request['status'] === 'Diterima' ? 'selected' : '' ?>>Setujui pengajuan</option>
                                            <option value="Ditolak" <?= $request['status'] === 'Ditolak' ? 'selected' : '' ?>>Tolak pengajuan</option>
                                            <option value="Barang diterima" <?= $request['status'] === 'Barang diterima' ? 'selected' : '' ?>>Barang sudah sampai toko</option>
                                            <option value="Refund diproses" <?= $request['status'] === 'Refund diproses' ? 'selected' : '' ?>>Refund diproses</option>
                                        </select>
                                    </label>
                                    <input name="admin_note" value="<?= clean_text($request['admin_note'] ?? '') ?>" placeholder="Catatan untuk customer (opsional)">
                                    <button type="submit">Simpan status retur</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                <?php endif; ?>
            </section>

        <?php endif; ?>
    </main>

    <footer class="site-footer">
        <div class="site-footer-inner">
            <strong>RESBOB Book Store</strong>
            <span>Panel admin</span>
            <a href="index.php">Lihat toko</a>
        </div>
    </footer>

    <?php if ($isAdmin): ?>
        <script>
            const coverUrl = document.getElementById('cover-url');
            const coverUpload = document.getElementById('cover-upload');
            const coverPreview = document.getElementById('cover-preview');

            const previewFallback =
                <?= json_encode(placeholder_cover_url((string)$formBook['title']), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

            if (coverUrl && coverPreview) {
                coverUrl.addEventListener('input', () => {
                    coverPreview.src = coverUrl.value.trim() || previewFallback;
                });

                coverPreview.addEventListener('error', () => {
                    if (coverPreview.src !== new URL(previewFallback, location.href).href) {
                        coverPreview.src = previewFallback;
                    }
                });
            }

            if (coverUpload && coverPreview) {
                coverUpload.addEventListener('change', () => {
                    const file = coverUpload.files[0];

                    if (file) {
                        coverPreview.src = URL.createObjectURL(file);
                    }
                });
            }
        </script>
    <?php endif; ?>
</body>
</html>
