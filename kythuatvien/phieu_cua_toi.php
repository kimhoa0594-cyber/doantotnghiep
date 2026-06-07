<?php
/**
 * phieu_cua_toi.php  —  Kỹ thuật viên: phiếu được phân công cho mình
 * Đặt trong cùng thư mục kythuatvien/
 */
session_start();
require_once '../db.php';
date_default_timezone_set('Asia/Ho_Chi_Minh');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'technician') {
    header("Location: ../login.php"); exit();
}

$tenKTV = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Kỹ thuật viên';
$ktv_email = $_SESSION['email'] ?? '';
if ($ktv_email) {
    $chkKtv = $conn->query("SHOW TABLES LIKE 'ky_thuat_vien'");
    if ($chkKtv && $chkKtv->num_rows > 0) {
        $sKtv = $conn->prepare("SELECT tenKTV FROM ky_thuat_vien WHERE email=? AND trangThai=1 LIMIT 1");
        $sKtv->bind_param("s", $ktv_email);
        $sKtv->execute();
        $rowKtv = $sKtv->get_result()->fetch_assoc();
        $sKtv->close();
        if (!empty($rowKtv['tenKTV'])) $tenKTV = $rowKtv['tenKTV'];
    }
}

// ── Đảm bảo bảng thông báo tồn tại ──
$conn->query("CREATE TABLE IF NOT EXISTS `thong_bao_ktv` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `maPhieu` INT NOT NULL,
    `kyThuatVien` VARCHAR(100) NOT NULL,
    `loai` VARCHAR(30) NOT NULL DEFAULT 'phan_cong',
    `tieuDe` VARCHAR(200) NOT NULL,
    `noiDung` TEXT DEFAULT NULL,
    `trangThai` VARCHAR(20) NOT NULL DEFAULT 'chua_doc',
    `nguoiGui` VARCHAR(100) DEFAULT NULL,
    `thoiGian` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Thêm cột kyThuatVien vào phieu_sua_chua nếu chưa có
$colCheck = $conn->query("SHOW COLUMNS FROM phieu_sua_chua LIKE 'kyThuatVien'");
if ($colCheck && $colCheck->num_rows == 0) {
    $conn->query("ALTER TABLE phieu_sua_chua ADD COLUMN `kyThuatVien` VARCHAR(100) DEFAULT NULL");
}

// ── Xử lý action ──
$msg = ''; $msgType = '';

// Cập nhật trạng thái từ form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cap_nhat_tt'])) {
    $maPhieu   = (int)$_POST['maPhieu'];
    $trangThai = $_POST['trangThai'] ?? '';
    $ghiChu    = trim($_POST['ghiChu'] ?? '');
    $chiPhiLinhKien = (int)($_POST['chiPhiLinhKien'] ?? 0);
    $chiPhiCong     = (int)($_POST['chiPhiCong'] ?? 0);
    $chiPhi         = $chiPhiLinhKien + $chiPhiCong;
    $ngayTraThucTe  = $_POST['ngayTraThucTe'] ?: null;

    // Chỉ được cập nhật phiếu của mình
    $check = $conn->prepare("SELECT maPhieu FROM phieu_sua_chua WHERE maPhieu=? AND kyThuatVien=?");
    $check->bind_param("is", $maPhieu, $tenKTV);
    $check->execute();
    if ($check->get_result()->num_rows > 0) {
        $sql = "UPDATE phieu_sua_chua SET trangThai=?, chiPhiLinhKien=?, chiPhiCong=?, chiPhi=?, updated_at=NOW()";
        $params = [$trangThai, $chiPhiLinhKien, $chiPhiCong, $chiPhi];
        $types  = "siii";
        if ($ngayTraThucTe) {
            $sql .= ", ngayTraThucTe=?";
            $params[] = $ngayTraThucTe;
            $types .= "s";
        }
        if ($ghiChu) {
            $sql .= ", ghiChu=?";
            $params[] = $ghiChu;
            $types .= "s";
        }
        $sql .= " WHERE maPhieu=?";
        $params[] = $maPhieu;
        $types .= "i";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        if ($stmt->execute()) {
            // Nếu bàn giao: ghi ngày thực tế nếu chưa có
            if ($trangThai === 'Đã bàn giao' && !$ngayTraThucTe) {
                $conn->query("UPDATE phieu_sua_chua SET ngayTraThucTe=CURDATE() WHERE maPhieu=$maPhieu AND ngayTraThucTe IS NULL");
            }
            $msg = "✅ Cập nhật phiếu #SC-{$maPhieu} thành công!";
            $msgType = 'success';
        } else {
            $msg = "❌ Lỗi: " . $stmt->error;
            $msgType = 'danger';
        }
        $stmt->close();
    } else {
        $msg = "⚠️ Bạn không có quyền cập nhật phiếu này.";
        $msgType = 'warning';
    }
    $check->close();
}

// ── Lấy phiếu được phân công ──
$filter = $_GET['filter'] ?? 'pending';  // pending | all | done
$search = trim($_GET['search'] ?? '');

$where = "WHERE ps.kyThuatVien = ?";
$params = [$tenKTV];
$types  = "s";

if ($filter === 'pending') {
    $where .= " AND ps.trangThai NOT IN ('Đã bàn giao','Không sửa được')";
} elseif ($filter === 'done') {
    $where .= " AND ps.trangThai IN ('Đã bàn giao','Đã sửa xong')";
}
if ($search) {
    $like = "%$search%";
    $where .= " AND (ps.maPhieu LIKE ? OR ps.tenThietBi LIKE ? OR ps.tenKH LIKE ?)";
    $params = array_merge($params, [$like, $like, $like]);
    $types .= "sss";
}

