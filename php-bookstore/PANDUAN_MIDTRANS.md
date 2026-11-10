# Panduan QRIS Otomatis Midtrans

File ini menjelaskan cara mengaktifkan QRIS dengan nominal otomatis di RESBOB Book Store.

## Sebelum mulai

Ada dua mode:

- `sandbox`: latihan. Tidak memakai uang asli.
- `production`: transaksi asli. QRIS merchant harus sudah aktif di akun Midtrans.

Jangan mengirim Server Key lewat chat, screenshot, atau GitHub. Kunci ini seperti kata sandi khusus server.

## 1. Siapkan akun Midtrans

1. Buka dashboard Midtrans dan buat/masuk ke akun merchant.
2. Mulai dari lingkungan Sandbox untuk mencoba integrasi.
3. Ambil `Server Key` dari menu akses API di dashboard Sandbox.
4. Jika ingin menerima uang asli, selesaikan aktivasi QRIS merchant lalu ambil Server Key Production.

Dokumentasi QRIS: https://docs.midtrans.com/reference/qris

## 2. Isi Server Key di project

1. Buka folder project di VS Code.
2. Buka file `config.local.php`.
3. Isi seperti berikut. Hanya ganti teks pada Server Key.

```php
<?php

return [
    'midtrans_environment' => 'sandbox',
    'midtrans_server_key' => 'SB-Mid-server-KEY-KAMU',
    'midtrans_qris_acquirer' => 'gopay',
];
```

4. Simpan file lalu refresh `http://localhost/php-bookstore`.
5. Di checkout akan muncul pilihan `QRIS otomatis (Midtrans)`.

Untuk transaksi asli, ubah menjadi:

```php
'midtrans_environment' => 'production',
'midtrans_server_key' => 'Mid-server-KEY-PRODUCTION-KAMU',
```

Jangan pernah memilih `production` sebelum QRIS merchant benar-benar aktif.

## 3. Cara kerja ketika customer checkout

1. Customer memilih `QRIS otomatis (Midtrans)`.
2. Website menghitung total dari database: harga buku, diskon, dan ongkir.
3. Website mengirim total itu ke Midtrans.
4. Midtrans mengembalikan QR khusus untuk kode pesanan tersebut.
5. Customer scan QR. Nominal sudah sesuai total pesanan.
6. Midtrans mengirim notifikasi pembayaran `settlement`.
7. Website mengecek signature notifikasi, kode pesanan, dan nominal, lalu mengubah status menjadi `Sudah dibayar`.

Jika notifikasi lambat, customer bisa klik **Cek status pembayaran**. Tombol itu meminta status ke Midtrans; tombol tidak bisa memalsukan pembayaran.

## 4. Agar status berubah otomatis

Midtrans harus bisa membuka URL project. `localhost` hanya bisa dibuka dari komputer sendiri, jadi tidak dapat menerima webhook otomatis dari internet.

Untuk mode otomatis penuh, deploy project PHP ke hosting yang mendukung HTTPS, kemudian di dashboard Midtrans isi URL notifikasi berikut:

```text
https://domain-kamu.com/midtrans-notification.php
```

Alamat harus menggunakan `https://` dan dapat dibuka dari internet. Untuk latihan lokal, QR dapat dibuat dan tombol **Cek status pembayaran** tetap dapat digunakan, tetapi webhook otomatis baru berjalan setelah ada alamat HTTPS publik.

## 5. Tes aman di Sandbox

1. Pastikan `midtrans_environment` bernilai `sandbox`.
2. Checkout satu buku dengan pilihan `QRIS otomatis (Midtrans)`.
3. Pastikan halaman pesanan menampilkan QR baru dan nominal yang benar.
4. Selesaikan pembayaran uji mengikuti simulator/metode uji dari dashboard Midtrans.
5. Kembali ke Pesanan Saya dan klik **Cek status pembayaran**.
6. Status harus berubah menjadi `Sudah dibayar` hanya bila Midtrans mengembalikan status sukses.

## 6. Jika ada masalah

| Gejala | Yang diperiksa |
| --- | --- |
| Pilihan QRIS otomatis tidak muncul | Pastikan `midtrans_server_key` di `config.local.php` tidak kosong. |
| QR gagal dibuat | Pastikan Server Key cocok dengan mode Sandbox/Production dan QRIS sudah aktif. |
| Status belum berubah otomatis | Pastikan URL webhook memakai HTTPS publik, bukan `localhost`. |
| Status masih menunggu setelah bayar | Klik **Cek status pembayaran**. Periksa juga log notifikasi di dashboard Midtrans. |
| Server Key terlihat di GitHub | Segera revoke/regenerate key dari dashboard Midtrans, lalu pastikan `config.local.php` tetap di `.gitignore`. |

## Jawaban singkat untuk presentasi

> Saya memakai QRIS dinamis Midtrans. Nominal QR dibuat berdasarkan total pesanan dari database. Setelah customer membayar, Midtrans mengirim webhook ke server. Website memverifikasi signature, nominal, dan kode pesanan sebelum mengubah status pembayaran menjadi sudah dibayar.
