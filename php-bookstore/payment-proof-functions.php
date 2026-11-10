<?php

/**
 * Folder penyimpanan bukti berada di luar htdocs.
 *
 * Jika proyek berada di:
 * C:\xampp\htdocs\php-bookstore
 *
 * maka bukti disimpan di:
 * C:\xampp\resbob-private\payment-proofs
 */
function payment_proof_directory(): string {
    return dirname(__DIR__, 2)
        . DIRECTORY_SEPARATOR
        . 'resbob-private'
        . DIRECTORY_SEPARATOR
        . 'payment-proofs';
}

/**
 * Menyimpan bukti pembayaran manual satu kali.
 *
 * PENTING:
 * Pemanggil harus memastikan pelanggan berhak mengakses
 * pesanan sebelum memanggil fungsi ini.
 */
function save_payment_proof(string $code, array $upload): void {
    $uploadError = (int)(
        $upload['error'] ?? UPLOAD_ERR_NO_FILE
    );

    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException(
            'Upload gagal. Pilih gambar dan coba lagi.'
        );
    }

    $size = (int)($upload['size'] ?? 0);
    $temporaryFile = (string)($upload['tmp_name'] ?? '');

    if ($size < 1 || $size > 3 * 1024 * 1024) {
        throw new InvalidArgumentException(
            'Ukuran bukti pembayaran maksimal 3 MB.'
        );
    }

    if (!is_uploaded_file($temporaryFile)) {
        throw new InvalidArgumentException(
            'File upload tidak valid.'
        );
    }

    $imageInfo = @getimagesize($temporaryFile);

    $allowedTypes = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
    ];

    if (
        $imageInfo === false
        || !isset($allowedTypes[$imageInfo[2]])
        || $imageInfo[0] > 8000
        || $imageInfo[1] > 8000
    ) {
        throw new InvalidArgumentException(
            'Bukti harus berupa gambar JPG atau PNG yang valid.'
        );
    }

    $directory = payment_proof_directory();

    if (
        !is_dir($directory)
        && !mkdir($directory, 0770, true)
        && !is_dir($directory)
    ) {
        throw new RuntimeException(
            'Folder bukti pembayaran tidak dapat dibuat.'
        );
    }

    $extension = $allowedTypes[$imageInfo[2]];

    $filename = bin2hex(random_bytes(20))
        . '.'
        . $extension;

    $destination = $directory
        . DIRECTORY_SEPARATOR
        . $filename;

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $statement = $pdo->prepare("
            SELECT
                payment_method,
                payment_status,
                status,
                payment_proof
            FROM orders
            WHERE code = :code
            LIMIT 1
            FOR UPDATE
        ");

        $statement->execute([
            ':code' => $code,
        ]);

        $order = $statement->fetch();

        if (!$order) {
            throw new RuntimeException(
                'Pesanan tidak ditemukan.'
            );
        }

        $manualMethods = [
            'QRIS',
            'DANA',
            'GoPay',
            'OVO',
        ];

        if (
            !in_array(
                $order['payment_method'],
                $manualMethods,
                true
            )
        ) {
            throw new RuntimeException(
                'Metode pembayaran ini tidak menggunakan bukti manual.'
            );
        }

        if (
            $order['status'] === 'Dibatalkan'
            || $order['payment_status'] === 'Sudah dibayar'
        ) {
            throw new RuntimeException(
                'Bukti tidak dapat dikirim untuk pesanan ini.'
            );
        }

        if (!empty($order['payment_proof'])) {
            throw new RuntimeException(
                'Bukti pembayaran sudah dikirim. '
                . 'Hubungi toko jika bukti yang dikirim salah.'
            );
        }

        if (!move_uploaded_file($temporaryFile, $destination)) {
            throw new RuntimeException(
                'Bukti pembayaran tidak dapat disimpan.'
            );
        }

        $update = $pdo->prepare("
            UPDATE orders
            SET payment_proof = :filename
            WHERE code = :code
        ");

        $update->execute([
            ':filename' => $filename,
            ':code' => $code,
        ]);

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        if (is_file($destination)) {
            @unlink($destination);
        }

        throw $exception;
    }
}
