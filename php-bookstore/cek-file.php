<?php

header('Content-Type: text/plain; charset=utf-8');

echo "Folder aplikasi: " . __DIR__ . PHP_EOL;

$kode = file_get_contents(__DIR__ . '/database.php');

if (preg_match('/^\s*seed_admin\s*\(\s*\$pdo\s*\)\s*;/m', $kode)) {
    echo "seed_admin: MASIH AKTIF";
} else {
    echo "seed_admin: tidak ditemukan dalam bentuk aktif";
}
