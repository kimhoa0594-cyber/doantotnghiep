<?php
// file: nhanvien/khuyen_mai.php
session_start();
require_once '../db.php';

// Kiểm tra đăng nhập và role nhân viên
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'nhan_vien' && $_SESSION['role'] !== 'employee')) {
    header("Location: ../login.php");
    exit();
}

$employee_name = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Nhân viên';

// ========================
// TẠO BẢNG PROMOTIONS (nếu chưa có)
// ========================
$conn->query("CREATE TABLE IF NOT EXISTS `promotions` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(255) NOT NULL,
    `code` varchar(50) NOT NULL UNIQUE,
    `description` text,
    `office_address` varchar(255) DEFAULT NULL,
    `image` varchar(255) DEFAULT NULL,
    `type` enum('percent','fixed') DEFAULT 'percent',
    `value` decimal(10,2) DEFAULT 0,
    `min_order_value` decimal(10,2) DEFAULT 0,
    `max_discount` decimal(10,2) DEFAULT 0,
    `usage_limit` int(11) DEFAULT 100,
    `used_count` int(11) DEFAULT 0,
    `target_user` enum('all','new','vip') DEFAULT 'all',
    `start_date` date NOT NULL,
    `end_date` date NOT NULL,
    `status` tinyint(1) DEFAULT 1,
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ========================
// XỬ LÝ THÊM / SỬA
// ========================
function uploadImage($file) {
    if (isset($file) && $file['error'] === 0 && $file['size'] > 0) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($ext, $allowed)) return null;
        $filename = time() . '_' . uniqid() . '.' . $ext;
        $target = "../uploads/promotions/" . $filename;
        if (!is_dir('../uploads/promotions/')) mkdir('../uploads/promotions/', 0777, true);
        if (move_uploaded_file($file['tmp_name'], $target)) return $filename;
    }
    return null;
}

// Thêm khuyến mãi mới
if (isset($_POST['add_promo'])) {
    $name = $conn->real_escape_string($_POST['name'] ?? '');
    $code = strtoupper($conn->real_escape_string($_POST['code'] ?? ''));
    $desc = $conn->real_escape_string($_POST['description'] ?? '');
    $address = $conn->real_escape_string($_POST['office_address'] ?? '');
    $type = $_POST['type'] ?? 'percent';
    $value = (float)($_POST['value'] ?? 0);
    $min_order = (float)($_POST['min_order_value'] ?? 0);
    $max_disc = (float)($_POST['max_discount'] ?? 0);
    $limit = (int)($_POST['usage_limit'] ?? 100);
    $target = $_POST['target_user'] ?? 'all';
    $start = $_POST['start_date'] ?? date('Y-m-d');
    $end = $_POST['end_date'] ?? date('Y-m-d', strtotime('+1 month'));
    $image = uploadImage($_FILES['image'] ?? null);

    // Kiểm tra mã trùng
    $check = $conn->query("SELECT id FROM promotions WHERE code = '$code'");
    if ($check && $check->num_rows > 0) {
        $_SESSION['error'] = "Mã khuyến mãi '$code' đã tồn tại!";
    } else {
        $sql = "INSERT INTO promotions (name, code, description, office_address, image, type, value, 
                min_order_value, max_discount, usage_limit, target_user, start_date, end_date, status) 
                VALUES ('$name', '$code', '$desc', '$address', '$image', '$type', $value, $min_order, 
                $max_disc, $limit, '$target', '$start', '$end', 1)";
        if ($conn->query($sql)) {
            $_SESSION['success'] = "✓ Đã thêm khuyến mãi mới thành công!";
        } else {
            $_SESSION['error'] = "Lỗi: " . $conn->error;
        }
    }
    header("Location: khuyen_mai.php");
    exit();
}

