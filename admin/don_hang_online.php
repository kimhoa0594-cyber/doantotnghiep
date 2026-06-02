<?php
/**
 * don_hang_online.php  ─ Quản lý đơn hàng online từ khách đặt qua website
 * Đặt file này trong thư mục admin/ (cùng chỗ với quan_ly_khach_hang.php)
 * Yêu cầu: session role = 'admin' hoặc 'nhanvien'
 */
session_start();
require_once '../db.php';
date_default_timezone_set('Asia/Ho_Chi_Minh');

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'nhanvien'])) {
    header("Location: ../index.php"); exit();
}
$admin_user = $_SESSION['username'] ?? 'Admin';
$is_admin   = ($_SESSION['role'] === 'admin');

/* ── Tự tạo bảng nếu chưa có ── */
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
    `items_json` LONGTEXT DEFAULT NULL,
    `is_read`    TINYINT(1) DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_is_read`  (`is_read`),
    INDEX `idx_created`  (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

/* ── Thêm cột thiếu nếu phiên bản cũ ── */
$conn->query("ALTER TABLE `order_notifications` ADD COLUMN IF NOT EXISTS `address`    TEXT     AFTER `phone`");
$conn->query("ALTER TABLE `order_notifications` ADD COLUMN IF NOT EXISTS `note`       TEXT     AFTER `payment`");
$conn->query("ALTER TABLE `order_notifications` ADD COLUMN IF NOT EXISTS `items_json` LONGTEXT AFTER `item_count`");

/* ══ XỬ LÝ AJAX ══ */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');

    /* Đánh dấu đã đọc 1 thông báo */
    if ($_GET['ajax'] === 'mark_read' && isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $conn->query("UPDATE order_notifications SET is_read=1 WHERE id=$id");
        echo json_encode(['ok' => true]);
        exit;
    }

    /* Đánh dấu tất cả đã đọc */
    if ($_GET['ajax'] === 'mark_all_read') {
        $conn->query("UPDATE order_notifications SET is_read=1 WHERE is_read=0");
        echo json_encode(['ok' => true]);
        exit;
    }

    /* Số thông báo chưa đọc */
    if ($_GET['ajax'] === 'unread_count') {
        $r = $conn->query("SELECT COUNT(*) as c FROM order_notifications WHERE is_read=0");
        $c = $r ? (int)$r->fetch_assoc()['c'] : 0;
        echo json_encode(['count' => $c]);
        exit;
    }

    /* Cập nhật trạng thái đơn hàng */
    if ($_GET['ajax'] === 'update_status' && isset($_POST['order_id'], $_POST['status'])) {
        $oid    = intval($_POST['order_id']);
        $status = $conn->real_escape_string($_POST['status']);
        $conn->query("UPDATE orders SET status='$status' WHERE id=$oid");
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Unknown action']);
    exit;
}

/* ══ LỌC / PHÂN TRANG ══ */
$filter_status  = $_GET['status']  ?? 'all';
$filter_payment = $_GET['payment'] ?? 'all';
$search         = trim($_GET['q']  ?? '');
$page           = max(1, intval($_GET['page'] ?? 1));
$per_page       = 20;

$where = [];
if ($filter_status  !== 'all') $where[] = "o.status='" . $conn->real_escape_string($filter_status) . "'";
if ($filter_payment !== 'all') $where[] = "o.payment='" . $conn->real_escape_string($filter_payment) . "'";
if ($search !== '') {
    $s = $conn->real_escape_string($search);
    $where[] = "(o.fullname LIKE '%$s%' OR o.phone LIKE '%$s%' OR o.id LIKE '%$s%')";
}
$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$total_rows = (int)$conn->query("SELECT COUNT(*) as c FROM orders o $where_sql")->fetch_assoc()['c'];
$total_pages = max(1, ceil($total_rows / $per_page));
$offset = ($page - 1) * $per_page;

$orders_res = $conn->query(
    "SELECT o.*, u.fullname as user_name, u.email as user_email
     FROM orders o
     LEFT JOIN users u ON u.id = o.user_id
     $where_sql
     ORDER BY o.created_at DESC
     LIMIT $per_page OFFSET $offset"
);

/* ══ THỐNG KÊ NHANH ══ */
$stats = [];
foreach (['pending' => 'Chờ xử lý', 'processing' => 'Đang giao', 'completed' => 'Hoàn thành', 'cancelled' => 'Đã hủy'] as $st => $lbl) {
    $r = $conn->query("SELECT COUNT(*) as c, SUM(total_amount) as t FROM orders WHERE status='$st'");
    $row = $r ? $r->fetch_assoc() : ['c'=>0,'t'=>0];
    $stats[$st] = ['label' => $lbl, 'count' => (int)$row['c'], 'total' => (int)($row['t']??0)];
}
$unread_count = (int)$conn->query("SELECT COUNT(*) as c FROM order_notifications WHERE is_read=0")->fetch_assoc()['c'];

/* ══ Nhãn trạng thái ══ */
$status_labels = [
    'pending'    => ['Chờ xử lý',  'warning'],
    'processing' => ['Đang giao',  'info'],
    'completed'  => ['Hoàn thành', 'success'],
    'cancelled'  => ['Đã hủy',     'danger'],
];
$payment_labels = [
    'cod'     => ['💵 COD',           '#92400e', '#fef3c7'],
    'bank'    => ['🏦 Chuyển khoản',  '#1e40af', '#dbeafe'],
    'ewallet' => ['📱 Ví điện tử',    '#581c87', '#f3e8ff'],
];

function fmoney($n) { return number_format((int)$n, 0, ',', '.') . 'đ'; }
function fdate($d) { return $d ? date('H:i d/m/Y', strtotime($d)) : '—'; }
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Đơn hàng Online | QA Admin</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
    --g1:#065f46;--g2:#047857;--g3:#059669;--g4:#10b981;
    --acc:#f59e0b;--red:#ef4444;--blue:#3b82f6;
    --txt:#0f172a;--muted:#64748b;--border:#e2e8f0;
    --bg:#f1f5f9;--surface:#fff;
    --sidebar:#0f172a;--sidebar-w:64px;
}
body{font-family:'Be Vietnam Pro',sans-serif;background:var(--bg);color:var(--txt);font-size:14px}
a{text-decoration:none;color:inherit}

/* ── Sidebar ── */
.sidebar{position:fixed;top:0;left:0;bottom:0;width:var(--sidebar-w);background:var(--sidebar);
         display:flex;flex-direction:column;z-index:200;transition:width .25s;overflow:hidden}
.sidebar:hover{width:230px}
.s-logo{height:60px;min-height:60px;display:flex;align-items:center;padding:0 16px;gap:12px;
        border-bottom:1px solid #1e293b}
.s-mark{width:32px;height:32px;flex-shrink:0;background:var(--g3);border-radius:8px;
        display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;color:#fff}
.s-name{color:#fff;font-weight:700;font-size:13px;white-space:nowrap;opacity:0;transition:opacity .2s}
.sidebar:hover .s-name{opacity:1}
.s-nav{flex:1;padding:10px 8px;display:flex;flex-direction:column;gap:2px;overflow-y:auto}
.nl{display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:9px;
    color:#94a3b8;font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;transition:.15s}
.nl i{font-size:14px;flex-shrink:0;width:20px;text-align:center}
.nl span{opacity:0;transition:opacity .15s .05s}
.sidebar:hover .nl span{opacity:1}
.nl:hover{background:#1e293b;color:#e2e8f0}
.nl.active{background:var(--g3);color:#fff}
.nl .badge-side{background:var(--acc);color:#333;font-size:10px;font-weight:800;
                border-radius:10px;padding:1px 6px;margin-left:auto;opacity:0;transition:opacity .15s .05s}
.sidebar:hover .badge-side{opacity:1}

/* ── Main ── */
.main{margin-left:var(--sidebar-w);min-height:100vh;display:flex;flex-direction:column}
.topbar{background:rgba(255,255,255,.95);backdrop-filter:blur(10px);border-bottom:1px solid var(--border);
        height:56px;display:flex;align-items:center;padding:0 24px;gap:14px;position:sticky;top:0;z-index:100}
.page-body{flex:1;padding:24px}

/* ── Stat cards ── */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px}
.stat-card{background:var(--surface);border-radius:12px;padding:16px 18px;
           border:1px solid var(--border);display:flex;align-items:center;gap:14px}
.stat-icon{width:44px;height:44px;border-radius:11px;display:flex;align-items:center;
           justify-content:center;font-size:18px;flex-shrink:0}
.stat-num{font-size:22px;font-weight:800;line-height:1}
.stat-lbl{font-size:11.5px;color:var(--muted);margin-top:2px}
.stat-sub{font-size:11px;color:var(--g3);margin-top:1px;font-weight:600}

/* ── Filters ── */
.filters-bar{background:var(--surface);border-radius:12px;border:1px solid var(--border);
             padding:14px 18px;margin-bottom:16px;display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.f-select,.f-input{border:1.5px solid var(--border);border-radius:9px;padding:8px 12px;
                    font-size:13px;font-family:inherit;background:#fff;color:var(--txt);outline:none;transition:.15s}
.f-select:focus,.f-input:focus{border-color:var(--g3);box-shadow:0 0 0 3px rgba(5,150,105,.1)}
.f-btn{background:linear-gradient(90deg,var(--g2),var(--g3));color:#fff;border:none;border-radius:9px;
       padding:8px 18px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:6px;transition:.15s}
.f-btn:hover{opacity:.9}
.f-btn.secondary{background:#fff;color:var(--muted);border:1.5px solid var(--border)}
.f-btn.secondary:hover{border-color:var(--muted)}

/* ── Notification bell ── */
.notif-btn{position:relative;background:none;border:1.5px solid var(--border);border-radius:9px;
           padding:7px 14px;cursor:pointer;font-family:inherit;font-size:13px;display:flex;align-items:center;gap:7px;transition:.15s}
.notif-btn:hover{border-color:var(--g3);color:var(--g3)}
.notif-badge{background:var(--red);color:#fff;font-size:10px;font-weight:800;width:18px;height:18px;
             border-radius:50%;display:flex;align-items:center;justify-content:center;
             position:absolute;top:-6px;right:-6px}

/* ── Table ── */
.table-card{background:var(--surface);border-radius:12px;border:1px solid var(--border);overflow:hidden}
.table-header{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;
              align-items:center;justify-content:space-between;gap:10px}
.table-title{font-weight:800;font-size:15px;display:flex;align-items:center;gap:8px}
table.ord-table{width:100%;border-collapse:collapse}
table.ord-table th{padding:11px 14px;font-size:11px;font-weight:700;text-transform:uppercase;
                   letter-spacing:.5px;color:var(--muted);background:#fafbfc;border-bottom:2px solid var(--border);text-align:left}
table.ord-table td{padding:13px 14px;border-bottom:1px solid #f8fafc;vertical-align:middle}
table.ord-table tr:last-child td{border-bottom:none}
table.ord-table tr:hover td{background:#fafffe}
.order-id{font-weight:800;color:var(--g2);font-size:13.5px}
.customer-name{font-weight:700;font-size:13.5px}
.customer-phone{font-size:12px;color:var(--muted);margin-top:2px}
.payment-badge{display:inline-block;font-size:11px;font-weight:700;padding:3px 10px;border-radius:8px}
.status-sel{border:1.5px solid var(--border);border-radius:8px;padding:5px 10px;font-size:12px;
            font-family:inherit;cursor:pointer;font-weight:700;background:#fff;transition:.15s}
.status-sel:focus{outline:none}
.status-pending   {color:#92400e;background:#fef3c7;border-color:#fcd34d}
.status-processing{color:#1d4ed8;background:#dbeafe;border-color:#93c5fd}
.status-completed {color:#065f46;background:#d1fae5;border-color:#6ee7b7}
.status-cancelled {color:#991b1b;background:#fee2e2;border-color:#fca5a5}
.btn-detail{background:#f0fdf4;color:var(--g2);border:1.5px solid #d1fae5;border-radius:8px;
            padding:5px 12px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit;transition:.15s}
.btn-detail:hover{background:var(--g3);color:#fff;border-color:var(--g3)}
.new-badge{background:var(--red);color:#fff;font-size:9px;font-weight:900;padding:2px 6px;
           border-radius:6px;margin-left:5px;animation:pulse 1.5s ease infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}

/* ── Modal ── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:500;
               display:flex;align-items:center;justify-content:center;padding:16px;
               opacity:0;pointer-events:none;transition:opacity .22s}
.modal-overlay.open{opacity:1;pointer-events:all}
.modal-box{background:#fff;border-radius:16px;width:100%;max-width:640px;
           max-height:90vh;overflow:hidden;display:flex;flex-direction:column;
           box-shadow:0 24px 60px rgba(0,0,0,.2);
           transform:translateY(24px) scale(.97);transition:transform .22s}
.modal-overlay.open .modal-box{transform:none}
.modal-hdr{background:linear-gradient(135deg,var(--g1),var(--g3));color:#fff;padding:18px 22px;
           display:flex;align-items:center;gap:12px;flex-shrink:0}
.modal-hdr-icon{width:40px;height:40px;background:rgba(255,255,255,.2);border-radius:10px;
                display:flex;align-items:center;justify-content:center;font-size:18px}
.modal-hdr-title{font-size:17px;font-weight:800;flex:1}
.modal-close{background:rgba(255,255,255,.18);border:none;color:#fff;width:32px;height:32px;
             border-radius:8px;cursor:pointer;font-size:15px;display:flex;align-items:center;justify-content:center;transition:.15s}
.modal-close:hover{background:rgba(255,255,255,.32)}
.modal-body{overflow-y:auto;padding:22px}

/* ── Notification list ── */
.notif-panel{position:fixed;top:56px;right:24px;width:420px;max-height:520px;
             background:#fff;border-radius:14px;border:1px solid var(--border);
             box-shadow:0 16px 48px rgba(0,0,0,.18);z-index:300;
             display:none;flex-direction:column;overflow:hidden}
.notif-panel.open{display:flex}
.notif-panel-hdr{padding:14px 18px;border-bottom:1px solid var(--border);
                 display:flex;align-items:center;justify-content:space-between}
.notif-panel-hdr h3{font-size:15px;font-weight:800;margin:0;display:flex;align-items:center;gap:8px}
.notif-list{overflow-y:auto;flex:1}
.notif-item{display:flex;gap:12px;padding:13px 18px;border-bottom:1px solid #f8fafc;cursor:pointer;transition:.15s}
.notif-item:hover{background:#fafffe}
.notif-item.unread{background:#f0fdf4}
.notif-item .ni-icon{width:40px;height:40px;background:var(--g3);border-radius:10px;
                     display:flex;align-items:center;justify-content:center;color:#fff;
                     font-size:16px;flex-shrink:0}
.notif-item.unread .ni-icon{background:var(--g2)}
.ni-title{font-weight:700;font-size:13.5px;color:var(--txt)}
.ni-sub{font-size:12px;color:var(--muted);margin-top:2px}
.ni-time{font-size:11px;color:var(--muted);margin-top:3px}
.ni-dot{width:8px;height:8px;background:var(--g3);border-radius:50%;flex-shrink:0;margin-top:5px}
.empty-notif{text-align:center;padding:40px 20px;color:var(--muted)}

/* ── Pagination ── */
.pag{display:flex;gap:5px;justify-content:center;margin-top:18px}
.pag a,.pag span{display:inline-flex;align-items:center;justify-content:center;
                 width:34px;height:34px;border-radius:8px;font-size:13px;font-weight:600;
                 border:1.5px solid var(--border);background:#fff;color:var(--muted)}
.pag a:hover{border-color:var(--g3);color:var(--g2)}
.pag .cur{background:var(--g3);color:#fff;border-color:var(--g3)}

/* ── Responsive ── */
@media(max-width:900px){
    .stats-row{grid-template-columns:repeat(2,1fr)}
    .notif-panel{right:8px;width:calc(100vw - 16px)}
}
@media(max-width:600px){
    .stats-row{grid-template-columns:1fr}
    table.ord-table thead{display:none}
    table.ord-table td{display:block;padding:6px 14px}
}
</style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar">
    <div class="s-logo">
        <div class="s-mark">QA</div>
        <span class="s-name">Quang Anh Tech</span>
    </div>
    <div class="s-nav">
        <a href="quan_ly_khach_hang.php" class="nl"><i class="fas fa-users"></i><span>Khách hàng</span></a>
        <a href="don_hang_online.php"    class="nl active"><i class="fas fa-shopping-bag"></i><span>Đơn hàng online</span>
            <?php if($unread_count > 0): ?><span class="badge-side"><?= $unread_count ?></span><?php endif; ?>
        </a>
        <a href="../index.php" class="nl"><i class="fas fa-store"></i><span>Trang chủ shop</span></a>
    </div>
    <div style="padding:10px 8px;border-top:1px solid #1e293b">
        <a href="../logout.php" class="nl" style="color:#f87171"><i class="fas fa-sign-out-alt"></i><span>Đăng xuất</span></a>
    </div>
</div>

<!-- MAIN -->
<div class="main">
    <!-- TOP BAR -->
    <div class="topbar">
        <i class="fas fa-shopping-bag" style="color:var(--g3)"></i>
        <span style="font-weight:700;font-size:15px">Đơn hàng Online</span>
        <span style="color:var(--muted);font-size:13px">/ Danh sách</span>
        <div style="flex:1"></div>

        <!-- Chuông thông báo -->
        <button class="notif-btn" onclick="toggleNotifPanel()" id="notifBtn">
            <i class="fas fa-bell"></i>
            Thông báo
            <?php if ($unread_count > 0): ?>
            <span class="notif-badge" id="notifBadge"><?= $unread_count ?></span>
            <?php endif; ?>
        </button>
        <span style="font-size:13px;color:var(--muted)">Xin chào, <strong><?= htmlspecialchars($admin_user) ?></strong></span>
    </div>

    <!-- NOTIFICATION PANEL (dropdown) -->
    <div class="notif-panel" id="notifPanel">
        <div class="notif-panel-hdr">
            <h3><i class="fas fa-bell" style="color:var(--g3)"></i> Thông báo đơn hàng mới
                <?php if ($unread_count > 0): ?><span style="background:var(--red);color:#fff;font-size:11px;padding:2px 8px;border-radius:10px"><?= $unread_count ?> mới</span><?php endif; ?>
            </h3>
            <button onclick="markAllRead()" style="border:none;background:none;color:var(--g3);font-size:12px;font-weight:700;cursor:pointer">Đánh dấu đã đọc tất cả</button>
        </div>
        <div class="notif-list" id="notifList">
        <?php
        $notifs = $conn->query("SELECT * FROM order_notifications ORDER BY created_at DESC LIMIT 30");
        if ($notifs && $notifs->num_rows > 0):
            while ($n = $notifs->fetch_assoc()):
                $unread_cls = $n['is_read'] ? '' : 'unread';
                $pay_info = $payment_labels[$n['payment']] ?? ['💳 ' . $n['payment'], '#374151', '#f1f5f9'];
        ?>
        <div class="notif-item <?= $unread_cls ?>" onclick="openOrderFromNotif(<?= $n['order_id'] ?>, <?= $n['id'] ?>)">
            <div class="ni-icon"><i class="fas fa-shopping-cart"></i></div>
            <div style="flex:1">
                <div class="ni-title">#QA-<?= str_pad($n['order_id'],5,'0',STR_PAD_LEFT) ?> – <?= htmlspecialchars($n['fullname']) ?>
                    <?php if (!$n['is_read']): ?><span class="new-badge">MỚI</span><?php endif; ?>
                </div>
                <div class="ni-sub">📞 <?= htmlspecialchars($n['phone']) ?> &nbsp;|&nbsp; <?= $pay_info[0] ?> &nbsp;|&nbsp; <?= $n['item_count'] ?> sản phẩm</div>
                <div class="ni-sub" style="font-weight:700;color:var(--g2)"><?= fmoney($n['total']) ?></div>
                <div class="ni-time"><i class="fas fa-clock"></i> <?= fdate($n['created_at']) ?></div>
            </div>
            <?php if (!$n['is_read']): ?><div class="ni-dot"></div><?php endif; ?>
        </div>
        <?php endwhile;
        else: ?>
        <div class="empty-notif"><i class="fas fa-bell-slash" style="font-size:36px;opacity:.2;margin-bottom:10px"></i><br>Chưa có thông báo nào</div>
        <?php endif; ?>
        </div>
    </div>

    <!-- PAGE BODY -->
    <div class="page-body">

        <!-- STATS -->
        <div class="stats-row">
            <?php
            $stat_cfg = [
                'pending'    => ['#fef3c7', '#d97706', 'fa-clock'],
                'processing' => ['#dbeafe', '#1d4ed8', 'fa-truck'],
                'completed'  => ['#d1fae5', '#065f46', 'fa-check-circle'],
                'cancelled'  => ['#fee2e2', '#991b1b', 'fa-times-circle'],
            ];
            foreach ($stats as $st => $sv):
                [$bg, $col, $ico] = $stat_cfg[$st];
            ?>
            <div class="stat-card">
                <div class="stat-icon" style="background:<?= $bg ?>;color:<?= $col ?>"><i class="fas <?= $ico ?>"></i></div>
                <div>
                    <div class="stat-num"><?= $sv['count'] ?></div>
                    <div class="stat-lbl"><?= $sv['label'] ?></div>
                    <div class="stat-sub"><?= fmoney($sv['total']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- FILTERS -->
        <form method="GET" action="">
            <div class="filters-bar">
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" class="f-input" placeholder="🔍 Tìm tên, SĐT, mã đơn..." style="flex:1;min-width:200px">
                <select name="status" class="f-select">
                    <option value="all" <?= $filter_status==='all'?'selected':'' ?>>Tất cả trạng thái</option>
                    <?php foreach ($status_labels as $sv => $sl): ?>
                    <option value="<?= $sv ?>" <?= $filter_status===$sv?'selected':'' ?>><?= $sl[0] ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="payment" class="f-select">
                    <option value="all" <?= $filter_payment==='all'?'selected':'' ?>>Tất cả PTTT</option>
                    <option value="cod"     <?= $filter_payment==='cod'?'selected':'' ?>>💵 COD</option>
                    <option value="bank"    <?= $filter_payment==='bank'?'selected':'' ?>>🏦 Chuyển khoản</option>
                    <option value="ewallet" <?= $filter_payment==='ewallet'?'selected':'' ?>>📱 Ví điện tử</option>
                </select>
                <button type="submit" class="f-btn"><i class="fas fa-search"></i>Lọc</button>
                <a href="don_hang_online.php" class="f-btn secondary"><i class="fas fa-redo"></i>Reset</a>
            </div>
        </form>

        <!-- TABLE -->
        <div class="table-card">
            <div class="table-header">
                <div class="table-title"><i class="fas fa-list" style="color:var(--g3)"></i> Danh sách đơn hàng online
                    <span style="font-size:12px;font-weight:600;color:var(--muted);background:#f1f5f9;padding:3px 10px;border-radius:10px"><?= $total_rows ?> đơn</span>
                </div>
                <div style="display:flex;gap:8px">
                    <button onclick="exportExcel()" class="f-btn secondary" style="font-size:12px"><i class="fas fa-file-excel"></i>Excel</button>
                </div>
            </div>
            <div style="overflow-x:auto">
            <table class="ord-table" id="orderTable">
                <thead>
                    <tr>
                        <th>Mã đơn</th>
                        <th>Khách hàng</th>
                        <th>Địa chỉ giao</th>
                        <th>Sản phẩm</th>
                        <th>Tổng tiền</th>
                        <th>PTTT</th>
                        <th>Trạng thái</th>
                        <th>Thời gian</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($orders_res && $orders_res->num_rows > 0):
                    while ($ord = $orders_res->fetch_assoc()):
                        $sl = $status_labels[$ord['status']] ?? ['Không rõ', 'secondary'];
                        $pl = $payment_labels[$ord['payment']] ?? ['💳 '.htmlspecialchars($ord['payment']), '#374151', '#f1f5f9'];
                        // đếm sản phẩm
                        $item_cnt_r = $conn->query("SELECT SUM(quantity) as c FROM order_items WHERE order_id=" . (int)$ord['id']);
                        $item_cnt = $item_cnt_r ? (int)$item_cnt_r->fetch_assoc()['c'] : 0;
                        // kiểm tra thông báo chưa đọc
                        $is_new_r = $conn->query("SELECT id FROM order_notifications WHERE order_id=" . (int)$ord['id'] . " AND is_read=0 LIMIT 1");
                        $is_new = ($is_new_r && $is_new_r->num_rows > 0);
                ?>
                <tr data-id="<?= $ord['id'] ?>">
                    <td>
                        <span class="order-id">#QA-<?= str_pad($ord['id'],5,'0',STR_PAD_LEFT) ?></span>
                        <?php if ($is_new): ?><span class="new-badge">MỚI</span><?php endif; ?>
                    </td>
                    <td>
                        <div class="customer-name"><?= htmlspecialchars($ord['fullname']) ?></div>
                        <div class="customer-phone">📞 <?= htmlspecialchars($ord['phone']) ?></div>
                    </td>
                    <td style="max-width:160px;font-size:12px;color:var(--muted)"><?= htmlspecialchars(mb_strimwidth($ord['address']??'',0,60,'…')) ?></td>
                    <td style="text-align:center;font-weight:700"><?= $item_cnt ?> sp</td>
                    <td style="font-weight:800;color:var(--g2)"><?= fmoney($ord['total_amount']) ?></td>
                    <td><span class="payment-badge" style="color:<?= $pl[1] ?>;background:<?= $pl[2] ?>"><?= $pl[0] ?></span></td>
                    <td>
                        <select class="status-sel status-<?= $ord['status'] ?>" onchange="updateStatus(<?= $ord['id'] ?>, this)">
                            <?php foreach ($status_labels as $sv => $sl2): ?>
                            <option value="<?= $sv ?>" <?= $ord['status']===$sv?'selected':'' ?>><?= $sl2[0] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td style="font-size:12px;color:var(--muted);white-space:nowrap"><?= fdate($ord['created_at']) ?></td>
                    <td><button class="btn-detail" onclick="openOrderDetail(<?= $ord['id'] ?>)"><i class="fas fa-eye"></i> Xem</button></td>
                </tr>
                <?php endwhile;
                else: ?>
                <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--muted)">
                    <i class="fas fa-inbox" style="font-size:36px;opacity:.2;display:block;margin-bottom:10px"></i>
                    Không có đơn hàng nào
                </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>

            <!-- PAGINATION -->
            <?php if ($total_pages > 1): ?>
            <div class="pag" style="padding-bottom:18px">
                <?php
                $base_url = '?' . http_build_query(array_merge($_GET, ['page'=>1]));
                for ($p = 1; $p <= $total_pages; $p++):
                    $url = '?' . http_build_query(array_merge($_GET, ['page'=>$p]));
                ?>
                <?php if ($p == $page): ?>
                <span class="cur"><?= $p ?></span>
                <?php else: ?>
                <a href="<?= $url ?>"><?= $p ?></a>
                <?php endif; ?>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /page-body -->
</div><!-- /main -->

<!-- ══ MODAL CHI TIẾT ĐƠN HÀNG ══ -->
<div class="modal-overlay" id="orderModal" onclick="if(event.target===this)closeModal()">
    <div class="modal-box">
        <div class="modal-hdr">
            <div class="modal-hdr-icon"><i class="fas fa-receipt"></i></div>
            <div class="modal-hdr-title" id="modal-title">Chi tiết đơn hàng</div>
            <button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" id="modal-body">
            <div style="text-align:center;padding:40px;color:var(--muted)"><i class="fas fa-spinner fa-spin fa-2x"></i></div>
        </div>
    </div>
</div>

<script>
/* ════ NOTIFICATION PANEL ════ */
function toggleNotifPanel() {
    const p = document.getElementById('notifPanel');
    p.classList.toggle('open');
}
document.addEventListener('click', e => {
    const btn = document.getElementById('notifBtn');
    const panel = document.getElementById('notifPanel');
    if (!btn.contains(e.target) && !panel.contains(e.target)) {
        panel.classList.remove('open');
    }
});

function markAllRead() {
    fetch('don_hang_online.php?ajax=mark_all_read')
        .then(r => r.json())
        .then(() => {
            document.querySelectorAll('.notif-item.unread').forEach(el => el.classList.remove('unread'));
            document.querySelectorAll('.new-badge').forEach(el => el.remove());
            document.querySelectorAll('.ni-dot').forEach(el => el.remove());
            const badge = document.getElementById('notifBadge');
            if (badge) badge.remove();
        });
}

function openOrderFromNotif(orderId, notifId) {
    // Đánh dấu thông báo đã đọc
    fetch(`don_hang_online.php?ajax=mark_read&id=${notifId}`);
    // Mở modal chi tiết
    openOrderDetail(orderId);
    document.getElementById('notifPanel').classList.remove('open');
}

/* ════ MODAL CHI TIẾT ════ */
function openOrderDetail(orderId) {
    const modal = document.getElementById('orderModal');
    const body  = document.getElementById('modal-body');
    document.getElementById('modal-title').textContent = `Chi tiết đơn #QA-${String(orderId).padStart(5,'0')}`;
    body.innerHTML = '<div style="text-align:center;padding:40px;color:#64748b"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';
    modal.classList.add('open');

    fetch(`?get_order=${orderId}`)
        .then(r => r.json())
        .then(data => {
            if (!data.ok) { body.innerHTML = '<p style="color:red">Lỗi tải dữ liệu!</p>'; return; }
            const o = data.order;
            const items = data.items;
            const payMap = {cod:'💵 Trả khi nhận hàng (COD)', bank:'🏦 Chuyển khoản ngân hàng', ewallet:'📱 Ví điện tử'};
            const statusMap = {pending:'⏳ Chờ xử lý', processing:'🚚 Đang giao hàng', completed:'✅ Hoàn thành', cancelled:'❌ Đã hủy'};
            let itemsHtml = items.map(it => `
                <tr>
                    <td style="padding:10px 0">
                        ${it.image ? `<img src="${it.image}" style="width:48px;height:48px;object-fit:cover;border-radius:8px;vertical-align:middle;margin-right:8px" onerror="this.style.display='none'">` : '💻 '}
                        <span style="font-weight:600;font-size:13px">${it.name}</span>
                    </td>
                    <td style="text-align:right;padding:10px 0 10px 12px;white-space:nowrap;font-size:13px">${Number(it.price).toLocaleString('vi-VN')}đ</td>
                    <td style="text-align:center;padding:10px;font-weight:700">${it.quantity}</td>
                    <td style="text-align:right;padding:10px 0;font-weight:800;color:#047857;white-space:nowrap">${Number(it.subtotal).toLocaleString('vi-VN')}đ</td>
                </tr>
            `).join('');

            body.innerHTML = `
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px">
                    <div style="background:#f0fdf4;border-radius:11px;padding:14px;border:1.5px solid #d1fae5">
                        <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">📋 Thông tin người nhận</div>
                        <div style="font-size:15px;font-weight:800;color:#0f172a;margin-bottom:4px">${o.fullname}</div>
                        <div style="font-size:13px;color:#374151">📞 ${o.phone}</div>
                        ${o.address ? `<div style="font-size:12px;color:#64748b;margin-top:6px">🏠 ${o.address}</div>` : ''}
                    </div>
                    <div style="background:#fafbfc;border-radius:11px;padding:14px;border:1.5px solid #e2e8f0">
                        <div style="font-size:11px;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px">🧾 Thông tin đơn hàng</div>
                        <div style="font-size:13px;color:#374151;line-height:2">
                            <div><strong>Mã đơn:</strong> #QA-${String(o.id).padStart(5,'0')}</div>
                            <div><strong>Thanh toán:</strong> ${payMap[o.payment]||o.payment}</div>
                            <div><strong>Trạng thái:</strong> ${statusMap[o.status]||o.status}</div>
                            <div><strong>Thời gian:</strong> ${new Date(o.created_at).toLocaleString('vi-VN')}</div>
                        </div>
                    </div>
                </div>
                ${o.note ? `<div style="background:#fef3c7;border-radius:10px;padding:11px 14px;margin-bottom:14px;font-size:13px;border-left:4px solid #f59e0b"><strong>📝 Ghi chú:</strong> ${o.note}</div>` : ''}
                <table style="width:100%;border-collapse:collapse;margin-bottom:16px">
                    <thead>
                        <tr style="border-bottom:2px solid #e2e8f0">
                            <th style="padding:8px 0;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;text-align:left">Sản phẩm</th>
                            <th style="padding:8px 12px;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;text-align:right">Đơn giá</th>
                            <th style="padding:8px;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;text-align:center">SL</th>
                            <th style="padding:8px 0;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;text-align:right">Thành tiền</th>
                        </tr>
                    </thead>
                    <tbody style="border-bottom:2px solid #e2e8f0">${itemsHtml}</tbody>
                </table>
                <div style="text-align:right;margin-bottom:14px">
                    <span style="font-size:14px;color:#64748b">Tổng cộng: </span>
                    <span style="font-size:22px;font-weight:900;color:#047857">${Number(o.total_amount).toLocaleString('vi-VN')}đ</span>
                </div>
                <div style="display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap">
                    <select id="modal-status" class="f-select status-${o.status}" onchange="updateStatus(${o.id}, this)">
                        <option value="pending"    ${o.status==='pending'   ?'selected':''}>⏳ Chờ xử lý</option>
                        <option value="processing" ${o.status==='processing'?'selected':''}>🚚 Đang giao hàng</option>
                        <option value="completed"  ${o.status==='completed' ?'selected':''}>✅ Hoàn thành</option>
                        <option value="cancelled"  ${o.status==='cancelled' ?'selected':''}>❌ Đã hủy</option>
                    </select>
                    <a href="tel:${o.phone}" style="background:#065f46;color:#fff;border-radius:9px;padding:8px 18px;font-size:13px;font-weight:800;display:inline-flex;align-items:center;gap:6px">
                        <i class="fas fa-phone-alt"></i>Gọi khách hàng
                    </a>
                </div>
            `;
        });
}

