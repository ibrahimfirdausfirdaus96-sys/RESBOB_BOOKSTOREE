<?php

function return_evidence_directory(): string {
    return dirname(__DIR__, 2)
        . DIRECTORY_SEPARATOR . 'resbob-private'
        . DIRECTORY_SEPARATOR . 'return-evidence';
}

function save_return_evidence(array $uploads): array {
    $names = $uploads['name'] ?? [];
    $temps = $uploads['tmp_name'] ?? [];
    $sizes = $uploads['size'] ?? [];
    $errors = $uploads['error'] ?? [];

    if (!is_array($names) || count($names) < 1 || count($names) > 3) {
        throw new InvalidArgumentException('Unggah 1 sampai 3 foto bukti.');
    }

    $totalSize = 0;
    $stored = [];
    $directory = return_evidence_directory();

    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('Folder bukti retur tidak dapat dibuat.');
    }

    try {
        foreach ($names as $index => $_name) {
            $error = (int)($errors[$index] ?? UPLOAD_ERR_NO_FILE);
            $temp = (string)($temps[$index] ?? '');
            $size = (int)($sizes[$index] ?? 0);
            $totalSize += $size;

            if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($temp)) {
                throw new InvalidArgumentException('Salah satu foto bukti tidak valid.');
            }

            $image = @getimagesize($temp);
            $extensions = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png'];

            if ($image === false || !isset($extensions[$image[2]]) || $image[0] > 8000 || $image[1] > 8000) {
                throw new InvalidArgumentException('Foto bukti harus berupa JPG atau PNG yang valid.');
            }

            $filename = bin2hex(random_bytes(20)) . '.' . $extensions[$image[2]];
            if (!move_uploaded_file($temp, $directory . DIRECTORY_SEPARATOR . $filename)) {
                throw new RuntimeException('Foto bukti retur tidak dapat disimpan.');
            }
            $stored[] = $filename;
        }

        if ($totalSize > 5 * 1024 * 1024) {
            throw new InvalidArgumentException('Total ukuran foto bukti maksimal 5 MB.');
        }
    } catch (Throwable $exception) {
        foreach ($stored as $filename) {
            @unlink($directory . DIRECTORY_SEPARATOR . $filename);
        }
        throw $exception;
    }

    return $stored;
}

function create_return_request(string $code, array $quantities, string $reason, string $description, array $uploads): void {
    $reasons = ['Barang rusak', 'Barang tidak sesuai', 'Barang kurang', 'Lainnya'];
    $reason = trim($reason);
    $description = trim($description);

    if (!in_array($reason, $reasons, true) || mb_strlen($description) < 15 || mb_strlen($description) > 1000) {
        throw new InvalidArgumentException('Lengkapi alasan dan kronologi retur minimal 15 karakter.');
    }

    $pdo = db();
    $orderStatement = $pdo->prepare('SELECT id, status, delivery_status FROM orders WHERE code = :code LIMIT 1');
    $orderStatement->execute([':code' => $code]);
    $order = $orderStatement->fetch();

    if (!$order || $order['status'] !== 'Selesai' || $order['delivery_status'] !== 'Sampai') {
        throw new InvalidArgumentException('Retur hanya dapat diajukan setelah pesanan diterima.');
    }

    $exists = $pdo->prepare('SELECT id FROM return_requests WHERE order_id = :order_id LIMIT 1');
    $exists->execute([':order_id' => (int)$order['id']]);
    if ($exists->fetchColumn()) {
        throw new InvalidArgumentException('Pengajuan retur untuk pesanan ini sudah ada.');
    }

    $itemsStatement = $pdo->prepare('SELECT id, title, quantity FROM order_items WHERE order_id = :order_id');
    $itemsStatement->execute([':order_id' => (int)$order['id']]);
    $orderItems = $itemsStatement->fetchAll();

    // Pesanan dengan satu buku selalu meretur satu buku itu. Ini menghindari
    // kegagalan form jika browser lama masih mengirim nilai jumlah 0.
    if (count($orderItems) === 1) {
        $onlyItemId = (int)$orderItems[0]['id'];
        $quantities[$onlyItemId] = max(1, (int)($quantities[$onlyItemId] ?? 1));
    }

    $selected = [];
    foreach ($orderItems as $item) {
        $quantity = max(0, (int)($quantities[(int)$item['id']] ?? 0));
        if ($quantity > (int)$item['quantity']) {
            throw new InvalidArgumentException('Jumlah retur tidak boleh melebihi jumlah pembelian.');
        }
        if ($quantity > 0) {
            $selected[] = ['id' => (int)$item['id'], 'title' => $item['title'], 'quantity' => $quantity];
        }
    }

    if (!$selected) {
        throw new InvalidArgumentException('Pilih minimal satu barang dari pesanan ini.');
    }

    $evidence = save_return_evidence($uploads);
    $pdo->beginTransaction();
    try {
        $request = $pdo->prepare('INSERT INTO return_requests (order_id, reason, description, evidence) VALUES (:order_id, :reason, :description, :evidence)');
        $request->execute([
            ':order_id' => (int)$order['id'], ':reason' => $reason,
            ':description' => $description, ':evidence' => json_encode($evidence),
        ]);
        $requestId = (int)$pdo->lastInsertId();
        $insertItem = $pdo->prepare('INSERT INTO return_request_items (return_request_id, order_item_id, title, quantity) VALUES (:request_id, :item_id, :title, :quantity)');
        foreach ($selected as $item) {
            $insertItem->execute([':request_id' => $requestId, ':item_id' => $item['id'], ':title' => $item['title'], ':quantity' => $item['quantity']]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        foreach ($evidence as $filename) @unlink(return_evidence_directory() . DIRECTORY_SEPARATOR . $filename);
        throw $exception;
    }
}

function find_return_request_by_order_code(string $code): ?array {
    $statement = db()->prepare('SELECT r.* FROM return_requests r JOIN orders o ON o.id = r.order_id WHERE o.code = :code LIMIT 1');
    $statement->execute([':code' => $code]);
    $request = $statement->fetch();
    if (!$request) return null;
    $items = db()->prepare('SELECT title, quantity FROM return_request_items WHERE return_request_id = :id');
    $items->execute([':id' => (int)$request['id']]);
    $request['items'] = $items->fetchAll();
    $request['evidence'] = json_decode((string)$request['evidence'], true) ?: [];
    return $request;
}

function all_return_requests(): array {
    $rows = db()->query('SELECT r.*, o.code, o.customer_name FROM return_requests r JOIN orders o ON o.id = r.order_id ORDER BY r.created_at DESC')->fetchAll();
    foreach ($rows as &$row) {
        $items = db()->prepare('SELECT title, quantity FROM return_request_items WHERE return_request_id = :id');
        $items->execute([':id' => (int)$row['id']]);
        $row['items'] = $items->fetchAll();
        $row['evidence'] = json_decode((string)$row['evidence'], true) ?: [];
    }
    unset($row);
    return $rows;
}

function update_return_request(int $id, string $status, string $adminNote): void {
    $allowed = ['Diajukan', 'Diterima', 'Ditolak', 'Barang diterima', 'Refund diproses'];
    if (!in_array($status, $allowed, true)) throw new InvalidArgumentException('Status retur tidak valid.');
    $statement = db()->prepare('UPDATE return_requests SET status = :status, admin_note = :note WHERE id = :id');
    $statement->execute([':status' => $status, ':note' => trim($adminNote), ':id' => $id]);
}
