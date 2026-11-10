# RESBOB Book Store PHP

RESBOB Book Store adalah aplikasi toko buku berbasis PHP native dan MySQL. Project ini dibuat untuk dijalankan di XAMPP, dibuka di VS Code, dan dikumpulkan sebagai source code GitHub.

## Fitur

- Katalog buku dengan cover asli, pencarian, dan filter kategori.
- Akun customer: daftar dan masuk memakai email atau nomor HP dengan password hash.
- Keranjang belanja, checkout, riwayat pesanan, dan invoice.
- Dashboard admin dengan login database, password hash, CSRF token, dan kunci sementara setelah salah login berulang.
- Admin bisa tambah, edit, dan hapus semua buku; melihat pesanan; mengubah status pesanan; membuka invoice; dan membuka WhatsApp pelanggan.
- QRIS otomatis Midtrans opsional: QR dibuat per pesanan, nominal terkunci, dan status pembayaran dapat diperbarui otomatis oleh webhook.
- Data disimpan di MySQL: `products`, `admins`, `orders`, dan `order_items`.

## Keamanan Admin

Password admin tidak dicantumkan di halaman website, README, maupun GitHub. Setelah berhasil masuk ke dashboard, buka bagian **Ganti password admin** untuk menggunakan password pribadi yang kuat. Password disimpan sebagai hash di tabel `admins`, bukan teks biasa.

## Jalankan di XAMPP

1. Copy folder `php-bookstore` ke:

```text
C:\xampp\htdocs\php-bookstore
```

2. Start **Apache** dan **MySQL** dari XAMPP Control Panel.
3. Buka website:

```text
http://localhost/php-bookstore
```

4. Buka admin:

```text
http://localhost/php-bookstore/admin.php
```

Saat pertama dibuka, aplikasi otomatis membuat database `resbob_book_store`, tabel, data buku awal, dan akun admin awal. File `db_resbob_bookstore.sql` tetap disediakan jika ingin import manual lewat phpMyAdmin.

## Cara Menambah Buku

1. Buka `http://localhost/php-bookstore/admin.php`.
2. Login admin.
3. Isi form **Tambah buku**.
4. Untuk cover, tempel link gambar langsung atau pilih file gambar lewat **Upload cover**.
5. Klik **Simpan buku**.

Contoh URL cover:

```text
https://covers.openlibrary.org/b/isbn/9780735211292-L.jpg
```

Cara mendapatkan link cover dari Google Images:

- Cari buku di Google Images, OpenLibrary, atau website penerbit.
- Klik kanan pada gambar cover.
- Pilih **Copy image address** atau **Salin alamat gambar**.
- Pilih **Copy image address** atau **Salin alamat gambar**, lalu tempel ke field **Link cover**.

Jangan menempel URL halaman hasil pencarian Google/Yahoo. Link gambar biasanya berakhiran `.jpg`, `.jpeg`, `.png`, atau `.webp`. Jika bingung, gunakan **Upload cover** saja; format JPG, PNG, WEBP, atau GIF hingga 3 MB didukung.

## Konfigurasi Database

Konfigurasi ada di `config.php`.

```php
'db_host' => '127.0.0.1',
'db_name' => 'resbob_book_store',
'db_user' => 'root',
'db_pass' => '',
```

Default ini cocok untuk XAMPP lokal.

## QRIS Otomatis Midtrans

Fitur QRIS otomatis tersedia setelah akun Midtrans memiliki metode QRIS aktif. Salin `config.local.php.example` menjadi `config.local.php`, lalu isi Server Key dari Midtrans Dashboard. File `config.local.php` sengaja tidak ikut GitHub.

```php
'midtrans_environment' => 'sandbox',
'midtrans_server_key' => 'SERVER_KEY_DARI_MIDTRANS',
'midtrans_qris_acquirer' => 'gopay',
```

Saat Server Key valid, checkout menampilkan pilihan **QRIS otomatis (Midtrans)**. Website membuat QR khusus sesuai total pesanan. Untuk pembayaran otomatis di internet, set URL notifikasi Midtrans ke:

```text
https://domain-kamu.com/midtrans-notification.php
```

`localhost` tidak dapat menerima notifikasi dari Midtrans. Saat masih latihan di XAMPP, gunakan mode Sandbox dan alamat HTTPS publik sementara atau deploy dulu ke hosting.

Panduan lengkap ada di `PANDUAN_MIDTRANS.md`.

## Struktur File

```text
php-bookstore/
  index.php                 Halaman toko, cart, dan checkout
  admin.php                 Dashboard admin
  orders.php                Riwayat pesanan
  invoice.php               Invoice/struk pesanan
  qris.php                  Proxy QR dinamis agar Server Key tidak bocor
  midtrans-notification.php Webhook status pembayaran dari Midtrans
  config.php                Konfigurasi admin dan database
  config.local.php          Kredensial lokal Midtrans, tidak ikut GitHub
  database.php              Koneksi PDO, membuat tabel, seed data awal
  data.php                  Data buku bawaan untuk seed
  store.php                 Helper produk, order, admin, CSRF, dan format
  style.css                 Tampilan website
  db_resbob_bookstore.sql   SQL database untuk import manual
```