function closeModal() {
    document.getElementById('orderModal').classList.remove('open');
}

/* ════ CẬP NHẬT TRẠNG THÁI ════ */
function updateStatus(orderId, sel) {
    const newStatus = sel.value;
    const data = new URLSearchParams({ order_id: orderId, status: newStatus });
    fetch('don_hang_online.php?ajax=update_status', { method: 'POST', body: data })
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                // Cập nhật class màu cho tất cả select cùng đơn
                document.querySelectorAll(`tr[data-id="${orderId}"] .status-sel`).forEach(el => {
                    el.className = `status-sel status-${newStatus}`;
                    el.value = newStatus;
                });
                // Nếu select trong modal cũng cần update
                const ms = document.getElementById('modal-status');
                if (ms) { ms.className = `f-select status-${newStatus}`; ms.value = newStatus; }
                showToast(`✅ Đã cập nhật trạng thái đơn #QA-${String(orderId).padStart(5,'0')} → ${sel.options[sel.selectedIndex].text}`);
            }
        });
}

/* ════ EXPORT EXCEL ════ */
function exportExcel() {
    const rows = [];
    rows.push(['Mã đơn', 'Khách hàng', 'SĐT', 'Địa chỉ', 'Tổng tiền', 'PTTT', 'Trạng thái', 'Thời gian']);
    document.querySelectorAll('#orderTable tbody tr[data-id]').forEach(tr => {
        const cells = tr.querySelectorAll('td');
        if (cells.length < 8) return;
        rows.push([
            cells[0].querySelector('.order-id')?.textContent?.trim() || '',
            cells[1].querySelector('.customer-name')?.textContent?.trim() || '',
            cells[1].querySelector('.customer-phone')?.textContent?.replace('📞','').trim() || '',
            cells[2].textContent.trim(),
            cells[4].textContent.trim(),
            cells[5].textContent.trim(),
            cells[6].querySelector('select')?.options[cells[6].querySelector('select')?.selectedIndex]?.text || '',
            cells[7].textContent.trim(),
        ]);
    });
    if (typeof XLSX === 'undefined') { alert('Thư viện XLSX chưa tải. Vui lòng thử lại sau vài giây.'); return; }
    const wb = XLSX.utils.book_new();
    const ws = XLSX.utils.aoa_to_sheet(rows);
    ws['!cols'] = [{wch:12},{wch:22},{wch:14},{wch:30},{wch:14},{wch:18},{wch:16},{wch:20}];
    XLSX.utils.book_append_sheet(wb, ws, 'Đơn hàng online');
    XLSX.writeFile(wb, `don_hang_online_${new Date().toISOString().slice(0,10)}.xlsx`);
}

