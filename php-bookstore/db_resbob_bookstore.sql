CREATE DATABASE IF NOT EXISTS resbob_book_store
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE resbob_book_store;

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
);

CREATE TABLE IF NOT EXISTS admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(160) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

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
);

CREATE TABLE IF NOT EXISTS order_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  order_id INT NOT NULL,
  product_id INT NOT NULL,
  title VARCHAR(160) NOT NULL,
  price INT NOT NULL,
  quantity INT NOT NULL,
  line_total INT NOT NULL,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

INSERT INTO admins (email, password_hash)
VALUES ('admin@resbob.local', '$2y$10$XY3m1YsLqV/OOr4L/x3IDOtgcbwQdExqAXrvdtpRejGpkPZz/IWfu')
ON DUPLICATE KEY UPDATE email = email;

INSERT INTO products
  (id, title, author, category, format, price, old_price, rating, badge, cover, description, stock, is_base)
VALUES
  (1, 'Atomic Habits', 'James Clear', 'Self Improvement', 'Paperback', 129000, 168000, 4.9, 'Best seller', 'https://covers.openlibrary.org/b/isbn/9780735211292-L.jpg', 'Panduan praktis membangun kebiasaan kecil yang berdampak besar.', 24, 1),
  (2, 'The Psychology of Money', 'Morgan Housel', 'Business', 'Hardcover', 145000, 180000, 4.8, 'Trending', 'https://covers.openlibrary.org/b/isbn/9780857197689-L.jpg', 'Cerita ringkas tentang perilaku, kekayaan, dan keputusan finansial.', 18, 1),
  (3, 'Deep Work', 'Cal Newport', 'Productivity', 'Paperback', 118000, NULL, 4.7, 'Fokus', 'https://covers.openlibrary.org/b/isbn/9781455586691-L.jpg', 'Cara bekerja mendalam di dunia yang penuh distraksi.', 15, 1),
  (4, 'Clean Code', 'Robert C. Martin', 'Technology', 'Paperback', 385000, NULL, 4.8, 'Developer pick', 'https://covers.openlibrary.org/b/isbn/9780132350884-L.jpg', 'Prinsip menulis kode yang jelas, mudah dirawat, dan profesional.', 9, 1),
  (5, 'Sapiens', 'Yuval Noah Harari', 'History', 'Paperback', 159000, 210000, 4.6, 'Pilihan editor', 'https://covers.openlibrary.org/b/isbn/9780062316097-L.jpg', 'Perjalanan besar manusia dari masa purba sampai dunia modern.', 20, 1),
  (6, 'Dune', 'Frank Herbert', 'Fiction', 'Paperback', 148000, NULL, 4.7, 'Sci-fi klasik', 'https://covers.openlibrary.org/b/isbn/9780441172719-L.jpg', 'Epik politik, keluarga, dan planet gurun yang legendaris.', 13, 1)
ON DUPLICATE KEY UPDATE title = VALUES(title);
