<?php
// kythuatvien/bao_hanh.php
session_start();
require_once __DIR__ . '/../db.php';
date_default_timezone_set('Asia/Ho_Chi_Minh');

// Kiểm tra đăng nhập
if (!isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    header("Location: ../login.php");
    exit();
}

$userRole = $_SESSION['role'];
$username = $_SESSION['username'];

// Admin có toàn quyền, KTV chỉ xem
$isAdmin = ($userRole === 'admin');
$isKTV = ($userRole === 'technician' || $userRole === 'kythuatvien');

if (!$isAdmin && !$isKTV) {
    header("Location: ../index.php");
    exit();
}

// Xử lý GET: Xóa bảo hành (chỉ admin)
if ($isAdmin && isset($_GET['xoa_bh'])) {
    $maBaoHanh = (int)$_GET['xoa_bh'];
    $conn->query("DELETE FROM bao_hanh WHERE maBaoHanh = $maBaoHanh");
    header("Location: bao_hanh.php");
    exit();
}

// Xử lý POST: Cập nhật bảo hành (chỉ admin)
if ($isAdmin && isset($_POST['sua_bao_hanh'])) {
    $maBaoHanh   = (int)$_POST['maBaoHanh'];
    $tenSP       = $conn->real_escape_string($_POST['tenSP']);
    $ngayBD      = $conn->real_escape_string($_POST['ngayBatDau']);
    $ngayKT      = $conn->real_escape_string($_POST['ngayKetThuc']);
    $thoiHan     = $conn->real_escape_string($_POST['thoiHan'] ?? '');
    $dieuKien    = $conn->real_escape_string($_POST['dieuKienBaoHanh'] ?? '');
    $trangThai   = $conn->real_escape_string($_POST['trangThai']);
    $conn->query("UPDATE bao_hanh SET tenSP='$tenSP', ngayBatDau='$ngayBD', ngayKetThuc='$ngayKT', thoiHan='$thoiHan', dieuKienBaoHanh='$dieuKien', trangThai='$trangThai' WHERE maBaoHanh=$maBaoHanh");
    header("Location: bao_hanh.php");
    exit();
}

