# Panduan Belajar dan Presentasi RESBOB Book Store

Dokumen ini menjelaskan cara kerja project RESBOB Book Store berdasarkan kode yang ada di folder ini. Buka file ini di VS Code bila perlu menjelaskan project ke guru atau teman.

## 1. Ringkasan Project

RESBOB Book Store adalah website toko buku berbasis **PHP native**, **MySQL**, HTML, dan CSS. Website dijalankan secara lokal memakai XAMPP.

Fitur utama:

- Katalog buku, pencarian, filter kategori, dan cover buku.
- Keranjang belanja dan checkout.
- Customer dapat daftar dan masuk memakai email atau nomor HP serta password.
- Pesanan, invoice, dan pelacakan status pesanan.
- Stok otomatis berkurang saat checkout berhasil.
- Metode pembayaran QRIS, DANA, GoPay, dan OVO dalam mode simulasi.
- Login admin yang memakai database dan password hash.
- Admin dapat mengelola buku, stok, pesanan, status pembayaran, dan nomor WhatsApp toko.

## 2. Cara Menjalankan di XAMPP

1. Pastikan folder project berada di:

```text
C:\xampp\htdocs\php-bookstore
```

2. Buka XAMPP Control Panel.
3. Jalankan **Apache** dan **MySQL**.
4. Buka halaman toko:

```text
http://localhost/php-bookstore/index.php
```

5. Buka halaman admin:

```text
http://localhost/php-bookstore/admin.php
```

Saat pertama aplikasi dibuka, `database.php` otomatis membuat database dan tabel yang diperlukan.

## 3. Login Customer Memakai Apa?

Customer dapat membuat akun dengan:

- Nama lengkap.
- **Email atau nomor HP** sebagai identitas akun.
- Password minimal 8 karakter.

Pada form akun ada dua tombol:

- **Daftar akun**: membuat akun baru.
- **Masuk**: membuka akun yang sudah pernah dibuat.

Password tidak disimpan sebagai teks biasa. Kode memakai `password_hash()` saat pendaftaran dan `password_verify()` saat login. Identitas email/nomor HP juga divalidasi terlebih dahulu. Setelah login sukses, PHP mengganti session ID lalu menyimpan profil sederhana di session:

```php
$_SESSION['customer'] = [
    'name' => $customer['name'],
    'identity_type' => 'email', // atau phone
    'identity' => $customer['identity'],
];
```

Nama customer dipakai pada pesanan. Jika customer masuk memakai nomor HP, nomor tersebut otomatis terisi pada checkout. Nomor HP tujuan pengiriman tetap harus diisi karena dipakai untuk pelacakan pesanan.

Data akun disimpan di tabel `store_settings` memakai key hash dan password hash. Ini membuat fitur akun tetap dapat berjalan pada database XAMPP lokal tanpa menaruh password di source code atau GitHub.

File utama customer login: `index.php` dan fungsi akun pada `store.php`.

### Jawaban singkat saat ditanya

> Customer mendaftar memakai email atau nomor HP dan password. Password disimpan sebagai hash dengan `password_hash()`, lalu diperiksa dengan `password_verify()` saat login. Setelah sukses, profil customer disimpan di PHP session.

Pesanan yang dibuat setelah customer login juga dihubungkan ke akun tersebut. Kode pesanan disimpan sebagai data akun di tabel `store_settings`, sehingga customer yang masuk lagi dengan email/nomor HP yang sama dapat melihat riwayat pesanannya, walaupun memakai browser atau perangkat lain.

## 4. Login Admin Memakai Apa?

Admin memakai **email dan password** yang diperiksa dari database MySQL tabel `admins`.

Password admin tidak dicantumkan di halaman website maupun dokumentasi GitHub. Pemilik toko dapat menggantinya setelah login melalui bagian **Ganti password admin** di dashboard. Yang disimpan di database bukan password biasa, tetapi `password_hash`. Saat login, kode menjalankan:

```php
password_verify($password, $admin['password_hash'])
```

Jika password cocok, sistem membuat session admin:

```php
$_SESSION['admin'] = $admin['email'];
```

Setelah itu admin bisa membuka dashboard, menambah buku, mengedit stok, melihat pesanan, mengubah status pesanan, dan mengubah status pembayaran.

Keamanan tambahan yang dipakai:

- Password disimpan sebagai hash.
- Form penting memakai CSRF token.
- Query memakai PDO prepared statement.
- Session ID diganti setelah login dengan `session_regenerate_id(true)`.
- Setelah salah login 5 kali, login dikunci 5 menit.
- Password admin dapat diganti dari dashboard dan tidak ditampilkan kembali.

