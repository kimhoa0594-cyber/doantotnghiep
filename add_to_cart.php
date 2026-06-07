<?php
session_start();
require_once 'db.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'msg' => 'Chưa đăng nhập']);
    exit;
}

$uid    = intval($_SESSION['user_id']);
$action = $_POST['action'] ?? $_GET['action'] ?? 'add';

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

/* ════════════════════════════════════
   THÊM / TĂNG SỐ LƯỢNG
════════════════════════════════════ */
if ($action === 'add') {
    $name     = trim($_POST['name']  ?? '');
    $priceRaw = $_POST['price'] ?? 0;
    $price    = intval(preg_replace('/[^\d]/', '', (string)$priceRaw));
    $image    = trim($_POST['image'] ?? '');
    $qty      = max(1, intval($_POST['qty'] ?? 1));

    if (!$name || $price <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Dữ liệu không hợp lệ (tên hoặc giá trống)']);
        exit;
    }

    $pkey = md5($name . $price);

    $stmt = $conn->prepare(
        "INSERT INTO cart (user_id, product_key, name, price, image, quantity)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)"
    );
    $stmt->bind_param('issisi', $uid, $pkey, $name, $price, $image, $qty);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['ok' => true, 'count' => getCartCount($conn, $uid), 'msg' => 'Đã thêm vào giỏ hàng!']);
    exit;
}

/* ════════════════════════════════════
   CẬP NHẬT SỐ LƯỢNG
════════════════════════════════════ */
if ($action === 'update') {
    $cart_id = intval($_POST['cart_id'] ?? 0);
    $qty     = intval($_POST['qty']     ?? 1);
    if ($qty <= 0) {
        $stmt = $conn->prepare("DELETE FROM cart WHERE id=? AND user_id=?");
        $stmt->bind_param('ii', $cart_id, $uid);
    } else {
        $stmt = $conn->prepare("UPDATE cart SET quantity=? WHERE id=? AND user_id=?");
        $stmt->bind_param('iii', $qty, $cart_id, $uid);
    }
    $stmt->execute();
    $stmt->close();
    echo json_encode(['ok' => true, 'count' => getCartCount($conn, $uid), 'subtotal' => getSubtotal($conn, $uid)]);
    exit;
}

/* ════════════════════════════════════
   XÓA 1 SẢN PHẨM
════════════════════════════════════ */
if ($action === 'remove') {
    $cart_id = intval($_POST['cart_id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM cart WHERE id=? AND user_id=?");
    $stmt->bind_param('ii', $cart_id, $uid);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['ok' => true, 'count' => getCartCount($conn, $uid), 'subtotal' => getSubtotal($conn, $uid)]);
    exit;
}

/* ════════════════════════════════════
   XÓA TOÀN BỘ GIỎ
════════════════════════════════════ */
if ($action === 'clear') {
    $stmt = $conn->prepare("DELETE FROM cart WHERE user_id=?");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['ok' => true, 'count' => 0, 'subtotal' => 0]);
    exit;
}

/* ════════════════════════════════════
   LẤY GIỎ HÀNG
════════════════════════════════════ */
if ($action === 'get') {
    $items = [];
    $stmt  = $conn->prepare("SELECT * FROM cart WHERE user_id=? ORDER BY added_at DESC");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $items[] = $row;
    $stmt->close();
    echo json_encode(['ok' => true, 'items' => $items, 'count' => count($items), 'subtotal' => getSubtotal($conn, $uid)]);
    exit;
}

