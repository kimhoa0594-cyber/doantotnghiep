<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

$total_users    = $conn->query("SELECT COUNT(*) as count FROM users")->fetch_assoc()['count'];
$total_orders   = $conn->query("SELECT COUNT(*) as count FROM customer_orders")->fetch_assoc()['count'];
$total_products = $conn->query("SELECT COUNT(*) as count FROM products")->fetch_assoc()['count'];
$recent_orders  = $conn->query("SELECT * FROM customer_orders ORDER BY id DESC LIMIT 5");

$total_nv = 0; $nv_chua_cap_nhat = 0;
$check_nv_table = $conn->query("SHOW TABLES LIKE 'nhan_vien'");
if ($check_nv_table && $check_nv_table->num_rows > 0) {
    $total_nv = $conn->query("SELECT COUNT(*) as c FROM nhan_vien WHERE trangThai=1")->fetch_assoc()['c'];
    $nv_chua_cap_nhat = $conn->query("SELECT COUNT(*) as c FROM nhan_vien WHERE trangThai=1
        AND (soDienThoai IS NULL OR soDienThoai='' OR ngaySinh IS NULL OR diaChi IS NULL OR diaChi='' OR email IS NULL OR email='')")->fetch_assoc()['c'];
}

$total_ktv = 0; $ktv_chua_cap_nhat = 0;
$check_ktv_table = $conn->query("SHOW TABLES LIKE 'ky_thuat_vien'");
if ($check_ktv_table && $check_ktv_table->num_rows > 0) {
    $total_ktv = $conn->query("SELECT COUNT(*) as c FROM ky_thuat_vien WHERE trangThai=1")->fetch_assoc()['c'];
    $ktv_chua_cap_nhat = $conn->query("SELECT COUNT(*) as c FROM ky_thuat_vien WHERE trangThai=1
        AND (tenKTV IS NULL OR tenKTV='' OR ngaySinh IS NULL OR soDienThoai IS NULL OR soDienThoai='' OR diaChi IS NULL OR diaChi='' OR email IS NULL OR email='')")->fetch_assoc()['c'];
}

$total_kh = 0;
$check_kh_table = $conn->query("SHOW TABLES LIKE 'khach_hang'");
if ($check_kh_table && $check_kh_table->num_rows > 0) {
    $total_kh = $conn->query("SELECT COUNT(*) as c FROM khach_hang WHERE trangThai=1")->fetch_assoc()['c'];
}

$admin_name = $_SESSION['AD'] ?? 'Admin';
$hour = (int)date('H');
$greeting = $hour < 12 ? 'Chào buổi sáng' : ($hour < 18 ? 'Chào buổi chiều' : 'Chào buổi tối');
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quang Anh Tech Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">

    <style>
        :root {
            --green-primary: #059669;
            --green-dark:    #047857;
            --green-light:   #d1fae5;
            --green-glow:    rgba(5,150,105,0.18);
            --sidebar-bg:    #0d1117;
            --sidebar-w:     268px;
            --surface:       #ffffff;
            --surface-2:     #f8fafc;
            --border:        #e2e8f0;
            --text-primary:  #0f172a;
            --text-muted:    #64748b;
            --radius:        14px;
            --shadow-sm:     0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
            --shadow-md:     0 4px 16px rgba(0,0,0,.08);
            --shadow-lg:     0 12px 40px rgba(0,0,0,.12);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Be Vietnam Pro', sans-serif;
            background: var(--surface-2);
            color: var(--text-primary);
            overflow-x: hidden;
        }

        /* ── SIDEBAR ── */
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
            background: linear-gradient(135deg, var(--green-primary), #10b981);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 18px; color: white;
            box-shadow: 0 4px 14px var(--green-glow);
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
            background: var(--green-primary);
            border-radius: 0 3px 3px 0;
            transition: height 0.2s ease;
        }
        .nav-link-item:hover { color: #e2e8f0; background: rgba(255,255,255,.04); }
        .nav-link-item.active { color: #fff; background: rgba(5,150,105,.12); }
        .nav-link-item.active::before { height: 32px; }
        .nav-icon {
            width: 32px; height: 32px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 8px;
            font-size: 13px;
            flex-shrink: 0;
            background: rgba(255,255,255,.05);
        }
        .nav-link-item.active .nav-icon { background: var(--green-glow); color: #34d399; }
        .nav-badge {
            margin-left: auto;
            padding: 2px 7px;
            border-radius: 20px;
            font-size: 10px; font-weight: 700;
        }
        .sidebar-divider { border-color: rgba(255,255,255,.07); margin: 8px 16px; }

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

        /* ── MAIN ── */
        .main-content { margin-left: var(--sidebar-w); min-height: 100vh; }

        /* ── TOPBAR ── */
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

        /* ── PAGE BODY ── */
        .page-body { padding: 28px 32px 48px; }

        /* ── GREETING ── */
        .greeting-card {
            background: linear-gradient(135deg, #0d1117 0%, #0f2918 50%, #052e16 100%);
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
            background: radial-gradient(circle, rgba(5,150,105,.35) 0%, transparent 70%);
            border-radius: 50%;
        }
        .greeting-card::after {
            content: '';
            position: absolute;
            bottom: -40px; left: 20%;
            width: 160px; height: 160px;
            background: radial-gradient(circle, rgba(16,185,129,.15) 0%, transparent 70%);
            border-radius: 50%;
        }
        .greeting-text { position: relative; z-index: 1; }
        .greeting-sub { font-size: 12px; font-weight: 600; color: #34d399; text-transform: uppercase; letter-spacing: 1.2px; margin-bottom: 4px; }
        .greeting-title { font-size: 22px; font-weight: 800; color: #fff; margin-bottom: 4px; }
        .greeting-desc { font-size: 13px; color: #94a3b8; }
        .greeting-stats { position: relative; z-index: 1; display: flex; gap: 20px; margin-top: 20px; }
        .greeting-stat {
            background: rgba(255,255,255,.06);
            border: 1px solid rgba(255,255,255,.08);
            border-radius: 10px;
            padding: 10px 16px;
            text-align: center;
        }
        .greeting-stat-val { font-size: 20px; font-weight: 800; color: #fff; }
        .greeting-stat-lbl { font-size: 10px; color: #64748b; text-transform: uppercase; letter-spacing: .8px; margin-top: 2px; }

        /* ── STAT GRID ── */
        .stats-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 16px; margin-bottom: 28px; }

        .kpi-card {
            background: var(--surface);
            border-radius: var(--radius);
            padding: 20px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
            transition: all .25s ease;
            position: relative;
            overflow: hidden;
            text-decoration: none;
            color: inherit;
            display: block;
        }
        .kpi-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
            border-color: transparent;
            color: inherit;
        }
        .kpi-card::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            border-radius: var(--radius) var(--radius) 0 0;
        }
        .kpi-card.c-green::after  { background: linear-gradient(90deg, #059669, #34d399); }
        .kpi-card.c-blue::after   { background: linear-gradient(90deg, #3b82f6, #60a5fa); }
        .kpi-card.c-amber::after  { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
        .kpi-card.c-red::after    { background: linear-gradient(90deg, #ef4444, #f87171); }
        .kpi-card.c-violet::after { background: linear-gradient(90deg, #8b5cf6, #a78bfa); }
        .kpi-card.c-teal::after   { background: linear-gradient(90deg, #14b8a6, #5eead4); }

        .kpi-icon {
            width: 42px; height: 42px;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px;
            margin-bottom: 14px;
        }
        .kpi-icon.c-green  { background: #d1fae5; color: #059669; }
        .kpi-icon.c-blue   { background: #dbeafe; color: #3b82f6; }
        .kpi-icon.c-amber  { background: #fef3c7; color: #d97706; }
        .kpi-icon.c-red    { background: #fee2e2; color: #ef4444; }
        .kpi-icon.c-violet { background: #ede9fe; color: #7c3aed; }
        .kpi-icon.c-teal   { background: #ccfbf1; color: #0d9488; }

        .kpi-value { font-size: 26px; font-weight: 800; color: var(--text-primary); line-height: 1; }
        .kpi-label { font-size: 12px; font-weight: 500; color: var(--text-muted); margin-top: 4px; }
        .kpi-alert { font-size: 11px; font-weight: 600; color: #ef4444; margin-top: 6px; display: flex; align-items: center; gap: 4px; }

        /* alert pulse dot */
        .pulse-dot {
            width: 7px; height: 7px; border-radius: 50%;
            background: #ef4444;
            animation: pulse 1.8s infinite;
            flex-shrink: 0;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: .5; transform: scale(1.3); }
        }

        /* ── QUICK ACTIONS ── */
        .section-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 16px;
        }
        .section-title { font-size: 14px; font-weight: 700; color: var(--text-primary); }
        .section-link  { font-size: 12px; font-weight: 600; color: var(--green-primary); text-decoration: none; }
        .section-link:hover { text-decoration: underline; }

        .quick-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 28px; }
        .qa-btn {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 9px 16px;
            border-radius: 10px;
            font-size: 12.5px; font-weight: 600;
            text-decoration: none;
            border: 1.5px solid transparent;
            transition: all .2s ease;
        }
        .qa-btn-green  { background: var(--green-light); color: var(--green-dark); border-color: #a7f3d0; }
        .qa-btn-blue   { background: #dbeafe; color: #1d4ed8; border-color: #93c5fd; }
        .qa-btn-amber  { background: #fef3c7; color: #92400e; border-color: #fcd34d; }
        .qa-btn-red    { background: #fee2e2; color: #991b1b; border-color: #fca5a5; }
        .qa-btn-slate  { background: #f1f5f9; color: #475569; border-color: #cbd5e1; }
        .qa-btn:hover  { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,.1); }

        /* ── TABLE CARD ── */
        .data-card {
            background: var(--surface);
            border-radius: var(--radius);
            border: 1px solid var(--border);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }
        .data-card-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border);
            display: flex; align-items: center; justify-content: space-between;
        }
        .data-card-title {
            font-size: 14px; font-weight: 700; color: var(--text-primary);
            display: flex; align-items: center; gap: 8px;
        }
        .live-dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: #22c55e;
            animation: pulse 2s infinite;
        }

        /* Table */
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table thead tr {
            background: var(--surface-2);
        }
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
            vertical-align: middle;
        }
        .data-table tbody tr:last-child td { border-bottom: none; }
        .data-table tbody tr:hover { background: #f8fafc; }

        .order-id {
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px; font-weight: 600;
            color: var(--text-muted);
            background: var(--surface-2);
            padding: 3px 8px; border-radius: 6px;
            border: 1px solid var(--border);
        }
        .customer-name { font-weight: 600; font-size: 13px; }
        .phone-text { font-family: 'JetBrains Mono', monospace; font-size: 12px; color: var(--text-muted); }

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

        .date-text { font-size: 12px; color: var(--text-muted); }
        .action-btn {
            width: 32px; height: 32px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: transparent;
            color: var(--text-muted);
            cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center;
            transition: all .2s;
            font-size: 12px;
        }
        .action-btn:hover { background: var(--green-light); color: var(--green-dark); border-color: #a7f3d0; }

        .empty-state {
            padding: 48px 24px;
            text-align: center;
        }
        .empty-icon { font-size: 36px; color: #cbd5e1; margin-bottom: 12px; }
        .empty-text { font-size: 13px; color: var(--text-muted); }

        /* ── FADE IN ANIMATION ── */
        .fade-in {
            opacity: 0;
            transform: translateY(12px);
            animation: fadeIn .45s ease forwards;
        }
        @keyframes fadeIn {
            to { opacity: 1; transform: translateY(0); }
        }
        .delay-1 { animation-delay: .05s; }
        .delay-2 { animation-delay: .10s; }
        .delay-3 { animation-delay: .15s; }
        .delay-4 { animation-delay: .20s; }
        .delay-5 { animation-delay: .25s; }
        .delay-6 { animation-delay: .30s; }
        .delay-7 { animation-delay: .35s; }
        .delay-8 { animation-delay: .40s; }

        /* ── SCROLLBAR ── */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        /* ── RESPONSIVE ── */
        @media (max-width: 1200px) { .stats-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .main-content { margin-left: 0; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .page-body { padding: 16px; }
        }
    </style>
</head>
<body>

<!-- ══════════════ SIDEBAR ══════════════ -->
<aside class="sidebar">
    <div class="sidebar-brand">
        <div class="brand-logo">
            <div class="brand-icon"><i class="fas fa-bolt"></i></div>
            <div class="brand-text">
                <div class="brand-name">Quang Anh Tech</div>
                <div class="brand-sub">Admin</div>
            </div>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section-label">Tổng quan</div>
        <div class="nav-item">
            <a href="index.php" class="nav-link-item active">
                <div class="nav-icon"><i class="fas fa-chart-pie"></i></div>
                Trang chủ
            </a>
        </div>

        <div class="nav-section-label">Quản lý</div>
        <div class="nav-item">
            <a href="users.php" class="nav-link-item">
                <div class="nav-icon"><i class="fas fa-user-shield"></i></div>
                Phân quyền
            </a>
        </div>
        <div class="nav-item">
            <a href="quan_ly_khach_hang.php" class="nav-link-item">
                <div class="nav-icon"><i class="fas fa-users"></i></div>
                Khách hàng
                <?php if($total_kh > 0): ?>
                <span class="nav-badge bg-success text-white ms-auto"><?= number_format($total_kh) ?></span>
                <?php endif; ?>
            </a>
        </div>
        <div class="nav-item">
            <a href="quan_ly_nhan_vien.php" class="nav-link-item">
                <div class="nav-icon"><i class="fas fa-id-badge"></i></div>
                Nhân viên
                <?php if($nv_chua_cap_nhat > 0): ?>
                <span class="nav-badge bg-warning text-dark ms-auto"><?= $nv_chua_cap_nhat ?></span>
                <?php endif; ?>
            </a>
        </div>
        <div class="nav-item">
            <a href="quan_ly_ky_thuat_vien.php" class="nav-link-item">
                <div class="nav-icon"><i class="fas fa-tools"></i></div>
                Kỹ thuật viên
                <?php if($ktv_chua_cap_nhat > 0): ?>
                <span class="nav-badge bg-danger text-white ms-auto"><?= $ktv_chua_cap_nhat ?></span>
                <?php endif; ?>
            </a>
        </div>

        <div class="nav-section-label">Marketing</div>
        <div class="nav-item">
            <a href="promotions.php" class="nav-link-item">
                <div class="nav-icon"><i class="fas fa-percentage"></i></div>
                Khuyến mãi
            </a>
        </div>
        <div class="nav-item">
            <a href="advertising.php" class="nav-link-item">
                <div class="nav-icon"><i class="fas fa-bullhorn"></i></div>
                Quảng bá
            </a>
        </div>

        <div class="nav-section-label">Hệ thống</div>
        <div class="nav-item">
            <a href="system_logs.php" class="nav-link-item">
                <div class="nav-icon"><i class="fas fa-clipboard-list"></i></div>
                Nhật ký hệ thống
            </a>
        </div>

        <hr class="sidebar-divider">
    </nav>

    <div class="sidebar-footer">
        <a href="../logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i>
            Đăng xuất
        </a>
    </div>
</aside>

<!-- ══════════════ MAIN ══════════════ -->
<main class="main-content">

    <!-- TOPBAR -->
    <header class="topbar">
        <div class="topbar-left">
            <span class="page-title">Trang chủ</span>
            <span class="breadcrumb-sep">/</span>
            <span class="breadcrumb-item">Tổng quan</span>
        </div>
        <div class="topbar-right">
            <div class="topbar-time" id="live-clock"></div>
            <div class="dropdown">
                <a href="#" class="avatar-btn dropdown-toggle text-decoration-none" data-bs-toggle="dropdown" aria-expanded="false">
                    <img src="https://ui-avatars.com/api/?name=<?= urlencode($admin_name) ?>&background=059669&color=fff&bold=true"
                         class="avatar-img" alt="avatar">
                    <span class="avatar-name"><?= htmlspecialchars($admin_name) ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 mt-2" style="min-width:180px;border-radius:12px;">
                    <li><a class="dropdown-item py-2" href="#"><i class="fas fa-user-circle me-2 text-muted"></i> Hồ sơ</a></li>
                    <li><hr class="dropdown-divider my-1"></li>
                    <li><a class="dropdown-item py-2 text-danger" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Đăng xuất</a></li>
                </ul>
            </div>
        </div>
    </header>

    <!-- PAGE BODY -->
    <div class="page-body">

        <!-- GREETING BANNER -->
        <div class="greeting-card fade-in">
            <div class="greeting-text">
                <div class="greeting-sub"><i class="fas fa-circle-check me-1"></i>Hệ thống hoạt động bình thường</div>
                <div class="greeting-title"><?= $greeting ?>, <?= htmlspecialchars($admin_name) ?> 👋</div>
                <div class="greeting-desc">Hôm nay là <?= date('l, d/m/Y') ?> &mdash; Đây là tổng quan hoạt động của hệ thống.</div>
            </div>
            <div class="greeting-stats">
                <div class="greeting-stat">
                    <div class="greeting-stat-val"><?= number_format($total_orders) ?></div>
                    <div class="greeting-stat-lbl">Đơn hàng</div>
                </div>
                <div class="greeting-stat">
                    <div class="greeting-stat-val"><?= number_format($total_kh) ?></div>
                    <div class="greeting-stat-lbl">Khách hàng</div>
                </div>
                <div class="greeting-stat">
                    <div class="greeting-stat-val"><?= number_format($total_products) ?></div>
                    <div class="greeting-stat-lbl">Sản phẩm</div>
                </div>
                <div class="greeting-stat">
                    <div class="greeting-stat-val"><?= number_format($total_users) ?></div>
                    <div class="greeting-stat-lbl">Tài khoản</div>
                </div>
            </div>
        </div>

        <!-- KPI CARDS -->
        <div class="stats-grid">
            <div class="kpi-card c-green fade-in delay-1">
                <div class="kpi-icon c-green"><i class="fas fa-users"></i></div>
                <div class="kpi-value"><?= number_format($total_users) ?></div>
                <div class="kpi-label">Người dùng hệ thống</div>
            </div>

            <div class="kpi-card c-blue fade-in delay-2">
                <div class="kpi-icon c-blue"><i class="fas fa-box-open"></i></div>
                <div class="kpi-value"><?= number_format($total_products) ?></div>
                <div class="kpi-label">Sản phẩm hiện có</div>
            </div>

            <div class="kpi-card c-amber fade-in delay-3">
                <div class="kpi-icon c-amber"><i class="fas fa-shopping-cart"></i></div>
                <div class="kpi-value"><?= number_format($total_orders) ?></div>
                <div class="kpi-label">Tổng số đơn hàng</div>
            </div>

            <a href="quan_ly_ky_thuat_vien.php" class="kpi-card c-red fade-in delay-4">
                <div class="kpi-icon c-red"><i class="fas fa-tools"></i></div>
                <div class="kpi-value"><?= number_format($total_ktv) ?></div>
                <div class="kpi-label">Kỹ thuật viên</div>
                <?php if($ktv_chua_cap_nhat > 0): ?>
                <div class="kpi-alert">
                    <span class="pulse-dot"></span>
                    <?= $ktv_chua_cap_nhat ?> chưa cập nhật hồ sơ
                </div>
                <?php endif; ?>
            </a>

            <a href="quan_ly_nhan_vien.php" class="kpi-card c-violet fade-in delay-5">
                <div class="kpi-icon c-violet"><i class="fas fa-id-badge"></i></div>
                <div class="kpi-value"><?= number_format($total_nv) ?></div>
                <div class="kpi-label">Nhân viên</div>
                <?php if($nv_chua_cap_nhat > 0): ?>
                <div class="kpi-alert">
                    <span class="pulse-dot"></span>
                    <?= $nv_chua_cap_nhat ?> thiếu thông tin
                </div>
                <?php endif; ?>
            </a>

            <a href="quan_ly_khach_hang.php" class="kpi-card c-teal fade-in delay-6">
                <div class="kpi-icon c-teal"><i class="fas fa-user-check"></i></div>
                <div class="kpi-value"><?= number_format($total_kh) ?></div>
                <div class="kpi-label">Khách hàng</div>
            </a>
        </div>

        <!-- QUICK ACTIONS -->
        <div class="section-header fade-in delay-7">
            <div class="section-title"><i class="fas fa-bolt me-2 text-warning"></i>Truy cập nhanh</div>
        </div>
        <div class="quick-actions fade-in delay-7">
            <a href="users.php" class="qa-btn qa-btn-slate">
                <i class="fas fa-user-shield"></i> Phân quyền
            </a>
            <a href="quan_ly_nhan_vien.php" class="qa-btn qa-btn-blue">
                <i class="fas fa-id-badge"></i> Nhân viên
                <?php if($nv_chua_cap_nhat > 0): ?>
                <span class="badge bg-warning text-dark" style="font-size:10px;"><?= $nv_chua_cap_nhat ?></span>
                <?php endif; ?>
            </a>
            <a href="quan_ly_ky_thuat_vien.php" class="qa-btn qa-btn-amber">
                <i class="fas fa-tools"></i> Kỹ thuật viên
                <?php if($ktv_chua_cap_nhat > 0): ?>
                <span class="badge bg-danger" style="font-size:10px;"><?= $ktv_chua_cap_nhat ?></span>
                <?php endif; ?>
            </a>
            <a href="quan_ly_khach_hang.php" class="qa-btn qa-btn-green">
                <i class="fas fa-users"></i> Khách hàng
            </a>
            <a href="promotions.php" class="qa-btn qa-btn-green">
                <i class="fas fa-percentage"></i> Khuyến mãi
            </a>
            <a href="system_logs.php" class="qa-btn qa-btn-slate">
                <i class="fas fa-clipboard-list"></i> Nhật ký hệ thống
            </a>
        </div>

        <!-- RECENT ORDERS TABLE -->
        <div class="section-header fade-in delay-8">
            <div class="section-title">
                <span class="live-dot"></span>
                Đơn hàng mới nhất
            </div>
            <a href="customers.php" class="section-link">Xem tất cả &rarr;</a>
        </div>

        <div class="data-card fade-in delay-8">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Mã đơn</th>
                        <th>Khách hàng</th>
                        <th>Số điện thoại</th>
                        <th>Trạng thái</th>
                        <th>Ngày đặt</th>
                        <th style="text-align:center;">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($recent_orders && $recent_orders->num_rows > 0): ?>
                        <?php while($row = $recent_orders->fetch_assoc()): ?>
                        <tr>
                            <td><span class="order-id">#QA-<?= $row['id'] ?></span></td>
                            <td><span class="customer-name"><?= htmlspecialchars($row['customer_name']) ?></span></td>
                            <td><span class="phone-text"><?= htmlspecialchars($row['phone']) ?></span></td>
                            <td>
                                <?php
                                    $status = $row['status'];
                                    if ($status === 'Đã hoàn thành') {
                                        echo "<span class='status-badge status-done'><span class='dot'></span>$status</span>";
                                    } elseif ($status === 'Đã hủy') {
                                        echo "<span class='status-badge status-cancel'><span class='dot'></span>$status</span>";
                                    } else {
                                        echo "<span class='status-badge status-pending'><span class='dot'></span>$status</span>";
                                    }
                                ?>
                            </td>
                            <td><span class="date-text"><?= date('d/m/Y', strtotime($row['order_date'])) ?></span></td>
                            <td style="text-align:center;">
                                <button class="action-btn" title="Xem chi tiết"><i class="fas fa-eye"></i></button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">
                                    <div class="empty-icon"><i class="fas fa-inbox"></i></div>
                                    <div class="empty-text">Chưa có đơn hàng nào</div>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div><!-- /page-body -->
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Live clock
function updateClock() {
    const now = new Date();
    const pad = n => String(n).padStart(2, '0');
    const days = ['CN','T2','T3','T4','T5','T6','T7'];
    document.getElementById('live-clock').textContent =
        days[now.getDay()] + ' ' + pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());
}
updateClock();
setInterval(updateClock, 1000);
</script>
</body>
</html>