File login admin: `admin.php`. Tabel admin dibuat di `database.php`.

### Jawaban singkat saat ditanya

> Login admin memakai email dan password dari tabel `admins` di MySQL. Password tidak disimpan polos, tetapi disimpan sebagai hash dan dicek memakai `password_verify()`.

## 5. Database yang Dipakai

Nama database:

```text
resbob_book_store
```

Tabel penting:

| Tabel | Fungsi |
| --- | --- |
| `products` | Menyimpan katalog buku, harga, cover, dan stok. |
| `admins` | Menyimpan email dan password hash admin. |
| `orders` | Menyimpan data utama pesanan, total, metode/status pembayaran, dan status pesanan. |
| `order_items` | Menyimpan detail buku yang ada di setiap pesanan. |
| `store_settings` | Menyimpan pengaturan toko, termasuk nomor WhatsApp toko. |

File `db_resbob_bookstore.sql` bisa di-import melalui phpMyAdmin. Namun aplikasi juga otomatis membuat tabel lewat fungsi `ensure_schema()` di `database.php`.

## 6. Bagaimana Stok Berkurang Saat Checkout?

Ini bagian penting. Stok tidak dikurangi dari tampilan browser saja, tetapi dikurangi di **database** saat checkout berhasil.

Alurnya:

1. Customer menambahkan buku ke keranjang yang disimpan di session.
2. Jumlah di keranjang tidak bisa melewati stok yang terlihat saat itu.
3. Saat customer klik **Buat pesanan**, `create_order()` di `store.php` memulai database transaction.
4. Sistem membaca stok terbaru dari tabel `products` dengan `SELECT ... FOR UPDATE`.
5. Sistem memastikan stok cukup.
6. Sistem menjalankan query berikut untuk setiap buku:

```sql
UPDATE products
SET stock = stock - :quantity
WHERE id = :id AND stock >= :quantity
```

7. Bila semua stok tersedia, data masuk ke tabel `orders` dan `order_items`, lalu transaction di-commit.
8. Bila salah satu buku kurang stok, semua proses di-rollback. Pesanan tidak dibuat dan stok tidak berubah.

Karena memakai transaction, pesanan dan stok tidak akan tersimpan setengah-setengah.

Aturan tambahan:

- Jika admin membatalkan pesanan, stok yang sebelumnya dikurangi akan dikembalikan.
- Jika admin mengaktifkan kembali pesanan yang sudah dibatalkan, stok dicek lagi sebelum dikurangi.
- Jika admin menghapus pesanan yang stoknya masih tertahan, stok akan dikembalikan.

File utama logika stok: `store.php` pada fungsi `create_order()`, `update_order_status()`, dan `delete_order()`.

### Jawaban singkat saat ditanya

> Stok berkurang saat checkout sukses melalui query `UPDATE products SET stock = stock - quantity`. Prosesnya memakai database transaction agar kalau stok kurang, semua perubahan dibatalkan.

## 7. Metode Pembayaran QRIS, DANA, dan E-Wallet

Di halaman checkout, customer dapat memilih:

- QRIS
- DANA
- GoPay
- OVO

Metode yang dipilih disimpan di kolom `payment_method` pada tabel `orders`. Pesanan baru otomatis memiliki status pembayaran:

```text
Menunggu pembayaran
```

Admin dapat memilih status pembayaran di dashboard:

```text
Menunggu pembayaran -> Sudah dibayar
```

Customer dapat melihat metode dan status pembayaran di halaman **Pesanan Saya** dan di invoice.

### QRIS statis dan QRIS otomatis

Metode `QRIS`, `DANA`, `GoPay`, dan `OVO` biasa masih berupa pencatatan pembayaran manual. Customer mengirim bukti bayar ke WhatsApp toko, lalu admin mengecek dan mengubah status menjadi `Sudah dibayar`.

Project juga memiliki pilihan **QRIS otomatis (Midtrans)**. Pilihan ini tampil hanya saat Server Key Midtrans sudah disimpan di `config.local.php`. Alurnya:

1. Customer checkout dan total final dihitung dari harga, diskon, dan ongkir di database.
2. `store.php` memanggil API Midtrans untuk membuat QRIS khusus dengan kode pesanan dan nominal tersebut.
3. Customer memindai QR tersebut. Nominal sudah terkunci sesuai pesanan; customer tidak perlu mengetik nominal.
4. Midtrans mengirim HTTP webhook ke `midtrans-notification.php` setelah transaksi berstatus `settlement`.
5. Website mengecek signature SHA-512, kode pesanan, dan nominal sebelum mengubah `payment_status` menjadi `Sudah dibayar`.

