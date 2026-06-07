<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

// ========================
// TẠO BẢNG & THÊM CỘT
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

$conn->query("CREATE TABLE IF NOT EXISTS `don_hang` (
  `maDH` int(11) NOT NULL AUTO_INCREMENT,
  `maKH` int(11) NOT NULL,
  `ngayDat` date DEFAULT current_timestamp(),
  `tongTien` double DEFAULT 0,
  `trangThai` varchar(50) DEFAULT 'Chờ duyệt',
  PRIMARY KEY (`maDH`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `phieu_sua_chua` (
  `maPhieu` int(11) NOT NULL AUTO_INCREMENT,
  `maKH` int(11) NOT NULL,
  `ngayNhan` date DEFAULT current_timestamp(),
  `trangThai` varchar(50) DEFAULT 'Tiếp nhận',
  PRIMARY KEY (`maPhieu`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ========================
// VALIDATION
// ========================
function validateCustomerData($data) {
    $errors = [];
    $tenKH = trim($data['tenKH'] ?? '');
    if (empty($tenKH))          $errors['tenKH'] = 'Họ tên không được để trống';
    elseif (strlen($tenKH) < 3) $errors['tenKH'] = 'Họ tên phải có ít nhất 3 ký tự';

    $sdt = trim($data['soDienThoai'] ?? '');
    if (empty($sdt))                             $errors['soDienThoai'] = 'Số điện thoại không được để trống';
    elseif (!preg_match('/^[0-9]{10}$/', $sdt))  $errors['soDienThoai'] = 'Số điện thoại phải đúng 10 chữ số';

    $email = trim($data['email'] ?? '');
    if (!empty($email)) {
        if (strpos($email, '@') === false)            $errors['email'] = 'Email phải chứa ký tự @';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Email không đúng định dạng (VD: ten@gmail.com)';
    }

    $today = date('Y-m-d');
    if (!empty($data['ngaySinh'])) {
        if ($data['ngaySinh'] > $today)       $errors['ngaySinh'] = 'Ngày sinh không được lớn hơn hôm nay';
        elseif ($data['ngaySinh'] < date('Y-m-d', strtotime('-120 years'))) $errors['ngaySinh'] = 'Ngày sinh không hợp lệ';
    }
    if (!empty($data['ngayDangKy']) && $data['ngayDangKy'] > $today)
        $errors['ngayDangKy'] = 'Ngày đăng ký không được lớn hơn hôm nay';

    return $errors;
}

// ========================
// XÓA
// ========================
if (isset($_GET['xoa_id'])) {
    $conn->query("DELETE FROM khach_hang WHERE maKH=" . (int)$_GET['xoa_id']);
    $_SESSION['success'] = 'Đã xóa khách hàng thành công';
    header("Location: quan_ly_khach_hang.php"); exit();
}

// ========================
// THÊM / SỬA
// ========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['luu_khach_hang'])) {
    $maKH    = $_POST['maKH'] ?? '';
    $errors  = validateCustomerData($_POST);
    if (empty($errors)) {
        $tenKH    = $conn->real_escape_string(trim($_POST['tenKH']));
        $sdt      = $conn->real_escape_string(trim($_POST['soDienThoai']));
        $email    = $conn->real_escape_string(trim($_POST['email'] ?? ''));
        $diaChi   = $conn->real_escape_string(trim($_POST['diaChi'] ?? ''));
        $ngaySinh = !empty($_POST['ngaySinh']) ? "'".$conn->real_escape_string($_POST['ngaySinh'])."'" : 'NULL';
        $loai     = $conn->real_escape_string($_POST['loaiKhachHang']);
        $tt       = (int)$_POST['trangThai'];
        if (!empty($maKH)) {
            $conn->query("UPDATE khach_hang SET tenKH='$tenKH',soDienThoai='$sdt',email='$email',
                diaChi='$diaChi',ngaySinh=$ngaySinh,loaiKhachHang='$loai',trangThai=$tt
                WHERE maKH=".(int)$maKH);
            $_SESSION['success'] = 'Cập nhật thành công';
        } else {
            $ngayDK = !empty($_POST['ngayDangKy']) ? $conn->real_escape_string($_POST['ngayDangKy']) : date('Y-m-d');
            $conn->query("INSERT INTO khach_hang(tenKH,soDienThoai,email,diaChi,ngaySinh,loaiKhachHang,ngayDangKy,trangThai)
                VALUES('$tenKH','$sdt','$email','$diaChi',$ngaySinh,'$loai','$ngayDK',$tt)");
            $_SESSION['success'] = 'Thêm khách hàng mới thành công';
        }
        header("Location: quan_ly_khach_hang.php"); exit();
    } else {
        $_SESSION['errors']   = $errors;
        $_SESSION['old_data'] = $_POST;
        header("Location: quan_ly_khach_hang.php"); exit();
    }
}

// ========================
// XUẤT CSV
// ========================
if (isset($_GET['export_csv'])) {
    $sqlE = "SELECT kh.maKH,kh.tenKH,kh.soDienThoai,kh.email,kh.diaChi,kh.ngaySinh,
                kh.loaiKhachHang,kh.ngayDangKy,kh.trangThai,
                COUNT(DISTINCT dh.maDH) as tongLanMua,
                COUNT(DISTINCT psc.maPhieu) as tongLanSua
            FROM khach_hang kh
            LEFT JOIN don_hang dh ON kh.maKH=dh.maKH
            LEFT JOIN phieu_sua_chua psc ON kh.maKH=psc.maKH
            GROUP BY kh.maKH ORDER BY kh.maKH DESC";
    $res = $conn->query($sqlE);
    $filename = 'khach_hang_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    echo "\xEF\xBB\xBF"; // BOM UTF-8
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Mã KH','Họ Tên','SĐT','Email','Địa Chỉ','Ngày Sinh','Giai Đoạn','Ngày ĐK','Trạng Thái','Đơn Hàng','Sửa Chữa']);
    while ($r = $res->fetch_assoc())
        fputcsv($out, [$r['maKH'],$r['tenKH'],$r['soDienThoai'],$r['email'],$r['diaChi'],
            $r['ngaySinh'] ? date('d/m/Y',strtotime($r['ngaySinh'])) : '',
            $r['loaiKhachHang'], date('d/m/Y',strtotime($r['ngayDangKy'])),
            $r['trangThai'] ? 'Hoạt động' : 'Đã khóa', $r['tongLanMua'], $r['tongLanSua']]);
    fclose($out); exit();
}

// ========================
// LỌC & TRUY VẤN
// ========================
$search      = isset($_GET['search'])      ? $conn->real_escape_string($_GET['search'])      : '';
$filter_loai = isset($_GET['filter_loai']) ? $conn->real_escape_string($_GET['filter_loai']) : '';
$filter_tt   = isset($_GET['filter_tt'])   ? $conn->real_escape_string($_GET['filter_tt'])   : '';

$sql = "SELECT kh.maKH,kh.tenKH,kh.soDienThoai,kh.email,kh.diaChi,kh.ngaySinh,
            kh.loaiKhachHang,kh.ngayDangKy,kh.trangThai,
            COUNT(DISTINCT dh.maDH) as tongLanMua,
            COUNT(DISTINCT psc.maPhieu) as tongLanSua,
            COALESCE(SUM(dh.tongTien),0) as tongChiTieu
        FROM khach_hang kh
        LEFT JOIN don_hang dh ON kh.maKH=dh.maKH
        LEFT JOIN phieu_sua_chua psc ON kh.maKH=psc.maKH
        WHERE 1=1";
if ($search)      $sql .= " AND (kh.tenKH LIKE '%$search%' OR kh.soDienThoai LIKE '%$search%' OR kh.email LIKE '%$search%')";
if ($filter_loai) $sql .= " AND kh.loaiKhachHang='$filter_loai'";
if ($filter_tt !== '') $sql .= " AND kh.trangThai='$filter_tt'";
$sql .= " GROUP BY kh.maKH ORDER BY kh.maKH DESC";
$result = $conn->query($sql);

// Thống kê
$stats = $conn->query("SELECT COUNT(*) as tong, SUM(trangThai=1) as hoat_dong,
    SUM(trangThai=0) as da_khoa, SUM(loaiKhachHang='Trung thành') as vip FROM khach_hang")->fetch_assoc();

// Phân bố giai đoạn (cho chart)
$distRes = $conn->query("SELECT loaiKhachHang, COUNT(*) as cnt FROM khach_hang GROUP BY loaiKhachHang");
$dist = [];
while ($d = $distRes->fetch_assoc()) $dist[$d['loaiKhachHang']] = (int)$d['cnt'];

// Sinh nhật hôm nay
$todayMD = date('m-d');
$bdayRes = $conn->query("SELECT maKH,tenKH,ngaySinh FROM khach_hang
    WHERE DATE_FORMAT(ngaySinh,'%m-%d')='$todayMD' AND ngaySinh IS NOT NULL AND trangThai=1");
$birthdays = [];
while ($b = $bdayRes->fetch_assoc()) $birthdays[] = $b;

// Old data nếu có lỗi
$old = $_SESSION['old_data'] ?? [];
$formErrors = $_SESSION['errors'] ?? [];
unset($_SESSION['old_data'], $_SESSION['errors']);

// Khách hàng chưa cập nhật đủ thông tin (thiếu ít nhất 1 trong: sđt, ngày sinh, địa chỉ, email)
$incompleteRes = $conn->query(
    "SELECT maKH, tenKH, ngayDangKy FROM khach_hang
     WHERE trangThai = 1
       AND (
           soDienThoai IS NULL OR soDienThoai = '' OR
           ngaySinh    IS NULL OR
           diaChi      IS NULL OR diaChi      = '' OR
           email       IS NULL OR email       = ''
       )
     ORDER BY ngayDangKy DESC"
);
$incompleteList = [];
while ($ic = $incompleteRes->fetch_assoc()) $incompleteList[] = $ic;

<<<<<<< HEAD
// Thêm vào sau phần lấy dữ liệu sinh nhật (khoảng dòng 180-200)

// ========================
// LẤY THÔNG BÁO TỪ KTV (ADMIN)
// ========================
$adminNotifications = [];
$notifRes = $conn->query("
    SELECT tb.*, ps.maKH, ps.tenThietBi, ps.tenKH, ps.trangThai as ttPhieu
    FROM thong_bao_admin tb
    LEFT JOIN phieu_sua_chua ps ON ps.maPhieu = tb.maPhieu
    WHERE tb.trangThai = 'chua_doc'
    ORDER BY tb.thoiGian DESC
    LIMIT 10
");
if ($notifRes) {
    while ($row = $notifRes->fetch_assoc()) {
        $adminNotifications[] = $row;
    }
}

=======
>>>>>>> fc0888887465ac6d64caa80abe4294c237f2aa7d
// Helper: avatar color từ tên
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
<title>Quản Lý Khách Hàng — QA Tech</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:ital,wght@0,400;0,500;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<style>
:root {
    --sw: 268px;
    --pri: #065f46; --pri-d: #047857; --pri-l: #059669; --pri-g: rgba(6,95,70,.10);
    --pri-bdr: rgba(6,95,70,.22);
    --blue: #1d4ed8; --purple: #6d28d9; --red: #dc2626;
    --amber: #d97706; --orange: #ea580c;
    --bg: #f0fdf4; --bg2: #ffffff; --bg3: #ecfdf5;
    --card: #ffffff; --card2: #f0fdf4;
    --bdr: rgba(6,95,70,.12);
    --text: #111827; --muted: #4b7a63; --white: #111827;
    --r: 14px; --sh: 0 4px 20px rgba(6,95,70,.10);
}
*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
html { scroll-behavior:smooth; }
body { font-family:'Sora',sans-serif; background:var(--bg); color:var(--text); display:flex; min-height:100vh; font-size:14px; line-height:1.6; }
::-webkit-scrollbar { width:5px; height:5px; }
::-webkit-scrollbar-track { background:#d1fae5; }
::-webkit-scrollbar-thumb { background:var(--pri); border-radius:99px; }

/* ── SIDEBAR ── */
.sidebar { width:var(--sw); background:var(--pri); border-right:none; height:100vh; position:fixed; display:flex; flex-direction:column; z-index:200; box-shadow:4px 0 20px rgba(6,95,70,.18); }
.sb-logo { padding:26px 22px 18px; border-bottom:1px solid rgba(255,255,255,.15); }
.sb-logo .brand { font-size:21px; font-weight:800; color:#fff; letter-spacing:-.5px; }
.sb-logo .brand span { color:#6ee7b7; }
.sb-logo .sub { font-size:10px; letter-spacing:2px; color:rgba(255,255,255,.6); text-transform:uppercase; margin-top:4px; }
.sb-nav { flex:1; padding:14px 10px; overflow-y:auto; }
.nav-sec { font-size:10px; letter-spacing:2px; text-transform:uppercase; color:rgba(255,255,255,.5); padding:10px 12px 5px; font-weight:700; }
.nav-a { display:flex; align-items:center; gap:11px; padding:10px 13px; border-radius:9px; color:rgba(255,255,255,.75); text-decoration:none; font-weight:500; font-size:13px; transition:all .18s; margin-bottom:2px; border:1px solid transparent; }
.nav-a i { width:16px; font-size:14px; }
.nav-a:hover { background:rgba(255,255,255,.12); color:#fff; }
.nav-a.active { background:rgba(255,255,255,.18); color:#fff; border-color:rgba(255,255,255,.25); font-weight:700; }
.sb-foot { padding:14px 10px; border-top:1px solid rgba(255,255,255,.15); }

/* ── MAIN ── */
.main { margin-left:var(--sw); width:calc(100% - var(--sw)); padding:28px 32px; min-height:100vh; background:var(--bg); }

/* ── TOP ROW ── */
.top-row { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:22px; gap:16px; flex-wrap:wrap; }
.page-title h1 { font-size:24px; font-weight:800; color:var(--pri); letter-spacing:-.4px; }
.page-title p  { font-size:12.5px; color:var(--muted); margin-top:3px; }
.top-actions { display:flex; gap:10px; align-items:center; }

/* ── BIRTHDAY BANNER ── */
.bday-banner {
    background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
    border: 1px solid rgba(245,158,11,.35);
    border-radius: var(--r);
    padding: 14px 20px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    animation: slideDown .4s ease;
    box-shadow: var(--sh);
}
.bday-icon { font-size: 28px; flex-shrink: 0; }
.bday-text strong { color: #92400e; font-weight: 700; font-size: 14px; }
.bday-text p { font-size: 12.5px; color: #1f2937; margin-top: 3px; }
.bday-names { display:flex; flex-wrap:wrap; gap:8px; margin-top:6px; }
.bday-chip {
    background: rgba(245,158,11,.14);
    border: 1px solid rgba(245,158,11,.3);
    color: #78350f;
    font-size: 12px;
    padding: 3px 12px;
    border-radius: 99px;
    font-weight: 600;
}
<<<<<<< HEAD
/* ── NOTIFICATION BELL ADMIN ── */
.notif-bell-admin {
    position: relative;
    cursor: pointer;
    padding: 8px 14px;
    border-radius: 9px;
    background: #f1f5f9;
    border: 1px solid var(--bdr);
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    font-weight: 600;
    color: var(--text);
    transition: all .15s;
}
.notif-bell-admin:hover {
    background: #e2e8f0;
}
.notif-dot-admin {
    position: absolute;
    top: -4px;
    right: -4px;
    background: #ef4444;
    color: #fff;
    border-radius: 50%;
    font-size: 9px;
    font-weight: 700;
    min-width: 18px;
    height: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid #fff;
}
.notif-panel-admin {
    position: fixed;
    top: 68px;
    right: 20px;
    width: 390px;
    max-height: 80vh;
    overflow-y: auto;
    background: #fff;
    border-radius: 16px;
    border: 1px solid var(--bdr);
    box-shadow: 0 8px 32px rgba(0,0,0,.16);
    z-index: 9999;
    display: none;
}
.notif-panel-admin.open {
    display: block;
}
.notif-header-admin {
    padding: 16px 18px;
    border-bottom: 1px solid var(--bdr);
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-weight: 700;
    font-size: 14px;
    background: linear-gradient(135deg, #064e3b, #065f46);
    color: #fff;
    border-radius: 16px 16px 0 0;
}
.notif-header-admin button {
    background: rgba(255,255,255,.2);
    border: none;
    font-size: 11px;
    color: #fff;
    cursor: pointer;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 6px;
}
.notif-item-admin {
    padding: 14px 18px;
    border-bottom: 1px solid #f8fafc;
    transition: background .15s;
    cursor: pointer;
}
.notif-item-admin:hover {
    background: #f8fafc;
}
.notif-item-admin.unread {
    background: #eff6ff;
    border-left: 3px solid #3b82f6;
}
.notif-title-admin {
    font-size: 13px;
    font-weight: 700;
    color: var(--text);
}
.notif-sub-admin {
    font-size: 11px;
    color: var(--muted);
    margin-top: 3px;
    white-space: pre-wrap;
    line-height: 1.5;
}
.notif-time-admin {
    font-size: 10px;
    color: #94a3b8;
    margin-top: 4px;
}
.notif-actions-admin {
    display: flex;
    gap: 6px;
    margin-top: 8px;
}
.btn-view-phieu {
    padding: 5px 12px;
    background: #dbeafe;
    color: #2563eb;
    border: none;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.btn-view-phieu:hover {
    background: #2563eb;
    color: #fff;
}
=======
>>>>>>> fc0888887465ac6d64caa80abe4294c237f2aa7d

/* ── LAYOUT: STATS + CHART ── */
.dashboard-row { display:grid; grid-template-columns:1fr 320px; gap:16px; margin-bottom:20px; }
.stats-col { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.stat-card { background:var(--card); border:1px solid var(--bdr); border-radius:var(--r); padding:18px; display:flex; align-items:center; gap:14px; transition:all .2s; box-shadow:var(--sh); }
.stat-card:hover { transform:translateY(-2px); box-shadow:0 8px 28px rgba(6,95,70,.14); }
.s-icon { width:44px; height:44px; border-radius:11px; display:flex; align-items:center; justify-content:center; font-size:19px; flex-shrink:0; }
.si-green  { background:rgba(6,95,70,.10);   color:var(--pri); }
.si-blue   { background:rgba(29,78,216,.10);  color:var(--blue); }
.si-red    { background:rgba(220,38,38,.10);  color:var(--red); }
.si-amber  { background:rgba(217,119,6,.10);  color:var(--amber); }
.s-num  { font-size:26px; font-weight:800; color:#111827; line-height:1; font-family:'DM Mono',monospace; }
.s-lbl  { font-size:11.5px; color:var(--muted); margin-top:3px; }

/* ── DONUT CHART CARD ── */
.chart-card { background:var(--card); border:1px solid var(--bdr); border-radius:var(--r); padding:18px; display:flex; flex-direction:column; box-shadow:var(--sh); }
.chart-card-title { font-size:12px; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:1.2px; margin-bottom:14px; }
.chart-inner { display:flex; align-items:center; gap:16px; flex:1; }
.chart-canvas-wrap { width:110px; height:110px; flex-shrink:0; position:relative; }
.chart-legend { flex:1; display:flex; flex-direction:column; gap:6px; }
.legend-item { display:flex; align-items:center; gap:8px; font-size:11.5px; }
.legend-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; }
.legend-name { color:#1f2937; flex:1; }
.legend-pct  { color:var(--muted); font-family:'DM Mono',monospace; font-size:11px; }

/* ── FILTER BAR ── */
.filter-bar { background:var(--card); border:1px solid var(--bdr); border-radius:var(--r); padding:15px 18px; margin-bottom:18px; display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; box-shadow:var(--sh); }
.fg { display:flex; flex-direction:column; gap:4px; }
.fg label { font-size:10px; letter-spacing:1.5px; text-transform:uppercase; color:var(--muted); font-weight:700; }
.fg input, .fg select { background:#f8fffe; border:1.5px solid var(--bdr); color:#111827; padding:8px 12px; border-radius:8px; font-family:'Sora',sans-serif; font-size:13px; outline:none; min-width:150px; transition:border-color .2s; }
.fg input:focus,.fg select:focus { border-color:var(--pri); box-shadow:0 0 0 3px rgba(6,95,70,.08); }
.fg input::placeholder { color:#9ca3af; }
.fg select option { background:#fff; color:#111827; }
.btn-filter { background:var(--pri); color:#fff; border:none; padding:8px 18px; border-radius:8px; font-weight:700; font-size:13px; cursor:pointer; font-family:'Sora',sans-serif; display:flex; align-items:center; gap:7px; transition:all .2s; }
.btn-filter:hover { background:var(--pri-d); transform:translateY(-1px); box-shadow:0 4px 14px rgba(6,95,70,.22); }
.btn-clear  { background:transparent; color:var(--muted); border:1.5px solid var(--bdr); padding:8px 14px; border-radius:8px; font-size:12px; cursor:pointer; font-family:'Sora',sans-serif; text-decoration:none; display:flex; align-items:center; gap:5px; transition:all .2s; }
.btn-clear:hover { color:var(--pri); border-color:var(--pri); }
.btn-export { background:rgba(29,78,216,.07); color:var(--blue); border:1.5px solid rgba(29,78,216,.2); padding:8px 16px; border-radius:8px; font-weight:600; font-size:12.5px; cursor:pointer; font-family:'Sora',sans-serif; text-decoration:none; display:flex; align-items:center; gap:6px; transition:all .2s; margin-left:auto; }
.btn-export:hover { background:rgba(29,78,216,.14); }

/* ── TABLE ── */
.tbl-wrap { background:var(--card); border:1px solid var(--bdr); border-radius:var(--r); overflow:hidden; box-shadow:var(--sh); }
.tbl-top { padding:16px 20px; border-bottom:1.5px solid var(--bdr); display:flex; justify-content:space-between; align-items:center; background:var(--bg3); }
.tbl-ttl { font-weight:700; font-size:14px; color:#111827; display:flex; align-items:center; gap:8px; }
.cnt-badge { background:rgba(6,95,70,.12); color:var(--pri); font-size:11px; padding:2px 9px; border-radius:99px; font-family:'DM Mono',monospace; border:1px solid var(--pri-bdr); }
.btn-add { background:var(--pri); color:#fff; border:none; padding:9px 20px; border-radius:9px; font-weight:700; font-size:13px; cursor:pointer; font-family:'Sora',sans-serif; display:flex; align-items:center; gap:7px; transition:all .2s; }
.btn-add:hover { background:var(--pri-d); transform:translateY(-2px); box-shadow:0 4px 16px rgba(6,95,70,.25); }

table { width:100%; border-collapse:collapse; }
thead th { background:#f0fdf4; padding:12px 16px; text-align:left; font-size:11px; letter-spacing:1.2px; text-transform:uppercase; color:var(--muted); font-weight:700; border-bottom:1.5px solid var(--bdr); white-space:nowrap; cursor:pointer; user-select:none; transition:color .2s; }
thead th:hover { color:var(--pri); }
thead th .sort-icon { margin-left:5px; opacity:.4; font-size:10px; }
thead th.sorted .sort-icon { opacity:1; color:var(--pri); }
tbody tr { border-bottom:1px solid rgba(6,95,70,.06); transition:background .15s; }
tbody tr:last-child { border-bottom:none; }
tbody tr:hover { background:#f0fdf4; }
tbody td { padding:14px 16px; vertical-align:middle; color:#111827; }

/* ── AVATAR ── */
.av-wrap { display:flex; align-items:center; gap:11px; }
.avatar { width:40px; height:40px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; letter-spacing:.5px; flex-shrink:0; border:2px solid rgba(6,95,70,.12); }
.cust-name { font-weight:700; color:#111827; font-size:13.5px; white-space:nowrap; }
.cust-meta { display:flex; flex-direction:column; gap:2px; margin-top:3px; }
.cust-meta span { font-size:11.5px; color:var(--muted); display:flex; align-items:center; gap:5px; }
.cust-meta i { width:11px; color:var(--pri-l); font-size:10px; }
.cust-id { font-family:'DM Mono',monospace; font-size:12px; color:var(--muted); background:#f0fdf4; padding:2px 8px; border-radius:5px; display:inline-block; border:1px solid var(--bdr); font-weight:600; }

/* ── TOOLTIP ── */
.tooltip-wrap { position:relative; cursor:default; }
.tooltip-box {
    position:absolute; left:0; top:calc(100% + 8px); z-index:999;
    background:#fff; border:1.5px solid var(--bdr);
    border-radius:12px; padding:14px 16px; width:240px;
    box-shadow:0 12px 40px rgba(6,95,70,.16);
    opacity:0; pointer-events:none; transform:translateY(-6px);
    transition:opacity .2s, transform .2s;
}
.tooltip-wrap:hover .tooltip-box { opacity:1; pointer-events:auto; transform:none; }
.tt-row { display:flex; align-items:flex-start; gap:8px; font-size:12px; color:#374151; margin-bottom:8px; }
.tt-row:last-child { margin-bottom:0; }
.tt-row i { color:var(--pri-l); width:13px; margin-top:2px; font-size:11px; }
.tt-divider { border:none; border-top:1px solid #e5f7ef; margin:10px 0; }
.tt-stat { display:flex; justify-content:space-between; font-size:11.5px; }
.tt-stat .lbl { color:var(--muted); }
.tt-stat .val { color:#111827; font-weight:700; font-family:'DM Mono',monospace; }

/* ── BADGES ── */
.badge { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:99px; font-size:11px; font-weight:700; white-space:nowrap; }
.b-visitor  { background:#f1f5f9;              color:#475569; border:1px solid #cbd5e1; }
.b-interest { background:rgba(29,78,216,.08);  color:#1d4ed8; border:1px solid rgba(29,78,216,.2); }
.b-used     { background:rgba(6,95,70,.09);    color:#065f46; border:1px solid rgba(6,95,70,.2); }
.b-return   { background:rgba(109,40,217,.08); color:#6d28d9; border:1px solid rgba(109,40,217,.2); }
.b-loyal    { background:rgba(217,119,6,.10);  color:#92400e; border:1px solid rgba(217,119,6,.22); }
.b-vip      { background:rgba(234,88,12,.10);  color:#9a3412; border:1px solid rgba(234,88,12,.22); }
.b-inactive { background:rgba(220,38,38,.08);  color:#991b1b; border:1px solid rgba(220,38,38,.2); }
.status-row { display:flex; align-items:center; gap:5px; margin-top:6px; font-size:11px; color:var(--muted); }
.dot { width:6px; height:6px; border-radius:50%; flex-shrink:0; }
.dot.on  { background:#059669; box-shadow:0 0 5px rgba(5,150,105,.4); }
.dot.off { background:var(--red); }

/* ── TRANSACTION ── */
.tx-row { display:flex; align-items:center; gap:6px; font-size:12px; margin-bottom:4px; }
.tx-ico { width:20px; height:20px; border-radius:5px; display:flex; align-items:center; justify-content:center; font-size:10px; }
.tx-ico.g { background:rgba(6,95,70,.10); color:var(--pri); }
.tx-ico.r { background:rgba(220,38,38,.10); color:var(--red); }
.tx-num { font-weight:700; color:#111827; font-family:'DM Mono',monospace; }

/* ── DATE CELL ── */
.date-cell { font-size:12.5px; font-family:'DM Mono',monospace; color:#111827; }
.date-sub  { font-size:11px; color:var(--muted); margin-top:3px; }

/* ── ACTION BUTTONS ── */
.act-btns { display:flex; align-items:center; gap:5px; }
.btn-ico { width:32px; height:32px; border-radius:7px; border:1.5px solid var(--bdr); background:#f8fffe; color:#4b7a63; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:12px; transition:all .18s; text-decoration:none; }
.btn-ico:hover { transform:translateY(-1px); }
.btn-ico.v:hover { background:rgba(29,78,216,.08); color:var(--blue); border-color:rgba(29,78,216,.25); }
.btn-ico.e:hover { background:rgba(217,119,6,.10); color:var(--amber); border-color:rgba(217,119,6,.25); }
.btn-ico.d:hover { background:rgba(220,38,38,.08); color:var(--red); border-color:rgba(220,38,38,.25); }

/* ── ALERT ── */
.alert { display:flex; align-items:flex-start; gap:12px; padding:12px 16px; border-radius:11px; margin-bottom:18px; animation:slideDown .3s ease; }
.alert-ok  { background:rgba(6,95,70,.07); border:1px solid rgba(6,95,70,.2); color:#064e3b; }
.alert-err { background:rgba(220,38,38,.06); border:1px solid rgba(220,38,38,.2); color:#991b1b; }
.alert ul  { margin:6px 0 0 18px; }
.alert ul li { font-size:12.5px; margin-bottom:3px; }
@keyframes slideDown { from{opacity:0;transform:translateY(-8px)} to{opacity:1;transform:none} }

/* ── EMPTY STATE ── */
.empty-cell { text-align:center; padding:60px !important; }
.empty-ico  { font-size:38px; opacity:.25; margin-bottom:12px; }
.empty-t    { font-weight:700; color:#111827; margin-bottom:6px; }
.empty-s    { font-size:13px; color:var(--muted); }

/* ── MODAL ── */
.modal-ov { display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); backdrop-filter:blur(6px); z-index:1000; align-items:center; justify-content:center; padding:20px; }
.modal-ov.open { display:flex; }
.modal-box { background:#fff; border:1.5px solid var(--bdr); border-radius:20px; width:100%; max-width:740px; max-height:92vh; overflow:hidden; display:flex; flex-direction:column; box-shadow:0 24px 60px rgba(6,95,70,.18); animation:mUp .3s cubic-bezier(.34,1.3,.64,1); }
@keyframes mUp { from{opacity:0;transform:translateY(28px) scale(.97)} to{opacity:1;transform:none} }
.m-head { padding:20px 26px; background:linear-gradient(135deg,var(--pri) 0%,var(--pri-d) 100%); border-bottom:none; display:flex; align-items:center; gap:13px; flex-shrink:0; }
.m-icon { width:44px; height:44px; background:rgba(255,255,255,.2); border:1px solid rgba(255,255,255,.3); border-radius:11px; display:flex; align-items:center; justify-content:center; font-size:19px; color:#fff; flex-shrink:0; }
.m-title h2 { font-size:17px; font-weight:800; color:#fff; }
.m-title p  { font-size:12px; color:rgba(255,255,255,.75); margin-top:2px; }
.m-close { margin-left:auto; width:32px; height:32px; background:rgba(255,255,255,.15); border:none; border-radius:7px; color:#fff; cursor:pointer; font-size:13px; transition:all .2s; display:flex; align-items:center; justify-content:center; }
.m-close:hover { background:rgba(220,38,38,.7); transform:rotate(90deg); }
.m-body { padding:22px 26px; overflow-y:auto; flex:1; background:#fff; }
.m-foot { padding:16px 26px; border-top:1.5px solid var(--bdr); display:flex; justify-content:flex-end; gap:10px; flex-shrink:0; background:#f8fffe; }

/* ── FORM ── */
.f-section { margin-bottom:22px; }
.f-sec-title { font-size:10.5px; letter-spacing:2px; text-transform:uppercase; color:var(--pri); font-weight:700; padding-bottom:9px; border-bottom:2px solid rgba(6,95,70,.12); margin-bottom:14px; display:flex; align-items:center; gap:7px; }
.f-grid  { display:grid; grid-template-columns:1fr 1fr; gap:13px; }
.f-full  { grid-column:1/-1; }
.field   { display:flex; flex-direction:column; gap:5px; }
.field label { font-size:12px; font-weight:600; color:#374151; display:flex; align-items:center; gap:5px; }
.field .req { color:var(--red); }
.field input,.field select { background:#f8fffe; border:1.5px solid #d1fae5; color:#111827; padding:9px 12px; border-radius:9px; font-family:'Sora',sans-serif; font-size:13px; outline:none; width:100%; transition:all .2s; }
.field input:focus,.field select:focus { border-color:var(--pri); box-shadow:0 0 0 3px rgba(6,95,70,.09); }
.field input.err { border-color:var(--red)!important; box-shadow:0 0 0 3px rgba(220,38,38,.08); }
.field select option { background:#fff; color:#111827; }
.field input::placeholder { color:#9ca3af; }
.f-hint { font-size:11px; color:var(--muted); display:flex; align-items:center; gap:4px; }
.f-err  { font-size:11.5px; color:#b91c1c; display:none; align-items:center; gap:4px; }
.f-err.show { display:flex; }
.srv-errors { background:rgba(220,38,38,.05); border:1px solid rgba(220,38,38,.18); border-radius:9px; padding:12px 14px; margin-bottom:16px; }
.srv-errors .se-t { font-size:13px; font-weight:700; color:#991b1b; display:flex; align-items:center; gap:7px; }
.srv-errors ul { margin:7px 0 0 18px; }
.srv-errors li { font-size:12px; color:#b91c1c; margin-bottom:3px; }
.btn-cancel { background:transparent; border:1.5px solid var(--bdr); color:var(--muted); padding:9px 20px; border-radius:9px; font-weight:600; font-size:13px; cursor:pointer; font-family:'Sora',sans-serif; transition:all .2s; }
.btn-cancel:hover { border-color:var(--pri); color:var(--pri); }
.btn-save { background:var(--pri); border:none; color:#fff; padding:9px 26px; border-radius:9px; font-weight:700; font-size:13px; cursor:pointer; font-family:'Sora',sans-serif; display:flex; align-items:center; gap:7px; transition:all .2s; }
.btn-save:hover { background:var(--pri-d); box-shadow:0 5px 18px rgba(6,95,70,.25); transform:translateY(-1px); }
</style>
</head>
<body>

<!-- ── SIDEBAR ── -->
<aside class="sidebar">
    <div class="sb-logo">
        <div class="brand">QA <span>TECH</span></div>
        <div class="sub">Admin Portal</div>
    </div>
    <nav class="sb-nav">
        <div class="nav-sec">Tổng Quan</div>
<<<<<<< HEAD
        <a href="index.php" class="nav-a"><i class="fas fa-th-large"></i> Trang chủ</a>
        <div class="nav-sec">Quản Lý</div>
        <a href="quan_ly_khach_hang.php" class="nav-a active"><i class="fas fa-users"></i> Khách Hàng</a>
        
=======
        <a href="index.php" class="nav-a"><i class="fas fa-th-large"></i> Dashboard</a>
        <div class="nav-sec">Quản Lý</div>
        <a href="quan_ly_khach_hang.php" class="nav-a active"><i class="fas fa-users"></i> Khách Hàng</a>
        <a href="don_hang.php" class="nav-a"><i class="fas fa-box-open"></i> Đơn Hàng</a>
>>>>>>> fc0888887465ac6d64caa80abe4294c237f2aa7d
        <a href="don_hang_online.php" class="nav-a" id="online-order-link" style="position:relative">
            <i class="fas fa-shopping-bag"></i> Đơn Online
            <span id="online-order-badge" style="display:none;background:#ef4444;color:#fff;font-size:10px;font-weight:800;padding:1px 6px;border-radius:10px;margin-left:auto"></span>
        </a>
<<<<<<< HEAD
        
        
=======
        <a href="sua_chua.php" class="nav-a"><i class="fas fa-tools"></i> Sửa Chữa</a>
        <a href="san_pham.php" class="nav-a"><i class="fas fa-laptop"></i> Sản Phẩm</a>
        <div class="nav-sec">Hệ Thống</div>
        <a href="bao_cao.php" class="nav-a"><i class="fas fa-chart-pie"></i> Báo Cáo</a>
        <a href="cai_dat.php" class="nav-a"><i class="fas fa-cog"></i> Cài Đặt</a>
>>>>>>> fc0888887465ac6d64caa80abe4294c237f2aa7d
    </nav>
    <div class="sb-foot">
        <a href="../logout.php" class="nav-a" style="color:#ef4444;"><i class="fas fa-power-off"></i> Đăng Xuất</a>
    </div>
</aside>

<!-- ── MAIN ── -->
<main class="main">

<!-- Alert success -->
<?php if(isset($_SESSION['success'])): ?>
<div class="alert alert-ok">
    <i class="fas fa-check-circle"></i>
    <span><?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></span>
</div>
<?php endif; ?>

<!-- ── BANNER: Khách hàng chưa cập nhật thông tin ── -->
<?php if (!empty($incompleteList)): ?>
<div id="incompleteBanner" style="
    background: linear-gradient(135deg,#fffbeb 0%,#fef3c7 100%);
    border: 1.5px solid #f59e0b;
    border-left: 5px solid #d97706;
    border-radius: 14px;
    margin-bottom: 20px;
    overflow: hidden;
    box-shadow: 0 4px 16px rgba(217,119,6,.13);
    animation: slideDown .4s ease;
">
    <!-- Header banner -->
    <div style="display:flex; align-items:center; justify-content:space-between; padding:14px 20px 10px; border-bottom:1px solid rgba(217,119,6,.2);">
        <div style="display:flex; align-items:center; gap:12px;">
            <span style="font-size:24px;">⚠️</span>
            <div>
                <div style="font-weight:800; color:#92400e; font-size:14.5px;">
                    <?= count($incompleteList) ?> khách hàng chưa được cập nhật thông tin đầy đủ
                </div>
                <div style="font-size:12px; color:#78350f; margin-top:2px;">
                    Thông tin thiếu: số điện thoại, ngày sinh, địa chỉ hoặc email.
                    Hãy cập nhật để chăm sóc khách hàng tốt hơn!
                </div>
            </div>
        </div>
        <button onclick="document.getElementById('incompleteBanner').style.display='none'" style="
            background:rgba(217,119,6,.15); border:1.5px solid rgba(217,119,6,.3);
            color:#92400e; width:28px; height:28px; border-radius:7px; cursor:pointer;
            font-size:12px; display:flex; align-items:center; justify-content:center; flex-shrink:0;
        ">✕</button>
    </div>
    <!-- Danh sách khách hàng chưa cập nhật -->
    <div style="padding:12px 20px 14px; display:flex; flex-direction:column; gap:8px; max-height:260px; overflow-y:auto;">
        <?php foreach($incompleteList as $ic):
            $missingFields = [];
            if (empty($ic['soDienThoai'])) $missingFields[] = 'Số điện thoại';
            if (empty($ic['ngaySinh']))    $missingFields[] = 'Ngày sinh';
            if (empty($ic['diaChi']))      $missingFields[] = 'Địa chỉ';
            if (empty($ic['email']))       $missingFields[] = 'Email';
            // Re-query để lấy đủ field (incompleteRes chỉ có 3 cột)
            $icFull = $conn->query("SELECT * FROM khach_hang WHERE maKH=".(int)$ic['maKH'])->fetch_assoc();
            $missingFields = [];
            if (empty($icFull['soDienThoai'])) $missingFields[] = 'SĐT';
            if (empty($icFull['ngaySinh']))    $missingFields[] = 'Ngày sinh';
            if (empty($icFull['diaChi']))      $missingFields[] = 'Địa chỉ';
            if (empty($icFull['email']))       $missingFields[] = 'Email';
        ?>
        <div style="
            background:#fff;
            border:1.5px solid rgba(217,119,6,.2);
            border-radius:10px;
            padding:10px 14px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            flex-wrap:wrap;
        ">
            <div style="display:flex; align-items:center; gap:12px; flex:1; min-width:0;">
                <?php
                    $avc2 = avatarColor($icFull['tenKH']);
                    $avi2 = avatarInitials($icFull['tenKH']);
                ?>
                <div style="width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;
                            font-weight:700;font-size:12px;flex-shrink:0;
                            background:<?= $avc2['bg'] ?>;color:<?= $avc2['fg'] ?>;border:2px solid rgba(217,119,6,.2);">
                    <?= $avi2 ?>
                </div>
                <div style="min-width:0;">
                    <div style="font-weight:700;color:#111827;font-size:13.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                        <?= htmlspecialchars($icFull['tenKH']) ?>
                        <span style="font-family:'DM Mono',monospace;font-size:11px;color:#92400e;background:rgba(217,119,6,.1);
                                     padding:1px 7px;border-radius:5px;border:1px solid rgba(217,119,6,.2);margin-left:6px;">
                            #<?= $icFull['maKH'] ?>
                        </span>
                    </div>
                    <div style="font-size:12px;color:#78350f;margin-top:3px;">
                        Tạo tài khoản ngày <strong><?= date('d/m/Y', strtotime($icFull['ngayDangKy'])) ?></strong>
                        — Chưa cập nhật:
                        <?php foreach($missingFields as $i => $mf): ?>
                        <span style="display:inline-flex;align-items:center;gap:3px;background:rgba(220,38,38,.08);color:#991b1b;
                                     font-size:11px;padding:2px 8px;border-radius:99px;border:1px solid rgba(220,38,38,.18);
                                     margin-left:3px;font-weight:600;">
                            <?= $mf ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <button type="button"
                onclick="openEditModal(
                    <?= $icFull['maKH'] ?>,
                    '<?= addslashes(htmlspecialchars($icFull['tenKH'])) ?>',
                    '<?= $icFull['soDienThoai'] ?? '' ?>',
                    '<?= addslashes($icFull['email'] ?? '') ?>',
                    '<?= addslashes($icFull['diaChi'] ?? '') ?>',
                    '<?= $icFull['ngaySinh'] ?? '' ?>',
                    '<?= $icFull['loaiKhachHang'] ?>',
                    <?= $icFull['trangThai'] ?>,
                    '<?= $icFull['ngayDangKy'] ?>'
                )"
                style="
                    background:#d97706; color:#fff; border:none;
                    padding:7px 16px; border-radius:8px; font-size:12.5px;
                    font-weight:700; cursor:pointer; white-space:nowrap;
                    font-family:'Sora',sans-serif;
                    transition:all .18s; flex-shrink:0;
                "
                onmouseover="this.style.background='#b45309'"
                onmouseout="this.style.background='#d97706'">
                <i class="fas fa-pen" style="margin-right:5px;"></i> Cập nhật ngay
            </button>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── BIRTHDAY BANNER ── -->
<?php if(!empty($birthdays)): ?>
<div class="bday-banner">
    <div class="bday-icon">🎂</div>
    <div class="bday-text">
        <strong>Sinh nhật hôm nay — <?= date('d/m/Y') ?></strong>
        <p>Chúc mừng sinh nhật đến những khách hàng đặc biệt!</p>
        <div class="bday-names">
            <?php foreach($birthdays as $b):
                $age = (new DateTime($b['ngaySinh']))->diff(new DateTime())->y;
            ?>
            <span class="bday-chip">🎉 <?= htmlspecialchars($b['tenKH']) ?> — <?= $age ?> tuổi</span>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── TOP ROW ── -->
<div class="top-row">
    <div class="page-title">
        <h1>Quản Lý Khách Hàng</h1>
        <p>Theo dõi vòng đời và hành trình khách hàng — QA Tech</p>
    </div>
<<<<<<< HEAD
    <div class="top-actions">
    <div class="notif-bell-admin" id="adminBellBtn" onclick="toggleAdminNotifPanel()">
        <i class="fas fa-bell"></i> Thông báo
        <span class="notif-dot-admin" id="adminNotifDot" style="display:none;">0</span>
    </div>
</div>
</div>

<!-- ── BANNER THÔNG BÁO TỪ KTV ── -->
<?php if (!empty($adminNotifications)): ?>
<div id="adminNotifBanner" style="
    background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
    border: 1.5px solid #3b82f6;
    border-left: 5px solid #2563eb;
    border-radius: 14px;
    margin-bottom: 20px;
    overflow: hidden;
    box-shadow: 0 4px 16px rgba(37,99,235,.13);
    animation: slideDown .4s ease;
">
    <div style="display:flex; align-items:center; justify-content:space-between; padding:14px 20px 10px; border-bottom:1px solid rgba(37,99,235,.2);">
        <div style="display:flex; align-items:center; gap:12px;">
            <span style="font-size:24px;">🔔</span>
            <div>
                <div style="font-weight:800; color:#1e40af; font-size:14.5px;">
                    📢 Có <?= count($adminNotifications) ?> thông báo mới từ kỹ thuật viên
                </div>
                <div style="font-size:12px; color:#2563eb; margin-top:2px;">
                    KTV đã phản hồi về phiếu sửa chữa. Click để xem chi tiết!
                </div>
            </div>
        </div>
        <button onclick="document.getElementById('adminNotifBanner').style.display='none'" style="
            background:rgba(37,99,235,.15); border:1.5px solid rgba(37,99,235,.3);
            color:#1e40af; width:28px; height:28px; border-radius:7px; cursor:pointer;
            font-size:12px; display:flex; align-items:center; justify-content:center; flex-shrink:0;
        ">✕</button>
    </div>
    <div style="padding:12px 20px 14px; display:flex; flex-direction:column; gap:8px; max-height:300px; overflow-y:auto;">
        <?php foreach($adminNotifications as $notif):
            // Xác định icon và màu sắc theo loại thông báo
            $isChapNhan = strpos($notif['tieuDe'], 'chấp nhận') !== false || strpos($notif['loai'], 'phan_cong') !== false;
            $isTuChoi = strpos($notif['tieuDe'], 'từ chối') !== false || $notif['loai'] === 'tu_choi';
            $bgColor = $isChapNhan ? '#d1fae5' : ($isTuChoi ? '#fee2e2' : '#dbeafe');
            $borderColor = $isChapNhan ? '#059669' : ($isTuChoi ? '#dc2626' : '#2563eb');
            $textColor = $isChapNhan ? '#065f46' : ($isTuChoi ? '#991b1b' : '#1e40af');
            $icon = $isChapNhan ? '✅' : ($isTuChoi ? '❌' : '🔔');
        ?>
        <div style="
            background:<?= $bgColor ?>;
            border:1px solid <?= $borderColor ?>;
            border-radius:10px;
            padding:12px 14px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            flex-wrap:wrap;
            cursor:pointer;
            transition:all .2s;
        " onclick="viewPhieuSuaChua(<?= $notif['maKH'] ?? 0 ?>, <?= $notif['maPhieu'] ?>)" onmouseover="this.style.transform='translateX(4px)'" onmouseout="this.style.transform='none'">
            <div style="display:flex; align-items:center; gap:12px; flex:1; min-width:0;">
                <div style="font-size:24px;"><?= $icon ?></div>
                <div style="min-width:0;">
                    <div style="font-weight:700; color:<?= $textColor ?>; font-size:13px;">
                        <?= htmlspecialchars($notif['tieuDe']) ?>
                        <span style="font-family:monospace;font-size:11px;background:rgba(0,0,0,.05);padding:2px 6px;border-radius:4px;margin-left:6px;">
                            #SC-<?= $notif['maPhieu'] ?>
                        </span>
                    </div>
                    <div style="font-size:11px; color:#4b5563; margin-top:2px; white-space:pre-wrap; line-height:1.4; max-height:40px; overflow:hidden;">
                        <?= mb_substr(htmlspecialchars($notif['noiDung']), 0, 120) . (mb_strlen($notif['noiDung']) > 120 ? '...' : '') ?>
                    </div>
                    <div style="font-size:10px; color:#6b7280; margin-top:3px;">
                        <i class="fas fa-clock"></i> <?= date('d/m/Y H:i', strtotime($notif['thoiGian'])) ?>
                    </div>
                </div>
            </div>
            <div style="display:flex; gap:8px; align-items:center; flex-shrink:0;">
                <?php if ($notif['maKH']): ?>
                <a href="chi_tiet_khach_hang.php?id=<?= $notif['maKH'] ?>&tab=ky-thuat&phieu=<?= $notif['maPhieu'] ?>" 
                   style="background:<?= $borderColor ?>; color:#fff; border:none; padding:6px 14px; border-radius:8px; font-size:11.5px; font-weight:600; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:5px;"
                   onmouseover="this.style.opacity='0.85'" onmouseout="this.style.opacity='1'">
                    <i class="fas fa-eye"></i> Xem phiếu
                </a>
                <?php endif; ?>
                <button onclick="event.stopPropagation(); markNotifRead(<?= $notif['id'] ?>)" style="
                    background:transparent; border:1px solid <?= $borderColor ?>;
                    color:<?= $textColor ?>; padding:6px 10px; border-radius:6px;
                    font-size:11px; cursor:pointer; display:inline-flex; align-items:center; gap:4px;
                ">
                    <i class="fas fa-check"></i> Đã đọc
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
function viewPhieuSuaChua(maKH, maPhieu) {
    if (maKH) {
        window.location.href = 'chi_tiet_khach_hang.php?id=' + maKH + '&tab=ky-thuat&phieu=' + maPhieu;
    } else {
        window.location.href = 'quan_ly_phieu_sua_chua.php';
    }
}

function markNotifRead(id) {
    fetch('notification_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=doc&id=' + id
    }).then(() => {
        location.reload();
    }).catch(() => {
        location.reload();
    });
}
</script>
<?php endif; ?>
=======
</div>
>>>>>>> fc0888887465ac6d64caa80abe4294c237f2aa7d

<!-- ── STATS + CHART ── -->
<div class="dashboard-row">
    <div class="stats-col">
        <div class="stat-card">
            <div class="s-icon si-green"><i class="fas fa-users"></i></div>
            <div><div class="s-num"><?= number_format($stats['tong'] ?? 0) ?></div><div class="s-lbl">Tổng khách hàng</div></div>
        </div>
        <div class="stat-card">
            <div class="s-icon si-blue"><i class="fas fa-user-check"></i></div>
            <div><div class="s-num"><?= number_format($stats['hoat_dong'] ?? 0) ?></div><div class="s-lbl">Đang hoạt động</div></div>
        </div>
        <div class="stat-card">
            <div class="s-icon si-red"><i class="fas fa-user-slash"></i></div>
            <div><div class="s-num"><?= number_format($stats['da_khoa'] ?? 0) ?></div><div class="s-lbl">Đã khóa</div></div>
        </div>
        <div class="stat-card">
            <div class="s-icon si-amber"><i class="fas fa-gem"></i></div>
            <div><div class="s-num"><?= number_format($stats['vip'] ?? 0) ?></div><div class="s-lbl">Khách VIP</div></div>
        </div>
    </div>

    <!-- DONUT CHART -->
    <?php
    $loaiList = [
        'Khách truy cập' => '#64748b',
        'Quan tâm'       => '#3b82f6',
        'Đã sử dụng'     => '#16c79a',
        'Quay lại'       => '#8b5cf6',
        'Thân thiết'     => '#f59e0b',
        'Trung thành'    => '#f97316',
        'Ngừng sử dụng'  => '#ef4444',
    ];
    $chartLabels = $chartData = $chartColors = [];
    $total = array_sum($dist);
    foreach($loaiList as $n => $c) {
        if(isset($dist[$n]) && $dist[$n] > 0) {
            $chartLabels[] = $n;
            $chartData[]   = $dist[$n];
            $chartColors[] = $c;
        }
    }
    ?>
    <div class="chart-card">
        <div class="chart-card-title"><i class="fas fa-chart-donut" style="color:var(--pri);margin-right:5px;"></i> Phân Bố Giai Đoạn</div>
        <?php if($total > 0): ?>
        <div class="chart-inner">
            <div class="chart-canvas-wrap">
                <canvas id="donutChart" width="110" height="110"></canvas>
            </div>
            <div class="chart-legend">
                <?php foreach($chartLabels as $i => $lbl): $pct = $total > 0 ? round($chartData[$i]/$total*100) : 0; ?>
                <div class="legend-item">
                    <div class="legend-dot" style="background:<?= $chartColors[$i] ?>;"></div>
                    <span class="legend-name"><?= $lbl ?></span>
                    <span class="legend-pct"><?= $pct ?>%</span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <div style="flex:1;display:flex;align-items:center;justify-content:center;color:var(--muted);font-size:13px;">Chưa có dữ liệu</div>
        <?php endif; ?>
    </div>
</div>

<!-- ── FILTER BAR ── -->
<form method="GET" class="filter-bar">
    <div class="fg" style="flex:2;min-width:190px;">
        <label>Tìm kiếm</label>
        <input type="text" name="search" placeholder="Tên, SĐT, email..." value="<?= htmlspecialchars($search) ?>">
    </div>
    <div class="fg">
        <label>Giai đoạn</label>
        <select name="filter_loai">
            <option value="">Tất cả</option>
            <?php foreach(array_keys($loaiList) as $l): ?>
            <option value="<?= $l ?>" <?= ($filter_loai==$l)?'selected':'' ?>><?= $l ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="fg">
        <label>Trạng thái</label>
        <select name="filter_tt">
            <option value="">Tất cả</option>
            <option value="1" <?= ($filter_tt==='1')?'selected':'' ?>>Hoạt động</option>
            <option value="0" <?= ($filter_tt==='0')?'selected':'' ?>>Đã khóa</option>
        </select>
    </div>
    <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Lọc</button>
    <a href="quan_ly_khach_hang.php" class="btn-clear"><i class="fas fa-times"></i> Xóa lọc</a>
    <a href="quan_ly_khach_hang.php?export_csv=1" class="btn-export"><i class="fas fa-file-csv"></i> Xuất CSV</a>
</form>

<!-- ── TABLE ── -->
<div class="tbl-wrap">
    <div class="tbl-top">
        <div class="tbl-ttl">
            <i class="fas fa-users" style="color:var(--pri);"></i>
            Danh sách
            <?php if($result): ?><span class="cnt-badge"><?= $result->num_rows ?> khách hàng</span><?php endif; ?>
        </div>
        <button class="btn-add" onclick="openAddModal()"><i class="fas fa-plus"></i> Thêm Khách Hàng</button>
    </div>

    <table id="mainTable">
        <thead>
            <tr>
                <th onclick="sortTable(0)" data-col="0">Mã KH<span class="sort-icon">⇅</span></th>
                <th onclick="sortTable(1)" data-col="1">Khách Hàng<span class="sort-icon">⇅</span></th>
                <th onclick="sortTable(2)" data-col="2">Ngày Sinh<span class="sort-icon">⇅</span></th>
                <th>Giai Đoạn</th>
                <th>Giao Dịch</th>
                <th onclick="sortTable(5)" data-col="5">Ngày ĐK<span class="sort-icon">⇅</span></th>
                <th>Thao Tác</th>
            </tr>
        </thead>
        <tbody id="tableBody">
        <?php
        $badgeMap = [
            'Khách truy cập' => ['b-visitor',  '🌐'],
            'Quan tâm'       => ['b-interest', '❤️'],
            'Đã sử dụng'     => ['b-used',     '✅'],
            'Quay lại'       => ['b-return',   '🔄'],
            'Thân thiết'     => ['b-loyal',    '⭐'],
            'Trung thành'    => ['b-vip',      '💎'],
            'Ngừng sử dụng'  => ['b-inactive', '⏸'],
        ];
        if($result && $result->num_rows > 0):
            while($row = $result->fetch_assoc()):
                [$bc,$bi] = $badgeMap[$row['loaiKhachHang']] ?? ['b-visitor','🌐'];
                $av  = avatarInitials($row['tenKH']);
                $avc = avatarColor($row['tenKH']);
                $age = '';
                if(!empty($row['ngaySinh'])) {
                    $dob = new DateTime($row['ngaySinh']);
                    $age = $dob->diff(new DateTime())->y . ' tuổi';
                }
                $chiTieu = number_format($row['tongChiTieu'] ?? 0, 0, ',', '.');
        ?>
        <tr>
            <td><span class="cust-id">#<?= $row['maKH'] ?></span></td>
            <td>
                <!-- TOOLTIP bọc avatar + tên -->
                <div class="tooltip-wrap">
                    <div class="av-wrap">
                        <div class="avatar" style="background:<?= $avc['bg'] ?>;color:<?= $avc['fg'] ?>;"><?= $av ?></div>
                        <div>
                            <div class="cust-name"><?= htmlspecialchars($row['tenKH']) ?></div>
                            <div class="cust-meta">
                                <span><i class="fas fa-phone-alt"></i><?= htmlspecialchars($row['soDienThoai']) ?></span>
                                <?php if($row['email']): ?>
                                <span><i class="fas fa-envelope"></i><?= htmlspecialchars($row['email']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <!-- TOOLTIP BOX -->
                    <div class="tooltip-box">
                        <?php if($row['diaChi']): ?>
                        <div class="tt-row"><i class="fas fa-map-marker-alt"></i><?= htmlspecialchars($row['diaChi']) ?></div>
                        <?php endif; ?>
                        <?php if(!empty($row['ngaySinh'])): ?>
                        <div class="tt-row"><i class="fas fa-birthday-cake"></i><?= date('d/m/Y', strtotime($row['ngaySinh'])) ?> (<?= $age ?>)</div>
                        <?php endif; ?>
                        <div class="tt-row"><i class="fas fa-calendar-alt"></i>Đăng ký: <?= date('d/m/Y', strtotime($row['ngayDangKy'])) ?></div>
                        <hr class="tt-divider">
                        <div class="tt-stat"><span class="lbl">Tổng đơn hàng</span><span class="val"><?= $row['tongLanMua'] ?> đơn</span></div>
                        <div class="tt-stat" style="margin-top:5px;"><span class="lbl">Tổng chi tiêu</span><span class="val"><?= $chiTieu ?> đ</span></div>
                    </div>
                </div>
            </td>
            <td>
                <?php if(!empty($row['ngaySinh'])): ?>
                <div class="date-cell"><?= date('d/m/Y', strtotime($row['ngaySinh'])) ?></div>
                <div class="date-sub"><?= $age ?></div>
                <?php else: ?>
                <span style="color:var(--muted);font-size:12px;">—</span>
                <?php endif; ?>
            </td>
            <td>
                <span class="badge <?= $bc ?>"><?= $bi ?> <?= htmlspecialchars($row['loaiKhachHang']) ?></span>
                <div class="status-row">
                    <span class="dot <?= $row['trangThai'] ? 'on' : 'off' ?>"></span>
                    <?= $row['trangThai'] ? 'Hoạt động' : 'Đã khóa' ?>
                </div>
            </td>
            <td>
                <div class="tx-row"><div class="tx-ico g"><i class="fas fa-box"></i></div><span class="tx-num"><?= $row['tongLanMua'] ?></span><span style="color:var(--muted);font-size:11.5px;">đơn hàng</span></div>
                <div class="tx-row"><div class="tx-ico r"><i class="fas fa-tools"></i></div><span class="tx-num"><?= $row['tongLanSua'] ?></span><span style="color:var(--muted);font-size:11.5px;">phiếu sửa</span></div>
            </td>
            <td><div class="date-cell"><?= date('d/m/Y', strtotime($row['ngayDangKy'])) ?></div></td>
            <td>
                <div class="act-btns">
                    <a href="chi_tiet_khach_hang.php?id=<?= $row['maKH'] ?>" class="btn-ico v" title="Chi tiết"><i class="fas fa-eye"></i></a>
                    <button type="button" class="btn-ico e" title="Chỉnh sửa"
                        onclick="openEditModal(<?= $row['maKH'] ?>,'<?= addslashes(htmlspecialchars($row['tenKH'])) ?>','<?= $row['soDienThoai'] ?>','<?= addslashes($row['email']) ?>','<?= addslashes($row['diaChi']) ?>','<?= $row['ngaySinh'] ?? '' ?>','<?= $row['loaiKhachHang'] ?>',<?= $row['trangThai'] ?>,'<?= $row['ngayDangKy'] ?>')">
                        <i class="fas fa-pen"></i>
                    </button>
                    <button type="button" class="btn-ico d" title="Xóa"
                        onclick="confirmDelete('quan_ly_khach_hang.php?xoa_id=<?= $row['maKH'] ?>','<?= addslashes(htmlspecialchars($row['tenKH'])) ?>')">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </td>
        </tr>
        <?php endwhile; else: ?>
        <tr><td colspan="7" class="empty-cell">
            <div class="empty-ico"><i class="fas fa-users-slash"></i></div>
            <div class="empty-t">Không tìm thấy khách hàng nào</div>
            <div class="empty-s">Thử thay đổi bộ lọc hoặc thêm mới</div>
        </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

</main>

<!-- ── MODAL THÊM / SỬA ── -->
<div class="modal-ov" id="customerModal">
    <div class="modal-box">
        <div class="m-head">
            <div class="m-icon"><i class="fas fa-user-edit"></i></div>
            <div class="m-title">
                <h2 id="modalTitle">Thêm Khách Hàng Mới</h2>
                <p id="modalSub">Điền đầy đủ thông tin để tạo hồ sơ</p>
            </div>
            <button class="m-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
        </div>

        <div class="m-body">
            <?php if(!empty($formErrors)): ?>
            <div class="srv-errors">
                <div class="se-t"><i class="fas fa-exclamation-triangle"></i> Dữ liệu không hợp lệ</div>
                <ul><?php foreach($formErrors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
            </div>
            <?php endif; ?>

            <form method="POST" id="customerForm">
                <input type="hidden" name="maKH" id="fld_id">

                <!-- THÔNG TIN CÁ NHÂN -->
                <div class="f-section">
                    <div class="f-sec-title"><i class="fas fa-id-card"></i> Thông Tin Cá Nhân</div>
                    <div class="f-grid">
                        <div class="field" id="grp_ten">
                            <label><i class="fas fa-user"></i> Họ và Tên <span class="req">*</span></label>
                            <input type="text" name="tenKH" id="fld_ten" placeholder="Nguyễn Văn A" value="<?= htmlspecialchars($old['tenKH'] ?? '') ?>">
                            <span class="f-hint"><i class="fas fa-info-circle"></i> Tối thiểu 3 ký tự</span>
                            <span class="f-err" id="err_ten"><i class="fas fa-exclamation-circle"></i><span></span></span>
                        </div>
                        <div class="field" id="grp_sdt">
                            <label><i class="fas fa-phone-alt"></i> Số Điện Thoại <span class="req">*</span></label>
                            <input type="tel" name="soDienThoai" id="fld_sdt" placeholder="0912345678" maxlength="10" value="<?= htmlspecialchars($old['soDienThoai'] ?? '') ?>">
                            <span class="f-hint"><i class="fas fa-info-circle"></i> Đúng 10 chữ số</span>
                            <span class="f-err" id="err_sdt"><i class="fas fa-exclamation-circle"></i><span></span></span>
                        </div>
                        <div class="field" id="grp_email">
                            <label><i class="fas fa-envelope"></i> Email</label>
                            <input type="email" name="email" id="fld_email" placeholder="example@gmail.com" value="<?= htmlspecialchars($old['email'] ?? '') ?>">
                            <span class="f-hint"><i class="fas fa-info-circle"></i> Phải chứa ký tự @</span>
                            <span class="f-err" id="err_email"><i class="fas fa-exclamation-circle"></i><span></span></span>
                        </div>
                        <div class="field" id="grp_ns">
                            <label><i class="fas fa-birthday-cake"></i> Ngày Sinh</label>
                            <input type="date" name="ngaySinh" id="fld_ns" value="<?= htmlspecialchars($old['ngaySinh'] ?? '') ?>">
                            <span class="f-hint"><i class="fas fa-info-circle"></i> Không lớn hơn ngày hiện tại</span>
                            <span class="f-err" id="err_ns"><i class="fas fa-exclamation-circle"></i><span></span></span>
                        </div>
                        <div class="field f-full">
                            <label><i class="fas fa-map-marker-alt"></i> Địa Chỉ</label>
                            <input type="text" name="diaChi" id="fld_diachi" placeholder="Số nhà, đường, phường/xã, quận/huyện, tỉnh/TP" value="<?= htmlspecialchars($old['diaChi'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <!-- PHÂN LOẠI -->
                <div class="f-section">
                    <div class="f-sec-title"><i class="fas fa-chart-line"></i> Phân Loại & Trạng Thái</div>
                    <div class="f-grid">
                        <div class="field">
                            <label><i class="fas fa-tag"></i> Giai Đoạn Vòng Đời</label>
                            <select name="loaiKhachHang" id="fld_loai">
                                <option value="Khách truy cập">🌐 Khách truy cập</option>
                                <option value="Quan tâm">❤️ Quan tâm — Đã liên hệ</option>
                                <option value="Đã sử dụng">✅ Đã sử dụng — Có giao dịch</option>
                                <option value="Quay lại">🔄 Quay lại — Tiếp tục dùng</option>
                                <option value="Thân thiết">⭐ Thân thiết — Tích lũy điểm</option>
                                <option value="Trung thành">💎 Trung thành — VIP</option>
                                <option value="Ngừng sử dụng">⏸ Ngừng sử dụng</option>
                            </select>
                        </div>
                        <div class="field">
                            <label><i class="fas fa-toggle-on"></i> Trạng Thái</label>
                            <select name="trangThai" id="fld_tt">
                                <option value="1">🟢 Đang hoạt động</option>
                                <option value="0">🔴 Đã khóa</option>
                            </select>
                        </div>
                        <div class="field" id="grp_ngaydk">
                            <label><i class="fas fa-calendar-alt"></i> Ngày Đăng Ký</label>
                            <input type="date" name="ngayDangKy" id="fld_ngaydk" value="<?= htmlspecialchars($old['ngayDangKy'] ?? date('Y-m-d')) ?>">
                            <span class="f-hint"><i class="fas fa-info-circle"></i> Không lớn hơn hôm nay</span>
                            <span class="f-err" id="err_ngaydk"><i class="fas fa-exclamation-circle"></i><span></span></span>
                        </div>
                    </div>
                </div>

                <button type="submit" name="luu_khach_hang" id="hiddenSubmit" style="display:none;"></button>
            </form>
        </div>

        <div class="m-foot">
            <button class="btn-cancel" onclick="closeModal()"><i class="fas fa-times"></i> Hủy</button>
            <button class="btn-save" onclick="submitForm()"><i class="fas fa-save"></i> <span id="btnTxt">Lưu Khách Hàng</span></button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script>
// ── DONUT CHART ──
<?php if($total > 0): ?>
(function() {
    const ctx = document.getElementById('donutChart');
    if (!ctx) return;
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($chartLabels) ?>,
            datasets: [{
                data: <?= json_encode($chartData) ?>,
                backgroundColor: <?= json_encode($chartColors) ?>,
                borderWidth: 2,
                borderColor: '#ffffff',
                hoverBorderWidth: 0,
            }]
        },
        options: {
            responsive: false,
            cutout: '72%',
            plugins: { legend: { display: false }, tooltip: {
                callbacks: { label: ctx => ' ' + ctx.label + ': ' + ctx.raw }
            }}
        }
    });
})();
<?php endif; ?>

// ── SORT TABLE ──
let sortDir = {};
function sortTable(col) {
    const tb = document.getElementById('tableBody');
    const rows = Array.from(tb.querySelectorAll('tr'));
    if (!rows.length || rows[0].cells.length === 1) return;
    sortDir[col] = !sortDir[col];
    rows.sort((a, b) => {
        const at = a.cells[col]?.innerText.trim() ?? '';
        const bt = b.cells[col]?.innerText.trim() ?? '';
        const an = parseFloat(at.replace(/\D/g,'')), bn = parseFloat(bt.replace(/\D/g,''));
        let cmp = isNaN(an) || isNaN(bn) ? at.localeCompare(bt,'vi') : an - bn;
        return sortDir[col] ? cmp : -cmp;
    });
    rows.forEach(r => tb.appendChild(r));
    document.querySelectorAll('thead th').forEach((th,i) => {
        th.classList.toggle('sorted', i === col);
        const si = th.querySelector('.sort-icon');
        if (si) si.textContent = i === col ? (sortDir[col] ? '↑' : '↓') : '⇅';
    });
}

// ── MODAL ──
const modal  = document.getElementById('customerModal');
const TODAY  = new Date().toISOString().split('T')[0];
document.getElementById('fld_ns').max     = TODAY;
document.getElementById('fld_ngaydk').max = TODAY;

function openAddModal() {
    document.getElementById('customerForm').reset();
    document.getElementById('fld_id').value = '';
    document.getElementById('fld_ngaydk').value = TODAY;
    document.getElementById('modalTitle').textContent = 'Thêm Khách Hàng Mới';
    document.getElementById('modalSub').textContent   = 'Điền đầy đủ thông tin để tạo hồ sơ';
    document.getElementById('btnTxt').textContent     = 'Lưu Khách Hàng';
    document.getElementById('grp_ngaydk').style.display = '';
    clearAllErrors();
    modal.classList.add('open');
}

function openEditModal(id,ten,sdt,email,diaChi,ns,loai,tt,ngaydk) {
    document.getElementById('fld_id').value      = id;
    document.getElementById('fld_ten').value     = ten;
    document.getElementById('fld_sdt').value     = sdt;
    document.getElementById('fld_email').value   = email || '';
    document.getElementById('fld_diachi').value  = diaChi || '';
    document.getElementById('fld_ns').value      = ns || '';
    document.getElementById('fld_loai').value    = loai;
    document.getElementById('fld_tt').value      = String(tt);
    document.getElementById('fld_ngaydk').value  = ngaydk || TODAY;
    document.getElementById('modalTitle').textContent = 'Chỉnh Sửa Thông Tin';
    document.getElementById('modalSub').textContent   = 'Cập nhật hồ sơ khách hàng #' + id;
    document.getElementById('btnTxt').textContent     = 'Cập Nhật';
    document.getElementById('grp_ngaydk').style.display = 'none';
    clearAllErrors();
    modal.classList.add('open');
}

function closeModal() { modal.classList.remove('open'); }
modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });

// Auto-reopen nếu có lỗi server
<?php if(!empty($formErrors)): ?>
window.addEventListener('DOMContentLoaded', () => {
    <?php if(!empty($old['maKH'])): ?>
    openEditModal('<?= $old['maKH'] ?>','<?= addslashes($old['tenKH'] ?? '') ?>','<?= addslashes($old['soDienThoai'] ?? '') ?>','<?= addslashes($old['email'] ?? '') ?>','<?= addslashes($old['diaChi'] ?? '') ?>','<?= $old['ngaySinh'] ?? '' ?>','<?= $old['loaiKhachHang'] ?? 'Khách truy cập' ?>','<?= $old['trangThai'] ?? '1' ?>','<?= $old['ngayDangKy'] ?? date('Y-m-d') ?>');
    <?php else: ?>
    openAddModal();
    <?php endif; ?>
});
<?php endif; ?>

// ── VALIDATION ──
function setErr(fld, errId, msg) {
    document.getElementById(fld)?.classList.add('err');
    const e = document.getElementById(errId);
    if (e) { e.querySelector('span').textContent = msg; e.classList.add('show'); }
}
function clrErr(fld, errId) {
    document.getElementById(fld)?.classList.remove('err');
    document.getElementById(errId)?.classList.remove('show');
}
function clearAllErrors() {
    [['fld_ten','err_ten'],['fld_sdt','err_sdt'],['fld_email','err_email'],
     ['fld_ns','err_ns'],['fld_ngaydk','err_ngaydk']].forEach(([f,e]) => clrErr(f,e));
}

function validateForm() {
    let ok = true;
    clearAllErrors();
    const ten = document.getElementById('fld_ten').value.trim();
    if (!ten) { setErr('fld_ten','err_ten','Họ tên không được để trống'); ok=false; }
    else if (ten.length < 3) { setErr('fld_ten','err_ten','Họ tên phải có ít nhất 3 ký tự'); ok=false; }

    const sdt = document.getElementById('fld_sdt').value.trim();
    if (!sdt) { setErr('fld_sdt','err_sdt','Số điện thoại không được để trống'); ok=false; }
    else if (!/^[0-9]{10}$/.test(sdt)) { setErr('fld_sdt','err_sdt','Số điện thoại phải đúng 10 chữ số (VD: 0912345678)'); ok=false; }

    const email = document.getElementById('fld_email').value.trim();
    if (email) {
        if (!email.includes('@')) { setErr('fld_email','err_email','Email phải chứa ký tự @'); ok=false; }
        else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { setErr('fld_email','err_email','Email không đúng định dạng'); ok=false; }
    }

    const ns = document.getElementById('fld_ns').value;
    if (ns) {
        if (ns > TODAY) { setErr('fld_ns','err_ns','Ngày sinh không được lớn hơn hôm nay'); ok=false; }
        else if (ns < (new Date().getFullYear()-120)+'-01-01') { setErr('fld_ns','err_ns','Ngày sinh không hợp lệ'); ok=false; }
    }

    const dk = document.getElementById('fld_ngaydk').value;
    if (dk && dk > TODAY) { setErr('fld_ngaydk','err_ngaydk','Ngày đăng ký không được lớn hơn hôm nay'); ok=false; }

    return ok;
}

function submitForm() {
    if (!validateForm()) {
        document.querySelector('.f-err.show')?.scrollIntoView({ behavior:'smooth', block:'center' });
        return;
    }
    document.getElementById('hiddenSubmit').click();
}

// Realtime input — chỉ cho nhập số vào SĐT
document.getElementById('fld_ten').addEventListener('input',  () => clrErr('fld_ten','err_ten'));
document.getElementById('fld_sdt').addEventListener('input',  function() { this.value=this.value.replace(/\D/g,'').slice(0,10); clrErr('fld_sdt','err_sdt'); });
document.getElementById('fld_email').addEventListener('input',() => clrErr('fld_email','err_email'));
document.getElementById('fld_ns').addEventListener('change',  () => clrErr('fld_ns','err_ns'));
document.getElementById('fld_ngaydk').addEventListener('change',() => clrErr('fld_ngaydk','err_ngaydk'));

// ── XÓA ──
function confirmDelete(url, name) {
    Swal.fire({
        title: 'Xóa khách hàng?',
        html: `Bạn sắp xóa <strong>${name}</strong>.<br>Thao tác này <strong>không thể hoàn tác</strong>!`,
        icon: 'warning',
        background: '#ffffff',
        color: '#111827',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#6b7280',
        confirmButtonText: '<i class="fas fa-trash"></i> Xóa vĩnh viễn',
        cancelButtonText: 'Hủy bỏ',
    }).then(r => { if(r.isConfirmed) window.location.href = url; });
}
</script>
<script>
/* ── Polling thông báo đơn hàng online (mỗi 30 giây) ── */
(function pollOrderNotif() {
    function check() {
        fetch('notification_api.php')
            .then(r => r.json())
            .then(d => {
                const badge = document.getElementById('online-order-badge');
                if (!badge) return;
                if (d.count > 0) {
                    badge.textContent = d.count;
                    badge.style.display = 'inline-flex';
                    badge.style.alignItems = 'center';
                    badge.style.justifyContent = 'center';
                    document.title = '(' + d.count + ') Đơn online mới – QA Admin';
                } else {
                    badge.style.display = 'none';
                    document.title = document.title.replace(/^\(\d+\)\s*/, '');
                }
            }).catch(() => {});
    }
    check();
    setInterval(check, 30000);
})();
</script>
<<<<<<< HEAD
<!-- Panel thông báo admin -->
<div class="notif-panel-admin" id="adminNotifPanel">
    <div class="notif-header-admin">
        <span><i class="fas fa-bell me-2"></i>Thông báo từ KTV</span>
        <button onclick="docTatCaAdmin()">Đánh dấu đã đọc</button>
    </div>
    <div id="adminNotifList">
        <div style="text-align:center;padding:30px;color:#94a3b8">
            <i class="fas fa-spinner fa-spin"></i> Đang tải...
        </div>
    </div>
    <div style="padding:12px 18px;border-top:1px solid var(--bdr);text-align:center">
        <a href="quan_ly_phieu_sua_chua.php" style="font-size:12px;color:#065f46;font-weight:600;text-decoration:none">
            <i class="fas fa-external-link-alt me-1"></i>Xem tất cả phiếu sửa chữa
        </a>
    </div>
</div>

<script>
// ── ADMIN NOTIFICATIONS ──
let adminNotifOpen = false;

function toggleAdminNotifPanel() {
    adminNotifOpen = !adminNotifOpen;
    const panel = document.getElementById('adminNotifPanel');
    panel.classList.toggle('open', adminNotifOpen);
    if (adminNotifOpen) loadAdminNotif();
}

document.addEventListener('click', function(e) {
    const panel = document.getElementById('adminNotifPanel');
    const bell  = document.getElementById('adminBellBtn');
    if (panel && bell && !panel.contains(e.target) && !bell.contains(e.target)) {
        panel.classList.remove('open');
        adminNotifOpen = false;
    }
});

function loadAdminNotif() {
    fetch('notification_api.php?action=list')
        .then(r => r.json())
        .then(d => {
            const list = document.getElementById('adminNotifList');
            if (!d.items || d.items.length === 0) {
                list.innerHTML = '<div style="text-align:center;padding:28px;color:#94a3b8;font-size:13px">' +
                    '<i class="fas fa-check-circle" style="font-size:24px;display:block;margin-bottom:8px;color:#10b981"></i>' +
                    'Không có thông báo mới</div>';
                return;
            }
            
            const ttMap = {
                'chua_doc': '🔴 Chưa đọc',
                'da_doc': '⚪ Đã đọc'
            };
            
            list.innerHTML = d.items.map(tb => {
                const unread = tb.trangThai === 'chua_doc';
                let noiDungHtml = (tb.noiDung || '').replace(/\n/g,'<br>');
                if (noiDungHtml.length > 400) {
                    noiDungHtml = noiDungHtml.substring(0, 400) + '...';
                }
                
                let actionBtn = '';
                if (tb.loai === 'phan_cong' || tb.loai === 'tu_choi') {
                    actionBtn = `<div class="notif-actions-admin">
                        <a href="chi_tiet_khach_hang.php?id=${tb.maPhieu ? '?id=' + tb.maPhieu : '#'}" class="btn-view-phieu">
                            <i class="fas fa-eye"></i> Xem phiếu
                        </a>
                    </div>`;
                }
                
                return `<div class="notif-item-admin ${unread ? 'unread' : ''}" id="admin-tb-${tb.id}" onclick="docAdminNotif(${tb.id})">
                    <div class="notif-title-admin">${tb.tieuDe || ''}</div>
                    <div class="notif-sub-admin">${noiDungHtml}</div>
                    <div class="notif-time-admin">${tb.thoiGianFormat || tb.thoiGian || ''} · ${ttMap[tb.trangThai] || tb.trangThai}</div>
                    ${actionBtn}
                </div>`;
            }).join('');
        });
}

function docAdminNotif(id) {
    const fd = new FormData();
    fd.append('action', 'doc');
    fd.append('id', id);
    fetch('notification_api.php', {method:'POST', body:fd})
        .then(r => r.json())
        .then(() => {
            loadAdminNotif();
            updateAdminBadge();
        });
}

function docTatCaAdmin() {
    const fd = new FormData();
    fd.append('action', 'doc');
    fd.append('id', '0');
    fetch('notification_api.php', {method:'POST', body:fd})
        .then(r => r.json())
        .then(() => {
            loadAdminNotif();
            updateAdminBadge();
        });
}

function updateAdminBadge() {
    fetch('notification_api.php?action=count')
        .then(r => r.json())
        .then(d => {
            const dot = document.getElementById('adminNotifDot');
            if (d.count > 0) {
                dot.textContent = d.count;
                dot.style.display = 'flex';
            } else {
                dot.style.display = 'none';
            }
        });
}

// Poll thông báo admin mỗi 30 giây
setInterval(() => {
    updateAdminBadge();
    if (adminNotifOpen) loadAdminNotif();
}, 30000);

// Khởi tạo badge khi load trang
updateAdminBadge();
</script>
=======
>>>>>>> fc0888887465ac6d64caa80abe4294c237f2aa7d
</body>
</html>