/* ════ TOAST ════ */
function showToast(msg, color='#047857') {
    let t = document.getElementById('_toast');
    if (!t) {
        t = document.createElement('div');
        t.id = '_toast';
        t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#047857;color:#fff;padding:12px 20px;border-radius:12px;font-size:13px;font-weight:700;z-index:9999;box-shadow:0 8px 24px rgba(6,95,70,.4);transition:.3s;opacity:0;transform:translateY(20px);font-family:Be Vietnam Pro,sans-serif;display:flex;align-items:center;gap:8px';
        document.body.appendChild(t);
    }
    t.style.background = color;
    t.textContent = msg;
    t.style.opacity = '1'; t.style.transform = 'translateY(0)';
    clearTimeout(t._tmr);
    t._tmr = setTimeout(() => { t.style.opacity = '0'; t.style.transform = 'translateY(20px)'; }, 3000);
}

/* ════ POLLING: kiểm tra thông báo mới mỗi 30 giây ════ */
let lastUnreadCount = <?= $unread_count ?>;
setInterval(() => {
    fetch('don_hang_online.php?ajax=unread_count')
        .then(r => r.json())
        .then(d => {
            const newCount = d.count || 0;
            if (newCount > lastUnreadCount) {
                showToast(`🔔 Có ${newCount - lastUnreadCount} đơn hàng mới!`, '#047857');
                // Phát âm thanh thông báo nếu browser hỗ trợ
                try {
                    const ctx = new (window.AudioContext || window.webkitAudioContext)();
                    const osc = ctx.createOscillator();
                    osc.connect(ctx.destination);
                    osc.frequency.value = 880;
                    osc.start(); osc.stop(ctx.currentTime + 0.2);
                } catch(e) {}
                // Cập nhật badge
                let badge = document.getElementById('notifBadge');
                if (!badge) {
                    badge = document.createElement('span');
                    badge.id = 'notifBadge';
                    badge.className = 'notif-badge';
                    document.getElementById('notifBtn').appendChild(badge);
                }
                badge.textContent = newCount;
                // Reload trang để hiện đơn mới
                if (newCount > lastUnreadCount) setTimeout(() => location.reload(), 500);
            }
            lastUnreadCount = newCount;
        }).catch(() => {});
}, 30000);

/* ════ Keyboard ════ */
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeModal();
});
</script>

<?php
/* ════ XỬ LÝ AJAX: lấy chi tiết đơn hàng ════ */
if (isset($_GET['get_order'])) {
    header('Content-Type: application/json; charset=utf-8');
    $oid = intval($_GET['get_order']);
    $order = $conn->query("SELECT * FROM orders WHERE id=$oid")->fetch_assoc();
    if (!$order) { echo json_encode(['ok'=>false,'msg'=>'Không tìm thấy đơn']); exit; }
    $items = [];
    $ir = $conn->query("SELECT * FROM order_items WHERE order_id=$oid");
    if ($ir) while ($row = $ir->fetch_assoc()) $items[] = $row;
    echo json_encode(['ok' => true, 'order' => $order, 'items' => $items]);
    exit;
}
?>

<!-- SheetJS for Excel export -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
</body>
</html>