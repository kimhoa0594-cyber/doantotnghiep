<?php
<<<<<<< HEAD
session_start();
require_once '../db.php';
date_default_timezone_set('Asia/Ho_Chi_Minh');

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['items' => [], 'count' => 0]);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Lấy danh sách thông báo chưa đọc
if ($action === 'list') {
    $limit = min((int)($_GET['limit'] ?? 20), 50);
    $stmt = $conn->prepare("
        SELECT tb.*, ps.tenThietBi, ps.tenKH, ps.trangThai as ttPhieu, ps.maKH
        FROM thong_bao_admin tb
        LEFT JOIN phieu_sua_chua ps ON ps.maPhieu = tb.maPhieu
        WHERE tb.trangThai = 'chua_doc'
        ORDER BY tb.thoiGian DESC
        LIMIT ?
    ");
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    foreach ($rows as &$row) {
        $row['thoiGianFormat'] = date('d/m/Y H:i', strtotime($row['thoiGian']));
    }
    
    echo json_encode(['items' => $rows]);
    exit;
}

// Đếm thông báo chưa đọc
if ($action === 'count') {
    $stmt = $conn->prepare("SELECT COUNT(*) as c FROM thong_bao_admin WHERE trangThai='chua_doc'");
    $stmt->execute();
    $c = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    echo json_encode(['count' => $c]);
    exit;
}

// Đánh dấu đã đọc
if ($action === 'doc' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE thong_bao_admin SET trangThai='da_doc' WHERE id=?");
        $stmt->bind_param("i", $id);
    } else {
        $stmt = $conn->prepare("UPDATE thong_bao_admin SET trangThai='da_doc' WHERE trangThai='chua_doc'");
    }
    $stmt->execute();
    $stmt->close();
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['items' => []]);
?>
=======
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
>>>>>>> fc0888887465ac6d64caa80abe4294c237f2aa7d
