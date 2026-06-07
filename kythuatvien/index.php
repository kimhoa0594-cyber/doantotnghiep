<?php
// file: kythuatvien/index.php (trang_chu.php)
session_start();
require_once '../db.php';
date_default_timezone_set('Asia/Ho_Chi_Minh');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'technician') {
    header("Location: ../login.php");
    exit();
}

// ── NGUỒN SỰ THẬT DUY NHẤT cho tên KTV ──
$ktvName = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Kỹ thuật viên';
$display_name = $ktvName;
$ktv_email = $_SESSION['email'] ?? '';

// Lấy thông tin kỹ thuật viên từ DB
$ktv_info = null;
$check = $conn->query("SHOW TABLES LIKE 'ky_thuat_vien'");
if ($check && $check->num_rows > 0) {
    $stmt = $conn->prepare("SELECT * FROM ky_thuat_vien WHERE email = ? AND trangThai = 1 LIMIT 1");
    $stmt->bind_param("s", $ktv_email);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $ktv_info = $result->fetch_assoc();
        $display_name = $ktv_info['tenKTV'] ?? $display_name;
    }
    $stmt->close();
}

// ── ktvName dùng tenKTV từ DB (đây là giá trị lưu trong thong_bao_ktv) ──
// Nếu tìm thấy trong ky_thuat_vien thì dùng tenKTV, đảm bảo khớp với bảng thông báo
$ktvName = $ktv_info['tenKTV'] ?? $display_name;

// Xử lý cập nhật thông tin cá nhân
$update_msg = '';
$update_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cap_nhat_ttcn'])) {
    $tenKTV = trim($_POST['tenKTV'] ?? '');
    $soDienThoai = trim($_POST['soDienThoai'] ?? '');
    $chuyenMon = trim($_POST['chuyenMon'] ?? '');
    
    if (empty($tenKTV)) {
        $update_error = 'Họ tên không được để trống';
    } elseif (!empty($soDienThoai) && !preg_match('/^[0-9]{10}$/', $soDienThoai)) {
        $update_error = 'Số điện thoại phải đúng 10 chữ số';
    } else {
        if ($ktv_info) {
            $stmt = $conn->prepare("UPDATE ky_thuat_vien SET tenKTV=?, soDienThoai=?, chuyenMon=? WHERE email=?");
            $stmt->bind_param("ssss", $tenKTV, $soDienThoai, $chuyenMon, $ktv_email);
            if ($stmt->execute()) {
                $update_msg = 'Cập nhật thông tin thành công!';
                // Cập nhật lại session
                $_SESSION['fullname'] = $tenKTV;
                // Lấy lại thông tin mới
                $stmt2 = $conn->prepare("SELECT * FROM ky_thuat_vien WHERE email = ? AND trangThai = 1 LIMIT 1");
                $stmt2->bind_param("s", $ktv_email);
                $stmt2->execute();
                $ktv_info = $stmt2->get_result()->fetch_assoc();
                $display_name = $ktv_info['tenKTV'] ?? $tenKTV;
                $stmt2->close();
            } else {
                $update_error = 'Có lỗi xảy ra: ' . $stmt->error;
            }
            $stmt->close();
        } else {
            // Nếu chưa có trong bảng ky_thuat_vien, tạo mới
            $stmt = $conn->prepare("INSERT INTO ky_thuat_vien (tenKTV, email, soDienThoai, chuyenMon, trangThai, ngayTaoTK) VALUES (?, ?, ?, ?, 1, NOW())");
            $stmt->bind_param("ssss", $tenKTV, $ktv_email, $soDienThoai, $chuyenMon);
            if ($stmt->execute()) {
                $update_msg = 'Cập nhật thông tin thành công!';
                $_SESSION['fullname'] = $tenKTV;
                // Lấy lại thông tin mới
                $stmt2 = $conn->prepare("SELECT * FROM ky_thuat_vien WHERE email = ? AND trangThai = 1 LIMIT 1");
                $stmt2->bind_param("s", $ktv_email);
                $stmt2->execute();
                $ktv_info = $stmt2->get_result()->fetch_assoc();
                $display_name = $ktv_info['tenKTV'] ?? $tenKTV;
                $stmt2->close();
            } else {
                $update_error = 'Có lỗi xảy ra: ' . $stmt->error;
            }
            $stmt->close();
        }
    }
}