/* ════════════════════════════════════
   ĐẶT HÀNG (checkout via AJAX)
════════════════════════════════════ */
if ($action === 'checkout') {
    $fullname = trim($_POST['fullname'] ?? '');
    $phone    = trim($_POST['phone']    ?? '');
    $address  = trim($_POST['address']  ?? '');
    $payment  = trim($_POST['payment']  ?? 'cod');
    $note     = trim($_POST['note']     ?? '');

    if (!$fullname || !$phone || !$address) {
        echo json_encode(['ok' => false, 'msg' => 'Vui lòng điền đầy đủ họ tên, SĐT và địa chỉ']);
        exit;
    }

    $items = [];
    $stmt  = $conn->prepare("SELECT * FROM cart WHERE user_id=?");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $items[] = $row;
    $stmt->close();

    if (empty($items)) {
        echo json_encode(['ok' => false, 'msg' => 'Giỏ hàng trống']);
        exit;
    }

    $total = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $items));

    /* ── Tạo bảng orders / order_items / order_notifications nếu chưa có ── */
    $conn->query("CREATE TABLE IF NOT EXISTS `orders` (
        `id`           INT AUTO_INCREMENT PRIMARY KEY,
        `user_id`      INT NOT NULL,
        `fullname`     VARCHAR(255) NOT NULL,
        `phone`        VARCHAR(20) NOT NULL,
        `address`      TEXT NOT NULL,
        `payment`      VARCHAR(50) DEFAULT 'cod',
        `note`         TEXT,
        `total_amount` BIGINT DEFAULT 0,
        `status`       VARCHAR(50) DEFAULT 'pending',
        `created_at`   DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS `order_items` (
        `id`       INT AUTO_INCREMENT PRIMARY KEY,
        `order_id` INT NOT NULL,
        `name`     VARCHAR(500) NOT NULL,
        `price`    BIGINT DEFAULT 0,
        `quantity` INT DEFAULT 1,
        `image`    TEXT,
        `subtotal` BIGINT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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
        `items_json` LONGTEXT DEFAULT NULL COMMENT 'JSON chi tiết sản phẩm',
        `is_read`    TINYINT(1) DEFAULT 0,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_is_read`  (`is_read`),
        INDEX `idx_created`  (`created_at`),
        INDEX `idx_order_id` (`order_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    /* ── Thêm cột address / note / items_json nếu phiên bản cũ chưa có ── */
    $conn->query("ALTER TABLE `order_notifications` ADD COLUMN IF NOT EXISTS `address`    TEXT          AFTER `phone`");
    $conn->query("ALTER TABLE `order_notifications` ADD COLUMN IF NOT EXISTS `note`       TEXT          AFTER `payment`");
    $conn->query("ALTER TABLE `order_notifications` ADD COLUMN IF NOT EXISTS `items_json` LONGTEXT      AFTER `item_count`");

    /* ── Lưu đơn hàng ── */
    $fn_esc   = $conn->real_escape_string($fullname);
    $ph_esc   = $conn->real_escape_string($phone);
    $addr_esc = $conn->real_escape_string($address);
    $pay_esc  = $conn->real_escape_string($payment);
    $note_esc = $conn->real_escape_string($note);

    $stmt = $conn->prepare(
        "INSERT INTO orders (user_id, fullname, phone, address, payment, note, total_amount)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('isssssi', $uid, $fullname, $phone, $address, $payment, $note, $total);
    $stmt->execute();
    $order_id = $conn->insert_id;
    $stmt->close();

    /* ── Lưu chi tiết đơn ── */
    $istmt = $conn->prepare(
        "INSERT INTO order_items (order_id, name, price, quantity, image, subtotal)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    foreach ($items as $item) {
        $n   = $item['name'];
        $p   = intval($item['price']);
        $q   = intval($item['quantity']);
        $img = $item['image'] ?? '';
        $sub = $p * $q;
        $istmt->bind_param('isiisi', $order_id, $n, $p, $q, $img, $sub);
        $istmt->execute();
    }
    $istmt->close();

    /* ── Xóa giỏ hàng ── */
    $stmt = $conn->prepare("DELETE FROM cart WHERE user_id=?");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $stmt->close();

    /* ════════════════════════════════════
       TẠO THÔNG BÁO ĐƠN HÀNG MỚI CHO ADMIN/NHÂN VIÊN
       Ghi đầy đủ: họ tên, SĐT, địa chỉ, ghi chú,
       tổng tiền, PTTT, số sản phẩm, JSON chi tiết
    ════════════════════════════════════ */
    $item_count  = count($items);
    $items_json  = json_encode(array_map(fn($it) => [
        'name'     => $it['name'],
        'price'    => $it['price'],
        'quantity' => $it['quantity'],
        'subtotal' => $it['price'] * $it['quantity'],
        'image'    => $it['image'] ?? '',
    ], $items), JSON_UNESCAPED_UNICODE);

    $items_json_esc = $conn->real_escape_string($items_json);
    $addr_esc2      = $conn->real_escape_string($address);
    $note_esc2      = $conn->real_escape_string($note);

    $conn->query(
        "INSERT INTO order_notifications
            (order_id, user_id, fullname, phone, address, total, payment, note, item_count, items_json, is_read)
         VALUES
            ($order_id, $uid, '$fn_esc', '$ph_esc', '$addr_esc2', $total, '$pay_esc', '$note_esc2', $item_count, '$items_json_esc', 0)"
    );

    echo json_encode(['ok' => true, 'order_id' => $order_id, 'msg' => 'Đặt hàng thành công!']);
    exit;
}

echo json_encode(['ok' => false, 'msg' => 'Action không hợp lệ']);

/* ── Helpers ── */
function getCartCount($conn, $uid) {
    $stmt = $conn->prepare("SELECT SUM(quantity) as c FROM cart WHERE user_id=?");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($r['c'] ?? 0);
}

function getSubtotal($conn, $uid) {
    $stmt = $conn->prepare("SELECT SUM(price*quantity) as t FROM cart WHERE user_id=?");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($r['t'] ?? 0);
}