// Cập nhật khuyến mãi
if (isset($_POST['edit_promo'])) {
    $id = (int)$_POST['id'];
    $name = $conn->real_escape_string($_POST['name'] ?? '');
    $desc = $conn->real_escape_string($_POST['description'] ?? '');
    $address = $conn->real_escape_string($_POST['office_address'] ?? '');
    $type = $_POST['type'] ?? 'percent';
    $value = (float)($_POST['value'] ?? 0);
    $min_order = (float)($_POST['min_order_value'] ?? 0);
    $max_disc = (float)($_POST['max_discount'] ?? 0);
    $limit = (int)($_POST['usage_limit'] ?? 100);
    $target = $_POST['target_user'] ?? 'all';
    $start = $_POST['start_date'] ?? date('Y-m-d');
    $end = $_POST['end_date'] ?? date('Y-m-d', strtotime('+1 month'));
    $status = (int)($_POST['status'] ?? 1);
    
    $image_update = "";
    $new_img = uploadImage($_FILES['image'] ?? null);
    if ($new_img) {
        $image_update = ", image='$new_img'";
    }
    
    $sql = "UPDATE promotions SET name='$name', description='$desc', office_address='$address', 
            type='$type', value=$value, min_order_value=$min_order, max_discount=$max_disc, 
            usage_limit=$limit, target_user='$target', start_date='$start', end_date='$end', 
            status=$status $image_update WHERE id=$id";
    if ($conn->query($sql)) {
        $_SESSION['success'] = "✓ Đã cập nhật khuyến mãi!";
    } else {
        $_SESSION['error'] = "Lỗi: " . $conn->error;
    }
    header("Location: khuyen_mai.php");
    exit();
}

// Xóa khuyến mãi
if (isset($_GET['del'])) {
    $id = (int)$_GET['del'];
    $conn->query("DELETE FROM promotions WHERE id = $id");
    $_SESSION['success'] = "Đã xóa khuyến mãi thành công!";
    header("Location: khuyen_mai.php");
    exit();
}