Customer juga mempunyai tombol **Cek status pembayaran** sebagai cadangan. Tombol ini menanyakan status langsung ke API Midtrans tanpa menandai pesanan lunas sendiri.

### Cara mengaktifkan QRIS otomatis

1. Daftar/masuk ke dashboard Midtrans dan aktifkan QRIS pada akun merchant.
2. Untuk belajar, ambil Server Key dari lingkungan **Sandbox**. Untuk transaksi uang asli, gunakan Server Key **Production** setelah QRIS merchant disetujui.
3. Buka file `config.local.php` di VS Code. Jika belum ada, copy isi `config.local.php.example`.
4. Isi konfigurasi berikut, lalu simpan:

```php
return [
    'midtrans_environment' => 'sandbox',
    'midtrans_server_key' => 'SERVER_KEY_DARI_DASHBOARD_MIDTRANS',
    'midtrans_qris_acquirer' => 'gopay',
];
```

5. Refresh toko. Pilihan `QRIS otomatis (Midtrans)` akan muncul di checkout.
6. Di dashboard Midtrans, masukkan URL notifikasi:

```text
https://domain-kamu.com/midtrans-notification.php
```

`localhost` hanya dapat dibuka dari komputer sendiri, jadi Midtrans tidak dapat mengirim webhook ke `http://localhost/...`. Saat latihan gunakan alamat HTTPS publik sementara atau deploy project ke hosting HTTPS terlebih dahulu.

### Keamanan yang dipakai

- `config.local.php` masuk `.gitignore` dan diblokir oleh `.htaccess`, jadi Server Key tidak diunggah ke GitHub atau dibuka lewat browser.
- Browser tidak pernah menerima Server Key. Gambar QR diambil melalui `qris.php` sebagai proxy aman.
- `midtrans-notification.php` menerima `POST` dari Midtrans dan memeriksa `signature_key`, nominal, serta kode pesanan sebelum memperbarui database.
- QRIS statis pada gambar lama tidak dapat diubah menjadi QR dinamis. Gunakan hanya QR yang dibuat Midtrans untuk pilihan QRIS otomatis.

### Jawaban singkat saat ditanya

> QRIS biasa dan e-wallet pada project saya masih dikonfirmasi admin. Untuk QRIS otomatis, website membuat QR dinamis melalui API Midtrans menggunakan total pesanan. Midtrans mengirim webhook yang diverifikasi signature-nya, lalu status pembayaran di database berubah otomatis menjadi sudah dibayar.

## 8. Kok Tombol Bisa Langsung Membuka WhatsApp?

Tombol WhatsApp memakai fitur resmi WhatsApp **Click to Chat**. Link dibentuk oleh fungsi berikut di `store.php`:

```php
function whatsapp_link(string $phone, string $message): string {
    return 'https://wa.me/' . normalize_phone($phone) . '?text=' . rawurlencode($message);
}
```

Contoh hasil link:

```text
https://wa.me/6281234567890?text=Halo%20RESBOB
```

Cara kerjanya:

1. Admin memasukkan nomor WhatsApp toko di dashboard admin.
2. Nomor disimpan pada tabel `store_settings` dengan key `store_whatsapp`.
3. Nomor `08...` diubah menjadi format internasional `628...` oleh `normalize_phone()`.
4. Saat customer klik **Hubungi toko**, browser membuka `wa.me` dengan nomor toko dan pesan yang sudah diisi.
5. Saat admin klik tombol **WA** pada tabel pesanan, browser membuka WhatsApp customer dengan pesan status pesanan.

Fitur ini **bukan pesan otomatis**. Sistem hanya membuka chat WhatsApp; user tetap menekan tombol kirim sendiri di aplikasi WhatsApp.

### Cara mengatur nomor toko

1. Login ke admin.
2. Pada bagian **WhatsApp untuk customer**, isi nomor, misalnya `081234567890`.
3. Klik **Simpan nomor**.
4. Customer kemudian dapat menggunakan tombol **Hubungi toko** di halaman pesanan.

### Jawaban singkat saat ditanya

> Tombol WhatsApp memakai link Click to Chat `https://wa.me/nomor?text=pesan`. Nomor toko disimpan di database, dinormalisasi ke format 62, lalu link dibuka di browser.

## 9. Alur Customer

