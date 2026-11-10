<?php

function db_config(): array {
    return require __DIR__ . '/config.php';
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $config = db_config();
    $host = $config['db_host'];
    $port = $config['db_port'] ?? 3306;
    $name = $config['db_name'];
    $user = $config['db_user'];
    $pass = $config['db_pass'];

    $server = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $server->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    ensure_schema($pdo);
    seed_admin($pdo);
    seed_store_settings($pdo);
    seed_products($pdo);
    migrate_legacy_books($pdo);
    seed_categories($pdo);
    return $pdo;
}

function ensure_schema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(160) NOT NULL,
            author VARCHAR(120) NOT NULL,
            category VARCHAR(80) NOT NULL,
            format VARCHAR(60) NOT NULL,
            price INT NOT NULL,
            old_price INT NULL,
            rating DECIMAL(2,1) NOT NULL DEFAULT 4.5,
            badge VARCHAR(60) NOT NULL,
            cover TEXT NOT NULL,
            description TEXT NOT NULL,
            stock INT NOT NULL DEFAULT 0,
            is_base TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $columns = $pdo->query("SHOW COLUMNS FROM products LIKE 'is_base'")->fetchAll();
    if (!$columns) {
        $pdo->exec("ALTER TABLE products ADD COLUMN is_base TINYINT(1) NOT NULL DEFAULT 0");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(40) NOT NULL UNIQUE,
            customer_name VARCHAR(120) NOT NULL,
            customer_phone VARCHAR(30) NOT NULL,
            customer_address TEXT NOT NULL,
            customer_note TEXT NULL,
            subtotal INT NOT NULL,
            shipping INT NOT NULL,
            discount INT NOT NULL,
            total INT NOT NULL,
            payment_method VARCHAR(40) NOT NULL DEFAULT 'QRIS',
            payment_status ENUM('Menunggu pembayaran', 'Sudah dibayar') NOT NULL DEFAULT 'Menunggu pembayaran',
            stock_reduced TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('Pesanan baru', 'Diproses', 'Selesai', 'Dibatalkan') NOT NULL DEFAULT 'Pesanan baru',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $paymentMethodColumn = $pdo->query("SHOW COLUMNS FROM orders LIKE 'payment_method'")->fetchAll();
    if (!$paymentMethodColumn) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN payment_method VARCHAR(40) NOT NULL DEFAULT 'QRIS' AFTER total");
    }

    $paymentStatusColumn = $pdo->query("SHOW COLUMNS FROM orders LIKE 'payment_status'")->fetchAll();
    if (!$paymentStatusColumn) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN payment_status ENUM('Menunggu pembayaran', 'Sudah dibayar') NOT NULL DEFAULT 'Menunggu pembayaran' AFTER payment_method");
    }

    $stockReducedColumn = $pdo->query("SHOW COLUMNS FROM orders LIKE 'stock_reduced'")->fetchAll();
    if (!$stockReducedColumn) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN stock_reduced TINYINT(1) NOT NULL DEFAULT 0 AFTER payment_status");
    }

    // Database lama belum memiliki kolom bukti pembayaran. Tambahkan tanpa
    // mengubah data pesanan yang sudah ada agar halaman pesanan tetap aman.
    $paymentProofColumn = $pdo->query("SHOW COLUMNS FROM orders LIKE 'payment_proof'")->fetchAll();
    if (!$paymentProofColumn) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN payment_proof VARCHAR(255) NULL AFTER payment_status");
    }

    $deliveryStatusColumn = $pdo->query("SHOW COLUMNS FROM orders LIKE 'delivery_status'")->fetchAll();
    if (!$deliveryStatusColumn) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN delivery_status VARCHAR(40) NOT NULL DEFAULT 'Menunggu pengemasan' AFTER status");
    }

    $deliveryCourierColumn = $pdo->query("SHOW COLUMNS FROM orders LIKE 'delivery_courier'")->fetchAll();
    if (!$deliveryCourierColumn) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN delivery_courier VARCHAR(60) NULL AFTER delivery_status");
    }

    $trackingNumberColumn = $pdo->query("SHOW COLUMNS FROM orders LIKE 'tracking_number'")->fetchAll();
    if (!$trackingNumberColumn) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN tracking_number VARCHAR(80) NULL AFTER delivery_courier");
    }

    // Pesanan yang dibuat sebelum fitur pengiriman ditambahkan perlu nilai
    // awal agar customer tetap melihat tahap pengiriman, bukan status kosong.
    $pdo->exec("
        UPDATE orders
        SET delivery_status = 'Menunggu pengemasan'
        WHERE delivery_status IS NULL OR delivery_status = ''
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS order_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            product_id INT NOT NULL,
            title VARCHAR(160) NOT NULL,
            price INT NOT NULL,
            quantity INT NOT NULL,
            line_total INT NOT NULL,
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admins (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(160) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS store_settings (
            setting_key VARCHAR(80) PRIMARY KEY,
            setting_value VARCHAR(255) NOT NULL DEFAULT ''
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL UNIQUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS customers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL,
            identity_type ENUM('email', 'phone') NOT NULL,
            identity VARCHAR(160) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS return_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL UNIQUE,
            reason VARCHAR(60) NOT NULL,
            description TEXT NOT NULL,
            evidence JSON NOT NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'Diajukan',
            admin_note TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS return_request_items (
            id INT AUTO_INCREMENT PRIMARY KEY,
            return_request_id INT NOT NULL,
            order_item_id INT NOT NULL,
            title VARCHAR(160) NOT NULL,
            quantity INT NOT NULL,
            FOREIGN KEY (return_request_id) REFERENCES return_requests(id) ON DELETE CASCADE,
            FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE RESTRICT
        )
    ");

    // seed_admin($pdo);
    seed_store_settings($pdo);
}

function seed_products(PDO $pdo): void {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE is_base = 1")->fetchColumn();
    if ($count > 0) return;

    $books = require __DIR__ . '/data.php';
    $statement = $pdo->prepare("
        INSERT INTO products
            (title, author, category, format, price, old_price, rating, badge, cover, description, stock, is_base)
        VALUES
            (:title, :author, :category, :format, :price, :old_price, :rating, :badge, :cover, :description, :stock, 1)
    ");

    foreach ($books as $book) {
        $statement->execute([
            ':title' => $book['title'],
            ':author' => $book['author'],
            ':category' => $book['category'],
            ':format' => $book['format'],
            ':price' => $book['price'],
            ':old_price' => $book['old_price'],
            ':rating' => $book['rating'],
            ':badge' => $book['badge'],
            ':cover' => $book['cover'],
            ':description' => $book['description'],
            ':stock' => $book['stock'],
        ]);
    }
}

function migrate_legacy_books(PDO $pdo): void {
    $path = __DIR__ . '/storage/products.json';
    if (!is_file($path)) return;

    $legacyBooks = json_decode((string)file_get_contents($path), true);
    if (!is_array($legacyBooks)) return;

    $exists = $pdo->prepare("SELECT id FROM products WHERE title = :title AND author = :author LIMIT 1");
    $insert = $pdo->prepare("
        INSERT INTO products
            (title, author, category, format, price, old_price, rating, badge, cover, description, stock, is_base)
        VALUES
            (:title, :author, :category, :format, :price, :old_price, :rating, :badge, :cover, :description, :stock, 0)
    ");

    foreach ($legacyBooks as $book) {
        if (!is_array($book)) continue;
        $title = trim((string)($book['title'] ?? ''));
        $author = trim((string)($book['author'] ?? ''));
        if ($title === '' || $author === '') continue;

        $exists->execute([':title' => $title, ':author' => $author]);
        if ($exists->fetchColumn()) continue;

        $insert->execute([
            ':title' => $title,
            ':author' => $author,
            ':category' => trim((string)($book['category'] ?? 'Umum')) ?: 'Umum',
            ':format' => trim((string)($book['format'] ?? 'Paperback')) ?: 'Paperback',
            ':price' => max(1000, (int)($book['price'] ?? 50000)),
            ':old_price' => !empty($book['old_price']) ? (int)$book['old_price'] : null,
            ':rating' => max(1, min(5, (float)($book['rating'] ?? 4.5))),
            ':badge' => trim((string)($book['badge'] ?? 'Baru')) ?: 'Baru',
            ':cover' => trim((string)($book['cover'] ?? '')),
            ':description' => trim((string)($book['description'] ?? 'Deskripsi buku.')) ?: 'Deskripsi buku.',
            ':stock' => max(1, (int)($book['stock'] ?? 1)),
        ]);
    }
}

function seed_admin(PDO $pdo): void {
    $config = db_config();
    $statement = $pdo->prepare("
        INSERT INTO admins (email, password_hash)
        VALUES (:email, :password_hash)
        ON DUPLICATE KEY UPDATE email = email
    ");
    $statement->execute([
        ':email' => $config['admin_email'],
        ':password_hash' => $config['admin_password_hash'],
    ]);
}

function seed_store_settings(PDO $pdo): void {
    $statement = $pdo->prepare("
        INSERT INTO store_settings (setting_key, setting_value)
        VALUES ('store_whatsapp', '')
        ON DUPLICATE KEY UPDATE setting_key = setting_key
    ");
    $statement->execute();
}

function seed_categories(PDO $pdo): void {
    $pdo->exec("
        INSERT IGNORE INTO categories (name)
        SELECT DISTINCT TRIM(category)
        FROM products
        WHERE TRIM(category) <> ''
    ");
}