// Xử lý POST: Thêm bảo hành mới (chỉ admin)
if ($isAdmin && isset($_POST['them_bao_hanh'])) {
    $maKH        = (int)$_POST['maKH'];
    $tenSP       = $conn->real_escape_string($_POST['tenSP']);
    $ngayBD      = $conn->real_escape_string($_POST['ngayBatDau']);
    $ngayKT      = $conn->real_escape_string($_POST['ngayKetThuc']);
    $thoiHan     = $conn->real_escape_string($_POST['thoiHan'] ?? '');
    $dieuKien    = $conn->real_escape_string($_POST['dieuKienBaoHanh'] ?? '');
    $trangThai   = $conn->real_escape_string($_POST['trangThai']);
    $conn->query("INSERT INTO bao_hanh (maKH, tenSP, ngayBatDau, ngayKetThuc, thoiHan, dieuKienBaoHanh, trangThai)
                  VALUES ($maKH, '$tenSP', '$ngayBD', '$ngayKT', '$thoiHan', '$dieuKien', '$trangThai')");
    header("Location: bao_hanh.php");
    exit();
}

// Đảm bảo bảng tồn tại
$conn->query("CREATE TABLE IF NOT EXISTS `bao_hanh` (
    `maBaoHanh` int(11) NOT NULL AUTO_INCREMENT,
    `maKH` int(11) NOT NULL,
    `tenSP` varchar(200) NOT NULL,
    `ngayBatDau` date NOT NULL,
    `ngayKetThuc` date NOT NULL,
    `thoiHan` varchar(50) DEFAULT NULL,
    `dieuKienBaoHanh` text DEFAULT NULL,
    `trangThai` varchar(50) DEFAULT 'Còn bảo hành',
    PRIMARY KEY (`maBaoHanh`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Lấy danh sách bảo hành kèm thông tin khách hàng - SẮP XẾP THEO TÊN KHÁCH HÀNG A-Z
$sql = "SELECT bh.*, kh.tenKH, kh.soDienThoai, kh.email, kh.diaChi, kh.loaiKhachHang
        FROM bao_hanh bh
        LEFT JOIN khach_hang kh ON bh.maKH = kh.maKH
        ORDER BY kh.tenKH ASC, 
                 CASE WHEN bh.trangThai = 'Còn bảo hành' AND bh.ngayKetThuc >= CURDATE() THEN 0 ELSE 1 END,
                 bh.ngayKetThuc ASC";
$baoHanhList = $conn->query($sql);

// Lấy danh sách khách hàng cho admin
$khachHangList = [];
if ($isAdmin) {
    $khRes = $conn->query("SELECT maKH, tenKH, soDienThoai FROM khach_hang ORDER BY tenKH ASC");
    while ($kh = $khRes->fetch_assoc()) $khachHangList[] = $kh;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý bảo hành - QA Tech</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.7.12/sweetalert2.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Be Vietnam Pro', sans-serif;
            background: #f1f5f9;
            color: #0f172a;
            font-size: 14px;
        }
        .sidebar {
            position: fixed; top: 0; left: 0; bottom: 0;
            width: 260px;
            background: #0f172a;
            display: flex; flex-direction: column;
            z-index: 200;
            overflow-y: auto;
        }
        .sidebar-brand { padding: 22px 20px 18px; border-bottom: 1px solid rgba(255,255,255,.07); }
        .brand-logo { display: flex; align-items: center; gap: 12px; text-decoration: none; }
        .brand-icon { width: 40px; height: 40px; background: #10b981; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; color: #fff; }
        .brand-name { font-size: 14px; font-weight: 800; color: #fff; }
        .brand-sub { font-size: 10px; color: #64748b; text-transform: uppercase; }
        .sidebar-user { padding: 14px 18px; border-bottom: 1px solid rgba(255,255,255,.07); display: flex; align-items: center; gap: 10px; }
        .user-avatar { width: 34px; height: 34px; border-radius: 50%; background: #10b981; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: 700; font-size: 14px; }
        .user-name { font-size: 12.5px; font-weight: 700; color: #e2e8f0; }
        .user-role { font-size: 10px; color: #64748b; }
        .sidebar-nav { flex: 1; padding: 14px 0; }
        .nav-label { font-size: 9px; font-weight: 700; letter-spacing: 1.4px; text-transform: uppercase; color: #334155; padding: 14px 18px 5px; }
        .nav-a { display: flex; align-items: center; gap: 12px; padding: 10px 18px; color: #94a3b8; text-decoration: none; font-size: 13px; font-weight: 500; transition: all .2s; }
        .nav-a i { width: 18px; font-size: 13px; }
        .nav-a:hover { background: rgba(255,255,255,.04); color: #e2e8f0; }
        .nav-a.active { background: rgba(16,185,129,.15); color: #5eead4; }
        .sidebar-footer { padding: 14px 18px; border-top: 1px solid rgba(255,255,255,.07); }
        .logout-a { display: flex; align-items: center; gap: 10px; padding: 10px 18px; border-radius: 9px; color: #f87171; text-decoration: none; font-size: 13px; font-weight: 600; }
        .logout-a:hover { background: rgba(239,68,68,.1); color: #fca5a5; }
        .main-wrap { margin-left: 260px; min-height: 100vh; padding: 20px 28px; }
        .topbar {
            background: rgba(255,255,255,.92);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 12px 24px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            border: 1px solid #e2e8f0;
        }
        .page-title {
            font-size: 20px;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .page-title i { color: #10b981; font-size: 24px; }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 18px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            border: 1px solid #e2e8f0;
        }
        .stat-icon {
            width: 52px; height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        .stat-icon.con { background: #d1fae5; color: #059669; }
        .stat-icon.het { background: #fee2e2; color: #dc2626; }
        .stat-icon.all { background: #dbeafe; color: #2563eb; }
        .stat-info h3 { font-size: 24px; font-weight: 800; margin: 0; }
        .stat-info p { margin: 0; font-size: 13px; color: #64748b; }
        .table-container {
            background: white;
            border-radius: 20px;
            border: 1px solid #e2e8f0;
            overflow: hidden;
        }
        .table-header {
            padding: 16px 24px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            background: #fafbfc;
        }
        .table-title {
            font-weight: 700;
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .table-responsive-custom { overflow-x: auto; }
        .tbl { width: 100%; border-collapse: collapse; font-size: 13px; }
        .tbl thead th {
            background: #f8fafc;
            font-weight: 600;
            font-size: 12px;
            color: #64748b;
            padding: 14px 16px;
            border-bottom: 2px solid #e2e8f0;
            white-space: nowrap;
        }
        .tbl tbody td { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
        .tbl tbody tr:hover td { background: #fafbff; }
        .chip {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .chip-success { background: #d1fae5; color: #065f46; }
        .chip-danger { background: #fee2e2; color: #991b1b; }
        .chip-warning { background: #fef3c7; color: #78350f; }
        .btn-primary-qa {
            display: inline-flex; align-items: center; gap: 6px;
            background: #10b981; color: #fff; border: none;
            padding: 8px 18px; border-radius: 10px;
            font-size: 13px; font-weight: 600;
            cursor: pointer;
        }
        .btn-primary-qa:hover { background: #059669; }
        .btn-outline-qa {
            display: inline-flex; align-items: center; gap: 6px;
            background: #fff; color: #0f172a;
            border: 1px solid #e2e8f0;
            padding: 7px 16px; border-radius: 10px;
            font-size: 13px; font-weight: 500;
            cursor: pointer;
        }
        .btn-outline-qa:hover { border-color: #10b981; color: #10b981; background: #f0fdf4; }
        .btn-ico {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px; height: 32px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            background: #fff;
            color: #64748b;
            cursor: pointer;
        }
        .btn-ico:hover { border-color: #10b981; color: #10b981; background: #f0fdf4; }
        .btn-ico.del:hover { border-color: #ef4444; color: #ef4444; background: #fee2e2; }
        .warranty-bar {
            height: 5px;
            border-radius: 3px;
            background: #e2e8f0;
            overflow: hidden;
            margin-top: 6px;
        }
        .warranty-fill {
            height: 100%;
            border-radius: 3px;
            background: linear-gradient(90deg, #10b981, #059669);
            transition: width .5s;
        }
        .warranty-fill.warning {
            background: linear-gradient(90deg, #f59e0b, #ef4444);
        }
        .empty-state { text-align: center; padding: 60px 24px; }
        .empty-state i { font-size: 56px; color: #cbd5e1; margin-bottom: 16px; }
        .search-box {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 40px;
            padding: 5px 16px;
        }
        .search-box input { border: none; outline: none; padding: 8px 0; font-size: 13px; min-width: 240px; }
        .modal-content { border-radius: 18px; border: none; overflow: hidden; }
        .modal-header {
            background: linear-gradient(135deg, #1e3a8a, #2563eb);
            color: white;
            padding: 18px 24px;
        }
        .modal-header .btn-close { filter: brightness(0) invert(1); }
        @media (max-width: 768px) {
            .main-wrap { margin-left: 260px; padding: 16px; }
            .search-box input { min-width: 140px; }
        }
        .customer-group {
            margin-top: 20px;
        }
        .customer-group:first-child {
            margin-top: 0;
        }
        .customer-title {
            background: linear-gradient(135deg, #f0fdf4, #dcfce7);
            padding: 8px 16px;
            border-radius: 10px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            color: #065f46;
            border-left: 4px solid #10b981;
        }
        .customer-title i {
            font-size: 16px;
        }
        .customer-title span {
            font-size: 13px;
            color: #64748b;
            font-weight: normal;
            margin-left: 8px;
        }
    </style>
</head>
<body>

<!-- Sidebar -->
<aside class="sidebar">
    <div class="sidebar-brand">
        <a href="index.php" class="brand-logo">
            <div class="brand-icon"><i class="fas fa-tools"></i></div>
            <div>
                <div class="brand-name">Quang Anh Tech</div>
                <div class="brand-sub">Kỹ thuật viên</div>
            </div>
        </a>
    </div>
    <div class="sidebar-user">
        <div class="user-avatar"><?= strtoupper(mb_substr($username, 0, 1)) ?></div>
        <div>
            <div class="user-name"><?= htmlspecialchars($username) ?></div>
            <div class="user-role">Kỹ thuật viên</div>
        </div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-label">Tổng quan</div>
        <a href="index.php" class="nav-a"><i class="fas fa-home"></i> Trang chủ</a>
        <div class="nav-label">Quản lý</div>
        <a href="quan_ly_khach_hang.php" class="nav-a"><i class="fas fa-users"></i> Khách hàng</a>
        <a href="bao_hanh.php" class="nav-a active"><i class="fas fa-shield-alt"></i> Bảo hành</a>
        <a href="phieu_sua_chua.php" class="nav-a"><i class="fas fa-tools"></i> Sửa chữa</a>
        <div class="nav-label">Tài khoản</div>
        <a href="doi_mat_khau.php" class="nav-a"><i class="fas fa-key"></i> Đổi mật khẩu</a>
    </nav>
    <div class="sidebar-footer">
        <a href="../logout.php" class="logout-a"><i class="fas fa-sign-out-alt"></i> Đăng xuất</a>
    </div>
</aside>

<!-- Main content -->
<div class="main-wrap">
    
    <div class="topbar">
        <div class="page-title">
            <i class="fas fa-shield-alt"></i>
            <span>Quản lý phiếu bảo hành</span>
            <?php if (!$isAdmin): ?>
            <span style="font-size: 12px; background: #f1f5f9; padding: 4px 12px; border-radius: 20px;">
                <i class="fas fa-user-cog"></i> Kỹ thuật viên
            </span>
            <?php endif; ?>
        </div>
        <div class="d-flex gap-2">
            <div class="search-box">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Tìm theo tên KH, sản phẩm, SĐT..." onkeyup="filterTable()">
            </div>
            <?php if ($isAdmin): ?>
            <button class="btn-primary-qa" data-bs-toggle="modal" data-bs-target="#addBHModal">
                <i class="fas fa-plus"></i> Thêm bảo hành
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php
    $allCount = $baoHanhList ? $baoHanhList->num_rows : 0;
    $conCount = 0;
    $hetCount = 0;
    $sapHetCount = 0;
    
    // Lưu lại dữ liệu để hiển thị theo nhóm
    $warrantyData = [];
    if ($baoHanhList && $baoHanhList->num_rows > 0) {
        while ($row = $baoHanhList->fetch_assoc()) {
            $today = new DateTime();
            $ngayKT = new DateTime($row['ngayKetThuc']);
            $isActive = ($row['trangThai'] == 'Còn bảo hành' && $today <= $ngayKT);
            $daysLeft = $isActive ? max(0, (int)$today->diff($ngayKT)->days) : 0;
            
            if ($isActive) {
                $conCount++;
                if ($daysLeft <= 30) $sapHetCount++;
            } else {
                $hetCount++;
            }
            
            $customerName = $row['tenKH'] ?? 'Khách hàng không xác định';
            $warrantyData[$customerName][] = $row;
        }
        // Sắp xếp mảng theo tên khách hàng
        ksort($warrantyData);
    }
    ?>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon all"><i class="fas fa-shield-alt"></i></div>
            <div class="stat-info"><h3><?= $allCount ?></h3><p>Tổng số phiếu</p></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon con"><i class="fas fa-check-circle"></i></div>
            <div class="stat-info"><h3><?= $conCount ?></h3><p>Đang còn hiệu lực</p></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon het"><i class="fas fa-clock"></i></div>
            <div class="stat-info"><h3><?= $hetCount ?></h3><p>Đã hết hạn</p></div>
        </div>
        <?php if ($sapHetCount > 0): ?>
        <div class="stat-card">
            <div class="stat-icon" style="background:#fef3c7;color:#d97706;"><i class="fas fa-hourglass-half"></i></div>
            <div class="stat-info"><h3><?= $sapHetCount ?></h3><p>Sắp hết hạn (≤30 ngày)</p></div>
        </div>
        <?php endif; ?>
    </div>

    <div class="table-container">
        <div class="table-header">
            <div class="table-title">
                <i class="fas fa-list-ul" style="color: #10b981;"></i>
                Danh sách phiếu bảo hành
                <span style="font-size: 12px; background: #f1f5f9; padding: 2px 10px; border-radius: 20px; margin-left: 8px;">
                    <i class="fas fa-sort-alpha-down"></i> Sắp xếp theo tên KH
                </span>
            </div>
            <div class="d-flex gap-2">
                <button class="btn-outline-qa" onclick="exportToExcel()">
                    <i class="fas fa-file-excel" style="color: #059669;"></i> Xuất Excel
                </button>
                <button class="btn-outline-qa" onclick="window.print()">
                    <i class="fas fa-print"></i> In danh sách
                </button>
            </div>
        </div>
        <div class="table-responsive-custom">
            <table class="tbl" id="warrantyTable">
                <thead>
                    <tr>
                        <th>Mã BH</th>
                        <th>Khách hàng</th>
                        <th>Sản phẩm</th>
                        <th>Thời hạn</th>
                        <th>Ngày bắt đầu</th>
                        <th>Ngày kết thúc</th>
                        <th>Tiến độ</th>
                        <th>Trạng thái</th>
                        <th class="text-center">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($warrantyData)): ?>
                        <?php foreach ($warrantyData as $customerName => $warranties): ?>
                            <!-- Nhóm khách hàng -->
                            <tr class="customer-group-row">
                                <td colspan="9" style="padding: 0;">
                                    <div class="customer-group">
                                        <div class="customer-title">
                                            <i class="fas fa-user-circle"></i>
                                            <?= htmlspecialchars($customerName) ?>
                                            <span>(<?= count($warranties) ?> phiếu bảo hành)</span>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php foreach ($warranties as $bh): 
                                $today = new DateTime();
                                $ngayKT = new DateTime($bh['ngayKetThuc']);
                                $ngayBD = new DateTime($bh['ngayBatDau']);
                                $totalDays = max(1, $ngayBD->diff($ngayKT)->days);
                                $usedDays = min($totalDays, max(0, $ngayBD->diff($today)->days));
                                $percent = round($usedDays / $totalDays * 100);
                                $isActive = ($bh['trangThai'] == 'Còn bảo hành' && $today <= $ngayKT);
                                $daysLeft = $isActive ? max(0, (int)$today->diff($ngayKT)->days) : 0;
                                $barColor = $percent > 80 ? 'warning' : '';
                                $isSapHet = $isActive && $daysLeft <= 30 && $daysLeft > 0;
                            ?>
                            <tr data-search="<?= strtolower(($bh['tenKH'] ?? '') . ' ' . $bh['tenSP'] . ' ' . ($bh['soDienThoai'] ?? '')) ?>">
                                <td>
                                    <strong style="color: #2563eb;">#BH-<?= str_pad($bh['maBaoHanh'], 5, '0', STR_PAD_LEFT) ?></strong>
                                </td>
                                <td>
                                    <div style="font-weight: 600;"><?= htmlspecialchars($bh['tenKH'] ?? '—') ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($bh['soDienThoai'] ?? '') ?></small>
                                 </td>
                                <td>
                                    <div style="font-weight: 500;"><?= htmlspecialchars($bh['tenSP']) ?></div>
                                    <?php if (!empty($bh['thoiHan'])): ?>
                                    <small class="text-muted"><?= htmlspecialchars($bh['thoiHan']) ?></small>
                                    <?php endif; ?>
                                 </td>
                                <td><?= htmlspecialchars($bh['thoiHan'] ?? '—') ?> </td>
                                <td><?= date('d/m/Y', strtotime($bh['ngayBatDau'])) ?> </td>
                                <td>
                                    <?= date('d/m/Y', strtotime($bh['ngayKetThuc'])) ?>
                                    <?php if ($isSapHet): ?>
                                    <span class="badge bg-warning text-dark ms-1" style="font-size: 9px;">Sắp hết</span>
                                    <?php endif; ?>
                                 </td>
                                <td>
                                    <div style="min-width: 100px;">
                                        <div class="d-flex justify-content-between small mb-1">
                                            <span><?= $usedDays ?>/<?= $totalDays ?> ngày</span>
                                            <span class="<?= $percent > 80 ? 'text-danger' : 'text-success' ?> fw-bold"><?= $percent ?>%</span>
                                        </div>
                                        <div class="warranty-bar">
                                            <div class="warranty-fill <?= $barColor ?>" style="width: <?= $percent ?>%;"></div>
                                        </div>
                                        <?php if ($isActive && $daysLeft > 0): ?>
                                        <small class="<?= $isSapHet ? 'text-warning' : 'text-muted' ?>">Còn <?= $daysLeft ?> ngày</small>
                                        <?php elseif ($isActive && $daysLeft == 0): ?>
                                        <small class="text-warning">Hết hạn hôm nay</small>
                                        <?php endif; ?>
                                    </div>
                                 </td>
                                <td>
                                    <?php if ($isActive): ?>
                                    <span class="chip chip-success"><i class="fas fa-check-circle"></i> Còn bảo hành</span>
                                    <?php else: ?>
                                    <span class="chip chip-danger"><i class="fas fa-exclamation-circle"></i> Hết hạn</span>
                                    <?php endif; ?>
                                 </td>
                                <td class="text-center">
                                    <div class="d-flex gap-1 justify-content-center">
                                        <button class="btn-ico" onclick='viewWarranty(<?= htmlspecialchars(json_encode($bh, JSON_HEX_TAG)) ?>)' title="Xem chi tiết">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn-ico" onclick="printWarranty(<?= $bh['maBaoHanh'] ?>)" title="In phiếu">
                                            <i class="fas fa-print"></i>
                                        </button>
                                        <button class="btn-ico" onclick="saveWarrantyPDF(<?= $bh['maBaoHanh'] ?>)" title="Lưu PDF">
                                            <i class="fas fa-file-pdf" style="color: #dc2626;"></i>
                                        </button>
                                        <?php if ($isAdmin): ?>
                                        <button class="btn-ico" data-bs-toggle="modal" data-bs-target="#editBHModal_<?= $bh['maBaoHanh'] ?>" title="Sửa">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn-ico del" onclick="confirmDelete(<?= $bh['maBaoHanh'] ?>)" title="Xóa">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>

                            <!-- Modal Xem chi tiết -->
                            <div class="modal fade" id="viewBHModal_<?= $bh['maBaoHanh'] ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg modal-dialog-centered">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title"><i class="fas fa-shield-alt me-2"></i>Phiếu Bảo Hành #BH-<?= str_pad($bh['maBaoHanh'], 5, '0', STR_PAD_LEFT) ?></h5>
                                            <button class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body" id="viewBHContent_<?= $bh['maBaoHanh'] ?>"></div>
                                        <div class="modal-footer">
                                            <button class="btn-outline-qa" data-bs-dismiss="modal">Đóng</button>
                                            <button class="btn-primary-qa" onclick="printWarranty(<?= $bh['maBaoHanh'] ?>)"><i class="fas fa-print"></i> In phiếu</button>
                                            <button class="btn-outline-qa" onclick="saveWarrantyPDF(<?= $bh['maBaoHanh'] ?>)"><i class="fas fa-save"></i> Lưu PDF</button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <?php if ($isAdmin): ?>
                            <!-- Modal Sửa -->
                            <div class="modal fade" id="editBHModal_<?= $bh['maBaoHanh'] ?>" tabindex="-1">
                                <div class="modal-dialog modal-lg modal-dialog-centered">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Cập nhật bảo hành #BH-<?= str_pad($bh['maBaoHanh'], 5, '0', STR_PAD_LEFT) ?></h5>
                                            <button class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="POST">
                                            <div class="modal-body">
                                                <input type="hidden" name="maBaoHanh" value="<?= $bh['maBaoHanh'] ?>">
                                                <div class="row g-3">
                                                    <div class="col-12">
                                                        <label class="form-label">Tên sản phẩm <span class="text-danger">*</span></label>
                                                        <input type="text" name="tenSP" class="form-control" value="<?= htmlspecialchars($bh['tenSP']) ?>" required>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label">Ngày bắt đầu</label>
                                                        <input type="date" name="ngayBatDau" class="form-control" value="<?= $bh['ngayBatDau'] ?>" required>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label">Thời hạn</label>
                                                        <select name="thoiHan" class="form-select">
                                                            <option value="3 tháng" <?= $bh['thoiHan'] == '3 tháng' ? 'selected' : '' ?>>3 tháng</option>
                                                            <option value="6 tháng" <?= $bh['thoiHan'] == '6 tháng' ? 'selected' : '' ?>>6 tháng</option>
                                                            <option value="12 tháng" <?= $bh['thoiHan'] == '12 tháng' ? 'selected' : '' ?>>12 tháng</option>
                                                            <option value="24 tháng" <?= $bh['thoiHan'] == '24 tháng' ? 'selected' : '' ?>>24 tháng</option>
                                                            <option value="36 tháng" <?= $bh['thoiHan'] == '36 tháng' ? 'selected' : '' ?>>36 tháng</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <label class="form-label">Ngày kết thúc</label>
                                                        <input type="date" name="ngayKetThuc" class="form-control" value="<?= $bh['ngayKetThuc'] ?>" required>
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label">Điều kiện bảo hành</label>
                                                        <textarea name="dieuKienBaoHanh" class="form-control" rows="6"><?= htmlspecialchars($bh['dieuKienBaoHanh'] ?? '') ?></textarea>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">Trạng thái</label>
                                                        <select name="trangThai" class="form-select">
                                                            <option value="Còn bảo hành" <?= $bh['trangThai'] == 'Còn bảo hành' ? 'selected' : '' ?>>Còn bảo hành</option>
                                                            <option value="Hết hạn" <?= $bh['trangThai'] == 'Hết hạn' ? 'selected' : '' ?>>Hết hạn</option>
                                                            <option value="Đang xử lý" <?= $bh['trangThai'] == 'Đang xử lý' ? 'selected' : '' ?>>Đang xử lý</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button class="btn-outline-qa" type="button" data-bs-dismiss="modal">Hủy</button>
                                                <button type="submit" name="sua_bao_hanh" class="btn-primary-qa">Lưu</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="9"><div class="empty-state"><i class="fas fa-shield-alt"></i><p>Chưa có phiếu bảo hành nào.</p></div></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($isAdmin): ?>
<div class="modal fade" id="addBHModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle me-2"></i>Thêm phiếu bảo hành mới</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Khách hàng <span class="text-danger">*</span></label>
                            <select name="maKH" class="form-select" required>
                                <option value="">-- Chọn khách hàng --</option>
                                <?php foreach ($khachHangList as $kh): ?>
                                <option value="<?= $kh['maKH'] ?>"><?= htmlspecialchars($kh['tenKH']) ?> (<?= htmlspecialchars($kh['soDienThoai']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Tên sản phẩm <span class="text-danger">*</span></label>
                            <input type="text" name="tenSP" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Ngày bắt đầu</label>
                            <input type="date" name="ngayBatDau" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Thời hạn</label>
                            <select name="thoiHan" class="form-select">
                                <option value="3 tháng">3 tháng</option>
                                <option value="6 tháng">6 tháng</option>
                                <option value="12 tháng" selected>12 tháng</option>
                                <option value="24 tháng">24 tháng</option>
                                <option value="36 tháng">36 tháng</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Ngày kết thúc</label>
                            <input type="date" name="ngayKetThuc" class="form-control" value="<?= date('Y-m-d', strtotime('+12 months')) ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Điều kiện bảo hành</label>
                            <textarea name="dieuKienBaoHanh" class="form-control" rows="6">CHÍNH SÁCH BẢO HÀNH - MÁY TÍNH QUANG ANH

📌 Laptop NEW: Bảo hành 12 tháng theo quy định của hãng.

📌 Laptop CŨ: Bảo hành 3-6 tháng 1 ĐỔI.

✅ Cài win, hỗ trợ cài đặt phần mềm: MIỄN PHÍ trọn đời.

📞 Mọi thắc mắc xin liên hệ: 0934322199.</textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Trạng thái</label>
                            <select name="trangThai" class="form-select">
                                <option value="Còn bảo hành" selected>Còn bảo hành</option>
                                <option value="Hết hạn">Hết hạn</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-outline-qa" type="button" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" name="them_bao_hanh" class="btn-primary-qa">Thêm</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.7.12/sweetalert2.all.min.js"></script>
<script src="https://cdn.sheetjs.com/xlsx-0.20.2/package/dist/xlsx.full.min.js"></script>

<script>
function filterTable() {
    const input = document.getElementById('searchInput');
    const filter = input.value.toLowerCase();
    const rows = document.querySelectorAll('#warrantyTable tbody tr');
    rows.forEach(row => {
        if (row.classList && row.classList.contains('customer-group-row')) {
            // Skip group header row
            return;
        }
        const searchData = row.getAttribute('data-search') || '';
        const shouldShow = searchData.includes(filter) || filter === '';
        row.style.display = shouldShow ? '' : 'none';
        
        // Show/hide group header based on visible children
        const prevRow = row.previousElementSibling;
        if (prevRow && prevRow.classList && prevRow.classList.contains('customer-group-row')) {
            let hasVisible = false;
            let nextRow = prevRow.nextElementSibling;
            while (nextRow && !(nextRow.classList && nextRow.classList.contains('customer-group-row'))) {
                if (nextRow.style.display !== 'none') {
                    hasVisible = true;
                    break;
                }
                nextRow = nextRow.nextElementSibling;
            }
            prevRow.style.display = hasVisible ? '' : 'none';
        }
    });
}

function viewWarranty(bh) {
    const modalId = 'viewBHModal_' + bh.maBaoHanh;
    const contentDiv = document.getElementById('viewBHContent_' + bh.maBaoHanh);
    
    const ngayBD = new Date(bh.ngayBatDau).toLocaleDateString('vi-VN');
    const ngayKT = new Date(bh.ngayKetThuc).toLocaleDateString('vi-VN');
    const today = new Date();
    const ngayKTDate = new Date(bh.ngayKetThuc);
    const isActive = (bh.trangThai === 'Còn bảo hành' && today <= ngayKTDate);
    const daysLeft = isActive ? Math.max(0, Math.ceil((ngayKTDate - today) / (1000 * 60 * 60 * 24))) : 0;
    
    const start = new Date(bh.ngayBatDau);
    const end = new Date(bh.ngayKetThuc);
    const totalDays = Math.max(1, Math.ceil((end - start) / (1000 * 60 * 60 * 24)));
    const usedDays = Math.min(totalDays, Math.max(0, Math.ceil((today - start) / (1000 * 60 * 60 * 24))));
    const percent = Math.round(usedDays / totalDays * 100);
    
    contentDiv.innerHTML = `
        <div style="text-align:center;margin-bottom:20px;padding-bottom:16px;border-bottom:2px solid #e2e8f0;">
            <div style="font-size:42px;">🛡️</div>
            <div style="font-size:22px;font-weight:800;color:#1e3a8a;">PHIẾU BẢO HÀNH</div>
            <div style="font-size:13px;color:#64748b;">Mã số: #BH-${String(bh.maBaoHanh).padStart(5,'0')}</div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <div style="background:#f0fdf4;border-radius:12px;padding:14px;">
                    <div style="font-weight:700;color:#065f46;margin-bottom:8px;">👤 THÔNG TIN KHÁCH HÀNG</div>
                    <div><strong>Họ tên:</strong> ${escapeHtml(bh.tenKH || '—')}</div>
                    <div><strong>SĐT:</strong> ${escapeHtml(bh.soDienThoai || '—')}</div>
                    <div><strong>Email:</strong> ${escapeHtml(bh.email || '—')}</div>
                    <div><strong>Địa chỉ:</strong> ${escapeHtml(bh.diaChi || '—')}</div>
                    <div><strong>Hạng:</strong> ${escapeHtml(bh.loaiKhachHang || '—')}</div>
                </div>
            </div>
            <div class="col-md-6">
                <div style="background:#eff6ff;border-radius:12px;padding:14px;">
                    <div style="font-weight:700;color:#1e40af;margin-bottom:8px;">💻 THÔNG TIN SẢN PHẨM</div>
                    <div><strong>Sản phẩm:</strong> ${escapeHtml(bh.tenSP)}</div>
                    <div><strong>Thời hạn:</strong> <span style="font-weight:700;color:#1e40af;">${escapeHtml(bh.thoiHan || '—')}</span></div>
                    <div><strong>Ngày bắt đầu:</strong> ${ngayBD}</div>
                    <div><strong>Ngày kết thúc:</strong> <span style="font-weight:700;color:#dc2626;">${ngayKT}</span></div>
                </div>
            </div>
        </div>
        <div class="text-center mb-3">
            <span style="background:${isActive ? '#d1fae5' : '#fee2e2'};color:${isActive ? '#065f46' : '#991b1b'};padding:6px 24px;border-radius:30px;font-weight:700;font-size:14px;">
                ${isActive ? '✅ CÒN HIỆU LỰC' : '❌ HẾT HIỆU LỰC'}
            </span>
            ${isActive && daysLeft > 0 ? `<div style="margin-top:8px;font-size:12px;color:#64748b;">⏳ Còn ${daysLeft} ngày bảo hành</div>` : ''}
        </div>
        <div style="margin-bottom:16px;">
            <div class="d-flex justify-content-between small mb-1">
                <span>Tiến độ sử dụng bảo hành</span>
                <span class="${percent > 80 ? 'text-danger' : 'text-success'} fw-bold">${percent}%</span>
            </div>
            <div style="background:#e2e8f0;border-radius:20px;height:8px;overflow:hidden;">
                <div style="width:${percent}%;height:100%;border-radius:20px;background:${percent > 80 ? 'linear-gradient(90deg,#f59e0b,#ef4444)' : 'linear-gradient(90deg,#10b981,#059669)'};"></div>
            </div>
            <div class="d-flex justify-content-between small mt-1">
                <span>${usedDays} ngày đã qua</span>
                <span>${totalDays - usedDays} ngày còn lại</span>
            </div>
        </div>
        ${bh.dieuKienBaoHanh ? `
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:14px;margin-top:8px;">
            <div style="font-weight:700;color:#1e293b;margin-bottom:8px;border-bottom:1px solid #e2e8f0;padding-bottom:6px;">
                <i class="fas fa-file-alt me-2"></i>ĐIỀU KIỆN & CHÍNH SÁCH BẢO HÀNH
            </div>
            <pre style="font-size:12px;line-height:1.6;margin:0;white-space:pre-wrap;font-family:inherit;color:#334155;">${escapeHtml(bh.dieuKienBaoHanh)}</pre>
        </div>
        ` : ''}
        <div class="row mt-4 text-center">
            <div class="col-6">
                <div style="border-top:1px solid #e2e8f0;padding-top:12px;">
                    <small class="text-muted">Khách hàng xác nhận<br>(Ký, ghi rõ họ tên)</small>
                </div>
            </div>
            <div class="col-6">
                <div style="border-top:1px solid #e2e8f0;padding-top:12px;">
                    <small class="text-muted">Đại diện Quang Anh<br>(Ký, đóng dấu)</small>
                </div>
            </div>
        </div>
    `;
    new bootstrap.Modal(document.getElementById(modalId)).show();
}

function printWarranty(id) {
    const el = document.getElementById('viewBHContent_' + id);
    if (!el) return;
    const w = window.open('', '_blank');
    w.document.write('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Phieu Bao Hanh</title><style>body{font-family:Arial;padding:30px;}pre{font-family:inherit;}</style></head><body>');
    w.document.write(el.innerHTML);
    w.document.write('</body></html>');
    w.document.close();
    w.print();
}

function saveWarrantyPDF(id) {
    printWarranty(id);
}

function confirmDelete(id) {
    Swal.fire({
        title: 'Xóa phiếu bảo hành?',
        text: 'Hành động này không thể hoàn tác!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        confirmButtonText: 'Xóa',
        cancelButtonText: 'Hủy'
    }).then(result => {
        if (result.isConfirmed) window.location.href = 'bao_hanh.php?xoa_bh=' + id;
    });
}

function exportToExcel() {
    const wsData = [['Mã BH', 'Khách hàng', 'SĐT', 'Sản phẩm', 'Thời hạn', 'Ngày bắt đầu', 'Ngày kết thúc', 'Trạng thái']];
    const rows = document.querySelectorAll('#warrantyTable tbody tr');
    rows.forEach(row => {
        if (row.classList && row.classList.contains('customer-group-row')) return;
        if (row.querySelector('.empty-state')) return;
        const cells = row.querySelectorAll('td');
        if (cells.length >= 8) {
            wsData.push([
                cells[0]?.innerText.trim() || '',
                cells[1]?.querySelector('div')?.innerText.trim() || '',
                cells[1]?.querySelector('small')?.innerText.trim() || '',
                cells[2]?.querySelector('div')?.innerText.trim() || '',
                cells[3]?.innerText.trim() || '',
                cells[4]?.innerText.trim() || '',
                cells[5]?.innerText.trim() || '',
                cells[7]?.innerText.trim() || ''
            ]);
        }
    });
    const ws = XLSX.utils.aoa_to_sheet(wsData);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Bao hanh');
    XLSX.writeFile(wb, 'DanhSachBaoHanh.xlsx');
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}
</script>

</body>
</html>