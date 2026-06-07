<?php
// file: nhanvien/quan_ly_khach_hang.php
session_start();
require_once '../db.php';

// Kiểm tra đăng nhập và role nhân viên
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'nhan_vien' && $_SESSION['role'] !== 'employee')) {
    header("Location: ../login.php");
    exit();
}

// Lấy thông tin nhân viên
$employee_name = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Nhân viên';
$ma_nv = $_SESSION['user_id'] ?? 0;

// ========================
// TẠO BẢNG & THÊM CỘT (nếu chưa có)
// ========================
$conn->query("CREATE TABLE IF NOT EXISTS `khach_hang` (
  `maKH` int(11) NOT NULL AUTO_INCREMENT,
  `tenKH` varchar(100) NOT NULL,
  `diaChi` varchar(255) DEFAULT NULL,
  `soDienThoai` varchar(15) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `ngaySinh` date DEFAULT NULL,
  `loaiKhachHang` varchar(50) DEFAULT 'Khách truy cập',
  `ngayDangKy` date DEFAULT current_timestamp(),
  `trangThai` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`maKH`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$checkCol = $conn->query("SHOW COLUMNS FROM khach_hang LIKE 'ngaySinh'");
if ($checkCol && $checkCol->num_rows === 0)
    $conn->query("ALTER TABLE khach_hang ADD COLUMN `ngaySinh` date DEFAULT NULL AFTER `email`");

// ========================
// THÊM / SỬA KHÁCH HÀNG (nhân viên được phép)
// ========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['luu_khach_hang'])) {
    $maKH    = $_POST['maKH'] ?? '';
    $tenKH   = $conn->real_escape_string(trim($_POST['tenKH']));
    $sdt     = $conn->real_escape_string(trim($_POST['soDienThoai']));
    $email   = $conn->real_escape_string(trim($_POST['email'] ?? ''));
    $diaChi  = $conn->real_escape_string(trim($_POST['diaChi'] ?? ''));
    $ngaySinh = !empty($_POST['ngaySinh']) ? "'".$conn->real_escape_string($_POST['ngaySinh'])."'" : 'NULL';
    $loai    = $conn->real_escape_string($_POST['loaiKhachHang']);
    $tt      = (int)$_POST['trangThai'];
    
    if (!empty($maKH)) {
        $conn->query("UPDATE khach_hang SET tenKH='$tenKH', soDienThoai='$sdt', email='$email',
            diaChi='$diaChi', ngaySinh=$ngaySinh, loaiKhachHang='$loai', trangThai=$tt
            WHERE maKH=".(int)$maKH);
        $_SESSION['success'] = 'Cập nhật thành công';
    } else {
        $ngayDK = !empty($_POST['ngayDangKy']) ? $conn->real_escape_string($_POST['ngayDangKy']) : date('Y-m-d');
        $conn->query("INSERT INTO khach_hang(tenKH, soDienThoai, email, diaChi, ngaySinh, loaiKhachHang, ngayDangKy, trangThai)
            VALUES('$tenKH','$sdt','$email','$diaChi',$ngaySinh,'$loai','$ngayDK',$tt)");
        $_SESSION['success'] = 'Thêm khách hàng mới thành công';
    }
    header("Location: quan_ly_khach_hang.php"); exit();
}

// ========================
// XÓA KHÁCH HÀNG (nhân viên được phép)
// ========================
if (isset($_GET['xoa_id'])) {
    $conn->query("DELETE FROM khach_hang WHERE maKH=" . (int)$_GET['xoa_id']);
    $_SESSION['success'] = 'Đã xóa khách hàng thành công';
    header("Location: quan_ly_khach_hang.php"); exit();
}

// ========================
// LỌC & TRUY VẤN
// ========================
$search      = isset($_GET['search'])      ? $conn->real_escape_string($_GET['search'])      : '';
$filter_loai = isset($_GET['filter_loai']) ? $conn->real_escape_string($_GET['filter_loai']) : '';
$filter_tt   = isset($_GET['filter_tt'])   ? $conn->real_escape_string($_GET['filter_tt'])   : '';

$sql = "SELECT kh.maKH, kh.tenKH, kh.soDienThoai, kh.email, kh.diaChi, kh.ngaySinh,
            kh.loaiKhachHang, kh.ngayDangKy, kh.trangThai,
            COUNT(DISTINCT dh.maDH) as tongLanMua,
            COUNT(DISTINCT psc.maPhieu) as tongLanSua,
            COALESCE(SUM(dh.tongTien),0) as tongChiTieu
        FROM khach_hang kh
        LEFT JOIN don_hang dh ON kh.maKH = dh.maKH
        LEFT JOIN phieu_sua_chua psc ON kh.maKH = psc.maKH
        WHERE 1=1";
if ($search)      $sql .= " AND (kh.tenKH LIKE '%$search%' OR kh.soDienThoai LIKE '%$search%' OR kh.email LIKE '%$search%')";
if ($filter_loai) $sql .= " AND kh.loaiKhachHang = '$filter_loai'";
if ($filter_tt !== '') $sql .= " AND kh.trangThai = '$filter_tt'";
$sql .= " GROUP BY kh.maKH ORDER BY kh.maKH DESC";
$result = $conn->query($sql);

// Thống kê
$stats = $conn->query("SELECT COUNT(*) as tong, SUM(trangThai=1) as hoat_dong,
    SUM(trangThai=0) as da_khoa, SUM(loaiKhachHang='Trung thành') as vip FROM khach_hang")->fetch_assoc();

// Khách hàng chưa cập nhật đủ thông tin
$incompleteRes = $conn->query(
    "SELECT maKH, tenKH, ngayDangKy, soDienThoai, ngaySinh, diaChi, email 
     FROM khach_hang
     WHERE trangThai = 1
       AND (soDienThoai IS NULL OR soDienThoai = '' OR
            ngaySinh    IS NULL OR
            diaChi      IS NULL OR diaChi = '' OR
            email       IS NULL OR email = '')
     ORDER BY ngayDangKy DESC"
);
$incompleteList = [];
while ($ic = $incompleteRes->fetch_assoc()) $incompleteList[] = $ic;

// Sinh nhật hôm nay
$todayMD = date('m-d');
$bdayRes = $conn->query("SELECT maKH, tenKH, ngaySinh FROM khach_hang
    WHERE DATE_FORMAT(ngaySinh,'%m-%d')='$todayMD' AND ngaySinh IS NOT NULL AND trangThai=1");
$birthdays = [];
while ($b = $bdayRes->fetch_assoc()) $birthdays[] = $b;

$old = $_SESSION['old_data'] ?? [];
$formErrors = $_SESSION['errors'] ?? [];
unset($_SESSION['old_data'], $_SESSION['errors']);

// Helper: avatar color
function avatarColor($name) {
    $colors = [
        ['bg'=>'#d1fae5','fg'=>'#065f46'],['bg'=>'#dbeafe','fg'=>'#1e40af'],
        ['bg'=>'#ede9fe','fg'=>'#4c1d95'],['bg'=>'#fef3c7','fg'=>'#78350f'],
        ['bg'=>'#fee2e2','fg'=>'#7f1d1d'],['bg'=>'#e0f2fe','fg'=>'#0c4a6e'],
        ['bg'=>'#fce7f3','fg'=>'#831843'],['bg'=>'#f0fdf4','fg'=>'#14532d'],
    ];
    $idx = abs(crc32($name)) % count($colors);
    return $colors[$idx];
}
function avatarInitials($name) {
    $parts = preg_split('/\s+/', trim($name));
    if (count($parts) >= 2) return mb_strtoupper(mb_substr($parts[0],0,1).mb_substr(end($parts),0,1));
    return mb_strtoupper(mb_substr($name,0,2));
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Quản Lý Khách Hàng — QA Tech (Nhân viên)</title>
<link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
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
*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
body {
    font-family: 'Be Vietnam Pro', sans-serif;
    background: #f1f5f9;
    color: var(--text-primary);
    min-height: 100vh;
}
::-webkit-scrollbar { width: 5px; }
::-webkit-scrollbar-track { background: #e2e8f0; }
::-webkit-scrollbar-thumb { background: var(--blue-primary); border-radius: 99px; }

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
    background: linear-gradient(135deg, var(--blue-primary), #3b82f6);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; color: white;
    flex-shrink: 0;
}
.brand-text { line-height: 1.2; }
.brand-name { font-size: 15px; font-weight: 800; color: #fff; }
.brand-sub  { font-size: 10px; font-weight: 500; color: #64748b; text-transform: uppercase; letter-spacing: 1.2px; }
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
}
.logout-btn:hover { background: rgba(239,68,68,.1); color: #fca5a5; }

/* ── MAIN ── */
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
.topbar-left { display: flex; align-items: center; gap: 12px; }
.page-title { font-size: 15px; font-weight: 700; color: var(--text-primary); }
.breadcrumb-sep { color: var(--text-muted); font-size: 13px; }
.topbar-right { display: flex; align-items: center; gap: 16px; }
.topbar-time {
    font-family: monospace;
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
}
.avatar-img {
    width: 32px; height: 32px;
    border-radius: 50%;
    object-fit: cover;
}
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

/* Filter bar */
.filter-bar {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px 20px;
    margin-bottom: 20px;
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: flex-end;
}
.filter-group { display: flex; flex-direction: column; gap: 5px; }
.filter-group label { font-size: 11px; font-weight: 700; text-transform: uppercase; color: var(--text-muted); letter-spacing: 0.5px; }
.filter-group input, .filter-group select {
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-family: inherit;
    font-size: 13px;
    min-width: 160px;
    outline: none;
}
.filter-group input:focus, .filter-group select:focus { border-color: var(--blue-primary); }
.btn-filter {
    background: var(--blue-primary);
    color: #fff;
    border: none;
    padding: 8px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
}
.btn-filter:hover { background: var(--blue-dark); }
.btn-clear {
    background: transparent;
    border: 1px solid var(--border);
    padding: 8px 16px;
    border-radius: 8px;
    font-size: 13px;
    cursor: pointer;
    text-decoration: none;
    color: var(--text-muted);
    display: flex;
    align-items: center;
    gap: 6px;
}
.btn-clear:hover { color: var(--blue-primary); border-color: var(--blue-primary); }

/* Table */
.tbl-wrap {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
}
.tbl-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: #fafbfc;
}
.tbl-title { font-weight: 700; font-size: 14px; display: flex; align-items: center; gap: 8px; }
.cnt-badge {
    background: #e2e8f0;
    padding: 2px 8px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
}
.btn-add {
    background: var(--blue-primary);
    color: #fff;
    border: none;
    padding: 8px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
}
.btn-add:hover { background: var(--blue-dark); }

table { width: 100%; border-collapse: collapse; }
thead th {
    padding: 12px 16px;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    color: var(--text-muted);
    border-bottom: 1px solid var(--border);
    background: #f8fafc;
}
tbody td {
    padding: 14px 16px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}
tbody tr:hover { background: #f8fafc; }

.avatar-wrap {
    display: flex;
    align-items: center;
    gap: 10px;
}
.avatar {
    width: 36px; height: 36px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 12px;
}
.cust-name { font-weight: 700; font-size: 13.5px; }
.cust-meta { font-size: 11px; color: var(--text-muted); margin-top: 2px; display: flex; gap: 8px; }

.badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
}
.b-visitor  { background: #f1f5f9; color: #475569; }
.b-interest { background: #dbeafe; color: #1d4ed8; }
.b-used     { background: #d1fae5; color: #065f46; }
.b-return   { background: #ede9fe; color: #5b21b6; }
.b-loyal    { background: #fef3c7; color: #92400e; }
.b-vip      { background: #fed7aa; color: #9a3412; }
.b-inactive { background: #fee2e2; color: #991b1b; }

.status-dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    display: inline-block;
    margin-right: 5px;
}
.dot-on { background: #059669; box-shadow: 0 0 4px #059669; }
.dot-off { background: #ef4444; }

.action-btns { display: flex; gap: 6px; }
.action-btn {
    width: 30px; height: 30px;
    border-radius: 7px;
    border: 1px solid var(--border);
    background: #fff;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    color: var(--text-muted);
    text-decoration: none;
}
.action-btn:hover { background: var(--blue-light); color: var(--blue-primary); border-color: #93c5fd; }
.action-btn.del:hover { background: #fee2e2; color: #ef4444; border-color: #fca5a5; }

.empty-cell { text-align: center; padding: 60px !important; }
.empty-icon { font-size: 48px; opacity: 0.3; margin-bottom: 12px; }
.empty-text { font-size: 14px; color: var(--text-muted); }

/* Alert */
.alert {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 18px;
    border-radius: 12px;
    margin-bottom: 20px;
}
.alert-success {
    background: #d1fae5;
    border: 1px solid #86efac;
    color: #065f46;
}

/* Incomplete banner */
.incomplete-banner {
    background: linear-gradient(135deg, #fffbeb, #fef3c7);
    border: 1.5px solid #f59e0b;
    border-left: 5px solid #d97706;
    border-radius: 14px;
    margin-bottom: 20px;
    overflow: hidden;
}
.banner-header {
    padding: 14px 20px 10px;
    border-bottom: 1px solid rgba(217,119,6,.2);
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.banner-title { font-weight: 800; color: #92400e; font-size: 14px; }
.banner-list { padding: 12px 20px 14px; max-height: 260px; overflow-y: auto; }
.incomplete-item {
    background: #fff;
    border: 1.5px solid rgba(217,119,6,.2);
    border-radius: 10px;
    padding: 10px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 8px;
}
.incomplete-info { display: flex; align-items: center; gap: 12px; flex: 1; }
.incomplete-avatar {
    width: 36px; height: 36px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 12px;
}
.incomplete-name { font-weight: 700; color: #111827; font-size: 13px; }
.incomplete-missing {
    font-size: 11px;
    color: #991b1b;
    background: rgba(220,38,38,.08);
    padding: 2px 8px;
    border-radius: 99px;
    margin-left: 6px;
}
.btn-warning {
    background: #d97706;
    color: #fff;
    border: none;
    padding: 6px 14px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
}
.btn-warning:hover { background: #b45309; }

/* Modal */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.4);
    backdrop-filter: blur(4px);
    z-index: 2000;
    align-items: center;
    justify-content: center;
}
.modal-overlay.open { display: flex; }
.modal-box {
    background: #fff;
    border-radius: 20px;
    width: 100%;
    max-width: 700px;
    max-height: 90vh;
    overflow: hidden;
    box-shadow: 0 24px 60px rgba(0,0,0,.2);
}
.modal-header {
    padding: 20px 24px;
    background: linear-gradient(135deg, var(--blue-primary), var(--blue-dark));
    display: flex;
    align-items: center;
    gap: 12px;
}
.modal-icon {
    width: 44px; height: 44px;
    background: rgba(255,255,255,.2);
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: #fff;
}
.modal-title h2 { font-size: 18px; font-weight: 800; color: #fff; }
.modal-title p { font-size: 12px; color: rgba(255,255,255,.7); margin-top: 2px; }
.modal-close {
    margin-left: auto;
    width: 32px; height: 32px;
    background: rgba(255,255,255,.15);
    border: none;
    border-radius: 8px;
    color: #fff;
    cursor: pointer;
}
.modal-body { padding: 24px; overflow-y: auto; max-height: calc(90vh - 140px); }
.modal-footer {
    padding: 16px 24px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-full { grid-column: 1/-1; }
.field { display: flex; flex-direction: column; gap: 5px; }
.field label { font-size: 12px; font-weight: 600; color: var(--text-primary); }
.field input, .field select {
    padding: 9px 12px;
    border: 1.5px solid #e2e8f0;
    border-radius: 9px;
    font-family: inherit;
    font-size: 13px;
}
.field input:focus, .field select:focus {
    border-color: var(--blue-primary);
    outline: none;
}
.form-hint { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
.btn-cancel {
    background: transparent;
    border: 1.5px solid #e2e8f0;
    padding: 9px 20px;
    border-radius: 9px;
    font-weight: 600;
    cursor: pointer;
}
.btn-save {
    background: var(--blue-primary);
    color: #fff;
    border: none;
    padding: 9px 24px;
    border-radius: 9px;
    font-weight: 700;
    cursor: pointer;
}
.btn-save:hover { background: var(--blue-dark); }

@media (max-width: 1024px) {
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 768px) {
    .sidebar { transform: translateX(-100%); }
    .main { margin-left: 0; }
    .main { padding: 20px 16px; }
}
</style>
</head>
<body>

<!-- SIDEBAR -->
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
            <div class="nav-icon"><i class="fas fa-chart-pie"></i></div>
            Trang chủ
        </a>
        <div class="nav-section-label">Quản lý</div>
        <a href="quan_ly_khach_hang.php" class="nav-link-item active">
            <div class="nav-icon"><i class="fas fa-users"></i></div>
            Khách hàng
        </a>
        <div class="nav-section-label">Nội dung</div>
        <a href="quan_ly_bai_viet.php" class="nav-link-item">
            <div class="nav-icon"><i class="fas fa-newspaper"></i></div>
            Quản lý bài viết
        </a>
        <a href="dang_bai_moi.php" class="nav-link-item">
            <div class="nav-icon"><i class="fas fa-pen-fancy"></i></div>
            Đăng bài mới
        </a>
        <div class="nav-section-label">Marketing</div>
        <a href="khuyen_mai.php" class="nav-link-item">
            <div class="nav-icon"><i class="fas fa-percentage"></i></div>
            Khuyến mãi
        </a>
        <div class="nav-section-label">Cá nhân</div>
        <a href="thong_tin_ca_nhan.php" class="nav-link-item">
            <div class="nav-icon"><i class="fas fa-user-circle"></i></div>
            Thông tin cá nhân
        </a>
        <a href="doi_mat_khau.php" class="nav-link-item">
            <div class="nav-icon"><i class="fas fa-key"></i></div>
            Đổi mật khẩu
        </a>
    </nav>
    <div class="sidebar-footer">
        <a href="../logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i>
            Đăng xuất
        </a>
    </div>
</aside>

<!-- MAIN -->
<main class="main">
    <!-- Topbar -->
    <div class="topbar">
        <div class="topbar-left">
            <span class="page-title">Quản lý khách hàng</span>
            <span class="breadcrumb-sep">/</span>
            <span class="breadcrumb-item">Danh sách</span>
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
    </div>

    <!-- Page Header -->
    <div class="page-header">
        <h1>Quản lý khách hàng</h1>
        <p>Xem, thêm, sửa và quản lý thông tin khách hàng</p>
    </div>

    <!-- Alert success -->
    <?php if(isset($_SESSION['success'])): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <span><?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></span>
    </div>
    <?php endif; ?>

    <!-- Banner: Khách hàng chưa cập nhật thông tin -->
    <?php if (!empty($incompleteList)): ?>
    <div class="incomplete-banner" id="incompleteBanner">
        <div class="banner-header">
            <div class="banner-title">
                <i class="fas fa-exclamation-triangle"></i> <?= count($incompleteList) ?> khách hàng chưa cập nhật đầy đủ thông tin
            </div>
            <button onclick="document.getElementById('incompleteBanner').style.display='none'" 
                style="background:rgba(217,119,6,.15); border:none; width:28px; height:28px; border-radius:7px; cursor:pointer;">
                ✕
            </button>
        </div>
        <div class="banner-list">
            <?php foreach($incompleteList as $ic):
                $missing = [];
                if (empty($ic['soDienThoai'])) $missing[] = 'SĐT';
                if (empty($ic['ngaySinh'])) $missing[] = 'Ngày sinh';
                if (empty($ic['diaChi'])) $missing[] = 'Địa chỉ';
                if (empty($ic['email'])) $missing[] = 'Email';
                $avc = avatarColor($ic['tenKH']);
                $avi = avatarInitials($ic['tenKH']);
            ?>
            <div class="incomplete-item">
                <div class="incomplete-info">
                    <div class="incomplete-avatar" style="background:<?= $avc['bg'] ?>;color:<?= $avc['fg'] ?>;"><?= $avi ?></div>
                    <div>
                        <div class="incomplete-name"><?= htmlspecialchars($ic['tenKH']) ?> 
                            <span class="incomplete-missing"><?= implode(', ', $missing) ?></span>
                        </div>
                        <div style="font-size: 11px; color: #78350f;">Đăng ký: <?= date('d/m/Y', strtotime($ic['ngayDangKy'])) ?></div>
                    </div>
                </div>
                <button class="btn-warning" onclick="openEditModal(<?= $ic['maKH'] ?>)">
                    <i class="fas fa-pen"></i> Cập nhật
                </button>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fas fa-users"></i></div>
            <div><div class="stat-value"><?= number_format($stats['tong'] ?? 0) ?></div><div class="stat-label">Tổng khách hàng</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green"><i class="fas fa-user-check"></i></div>
            <div><div class="stat-value"><?= number_format($stats['hoat_dong'] ?? 0) ?></div><div class="stat-label">Đang hoạt động</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon red"><i class="fas fa-user-slash"></i></div>
            <div><div class="stat-value"><?= number_format($stats['da_khoa'] ?? 0) ?></div><div class="stat-label">Đã khóa</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon amber"><i class="fas fa-gem"></i></div>
            <div><div class="stat-value"><?= number_format($stats['vip'] ?? 0) ?></div><div class="stat-label">Khách VIP</div></div>
        </div>
    </div>

    <!-- Filter Bar -->
    <form method="GET" class="filter-bar">
        <div class="filter-group" style="flex:2;">
            <label><i class="fas fa-search"></i> Tìm kiếm</label>
            <input type="text" name="search" placeholder="Tên, SĐT, email..." value="<?= htmlspecialchars($search) ?>">
        </div>
        <div class="filter-group">
            <label><i class="fas fa-tag"></i> Giai đoạn</label>
            <select name="filter_loai">
                <option value="">Tất cả</option>
                <option value="Khách truy cập" <?= $filter_loai=='Khách truy cập' ? 'selected' : '' ?>>🌐 Khách truy cập</option>
                <option value="Quan tâm" <?= $filter_loai=='Quan tâm' ? 'selected' : '' ?>>❤️ Quan tâm</option>
                <option value="Đã sử dụng" <?= $filter_loai=='Đã sử dụng' ? 'selected' : '' ?>>✅ Đã sử dụng</option>
                <option value="Quay lại" <?= $filter_loai=='Quay lại' ? 'selected' : '' ?>>🔄 Quay lại</option>
                <option value="Thân thiết" <?= $filter_loai=='Thân thiết' ? 'selected' : '' ?>>⭐ Thân thiết</option>
                <option value="Trung thành" <?= $filter_loai=='Trung thành' ? 'selected' : '' ?>>💎 Trung thành</option>
                <option value="Ngừng sử dụng" <?= $filter_loai=='Ngừng sử dụng' ? 'selected' : '' ?>>⏸ Ngừng sử dụng</option>
            </select>
        </div>
        <div class="filter-group">
            <label><i class="fas fa-toggle-on"></i> Trạng thái</label>
            <select name="filter_tt">
                <option value="">Tất cả</option>
                <option value="1" <?= $filter_tt==='1' ? 'selected' : '' ?>>Hoạt động</option>
                <option value="0" <?= $filter_tt==='0' ? 'selected' : '' ?>>Đã khóa</option>
            </select>
        </div>
        <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Lọc</button>
        <a href="quan_ly_khach_hang.php" class="btn-clear"><i class="fas fa-times"></i> Xóa lọc</a>
    </form>

    <!-- Table -->
    <div class="tbl-wrap">
        <div class="tbl-header">
            <div class="tbl-title">
                <i class="fas fa-users" style="color:var(--blue-primary);"></i>
                Danh sách khách hàng
                <?php if($result): ?><span class="cnt-badge"><?= $result->num_rows ?> khách hàng</span><?php endif; ?>
            </div>
            <button class="btn-add" onclick="openAddModal()"><i class="fas fa-plus"></i> Thêm khách hàng</button>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Mã KH</th>
                    <th>Khách hàng</th>
                    <th>Ngày sinh</th>
                    <th>Giai đoạn</th>
                    <th>Giao dịch</th>
                    <th>Ngày ĐK</th>
                    <th>Thao tác</th>
                </tr>
            </thead>
            <tbody>
            <?php if($result && $result->num_rows > 0):
                $badgeMap = [
                    'Khách truy cập' => ['b-visitor',  '🌐'],
                    'Quan tâm'       => ['b-interest', '❤️'],
                    'Đã sử dụng'     => ['b-used',     '✅'],
                    'Quay lại'       => ['b-return',   '🔄'],
                    'Thân thiết'     => ['b-loyal',    '⭐'],
                    'Trung thành'    => ['b-vip',      '💎'],
                    'Ngừng sử dụng'  => ['b-inactive', '⏸'],
                ];
                while($row = $result->fetch_assoc()):
                    $badge = $badgeMap[$row['loaiKhachHang']] ?? ['b-visitor', '🌐'];
                    $avc = avatarColor($row['tenKH']);
                    $avi = avatarInitials($row['tenKH']);
                    $chiTieu = number_format($row['tongChiTieu'] ?? 0, 0, ',', '.');
            ?>
                <tr>
                    <td style="font-family:monospace; font-weight:600;">#<?= $row['maKH'] ?></td>
                    <td>
                        <div class="avatar-wrap">
                            <div class="avatar" style="background:<?= $avc['bg'] ?>;color:<?= $avc['fg'] ?>;"><?= $avi ?></div>
                            <div>
                                <div class="cust-name"><?= htmlspecialchars($row['tenKH']) ?></div>
                                <div class="cust-meta">
                                    <span><i class="fas fa-phone-alt"></i> <?= htmlspecialchars($row['soDienThoai'] ?? '—') ?></span>
                                    <?php if($row['email']): ?>
                                    <span><i class="fas fa-envelope"></i> <?= htmlspecialchars($row['email']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php if(!empty($row['ngaySinh'])): ?>
                        <?= date('d/m/Y', strtotime($row['ngaySinh'])) ?>
                        <?php else: ?>
                        <span style="color:var(--text-muted);">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?= $badge[0] ?>"><?= $badge[1] ?> <?= htmlspecialchars($row['loaiKhachHang']) ?></span>
                        <div style="margin-top: 5px;">
                            <span class="status-dot <?= $row['trangThai'] ? 'dot-on' : 'dot-off' ?>"></span>
                            <?= $row['trangThai'] ? 'Hoạt động' : 'Đã khóa' ?>
                        </div>
                    </td>
                    <td>
                        <div><i class="fas fa-box"></i> <?= $row['tongLanMua'] ?> đơn</div>
                        <div><i class="fas fa-tools"></i> <?= $row['tongLanSua'] ?> sửa</div>
                        <div><i class="fas fa-coins"></i> <?= $chiTieu ?> đ</div>
                    </td>
                    <td><?= date('d/m/Y', strtotime($row['ngayDangKy'])) ?></td>
                    <td>
                        <div class="action-btns">
                            <a href="chi_tiet_khach_hang.php?id=<?= $row['maKH'] ?>" class="action-btn" title="Chi tiết"><i class="fas fa-eye"></i></a>
                            <button class="action-btn" onclick='openEditModal(<?= $row['maKH'] ?>)' title="Sửa"><i class="fas fa-pen"></i></button>
                            <button class="action-btn del" onclick="confirmDelete(<?= $row['maKH'] ?>, '<?= addslashes(htmlspecialchars($row['tenKH'])) ?>')" title="Xóa"><i class="fas fa-trash"></i></button>
                        </div>
                    </td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="7" class="empty-cell">
                    <div class="empty-icon"><i class="fas fa-users-slash"></i></div>
                    <div class="empty-text">Không tìm thấy khách hàng nào</div>
                </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>

<!-- Modal Thêm/Sửa -->
<div class="modal-overlay" id="customerModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-icon"><i class="fas fa-user-edit"></i></div>
            <div class="modal-title">
                <h2 id="modalTitle">Thêm khách hàng mới</h2>
                <p id="modalSub">Điền đầy đủ thông tin</p>
            </div>
            <button class="modal-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
        </div>
        <form method="POST" id="customerForm">
            <input type="hidden" name="maKH" id="fld_id">
            <div class="modal-body">
                <div class="form-grid">
                    <div class="field">
                        <label>Họ và tên <span style="color:#ef4444;">*</span></label>
                        <input type="text" name="tenKH" id="fld_ten" placeholder="Nguyễn Văn A" required>
                    </div>
                    <div class="field">
                        <label>Số điện thoại <span style="color:#ef4444;">*</span></label>
                        <input type="tel" name="soDienThoai" id="fld_sdt" placeholder="0912345678" maxlength="10">
                    </div>
                    <div class="field">
                        <label>Email</label>
                        <input type="email" name="email" id="fld_email" placeholder="example@gmail.com">
                    </div>
                    <div class="field">
                        <label>Ngày sinh</label>
                        <input type="date" name="ngaySinh" id="fld_ns">
                    </div>
                    <div class="form-full field">
                        <label>Địa chỉ</label>
                        <input type="text" name="diaChi" id="fld_diachi" placeholder="Số nhà, đường, phường/xã, quận/huyện, tỉnh/TP">
                    </div>
                    <div class="field">
                        <label>Giai đoạn khách hàng</label>
                        <select name="loaiKhachHang" id="fld_loai">
                            <option value="Khách truy cập">🌐 Khách truy cập</option>
                            <option value="Quan tâm">❤️ Quan tâm</option>
                            <option value="Đã sử dụng">✅ Đã sử dụng</option>
                            <option value="Quay lại">🔄 Quay lại</option>
                            <option value="Thân thiết">⭐ Thân thiết</option>
                            <option value="Trung thành">💎 Trung thành</option>
                            <option value="Ngừng sử dụng">⏸ Ngừng sử dụng</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Trạng thái</label>
                        <select name="trangThai" id="fld_tt">
                            <option value="1">🟢 Hoạt động</option>
                            <option value="0">🔴 Đã khóa</option>
                        </select>
                    </div>
                    <div class="field" id="ngaydk_group">
                        <label>Ngày đăng ký</label>
                        <input type="date" name="ngayDangKy" id="fld_ngaydk">
                        <div class="form-hint">Để trống sẽ lấy ngày hiện tại</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" onclick="closeModal()">Hủy</button>
                <button type="submit" name="luu_khach_hang" class="btn-save"><i class="fas fa-save"></i> Lưu</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Live clock
function updateClock() {
    const now = new Date();
    const pad = n => String(n).padStart(2, '0');
    const days = ['CN','T2','T3','T4','T5','T6','T7'];
    document.getElementById('live-clock').textContent = days[now.getDay()] + ' ' + pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());
}
updateClock();
setInterval(updateClock, 1000);

// Modal functions
const modal = document.getElementById('customerModal');
const TODAY = new Date().toISOString().split('T')[0];
document.getElementById('fld_ns').max = TODAY;

// Khai báo biến lưu danh sách khách hàng từ PHP (để edit lấy dữ liệu)
let customersData = <?php 
    $data = [];
    if($result && $result->num_rows > 0){
        $result->data_seek(0);
        while($row = $result->fetch_assoc()){
            $data[$row['maKH']] = $row;
        }
        $result->data_seek(0);
    }
    echo json_encode($data);
?>;

function openAddModal() {
    document.getElementById('customerForm').reset();
    document.getElementById('fld_id').value = '';
    document.getElementById('fld_ngaydk').value = TODAY;
    document.getElementById('ngaydk_group').style.display = 'flex';
    document.getElementById('modalTitle').textContent = 'Thêm khách hàng mới';
    document.getElementById('modalSub').textContent = 'Điền đầy đủ thông tin để tạo hồ sơ';
    modal.classList.add('open');
}

function openEditModal(id) {
    const c = customersData[id];
    if (!c) return;
    document.getElementById('fld_id').value = id;
    document.getElementById('fld_ten').value = c.tenKH;
    document.getElementById('fld_sdt').value = c.soDienThoai || '';
    document.getElementById('fld_email').value = c.email || '';
    document.getElementById('fld_ns').value = c.ngaySinh || '';
    document.getElementById('fld_diachi').value = c.diaChi || '';
    document.getElementById('fld_loai').value = c.loaiKhachHang;
    document.getElementById('fld_tt').value = c.trangThai;
    document.getElementById('fld_ngaydk').value = c.ngayDangKy;
    document.getElementById('ngaydk_group').style.display = 'none';
    document.getElementById('modalTitle').textContent = 'Chỉnh sửa thông tin';
    document.getElementById('modalSub').textContent = 'Cập nhật hồ sơ khách hàng #' + id;
    modal.classList.add('open');
}

function closeModal() {
    modal.classList.remove('open');
}

modal.addEventListener('click', function(e) {
    if (e.target === modal) closeModal();
});

// Xóa khách hàng
function confirmDelete(id, name) {
    Swal.fire({
        title: 'Xóa khách hàng?',
        html: `Bạn sắp xóa <strong>${name}</strong>.<br>Thao tác này không thể hoàn tác!`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#64748b',
        confirmButtonText: '<i class="fas fa-trash"></i> Xóa vĩnh viễn',
        cancelButtonText: 'Hủy'
    }).then(result => {
        if (result.isConfirmed) window.location.href = 'quan_ly_khach_hang.php?xoa_id=' + id;
    });
}

// Validate số điện thoại chỉ nhập số
document.getElementById('fld_sdt').addEventListener('input', function() {
    this.value = this.value.replace(/\D/g, '').slice(0, 10);
});
</script>
</body>
</html>