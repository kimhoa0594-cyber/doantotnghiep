<?php
// file: nhanvien/trang_chu.php
session_start();
require_once '../db.php';

// Xóa dòng echo debug (đã comment)
// echo '<pre>';
// print_r($_SESSION);
// echo '</pre>';

// Kiểm tra đăng nhập và role nhân viên
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'staff') {
    header("Location: ../login.php");
    exit();
}

// Lấy thông tin nhân viên từ database
$ma_nv = $_SESSION['user_id'] ?? 0;
$employee_name = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Nhân viên';
$employee_info = null;

// Lấy thông tin chi tiết nhân viên
$stmt = $conn->prepare("SELECT * FROM nhan_vien WHERE maNV = ? AND trangThai = 1");
$stmt->bind_param("i", $ma_nv);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $employee_info = $result->fetch_assoc();
    $employee_name = $employee_info['tenNV'] ?? $employee_name;
}
$stmt->close();

// Thống kê cho nhân viên
$total_products = 0;
$check_products = $conn->query("SHOW TABLES LIKE 'products'");
if ($check_products && $check_products->num_rows > 0) {
    $total_products = $conn->query("SELECT COUNT(*) as count FROM products")->fetch_assoc()['count'];
}

$total_orders = 0;
$check_orders = $conn->query("SHOW TABLES LIKE 'customer_orders'");
if ($check_orders && $check_orders->num_rows > 0) {
    $total_orders = $conn->query("SELECT COUNT(*) as count FROM customer_orders")->fetch_assoc()['count'];
} else {
    $check_orders2 = $conn->query("SHOW TABLES LIKE 'don_hang'");
    if ($check_orders2 && $check_orders2->num_rows > 0) {
        $total_orders = $conn->query("SELECT COUNT(*) as count FROM don_hang")->fetch_assoc()['count'];
    }
}

$total_customers = 0;
$check_kh_table = $conn->query("SHOW TABLES LIKE 'khach_hang'");
if ($check_kh_table && $check_kh_table->num_rows > 0) {
    $total_customers = $conn->query("SELECT COUNT(*) as c FROM khach_hang WHERE trangThai=1")->fetch_assoc()['c'];
}

// Đơn hàng gần đây - kiểm tra bảng tồn tại
$recent_orders = null;
if ($check_orders && $check_orders->num_rows > 0) {
    $recent_orders = $conn->query("SELECT * FROM customer_orders ORDER BY id DESC LIMIT 5");
} else {
    $check_orders2 = $conn->query("SHOW TABLES LIKE 'don_hang'");
    if ($check_orders2 && $check_orders2->num_rows > 0) {
        $recent_orders = $conn->query("SELECT maDH as id, tenKH as customer_name, soDienThoai as phone, trangThai as status, ngayDat as order_date FROM don_hang d JOIN khach_hang k ON d.maKH = k.maKH ORDER BY d.maDH DESC LIMIT 5");
    }
}

// Tin tức/Bài viết mới nhất
$recent_posts = null;
$check_news_table = $conn->query("SHOW TABLES LIKE 'news'");
if ($check_news_table && $check_news_table->num_rows > 0) {
    $recent_posts = $conn->query("SELECT * FROM news WHERE status = 'published' ORDER BY id DESC LIMIT 4");
}

