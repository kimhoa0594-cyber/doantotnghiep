<?php
/**
 * notification_api.php  ─ API lấy số thông báo đơn hàng chưa đọc
 * Gọi từ các trang admin để hiển thị badge realtime.
 * Đặt trong thư mục admin/ (cùng chỗ với don_hang_online.php)
 */
session_start();
require_once '../db.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'nhanvien'])) {
    http_response_code(403);
    echo json_encode(['count' => 0, 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

/* Tự tạo bảng nếu chưa có */
$conn->query("CREATE TABLE IF NOT EXISTS `order_notifications` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `order_id`   INT NOT NULL,
    `user_id`    INT NOT NULL,
    `fullname`   VARCHAR(255) NOT NULL,
    `phone`      VARCHAR(20) NOT NULL,
    `address`    TEXT,
    `total`      BIGINT DEFAULT 0,
    `payment`    VARCHAR(50) DEFAULT 'cod',
    `note`       TEXT,
    `item_count` INT DEFAULT 0,
    `items_json` LONGTEXT DEFAULT NULL,
    `is_read`    TINYINT(1) DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_is_read` (`is_read`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$r = $conn->query("SELECT COUNT(*) as c FROM order_notifications WHERE is_read=0");
$count = $r ? (int)$r->fetch_assoc()['c'] : 0;

/* Lấy 5 thông báo mới nhất chưa đọc để preview */
$recent = [];
$rr = $conn->query("SELECT order_id, fullname, phone, total, payment, item_count, created_at
                    FROM order_notifications
                    WHERE is_read=0
                    ORDER BY created_at DESC
                    LIMIT 5");
if ($rr) while ($row = $rr->fetch_assoc()) $recent[] = $row;

echo json_encode([
    'count'  => $count,
    'recent' => $recent,
]);