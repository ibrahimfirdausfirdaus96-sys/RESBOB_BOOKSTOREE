<?php

// File lokal ini tidak ikut GitHub. Isi Server Key hanya dari Midtrans Dashboard.
// Saat nilainya kosong, pilihan QRIS otomatis tidak akan tampil saat checkout.
return [
    'midtrans_environment' => 'sandbox',
    'midtrans_server_key' => '',
    'midtrans_qris_acquirer' => 'gopay',
];