// Khuyến mãi đang diễn ra
$active_promotions = null;
$check_promotions_table = $conn->query("SHOW TABLES LIKE 'promotions'");
if ($check_promotions_table && $check_promotions_table->num_rows > 0) {
    // Kiểm tra cột tồn tại trong bảng promotions
    $columns = $conn->query("SHOW COLUMNS FROM promotions");
    $col_names = [];
    while ($col = $columns->fetch_assoc()) {
        $col_names[] = $col['Field'];
    }
    
    // Xây dựng câu truy vấn dựa trên cột có sẵn
    $select_fields = "*";
    $title_field = in_array('name', $col_names) ? 'name' : (in_array('title', $col_names) ? 'title' : 'id');
    $desc_field = in_array('description', $col_names) ? 'description' : (in_array('moTa', $col_names) ? 'moTa' : '');
    $end_field = in_array('end_date', $col_names) ? 'end_date' : (in_array('ngayHetHan', $col_names) ? 'ngayHetHan' : 'created_at');
    
    $active_promotions = $conn->query("SELECT *, '$title_field' as promo_title, '$desc_field' as promo_desc FROM promotions WHERE status = 1 AND ($end_field >= CURDATE() OR $end_field IS NULL) ORDER BY id DESC LIMIT 3");
}

$hour = (int)date('H');
$greeting = $hour < 12 ? 'Chào buổi sáng' : ($hour < 18 ? 'Chào buổi chiều' : 'Chào buổi tối');