1. Buka `index.php`.
2. Daftar akun atau masuk memakai email/nomor HP dan password.
3. Cari dan pilih buku.
4. Klik **Tambah ke keranjang**.
5. Buka bagian checkout.
6. Isi nomor HP, alamat, catatan, dan metode pembayaran.
7. Klik **Buat pesanan**.
8. Sistem mengurangi stok dan menyimpan pesanan di database.
9. Customer diarahkan ke `orders.php` untuk melihat kode, status, metode pembayaran, dan invoice.
10. Customer dapat klik **Hubungi toko** untuk membuka chat WhatsApp.

## 10. Alur Admin

1. Buka `admin.php`.
2. Login dengan email dan password admin.
3. Atur nomor WhatsApp toko jika belum ada.
4. Tambah, edit, hapus buku, atau ubah stok di inventori.
5. Buka bagian **Pesanan masuk**.
6. Ubah status pesanan: `Pesanan baru`, `Diproses`, `Selesai`, atau `Dibatalkan`.
7. Ubah status pembayaran menjadi `Sudah dibayar` setelah pembayaran dikonfirmasi.
8. Gunakan tombol `WA` untuk membuka chat customer.
9. Gunakan tombol `Invoice` untuk melihat atau print struk.

## 11. Fungsi File Utama

| File | Fungsi |
| --- | --- |
| `index.php` | Halaman toko, login customer, keranjang, checkout, dan pilihan pembayaran. |
| `admin.php` | Login admin, pengelolaan buku, stok, pesanan, pembayaran, dan WhatsApp toko. |
| `orders.php` | Customer melihat pesanan, status, pembayaran, invoice, dan tombol hubungi toko. |
| `invoice.php` | Menampilkan invoice yang bisa dicetak. |
| `store.php` | Kumpulan fungsi bisnis: buku, pesanan, stok, pembayaran, CSRF, WhatsApp, dan format rupiah. |
| `database.php` | Koneksi PDO serta pembuatan tabel database otomatis. |
| `config.php` | Konfigurasi database dan akun admin awal. |
| `data.php` | Data buku bawaan saat database pertama kali dibuat. |
| `style.css` | Tampilan seluruh halaman website. |

## 12. Pertanyaan yang Sering Muncul

### Apakah menggunakan framework?

Tidak. Project memakai PHP native agar proses login, session, dan database mudah dipelajari.

### Bagaimana customer login?

Customer membuat akun dengan nama, email atau nomor HP, dan password minimal 8 karakter. Password disimpan sebagai hash. Riwayat pesanan kemudian terhubung ke akun tersebut.

### Data pesanan tersimpan di mana?

Di MySQL, terutama pada tabel `orders` dan `order_items`.

### Apa fungsi PDO prepared statement?

Prepared statement memisahkan query SQL dari input form sehingga membantu mencegah SQL injection.

### Apa fungsi CSRF token?

CSRF token memastikan request form berasal dari halaman aplikasi sendiri, bukan dari form palsu di website lain.

### Mengapa stok harus memakai transaction?

Supaya stok dan pesanan selalu konsisten. Jika stok kurang, pesanan dan pengurangan stok sama-sama dibatalkan.

### Apakah QRIS sudah menerima uang asli?

Belum. Saat ini metode pembayaran bersifat simulasi dan admin mengonfirmasi pembayaran. QRIS asli memerlukan payment gateway dan akun merchant.

### Apakah tombol WhatsApp mengirim pesan otomatis?

Tidak. Tombol hanya membuka WhatsApp dengan nomor dan pesan yang sudah disiapkan. Pengguna tetap mengirim pesan secara manual.

## 13. Kalimat Presentasi Siap Pakai

> Project saya bernama RESBOB Book Store. Project ini dibuat memakai PHP native, MySQL, HTML, dan CSS dengan XAMPP sebagai server lokal. Customer membuat akun dengan email atau nomor HP dan password yang di-hash, lalu bisa memilih buku, checkout, memilih metode pembayaran QRIS atau e-wallet, serta memantau pesanan. Admin login memakai email dan password yang di-hash di database, dengan fitur penggantian password dari dashboard. Saat checkout berhasil, sistem menjalankan transaction untuk mengurangi stok, menyimpan pesanan, detail item, metode pembayaran, dan status pembayaran. Tombol WhatsApp menggunakan Click to Chat `wa.me`, sehingga customer atau admin bisa langsung membuka chat dengan pesan yang sudah disiapkan.

## 14. Link Penting

```text
Toko:     http://localhost/php-bookstore/index.php
Admin:    http://localhost/php-bookstore/admin.php
Pesanan:  http://localhost/php-bookstore/orders.php
```
