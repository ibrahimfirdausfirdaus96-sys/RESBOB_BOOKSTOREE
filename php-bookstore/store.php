<?php

require_once __DIR__ . '/database.php';

function all_books(): array {
    $statement = db()->query(
        "SELECT * FROM products ORDER BY is_base DESC, id ASC"
    );

    return array_map('normalize_book', $statement->fetchAll());
}

function find_book_by_id(int $id): ?array {
    if ($id < 1) {
        return null;
    }

    $statement = db()->prepare(
        "SELECT * FROM products WHERE id = :id LIMIT 1"
    );

    $statement->execute([
        ':id' => $id,
    ]);

    $book = $statement->fetch();

    return $book ? normalize_book($book) : null;
}

function find_book(array $books, int $id): ?array {
    foreach ($books as $book) {
        if ((int)$book['id'] === $id) {
            return $book;
        }
    }

    return null;
}

function custom_books(): array {
    $statement = db()->query(
        "SELECT * FROM products WHERE is_base = 0 ORDER BY id DESC"
    );

    return array_map('normalize_book', $statement->fetchAll());
}

function all_categories(): array {
    return db()->query("
        SELECT id, name, created_at
        FROM categories
        ORDER BY name ASC
    ")->fetchAll();
}

function save_category(string $name): void {
    $name = trim($name);

    if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
        throw new InvalidArgumentException(
            'Nama kategori harus terdiri dari 2 sampai 80 karakter.'
        );
    }

    $statement = db()->prepare("
        INSERT INTO categories (name)
        VALUES (:name)
    ");

    try {
        $statement->execute([':name' => $name]);
    } catch (PDOException $exception) {
        if ((string)$exception->getCode() === '23000') {
            throw new InvalidArgumentException('Kategori tersebut sudah ada.');
        }

        throw $exception;
    }
}

function delete_category(int $id): void {
    $pdo = db();
    $find = $pdo->prepare('SELECT name FROM categories WHERE id = :id LIMIT 1');
    $find->execute([':id' => $id]);
    $name = (string)($find->fetchColumn() ?: '');

    if ($name === '') {
        throw new InvalidArgumentException('Kategori tidak ditemukan.');
    }

    $usage = $pdo->prepare('SELECT COUNT(*) FROM products WHERE category = :name');
    $usage->execute([':name' => $name]);

    if ((int)$usage->fetchColumn() > 0) {
        throw new InvalidArgumentException(
            'Kategori masih digunakan oleh buku dan tidak dapat dihapus.'
        );
    }

    $delete = $pdo->prepare('DELETE FROM categories WHERE id = :id');
    $delete->execute([':id' => $id]);
}

function save_book(array $input, array $files = []): array {
    $id = (int)($input['id'] ?? 0);
    $existing = $id > 0 ? find_book_by_id($id) : null;

    if ($id > 0 && !$existing) {
        throw new InvalidArgumentException(
            'Buku yang ingin diedit tidak ditemukan.'
        );
    }

    $title = trim((string)($input['title'] ?? ''));
    $author = trim((string)($input['author'] ?? ''));
    $category = trim((string)($input['category'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));

    if (
        $title === ''
        || $author === ''
        || $category === ''
        || $description === ''
    ) {
        throw new InvalidArgumentException(
            'Judul, penulis, kategori, dan deskripsi wajib diisi.'
        );
    }

    $coverResult = resolve_book_cover(
        (string)($input['cover'] ?? ''),
        $files['cover_upload'] ?? [],
        $title,
        $existing['cover'] ?? null
    );

    $values = [
        ':title' => $title,
        ':author' => $author,
        ':category' => $category,
        ':format' => trim((string)($input['format'] ?? 'Paperback'))
            ?: 'Paperback',
        ':price' => clean_number($input['price'] ?? 0, 50000),
        ':old_price' => clean_number($input['old_price'] ?? 0, 0)
            ?: null,
        ':rating' => max(
            1,
            min(5, (float)($input['rating'] ?? 4.5))
        ),
        ':badge' => trim((string)($input['badge'] ?? 'Baru'))
            ?: 'Baru',
        ':cover' => $coverResult['url'],
        ':description' => $description,
        ':stock' => max(0, (int)($input['stock'] ?? 0)),
    ];

    if ($existing) {
        $values[':id'] = $id;

        $statement = db()->prepare("
            UPDATE products SET
                title = :title,
                author = :author,
                category = :category,
                format = :format,
                price = :price,
                old_price = :old_price,
                rating = :rating,
                badge = :badge,
                cover = :cover,
                description = :description,
                stock = :stock
            WHERE id = :id
        ");

        $statement->execute($values);
    } else {
        $statement = db()->prepare("
            INSERT INTO products (
                title,
                author,
                category,
                format,
                price,
                old_price,
                rating,
                badge,
                cover,
                description,
                stock,
                is_base
            )
            VALUES (
                :title,
                :author,
                :category,
                :format,
                :price,
                :old_price,
                :rating,
                :badge,
                :cover,
                :description,
                :stock,
                0
            )
        ");

        $statement->execute($values);
    }

    return [
        'updated' => (bool)$existing,
        'cover_notice' => $coverResult['notice'],
    ];
}

function delete_book(int $id): bool {
    if ($id < 1) {
        return false;
    }

    $statement = db()->prepare(
        "DELETE FROM products WHERE id = :id"
    );

    $statement->execute([
        ':id' => $id,
    ]);

    return $statement->rowCount() > 0;
}

function resolve_book_cover(
    string $rawUrl,
    array $upload,
    string $title,
    ?string $currentCover = null
): array {
    $uploadedCover = store_cover_upload($upload);

    if ($uploadedCover !== null) {
        return [
            'url' => $uploadedCover,
            'notice' => 'Cover diambil dari file yang diupload.',
        ];
    }

    $rawUrl = trim(
        html_entity_decode($rawUrl, ENT_QUOTES, 'UTF-8')
    );

    if ($rawUrl === '') {
        return [
            'url' => $currentCover ?: placeholder_cover_url($title),
            'notice' => null,
        ];
    }

    $directUrl = extract_image_url($rawUrl);

    if ($directUrl !== null) {
        return [
            'url' => $directUrl,
            'notice' => null,
        ];
    }

    return [
        'url' => $currentCover ?: placeholder_cover_url($title),
        'notice' => 'Link tersebut adalah halaman pencarian, bukan file gambar. '
            . 'Cover sementara dipakai; klik gambar lalu pilih Salin alamat '
            . 'gambar, atau upload file cover.',
    ];
}

function extract_image_url(string $url): ?string {
    if (
        preg_match(
            '/^assets\/covers\/[a-z0-9_-]+\.(?:jpe?g|png|webp|gif)$/i',
            $url
        )
    ) {
        return $url;
    }

    $parts = parse_url($url);

    if ($parts === false) {
        return null;
    }

    $query = [];

    if (isset($parts['query'])) {
        parse_str($parts['query'], $query);
    }

    foreach (
        [
            'imgurl',
            'mediaurl',
            'mediaUrl',
            'image_url',
            'image',
            'img',
            'src',
        ] as $key
    ) {
        if (empty($query[$key]) || !is_string($query[$key])) {
            continue;
        }

        $candidate = trim(
            html_entity_decode($query[$key], ENT_QUOTES, 'UTF-8')
        );

        if (str_starts_with($candidate, '//')) {
            $candidate = 'https:' . $candidate;
        }

        if (
            !preg_match('#^https?://#i', $candidate)
            && preg_match('#^[a-z0-9.-]+\.[a-z]{2,}/#i', $candidate)
        ) {
            $candidate = 'https://' . $candidate;
        }

        if (
            filter_var($candidate, FILTER_VALIDATE_URL)
            && preg_match('#^https?://#i', $candidate)
        ) {
            return $candidate;
        }
    }

    if (
        !filter_var($url, FILTER_VALIDATE_URL)
        || !preg_match('#^https?://#i', $url)
    ) {
        return null;
    }

    $host = strtolower((string)($parts['host'] ?? ''));

    if (
        str_contains($host, 'google.')
        || str_contains($host, 'yahoo.')
        || str_contains($host, 'bing.com')
    ) {
        return null;
    }

    return $url;
}

function store_cover_upload(array $upload): ?string {
    $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;

    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException(
            'Upload cover gagal. Coba pilih file gambar lain.'
        );
    }

    if ((int)($upload['size'] ?? 0) > 3 * 1024 * 1024) {
        throw new InvalidArgumentException(
            'Ukuran cover maksimal 3 MB.'
        );
    }

    $imageInfo = @getimagesize(
        (string)($upload['tmp_name'] ?? '')
    );

    $extensions = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];

    if (
        $imageInfo === false
        || !isset($extensions[$imageInfo[2]])
    ) {
        throw new InvalidArgumentException(
            'Cover harus berupa JPG, PNG, WEBP, atau GIF.'
        );
    }

    $directory = __DIR__ . '/assets/covers';

    if (
        !is_dir($directory)
        && !mkdir($directory, 0775, true)
        && !is_dir($directory)
    ) {
        throw new RuntimeException(
            'Folder cover tidak dapat dibuat.'
        );
    }

    $filename = bin2hex(random_bytes(12))
        . '.'
        . $extensions[$imageInfo[2]];

    if (
        !move_uploaded_file(
            (string)$upload['tmp_name'],
            $directory . '/' . $filename
        )
    ) {
        throw new RuntimeException(
            'File cover tidak dapat disimpan.'
        );
    }

    return 'assets/covers/' . $filename;
}

function placeholder_cover_url(string $title): string {
    return 'cover-placeholder.php?title=' . rawurlencode($title);
}

function book_cover_url(string $cover, string $title): string {
    return extract_image_url($cover) ?: placeholder_cover_url($title);
}

function all_orders(): array {
    $orders = db()->query(
        "SELECT * FROM orders ORDER BY id DESC"
    )->fetchAll();

    return array_map('attach_order_items', $orders);
}

function find_order(string $code): ?array {
    $statement = db()->prepare(
        "SELECT * FROM orders WHERE code = :code LIMIT 1"
    );

    $statement->execute([
        ':code' => $code,
    ]);

    $order = $statement->fetch();

    return $order ? attach_order_items($order) : null;
}

function payment_methods(): array {
    return [
        'QRIS' => 'QRIS',
        'DANA' => 'DANA',
        'GoPay' => 'GoPay',
        'OVO' => 'OVO',
    ];
}




function payment_statuses(): array {
    return [
        'Menunggu pembayaran',
        'Sudah dibayar',
    ];
}

function delivery_statuses(): array {
    return ['Menunggu pengemasan', 'Dikemas', 'Dikirim', 'Sampai'];
}

function update_delivery(string $code, string $deliveryStatus, string $courier, string $trackingNumber): void {
    $adminStatuses = ['Menunggu pengemasan', 'Dikemas', 'Dikirim'];

    if (!in_array($deliveryStatus, $adminStatuses, true)) {
        throw new InvalidArgumentException('Status pengiriman tidak valid.');
    }

    $courier = trim($courier);
    $trackingNumber = trim($trackingNumber);

    if (mb_strlen($courier) > 60 || mb_strlen($trackingNumber) > 80) {
        throw new InvalidArgumentException('Data kurir atau nomor resi terlalu panjang.');
    }

    if ($deliveryStatus === 'Dikirim' && ($courier === '' || $trackingNumber === '')) {
        throw new InvalidArgumentException('Isi nama kurir dan nomor resi sebelum mengubah status menjadi Dikirim.');
    }

    $pdo = db();
    $check = $pdo->prepare('SELECT payment_status, status FROM orders WHERE code = :code LIMIT 1');
    $check->execute([':code' => $code]);
    $order = $check->fetch();

    if (!$order) {
        throw new InvalidArgumentException('Pesanan tidak ditemukan.');
    }

    if ($order['payment_status'] !== 'Sudah dibayar') {
        throw new InvalidArgumentException('Pengiriman dapat diperbarui setelah pembayaran dikonfirmasi.');
    }

    if ($order['status'] === 'Dibatalkan') {
        throw new InvalidArgumentException('Pesanan yang dibatalkan tidak dapat dikirim.');
    }

    $statement = $pdo->prepare("
        UPDATE orders
        SET delivery_status = :delivery_status,
            delivery_courier = :delivery_courier,
            tracking_number = :tracking_number
        WHERE code = :code
    ");
    $statement->execute([
        ':delivery_status' => $deliveryStatus,
        ':delivery_courier' => $courier !== '' ? $courier : null,
        ':tracking_number' => $trackingNumber !== '' ? $trackingNumber : null,
        ':code' => $code,
    ]);

    if (in_array($deliveryStatus, ['Dikemas', 'Dikirim'], true)) {
        update_order_status($code, 'Diproses', 'Sudah dibayar');
    }
}

function confirm_delivery_received(string $code): void {
    $pdo = db();
    $statement = $pdo->prepare("
        SELECT payment_status, delivery_status, status
        FROM orders
        WHERE code = :code
        LIMIT 1
    ");
    $statement->execute([':code' => $code]);
    $order = $statement->fetch();

    if (!$order) {
        throw new InvalidArgumentException('Pesanan tidak ditemukan.');
    }

    if ($order['payment_status'] !== 'Sudah dibayar') {
        throw new InvalidArgumentException('Pembayaran pesanan belum dikonfirmasi.');
    }

    if ($order['delivery_status'] !== 'Dikirim') {
        throw new InvalidArgumentException('Pesanan belum berstatus dikirim.');
    }

    $update = $pdo->prepare("
        UPDATE orders
        SET delivery_status = 'Sampai'
        WHERE code = :code
    ");
    $update->execute([':code' => $code]);

    update_order_status($code, 'Selesai', 'Sudah dibayar');
}

function midtrans_qris_method(): string {
    return 'QRIS Otomatis';
}

function is_midtrans_qris_order(array $order): bool {
    return ($order['payment_method'] ?? '') === midtrans_qris_method();
}

function midtrans_config(): array {
    $config = db_config();

    $environment = ($config['midtrans_environment'] ?? 'sandbox')
        === 'production'
        ? 'production'
        : 'sandbox';

    return [
        'environment' => $environment,
        'server_key' => trim(
            (string)($config['midtrans_server_key'] ?? '')
        ),
        'qris_acquirer' => trim(
            (string)($config['midtrans_qris_acquirer'] ?? 'gopay')
        ) ?: 'gopay',
    ];
}

function midtrans_is_configured(): bool {
    return midtrans_config()['server_key'] !== '';
}

function midtrans_api_base_url(): string {
    return midtrans_config()['environment'] === 'production'
        ? 'https://api.midtrans.com'
        : 'https://api.sandbox.midtrans.com';
}

function midtrans_request(
    string $method,
    string $path,
    ?array $payload = null
): array {
    $config = midtrans_config();

    if ($config['server_key'] === '') {
        throw new RuntimeException(
            'QRIS otomatis belum dikonfigurasi. '
            . 'Isi Server Key Midtrans terlebih dahulu.'
        );
    }

    $url = midtrans_api_base_url() . '/' . ltrim($path, '/');

    $body = $payload === null
        ? null
        : json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

    if ($payload !== null && $body === false) {
        throw new RuntimeException(
            'Data pembayaran tidak dapat diproses.'
        );
    }

    $curl = curl_init($url);

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_USERPWD => $config['server_key'] . ':',
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 20,
    ]);

    if ($body !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    }

    $responseBody = curl_exec($curl);
    $curlError = curl_error($curl);
    $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);

    curl_close($curl);

    if ($responseBody === false) {
        throw new RuntimeException(
            'Tidak dapat terhubung ke Midtrans: '
            . ($curlError ?: 'kesalahan tidak diketahui')
            . '.'
        );
    }

    $response = json_decode($responseBody, true);

    if (!is_array($response)) {
        throw new RuntimeException(
            'Respons Midtrans tidak dapat dibaca.'
        );
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $message = trim(
            (string)(
                $response['status_message']
                ?? 'Permintaan pembayaran ditolak oleh Midtrans.'
            )
        );

        throw new RuntimeException('Midtrans: ' . $message);
    }

    return $response;
}

function midtrans_qr_url_is_allowed(string $url): bool {
    $host = strtolower(
        (string)parse_url($url, PHP_URL_HOST)
    );

    return in_array(
        $host,
        [
            'api.midtrans.com',
            'api.sandbox.midtrans.com',
        ],
        true
    );
}

function midtrans_reference_setting_key(string $code): string {
    return 'midtrans_ref_' . strtolower(trim($code));
}

function save_midtrans_payment_metadata(
    string $code,
    string $reference
): void {
    if (trim($code) === '' || trim($reference) === '') {
        throw new RuntimeException(
            'Data pesanan pembayaran tidak valid.'
        );
    }

    $statement = db()->prepare("
        INSERT INTO store_settings (setting_key, setting_value)
        VALUES (:setting_key, :reference)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");

    $statement->execute([
        ':setting_key' => midtrans_reference_setting_key($code),
        ':reference' => trim($reference),
    ]);
}

function midtrans_payment_reference(string $code): string {
    $statement = db()->prepare("
        SELECT setting_value
        FROM store_settings
        WHERE setting_key = :setting_key
        LIMIT 1
    ");

    $statement->execute([
        ':setting_key' => midtrans_reference_setting_key($code),
    ]);

    return trim((string)($statement->fetchColumn() ?: ''));
}

function midtrans_qr_url_for_reference(string $reference): string {
    return midtrans_api_base_url()
        . '/v2/qris/'
        . rawurlencode($reference)
        . '/qr-code';
}

function create_midtrans_qris_payment(string $code): void {
    $order = find_order($code);

    if (!$order || !is_midtrans_qris_order($order)) {
        throw new RuntimeException(
            'Pesanan QRIS otomatis tidak ditemukan.'
        );
    }

    /*
     * item_details tidak dikirim agar jumlah detail buku yang belum
     * memperhitungkan ongkir dan diskon tidak bertentangan dengan
     * gross_amount. Uji payload ini di Midtrans sandbox.
     */
    $response = midtrans_request('POST', 'v2/charge', [
        'payment_type' => 'qris',
        'qris' => [
            'acquirer' => midtrans_config()['qris_acquirer'],
        ],
        'transaction_details' => [
            'order_id' => $order['code'],
            'gross_amount' => (int)$order['total'],
        ],
        'customer_details' => [
            'first_name' => mb_substr(
                (string)$order['customer']['name'],
                0,
                50
            ),
            'phone' => (string)$order['customer']['phone'],
            'shipping_address' => [
                'address' => mb_substr(
                    (string)$order['customer']['address'],
                    0,
                    200
                ),
            ],
        ],
    ]);

    $qrUrl = '';

    foreach (($response['actions'] ?? []) as $action) {
        if (
            ($action['name'] ?? '') === 'generate-qr-code'
            && !empty($action['url'])
        ) {
            $qrUrl = (string)$action['url'];
            break;
        }
    }

    if ($qrUrl === '' || !midtrans_qr_url_is_allowed($qrUrl)) {
        throw new RuntimeException(
            'Midtrans tidak mengembalikan QR pembayaran yang valid.'
        );
    }

    save_midtrans_payment_metadata(
        $order['code'],
        trim((string)($response['transaction_id'] ?? ''))
    );
}

function midtrans_qr_image(string $code): array {
    $order = find_order($code);

    $reference = $order
        ? midtrans_payment_reference($order['code'])
        : '';

    $url = $reference === ''
        ? ''
        : midtrans_qr_url_for_reference($reference);

    if (
        !$order
        || !is_midtrans_qris_order($order)
        || $url === ''
        || !midtrans_qr_url_is_allowed($url)
    ) {
        throw new RuntimeException(
            'QR pembayaran tidak tersedia.'
        );
    }

    $config = midtrans_config();
    $curl = curl_init($url);

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $config['server_key'] . ':',
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_TIMEOUT => 20,
    ]);

    $image = curl_exec($curl);
    $curlError = curl_error($curl);
    $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo(
        $curl,
        CURLINFO_CONTENT_TYPE
    );

    curl_close($curl);

    if (
        $image === false
        || $httpCode < 200
        || $httpCode >= 300
        || !str_starts_with(strtolower($contentType), 'image/')
    ) {
        throw new RuntimeException(
            'QR pembayaran tidak dapat dimuat'
            . ($curlError ? ': ' . $curlError : '.')
        );
    }

    return [
        'content' => $image,
        'content_type' => $contentType,
    ];
}

function apply_midtrans_payment_status(
    array $notification,
    bool $verifySignature
): string {
    $code = trim((string)($notification['order_id'] ?? ''));
    $order = $code !== '' ? find_order($code) : null;

    if (!$order || !is_midtrans_qris_order($order)) {
        throw new RuntimeException(
            'Pesanan Midtrans tidak ditemukan.'
        );
    }

    $grossAmount = (string)($notification['gross_amount'] ?? '');

    if (
        !is_numeric($grossAmount)
        || abs((float)$grossAmount - (float)$order['total']) > 0.001
    ) {
        throw new RuntimeException(
            'Nominal notifikasi pembayaran tidak cocok dengan pesanan.'
        );
    }

    if ($verifySignature) {
        $signature = (string)(
            $notification['signature_key'] ?? ''
        );

        $expected = hash(
            'sha512',
            $code
            . (string)($notification['status_code'] ?? '')
            . $grossAmount
            . midtrans_config()['server_key']
        );

        if (
            $signature === ''
            || !hash_equals($expected, $signature)
        ) {
            throw new RuntimeException(
                'Tanda tangan notifikasi Midtrans tidak valid.'
            );
        }
    }

    $reference = trim(
        (string)($notification['transaction_id'] ?? '')
    );

    if ($reference !== '') {
        save_midtrans_payment_metadata(
            $order['code'],
            $reference
        );
    }

    $status = strtolower(
        trim((string)($notification['transaction_status'] ?? 'pending'))
    );

    if (in_array($status, ['settlement', 'capture'], true)) {
        if ($order['status'] === 'Dibatalkan') {
            return 'ignored';
        }

        $statement = db()->prepare("
            UPDATE orders
            SET payment_status = 'Sudah dibayar'
            WHERE code = :code
        ");

        $statement->execute([
            ':code' => $code,
        ]);

        return 'paid';
    }

    if (
        in_array($status, ['deny', 'cancel', 'expire'], true)
        && $order['payment_status'] !== 'Sudah dibayar'
    ) {
        update_order_status(
            $code,
            'Dibatalkan',
            'Menunggu pembayaran'
        );

        return 'cancelled';
    }

    return 'pending';
}

function sync_midtrans_payment_status(string $code): string {
    $order = find_order($code);

    if (!$order || !is_midtrans_qris_order($order)) {
        throw new RuntimeException(
            'Pesanan QRIS otomatis tidak ditemukan.'
        );
    }

    $response = midtrans_request(
        'GET',
        'v2/' . rawurlencode($code) . '/status'
    );

    return apply_midtrans_payment_status($response, false);
}

function create_order(
    array $customer,
    array $cart,
    string $paymentMethod
): string {
    if (!array_key_exists($paymentMethod, payment_methods())) {
        throw new InvalidArgumentException(
            'Pilih metode pembayaran yang tersedia.'
        );
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $bookStatement = $pdo->prepare("
            SELECT id, title, price, stock
            FROM products
            WHERE id = :id
            LIMIT 1
            FOR UPDATE
        ");

        $stockStatement = $pdo->prepare("
            UPDATE products
            SET stock = stock - :quantity
            WHERE id = :id
              AND stock >= :minimum_stock
        ");

        $items = [];

        foreach ($cart as $id => $quantity) {
            $id = (int)$id;
            $quantity = (int)$quantity;

            if ($id < 1 || $quantity < 1) {
                continue;
            }

            $bookStatement->execute([
                ':id' => $id,
            ]);

            $book = $bookStatement->fetch();

            if (!$book) {
                throw new RuntimeException(
                    'Salah satu buku di keranjang sudah tidak tersedia. '
                    . 'Silakan muat ulang halaman.'
                );
            }

            if ((int)$book['stock'] < $quantity) {
                throw new RuntimeException(
                    'Stok buku "' . $book['title']
                    . '" tidak cukup. Sisa stok: '
                    . (int)$book['stock']
                    . '.'
                );
            }

            $items[] = [
                'id' => (int)$book['id'],
                'title' => (string)$book['title'],
                'price' => (int)$book['price'],
                'quantity' => $quantity,
                'line_total' => (int)$book['price'] * $quantity,
            ];
        }

        if (!$items) {
            throw new RuntimeException(
                'Keranjang masih kosong.'
            );
        }

        $subtotal = array_sum(
            array_column($items, 'line_total')
        );

        $shipping = $subtotal < 300000 ? 18000 : 0;

        $discount = $subtotal >= 300000
            ? (int)round($subtotal * 0.12)
            : 0;

        $grandTotal = $subtotal + $shipping - $discount;

        foreach ($items as $item) {
            $stockStatement->execute([
                ':id' => $item['id'],
                ':quantity' => $item['quantity'],
                ':minimum_stock' => $item['quantity'],
            ]);

            if ($stockStatement->rowCount() !== 1) {
                throw new RuntimeException(
                    'Stok buku "' . $item['title']
                    . '" baru saja habis. Coba checkout lagi.'
                );
            }
        }

        $code = 'RB-'
            . date('Ymd')
            . '-'
            . strtoupper(bin2hex(random_bytes(6)));

        $orderStatement = $pdo->prepare("
            INSERT INTO orders (
                code,
                customer_name,
                customer_phone,
                customer_address,
                customer_note,
                subtotal,
                shipping,
                discount,
                total,
                payment_method,
                payment_status,
                stock_reduced,
                status
            )
            VALUES (
                :code,
                :name,
                :phone,
                :address,
                :note,
                :subtotal,
                :shipping,
                :discount,
                :total,
                :payment_method,
                'Menunggu pembayaran',
                1,
                'Pesanan baru'
            )
        ");

        $orderStatement->execute([
            ':code' => $code,
            ':name' => trim(
                (string)($customer['name'] ?? 'Pelanggan')
            ),
            ':phone' => trim(
                (string)($customer['phone'] ?? '-')
            ),
            ':address' => trim(
                (string)($customer['address'] ?? '-')
            ),
            ':note' => trim(
                (string)($customer['note'] ?? '')
            ),
            ':subtotal' => $subtotal,
            ':shipping' => $shipping,
            ':discount' => $discount,
            ':total' => $grandTotal,
            ':payment_method' => $paymentMethod,
        ]);

        $orderId = (int)$pdo->lastInsertId();

        $itemStatement = $pdo->prepare("
            INSERT INTO order_items (
                order_id,
                product_id,
                title,
                price,
                quantity,
                line_total
            )
            VALUES (
                :order_id,
                :product_id,
                :title,
                :price,
                :quantity,
                :line_total
            )
        ");

        foreach ($items as $item) {
            $itemStatement->execute([
                ':order_id' => $orderId,
                ':product_id' => $item['id'],
                ':title' => $item['title'],
                ':price' => $item['price'],
                ':quantity' => $item['quantity'],
                ':line_total' => $item['line_total'],
            ]);
        }

        $pdo->commit();

        return $code;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }
}

function update_order_status(
    string $code,
    string $status,
    string $paymentStatus
): void {
    $allowed = [
        'Pesanan baru',
        'Diproses',
        'Selesai',
        'Dibatalkan',
    ];

    if (
        !in_array($status, $allowed, true)
        || !in_array($paymentStatus, payment_statuses(), true)
    ) {
        return;
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $orderStatement = $pdo->prepare("
            SELECT id, status, stock_reduced
            FROM orders
            WHERE code = :code
            LIMIT 1
            FOR UPDATE
        ");

        $orderStatement->execute([
            ':code' => $code,
        ]);

        $order = $orderStatement->fetch();

        if (!$order) {
            $pdo->rollBack();
            return;
        }

        $itemsStatement = $pdo->prepare("
            SELECT product_id, title, quantity
            FROM order_items
            WHERE order_id = :order_id
        ");

        $itemsStatement->execute([
            ':order_id' => (int)$order['id'],
        ]);

        $items = $itemsStatement->fetchAll();
        $stockReduced = (int)$order['stock_reduced'] === 1;
        $wasCancelled = $order['status'] === 'Dibatalkan';

        if (
            $status === 'Dibatalkan'
            && !$wasCancelled
            && $stockReduced
        ) {
            $restoreStock = $pdo->prepare("
                UPDATE products
                SET stock = stock + :quantity
                WHERE id = :id
            ");

            foreach ($items as $item) {
                $restoreStock->execute([
                    ':id' => (int)$item['product_id'],
                    ':quantity' => (int)$item['quantity'],
                ]);
            }

            $stockReduced = false;
        }

        if (
            $status !== 'Dibatalkan'
            && $wasCancelled
            && !$stockReduced
        ) {
            $reduceStock = $pdo->prepare("
                UPDATE products
                SET stock = stock - :quantity
                WHERE id = :id
                  AND stock >= :minimum_stock
            ");

            foreach ($items as $item) {
                $reduceStock->execute([
                    ':id' => (int)$item['product_id'],
                    ':quantity' => (int)$item['quantity'],
                    ':minimum_stock' => (int)$item['quantity'],
                ]);

                if ($reduceStock->rowCount() !== 1) {
                    throw new RuntimeException(
                        'Stok buku "' . $item['title']
                        . '" tidak cukup untuk mengaktifkan kembali '
                        . 'pesanan ini.'
                    );
                }
            }

            $stockReduced = true;
        }

        $statement = $pdo->prepare("
            UPDATE orders
            SET
                status = :status,
                payment_status = :payment_status,
                stock_reduced = :stock_reduced
            WHERE id = :id
        ");

        $statement->execute([
            ':status' => $status,
            ':payment_status' => $paymentStatus,
            ':stock_reduced' => $stockReduced ? 1 : 0,
            ':id' => (int)$order['id'],
        ]);

        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }
}

function delete_order(string $code): void {
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $orderStatement = $pdo->prepare("
            SELECT id, code, stock_reduced
            FROM orders
            WHERE code = :code
            LIMIT 1
            FOR UPDATE
        ");

        $orderStatement->execute([
            ':code' => $code,
        ]);

        $order = $orderStatement->fetch();

        if (!$order) {
            $pdo->rollBack();
            return;
        }

        if ((int)$order['stock_reduced'] === 1) {
            $itemsStatement = $pdo->prepare("
                SELECT product_id, quantity
                FROM order_items
                WHERE order_id = :order_id
            ");

            $itemsStatement->execute([
                ':order_id' => (int)$order['id'],
            ]);

            $restoreStock = $pdo->prepare("
                UPDATE products
                SET stock = stock + :quantity
                WHERE id = :id
            ");

            foreach ($itemsStatement->fetchAll() as $item) {
                $restoreStock->execute([
                    ':id' => (int)$item['product_id'],
                    ':quantity' => (int)$item['quantity'],
                ]);
            }
        }

        $paymentStatement = $pdo->prepare("
            DELETE FROM store_settings
            WHERE setting_key = :setting_key
        ");

        $paymentStatement->execute([
            ':setting_key' => midtrans_reference_setting_key(
                (string)$order['code']
            ),
        ]);

        $statement = $pdo->prepare("
            DELETE FROM orders WHERE id = :id
        ");

        $statement->execute([
            ':id' => (int)$order['id'],
        ]);

        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }
}

function store_whatsapp_number(): string {
    $statement = db()->prepare("
        SELECT setting_value
        FROM store_settings
        WHERE setting_key = 'store_whatsapp'
        LIMIT 1
    ");

    $statement->execute();

    return normalize_phone(
        (string)($statement->fetchColumn() ?: '')
    );
}

function update_store_whatsapp_number(string $phone): void {
    $phone = trim($phone);
    $normalized = normalize_phone($phone);

    if (
        $phone !== ''
        && !preg_match('/^62[0-9]{8,13}$/', $normalized)
    ) {
        throw new InvalidArgumentException(
            'Masukkan nomor WhatsApp Indonesia yang valid, '
            . 'contoh: 081234567890.'
        );
    }

    $statement = db()->prepare("
        INSERT INTO store_settings (setting_key, setting_value)
        VALUES ('store_whatsapp', :phone)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");

    $statement->execute([
        ':phone' => $normalized,
    ]);
}

function customer_identity(string $rawIdentity): ?array {
    $identity = trim($rawIdentity);

    if ($identity === '') {
        return null;
    }

    if (filter_var($identity, FILTER_VALIDATE_EMAIL)) {
        return [
            'type' => 'email',
            'value' => strtolower($identity),
        ];
    }

    $phone = normalize_phone($identity);

    if (preg_match('/^62[0-9]{8,13}$/', $phone)) {
        return [
            'type' => 'phone',
            'value' => $phone,
        ];
    }

    return null;
}

function customer_setting_key(array $identity): string {
    return 'customer_'
        . hash(
            'sha256',
            $identity['type'] . ':' . $identity['value']
        );
}

function find_customer_account(array $identity): ?array {
    $customer = db()->prepare("
        SELECT name, identity_type, identity, password_hash
        FROM customers
        WHERE identity = :identity
        LIMIT 1
    ");

    $customer->execute([':identity' => $identity['value']]);
    $account = $customer->fetch();

    if ($account) {
        return [
            'name' => (string)$account['name'],
            'identity_type' => (string)$account['identity_type'],
            'identity' => (string)$account['identity'],
            'password_hash' => (string)$account['password_hash'],
        ];
    }

    $statement = db()->prepare("
        SELECT setting_value
        FROM store_settings
        WHERE setting_key = :setting_key
        LIMIT 1
    ");

    $statement->execute([
        ':setting_key' => customer_setting_key($identity),
    ]);

    $account = json_decode(
        (string)($statement->fetchColumn() ?: ''),
        true
    );

    if (
        !is_array($account)
        || empty($account['name'])
        || empty($account['password_hash'])
    ) {
        return null;
    }

    return [
        'name' => (string)$account['name'],
        'identity_type' => $identity['type'],
        'identity' => $identity['value'],
        'password_hash' => (string)$account['password_hash'],
    ];
}

function register_customer_account(
    string $name,
    string $rawIdentity,
    string $password
): array {
    $name = trim($name);
    $identity = customer_identity($rawIdentity);

    if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
        throw new InvalidArgumentException(
            'Nama harus terdiri dari 2 sampai 80 karakter.'
        );
    }

    if ($identity === null) {
        throw new InvalidArgumentException(
            'Masukkan email yang valid atau nomor HP Indonesia yang valid.'
        );
    }

    if (strlen($password) < 8) {
        throw new InvalidArgumentException(
            'Password minimal 8 karakter.'
        );
    }

    if (find_customer_account($identity) !== null) {
        throw new InvalidArgumentException(
            'Email atau nomor HP tersebut sudah memiliki akun. '
            . 'Silakan masuk.'
        );
    }

    $statement = db()->prepare("
        INSERT INTO customers (name, identity_type, identity, password_hash)
        VALUES (:name, :identity_type, :identity, :password_hash)
    ");

    $statement->execute([
        ':name' => $name,
        ':identity_type' => $identity['type'],
        ':identity' => $identity['value'],
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);

    return [
        'name' => $name,
        'identity_type' => $identity['type'],
        'identity' => $identity['value'],
    ];
}

function all_customers(): array {
    return db()->query("
        SELECT id, name, identity_type, identity, created_at
        FROM customers
        ORDER BY created_at DESC, id DESC
    ")->fetchAll();
}

function authenticate_customer_account(
    string $rawIdentity,
    string $password
): array {
    $identity = customer_identity($rawIdentity);

    if ($identity === null || $password === '') {
        throw new InvalidArgumentException(
            'Masukkan email atau nomor HP serta password.'
        );
    }

    $account = find_customer_account($identity);

    if (
        $account === null
        || !password_verify($password, $account['password_hash'])
    ) {
        throw new InvalidArgumentException(
            'Email/nomor HP atau password tidak cocok.'
        );
    }

    return [
        'name' => $account['name'],
        'identity_type' => $account['identity_type'],
        'identity' => $account['identity'],
    ];
}

function customer_order_history_key(array $customer): string {
    if (
        empty($customer['identity_type'])
        || empty($customer['identity'])
    ) {
        throw new InvalidArgumentException(
            'Data akun customer tidak valid.'
        );
    }

    return 'customer_orders_'
        . hash(
            'sha256',
            $customer['identity_type'] . ':' . $customer['identity']
        );
}

function customer_order_codes(array $customer): array {
    try {
        $statement = db()->prepare("
            SELECT setting_value
            FROM store_settings
            WHERE setting_key = :setting_key
            LIMIT 1
        ");

        $statement->execute([
            ':setting_key' => customer_order_history_key($customer),
        ]);

        $codes = json_decode(
            (string)($statement->fetchColumn() ?: '[]'),
            true
        );

        if (!is_array($codes)) {
            return [];
        }

        return array_values(
            array_unique(
                array_filter(
                    $codes,
                    fn($code) => is_string($code)
                        && preg_match('/^RB-[A-Z0-9-]+$/', $code)
                )
            )
        );
    } catch (Throwable) {
        return [];
    }
}

function remember_customer_order(
    array $customer,
    string $code
): void {
    if (!preg_match('/^RB-[A-Z0-9-]+$/', $code)) {
        throw new InvalidArgumentException(
            'Kode pesanan tidak valid.'
        );
    }

    $codes = customer_order_codes($customer);
    $codes[] = $code;
    $codes = array_values(array_unique($codes));
    $codes = array_slice($codes, -10);

    $encoded = json_encode($codes);

    if ($encoded === false || strlen($encoded) > 255) {
        throw new RuntimeException(
            'Riwayat pesanan customer tidak dapat disimpan.'
        );
    }

    $statement = db()->prepare("
        INSERT INTO store_settings (setting_key, setting_value)
        VALUES (:setting_key, :setting_value)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");

    $statement->execute([
        ':setting_key' => customer_order_history_key($customer),
        ':setting_value' => $encoded,
    ]);
}

function find_admin_by_email(string $email): ?array {
    $statement = db()->prepare("
        SELECT *
        FROM admins
        WHERE LOWER(email) = LOWER(:email)
        LIMIT 1
    ");

    $statement->execute([
        ':email' => trim($email),
    ]);

    $admin = $statement->fetch();

    return $admin ?: null;
}

function change_admin_password(
    string $email,
    string $currentPassword,
    string $newPassword,
    string $confirmation
): void {
    if (
        $currentPassword === ''
        || $newPassword === ''
        || $confirmation === ''
    ) {
        throw new InvalidArgumentException(
            'Lengkapi seluruh kolom password.'
        );
    }

    if (mb_strlen($newPassword) < 10) {
        throw new InvalidArgumentException(
            'Password baru minimal 10 karakter.'
        );
    }

    if (!hash_equals($newPassword, $confirmation)) {
        throw new InvalidArgumentException(
            'Konfirmasi password baru tidak sama.'
        );
    }

    $admin = find_admin_by_email($email);

    if (
        !$admin
        || !password_verify(
            $currentPassword,
            (string)$admin['password_hash']
        )
    ) {
        throw new InvalidArgumentException(
            'Password saat ini tidak cocok.'
        );
    }

    if (
        password_verify(
            $newPassword,
            (string)$admin['password_hash']
        )
    ) {
        throw new InvalidArgumentException(
            'Password baru harus berbeda dari password saat ini.'
        );
    }

    $statement = db()->prepare("
        UPDATE admins
        SET password_hash = :password_hash
        WHERE id = :id
    ");

    $statement->execute([
        ':password_hash' => password_hash(
            $newPassword,
            PASSWORD_DEFAULT
        ),
        ':id' => (int)$admin['id'],
    ]);
}

function rupiah(int $value): string {
    return 'Rp' . number_format($value, 0, ',', '.');
}

function clean_text(mixed $value): string {
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function clean_number(
    mixed $value,
    int $fallback = 0
): int {
    $number = (int)preg_replace(
        '/[^0-9]/',
        '',
        (string)$value
    );

    return $number > 0 ? $number : $fallback;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="'
        . clean_text(csrf_token())
        . '">';
}

function verify_csrf(): void {
    $token = (string)($_POST['csrf_token'] ?? '');

    if (
        $token === ''
        || empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        http_response_code(403);
        exit('Permintaan ditolak. Token keamanan tidak valid.');
    }
}

function whatsapp_link(
    string $phone,
    string $message
): string {
    return 'https://wa.me/'
        . normalize_phone($phone)
        . '?text='
        . rawurlencode($message);
}

function normalize_phone(string $phone): string {
    $digits = preg_replace('/[^0-9]/', '', $phone);

    if (str_starts_with($digits, '0')) {
        return '62' . substr($digits, 1);
    }

    return $digits;
}

function normalize_book(array $book): array {
    $book['id'] = (int)$book['id'];
    $book['price'] = (int)$book['price'];

    $book['old_price'] = $book['old_price'] === null
        ? null
        : (int)$book['old_price'];

    $book['rating'] = (float)$book['rating'];
    $book['stock'] = (int)$book['stock'];
    $book['is_base'] = (int)$book['is_base'];

    return $book;
}

function attach_order_items(array $order): array {
    $statement = db()->prepare("
        SELECT
            product_id AS id,
            title,
            price,
            quantity,
            line_total
        FROM order_items
        WHERE order_id = :order_id
        ORDER BY id ASC
    ");

    $statement->execute([
        ':order_id' => (int)$order['id'],
    ]);

    $paymentReference = midtrans_payment_reference(
        (string)$order['code']
    );

    return [
        'id' => (int)$order['id'],
        'code' => $order['code'],
        'created_at' => $order['created_at'],
        'customer' => [
            'name' => $order['customer_name'],
            'phone' => $order['customer_phone'],
            'address' => $order['customer_address'],
            'note' => $order['customer_note'] ?? '',
        ],
        'items' => $statement->fetchAll(),
        'subtotal' => (int)$order['subtotal'],
        'shipping' => (int)$order['shipping'],
        'discount' => (int)$order['discount'],
        'total' => (int)$order['total'],
        'payment_method' => $order['payment_method'] ?? 'QRIS',
        'payment_status' => $order['payment_status']
            ?? 'Menunggu pembayaran',
        'payment_proof' => $order['payment_proof'] ?? null,
        'delivery_status' => $order['delivery_status']
            ?? 'Menunggu pengemasan',
        'delivery_courier' => $order['delivery_courier'] ?? null,
        'tracking_number' => $order['tracking_number'] ?? null,

        'payment_gateway_reference' => $paymentReference,
        'payment_qr_url' => $paymentReference === ''
            ? ''
            : midtrans_qr_url_for_reference($paymentReference),
        'status' => $order['status'],
    ];
}