// Lấy danh sách trạng thái đơn hàng để hiển thị badge đúng
function getStatusBadge($status) {
    $status = trim($status);
    if ($status === 'Đã hoàn thành' || $status === 'Đã giao hàng' || $status === 'Hoàn thành') {
        return '<span class="status-badge status-done"><span class="dot"></span>Đã hoàn thành</span>';
    } elseif ($status === 'Đã hủy' || $status === 'Hủy') {
        return '<span class="status-badge status-cancel"><span class="dot"></span>Đã hủy</span>';
    } elseif ($status === 'Đang giao hàng' || $status === 'Đang giao') {
        return '<span class="status-badge status-pending" style="background:#e0f2fe;color:#0369a1;"><span class="dot" style="background:#0ea5e9;"></span>Đang giao</span>';
    } elseif ($status === 'Chờ xác nhận' || $status === 'Chờ duyệt') {
        return '<span class="status-badge status-pending" style="background:#fef3c7;color:#92400e;"><span class="dot" style="background:#f59e0b;"></span>Chờ xác nhận</span>';
    } else {
        return '<span class="status-badge status-pending"><span class="dot"></span>' . htmlspecialchars($status) . '</span>';
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quang Anh Tech - Nhân viên</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --blue-primary: #2563eb;
            --blue-dark: #1d4ed8;
            --blue-light: #dbeafe;
            --blue-glow: rgba(37,99,235,0.12);
            --sidebar-bg: #0f172a;
            --sidebar-w: 268px;
            --surface: #ffffff;
            --surface-2: #f8fafc;
            --border: #e2e8f0;
            --text-primary: #0f172a;
            --text-muted: #64748b;
            --radius: 14px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
            --shadow-md: 0 4px 16px rgba(0,0,0,.08);
            --shadow-lg: 0 12px 40px rgba(0,0,0,.12);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Be Vietnam Pro', sans-serif;
            background: var(--surface-2);
            color: var(--text-primary);
            overflow-x: hidden;
        }

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
        .brand-logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .brand-icon {
            width: 40px; height: 40px;
            background: linear-gradient(135deg, var(--blue-primary), #3b82f6);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; color: white;
            box-shadow: 0 4px 14px var(--blue-glow);
            flex-shrink: 0;
        }
        .brand-text { line-height: 1.2; }
        .brand-name { font-size: 15px; font-weight: 800; color: #fff; letter-spacing: .3px; }
        .brand-sub  { font-size: 10px; font-weight: 500; color: #64748b; text-transform: uppercase; letter-spacing: 1.2px; }

        .sidebar-nav { flex: 1; padding: 16px 0; overflow-y: auto; }
        .nav-section-label {
            font-size: 9px; font-weight: 700; letter-spacing: 1.4px;
            text-transform: uppercase; color: #334155;
            padding: 16px 20px 6px;
        }
        .nav-item { display: block; position: relative; }
        .nav-link-item {
            display: flex; align-items: center; gap: 12px;
            padding: 10px 20px;
            color: #94a3b8;
            text-decoration: none;
            font-size: 13.5px; font-weight: 500;
            border-radius: 0;
            transition: all 0.2s ease;
            position: relative;
        }
        .nav-link-item::before {
            content: '';
            position: absolute; left: 0; top: 50%; transform: translateY(-50%);
            width: 3px; height: 0;
            background: var(--blue-primary);
            border-radius: 0 3px 3px 0;
            transition: height 0.2s ease;
        }
        .nav-link-item:hover { color: #e2e8f0; background: rgba(255,255,255,.04); }
        .nav-link-item.active { color: #fff; background: rgba(37,99,235,.12); }
        .nav-link-item.active::before { height: 32px; }
        .nav-icon {
            width: 32px; height: 32px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 8px;
            font-size: 13px;
            flex-shrink: 0;
            background: rgba(255,255,255,.05);
        }
        .nav-link-item.active .nav-icon { background: var(--blue-glow); color: #60a5fa; }

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
            transition: all .2s;
        }
        .logout-btn:hover { background: rgba(239,68,68,.1); color: #fca5a5; }

        /* Main content */
        .main-content { margin-left: var(--sidebar-w); min-height: 100vh; }

        /* Topbar */
        .topbar {
            position: sticky; top: 0; z-index: 100;
            background: rgba(248,250,252,.9);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
            padding: 0 32px;
            height: 64px;
            display: flex; align-items: center; justify-content: space-between;
        }
        .topbar-left { display: flex; align-items: center; gap: 12px; }
        .page-title { font-size: 15px; font-weight: 700; color: var(--text-primary); }
        .breadcrumb-sep { color: var(--text-muted); font-size: 13px; }
        .breadcrumb-item { font-size: 13px; color: var(--text-muted); }

        .topbar-right { display: flex; align-items: center; gap: 16px; }
        .topbar-time {
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px; color: var(--text-muted);
            background: var(--surface);
            border: 1px solid var(--border);
            padding: 5px 12px;
            border-radius: 8px;
        }
        .avatar-btn {
            display: flex; align-items: center; gap: 10px;
            padding: 6px 12px 6px 6px;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 40px;
            text-decoration: none;
            color: var(--text-primary);
            transition: all .2s;
        }
        .avatar-btn:hover { box-shadow: var(--shadow-md); color: var(--text-primary); }
        .avatar-img {
            width: 32px; height: 32px;
            border-radius: 50%;
            object-fit: cover;
        }
        .avatar-name { font-size: 13px; font-weight: 600; }

        /* Page body */
        .page-body { padding: 28px 32px 48px; }

        /* Greeting Card */
        .greeting-card {
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 50%, #1e3a8a 100%);
            border-radius: var(--radius);
            padding: 28px 32px;
            margin-bottom: 28px;
            position: relative;
            overflow: hidden;
        }
        .greeting-card::before {
            content: '';
            position: absolute;
            top: -60px; right: -60px;
            width: 220px; height: 220px;
            background: radial-gradient(circle, rgba(37,99,235,.35) 0%, transparent 70%);
            border-radius: 50%;
        }
        .greeting-text { position: relative; z-index: 1; }
        .greeting-sub { font-size: 12px; font-weight: 600; color: #60a5fa; text-transform: uppercase; letter-spacing: 1.2px; margin-bottom: 4px; }
        .greeting-title { font-size: 22px; font-weight: 800; color: #fff; margin-bottom: 4px; }
        .greeting-desc { font-size: 13px; color: #94a3b8; }

        /* Stats grid */
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 28px; }

        .kpi-card {
            background: var(--surface);
            border-radius: var(--radius);
            padding: 20px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
            transition: all .25s ease;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .kpi-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
            color: inherit;
        }
        .kpi-icon {
            width: 42px; height: 42px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px;
            margin-bottom: 14px;
        }
        .kpi-icon.blue  { background: #dbeafe; color: #2563eb; }
        .kpi-icon.green { background: #d1fae5; color: #059669; }
        .kpi-icon.amber { background: #fef3c7; color: #d97706; }

        .kpi-value { font-size: 26px; font-weight: 800; color: var(--text-primary); line-height: 1; }
        .kpi-label { font-size: 12px; font-weight: 500; color: var(--text-muted); margin-top: 4px; }

        /* Section */
        .section-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 16px;
        }
        .section-title { font-size: 14px; font-weight: 700; color: var(--text-primary); }
        .section-link { font-size: 12px; font-weight: 600; color: var(--blue-primary); text-decoration: none; }
        .section-link:hover { text-decoration: underline; }

        /* Quick actions */
        .quick-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 28px; }
        .qa-btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 9px 16px;
            border-radius: 10px;
            font-size: 12.5px; font-weight: 600;
            text-decoration: none;
            transition: all .2s ease;
        }
        .qa-btn-blue   { background: #dbeafe; color: #1d4ed8; border: 1.5px solid #93c5fd; }
        .qa-btn-green  { background: #d1fae5; color: #065f46; border: 1.5px solid #a7f3d0; }
        .qa-btn-amber  { background: #fef3c7; color: #92400e; border: 1.5px solid #fcd34d; }
        .qa-btn-slate  { background: #f1f5f9; color: #475569; border: 1.5px solid #cbd5e1; }
        .qa-btn:hover  { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,.1); }

        /* Data card */
        .data-card {
            background: var(--surface);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 28px;
        }

        /* Tables */
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table thead tr { background: var(--surface-2); }
        .data-table th {
            padding: 10px 16px;
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .7px;
            color: var(--text-muted);
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
        .data-table td {
            padding: 13px 16px;
            font-size: 13px;
            border-bottom: 1px solid #f1f5f9;
        }
        .data-table tbody tr:hover { background: #f8fafc; }

        .order-id {
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px; font-weight: 600;
            background: #f1f5f9;
            padding: 3px 8px;
            border-radius: 6px;
        }
        .status-badge {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 4px 10px; border-radius: 20px;
            font-size: 11px; font-weight: 700;
        }
        .status-badge .dot { width: 5px; height: 5px; border-radius: 50%; }
        .status-done    { background: #d1fae5; color: #065f46; }
        .status-done .dot { background: #059669; }
        .status-cancel  { background: #fee2e2; color: #991b1b; }
        .status-cancel .dot { background: #ef4444; }
        .status-pending { background: #e0f2fe; color: #0369a1; }
        .status-pending .dot { background: #0ea5e9; }

        /* Post cards */
        .posts-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            padding: 16px 24px 24px;
        }
        .post-card {
            background: var(--surface-2);
            border-radius: 12px;
            padding: 16px;
            border: 1px solid var(--border);
            transition: all .2s;
        }
        .post-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        .post-title {
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 8px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .post-excerpt {
            font-size: 12px;
            color: var(--text-muted);
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
            margin-bottom: 12px;
        }
        .post-date {
            font-size: 10px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .post-link {
            font-size: 11px;
            font-weight: 600;
            color: var(--blue-primary);
            text-decoration: none;
        }

        /* Promo cards */
        .promo-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            padding: 16px 24px 24px;
        }
        .promo-card {
            background: linear-gradient(135deg, #fef3c7, #fde68a);
            border-radius: 12px;
            padding: 16px;
            border: none;
        }
        .promo-title {
            font-size: 14px;
            font-weight: 800;
            color: #92400e;
            margin-bottom: 4px;
        }
        .promo-desc {
            font-size: 11px;
            color: #b45309;
            margin-bottom: 8px;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .promo-date {
            font-size: 10px;
            color: #d97706;
            font-weight: 500;
        }

        .empty-state {
            padding: 48px 24px;
            text-align: center;
        }
        .empty-icon { font-size: 36px; color: #cbd5e1; margin-bottom: 12px; }

        .fade-in { opacity: 0; transform: translateY(12px); animation: fadeIn .45s ease forwards; }
        @keyframes fadeIn { to { opacity: 1; transform: translateY(0); } }
        .delay-1 { animation-delay: .05s; }
        .delay-2 { animation-delay: .10s; }
        .delay-3 { animation-delay: .15s; }
        .delay-4 { animation-delay: .20s; }
        .delay-5 { animation-delay: .25s; }
        .delay-6 { animation-delay: .30s; }
        .delay-7 { animation-delay: .35s; }
        .delay-8 { animation-delay: .40s; }

        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }

        @media (max-width: 1200px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .posts-grid { grid-template-columns: repeat(2, 1fr); }
            .promo-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .main-content { margin-left: 0; }
            .page-body { padding: 16px; }
            .posts-grid, .promo-grid { grid-template-columns: 1fr; }
        }
        
        .text-truncate-custom {
            max-width: 200px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
    </style>
</head>
<body>

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
        <div class="nav-item">
            <a href="trang_chu.php" class="nav-link-item active">
                <div class="nav-icon"><i class="fas fa-chart-pie"></i></div>
                Trang chủ
            </a>
        </div>

        <div class="nav-section-label">Quản lý</div>
        <div class="nav-item">
            <a href="quan_ly_khach_hang.php" class="nav-link-item">
                <div class="nav-icon"><i class="fas fa-users"></i></div>
                Khách hàng
                <?php if($total_customers > 0): ?>
                <span class="badge bg-success text-white ms-auto" style="font-size: 10px; padding: 2px 8px;"><?= number_format($total_customers) ?></span>
                <?php endif; ?>
            </a>
        </div>

        <div class="nav-section-label">Nội dung</div>
        <div class="nav-item">
            <a href="quan_ly_bai_viet.php" class="nav-link-item">
                <div class="nav-icon"><i class="fas fa-newspaper"></i></div>
                Quản lý bài viết
            </a>
        </div>
        <div class="nav-item">
            <a href="dang_bai_moi.php" class="nav-link-item">
                <div class="nav-icon"><i class="fas fa-pen-fancy"></i></div>
                Đăng bài mới
            </a>
        </div>

        <div class="nav-section-label">Marketing</div>
        <div class="nav-item">
            <a href="khuyen_mai.php" class="nav-link-item">
                <div class="nav-icon"><i class="fas fa-percentage"></i></div>
                Khuyến mãi
            </a>
        </div>

        <div class="nav-section-label">Cá nhân</div>
        <div class="nav-item">
            <a href="thong_tin_ca_nhan.php" class="nav-link-item">
                <div class="nav-icon"><i class="fas fa-user-circle"></i></div>
                Thông tin cá nhân
            </a>
        </div>
        <div class="nav-item">
            <a href="doi_mat_khau.php" class="nav-link-item">
                <div class="nav-icon"><i class="fas fa-key"></i></div>
                Đổi mật khẩu
            </a>
        </div>
    </nav>

    <div class="sidebar-footer">
        <a href="../logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i>
            Đăng xuất
        </a>
    </div>
</aside>

<main class="main-content">
    <header class="topbar">
        <div class="topbar-left">
            <span class="page-title">Trang chủ</span>
            <span class="breadcrumb-sep">/</span>
            <span class="breadcrumb-item">Tổng quan</span>
        </div>
        <div class="topbar-right">
            <div class="topbar-time" id="live-clock"></div>
            <div class="dropdown">
                <a href="#" class="avatar-btn dropdown-toggle text-decoration-none" data-bs-toggle="dropdown">
                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($employee_name) ?>&background=2563eb&color=fff&bold=true"
                         class="avatar-img" alt="avatar">
                    <span class="avatar-name"><?= htmlspecialchars($employee_name) ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 mt-2">
                    <li><a class="dropdown-item py-2" href="thong_tin_ca_nhan.php"><i class="fas fa-user-circle me-2"></i> Thông tin cá nhân</a></li>
                    <li><a class="dropdown-item py-2" href="doi_mat_khau.php"><i class="fas fa-key me-2"></i> Đổi mật khẩu</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item py-2 text-danger" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Đăng xuất</a></li>
                </ul>
            </div>
        </div>
    </header>

    <div class="page-body">
        <!-- Greeting Banner -->
        <div class="greeting-card fade-in">
            <div class="greeting-text">
                <div class="greeting-sub"><i class="fas fa-briefcase me-1"></i> Khu vực nhân viên</div>
                <div class="greeting-title"><?= $greeting ?>, <?= htmlspecialchars($employee_name) ?> 👋</div>
                <div class="greeting-desc">Hôm nay là <?= date('l, d/m/Y') ?> &mdash; Chào mừng bạn đến với hệ thống quản lý.</div>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="stats-grid">
            <div class="kpi-card fade-in delay-1">
                <div class="kpi-icon blue"><i class="fas fa-box-open"></i></div>
                <div class="kpi-value"><?= number_format($total_products) ?></div>
                <div class="kpi-label">Sản phẩm</div>
            </div>
            <div class="kpi-card fade-in delay-2">
                <div class="kpi-icon green"><i class="fas fa-shopping-cart"></i></div>
                <div class="kpi-value"><?= number_format($total_orders) ?></div>
                <div class="kpi-label">Đơn hàng</div>
            </div>
            <div class="kpi-card fade-in delay-3">
                <div class="kpi-icon amber"><i class="fas fa-users"></i></div>
                <div class="kpi-value"><?= number_format($total_customers) ?></div>
                <div class="kpi-label">Khách hàng</div>
            </div>
            <div class="kpi-card fade-in delay-4">
                <div class="kpi-icon blue"><i class="fas fa-tags"></i></div>
                <div class="kpi-value"><?= number_format($active_promotions ? $active_promotions->num_rows : 0) ?></div>
                <div class="kpi-label">Khuyến mãi đang diễn ra</div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="section-header fade-in delay-5">
            <div class="section-title"><i class="fas fa-bolt me-2 text-primary"></i>Truy cập nhanh</div>
        </div>
        <div class="quick-actions fade-in delay-5">
            <a href="quan_ly_khach_hang.php" class="qa-btn qa-btn-blue">
                <i class="fas fa-users"></i> Quản lý khách hàng
            </a>
            <a href="dang_bai_moi.php" class="qa-btn qa-btn-green">
                <i class="fas fa-pen-fancy"></i> Đăng bài mới
            </a>
            <a href="quan_ly_bai_viet.php" class="qa-btn qa-btn-amber">
                <i class="fas fa-newspaper"></i> Quản lý bài viết
            </a>
            <a href="khuyen_mai.php" class="qa-btn qa-btn-slate">
                <i class="fas fa-percentage"></i> Quản lý khuyến mãi
            </a>
        </div>

        <!-- Recent Orders -->
        <div class="section-header fade-in delay-6">
            <div class="section-title"><i class="fas fa-clock me-2 text-primary"></i> Đơn hàng gần đây</div>
            <a href="#" class="section-link">Xem tất cả &rarr;</a>
        </div>
        <div class="data-card fade-in delay-6">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Mã đơn</th>
                        <th>Khách hàng</th>
                        <th>Số điện thoại</th>
                        <th>Trạng thái</th>
                        <th>Ngày đặt</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($recent_orders && $recent_orders->num_rows > 0): ?>
                        <?php while($row = $recent_orders->fetch_assoc()): ?>
                        <tr>
                            <td><span class="order-id">#<?= $row['id'] ?></span></td>
                            <td><span class="fw-medium"><?= htmlspecialchars($row['customer_name'] ?? $row['tenKH'] ?? 'N/A') ?></span></td>
                            <td><?= htmlspecialchars($row['phone'] ?? $row['soDienThoai'] ?? '—') ?></td>
                            <td><?= getStatusBadge($row['status'] ?? $row['trangThai'] ?? 'Chờ xác nhận') ?></td>
                            <td><?= date('d/m/Y', strtotime($row['order_date'] ?? $row['ngayDat'] ?? 'now')) ?></td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5">
                                <div class="empty-state">
                                    <div class="empty-icon"><i class="fas fa-inbox"></i></div>
                                    <div>Chưa có đơn hàng nào</div>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Recent Posts -->
        <div class="section-header fade-in delay-7">
            <div class="section-title"><i class="fas fa-newspaper me-2 text-primary"></i> Bài viết mới nhất</div>
            <a href="quan_ly_bai_viet.php" class="section-link">Quản lý bài viết &rarr;</a>
        </div>
        <div class="data-card fade-in delay-7">
            <?php if($recent_posts && $recent_posts->num_rows > 0): ?>
            <div class="posts-grid">
                <?php while($post = $recent_posts->fetch_assoc()): ?>
                <div class="post-card">
                    <div class="post-title"><?= htmlspecialchars($post['title'] ?? 'Bài viết') ?></div>
                    <div class="post-excerpt"><?= htmlspecialchars(substr(strip_tags($post['content'] ?? ''), 0, 80)) ?>...</div>
                    <div class="post-date">
                        <i class="far fa-calendar-alt"></i> <?= date('d/m/Y', strtotime($post['created_at'] ?? 'now')) ?>
                        <a href="sua_bai_viet.php?id=<?= $post['id'] ?>" class="post-link ms-auto">Sửa</a>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-edit"></i></div>
                <div>Chưa có bài viết nào. <a href="dang_bai_moi.php">Đăng bài ngay</a></div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Active Promotions -->
        <div class="section-header fade-in delay-8">
            <div class="section-title"><i class="fas fa-percentage me-2 text-primary"></i> Khuyến mãi đang diễn ra</div>
            <a href="khuyen_mai.php" class="section-link">Quản lý khuyến mãi &rarr;</a>
        </div>
        <div class="data-card fade-in delay-8">
            <?php if($active_promotions && $active_promotions->num_rows > 0): ?>
            <div class="promo-grid">
                <?php while($promo = $active_promotions->fetch_assoc()): 
                    // Lấy title từ cột name hoặc title
                    $promo_title = $promo['name'] ?? $promo['title'] ?? 'Khuyến mãi';
                    $promo_desc = $promo['description'] ?? $promo['moTa'] ?? '';
                    $promo_end = $promo['end_date'] ?? $promo['ngayHetHan'] ?? null;
                ?>
                <div class="promo-card">
                    <div class="promo-title"><i class="fas fa-gift me-1"></i> <?= htmlspecialchars($promo_title) ?></div>
                    <div class="promo-desc"><?= htmlspecialchars(substr($promo_desc, 0, 60)) ?></div>
                    <div class="promo-date">
                        <?php if($promo_end): ?>
                        <i class="far fa-clock"></i> Hết hạn: <?= date('d/m/Y', strtotime($promo_end)) ?>
                        <?php else: ?>
                        <i class="fas fa-infinity"></i> Không giới hạn
                        <?php endif; ?>
                    </div>
                </div>
                <?php endwhile; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fas fa-tags"></i></div>
                <div>Hiện không có chương trình khuyến mãi nào. <a href="khuyen_mai.php">Thêm khuyến mãi</a></div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function updateClock() {
    const now = new Date();
    const pad = n => String(n).padStart(2, '0');
    const days = ['CN','T2','T3','T4','T5','T6','T7'];
    document.getElementById('live-clock').textContent = days[now.getDay()] + ' ' + pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());
}
updateClock();
setInterval(updateClock, 1000);
</script>
</body>
</html>