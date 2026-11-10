<?php
session_start();
require __DIR__ . '/store.php';

$storeWhatsapp = store_whatsapp_number();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Tentang RESBOB Book Store</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <header class="hero about-hero">
        <nav class="nav">
            <a class="brand" href="index.php">RESBOB <span>Book Store</span></a>
            <div class="nav-links">
                <a href="index.php#catalog">Koleksi</a>
                <a href="orders.php">Pesanan saya</a>
                <a class="pill" href="index.php">Kembali ke toko</a>
            </div>
        </nav>
        <section class="hero-grid">
            <div class="hero-copy">
                <p class="eyebrow">Tentang kami</p>
                <h1>RESBOB Book Store</h1>
                <p class="hero-title-detail">Toko buku online yang membantu pembaca menemukan buku pilihan melalui proses belanja yang sederhana, jelas, dan mudah dilacak.</p>
            </div>
        </section>
    </header>

    <main class="about-page">
        <section class="about-grid">
            <article class="about-card">
                <p class="eyebrow dark">Tujuan</p>
                <h2>Membuat belanja buku lebih mudah.</h2>
                <p>RESBOB menyediakan katalog buku, pencarian, keranjang, checkout, dan pelacakan pesanan dalam satu website.</p>
            </article>
            <article class="about-card">
                <p class="eyebrow dark">Layanan</p>
                <h2>Pesan dengan langkah yang jelas.</h2>
                <p>Pelanggan dapat membuat akun, memilih buku, melakukan pembayaran, melihat invoice, dan memantau status pesanan.</p>
            </article>
            <article class="about-card">
                <p class="eyebrow dark">Bantuan</p>
                <h2>Hubungi kami bila perlu.</h2>
                <?php if ($storeWhatsapp): ?>
                    <p>Tim toko dapat dihubungi melalui WhatsApp untuk bantuan pesanan dan pembayaran.</p>
                    <a class="button" href="<?= clean_text(whatsapp_link($storeWhatsapp, 'Halo RESBOB Book Store, saya membutuhkan bantuan.')) ?>" target="_blank" rel="noopener">Hubungi admin</a>
                <?php else: ?>
                    <p>Nomor WhatsApp toko akan tersedia setelah diatur oleh admin.</p>
                <?php endif; ?>
            </article>
        </section>
    </main>

    <footer class="site-footer">
        <div class="site-footer-inner">
            <strong>RESBOB Book Store</strong>
            <span>&copy; <?= date('Y') ?> RESBOB</span>
            <a href="index.php">Kembali ke toko</a>
        </div>
    </footer>
</body>
</html>
