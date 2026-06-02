<?php
/**
 * cart_action.php – PHIÊN BẢN MỚI
 * File này chuyển tiếp request sang add_to_cart.php (DB-based cart).
 * Xóa cart session cũ nếu còn tồn tại.
 */
session_start();
require_once 'db.php';

/* Xóa session cart cũ (nếu hệ thống cũ để lại) */
unset($_SESSION['cart']);

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'msg' => 'Chưa đăng nhập', 'total_count' => 0]);
    exit;
}

$uid = intval($_SESSION['user_id']);

/* ── Tự tạo bảng cart nếu chưa có ── */
if ($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `cart` (
        `id`          INT AUTO_INCREMENT PRIMARY KEY,
        `user_id`     INT NOT NULL,
        `product_key` VARCHAR(255) NOT NULL,
        `name`        VARCHAR(500) NOT NULL,
        `price`       BIGINT NOT NULL DEFAULT 0,
        `image`       TEXT DEFAULT NULL,
        `quantity`    INT NOT NULL DEFAULT 1,
        `added_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_user_product` (`user_id`,`product_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

$product_id = intval($_POST['product_id'] ?? 0);

if ($product_id > 0 && $conn) {
    /* Lấy sản phẩm từ bảng products */
    $stmt = $conn->prepare("SELECT * FROM products WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $product_id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($product) {
        /* Chuẩn hóa giá về số nguyên */
        $priceRaw = $product['price'] ?? 0;
        $price = intval(preg_replace('/[^\d]/', '', (string)$priceRaw));

        $name  = $product['name'] ?? $product['title'] ?? 'Sản phẩm';
        $image = $product['image'] ?? '';
        $pkey  = md5($name . $price);

        /* Thêm vào bảng cart (DB), tăng qty nếu đã có */
        $stmt = $conn->prepare(
            "INSERT INTO cart (user_id, product_key, name, price, image, quantity)
             VALUES (?, ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE quantity = quantity + 1"
        );
        $stmt->bind_param('issis', $uid, $pkey, $name, $price, $image);
        $stmt->execute();
        $stmt->close();
    }
}

/* Đếm tổng số lượng trong giỏ */
$total_count = 0;
$stmt = $conn->prepare("SELECT SUM(quantity) as c FROM cart WHERE user_id=?");
$stmt->bind_param('i', $uid);
$stmt->execute();
$r = $stmt->get_result()->fetch_assoc();
$total_count = (int)($r['c'] ?? 0);
$stmt->close();

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['status' => 'success', 'total_count' => $total_count]);