$stmtL = $conn->prepare("
    SELECT ps.*,
           tb.id as tbId, tb.trangThai as tbTT, tb.thoiGian as tbThoiGian, tb.nguoiGui,
           kh.email as emailKH, kh.diaChi as diaChiKH
    FROM phieu_sua_chua ps
    LEFT JOIN thong_bao_ktv tb ON tb.maPhieu = ps.maPhieu AND tb.kyThuatVien = ps.kyThuatVien AND tb.loai='phan_cong'
    LEFT JOIN khach_hang kh ON kh.maKH = ps.maKH
    $where
    ORDER BY FIELD(ps.trangThai,'Chờ xử lý','Đang sửa','Chờ linh kiện','Đã sửa xong','Đã bàn giao','Không sửa được'),
             FIELD(ps.mucDoUuTien,'Khẩn cấp','Cao','Bình thường','Thấp'),
             ps.maPhieu DESC
");
$stmtL->bind_param($types, ...$params);
$stmtL->execute();
$phieuList = $stmtL->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtL->close();

// Đếm pending thông báo
$stmtCount = $conn->prepare("SELECT COUNT(*) as c FROM thong_bao_ktv WHERE kyThuatVien=? AND trangThai='chua_doc'");
$stmtCount->bind_param("s", $tenKTV);
$stmtCount->execute();
$pendingNotif = (int)$stmtCount->get_result()->fetch_assoc()['c'];
$stmtCount->close();

// Stats
$statsRes = $conn->prepare("SELECT trangThai, COUNT(*) as c FROM phieu_sua_chua WHERE kyThuatVien=? GROUP BY trangThai");
$statsRes->bind_param("s", $tenKTV);
$statsRes->execute();
$statsRows = $statsRes->get_result()->fetch_all(MYSQLI_ASSOC);
$statsRes->close();
$stats = [];
foreach ($statsRows as $sr) $stats[$sr['trangThai']] = $sr['c'];
$totalCuaToi = array_sum($stats);
$dangXuLy    = ($stats['Chờ xử lý'] ?? 0) + ($stats['Đang sửa'] ?? 0) + ($stats['Chờ linh kiện'] ?? 0);

$ttColors = [
    'Chờ xử lý'      => ['bg'=>'#fef3c7','color'=>'#d97706','icon'=>'fa-clock'],
    'Đang sửa'       => ['bg'=>'#dbeafe','color'=>'#2563eb','icon'=>'fa-wrench'],
    'Chờ linh kiện'  => ['bg'=>'#fce7f3','color'=>'#db2777','icon'=>'fa-box'],
    'Đã sửa xong'    => ['bg'=>'#d1fae5','color'=>'#059669','icon'=>'fa-check-circle'],
    'Đã bàn giao'    => ['bg'=>'#f0fdf4','color'=>'#16a34a','icon'=>'fa-handshake'],
    'Không sửa được' => ['bg'=>'#fee2e2','color'=>'#dc2626','icon'=>'fa-times-circle'],
];
$trangThaiList = array_keys($ttColors);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Phiếu của tôi – Kỹ thuật viên</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root{
    --primary:#0f766e;--primary-dark:#0d6460;--primary-light:#ccfbf1;
    --sidebar-bg:#0f172a;--sidebar-w:260px;
    --surface:#fff;--surface-2:#f8fafc;--border:#e2e8f0;
    --text-primary:#0f172a;--text-muted:#64748b;
    --radius:14px;--shadow-sm:0 1px 3px rgba(0,0,0,.06);--shadow-md:0 4px 16px rgba(0,0,0,.08);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Be Vietnam Pro',sans-serif;background:var(--surface-2);color:var(--text-primary);font-size:14px}
/* Sidebar */
.sidebar{width:var(--sidebar-w);height:100vh;position:fixed;top:0;left:0;background:var(--sidebar-bg);display:flex;flex-direction:column;z-index:1000}
.sidebar-brand{padding:22px 18px 18px;border-bottom:1px solid rgba(255,255,255,.07)}
.brand-logo{display:flex;align-items:center;gap:12px;text-decoration:none}
.brand-icon{width:40px;height:40px;background:var(--primary);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;color:#fff}
.brand-name{font-size:14px;font-weight:800;color:#fff}.brand-sub{font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:1px}
.sidebar-user{padding:14px 18px;border-bottom:1px solid rgba(255,255,255,.07);display:flex;align-items:center;gap:10px}
.user-avatar{width:34px;height:34px;border-radius:50%;background:var(--primary);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:14px;flex-shrink:0}
.user-name{font-size:12.5px;font-weight:700;color:#e2e8f0}.user-role{font-size:10px;color:#64748b}
.sidebar-nav{flex:1;padding:14px 0;overflow-y:auto}
.nav-label{font-size:9px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:#334155;padding:14px 18px 5px}
.nav-link-item{display:flex;align-items:center;gap:11px;padding:10px 18px;color:#94a3b8;text-decoration:none;font-size:13px;font-weight:500;transition:all .2s;position:relative}
.nav-link-item:hover{color:#e2e8f0;background:rgba(255,255,255,.04)}
.nav-link-item.active{color:#fff;background:rgba(15,118,110,.15)}
.nav-icon{width:30px;height:30px;display:flex;align-items:center;justify-content:center;border-radius:7px;font-size:12px;flex-shrink:0;background:rgba(255,255,255,.05)}
.nav-link-item.active .nav-icon{background:rgba(15,118,110,.3);color:#5eead4}
.notif-badge{background:#ef4444;color:#fff;border-radius:20px;font-size:10px;font-weight:700;padding:1px 6px;margin-left:auto}
.sidebar-footer{padding:14px 18px;border-top:1px solid rgba(255,255,255,.07)}
.logout-btn{display:flex;align-items:center;gap:9px;padding:9px 11px;color:#f87171;text-decoration:none;font-size:12.5px;font-weight:600;border-radius:9px;transition:all .2s}
.logout-btn:hover{background:rgba(239,68,68,.1);color:#fca5a5}
/* Main */
.main-content{margin-left:var(--sidebar-w);min-height:100vh}
.topbar{height:60px;background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 28px;gap:14px;position:sticky;top:0;z-index:100;box-shadow:var(--shadow-sm)}
.topbar-title{font-size:15px;font-weight:700;color:var(--text-primary);flex:1}
.live-clock{font-size:12px;color:var(--text-muted);font-weight:600}
.page-body{padding:24px 28px}
.alert-box{padding:12px 16px;border-radius:10px;margin-bottom:18px;font-size:13px;font-weight:600}
.alert-success{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0}
.alert-danger{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
.alert-warning{background:#fef3c7;color:#92400e;border:1px solid #fde68a}
/* Stats */
.kpi-row{display:flex;gap:14px;margin-bottom:20px;flex-wrap:wrap}
.kpi{background:#fff;border-radius:12px;padding:16px 20px;flex:1;min-width:120px;border:1px solid var(--border);box-shadow:var(--shadow-sm);display:flex;align-items:center;gap:14px}
.kpi-icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.kpi-val{font-size:24px;font-weight:800;color:var(--text-primary);line-height:1}
.kpi-lbl{font-size:11px;color:var(--text-muted);margin-top:3px}
/* Filter tabs */
.filter-tabs{display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap}
.ftab{padding:8px 18px;border-radius:9px;font-size:13px;font-weight:600;text-decoration:none;border:1px solid var(--border);color:var(--text-muted);background:#fff;transition:all .15s}
.ftab:hover{border-color:var(--primary);color:var(--primary)}
.ftab.active{background:var(--primary);color:#fff;border-color:var(--primary)}
/* Search */
.search-bar{background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;display:flex;gap:10px;margin-bottom:18px;box-shadow:var(--shadow-sm)}
.search-input{flex:1;padding:9px 12px;border:1px solid var(--border);border-radius:9px;font-size:13px;font-family:inherit;background:#f8fafc}
.search-input:focus{outline:none;border-color:var(--primary)}
.btn-search{padding:9px 18px;background:var(--primary);color:#fff;border:none;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer}
/* Cards phiếu */
.phieu-grid{display:flex;flex-direction:column;gap:14px}
.phieu-card{background:#fff;border-radius:14px;border:1px solid var(--border);box-shadow:var(--shadow-sm);overflow:hidden;transition:box-shadow .2s}
.phieu-card:hover{box-shadow:var(--shadow-md)}
.phieu-card.urgent{border-left:4px solid #dc2626}
.phieu-card.high{border-left:4px solid #ea580c}
.phieu-card.normal{border-left:4px solid var(--primary)}
.phieu-card.low{border-left:4px solid #059669}
.pc-header{display:flex;align-items:center;gap:12px;padding:14px 18px;border-bottom:1px solid #f1f5f9;background:#fafafa}
.pc-id{font-size:13px;font-weight:800;color:var(--primary)}
.pc-device{font-size:14px;font-weight:700;flex:1}
.badge-tt{display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:11px;font-weight:700}
.badge-priority{display:inline-flex;padding:3px 8px;border-radius:12px;font-size:10px;font-weight:700}
.pc-body{padding:14px 18px;display:grid;grid-template-columns:1fr 1fr;gap:10px}
.pc-info-item{font-size:12px;color:var(--text-muted)}
.pc-info-item strong{color:var(--text-primary);display:block;font-size:13px}
.pc-loi{grid-column:1/-1;background:#f8fafc;border-radius:8px;padding:10px 12px;font-size:12px;color:#475569;border-left:3px solid var(--primary)}
.pc-footer{padding:12px 18px;border-top:1px solid #f1f5f9;display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.btn-act{padding:7px 14px;border:none;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .15s;text-decoration:none}
.btn-update{background:#dbeafe;color:#2563eb}.btn-update:hover{background:#2563eb;color:#fff}
.btn-handover{background:#d1fae5;color:#059669}.btn-handover:hover{background:#059669;color:#fff}
.btn-detail{background:#f1f5f9;color:#475569}.btn-detail:hover{background:#e2e8f0}
.pc-notif{margin-left:auto;font-size:11px;color:#94a3b8}
/* Panel cập nhật inline */
.update-panel{display:none;padding:18px;border-top:2px solid var(--primary-light);background:#f0fdf4;animation:fadeIn .2s}
@keyframes fadeIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
.update-panel.open{display:block}
.up-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-bottom:14px}
.form-label{font-size:12px;font-weight:700;color:var(--text-primary);margin-bottom:4px;display:block}
.form-control,.form-select{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:inherit}
.form-control:focus,.form-select:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(15,118,110,.12)}
.btn-save{padding:9px 20px;background:var(--primary);color:#fff;border:none;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer}
.btn-cancel-up{padding:9px 16px;background:#f1f5f9;color:#64748b;border:1px solid var(--border);border-radius:9px;font-size:13px;font-weight:600;cursor:pointer}
/* Modal thông báo */
.notif-panel{position:fixed;top:60px;right:20px;width:380px;max-height:80vh;overflow-y:auto;background:#fff;border-radius:16px;border:1px solid var(--border);box-shadow:0 8px 32px rgba(0,0,0,.16);z-index:9999;display:none}
.notif-panel.open{display:block}
.notif-header{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;font-weight:700;font-size:14px}
.notif-item{padding:14px 18px;border-bottom:1px solid #f8fafc;transition:background .15s}
.notif-item:hover{background:#f8fafc}
.notif-item.unread{background:#eff6ff;border-left:3px solid #3b82f6}
.notif-title{font-size:13px;font-weight:700;color:var(--text-primary)}
.notif-sub{font-size:11px;color:var(--text-muted);margin-top:3px}
.notif-time{font-size:10px;color:#94a3b8;margin-top:4px}
.notif-actions{display:flex;gap:6px;margin-top:8px}
.btn-chap-nhan{padding:5px 12px;background:#d1fae5;color:#059669;border:none;border-radius:6px;font-size:11px;font-weight:700;cursor:pointer}
.btn-chap-nhan:hover{background:#059669;color:#fff}
.btn-tu-choi{padding:5px 12px;background:#fee2e2;color:#dc2626;border:none;border-radius:6px;font-size:11px;font-weight:700;cursor:pointer}
.btn-tu-choi:hover{background:#dc2626;color:#fff}
.notif-bell{position:relative;cursor:pointer;padding:8px;border-radius:9px;background:#f1f5f9;border:1px solid var(--border);display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:var(--text-primary);transition:all .15s}
.notif-bell:hover{background:#e2e8f0}
.notif-dot{position:absolute;top:-4px;right:-4px;background:#ef4444;color:#fff;border-radius:50%;font-size:9px;font-weight:700;min-width:18px;height:18px;display:flex;align-items:center;justify-content:center;border:2px solid #fff}
.empty-state{text-align:center;padding:48px 20px;color:var(--text-muted)}
.empty-state i{font-size:40px;opacity:.3;display:block;margin-bottom:12px}
/* Modal chi tiết phiếu */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9000;align-items:center;justify-content:center;padding:20px}
.modal-overlay.open{display:flex}
.modal-box{background:#fff;border-radius:18px;width:100%;max-width:900px;max-height:92vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden;display:flex;flex-direction:column;}
.modal-header{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;flex-shrink:0;background:#fff;}
.modal-title{font-size:14px;font-weight:800;color:var(--text-primary)}
.modal-close{background:none;border:none;font-size:22px;color:#94a3b8;cursor:pointer;line-height:1;padding:4px}
.modal-close:hover{color:var(--text-primary)}
.modal-body{flex:1;overflow-y:auto;}
.modal-body > div {border-radius:0;}
/* Responsive: stack columns on small screens */
@media(max-width:640px){
    .modal-box{max-width:100%;border-radius:14px;}
    .modal-body > div > div[style*="grid-template-columns:1fr 1fr"]{display:block!important;}
    .modal-body > div > div > div[style*="border-right"]{border-right:none!important;border-bottom:1px solid #f1f5f9;}
}
</style>
</head>
<body>

<!-- Sidebar -->
<aside class="sidebar">
    <div class="sidebar-brand">
        <a href="trang_chu.php" class="brand-logo">
            <div class="brand-icon"><i class="fas fa-tools"></i></div>
            <div><div class="brand-name">Quang Anh Tech</div><div class="brand-sub">Kỹ thuật viên</div></div>
        </a>
    </div>
    <div class="sidebar-user">
        <div class="user-avatar"><?= strtoupper(mb_substr($tenKTV,0,1)) ?></div>
        <div><div class="user-name"><?= htmlspecialchars($tenKTV) ?></div><div class="user-role">Kỹ thuật viên</div></div>
    </div>
    <nav class="sidebar-nav">
    <div class="nav-label">Tổng quan</div>
    <a href="index.php" class="nav-link-item"><div class="nav-icon"><i class="fas fa-home"></i></div>Trang chủ</a>
    <div class="nav-label">Quản lý</div>
    <a href="phieu_cua_toi.php" class="nav-link-item active">
        <div class="nav-icon"><i class="fas fa-user-check"></i></div>Sửa chữa
        <?php if ($pendingNotif > 0): ?><span class="notif-badge"><?= $pendingNotif ?></span><?php endif; ?>
    </a>
    <a href="phieu_sua_chua.php" class="nav-link-item"><div class="nav-icon"><i class="fas fa-screwdriver-wrench"></i></div>Tất cả phiếu</a>
    <a href="quan_ly_khach_hang.php" class="nav-link-item"><div class="nav-icon"><i class="fas fa-users"></i></div>Khách hàng</a>
    <a href="bao_hanh.php" class="nav-link-item"><div class="nav-icon"><i class="fas fa-shield-alt"></i></div>Bảo hành</a>  <!-- THÊM DÒNG NÀY -->
    <div class="nav-label">Tài khoản</div>
    <a href="doi_mat_khau.php" class="nav-link-item"><div class="nav-icon"><i class="fas fa-key"></i></div>Đổi mật khẩu</a>
</nav>
    <div class="sidebar-footer">
        <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Đăng xuất</a>
    </div>
</aside>

<!-- Main -->
<main class="main-content">
    <div class="topbar">
        <div class="topbar-title"><i class="fas fa-user-check me-2" style="color:var(--primary)"></i>Phiếu của tôi</div>
        <div class="live-clock" id="live-clock"></div>
        <!-- Bell thông báo -->
        <div class="notif-bell ms-3" id="bellBtn" onclick="toggleNotifPanel()">
            <i class="fas fa-bell"></i> Thông báo
            <?php if ($pendingNotif > 0): ?>
            <span class="notif-dot" id="notifDot"><?= $pendingNotif ?></span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Panel thông báo -->
    <div class="notif-panel" id="notifPanel">
        <div class="notif-header">
            <span><i class="fas fa-bell me-2"></i>Thông báo phân công</span>
            <button onclick="docTatCa()" style="background:none;border:none;font-size:11px;color:var(--primary);cursor:pointer;font-weight:600">Đánh dấu đã đọc</button>
        </div>
        <div id="notifList"><div style="text-align:center;padding:30px;color:#94a3b8"><i class="fas fa-spinner fa-spin"></i></div></div>
    </div>

    <div class="page-body">
        <?php if ($msg): ?>
        <div class="alert-box alert-<?= $msgType ?>"><?= $msg ?></div>
        <?php endif; ?>

        <!-- KPI -->
        <div class="kpi-row">
            <div class="kpi">
                <div class="kpi-icon" style="background:#ccfbf1;color:var(--primary)"><i class="fas fa-clipboard-list"></i></div>
                <div><div class="kpi-val"><?= $totalCuaToi ?></div><div class="kpi-lbl">Tổng phiếu của tôi</div></div>
            </div>
            <div class="kpi">
                <div class="kpi-icon" style="background:#fef3c7;color:#d97706"><i class="fas fa-wrench"></i></div>
                <div><div class="kpi-val"><?= $dangXuLy ?></div><div class="kpi-lbl">Đang xử lý</div></div>
            </div>
            <div class="kpi">
                <div class="kpi-icon" style="background:#d1fae5;color:#059669"><i class="fas fa-handshake"></i></div>
                <div><div class="kpi-val"><?= $stats['Đã bàn giao'] ?? 0 ?></div><div class="kpi-lbl">Đã bàn giao</div></div>
            </div>
            <?php if ($pendingNotif > 0): ?>
            <div class="kpi" style="border-color:#fca5a5;cursor:pointer" onclick="toggleNotifPanel()">
                <div class="kpi-icon" style="background:#fee2e2;color:#dc2626"><i class="fas fa-bell fa-shake"></i></div>
                <div><div class="kpi-val" style="color:#dc2626"><?= $pendingNotif ?></div><div class="kpi-lbl">Chờ chấp nhận</div></div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Filter tabs -->
        <div class="filter-tabs">
            <?php
            $tabs = ['pending'=>'🔄 Đang xử lý','all'=>'📋 Tất cả','done'=>'✅ Hoàn thành'];
            foreach ($tabs as $k=>$lbl):
                $q = array_merge($_GET,['filter'=>$k]);
            ?>
            <a href="?<?= http_build_query($q) ?>" class="ftab <?= $filter===$k?'active':'' ?>"><?= $lbl ?></a>
            <?php endforeach; ?>
        </div>

        <!-- Search -->
        <form method="GET">
            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
            <div class="search-bar">
                <input type="text" name="search" class="search-input" placeholder="🔍 Tìm theo mã phiếu, tên KH, thiết bị..." value="<?= htmlspecialchars($search) ?>">
                <button type="submit" class="btn-search"><i class="fas fa-search"></i> Tìm</button>
            </div>
        </form>

        <!-- Danh sách phiếu -->
        <div class="phieu-grid">
        <?php if (empty($phieuList)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <?= $filter === 'pending' ? 'Không có phiếu nào đang xử lý.' : 'Chưa có phiếu nào được phân công.' ?>
            </div>
        <?php else: foreach ($phieuList as $p):
            $tt  = $p['trangThai'] ?? 'Chờ xử lý';
            $c   = $ttColors[$tt] ?? ['bg'=>'#f1f5f9','color'=>'#64748b','icon'=>'fa-question'];
            $ut  = $p['mucDoUuTien'] ?? 'Bình thường';
            $utClass = ['Khẩn cấp'=>'urgent','Cao'=>'high','Bình thường'=>'normal','Thấp'=>'low'][$ut] ?? 'normal';
            $unread  = ($p['tbTT'] === 'chua_doc');
        ?>
            <div class="phieu-card <?= $utClass ?>" id="card-<?= $p['maPhieu'] ?>">
                <div class="pc-header">
                    <span class="pc-id">#SC-<?= $p['maPhieu'] ?></span>
                    <span class="pc-device"><?= htmlspecialchars($p['tenThietBi']) ?></span>
                    <?php if ($unread): ?>
                    <span style="background:#ef4444;color:#fff;border-radius:20px;font-size:10px;font-weight:700;padding:2px 8px">🔔 Mới</span>
                    <?php endif; ?>
                    <span class="badge-tt ms-2" style="background:<?= $c['bg'] ?>;color:<?= $c['color'] ?>">
                        <i class="fas <?= $c['icon'] ?>"></i> <?= $tt ?>
                    </span>
                    <span class="badge-priority ms-1" style="background:<?= ['Khẩn cấp'=>'#fee2e2','Cao'=>'#fed7aa','Bình thường'=>'#e2e8f0','Thấp'=>'#d1fae5'][$ut] ?? '#e2e8f0' ?>;color:<?= ['Khẩn cấp'=>'#dc2626','Cao'=>'#ea580c','Bình thường'=>'#475569','Thấp'=>'#059669'][$ut] ?? '#475569' ?>"><?= $ut ?></span>
                </div>
                <div class="pc-body">
                    <div class="pc-info-item">
                        <strong><?= htmlspecialchars($p['tenKH'] ?? '—') ?></strong>
                        <i class="fas fa-user" style="font-size:10px"></i> Khách hàng
                    </div>
                    <div class="pc-info-item">
                        <strong><?= htmlspecialchars($p['soDienThoai'] ?? '—') ?></strong>
                        <i class="fas fa-phone" style="font-size:10px"></i> Điện thoại
                    </div>
                    <?php if (!empty($p['emailKH'])): ?>
                    <div class="pc-info-item">
                        <strong><?= htmlspecialchars($p['emailKH']) ?></strong>
                        <i class="fas fa-envelope" style="font-size:10px"></i> Email
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($p['diaChiKH'])): ?>
                    <div class="pc-info-item">
                        <strong><?= htmlspecialchars($p['diaChiKH']) ?></strong>
                        <i class="fas fa-map-marker-alt" style="font-size:10px"></i> Địa chỉ
                    </div>
                    <?php endif; ?>
                    <div class="pc-info-item">
                        <strong><?= $p['ngayNhan'] ? date('d/m/Y', strtotime($p['ngayNhan'])) : '—' ?></strong>
                        <i class="fas fa-calendar" style="font-size:10px"></i> Ngày nhận
                    </div>
                    <div class="pc-info-item">
                        <strong><?= $p['ngayTraDuKien'] ? date('d/m/Y', strtotime($p['ngayTraDuKien'])) : '—' ?></strong>
                        <i class="fas fa-calendar-check" style="font-size:10px"></i> Ngày trả dự kiến
                    </div>
                    <div class="pc-info-item">
                        <strong style="color:var(--primary)"><?= number_format($p['chiPhi'] ?? 0) ?>đ</strong>
                        <i class="fas fa-money-bill" style="font-size:10px"></i> Chi phí
                    </div>
                    <?php if (!empty($p['baoGia'])): ?>
                    <div class="pc-info-item">
                        <strong style="color:#059669"><?= number_format($p['baoGia']) ?>đ</strong>
                        <i class="fas fa-file-invoice-dollar" style="font-size:10px"></i> Báo giá
                    </div>
                    <?php endif; ?>
                    <?php if ($p['nguoiGui']): ?>
                    <div class="pc-info-item">
                        <strong><?= htmlspecialchars($p['nguoiGui']) ?></strong>
                        <i class="fas fa-user-tie" style="font-size:10px"></i> Phân công bởi
                    </div>
                    <?php endif; ?>
                    <?php if ($p['moTaLoi']): ?>
                    <div class="pc-loi"><i class="fas fa-exclamation-circle" style="color:var(--primary);margin-right:6px"></i><?= nl2br(htmlspecialchars(mb_substr($p['moTaLoi'], 0, 150))) ?><?= mb_strlen($p['moTaLoi']) > 150 ? '...' : '' ?></div>
                    <?php endif; ?>
                    <?php
                    // Hiển thị ảnh thumbnail (tối đa 4 ảnh)
                    $anhArr = [];
                    if (!empty($p['anhSanPham'])) {
                        $anhArr = array_filter(array_map('trim', explode(',', $p['anhSanPham'])));
                    }
                    if (!empty($anhArr)):
                    ?>
                    <div style="grid-column:1/-1;margin-top:4px">
                        <div style="font-size:10px;color:var(--text-muted);font-weight:600;margin-bottom:6px"><i class="fas fa-images"></i> Ảnh sản phẩm (<?= count($anhArr) ?> ảnh)</div>
                        <div style="display:flex;gap:8px;flex-wrap:wrap">
                            <?php foreach(array_slice($anhArr,0,4) as $anh): ?>
                            <img src="../<?= htmlspecialchars($anh) ?>" alt="Ảnh SP"
                                 style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid var(--border);cursor:pointer"
                                 onclick="xemAnhLon('<?= htmlspecialchars(addslashes($anh)) ?>')"
                                 onerror="this.style.display='none'">
                            <?php endforeach; ?>
                            <?php if(count($anhArr) > 4): ?>
                            <div style="width:64px;height:64px;border-radius:8px;background:#f1f5f9;border:1px solid var(--border);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:var(--text-muted);cursor:pointer" onclick="moChiTiet(<?= $p['maPhieu'] ?>)">+<?= count($anhArr)-4 ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="pc-footer">
                    <?php if ($unread && $p['tbId']): ?>
                    <button class="btn-act btn-chap-nhan" onclick="chapNhan(<?= $p['tbId'] ?>,<?= $p['maPhieu'] ?>,'<?= addslashes($p['tenThietBi']) ?>')">
                        <i class="fas fa-check"></i> Chấp nhận
                    </button>
                    <button class="btn-act btn-tu-choi" onclick="tuChoi(<?= $p['tbId'] ?>,<?= $p['maPhieu'] ?>)">
                        <i class="fas fa-times"></i> Từ chối
                    </button>
                    <?php elseif ($tt !== 'Đã bàn giao' && $tt !== 'Không sửa được'): ?>
                    <button class="btn-act btn-update" onclick="toggleUpdate(<?= $p['maPhieu'] ?>)">
                        <i class="fas fa-edit"></i> Cập nhật
                    </button>
                    <?php endif; ?>
                    <?php if ($tt !== 'Đã bàn giao' && $tt !== 'Không sửa được' && !$unread): ?>
                    <button class="btn-act btn-handover" onclick="banGiao(<?= $p['maPhieu'] ?>)">
                        <i class="fas fa-handshake"></i> Bàn giao
                    </button>
                    <?php endif; ?>
                    <?php if ($p['tbThoiGian']): ?>
                    <span class="pc-notif"><i class="fas fa-clock"></i> Phân công: <?= date('d/m/Y H:i', strtotime($p['tbThoiGian'])) ?></span>
                    <?php endif; ?>
                    <button class="btn-act btn-xem-ct ms-auto" onclick="moChiTiet(<?= $p['maPhieu'] ?>)">
                        <i class="fas fa-eye"></i> Xem chi tiết
                    </button>
                </div>

                <!-- Panel cập nhật inline -->
                <div class="update-panel" id="up-<?= $p['maPhieu'] ?>">
                    <form method="POST">
                        <input type="hidden" name="maPhieu" value="<?= $p['maPhieu'] ?>">
                        <div class="up-grid">
                            <div>
                                <label class="form-label">Trạng thái mới</label>
                                <select name="trangThai" class="form-select">
                                    <?php foreach ($trangThaiList as $tl): ?>
                                    <option value="<?= $tl ?>" <?= $tt===$tl?'selected':'' ?>><?= $tl ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="form-label">Chi phí linh kiện (đ)</label>
                                <input type="number" name="chiPhiLinhKien" class="form-control" value="<?= $p['chiPhiLinhKien'] ?? 0 ?>" min="0" step="1000">
                            </div>
                            <div>
                                <label class="form-label">Chi phí công (đ)</label>
                                <input type="number" name="chiPhiCong" class="form-control" value="<?= $p['chiPhiCong'] ?? 0 ?>" min="0" step="1000">
                            </div>
                            <div>
                                <label class="form-label">Ngày trả thực tế</label>
                                <input type="date" name="ngayTraThucTe" class="form-control" value="<?= $p['ngayTraThucTe'] ?? '' ?>">
                            </div>
                            <div style="grid-column:1/-1">
                                <label class="form-label">Ghi chú thêm</label>
                                <input type="text" name="ghiChu" class="form-control" placeholder="Tình trạng, vật tư đã dùng..." value="<?= htmlspecialchars($p['ghiChu'] ?? '') ?>">
                            </div>
                        </div>
                        <div style="display:flex;gap:8px">
                            <button type="submit" name="cap_nhat_tt" class="btn-save"><i class="fas fa-save me-1"></i>Lưu cập nhật</button>
                            <button type="button" class="btn-cancel-up" onclick="toggleUpdate(<?= $p['maPhieu'] ?>)">Hủy</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endforeach; endif; ?>
        </div>
    </div>
    <!-- Modal chi tiết phiếu -->
    <div class="modal-overlay" id="chiTietModal" onclick="if(event.target===this)dongModal()">
        <div class="modal-box">
            <div class="modal-header">
                <span class="modal-title" id="modalTitle">Chi tiết phiếu</span>
                <button class="modal-close" onclick="dongModal()">×</button>
            </div>
            <div class="modal-body" id="modalBody">
                <div style="text-align:center;padding:40px;color:#94a3b8"><i class="fas fa-spinner fa-spin fa-2x"></i></div>
            </div>
        </div>
    </div>

    <!-- Modal xem ảnh lớn -->
    <div class="modal-overlay" id="anhModal" onclick="dongAnhModal()" style="z-index:9500">
        <img id="anhLon" src="" alt="" style="max-width:90vw;max-height:85vh;border-radius:12px;box-shadow:0 8px 40px rgba(0,0,0,.5)">
    </div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Đồng hồ
function updateClock(){
    const now=new Date(),pad=n=>String(n).padStart(2,'0'),days=['CN','T2','T3','T4','T5','T6','T7'];
    document.getElementById('live-clock').textContent=days[now.getDay()]+' '+pad(now.getHours())+':'+pad(now.getMinutes())+':'+pad(now.getSeconds());
}
updateClock();setInterval(updateClock,1000);

// Toggle panel cập nhật
function toggleUpdate(id) {
    const el = document.getElementById('up-' + id);
    el.classList.toggle('open');
    if (el.classList.contains('open')) {
        el.scrollIntoView({behavior:'smooth', block:'nearest'});
    }
}

// Bàn giao thiết bị
function banGiao(id) {
    Swal.fire({
        title:'Xác nhận bàn giao?',
        text:'Thiết bị sẽ được đánh dấu Đã bàn giao cho khách hàng.',
        icon:'question',
        confirmButtonColor:'#059669',
        confirmButtonText:'✅ Xác nhận bàn giao',
        showCancelButton:true, cancelButtonText:'Hủy'
    }).then(r => {
        if (r.isConfirmed) {
            // Submit form ẩn
            const f = document.createElement('form');
            f.method = 'POST';
            f.innerHTML = `
                <input name="maPhieu" value="${id}">
                <input name="trangThai" value="Đã bàn giao">
                <input name="chiPhiLinhKien" value="0">
                <input name="chiPhiCong" value="0">
                <input name="cap_nhat_tt" value="1">
            `;
            document.body.appendChild(f);
            f.submit();
        }
    });
}

// ── Thông báo ──
let notifOpen = false;
function toggleNotifPanel() {
    notifOpen = !notifOpen;
    const panel = document.getElementById('notifPanel');
    panel.classList.toggle('open', notifOpen);
    if (notifOpen) loadNotif();
}

document.addEventListener('click', function(e) {
    if (!document.getElementById('notifPanel').contains(e.target) &&
        !document.getElementById('bellBtn').contains(e.target)) {
        document.getElementById('notifPanel').classList.remove('open');
        notifOpen = false;
    }
});

function loadNotif() {
    fetch('thong_bao_api.php?action=list&limit=20')
        .then(r=>r.json())
        .then(d=>{
            const list = document.getElementById('notifList');
            if (!d.items || d.items.length===0) {
                list.innerHTML='<div style="text-align:center;padding:28px;color:#94a3b8;font-size:13px"><i class="fas fa-check-circle" style="font-size:24px;display:block;margin-bottom:8px;color:#10b981"></i>Không có thông báo mới</div>';
                return;
            }
            list.innerHTML = d.items.map(tb => {
                const unread = tb.trangThai === 'chua_doc';
                const isPhanCong = tb.loai === 'phan_cong';
                const isNhacNho = tb.loai === 'nhac_nho';
                const ttMap = {chua_doc:'🔴 Chưa đọc',da_doc:'⚪ Đã đọc',da_chap_nhan:'✅ Đã chấp nhận',tu_choi:'❌ Đã từ chối'};
                
                // Xử lý nội dung hiển thị với xuống dòng
                let noiDungHtml = (tb.noiDung || '').replace(/\n/g,'<br>');
                // Nếu nội dung quá dài, cắt bớt
                if (noiDungHtml.length > 300) {
                    noiDungHtml = noiDungHtml.substring(0, 300) + '...';
                }
                
                return `<div class="notif-item ${unread?'unread':''}" id="tb-${tb.id}">
                    <div class="notif-title">${tb.tieuDe || ''}</div>
                    <div class="notif-sub" style="white-space:pre-wrap;font-size:11px;line-height:1.5;">${noiDungHtml}</div>
                    <div class="notif-time">${tb.thoiGian || ''} · ${ttMap[tb.trangThai]||tb.trangThai}</div>
                    ${(isPhanCong && unread) ? `<div class="notif-actions">
                        <button class="btn-chap-nhan" onclick="chapNhan(${tb.id},${tb.maPhieu},'${(tb.tenThietBi||'').replace(/'/g,"\\'")}')">✅ Chấp nhận</button>
                        <button class="btn-tu-choi" onclick="tuChoi(${tb.id},${tb.maPhieu})">❌ Từ chối</button>
                    </div>` : ''}
                    ${(isNhacNho && unread) ? `<div class="notif-actions">
                        <button class="btn-chap-nhan" onclick="docNotif(${tb.id})">📖 Đánh dấu đã đọc</button>
                    </div>` : ''}
                </div>`;
            }).join('');
        });
}

// Thêm hàm đánh dấu đã đọc cho thông báo thường
function docNotif(id) {
    const fd = new FormData();
    fd.append('action','doc'); 
    fd.append('id', id);
    fetch('thong_bao_api.php', {method:'POST', body:fd})
        .then(r => r.json())
        .then(() => {
            loadNotif();
            // Cập nhật badge
            fetch('thong_bao_api.php?action=count')
                .then(r => r.json())
                .then(d => {
                    let dot = document.getElementById('notifDot');
                    if (d.count > 0) {
                        if (!dot) {
                            dot = document.createElement('span');
                            dot.id = 'notifDot'; dot.className = 'notif-dot';
                            document.getElementById('bellBtn').appendChild(dot);
                        }
                        dot.textContent = d.count;
                        const sideNotif = document.querySelector('.notif-badge');
                        if (sideNotif) sideNotif.textContent = d.count;
                    } else {
                        if (dot) dot.remove();
                        const sideNotif = document.querySelector('.notif-badge');
                        if (sideNotif) sideNotif.remove();
                    }
                });
        });
}

function chapNhan(tbId, maPhieu, tenThietBi) {
    Swal.fire({
        title:'Chấp nhận phân công?',
        html:`Phiếu <strong>#SC-${maPhieu}</strong>${tenThietBi?' – '+tenThietBi:''} sẽ chuyển sang trạng thái <b>Đang sửa</b>.`,
        icon:'question',
        confirmButtonColor:'#059669',
        confirmButtonText:'✅ Chấp nhận',
        showCancelButton:true, cancelButtonText:'Hủy'
    }).then(r => {
        if (!r.isConfirmed) return;
        const fd = new FormData();
        fd.append('action','chap_nhan'); fd.append('id',tbId); fd.append('maPhieu',maPhieu);
        fetch('thong_bao_api.php',{method:'POST',body:fd})
            .then(r=>r.json())
            .then(d=>{
                Swal.fire({icon:d.ok?'success':'error',title:d.ok?'Thành công!':'Lỗi',text:d.msg,timer:2000,showConfirmButton:false})
                    .then(()=>location.reload());
            });
    });
}

function tuChoi(tbId, maPhieu) {
    Swal.fire({
        title:'Từ chối phân công?',
        input:'text', inputPlaceholder:'Lý do từ chối (tuỳ chọn)...',
        icon:'warning',
        confirmButtonColor:'#dc2626',
        confirmButtonText:'Từ chối',
        showCancelButton:true, cancelButtonText:'Hủy'
    }).then(r => {
        if (!r.isConfirmed) return;
        const fd = new FormData();
        fd.append('action','tu_choi'); fd.append('id',tbId); fd.append('maPhieu',maPhieu); fd.append('ly_do',r.value||'');
        fetch('thong_bao_api.php',{method:'POST',body:fd})
            .then(r=>r.json())
            .then(d=>{
                Swal.fire({icon:d.ok?'info':'error',title:d.ok?'Đã từ chối':'Lỗi',text:d.msg,timer:2000,showConfirmButton:false})
                    .then(()=>location.reload());
            });
    });
}

function docTatCa() {
    const fd = new FormData();
    fd.append('action','doc'); fd.append('id','0');
    fetch('thong_bao_api.php',{method:'POST',body:fd}).then(()=>loadNotif());
    const dot = document.getElementById('notifDot');
    if (dot) dot.remove();
}

// ── Modal chi tiết phiếu (giống hệt admin) ──
function moChiTiet(maPhieu) {
    document.getElementById('chiTietModal').classList.add('open');
    document.getElementById('modalTitle').textContent = 'Chi tiết phiếu #SC-' + String(maPhieu).padStart(5,'0');
    document.getElementById('modalBody').innerHTML = '<div style="text-align:center;padding:60px;color:#94a3b8"><i class="fas fa-spinner fa-spin fa-2x"></i></div>';

    fetch('chi_tiet_phieu_api.php?maPhieu=' + maPhieu)
        .then(r => r.json())
        .then(d => {
            if (!d.ok) { document.getElementById('modalBody').innerHTML = '<p style="color:#dc2626;padding:20px">Không tải được thông tin phiếu.</p>'; return; }
            const p   = d.phieu;
            const anh = d.anhArr || [];

            const fmtDate = v => {
                if (!v) return '—';
                const dt = new Date(v);
                return dt.toLocaleDateString('vi-VN', {day:'2-digit',month:'2-digit',year:'numeric'});
            };
            const fmtMoney = v => v && Number(v) > 0 ? Number(v).toLocaleString('vi-VN') + ' đ' : null;
            const esc = s => (s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

            // ── Timeline bước trạng thái ──
            const allSteps   = ['Tiếp nhận','Đang kiểm tra','Đang xử lý','Chờ linh kiện','Đã sửa xong','Đã bàn giao'];
            const stepIcons  = ['📥','🔍','⚙️','📦','✅','🚀'];
            const stepColors = ['#64748b','#3b82f6','#f59e0b','#ef4444','#10b981','#8b5cf6'];
            // Map trạng thái hệ thống sang timeline
            const ttMap = {
                'Chờ xử lý':'Tiếp nhận','Đang sửa':'Đang xử lý',
                'Chờ linh kiện':'Chờ linh kiện','Đã sửa xong':'Đã sửa xong',
                'Đã bàn giao':'Đã bàn giao','Không sửa được':'Đã sửa xong'
            };
            const trangThaiMapped = ttMap[p.trangThai] || p.trangThai;
            let stepIdx = allSteps.indexOf(trangThaiMapped);
            if (stepIdx < 0) stepIdx = 0;

            let timelineHtml = '';
            const lineColor = stepColors[stepIdx] || '#e2e8f0';
            timelineHtml += `<div style="position:relative;padding-left:28px;margin-bottom:20px;">
                <div style="position:absolute;left:10px;top:8px;bottom:8px;width:2px;background:linear-gradient(180deg,${lineColor},#e2e8f0);border-radius:2px;"></div>`;
            allSteps.forEach((step, si) => {
                const isPast    = si < stepIdx;
                const isCurrent = si === stepIdx;
                const dotColor  = isPast ? '#10b981' : (isCurrent ? stepColors[si] : '#e2e8f0');
                const textColor = isPast ? '#10b981' : (isCurrent ? stepColors[si] : '#cbd5e1');
                const dotSize   = isCurrent ? '20px' : '16px';
                const marginL   = isCurrent ? 'margin-left:-2px;' : '';
                timelineHtml += `<div style="position:relative;display:flex;align-items:center;gap:10px;padding:5px 0;${isCurrent?'margin:-2px 0;':''}">
                    <div style="position:absolute;left:-22px;width:${dotSize};height:${dotSize};border-radius:50%;background:${dotColor};border:3px solid ${isCurrent?stepColors[si]:(isPast?'#10b981':'#e2e8f0')};box-shadow:${isCurrent?'0 0 0 4px '+stepColors[si]+'22':'none'};display:flex;align-items:center;justify-content:center;font-size:${isCurrent?'9px':'8px'};${marginL}z-index:1;">
                        ${isPast ? '<span style="color:#fff;font-size:8px;">✓</span>' : ''}
                    </div>
                    <div style="font-size:${isCurrent?'13px':'12px'};font-weight:${isCurrent?'700':'400'};color:${textColor};">
                        ${stepIcons[si]} ${step}
                        ${isCurrent ? `<span style="background:${stepColors[si]};color:#fff;font-size:10px;padding:1px 7px;border-radius:10px;margin-left:6px;">Hiện tại</span>` : ''}
                    </div>
                </div>`;
            });
            timelineHtml += '</div>';

            // ── Thời gian ──
            const ngayNhanTs = p.ngayNhan ? new Date(p.ngayNhan).getTime() : null;
            const ngayTraTs  = p.ngayTraDuKien ? new Date(p.ngayTraDuKien).getTime() : (p.ngayTra ? new Date(p.ngayTra).getTime() : null);
            const homNayTs   = new Date().setHours(0,0,0,0);
            const soDaSua    = ngayNhanTs ? Math.floor((homNayTs - ngayNhanTs)/86400000) : 0;
            const soConLai   = ngayTraTs  ? Math.floor((ngayTraTs - homNayTs)/86400000) : null;
            const tongNgay   = (ngayNhanTs && ngayTraTs) ? Math.floor((ngayTraTs - ngayNhanTs)/86400000) : null;
            const pct        = (tongNgay && tongNgay > 0) ? Math.min(100, Math.max(0, Math.round(soDaSua/tongNgay*100))) : null;
            const isDone     = ['Đã sửa xong','Đã bàn giao'].includes(p.trangThai);
            const isQuaHan   = ngayTraTs && homNayTs > ngayTraTs && !isDone;

            const soConLaiTxt = isDone ? '✅ Hoàn thành' : soConLai === null ? '—' : soConLai < 0 ? `Quá ${Math.abs(soConLai)} ngày` : soConLai === 0 ? 'Hôm nay!' : `${soConLai} ngày`;
            const soConLaiColor = isDone ? '#059669' : soConLai === null ? '#94a3b8' : soConLai < 0 ? '#ef4444' : soConLai <= 1 ? '#d97706' : '#0f172a';
            const soConLaiBg    = isDone ? '#f0fdf4' : (soConLai !== null && soConLai < 0 ? '#fff1f2' : (soConLai !== null && soConLai <= 1 ? '#fff7ed' : '#f8fafc'));

            let progressHtml = '';
            if (pct !== null && !isDone) {
                const barColor = isQuaHan ? 'linear-gradient(90deg,#ef4444,#dc2626)' : (pct > 80 ? 'linear-gradient(90deg,#f59e0b,#d97706)' : 'linear-gradient(90deg,#10b981,#059669)');
                progressHtml = `<div style="margin-bottom:14px;">
                    <div style="display:flex;justify-content:space-between;font-size:11px;color:#64748b;margin-bottom:5px;">
                        <span>Tiến độ thời gian</span>
                        <span style="font-weight:700;color:${isQuaHan?'#ef4444':'#f59e0b'}">${pct}%${isQuaHan?' (Quá hạn!)':''}</span>
                    </div>
                    <div style="background:#e2e8f0;border-radius:20px;height:8px;overflow:hidden;">
                        <div style="width:${Math.min(100,pct)}%;height:100%;border-radius:20px;background:${barColor};"></div>
                    </div>
                </div>`;
            }

            // ── Chi phí ──
            const chiPhiGoc = Number(p.chi_phi_goc||0);
            const chiPhi    = Number(p.chiPhi||0);
            let chiPhiHtml = '';
            if (chiPhiGoc > 0 && chiPhiGoc !== chiPhi) {
                chiPhiHtml += `<div style="font-size:12px;color:#64748b;text-decoration:line-through;margin-bottom:2px;">Gốc: ${chiPhiGoc.toLocaleString('vi-VN')} đ</div>
                <div style="font-size:10px;color:#ef4444;margin-bottom:4px;">– Voucher giảm: ${(chiPhiGoc - chiPhi).toLocaleString('vi-VN')} đ</div>`;
            }
            chiPhiHtml += `<div style="font-size:22px;font-weight:800;color:#059669;">${chiPhi > 0 ? chiPhi.toLocaleString('vi-VN')+' đ' : '<span style="color:#94a3b8;font-size:15px;">Chưa xác định</span>'}</div>`;

            // ── Ảnh ──
            const groupLabels = {truoc:'📸 Trước sửa', sau:'✅ Sau sửa', loi:'⚠️ Lỗi / Hỏng', linh_kien:'🔩 Linh kiện', khac:'📎 Khác'};
            let anhHtml = '';
            if (anh.length === 0) {
                anhHtml = `<div style="border:2px dashed #e2e8f0;border-radius:12px;padding:40px 20px;text-align:center;">
                    <div style="font-size:36px;margin-bottom:10px;">📷</div>
                    <div style="color:#94a3b8;font-size:13px;">Chưa có ảnh đính kèm</div>
                </div>`;
            } else {
                // Nhóm ảnh theo loaiAnh
                const groups = {};
                anh.forEach(a => { const g = a.loaiAnh||'khac'; if (!groups[g]) groups[g]=[]; groups[g].push(a); });
                for (const [gKey, gAnhs] of Object.entries(groups)) {
                    const lbl = groupLabels[gKey] || gKey;
                    anhHtml += `<div style="margin-bottom:14px;">
                        <div style="font-size:11px;font-weight:600;color:#64748b;margin-bottom:7px;">${lbl} (${gAnhs.length})</div>
                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(80px,1fr));gap:6px;">`;
                    gAnhs.forEach(a => {
                        const src = a.duongDan.startsWith('http') ? a.duongDan : '../' + a.duongDan;
                        anhHtml += `<div style="position:relative;aspect-ratio:1;border-radius:10px;overflow:hidden;border:2px solid #e2e8f0;cursor:pointer;" onclick="xemAnhLon('${esc(a.duongDan)}')">
                            <img src="${esc(src)}" style="width:100%;height:100%;object-fit:cover;display:block;" onerror="this.parentElement.style.display='none'">
                            ${a.moTa ? `<div style="position:absolute;bottom:0;left:0;right:0;background:linear-gradient(transparent,rgba(0,0,0,.6));padding:4px 5px;font-size:9px;color:#fff;line-height:1.2;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;">${esc(a.moTa)}</div>` : ''}
                        </div>`;
                    });
                    anhHtml += '</div></div>';
                }
            }

            // ── KTV ──
            const ktvInitial = (p.kyThuatVien||'').charAt(0).toUpperCase();
            const ktvHtml = p.kyThuatVien
                ? `<span style="display:inline-flex;align-items:center;gap:5px;">
                    <span style="width:20px;height:20px;background:linear-gradient(135deg,#3b82f6,#2563eb);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:9px;font-weight:800;flex-shrink:0;">${ktvInitial}</span>
                    <strong style="color:#1e40af;">${esc(p.kyThuatVien)}</strong>
                  </span>`
                : '<span style="color:#f59e0b;font-weight:600;font-size:11px;">⚠ Chưa phân công</span>';

            // ── Thông tin khách hàng (thêm so với admin) ──
            const khachHang = [
                ['👤 Khách hàng', esc(p.tenKH)],
                ['📞 Điện thoại',  esc(p.soDienThoai||'—')],
                ['✉️ Email',        esc(p.email||'—')],
                ['📍 Địa chỉ',      esc(p.diaChi||'—')],
            ];
            const khHtml = khachHang.map(([lbl,val]) =>
                `<div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:5px;gap:8px;">
                    <span style="font-size:11px;color:#94a3b8;white-space:nowrap;">${lbl}</span>
                    <strong style="font-size:12px;color:#0f172a;text-align:right;">${val||'—'}</strong>
                </div>`
            ).join('');

            document.getElementById('modalTitle').textContent = `Chi tiết phiếu #SC-${String(maPhieu).padStart(5,'0')}`;
            document.getElementById('modalBody').innerHTML = `
            <div style="display:flex;flex-direction:column;gap:0;">

                <!-- Header gradient giống admin -->
                <div style="background:linear-gradient(135deg,#1c1917,#292524,#44403c);padding:20px 24px 16px;position:relative;overflow:hidden;margin:-1px -1px 0;">
                    <div style="position:absolute;inset:0;background:radial-gradient(ellipse at 80% 50%,rgba(251,191,36,.15),transparent 70%);pointer-events:none;"></div>
                    <div style="position:relative;z-index:1;display:flex;align-items:flex-start;gap:14px;">
                        <div style="width:50px;height:50px;background:linear-gradient(135deg,#fbbf24,#f59e0b);border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:24px;box-shadow:0 4px 14px rgba(251,191,36,.4);flex-shrink:0;">🔧</div>
                        <div>
                            <div style="font-size:18px;font-weight:800;color:#fff;">Phiếu sửa chữa <span style="color:#fbbf24;">#SC-${String(maPhieu).padStart(5,'0')}</span></div>
                            <div style="font-size:12px;color:rgba(255,255,255,.55);margin-top:3px;">
                                ${esc(p.tenThietBi||'—')} &nbsp;·&nbsp; Nhận: ${fmtDate(p.ngayNhan)}
                                ${isQuaHan ? '<span style="background:#ef4444;color:#fff;font-size:10px;padding:2px 8px;border-radius:10px;margin-left:8px;font-weight:700;">⚠️ QUÁ HẠN</span>' : ''}
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Body 2 cột -->
                <div style="display:grid;grid-template-columns:1fr 1fr;min-height:0;">

                    <!-- Cột trái -->
                    <div style="padding:22px 20px 22px 24px;border-right:1px solid #f1f5f9;">

                        <!-- Thông tin khách hàng -->
                        <div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;margin-bottom:10px;display:flex;align-items:center;gap:6px;">
                            <i class="fas fa-user"></i> Khách hàng
                            <span style="flex:1;height:1px;background:#e2e8f0;display:block;margin-left:6px;"></span>
                        </div>
                        <div style="background:#f8fafc;border-radius:10px;padding:12px 14px;margin-bottom:16px;">
                            ${khHtml}
                        </div>

                        <!-- Timeline -->
                        <div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;margin-bottom:12px;display:flex;align-items:center;gap:6px;">
                            <i class="fas fa-route"></i> Tiến trình xử lý
                            <span style="flex:1;height:1px;background:#e2e8f0;display:block;margin-left:6px;"></span>
                        </div>
                        ${timelineHtml}

                        <!-- Thời gian -->
                        <div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;margin-bottom:10px;display:flex;align-items:center;gap:6px;">
                            <i class="fas fa-clock"></i> Thời gian thực hiện
                            <span style="flex:1;height:1px;background:#e2e8f0;display:block;margin-left:6px;"></span>
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px;">
                            <div style="background:#f8fafc;border-radius:10px;padding:11px 13px;">
                                <div style="font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Ngày nhận</div>
                                <div style="font-size:15px;font-weight:700;color:#0f172a;margin-top:2px;">${fmtDate(p.ngayNhan)}</div>
                            </div>
                            <div style="background:${isQuaHan?'#fff1f2':'#f8fafc'};border-radius:10px;padding:11px 13px;${isQuaHan?'border:1px solid #fca5a5;':''}">
                                <div style="font-size:10px;color:${isQuaHan?'#ef4444':'#94a3b8'};text-transform:uppercase;letter-spacing:.05em;">Ngày trả${isDone?'':' (dự kiến)'}</div>
                                <div style="font-size:15px;font-weight:700;color:${isQuaHan?'#ef4444':'#0f172a'};margin-top:2px;">${fmtDate(p.ngayTraDuKien||p.ngayTra)}</div>
                            </div>
                            <div style="background:${soDaSua>7?'#fff7ed':'#f0fdf4'};border-radius:10px;padding:11px 13px;">
                                <div style="font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Đã sửa được</div>
                                <div style="font-size:15px;font-weight:700;color:${soDaSua>7?'#d97706':'#059669'};margin-top:2px;">${soDaSua} ngày</div>
                            </div>
                            <div style="background:${soConLaiBg};border-radius:10px;padding:11px 13px;">
                                <div style="font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">${isDone?'Kết quả':'Còn lại'}</div>
                                <div style="font-size:15px;font-weight:700;color:${soConLaiColor};margin-top:2px;">${soConLaiTxt}</div>
                            </div>
                        </div>
                        ${progressHtml}

                        <!-- Chi phí -->
                        <div style="background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:1.5px solid #86efac;border-radius:12px;padding:14px;margin-bottom:14px;">
                            <div style="font-size:11px;color:#166534;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px;">💰 Chi phí sửa chữa</div>
                            ${chiPhiHtml}
                        </div>

                        <!-- Mô tả lỗi -->
                        <div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                            <i class="fas fa-clipboard-list"></i> Mô tả / Ghi chú kỹ thuật
                            <span style="flex:1;height:1px;background:#e2e8f0;"></span>
                        </div>
                        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:13px;white-space:pre-wrap;font-size:13px;line-height:1.7;color:#374151;min-height:80px;">${p.moTaLoi ? esc(p.moTaLoi) : '<span style="color:#cbd5e1;font-style:italic;">Không có mô tả</span>'}</div>
                    </div>

                    <!-- Cột phải: ảnh + thông tin thêm -->
                    <div style="padding:22px 24px 22px 20px;background:#fafafa;">
                        <div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;margin-bottom:12px;display:flex;align-items:center;gap:6px;">
                            <i class="fas fa-images"></i> Ảnh thiết bị (${anh.length})
                            <span style="flex:1;height:1px;background:#e2e8f0;"></span>
                        </div>
                        ${anhHtml}

                        <!-- Thông tin thêm -->
                        <div style="margin-top:16px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px;font-size:12px;color:#64748b;">
                            <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                                <span>Mã phiếu</span>
                                <strong style="color:#f59e0b;">#SC-${String(maPhieu).padStart(5,'0')}</strong>
                            </div>
                            <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                                <span>Hạng KH</span>
                                <strong>${esc(p.loaiKhachHang||'—')}</strong>
                            </div>
                            <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                                <span>Số ảnh</span>
                                <strong>${anh.length} ảnh</strong>
                            </div>
                            ${p.ghiChu ? `<div style="display:flex;justify-content:space-between;margin-bottom:4px;"><span>Ghi chú</span><strong style="text-align:right;max-width:200px;">${esc(p.ghiChu)}</strong></div>` : ''}
                            <div style="height:1px;background:#f1f5f9;margin:8px 0;"></div>
                            <div style="display:flex;justify-content:space-between;align-items:center;">
                                <span><i class="fas fa-user-cog me-1" style="color:#3b82f6;"></i>Kỹ thuật viên</span>
                                ${ktvHtml}
                            </div>
                        </div>
                    </div>
                </div>
            </div>`;
        })
        .catch(err => { document.getElementById('modalBody').innerHTML = '<p style="color:#dc2626;padding:20px">Lỗi kết nối: ' + err + '</p>'; });
}

function dongModal() { document.getElementById('chiTietModal').classList.remove('open'); }
function xemAnhLon(src) {
    const full = src.startsWith('http') ? src : '../' + src;
    document.getElementById('anhLon').src = full;
    document.getElementById('anhModal').classList.add('open');
}
function dongAnhModal() { document.getElementById('anhModal').classList.remove('open'); }

// Poll thông báo mỗi 30s
setInterval(()=>{
    fetch('thong_bao_api.php?action=count')
        .then(r=>r.json())
        .then(d=>{
            let dot = document.getElementById('notifDot');
            if (d.count>0) {
                if (!dot) {
                    dot = document.createElement('span');
                    dot.id='notifDot'; dot.className='notif-dot';
                    document.getElementById('bellBtn').appendChild(dot);
                }
                dot.textContent=d.count;
            } else if (dot) dot.remove();
        });
}, 30000);
</script>
</body>
</html>