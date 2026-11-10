<?php

$config = [
    'admin_email' => 'ibrahimfirdausfirdaus96@gmail.com',
    'admin_password_hash' => '$2y$10$XY3m1YsLqV/OOr4L/x3IDOtgcbwQdExqAXrvdtpRejGpkPZz/IWfu',
    'db_host' => '127.0.0.1',
    'db_port' => 3307,
    'db_name' => 'resbob_book_store',
    'db_user' => 'root',
    'db_pass' => '',
    'midtrans_environment' => 'sandbox',
    'midtrans_server_key' => '',
    'midtrans_qris_acquirer' => 'gopay',
];

// Secret payment credentials live only in this ignored local file.
$localConfigPath = __DIR__ . '/config.local.php';
if (is_file($localConfigPath)) {
    $localConfig = require $localConfigPath;
    if (is_array($localConfig)) {
        $config = array_replace($config, $localConfig);
    }
}

return $config;