// ── Đảm bảo bảng thông báo tồn tại ──
$conn->query("CREATE TABLE IF NOT EXISTS `thong_bao_ktv` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `maPhieu`     INT NOT NULL,
    `kyThuatVien` VARCHAR(100) NOT NULL,
    `loai`        VARCHAR(30)  NOT NULL DEFAULT 'phan_cong',
    `tieuDe`      VARCHAR(200) NOT NULL,
    `noiDung`     TEXT         DEFAULT NULL,
    `trangThai`   VARCHAR(20)  NOT NULL DEFAULT 'chua_doc',
    `nguoiGui`    VARCHAR(100) DEFAULT NULL,
    `thoiGian`    DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ktv_tt (`kyThuatVien`, `trangThai`),
    INDEX idx_phieu (`maPhieu`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Thêm cột kyThuatVien vào phieu_sua_chua nếu chưa có
$colCheck = $conn->query("SHOW COLUMNS FROM phieu_sua_chua LIKE 'kyThuatVien'");
if ($colCheck && $colCheck->num_rows == 0) {
    $conn->query("ALTER TABLE phieu_sua_chua ADD COLUMN `kyThuatVien` VARCHAR(100) DEFAULT NULL COMMENT 'KTV được phân công'");
}

// Thống kê
$total_phieu = 0;
$check_phieu = $conn->query("SHOW TABLES LIKE 'phieu_sua_chua'");
if ($check_phieu && $check_phieu->num_rows > 0) {
    $total_phieu = $conn->query("SELECT COUNT(*) as c FROM phieu_sua_chua")->fetch_assoc()['c'];
}

$phieu_cho_xu_ly = 0;
if ($check_phieu && $check_phieu->num_rows > 0) {
    $r = $conn->query("SELECT COUNT(*) as c FROM phieu_sua_chua WHERE trangThai = 'Chờ xử lý' OR trangThai = 'cho_xu_ly'");
    if ($r) $phieu_cho_xu_ly = $r->fetch_assoc()['c'];
}

// Phiếu được phân công
$phieu_cua_toi = 0;
$dang_xu_ly_cua_toi = 0;
$hoan_thanh_cua_toi = 0;
$stmt_ktv = $conn->prepare("SELECT COUNT(*) as c FROM phieu_sua_chua WHERE kyThuatVien = ?");
$stmt_ktv->bind_param("s", $ktvName);
$stmt_ktv->execute();
$phieu_cua_toi = (int)$stmt_ktv->get_result()->fetch_assoc()['c'];
$stmt_ktv->close();

$stmt_ktv2 = $conn->prepare("SELECT COUNT(*) as c FROM phieu_sua_chua WHERE kyThuatVien = ? AND trangThai NOT IN ('Đã bàn giao','Không sửa được')");
$stmt_ktv2->bind_param("s", $ktvName);
$stmt_ktv2->execute();
$dang_xu_ly_cua_toi = (int)$stmt_ktv2->get_result()->fetch_assoc()['c'];
$stmt_ktv2->close();

$stmt_ktv3 = $conn->prepare("SELECT COUNT(*) as c FROM phieu_sua_chua WHERE kyThuatVien = ? AND trangThai IN ('Đã bàn giao','Đã sửa xong')");
$stmt_ktv3->bind_param("s", $ktvName);
$stmt_ktv3->execute();
$hoan_thanh_cua_toi = (int)$stmt_ktv3->get_result()->fetch_assoc()['c'];
$stmt_ktv3->close();

// Đếm thông báo chưa đọc
$pendingNotif = 0;
$stmtN = $conn->prepare("SELECT COUNT(*) as c FROM thong_bao_ktv WHERE kyThuatVien=? AND trangThai='chua_doc'");
$stmtN->bind_param("s", $ktvName);
$stmtN->execute();
$pendingNotif = (int)$stmtN->get_result()->fetch_assoc()['c'];
$stmtN->close();

// Lấy danh sách phiếu gần đây
$recentPhieu = [];
$stmtRecent = $conn->prepare("SELECT * FROM phieu_sua_chua WHERE kyThuatVien = ? ORDER BY ngayNhan DESC LIMIT 5");
$stmtRecent->bind_param("s", $ktvName);
$stmtRecent->execute();
$recentPhieu = $stmtRecent->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtRecent->close();

$hour = (int)date('H');
$greeting = $hour < 12 ? 'Chào buổi sáng' : ($hour < 18 ? 'Chào buổi chiều' : 'Chào buổi tối');
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quang Anh Tech - Kỹ thuật viên</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary: #0f766e;
            --primary-dark: #0d6460;
            --primary-light: #ccfbf1;
            --primary-bg: rgba(15,118,110,.08);
            --sidebar-bg: #0f172a;
            --sidebar-w: 260px;
            --surface: #ffffff;
            --surface-2: #f8fafc;
            --border: #e2e8f0;
            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #64748b;
            --radius: 16px;
            --radius-sm: 12px;
            --shadow-sm: 0 1px 2px rgba(0,0,0,.04), 0 1px 3px rgba(0,0,0,.06);
            --shadow-md: 0 4px 6px -2px rgba(0,0,0,.05), 0 10px 15px -3px rgba(0,0,0,.1);
            --shadow-lg: 0 20px 25px -5px rgba(0,0,0,.05), 0 8px 10px -6px rgba(0,0,0,.05);
            --transition: all .2s cubic-bezier(.4,0,.2,1);
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            font-family: 'Inter', sans-serif; 
            background: var(--surface-2); 
            color: var(--text-primary); 
            overflow-x: hidden; 
            font-size: 14px;
            line-height: 1.5;
        }

        /* Scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #e2e8f0; border-radius: 10px; }
        ::-webkit-scrollbar-thumb { background: #94a3b8; border-radius: 10px; }
        ::-webkit-scrollbar-thumb:hover { background: var(--primary); }

        /* Sidebar */
        .sidebar { 
            width: var(--sidebar-w); 
            height: 100vh; 
            position: fixed; 
            top: 0; 
            left: 0; 
            background: var(--sidebar-bg); 
            display: flex; 
            flex-direction: column; 
            z-index: 1000;
            backdrop-filter: blur(10px);
        }
        .sidebar-brand { padding: 24px 20px 20px; border-bottom: 1px solid rgba(255,255,255,.08); }
        .brand-logo { display: flex; align-items: center; gap: 12px; text-decoration: none; }
        .brand-icon { 
            width: 38px; height: 38px; 
            background: linear-gradient(135deg, var(--primary), var(--primary-dark)); 
            border-radius: 10px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 18px; 
            color: white; 
        }
        .brand-name { color: white; font-size: 16px; font-weight: 800; letter-spacing: -0.3px; }
        .brand-sub { color: #64748b; font-size: 9px; text-transform: uppercase; letter-spacing: 1.2px; margin-top: 2px; }
        
        .sidebar-user { padding: 16px 20px; border-bottom: 1px solid rgba(255,255,255,.08); display: flex; align-items: center; gap: 12px; }
        .user-avatar { 
            width: 42px; height: 42px; 
            border-radius: 12px; 
            background: linear-gradient(135deg, var(--primary), #14b8a6); 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            color: white; 
            font-weight: 700; 
            font-size: 16px; 
            flex-shrink: 0;
        }
        .user-info { flex: 1; min-width: 0; }
        .user-name { font-size: 13px; font-weight: 700; color: white; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .user-role { font-size: 10px; color: #94a3b8; margin-top: 2px; }
        
        .sidebar-nav { flex: 1; padding: 16px 12px; overflow-y: auto; }
        .nav-label { font-size: 10px; font-weight: 600; letter-spacing: 1px; text-transform: uppercase; color: #475569; padding: 8px 12px 4px; margin-top: 8px; }
        .nav-link-item { 
            display: flex; 
            align-items: center; 
            gap: 12px; 
            padding: 10px 12px; 
            border-radius: 10px; 
            color: #94a3b8; 
            text-decoration: none; 
            font-size: 13px; 
            font-weight: 500; 
            transition: var(--transition); 
            margin-bottom: 4px;
        }
        .nav-link-item i { width: 20px; font-size: 14px; text-align: center; }
        .nav-link-item:hover { background: rgba(255,255,255,.06); color: #e2e8f0; }
        .nav-link-item.active { background: rgba(15,118,110,.25); color: var(--primary-light); }
        .nav-link-item.active i { color: #5eead4; }
        .notif-badge { 
            background: #ef4444; 
            color: white; 
            border-radius: 20px; 
            font-size: 10px; 
            font-weight: 700; 
            padding: 2px 8px; 
            margin-left: auto; 
        }
        
        .sidebar-footer { padding: 16px 12px; border-top: 1px solid rgba(255,255,255,.08); }
        .logout-btn { 
            display: flex; 
            align-items: center; 
            gap: 12px; 
            padding: 10px 12px; 
            border-radius: 10px; 
            color: #f87171; 
            text-decoration: none; 
            font-size: 13px; 
            font-weight: 600; 
            transition: var(--transition); 
        }
        .logout-btn:hover { background: rgba(239,68,68,.1); color: #fca5a5; }

        /* Main */
        .main-content { margin-left: var(--sidebar-w); min-height: 100vh; }
        
        /* Topbar */
        .topbar { 
            background: rgba(255,255,255,.92); 
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border); 
            padding: 0 28px; 
            height: 64px; 
            display: flex; 
            align-items: center; 
            justify-content: space-between;
            position: sticky; 
            top: 0; 
            z-index: 99;
        }
        .page-title { font-size: 16px; font-weight: 700; color: var(--text-primary); }
        .topbar-right { display: flex; align-items: center; gap: 16px; }
        .live-clock { font-size: 12px; color: var(--text-muted); background: #f1f5f9; padding: 6px 14px; border-radius: 20px; font-weight: 500; }
        
        /* Bell notification */
        .notif-bell { 
            position: relative; 
            cursor: pointer; 
            padding: 8px; 
            border-radius: 10px; 
            background: #f1f5f9; 
            transition: var(--transition); 
            display: flex; 
            align-items: center; 
            gap: 6px;
            font-size: 13px;
            font-weight: 500;
        }
        .notif-bell:hover { background: #e2e8f0; }
        .notif-dot { 
            position: absolute; 
            top: -2px; 
            right: -2px; 
            background: #ef4444; 
            color: white; 
            border-radius: 50%; 
            font-size: 9px; 
            font-weight: 700; 
            min-width: 16px; 
            height: 16px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            border: 2px solid #f1f5f9; 
        }
        
        .notif-panel { 
            position: fixed; 
            top: 70px; 
            right: 20px; 
            width: 380px; 
            max-height: 80vh; 
            overflow-y: auto; 
            background: white; 
            border-radius: 16px; 
            border: 1px solid var(--border); 
            box-shadow: var(--shadow-lg); 
            z-index: 9999; 
            display: none; 
        }
        .notif-panel.open { display: block; }
        .notif-header { padding: 16px 18px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; font-weight: 700; }
        .notif-item { padding: 14px 18px; border-bottom: 1px solid #f8fafc; transition: var(--transition); cursor: pointer; }
        .notif-item:hover { background: #f8fafc; }
        .notif-item.unread { background: #eff6ff; border-left: 3px solid #3b82f6; }
        .notif-title { font-size: 13px; font-weight: 700; margin-bottom: 4px; }
        .notif-sub { font-size: 11px; color: var(--text-muted); line-height: 1.4; }
        .notif-time { font-size: 10px; color: #94a3b8; margin-top: 6px; }
        
        /* Page body */
        .page-body { padding: 28px 32px; }
        
        /* Hero greeting */
        .hero-section {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f766e 100%);
            border-radius: 20px;
            padding: 28px 32px;
            margin-bottom: 28px;
            color: white;
            position: relative;
            overflow: hidden;
        }
        .hero-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(255,255,255,.08) 0%, transparent 70%);
            border-radius: 50%;
        }
        .hero-content { position: relative; z-index: 1; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px; }
        .hero-text h1 { font-size: 24px; font-weight: 800; margin-bottom: 8px; letter-spacing: -0.3px; }
        .hero-text p { font-size: 13px; opacity: .75; margin-bottom: 0; }
        .hero-stats { display: flex; gap: 24px; }
        .hero-stat { text-align: center; background: rgba(255,255,255,.1); padding: 8px 20px; border-radius: 40px; backdrop-filter: blur(4px); }
        .hero-stat-value { font-size: 22px; font-weight: 800; }
        .hero-stat-label { font-size: 11px; opacity: .7; }
        
        /* Stats cards */
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 28px; }
        .stat-card { 
            background: white; 
            border-radius: 16px; 
            padding: 20px; 
            display: flex; 
            align-items: center; 
            gap: 16px; 
            border: 1px solid var(--border); 
            transition: var(--transition); 
            cursor: pointer; 
            box-shadow: var(--shadow-sm);
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); border-color: var(--primary-light); }
        .stat-icon { 
            width: 52px; height: 52px; 
            border-radius: 14px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 22px; 
            flex-shrink: 0; 
        }
        .stat-info { flex: 1; }
        .stat-value { font-size: 28px; font-weight: 800; color: var(--text-primary); line-height: 1.2; }
        .stat-label { font-size: 12px; color: var(--text-muted); margin-top: 4px; }
        
        /* Two columns */
        .two-columns { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 28px; }
        @media (max-width: 900px) { .two-columns { grid-template-columns: 1fr; } }
        
        /* Card */
        .card { background: white; border-radius: 20px; border: 1px solid var(--border); overflow: hidden; box-shadow: var(--shadow-sm); }
        .card-header { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        .card-title { font-size: 15px; font-weight: 700; display: flex; align-items: center; gap: 8px; }
        .card-body { padding: 20px; }
        
        /* Recent tickets list */
        .ticket-item { 
            display: flex; 
            align-items: center; 
            justify-content: space-between; 
            padding: 14px 0; 
            border-bottom: 1px solid #f1f5f9; 
            transition: var(--transition); 
        }
        .ticket-item:last-child { border-bottom: none; }
        .ticket-item:hover { background: #f8fafc; margin: 0 -10px; padding: 14px 10px; border-radius: 10px; }
        .ticket-info { flex: 1; }
        .ticket-id { font-weight: 700; color: var(--primary); font-size: 12px; font-family: monospace; }
        .ticket-device { font-size: 13px; font-weight: 600; margin-top: 4px; }
        .ticket-date { font-size: 11px; color: var(--text-muted); margin-top: 4px; }
        .ticket-status { 
            padding: 4px 10px; 
            border-radius: 20px; 
            font-size: 11px; 
            font-weight: 600; 
            white-space: nowrap; 
        }
        .status-warning { background: #fef3c7; color: #d97706; }
        .status-info { background: #dbeafe; color: #2563eb; }
        .status-success { background: #d1fae5; color: #059669; }
        .status-danger { background: #fee2e2; color: #dc2626; }
        
        /* Profile card */
        .profile-avatar { 
            width: 80px; height: 80px; 
            background: linear-gradient(135deg, var(--primary), #14b8a6); 
            border-radius: 20px; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            font-size: 32px; 
            font-weight: 700; 
            color: white; 
            margin-bottom: 16px;
        }
        .info-row { display: flex; padding: 10px 0; border-bottom: 1px solid #f1f5f9; }
        .info-label { width: 110px; font-size: 12px; color: var(--text-muted); font-weight: 500; }
        .info-value { flex: 1; font-size: 13px; font-weight: 500; color: var(--text-primary); }
        .edit-profile-btn { 
            width: 100%; 
            margin-top: 16px; 
            padding: 10px; 
            background: var(--primary-bg); 
            border: 1px solid var(--primary-light); 
            border-radius: 12px; 
            color: var(--primary); 
            font-weight: 600; 
            cursor: pointer; 
            transition: var(--transition); 
        }
        .edit-profile-btn:hover { background: var(--primary); color: white; border-color: var(--primary); }
        
        /* Quick actions */
        .quick-actions { display: flex; gap: 12px; flex-wrap: wrap; }
        .qa-btn { 
            display: inline-flex; 
            align-items: center; 
            gap: 8px; 
            padding: 10px 18px; 
            border-radius: 12px; 
            font-size: 13px; 
            font-weight: 600; 
            text-decoration: none; 
            transition: var(--transition); 
            cursor: pointer;
        }
        .qa-btn-primary { background: var(--primary); color: white; border: none; }
        .qa-btn-primary:hover { background: var(--primary-dark); transform: translateY(-1px); }
        .qa-btn-outline { background: white; border: 1px solid var(--border); color: var(--text-primary); }
        .qa-btn-outline:hover { border-color: var(--primary); color: var(--primary); }
        
        /* Modal */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); backdrop-filter: blur(4px); z-index: 1100; align-items: center; justify-content: center; }
        .modal-overlay.open { display: flex; }
        .modal-container { background: white; border-radius: 24px; width: 100%; max-width: 500px; max-height: 90vh; overflow: hidden; box-shadow: var(--shadow-lg); animation: modalSlideIn .3s ease; }
        @keyframes modalSlideIn { from { opacity: 0; transform: scale(.96); } to { opacity: 1; transform: scale(1); } }
        .modal-header { padding: 20px 24px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
        .modal-header h3 { font-size: 18px; font-weight: 700; margin: 0; }
        .modal-close { background: none; border: none; font-size: 20px; cursor: pointer; color: var(--text-muted); }
        .modal-body { padding: 24px; overflow-y: auto; }
        .modal-footer { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 12px; }
        
        .form-group { margin-bottom: 20px; }
        .form-label { font-size: 13px; font-weight: 600; margin-bottom: 6px; display: block; color: var(--text-primary); }
        .form-control { 
            width: 100%; 
            padding: 10px 14px; 
            border: 1.5px solid var(--border); 
            border-radius: 12px; 
            font-size: 13px; 
            font-family: inherit; 
            transition: var(--transition); 
        }
        .form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(15,118,110,.1); }
        .form-control.is-invalid { border-color: #dc2626; }
        .invalid-feedback { font-size: 11px; color: #dc2626; margin-top: 4px; }
        
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; border-radius: 12px; padding: 12px 16px; margin-bottom: 20px; font-size: 13px; display: flex; align-items: center; gap: 8px; }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; border-radius: 12px; padding: 12px 16px; margin-bottom: 20px; font-size: 13px; display: flex; align-items: center; gap: 8px; }
        
        .empty-state { text-align: center; padding: 48px 24px; color: var(--text-muted); }
        .empty-state i { font-size: 48px; opacity: .3; margin-bottom: 16px; display: block; }
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
        <div class="user-avatar"><?= strtoupper(mb_substr($display_name, 0, 1, 'UTF-8')) ?></div>
        <div class="user-info">
            <div class="user-name"><?= htmlspecialchars($display_name) ?></div>
            <div class="user-role">🔧 Kỹ thuật viên</div>
        </div>
    </div>
 <nav class="sidebar-nav">
    <div class="nav-label">Tổng quan</div>
    <a href="index.php" class="nav-link-item active">
        <i class="fas fa-home"></i> Trang chủ
    </a>
    <div class="nav-label">Quản lý</div>
    <a href="phieu_cua_toi.php" class="nav-link-item">
        <i class="fas fa-user-check"></i> Sửa chữa
        <?php if ($pendingNotif > 0): ?>
        <span class="notif-badge"><?= $pendingNotif ?></span>
        <?php endif; ?>
    </a>
    <a href="phieu_sua_chua.php" class="nav-link-item">
        <i class="fas fa-clipboard-list"></i> Tất cả phiếu
    </a>
    <a href="quan_ly_khach_hang.php" class="nav-link-item">
        <i class="fas fa-users"></i> Khách hàng
    </a>
    <a href="bao_hanh.php" class="nav-link-item">   <!-- THÊM DÒNG NÀY -->
        <i class="fas fa-shield-alt"></i> Bảo hành
    </a>
    <div class="nav-label">Tài khoản</div>
    <a href="doi_mat_khau.php" class="nav-link-item">
        <i class="fas fa-key"></i> Đổi mật khẩu
    </a>
</nav>
    <div class="sidebar-footer">
        <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Đăng xuất</a>
    </div>
</aside>

<!-- Main -->
<main class="main-content">
    <div class="topbar">
        <div class="page-title"><i class="fas fa-home me-2" style="color:var(--primary)"></i>Trang chủ</div>
        <div class="topbar-right">
            <div class="live-clock" id="live-clock"></div>
            <div class="notif-bell" id="bellBtn" onclick="toggleNotifPanel()">
                <i class="fas fa-bell"></i> 
                <?php if ($pendingNotif > 0): ?>
                <span class="notif-dot" id="notifDot"><?= $pendingNotif ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Notification Panel -->
    <div class="notif-panel" id="notifPanel">
        <div class="notif-header">
            <span><i class="fas fa-bell me-2" style="color:#f59e0b"></i>Thông báo</span>
            <button onclick="docTatCa()" style="background:none;border:none;font-size:11px;color:var(--primary);cursor:pointer;font-weight:600">Đánh dấu đã đọc</button>
        </div>
        <div id="notifList"><div style="text-align:center;padding:30px;color:#94a3b8"><i class="fas fa-spinner fa-spin"></i> Đang tải...</div></div>
    </div>

    <div class="page-body">
        
        <!-- Hero Greeting -->
        <div class="hero-section">
            <div class="hero-content">
                <div class="hero-text">
                    <h1><?= $greeting ?>, <?= htmlspecialchars($display_name) ?>! 👋</h1>
                    <p>Hôm nay là <?= date('d/m/Y') ?> · Hãy kiểm tra phiếu sửa chữa được phân công nhé!</p>
                </div>
                <div class="hero-stats">
                    <div class="hero-stat">
                        <div class="hero-stat-value"><?= $phieu_cua_toi ?></div>
                        <div class="hero-stat-label">Tổng phiếu</div>
                    </div>
                    <div class="hero-stat">
                        <div class="hero-stat-value"><?= $dang_xu_ly_cua_toi ?></div>
                        <div class="hero-stat-label">Đang xử lý</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alert chưa đọc -->
        <?php if ($pendingNotif > 0): ?>
        <div style="background:#fffbeb; border:1px solid #fcd34d; border-radius:14px; padding:14px 20px; margin-bottom:24px; display:flex; align-items:center; gap:12px; cursor:pointer;" onclick="toggleNotifPanel()">
            <span style="font-size:20px;">🔔</span>
            <div style="flex:1;">
                <div style="font-weight:700; color:#92400e;">Bạn có <?= $pendingNotif ?> thông báo phân công chưa xử lý!</div>
                <div style="font-size:12px; color:#b45309;">Nhấn để xem và chấp nhận / từ chối phiếu</div>
            </div>
            <i class="fas fa-chevron-right" style="color:#d97706"></i>
        </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card" onclick="location.href='phieu_cua_toi.php'">
                <div class="stat-icon" style="background:#dbeafe; color:#2563eb;"><i class="fas fa-clipboard-list"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?= $phieu_cua_toi ?></div>
                    <div class="stat-label">Tổng phiếu của tôi</div>
                </div>
            </div>
            <div class="stat-card" onclick="location.href='phieu_cua_toi.php?filter=pending'">
                <div class="stat-icon" style="background:#fef3c7; color:#d97706;"><i class="fas fa-wrench"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?= $dang_xu_ly_cua_toi ?></div>
                    <div class="stat-label">Đang xử lý</div>
                </div>
            </div>
            <div class="stat-card" onclick="location.href='phieu_cua_toi.php?filter=done'">
                <div class="stat-icon" style="background:#d1fae5; color:#059669;"><i class="fas fa-check-circle"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?= $hoan_thanh_cua_toi ?></div>
                    <div class="stat-label">Hoàn thành</div>
                </div>
            </div>
            <div class="stat-card" onclick="location.href='phieu_sua_chua.php'">
                <div class="stat-icon" style="background:#ede9fe; color:#7c3aed;"><i class="fas fa-chart-line"></i></div>
                <div class="stat-info">
                    <div class="stat-value"><?= $total_phieu ?></div>
                    <div class="stat-label">Tổng hệ thống</div>
                </div>
            </div>
        </div>

        <!-- Two Columns -->
        <div class="two-columns">
            
            <!-- Recent Tickets -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="fas fa-clock" style="color:var(--primary)"></i> Phiếu gần đây</div>
                    <a href="phieu_cua_toi.php" style="font-size:12px; color:var(--primary); text-decoration:none;">Xem tất cả <i class="fas fa-arrow-right ms-1"></i></a>
                </div>
                <div class="card-body">
                    <?php if (!empty($recentPhieu)): ?>
                        <?php foreach ($recentPhieu as $rp): 
                            $statusClass = match($rp['trangThai']) {
                                'Chờ xử lý' => 'status-warning',
                                'Đang sửa' => 'status-info',
                                'Chờ linh kiện' => 'status-warning',
                                'Đã sửa xong', 'Đã bàn giao' => 'status-success',
                                default => 'status-info'
                            };
                        ?>
                        <div class="ticket-item" onclick="location.href='phieu_cua_toi.php?search=<?= $rp['maPhieu'] ?>'">
                            <div class="ticket-info">
                                <div class="ticket-id">#SC-<?= str_pad($rp['maPhieu'], 5, '0', STR_PAD_LEFT) ?></div>
                                <div class="ticket-device"><?= htmlspecialchars(mb_substr($rp['tenThietBi'] ?? '—', 0, 40)) ?></div>
                                <div class="ticket-date"><i class="far fa-calendar-alt me-1"></i><?= date('d/m/Y', strtotime($rp['ngayNhan'])) ?></div>
                            </div>
                            <div class="ticket-status <?= $statusClass ?>"><?= htmlspecialchars($rp['trangThai']) ?></div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <p>Chưa có phiếu nào được phân công</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Profile Card -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="fas fa-user-circle" style="color:var(--primary)"></i> Thông tin cá nhân</div>
                    <button class="edit-profile-btn" style="width:auto; padding:6px 14px; margin:0;" onclick="openProfileModal()"><i class="fas fa-edit"></i> Chỉnh sửa</button>
                </div>
                <div class="card-body">
                    <div style="display:flex; align-items:center; gap:20px; margin-bottom:20px; flex-wrap:wrap;">
                        <div class="profile-avatar"><?= strtoupper(mb_substr($display_name, 0, 1, 'UTF-8')) ?></div>
                        <div>
                            <h3 style="font-size:18px; font-weight:800; margin-bottom:4px;"><?= htmlspecialchars($display_name) ?></h3>
                            <p style="color:var(--text-muted); font-size:12px; margin-bottom:0;">
                                <i class="fas fa-envelope me-1"></i> <?= htmlspecialchars($ktv_email ?: ($_SESSION['username'] ?? 'Chưa có email')) ?>
                            </p>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <div class="info-label">Họ và tên</div>
                        <div class="info-value"><?= htmlspecialchars($ktv_info['tenKTV'] ?? $display_name) ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Số điện thoại</div>
                        <div class="info-value"><?= htmlspecialchars($ktv_info['soDienThoai'] ?? '—') ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Chuyên môn</div>
                        <div class="info-value"><?= htmlspecialchars($ktv_info['chuyenMon'] ?? 'Chưa cập nhật') ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label">Ngày tham gia</div>
                        <div class="info-value"><?= isset($ktv_info['ngayTaoTK']) ? date('d/m/Y', strtotime($ktv_info['ngayTaoTK'])) : date('d/m/Y', strtotime($_SESSION['created_at'] ?? 'now')) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-bolt" style="color:var(--primary)"></i> Truy cập nhanh</div>
            </div>
            <div class="card-body">
                <div class="quick-actions">
                    <a href="phieu_cua_toi.php" class="qa-btn qa-btn-primary"><i class="fas fa-user-check"></i> Phiếu của tôi</a>
                    <a href="phieu_sua_chua.php" class="qa-btn qa-btn-outline"><i class="fas fa-clipboard-list"></i> Tất cả phiếu</a>
                    <a href="quan_ly_khach_hang.php" class="qa-btn qa-btn-outline"><i class="fas fa-users"></i> Khách hàng</a>
                    <a href="doi_mat_khau.php" class="qa-btn qa-btn-outline"><i class="fas fa-key"></i> Đổi mật khẩu</a>
                </div>
            </div>
        </div>
    </div>
</main>

<!-- Modal chỉnh sửa thông tin -->
<div class="modal-overlay" id="profileModal">
    <div class="modal-container">
        <div class="modal-header">
            <h3><i class="fas fa-user-edit me-2" style="color:var(--primary)"></i>Chỉnh sửa thông tin</h3>
            <button class="modal-close" onclick="closeProfileModal()">&times;</button>
        </div>
        <form method="POST" id="profileForm">
            <div class="modal-body">
                <?php if ($update_msg): ?>
                <div class="alert-success"><i class="fas fa-check-circle"></i> <?= $update_msg ?></div>
                <?php endif; ?>
                <?php if ($update_error): ?>
                <div class="alert-danger"><i class="fas fa-exclamation-circle"></i> <?= $update_error ?></div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label class="form-label">Họ và tên <span style="color:#dc2626">*</span></label>
                    <input type="text" name="tenKTV" class="form-control" id="edit_tenKTV" value="<?= htmlspecialchars($ktv_info['tenKTV'] ?? $display_name) ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" value="<?= htmlspecialchars($ktv_email ?: ($_SESSION['username'] ?? '')) ?>" disabled style="background:#f1f5f9">
                    <small class="text-muted" style="font-size:11px;">Email không thể thay đổi</small>
                </div>
                <div class="form-group">
                    <label class="form-label">Số điện thoại</label>
                    <input type="tel" name="soDienThoai" class="form-control" id="edit_soDienThoai" value="<?= htmlspecialchars($ktv_info['soDienThoai'] ?? '') ?>" placeholder="0912345678" maxlength="10">
                </div>
                <div class="form-group">
                    <label class="form-label">Chuyên môn</label>
                    <select name="chuyenMon" class="form-control" id="edit_chuyenMon">
                        <option value="">-- Chọn chuyên môn --</option>
                        <option value="Sửa chữa Laptop" <?= ($ktv_info['chuyenMon'] ?? '') == 'Sửa chữa Laptop' ? 'selected' : '' ?>>💻 Sửa chữa Laptop</option>
                        <option value="Sửa chữa PC" <?= ($ktv_info['chuyenMon'] ?? '') == 'Sửa chữa PC' ? 'selected' : '' ?>>🖥️ Sửa chữa PC</option>
                        <option value="Sửa chữa Macbook" <?= ($ktv_info['chuyenMon'] ?? '') == 'Sửa chữa Macbook' ? 'selected' : '' ?>>🍎 Sửa chữa Macbook</option>
                        <option value="Sửa chữa điện thoại" <?= ($ktv_info['chuyenMon'] ?? '') == 'Sửa chữa điện thoại' ? 'selected' : '' ?>>📱 Sửa chữa điện thoại</option>
                        <option value="Sửa chữa linh kiện" <?= ($ktv_info['chuyenMon'] ?? '') == 'Sửa chữa linh kiện' ? 'selected' : '' ?>>🔧 Sửa chữa linh kiện</option>
                        <option value="Tư vấn kỹ thuật" <?= ($ktv_info['chuyenMon'] ?? '') == 'Tư vấn kỹ thuật' ? 'selected' : '' ?>>💬 Tư vấn kỹ thuật</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="qa-btn qa-btn-outline" onclick="closeProfileModal()">Hủy</button>
                <button type="submit" name="cap_nhat_ttcn" class="qa-btn qa-btn-primary"><i class="fas fa-save"></i> Lưu thay đổi</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Clock
function updateClock() {
    const now = new Date();
    const pad = n => String(n).padStart(2, '0');
    const days = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];
    document.getElementById('live-clock').textContent = days[now.getDay()] + ', ' + pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());
}
updateClock();
setInterval(updateClock, 1000);

// ── Thông báo ──
let notifOpen = false;

function toggleNotifPanel() {
    notifOpen = !notifOpen;
    const panel = document.getElementById('notifPanel');
    panel.classList.toggle('open', notifOpen);
    if (notifOpen) loadNotif();
}

document.addEventListener('click', function(e) {
    const panel = document.getElementById('notifPanel');
    const bell = document.getElementById('bellBtn');
    if (panel && bell && !panel.contains(e.target) && !bell.contains(e.target)) {
        panel.classList.remove('open');
        notifOpen = false;
    }
});

function loadNotif() {
    fetch('thong_bao_api.php?action=list&limit=20')
        .then(r => r.json())
        .then(d => {
            const list = document.getElementById('notifList');
            if (!d.items || d.items.length === 0) {
                list.innerHTML = '<div style="text-align:center;padding:28px;color:#94a3b8"><i class="fas fa-check-circle" style="font-size:24px;display:block;margin-bottom:8px;color:#10b981"></i>Không có thông báo mới</div>';
                return;
            }
            const ttMap = {chua_doc: '🔴 Chưa đọc', da_doc: '⚪ Đã đọc', da_chap_nhan: '✅ Đã chấp nhận', tu_choi: '❌ Đã từ chối'};
            list.innerHTML = d.items.map(tb => {
                const unread = tb.trangThai === 'chua_doc';
                const isPhanCong = tb.loai === 'phan_cong';
                let noiDungHtml = (tb.noiDung || '').replace(/\n/g, '<br>');
                if (noiDungHtml.length > 200) noiDungHtml = noiDungHtml.substring(0, 200) + '...';
                return `<div class="notif-item ${unread ? 'unread' : ''}" id="tb-${tb.id}" onclick="viewNotif(${tb.id}, ${tb.maPhieu})">
                    <div class="notif-title">${tb.tieuDe || ''}</div>
                    <div class="notif-sub">${noiDungHtml}</div>
                    <div class="notif-time">${tb.thoiGianFormat || tb.thoiGian || ''} · ${ttMap[tb.trangThai] || tb.trangThai}</div>
                    ${(isPhanCong && unread) ? `<div class="notif-actions mt-2" onclick="event.stopPropagation()">
                        <button onclick="chapNhan(${tb.id},${tb.maPhieu},'')" style="background:#d1fae5;color:#065f46;border:none;border-radius:7px;padding:5px 12px;font-size:12px;font-weight:600;cursor:pointer;margin-right:6px;">✅ Chấp nhận</button>
                        <button onclick="tuChoi(${tb.id},${tb.maPhieu})" style="background:#fee2e2;color:#991b1b;border:none;border-radius:7px;padding:5px 12px;font-size:12px;font-weight:600;cursor:pointer;">❌ Từ chối</button>
                    </div>` : ''}
                </div>`;
            }).join('');
        });
}

function viewNotif(tbId, maPhieu) {
    window.location.href = `phieu_cua_toi.php?search=${maPhieu}`;
}

function chapNhan(tbId, maPhieu, tenThietBi) {
    Swal.fire({
        title: 'Chấp nhận phân công?',
        html: `Phiếu <strong>#SC-${maPhieu}</strong> sẽ chuyển sang <b>Đang sửa</b>.`,
        icon: 'question',
        confirmButtonColor: '#059669',
        confirmButtonText: '✅ Chấp nhận',
        showCancelButton: true
    }).then(r => {
        if (!r.isConfirmed) return;
        const fd = new FormData();
        fd.append('action', 'chap_nhan');
        fd.append('id', tbId);
        fd.append('maPhieu', maPhieu);
        fetch('thong_bao_api.php', {method: 'POST', body: fd})
            .then(r => r.json())
            .then(() => location.reload());
    });
}

function tuChoi(tbId, maPhieu) {
    Swal.fire({
        title: 'Từ chối phân công?',
        input: 'text',
        inputPlaceholder: 'Lý do từ chối (tuỳ chọn)...',
        icon: 'warning',
        confirmButtonColor: '#dc2626',
        confirmButtonText: 'Từ chối',
        showCancelButton: true
    }).then(r => {
        if (!r.isConfirmed) return;
        const fd = new FormData();
        fd.append('action', 'tu_choi');
        fd.append('id', tbId);
        fd.append('maPhieu', maPhieu);
        fd.append('ly_do', r.value || '');
        fetch('thong_bao_api.php', {method: 'POST', body: fd})
            .then(r => r.json())
            .then(() => location.reload());
    });
}

function docTatCa() {
    const fd = new FormData();
    fd.append('action', 'doc');
    fd.append('id', '0');
    fetch('thong_bao_api.php', {method: 'POST', body: fd}).then(() => loadNotif());
    const dot = document.getElementById('notifDot');
    if (dot) dot.remove();
}

// Poll thông báo
setInterval(() => {
    fetch('thong_bao_api.php?action=count')
        .then(r => r.json())
        .then(d => {
            let dot = document.getElementById('notifDot');
            if (d.count > 0) {
                if (!dot) {
                    dot = document.createElement('span');
                    dot.id = 'notifDot';
                    dot.className = 'notif-dot';
                    document.getElementById('bellBtn').appendChild(dot);
                }
                dot.textContent = d.count;
            } else if (dot) dot.remove();
        });
}, 30000);

// Profile Modal
function openProfileModal() {
    document.getElementById('profileModal').classList.add('open');
}

function closeProfileModal() {
    document.getElementById('profileModal').classList.remove('open');
}

// Validate phone
document.getElementById('edit_soDienThoai')?.addEventListener('input', function() {
    this.value = this.value.replace(/\D/g, '').slice(0, 10);
});
</script>
</body>
</html>