// Bật/tắt trạng thái
if (isset($_GET['toggle_status']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $current = (int)$_GET['toggle_status'];
    $new = $current == 1 ? 0 : 1;
    $conn->query("UPDATE promotions SET status = $new WHERE id = $id");
    header("Location: khuyen_mai.php");
    exit();
}

// ========================
// LỌC & TÌM KIẾM
// ========================
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

$where = "WHERE (name LIKE '%$search%' OR code LIKE '%$search%')";
if ($filter == 'active') {
    $where .= " AND status = 1 AND end_date >= CURDATE()";
} elseif ($filter == 'expired') {
    $where .= " AND (status = 0 OR end_date < CURDATE())";
} elseif ($filter == 'soon') {
    $where .= " AND status = 1 AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
}

$sort = isset($_GET['sort']) ? $_GET['sort'] : 'id_desc';
$order_by = match($sort) {
    'date_asc' => "end_date ASC",
    'value_desc' => "value DESC",
    'used_desc' => "used_count DESC",
    default => "id DESC"
};

$promos = $conn->query("SELECT * FROM promotions $where ORDER BY $order_by");

// Thống kê
$total_all = $conn->query("SELECT COUNT(*) as c FROM promotions")->fetch_assoc()['c'] ?? 0;
$total_active = $conn->query("SELECT COUNT(*) as c FROM promotions WHERE status = 1 AND end_date >= CURDATE()")->fetch_assoc()['c'] ?? 0;
$total_expired = $conn->query("SELECT COUNT(*) as c FROM promotions WHERE status = 0 OR end_date < CURDATE()")->fetch_assoc()['c'] ?? 0;
$total_used = $conn->query("SELECT SUM(used_count) as total FROM promotions")->fetch_assoc()['total'] ?? 0;
$total_limit = $conn->query("SELECT SUM(usage_limit) as total FROM promotions WHERE status=1")->fetch_assoc()['total'] ?? 1;
$usage_rate = $total_limit > 0 ? round(($total_used / $total_limit) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Quản lý Khuyến mãi — QA Tech (Nhân viên)</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<style>
:root {
    --blue-primary: #2563eb;
    --blue-dark: #1d4ed8;
    --blue-light: #dbeafe;
    --sidebar-bg: #0f172a;
    --sidebar-w: 268px;
    --surface: #ffffff;
    --surface-2: #f8fafc;
    --border: #e2e8f0;
    --text-primary: #0f172a;
    --text-muted: #64748b;
    --radius: 14px;
    --shadow-sm: 0 1px 3px rgba(0,0,0,.06);
    --shadow-md: 0 4px 16px rgba(0,0,0,.08);
}
*,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Be Vietnam Pro', sans-serif;
    background: #f1f5f9;
    color: var(--text-primary);
}
::-webkit-scrollbar { width: 5px; }
::-webkit-scrollbar-track { background: #e2e8f0; }
::-webkit-scrollbar-thumb { background: var(--blue-primary); border-radius: 99px; }

/* Sidebar */
.sidebar {
    width: var(--sidebar-w);
    height: 100vh;
    position: fixed;
    top: 0; left: 0;
    background: var(--sidebar-bg);
    display: flex;
    flex-direction: column;
    z-index: 1000;
    border-right: 1px solid rgba(255,255,255,0.05);
}
.sidebar-brand {
    padding: 24px 20px 20px;
    border-bottom: 1px solid rgba(255,255,255,0.07);
}
.brand-logo { display: flex; align-items: center; gap: 12px; }
.brand-icon {
    width: 40px; height: 40px;
    background: linear-gradient(135deg, var(--blue-primary), #3b82f6);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; color: white;
}
.brand-name { font-size: 15px; font-weight: 800; color: #fff; }
.brand-sub { font-size: 10px; font-weight: 500; color: #64748b; text-transform: uppercase; }
.sidebar-nav { flex: 1; padding: 16px 0; overflow-y: auto; }
.nav-section-label {
    font-size: 9px; font-weight: 700; letter-spacing: 1.4px;
    text-transform: uppercase; color: #334155;
    padding: 16px 20px 6px;
}
.nav-link-item {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 20px;
    color: #94a3b8;
    text-decoration: none;
    font-size: 13.5px; font-weight: 500;
    transition: all 0.2s ease;
}
.nav-link-item:hover { color: #e2e8f0; background: rgba(255,255,255,.04); }
.nav-link-item.active { color: #fff; background: rgba(37,99,235,.12); }
.nav-icon {
    width: 32px; height: 32px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 8px;
    font-size: 13px;
    background: rgba(255,255,255,.05);
}
.sidebar-footer {
    padding: 16px 20px;
    border-top: 1px solid rgba(255,255,255,.07);
}
.logout-btn {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 12px;
    color: #f87171;
    text-decoration: none;
    font-size: 13px; font-weight: 600;
    border-radius: 10px;
}
.logout-btn:hover { background: rgba(239,68,68,.1); color: #fca5a5; }

/* Main */
.main {
    margin-left: var(--sidebar-w);
    min-height: 100vh;
    padding: 28px 32px;
}
.topbar {
    position: sticky; top: 0; z-index: 100;
    background: rgba(248,250,252,.9);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid var(--border);
    padding: 0 32px;
    height: 64px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin: -28px -32px 28px -32px;
}
.avatar-btn {
    display: flex; align-items: center; gap: 10px;
    padding: 6px 12px 6px 6px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 40px;
    text-decoration: none;
    color: var(--text-primary);
}
.avatar-img { width: 32px; height: 32px; border-radius: 50%; }
.avatar-name { font-size: 13px; font-weight: 600; }

/* Page header */
.page-header { margin-bottom: 24px; }
.page-header h1 { font-size: 22px; font-weight: 800; color: var(--text-primary); }
.page-header p { font-size: 13px; color: var(--text-muted); margin-top: 4px; }

/* Stats */
.stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
.stat-card {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 18px 20px;
    border: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 14px;
    transition: all .2s;
}
.stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
.stat-icon {
    width: 48px; height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
}
.stat-icon.blue { background: #dbeafe; color: #2563eb; }
.stat-icon.green { background: #d1fae5; color: #059669; }
.stat-icon.red { background: #fee2e2; color: #ef4444; }
.stat-icon.amber { background: #fef3c7; color: #d97706; }
.stat-value { font-size: 26px; font-weight: 800; line-height: 1; }
.stat-label { font-size: 12px; color: var(--text-muted); margin-top: 4px; }

/* Toolbar */
.toolbar {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px 20px;
    margin-bottom: 20px;
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: center;
}
.filter-pills { display: flex; gap: 8px; flex-wrap: wrap; flex: 1; }
.filter-pill {
    padding: 6px 16px;
    border-radius: 20px;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text-muted);
    background: #f8fafc;
    border: 1px solid var(--border);
    text-decoration: none;
    transition: all .15s;
}
.filter-pill:hover { background: var(--blue-light); color: var(--blue-primary); }
.filter-pill.active { background: var(--blue-primary); color: #fff; border-color: var(--blue-primary); }
.sort-select {
    padding: 7px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 13px;
    background: #fff;
    cursor: pointer;
}
.search-wrap {
    display: flex;
    align-items: center;
    gap: 8px;
    background: #f8fafc;
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 6px 12px;
}
.search-wrap input {
    border: none;
    background: transparent;
    outline: none;
    font-size: 13px;
    min-width: 200px;
}
.btn-filter {
    background: var(--blue-primary);
    color: #fff;
    border: none;
    padding: 8px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
}
.btn-filter:hover { background: var(--blue-dark); }

/* Promo grid */
.promo-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; }
.promo-card {
    background: var(--surface);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    overflow: hidden;
    transition: all .2s;
}
.promo-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); }
.promo-banner {
    height: 130px;
    background: linear-gradient(135deg, #1e40af, #2563eb, #3b82f6);
    position: relative;
    display: flex;
    align-items: flex-end;
    justify-content: center;
    padding: 16px;
}
.promo-banner img { width: 100%; height: 100%; object-fit: cover; position: absolute; inset: 0; }
.promo-value {
    position: relative;
    font-size: 32px;
    font-weight: 800;
    color: #fff;
    text-shadow: 0 2px 8px rgba(0,0,0,.2);
}
.promo-badge {
    position: absolute;
    top: 10px;
    right: 10px;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 700;
}
.badge-active { background: #10b981; color: #fff; }
.badge-expired { background: #64748b; color: #fff; }
.badge-soon { background: #f59e0b; color: #fff; animation: pulse 1.5s infinite; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.7} }
.promo-body { padding: 16px; }
.promo-name { font-size: 14px; font-weight: 700; margin-bottom: 8px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.promo-code {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #f1f5f9;
    border: 1px dashed #cbd5e1;
    border-radius: 8px;
    padding: 5px 12px;
    font-size: 12px;
    font-weight: 700;
    font-family: monospace;
    cursor: pointer;
    margin-bottom: 12px;
}
.promo-code:hover { background: #e2e8f0; }
.promo-meta { display: flex; flex-direction: column; gap: 6px; font-size: 12px; }
.meta-row { display: flex; justify-content: space-between; align-items: center; }
.meta-label { color: var(--text-muted); display: flex; align-items: center; gap: 5px; }
.meta-value { font-weight: 600; }
.usage-bar { margin-top: 8px; }
.usage-bar .bar { height: 5px; background: #e2e8f0; border-radius: 3px; overflow: hidden; }
.usage-bar .fill { height: 100%; background: #10b981; border-radius: 3px; }
.promo-footer {
    border-top: 1px solid var(--border);
    padding: 12px 16px;
    display: flex;
    gap: 8px;
}
.promo-btn {
    flex: 1;
    padding: 6px 0;
    border-radius: 7px;
    font-size: 12px;
    font-weight: 600;
    text-align: center;
    cursor: pointer;
    background: #f8fafc;
    border: 1px solid var(--border);
    text-decoration: none;
    color: var(--text-muted);
}
.promo-btn:hover { background: var(--blue-light); color: var(--blue-primary); }
.promo-btn.danger:hover { background: #fee2e2; color: #dc2626; border-color: #fecaca; }

/* Modal */
.modal-content { border-radius: 14px; border: none; }
.modal-header { border-bottom: 1px solid var(--border); padding: 16px 20px; }
.modal-title { font-weight: 700; font-size: 15px; }
.form-label-sm { font-size: 12px; font-weight: 600; margin-bottom: 5px; }
.form-control-sm, .form-select-sm { font-size: 13px; border-radius: 8px; }
.img-preview {
    width: 100%;
    height: 100px;
    border-radius: 8px;
    object-fit: cover;
    margin-top: 8px;
    display: none;
}
.empty-state { text-align: center; padding: 60px; color: var(--text-muted); }
.empty-icon { font-size: 48px; margin-bottom: 16px; opacity: 0.3; }

/* Alert */
.alert-custom {
    padding: 12px 18px;
    border-radius: 10px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.alert-success { background: #d1fae5; border: 1px solid #86efac; color: #065f46; }
.alert-danger { background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; }

@media (max-width: 768px) {
    .sidebar { transform: translateX(-100%); }
    .main { margin-left: 0; padding: 16px; }
    .topbar { margin: -16px -16px 20px -16px; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>
</head>
<body>

<!-- Sidebar -->
<aside class="sidebar">
    <div class="sidebar-brand">
        <div class="brand-logo">
            <div class="brand-icon"><i class="fas fa-store"></i></div>
            <div class="brand-text">
                <div class="brand-name">Quang Anh Tech</div>
                <div class="brand-sub">Nhân viên</div>
            </div>
        </div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-section-label">Tổng quan</div>
        <a href="trang_chu.php" class="nav-link-item">
            <div class="nav-icon"><i class="fas fa-chart-pie"></i></div> Trang chủ
        </a>
        <div class="nav-section-label">Quản lý</div>
        <a href="quan_ly_khach_hang.php" class="nav-link-item">
            <div class="nav-icon"><i class="fas fa-users"></i></div> Khách hàng
        </a>
        <div class="nav-section-label">Nội dung</div>
        <a href="quan_ly_bai_viet.php" class="nav-link-item">
            <div class="nav-icon"><i class="fas fa-newspaper"></i></div> Quản lý bài viết
        </a>
        <a href="dang_bai_moi.php" class="nav-link-item">
            <div class="nav-icon"><i class="fas fa-pen-fancy"></i></div> Đăng bài mới
        </a>
        <div class="nav-section-label">Marketing</div>
        <a href="khuyen_mai.php" class="nav-link-item active">
            <div class="nav-icon"><i class="fas fa-percentage"></i></div> Khuyến mãi
        </a>
        <div class="nav-section-label">Cá nhân</div>
        <a href="thong_tin_ca_nhan.php" class="nav-link-item">
            <div class="nav-icon"><i class="fas fa-user-circle"></i></div> Thông tin cá nhân
        </a>
        <a href="doi_mat_khau.php" class="nav-link-item">
            <div class="nav-icon"><i class="fas fa-key"></i></div> Đổi mật khẩu
        </a>
    </nav>
    <div class="sidebar-footer">
        <a href="../logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i> Đăng xuất
        </a>
    </div>
</aside>

<!-- Main -->
<main class="main">
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <span class="fw-bold">Khuyến mãi</span>
            <span class="text-muted">/</span>
            <span class="text-muted">Quản lý ưu đãi</span>
        </div>
        <div class="dropdown">
            <a href="#" class="avatar-btn dropdown-toggle text-decoration-none" data-bs-toggle="dropdown">
                <img src="https://ui-avatars.com/api/?name=<?= urlencode($employee_name) ?>&background=2563eb&color=fff&bold=true"
                     class="avatar-img" alt="avatar">
                <span class="avatar-name"><?= htmlspecialchars($employee_name) ?></span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 mt-2">
                <li><a class="dropdown-item" href="thong_tin_ca_nhan.php"><i class="fas fa-user-circle me-2"></i> Thông tin cá nhân</a></li>
                <li><a class="dropdown-item" href="doi_mat_khau.php"><i class="fas fa-key me-2"></i> Đổi mật khẩu</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Đăng xuất</a></li>
            </ul>
        </div>
    </div>

    <div class="page-header">
        <h1>Quản lý khuyến mãi</h1>
        <p>Tạo và quản lý các chương trình ưu đãi, mã giảm giá</p>
    </div>

    <!-- Alert -->
    <?php if(isset($_SESSION['success'])): ?>
    <div class="alert-custom alert-success">
        <i class="fas fa-check-circle"></i> <?= $_SESSION['success']; unset($_SESSION['success']); ?>
    </div>
    <?php endif; ?>
    <?php if(isset($_SESSION['error'])): ?>
    <div class="alert-custom alert-danger">
        <i class="fas fa-exclamation-triangle"></i> <?= $_SESSION['error']; unset($_SESSION['error']); ?>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fas fa-tags"></i></div>
            <div><div class="stat-value"><?= $total_all ?></div><div class="stat-label">Tổng khuyến mãi</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green"><i class="fas fa-play-circle"></i></div>
            <div><div class="stat-value"><?= $total_active ?></div><div class="stat-label">Đang diễn ra</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon amber"><i class="fas fa-fire"></i></div>
            <div><div class="stat-value"><?= number_format($total_used) ?></div><div class="stat-label">Lượt sử dụng</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon red"><div class="progress-thin-bar" style="width:<?= $usage_rate ?>%;height:4px;background:#10b981;border-radius:2px;"></div></div>
            <div><div class="stat-value"><?= $usage_rate ?>%</div><div class="stat-label">Tỷ lệ sử dụng</div></div>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
        <div class="filter-pills">
            <a href="?filter=all" class="filter-pill <?= $filter=='all' ? 'active' : '' ?>">Tất cả</a>
            <a href="?filter=active" class="filter-pill <?= $filter=='active' ? 'active' : '' ?>">Đang chạy</a>
            <a href="?filter=expired" class="filter-pill <?= $filter=='expired' ? 'active' : '' ?>">Đã kết thúc</a>
            <a href="?filter=soon" class="filter-pill <?= $filter=='soon' ? 'active' : '' ?>">Sắp hết hạn</a>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <form method="GET" class="d-flex gap-2">
                <input type="hidden" name="filter" value="<?= $filter ?>">
                <select name="sort" class="sort-select" onchange="this.form.submit()">
                    <option value="id_desc" <?= $sort=='id_desc' ? 'selected' : '' ?>>Mới nhất</option>
                    <option value="date_asc" <?= $sort=='date_asc' ? 'selected' : '' ?>>Sắp hết hạn</option>
                    <option value="value_desc" <?= $sort=='value_desc' ? 'selected' : '' ?>>Giá trị cao nhất</option>
                    <option value="used_desc" <?= $sort=='used_desc' ? 'selected' : '' ?>>Dùng nhiều nhất</option>
                </select>
                <div class="search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" placeholder="Tìm theo tên, mã..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Lọc</button>
            </form>
            <button class="btn-filter" style="background:#10b981;" data-bs-toggle="modal" data-bs-target="#addPromoModal">
                <i class="fas fa-plus"></i> Thêm khuyến mãi
            </button>
        </div>
    </div>

    <!-- Promo Grid -->
    <div class="promo-grid">
    <?php if ($promos && $promos->num_rows > 0): 
        while($p = $promos->fetch_assoc()):
            $end_ts = strtotime($p['end_date']);
            $is_expired = $end_ts < time() || $p['status'] == 0;
            $is_soon = !$is_expired && ($end_ts - time() < 604800);
            $days_left = ceil(($end_ts - time()) / 86400);
            $value_display = $p['type'] == 'percent' ? $p['value'] . '%' : number_format($p['value']) . 'đ';
            $percent_used = $p['usage_limit'] > 0 ? round(($p['used_count'] / $p['usage_limit']) * 100) : 0;
    ?>
    <div class="promo-card">
        <div class="promo-banner">
            <?php if(!empty($p['image'])): ?>
                <img src="../uploads/promotions/<?= $p['image'] ?>" alt="<?= htmlspecialchars($p['name']) ?>">
            <?php endif; ?>
            <div class="promo-value"><?= $value_display ?></div>
            <span class="promo-badge <?= $is_expired ? 'badge-expired' : ($is_soon ? 'badge-soon' : 'badge-active') ?>">
                <?= $is_expired ? 'Đã kết thúc' : ($is_soon ? 'Sắp hết!' : 'Đang diễn ra') ?>
            </span>
        </div>
        <div class="promo-body">
            <div class="promo-name" title="<?= htmlspecialchars($p['name']) ?>"><?= htmlspecialchars($p['name']) ?></div>
            <div class="promo-code" onclick="copyCode('<?= $p['code'] ?>')">
                <i class="fas fa-ticket-alt"></i> <span><?= $p['code'] ?></span>
                <i class="far fa-copy"></i>
            </div>
            <div class="promo-meta">
                <div class="meta-row">
                    <span class="meta-label"><i class="fas fa-shopping-cart"></i> Đơn tối thiểu</span>
                    <span class="meta-value"><?= number_format($p['min_order_value']) ?>đ</span>
                </div>
                <?php if($p['max_discount'] > 0): ?>
                <div class="meta-row">
                    <span class="meta-label"><i class="fas fa-hand-holding-usd"></i> Giảm tối đa</span>
                    <span class="meta-value"><?= number_format($p['max_discount']) ?>đ</span>
                </div>
                <?php endif; ?>
                <div class="meta-row">
                    <span class="meta-label"><i class="fas fa-calendar-alt"></i> Hết hạn</span>
                    <span class="meta-value <?= $is_soon ? 'text-warning' : '' ?>">
                        <?= date('d/m/Y', $end_ts) ?>
                        <?php if(!$is_expired && $days_left > 0): ?>
                        <small class="text-muted">(còn <?= $days_left ?> ngày)</small>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="usage-bar">
                    <div class="meta-row mb-1">
                        <span class="meta-label"><i class="fas fa-users"></i> Đã dùng</span>
                        <span><?= $p['used_count'] ?> / <?= $p['usage_limit'] ?></span>
                    </div>
                    <div class="bar">
                        <div class="fill" style="width: <?= min($percent_used, 100) ?>%; background: <?= $percent_used >= 80 ? '#ef4444' : ($percent_used >= 50 ? '#f59e0b' : '#10b981') ?>;"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="promo-footer">
            <button class="promo-btn" onclick='openEditModal(<?= json_encode($p) ?>)'><i class="fas fa-edit"></i> Sửa</button>
            <a href="?toggle_status=<?= $p['status'] ?>&id=<?= $p['id'] ?>&filter=<?= $filter ?>" class="promo-btn">
                <i class="fas <?= $p['status'] == 1 ? 'fa-pause' : 'fa-play' ?>"></i> <?= $p['status'] == 1 ? 'Tạm dừng' : 'Kích hoạt' ?>
            </a>
            <a href="?del=<?= $p['id'] ?>" class="promo-btn danger" onclick="return confirmDelete(this.href, '<?= addslashes($p['name']) ?>')">
                <i class="fas fa-trash"></i> Xóa
            </a>
        </div>
    </div>
    <?php endwhile; else: ?>
    <div class="empty-state" style="grid-column: 1/-1;">
        <div class="empty-icon"><i class="fas fa-tags"></i></div>
        <h5>Chưa có khuyến mãi nào</h5>
        <p>Hãy tạo chương trình khuyến mãi đầu tiên</p>
        <button class="btn-filter mt-3" style="background:#10b981;" data-bs-toggle="modal" data-bs-target="#addPromoModal">
            <i class="fas fa-plus"></i> Thêm khuyến mãi
        </button>
    </div>
    <?php endif; ?>
    </div>
</main>

<!-- Modal Thêm khuyến mãi -->
<div class="modal fade" id="addPromoModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle text-success me-2"></i>Thêm khuyến mãi mới</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label class="form-label-sm">Tên khuyến mãi <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control form-control-sm" required placeholder="VD: Flash Sale Tháng 6">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label-sm">Mã voucher <span class="text-danger">*</span></label>
                                <input type="text" name="code" class="form-control form-control-sm text-uppercase" required placeholder="FLASH30" style="font-family: monospace;">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label-sm">Loại giảm giá</label>
                                <select name="type" class="form-select form-select-sm">
                                    <option value="percent">Phần trăm (%)</option>
                                    <option value="fixed">Số tiền cố định (đ)</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label-sm">Giá trị</label>
                                <input type="number" name="value" class="form-control form-control-sm" min="0" step="1000" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label-sm">Giảm tối đa (đ)</label>
                                <input type="number" name="max_discount" class="form-control form-control-sm" min="0" value="0">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label-sm">Đơn tối thiểu (đ)</label>
                                <input type="number" name="min_order_value" class="form-control form-control-sm" min="0" value="0">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label-sm">Giới hạn lượt dùng</label>
                                <input type="number" name="usage_limit" class="form-control form-control-sm" min="1" value="100">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label-sm">Đối tượng áp dụng</label>
                                <select name="target_user" class="form-select form-select-sm">
                                    <option value="all">Tất cả khách hàng</option>
                                    <option value="new">Khách hàng mới</option>
                                    <option value="vip">Khách VIP</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label class="form-label-sm">Ngày bắt đầu</label>
                                <input type="date" name="start_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label class="form-label-sm">Ngày kết thúc</label>
                                <input type="date" name="end_date" class="form-control form-control-sm" value="<?= date('Y-m-d', strtotime('+1 month')) ?>">
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="mb-3">
                                <label class="form-label-sm">Mô tả chi tiết</label>
                                <textarea name="description" class="form-control" rows="2" placeholder="Điều khoản áp dụng, điều kiện sử dụng..."></textarea>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="mb-3">
                                <label class="form-label-sm">Địa chỉ áp dụng</label>
                                <input type="text" name="office_address" class="form-control form-control-sm" placeholder="VD: Toàn quốc, 57 Nguyễn Bình, Hải Phòng...">
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="mb-3">
                                <label class="form-label-sm">Ảnh banner</label>
                                <input type="file" name="image" class="form-control form-control-sm" accept="image/*" onchange="previewImage(this, 'addPreview')">
                                <img id="addPreview" class="img-preview" style="display: none;">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-outline-secondary" type="button" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" name="add_promo" class="btn btn-success btn-sm"><i class="fas fa-save"></i> Lưu</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Sửa khuyến mãi -->
<div class="modal fade" id="editPromoModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-edit text-primary me-2"></i>Chỉnh sửa khuyến mãi</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="editForm">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label class="form-label-sm">Tên khuyến mãi</label>
                                <input type="text" name="name" id="edit_name" class="form-control form-control-sm" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label-sm">Trạng thái</label>
                                <select name="status" id="edit_status" class="form-select form-select-sm">
                                    <option value="1">🟢 Đang kích hoạt</option>
                                    <option value="0">🔴 Tạm dừng</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label-sm">Loại giảm giá</label>
                                <select name="type" id="edit_type" class="form-select form-select-sm">
                                    <option value="percent">Phần trăm (%)</option>
                                    <option value="fixed">Số tiền cố định (đ)</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label-sm">Giá trị</label>
                                <input type="number" name="value" id="edit_value" class="form-control form-control-sm" min="0" step="1000">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label-sm">Giảm tối đa (đ)</label>
                                <input type="number" name="max_discount" id="edit_max_disc" class="form-control form-control-sm" min="0">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label-sm">Đơn tối thiểu (đ)</label>
                                <input type="number" name="min_order_value" id="edit_min_order" class="form-control form-control-sm" min="0">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label-sm">Giới hạn lượt dùng</label>
                                <input type="number" name="usage_limit" id="edit_limit" class="form-control form-control-sm" min="1">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label-sm">Đối tượng áp dụng</label>
                                <select name="target_user" id="edit_target" class="form-select form-select-sm">
                                    <option value="all">Tất cả khách hàng</option>
                                    <option value="new">Khách hàng mới</option>
                                    <option value="vip">Khách VIP</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label class="form-label-sm">Ngày bắt đầu</label>
                                <input type="date" name="start_date" id="edit_start" class="form-control form-control-sm">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label class="form-label-sm">Ngày kết thúc</label>
                                <input type="date" name="end_date" id="edit_end" class="form-control form-control-sm">
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="mb-3">
                                <label class="form-label-sm">Mô tả chi tiết</label>
                                <textarea name="description" id="edit_desc" class="form-control" rows="2"></textarea>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="mb-3">
                                <label class="form-label-sm">Địa chỉ áp dụng</label>
                                <input type="text" name="office_address" id="edit_address" class="form-control form-control-sm">
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="mb-3">
                                <label class="form-label-sm">Ảnh banner (để trống nếu không đổi)</label>
                                <input type="file" name="image" class="form-control form-control-sm" accept="image/*" onchange="previewImage(this, 'editPreview')">
                                <img id="editPreview" class="img-preview" style="display: none;">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-outline-secondary" type="button" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" name="edit_promo" class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Cập nhật</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function copyCode(code) {
    navigator.clipboard.writeText(code).then(() => {
        Swal.fire({
            icon: 'success',
            title: 'Đã sao chép!',
            text: 'Mã ' + code,
            timer: 1500,
            showConfirmButton: false
        });
    });
}

function previewImage(input, previewId) {
    const preview = document.getElementById(previewId);
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            preview.src = e.target.result;
            preview.style.display = 'block';
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function openEditModal(data) {
    document.getElementById('edit_id').value = data.id;
    document.getElementById('edit_name').value = data.name;
    document.getElementById('edit_type').value = data.type;
    document.getElementById('edit_value').value = data.value;
    document.getElementById('edit_max_disc').value = data.max_discount;
    document.getElementById('edit_min_order').value = data.min_order_value;
    document.getElementById('edit_limit').value = data.usage_limit;
    document.getElementById('edit_target').value = data.target_user;
    document.getElementById('edit_start').value = data.start_date;
    document.getElementById('edit_end').value = data.end_date;
    document.getElementById('edit_desc').value = data.description || '';
    document.getElementById('edit_address').value = data.office_address || '';
    document.getElementById('edit_status').value = data.status;
    
    const preview = document.getElementById('editPreview');
    if (data.image) {
        preview.src = '../uploads/promotions/' + data.image;
        preview.style.display = 'block';
    } else {
        preview.style.display = 'none';
    }
    
    new bootstrap.Modal(document.getElementById('editPromoModal')).show();
}

function confirmDelete(url, name) {
    event.preventDefault();
    Swal.fire({
        title: 'Xóa khuyến mãi?',
        html: `Bạn sắp xóa <strong>${name}</strong>.<br>Thao tác này không thể hoàn tác!`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#64748b',
        confirmButtonText: '<i class="fas fa-trash"></i> Xóa vĩnh viễn',
        cancelButtonText: 'Hủy'
    }).then(result => {
        if (result.isConfirmed) window.location.href = url;
    });
    return false;
}
</script>
</body>
</html>