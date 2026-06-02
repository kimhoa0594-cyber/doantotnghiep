<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

// ========================
// TẠO BẢNG NẾU CHƯA CÓ
// ========================
$conn->query("CREATE TABLE IF NOT EXISTS `nhan_vien` (
    `maNV`        int(11)      NOT NULL AUTO_INCREMENT,
    `tenNV`       varchar(100) NOT NULL,
    `email`       varchar(100) DEFAULT NULL,
    `soDienThoai` varchar(15)  DEFAULT NULL,
    `diaChi`      varchar(255) DEFAULT NULL,
    `ngaySinh`    date         DEFAULT NULL,
    `chucVu`      varchar(50)  DEFAULT 'Nhân viên',
    `phongBan`    varchar(100) DEFAULT NULL,
    `ngayVaoLam`  date         DEFAULT (CURRENT_DATE),
    `trangThai`   tinyint(1)   DEFAULT 1,
    PRIMARY KEY (`maNV`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ========================
// VALIDATION
// ========================
function validateStaffData($data) {
    $errors = [];
    $ten = trim($data['tenNV'] ?? '');
    if (empty($ten))         $errors['tenNV'] = 'Họ tên không được để trống';
    elseif (strlen($ten) < 3) $errors['tenNV'] = 'Họ tên phải có ít nhất 3 ký tự';

    $sdt = trim($data['soDienThoai'] ?? '');
    if (!empty($sdt) && !preg_match('/^[0-9]{10}$/', $sdt))
        $errors['soDienThoai'] = 'Số điện thoại phải đúng 10 chữ số';

    $email = trim($data['email'] ?? '');
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors['email'] = 'Email không đúng định dạng';

    $today = date('Y-m-d');
    if (!empty($data['ngaySinh'])) {
        if ($data['ngaySinh'] > $today)
            $errors['ngaySinh'] = 'Ngày sinh không được lớn hơn hôm nay';
        elseif ($data['ngaySinh'] < date('Y-m-d', strtotime('-80 years')))
            $errors['ngaySinh'] = 'Ngày sinh không hợp lệ';
    }
    if (!empty($data['ngayVaoLam']) && $data['ngayVaoLam'] > $today)
        $errors['ngayVaoLam'] = 'Ngày vào làm không được lớn hơn hôm nay';

    return $errors;
}

// ========================
// XÓA
// ========================
if (isset($_GET['xoa_id'])) {
    $conn->query("DELETE FROM nhan_vien WHERE maNV=" . (int)$_GET['xoa_id']);
    $_SESSION['success'] = 'Đã xóa nhân viên thành công';
    header("Location: quan_ly_nhan_vien.php"); exit();
}

// ========================
// THÊM / SỬA
// ========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['luu_nhan_vien'])) {
    $maNV   = $_POST['maNV'] ?? '';
    $errors = validateStaffData($_POST);
    if (empty($errors)) {
        $tenNV   = $conn->real_escape_string(trim($_POST['tenNV']));
        $email   = $conn->real_escape_string(trim($_POST['email'] ?? ''));
        $sdt     = $conn->real_escape_string(trim($_POST['soDienThoai'] ?? ''));
        $diaChi  = $conn->real_escape_string(trim($_POST['diaChi'] ?? ''));
        $ns      = !empty($_POST['ngaySinh'])   ? "'".$conn->real_escape_string($_POST['ngaySinh'])."'"   : 'NULL';
        $chucVu  = $conn->real_escape_string($_POST['chucVu']);
        $phongBan= $conn->real_escape_string(trim($_POST['phongBan'] ?? ''));
        $tt      = (int)$_POST['trangThai'];

        if (!empty($maNV)) {
            $conn->query("UPDATE nhan_vien SET tenNV='$tenNV',email='$email',soDienThoai='$sdt',
                diaChi='$diaChi',ngaySinh=$ns,chucVu='$chucVu',phongBan='$phongBan',trangThai=$tt
                WHERE maNV=".(int)$maNV);
            $_SESSION['success'] = 'Cập nhật thông tin nhân viên thành công';
        } else {
            $ngayVL = !empty($_POST['ngayVaoLam'])
                ? $conn->real_escape_string($_POST['ngayVaoLam'])
                : date('Y-m-d');
            $conn->query("INSERT INTO nhan_vien(tenNV,email,soDienThoai,diaChi,ngaySinh,chucVu,phongBan,ngayVaoLam,trangThai)
                VALUES('$tenNV','$email','$sdt','$diaChi',$ns,'$chucVu','$phongBan','$ngayVL',$tt)");
            $_SESSION['success'] = 'Thêm nhân viên mới thành công';
        }
        header("Location: quan_ly_nhan_vien.php"); exit();
    } else {
        $_SESSION['errors']   = $errors;
        $_SESSION['old_data'] = $_POST;
        header("Location: quan_ly_nhan_vien.php"); exit();
    }
}

// ========================
// XUẤT CSV
// ========================
if (isset($_GET['export_csv'])) {
    $res = $conn->query("SELECT * FROM nhan_vien ORDER BY maNV DESC");
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="nhan_vien_'.date('Y-m-d').'.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Mã NV','Họ Tên','Email','SĐT','Địa Chỉ','Ngày Sinh','Chức Vụ','Phòng Ban','Ngày Vào Làm','Trạng Thái']);
    while ($r = $res->fetch_assoc())
        fputcsv($out, [
            $r['maNV'], $r['tenNV'], $r['email'], $r['soDienThoai'], $r['diaChi'],
            $r['ngaySinh'] ? date('d/m/Y', strtotime($r['ngaySinh'])) : '',
            $r['chucVu'], $r['phongBan'],
            date('d/m/Y', strtotime($r['ngayVaoLam'])),
            $r['trangThai'] ? 'Hoạt động' : 'Đã nghỉ'
        ]);
    fclose($out); exit();
}

// ========================
// LỌC & TRUY VẤN
// ========================
$search     = isset($_GET['search'])      ? $conn->real_escape_string($_GET['search'])      : '';
$filter_cv  = isset($_GET['filter_cv'])   ? $conn->real_escape_string($_GET['filter_cv'])   : '';
$filter_tt  = isset($_GET['filter_tt'])   ? $conn->real_escape_string($_GET['filter_tt'])   : '';

$sql = "SELECT * FROM nhan_vien WHERE 1=1";
if ($search)     $sql .= " AND (tenNV LIKE '%$search%' OR soDienThoai LIKE '%$search%' OR email LIKE '%$search%' OR phongBan LIKE '%$search%')";
if ($filter_cv)  $sql .= " AND chucVu='$filter_cv'";
if ($filter_tt !== '') $sql .= " AND trangThai='$filter_tt'";
$sql .= " ORDER BY maNV DESC";
$result = $conn->query($sql);

// Thống kê
$stats = $conn->query("SELECT
    COUNT(*) as tong,
    SUM(trangThai=1) as hoat_dong,
    SUM(trangThai=0) as da_nghi,
    SUM(chucVu='Kỹ thuật viên') as ky_thuat,
    SUM(chucVu='Nhân viên') as nhan_vien_thuong
FROM nhan_vien")->fetch_assoc();

// Sinh nhật hôm nay
$todayMD = date('m-d');
$bdayRes = $conn->query("SELECT maNV,tenNV,ngaySinh FROM nhan_vien
    WHERE DATE_FORMAT(ngaySinh,'%m-%d')='$todayMD' AND ngaySinh IS NOT NULL AND trangThai=1");
$birthdays = [];
while ($b = $bdayRes->fetch_assoc()) $birthdays[] = $b;

// Nhân viên chưa cập nhật đủ thông tin
$incompleteRes = $conn->query(
    "SELECT * FROM nhan_vien WHERE trangThai=1
     AND (soDienThoai IS NULL OR soDienThoai='' OR
          ngaySinh IS NULL OR
          diaChi IS NULL OR diaChi='' OR
          email IS NULL OR email='')
     ORDER BY ngayVaoLam DESC"
);
$incompleteList = [];
while ($ic = $incompleteRes->fetch_assoc()) $incompleteList[] = $ic;

// Old data
$old = $_SESSION['old_data'] ?? [];
$formErrors = $_SESSION['errors'] ?? [];
unset($_SESSION['old_data'], $_SESSION['errors']);

// Danh sách chức vụ
$chucVuList = ['Nhân viên', 'Kỹ thuật viên', 'Trưởng phòng', 'Quản lý', 'Giám đốc'];
$phongBanList = ['Kỹ thuật', 'Kinh doanh', 'Kế toán', 'Hành chính', 'Marketing', 'Ban giám đốc'];

// Avatar helper
function nvAvatarColor($name) {
    $colors = [
        ['bg'=>'#dbeafe','fg'=>'#1e40af'],['bg'=>'#ede9fe','fg'=>'#4c1d95'],
        ['bg'=>'#fce7f3','fg'=>'#831843'],['bg'=>'#fef3c7','fg'=>'#78350f'],
        ['bg'=>'#d1fae5','fg'=>'#065f46'],['bg'=>'#fee2e2','fg'=>'#7f1d1d'],
        ['bg'=>'#e0f2fe','fg'=>'#0c4a6e'],['bg'=>'#f0fdf4','fg'=>'#14532d'],
    ];
    return $colors[abs(crc32($name)) % count($colors)];
}
function nvAvatarInitials($name) {
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
<title>Quản Lý Nhân Viên — QA Tech</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:ital,wght@0,400;0,500;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

<style>
:root {
    --sw: 268px;
    --pri: #1d4ed8; --pri-d: #1e40af; --pri-l: #3b82f6;
    --pri-g: rgba(29,78,216,.10); --pri-bdr: rgba(29,78,216,.22);
    --bg: #eff6ff; --bg2: #ffffff; --bg3: #eff6ff;
    --card: #ffffff; --bdr: rgba(29,78,216,.12);
    --text: #111827; --muted: #3b5fa0; --white: #111827;
    --r: 14px; --sh: 0 4px 20px rgba(29,78,216,.10);
    --green: #059669; --red: #dc2626; --amber: #d97706; --purple: #6d28d9;
}
*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
html { scroll-behavior:smooth; }
body { font-family:'Sora',sans-serif; background:var(--bg); color:var(--text); display:flex; min-height:100vh; font-size:14px; line-height:1.6; }
::-webkit-scrollbar { width:5px; height:5px; }
::-webkit-scrollbar-track { background:#dbeafe; }
::-webkit-scrollbar-thumb { background:var(--pri); border-radius:99px; }

/* ── SIDEBAR ── */
.sidebar { width:var(--sw); background:#0f172a; border-right:none; height:100vh; position:fixed; display:flex; flex-direction:column; z-index:200; box-shadow:4px 0 20px rgba(0,0,0,.18); }
.sb-logo { padding:26px 22px 18px; border-bottom:1px solid rgba(255,255,255,.1); }
.sb-logo .brand { font-size:21px; font-weight:800; color:#fff; letter-spacing:-.5px; }
.sb-logo .brand span { color:#60a5fa; }
.sb-logo .sub { font-size:10px; letter-spacing:2px; color:rgba(255,255,255,.5); text-transform:uppercase; margin-top:4px; }
.sb-nav { flex:1; padding:14px 10px; overflow-y:auto; }
.nav-sec { font-size:10px; letter-spacing:2px; text-transform:uppercase; color:rgba(255,255,255,.4); padding:10px 12px 5px; font-weight:700; }
.nav-a { display:flex; align-items:center; gap:11px; padding:10px 13px; border-radius:9px; color:rgba(255,255,255,.65); text-decoration:none; font-weight:500; font-size:13px; transition:all .18s; margin-bottom:2px; border:1px solid transparent; }
.nav-a i { width:16px; font-size:14px; }
.nav-a:hover { background:rgba(255,255,255,.08); color:#fff; }
.nav-a.active { background:rgba(29,78,216,.55); color:#fff; border-color:rgba(59,130,246,.4); font-weight:700; }
.sb-foot { padding:14px 10px; border-top:1px solid rgba(255,255,255,.1); }

/* ── MAIN ── */
.main { margin-left:var(--sw); width:calc(100% - var(--sw)); padding:28px 32px; min-height:100vh; background:var(--bg); }

/* ── TOP ROW ── */
.top-row { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:22px; gap:16px; flex-wrap:wrap; }
.page-title h1 { font-size:24px; font-weight:800; color:var(--pri); letter-spacing:-.4px; }
.page-title p { font-size:12.5px; color:var(--muted); margin-top:3px; }

/* ── BIRTHDAY BANNER ── */
.bday-banner { background:linear-gradient(135deg,#eff6ff 0%,#dbeafe 100%); border:1px solid rgba(245,158,11,.35); border-radius:var(--r); padding:14px 20px; margin-bottom:20px; display:flex; align-items:center; gap:16px; flex-wrap:wrap; animation:slideDown .4s ease; box-shadow:var(--sh); }
.bday-icon { font-size:28px; flex-shrink:0; }
.bday-text strong { color:#92400e; font-weight:700; font-size:14px; }
.bday-text p { font-size:12.5px; color:#1f2937; margin-top:3px; }
.bday-names { display:flex; flex-wrap:wrap; gap:8px; margin-top:6px; }
.bday-chip { background:rgba(245,158,11,.14); border:1px solid rgba(245,158,11,.3); color:#78350f; font-size:12px; padding:3px 12px; border-radius:99px; font-weight:600; }

/* ── STATS ── */
.stats-grid { display:grid; grid-template-columns:repeat(5,1fr); gap:14px; margin-bottom:20px; }
.stat-card { background:var(--card); border:1px solid var(--bdr); border-radius:var(--r); padding:18px; display:flex; align-items:center; gap:14px; transition:all .2s; box-shadow:var(--sh); }
.stat-card:hover { transform:translateY(-2px); box-shadow:0 8px 28px rgba(29,78,216,.14); }
.s-icon { width:44px; height:44px; border-radius:11px; display:flex; align-items:center; justify-content:center; font-size:19px; flex-shrink:0; }
.si-blue   { background:rgba(29,78,216,.10);  color:var(--pri); }
.si-green  { background:rgba(5,150,105,.10);  color:var(--green); }
.si-red    { background:rgba(220,38,38,.10);  color:var(--red); }
.si-amber  { background:rgba(217,119,6,.10);  color:var(--amber); }
.si-purple { background:rgba(109,40,217,.10); color:var(--purple); }
.s-num { font-size:26px; font-weight:800; color:#111827; line-height:1; font-family:'DM Mono',monospace; }
.s-lbl { font-size:11.5px; color:var(--muted); margin-top:3px; }

/* ── INCOMPLETE BANNER ── */
.incomplete-banner {
    background:linear-gradient(135deg,#fffbeb 0%,#fef3c7 100%);
    border:1.5px solid #f59e0b; border-left:5px solid #d97706;
    border-radius:14px; margin-bottom:20px; overflow:hidden;
    box-shadow:0 4px 16px rgba(217,119,6,.13); animation:slideDown .4s ease;
}
.ib-head { display:flex; align-items:center; justify-content:space-between; padding:14px 20px 10px; border-bottom:1px solid rgba(217,119,6,.2); }
.ib-title { font-weight:800; color:#92400e; font-size:14.5px; }
.ib-sub   { font-size:12px; color:#78350f; margin-top:2px; }
.ib-close { background:rgba(217,119,6,.15); border:1.5px solid rgba(217,119,6,.3); color:#92400e; width:28px; height:28px; border-radius:7px; cursor:pointer; font-size:12px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.ib-list { padding:12px 20px 14px; display:flex; flex-direction:column; gap:8px; max-height:260px; overflow-y:auto; }
.ib-item { background:#fff; border:1.5px solid rgba(217,119,6,.2); border-radius:10px; padding:10px 14px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
.ib-av { width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:12px; flex-shrink:0; border:2px solid rgba(217,119,6,.2); }
.ib-name { font-weight:700; color:#111827; font-size:13.5px; }
.ib-id { font-family:'DM Mono',monospace; font-size:11px; color:#92400e; background:rgba(217,119,6,.1); padding:1px 7px; border-radius:5px; border:1px solid rgba(217,119,6,.2); margin-left:6px; }
.ib-missing-chip { display:inline-flex; align-items:center; gap:3px; background:rgba(220,38,38,.08); color:#991b1b; font-size:11px; padding:2px 8px; border-radius:99px; border:1px solid rgba(220,38,38,.18); margin-left:3px; font-weight:600; }
.btn-update-now { background:#d97706; color:#fff; border:none; padding:7px 16px; border-radius:8px; font-size:12.5px; font-weight:700; cursor:pointer; white-space:nowrap; font-family:'Sora',sans-serif; transition:all .18s; flex-shrink:0; }
.btn-update-now:hover { background:#b45309; }

/* ── FILTER BAR ── */
.filter-bar { background:var(--card); border:1px solid var(--bdr); border-radius:var(--r); padding:15px 18px; margin-bottom:18px; display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; box-shadow:var(--sh); }
.fg { display:flex; flex-direction:column; gap:4px; }
.fg label { font-size:10px; letter-spacing:1.5px; text-transform:uppercase; color:var(--muted); font-weight:700; }
.fg input,.fg select { background:#f8faff; border:1.5px solid var(--bdr); color:#111827; padding:8px 12px; border-radius:8px; font-family:'Sora',sans-serif; font-size:13px; outline:none; min-width:150px; transition:border-color .2s; }
.fg input:focus,.fg select:focus { border-color:var(--pri); box-shadow:0 0 0 3px rgba(29,78,216,.08); }
.fg input::placeholder { color:#9ca3af; }
.btn-filter { background:var(--pri); color:#fff; border:none; padding:8px 18px; border-radius:8px; font-weight:700; font-size:13px; cursor:pointer; font-family:'Sora',sans-serif; display:flex; align-items:center; gap:7px; transition:all .2s; }
.btn-filter:hover { background:var(--pri-d); transform:translateY(-1px); box-shadow:0 4px 14px rgba(29,78,216,.22); }
.btn-clear { background:transparent; color:var(--muted); border:1.5px solid var(--bdr); padding:8px 14px; border-radius:8px; font-size:12px; cursor:pointer; font-family:'Sora',sans-serif; text-decoration:none; display:flex; align-items:center; gap:5px; transition:all .2s; }
.btn-clear:hover { color:var(--pri); border-color:var(--pri); }
.btn-export { background:rgba(109,40,217,.07); color:var(--purple); border:1.5px solid rgba(109,40,217,.2); padding:8px 16px; border-radius:8px; font-weight:600; font-size:12.5px; cursor:pointer; font-family:'Sora',sans-serif; text-decoration:none; display:flex; align-items:center; gap:6px; transition:all .2s; margin-left:auto; }
.btn-export:hover { background:rgba(109,40,217,.14); }

/* ── TABLE ── */
.tbl-wrap { background:var(--card); border:1px solid var(--bdr); border-radius:var(--r); overflow:hidden; box-shadow:var(--sh); }
.tbl-top { padding:16px 20px; border-bottom:1.5px solid var(--bdr); display:flex; justify-content:space-between; align-items:center; background:#eff6ff; }
.tbl-ttl { font-weight:700; font-size:14px; color:#111827; display:flex; align-items:center; gap:8px; }
.cnt-badge { background:rgba(29,78,216,.12); color:var(--pri); font-size:11px; padding:2px 9px; border-radius:99px; font-family:'DM Mono',monospace; border:1px solid var(--pri-bdr); }
.btn-add { background:var(--pri); color:#fff; border:none; padding:9px 20px; border-radius:9px; font-weight:700; font-size:13px; cursor:pointer; font-family:'Sora',sans-serif; display:flex; align-items:center; gap:7px; transition:all .2s; }
.btn-add:hover { background:var(--pri-d); transform:translateY(-2px); box-shadow:0 4px 16px rgba(29,78,216,.25); }

table { width:100%; border-collapse:collapse; }
thead th { background:#eff6ff; padding:12px 16px; text-align:left; font-size:11px; letter-spacing:1.2px; text-transform:uppercase; color:var(--muted); font-weight:700; border-bottom:1.5px solid var(--bdr); white-space:nowrap; cursor:pointer; user-select:none; }
thead th:hover { color:var(--pri); }
tbody tr { border-bottom:1px solid rgba(29,78,216,.06); transition:background .15s; }
tbody tr:last-child { border-bottom:none; }
tbody tr:hover { background:#eff6ff; }
tbody td { padding:14px 16px; vertical-align:middle; color:#111827; }

/* ── AVATAR ── */
.av-wrap { display:flex; align-items:center; gap:11px; }
.avatar { width:40px; height:40px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:13px; letter-spacing:.5px; flex-shrink:0; border:2px solid rgba(29,78,216,.12); }
.staff-name { font-weight:700; color:#111827; font-size:13.5px; white-space:nowrap; }
.staff-meta { display:flex; flex-direction:column; gap:2px; margin-top:3px; }
.staff-meta span { font-size:11.5px; color:var(--muted); display:flex; align-items:center; gap:5px; }
.staff-meta i { width:11px; color:var(--pri-l); font-size:10px; }
.staff-id { font-family:'DM Mono',monospace; font-size:12px; color:var(--muted); background:#eff6ff; padding:2px 8px; border-radius:5px; display:inline-block; border:1px solid var(--bdr); font-weight:600; }

/* ── BADGES ── */
.badge { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:99px; font-size:11px; font-weight:700; white-space:nowrap; }
.b-nv      { background:#dbeafe; color:#1e40af; border:1px solid rgba(29,78,216,.2); }
.b-kt      { background:#fef9c3; color:#78350f; border:1px solid rgba(217,119,6,.25); }
.b-tp      { background:#ede9fe; color:#4c1d95; border:1px solid rgba(109,40,217,.2); }
.b-ql      { background:#fee2e2; color:#7f1d1d; border:1px solid rgba(220,38,38,.2); }
.b-gd      { background:rgba(234,88,12,.10); color:#9a3412; border:1px solid rgba(234,88,12,.22); }
.status-row { display:flex; align-items:center; gap:5px; margin-top:6px; font-size:11px; color:var(--muted); }
.dot { width:6px; height:6px; border-radius:50%; flex-shrink:0; }
.dot.on  { background:#059669; box-shadow:0 0 5px rgba(5,150,105,.4); }
.dot.off { background:var(--red); }

/* ── DATE CELL ── */
.date-cell { font-size:12.5px; font-family:'DM Mono',monospace; color:#111827; }
.date-sub  { font-size:11px; color:var(--muted); margin-top:3px; }

/* ── ACTION BUTTONS ── */
.act-btns { display:flex; align-items:center; gap:5px; }
.btn-ico { width:32px; height:32px; border-radius:7px; border:1.5px solid var(--bdr); background:#f8faff; color:#4b7a63; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:12px; transition:all .18s; text-decoration:none; }
.btn-ico:hover { transform:translateY(-1px); }
.btn-ico.e:hover { background:rgba(217,119,6,.10); color:var(--amber); border-color:rgba(217,119,6,.25); }
.btn-ico.d:hover { background:rgba(220,38,38,.08); color:var(--red); border-color:rgba(220,38,38,.25); }

/* ── ALERT ── */
.alert { display:flex; align-items:flex-start; gap:12px; padding:12px 16px; border-radius:11px; margin-bottom:18px; animation:slideDown .3s ease; }
.alert-ok  { background:rgba(29,78,216,.07); border:1px solid rgba(29,78,216,.2); color:#1e3a8a; }
.alert-err { background:rgba(220,38,38,.06); border:1px solid rgba(220,38,38,.2); color:#991b1b; }
@keyframes slideDown { from{opacity:0;transform:translateY(-8px)} to{opacity:1;transform:none} }

/* ── EMPTY ── */
.empty-cell { text-align:center; padding:60px !important; }
.empty-ico { font-size:38px; opacity:.25; margin-bottom:12px; }
.empty-t { font-weight:700; color:#111827; margin-bottom:6px; }
.empty-s { font-size:13px; color:var(--muted); }

/* ── MODAL ── */
.modal-ov { display:none; position:fixed; inset:0; background:rgba(0,0,0,.35); backdrop-filter:blur(6px); z-index:1000; align-items:center; justify-content:center; padding:20px; }
.modal-ov.open { display:flex; }
.modal-box { background:#fff; border:1.5px solid var(--bdr); border-radius:20px; width:100%; max-width:760px; max-height:92vh; overflow:hidden; display:flex; flex-direction:column; box-shadow:0 24px 60px rgba(29,78,216,.18); animation:mUp .3s cubic-bezier(.34,1.3,.64,1); }
@keyframes mUp { from{opacity:0;transform:translateY(28px) scale(.97)} to{opacity:1;transform:none} }
.m-head { padding:20px 26px; background:linear-gradient(135deg,var(--pri) 0%,var(--pri-d) 100%); display:flex; align-items:center; gap:13px; flex-shrink:0; }
.m-icon { width:44px; height:44px; background:rgba(255,255,255,.2); border:1px solid rgba(255,255,255,.3); border-radius:11px; display:flex; align-items:center; justify-content:center; font-size:19px; color:#fff; flex-shrink:0; }
.m-title h2 { font-size:17px; font-weight:800; color:#fff; }
.m-title p  { font-size:12px; color:rgba(255,255,255,.75); margin-top:2px; }
.m-close { margin-left:auto; width:32px; height:32px; background:rgba(255,255,255,.15); border:none; border-radius:7px; color:#fff; cursor:pointer; font-size:13px; transition:all .2s; display:flex; align-items:center; justify-content:center; }
.m-close:hover { background:rgba(220,38,38,.7); transform:rotate(90deg); }
.m-body { padding:22px 26px; overflow-y:auto; flex:1; background:#fff; }
.m-foot { padding:16px 26px; border-top:1.5px solid var(--bdr); display:flex; justify-content:flex-end; gap:10px; flex-shrink:0; background:#f8faff; }

/* ── FORM ── */
.f-section { margin-bottom:22px; }
.f-sec-title { font-size:10.5px; letter-spacing:2px; text-transform:uppercase; color:var(--pri); font-weight:700; padding-bottom:9px; border-bottom:2px solid rgba(29,78,216,.12); margin-bottom:14px; display:flex; align-items:center; gap:7px; }
.f-grid { display:grid; grid-template-columns:1fr 1fr; gap:13px; }
.f-full { grid-column:1/-1; }
.field { display:flex; flex-direction:column; gap:5px; }
.field label { font-size:12px; font-weight:600; color:#374151; display:flex; align-items:center; gap:5px; }
.field .req { color:var(--red); }
.field input,.field select { background:#f8faff; border:1.5px solid #dbeafe; color:#111827; padding:9px 12px; border-radius:9px; font-family:'Sora',sans-serif; font-size:13px; outline:none; width:100%; transition:all .2s; }
.field input:focus,.field select:focus { border-color:var(--pri); box-shadow:0 0 0 3px rgba(29,78,216,.09); }
.field input.err { border-color:var(--red)!important; }
.f-hint { font-size:11px; color:var(--muted); display:flex; align-items:center; gap:4px; }
.f-err  { font-size:11.5px; color:#b91c1c; display:none; align-items:center; gap:4px; }
.f-err.show { display:flex; }
.btn-cancel { background:transparent; border:1.5px solid var(--bdr); color:var(--muted); padding:9px 20px; border-radius:9px; font-weight:600; font-size:13px; cursor:pointer; font-family:'Sora',sans-serif; transition:all .2s; }
.btn-cancel:hover { border-color:var(--pri); color:var(--pri); }
.btn-save { background:var(--pri); border:none; color:#fff; padding:9px 26px; border-radius:9px; font-weight:700; font-size:13px; cursor:pointer; font-family:'Sora',sans-serif; display:flex; align-items:center; gap:7px; transition:all .2s; }
.btn-save:hover { background:var(--pri-d); box-shadow:0 5px 18px rgba(29,78,216,.25); transform:translateY(-1px); }
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
        <a href="index.php" class="nav-a"><i class="fas fa-th-large"></i> Dashboard</a>
        <div class="nav-sec">Quản Lý</div>
        <a href="quan_ly_nhan_vien.php" class="nav-a active"><i class="fas fa-id-badge"></i> Nhân Viên</a>
        <a href="quan_ly_khach_hang.php" class="nav-a"><i class="fas fa-users"></i> Khách Hàng</a>
        <a href="don_hang.php" class="nav-a"><i class="fas fa-box-open"></i> Đơn Hàng</a>
        <a href="sua_chua.php" class="nav-a"><i class="fas fa-tools"></i> Sửa Chữa</a>
        <a href="san_pham.php" class="nav-a"><i class="fas fa-laptop"></i> Sản Phẩm</a>
        <div class="nav-sec">Hệ Thống</div>
        <a href="users.php" class="nav-a"><i class="fas fa-user-shield"></i> Phân Quyền</a>
        <a href="bao_cao.php" class="nav-a"><i class="fas fa-chart-pie"></i> Báo Cáo</a>
        <a href="cai_dat.php" class="nav-a"><i class="fas fa-cog"></i> Cài Đặt</a>
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

<!-- ── BANNER: Nhân viên chưa cập nhật thông tin ── -->
<?php if (!empty($incompleteList)): ?>
<div class="incomplete-banner" id="incompleteBanner">
    <div class="ib-head">
        <div style="display:flex;align-items:center;gap:12px;">
            <span style="font-size:24px;">⚠️</span>
            <div>
                <div class="ib-title"><?= count($incompleteList) ?> nhân viên chưa được cập nhật thông tin đầy đủ</div>
                <div class="ib-sub">Thông tin thiếu: số điện thoại, ngày sinh, địa chỉ hoặc email. Hãy cập nhật để quản lý nhân viên tốt hơn!</div>
            </div>
        </div>
        <button class="ib-close" onclick="document.getElementById('incompleteBanner').style.display='none'">✕</button>
    </div>
    <div class="ib-list">
        <?php foreach($incompleteList as $ic):
            $missingFields = [];
            if (empty($ic['soDienThoai'])) $missingFields[] = 'SĐT';
            if (empty($ic['ngaySinh']))    $missingFields[] = 'Ngày sinh';
            if (empty($ic['diaChi']))      $missingFields[] = 'Địa chỉ';
            if (empty($ic['email']))       $missingFields[] = 'Email';
            $avc2 = nvAvatarColor($ic['tenNV']);
            $avi2 = nvAvatarInitials($ic['tenNV']);
        ?>
        <div class="ib-item">
            <div style="display:flex;align-items:center;gap:12px;flex:1;min-width:0;">
                <div class="ib-av" style="background:<?= $avc2['bg'] ?>;color:<?= $avc2['fg'] ?>;"><?= $avi2 ?></div>
                <div style="min-width:0;">
                    <div class="ib-name">
                        <?= htmlspecialchars($ic['tenNV']) ?>
                        <span class="ib-id">#<?= $ic['maNV'] ?></span>
                    </div>
                    <div style="font-size:12px;color:#78350f;margin-top:3px;">
                        Vào làm ngày <strong><?= date('d/m/Y', strtotime($ic['ngayVaoLam'])) ?></strong>
                        — <?= htmlspecialchars($ic['chucVu']) ?>
                        — Chưa cập nhật:
                        <?php foreach($missingFields as $mf): ?>
                        <span class="ib-missing-chip"><?= $mf ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <button type="button" class="btn-update-now"
                onclick="openEditModal(
                    <?= $ic['maNV'] ?>,
                    '<?= addslashes(htmlspecialchars($ic['tenNV'])) ?>',
                    '<?= $ic['soDienThoai'] ?? '' ?>',
                    '<?= addslashes($ic['email'] ?? '') ?>',
                    '<?= addslashes($ic['diaChi'] ?? '') ?>',
                    '<?= $ic['ngaySinh'] ?? '' ?>',
                    '<?= addslashes($ic['chucVu']) ?>',
                    '<?= addslashes($ic['phongBan'] ?? '') ?>',
                    <?= $ic['trangThai'] ?>,
                    '<?= $ic['ngayVaoLam'] ?>'
                )">
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
        <strong>Sinh nhật nhân viên hôm nay — <?= date('d/m/Y') ?></strong>
        <p>Chúc mừng sinh nhật các nhân viên đặc biệt!</p>
        <div class="bday-names">
            <?php foreach($birthdays as $b):
                $age = (new DateTime($b['ngaySinh']))->diff(new DateTime())->y;
            ?>
            <span class="bday-chip">🎉 <?= htmlspecialchars($b['tenNV']) ?> — <?= $age ?> tuổi</span>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── TOP ROW ── -->
<div class="top-row">
    <div class="page-title">
        <h1>Quản Lý Nhân Viên</h1>
        <p>Quản lý thông tin và theo dõi đội ngũ nhân sự — QA Tech</p>
    </div>
</div>

<!-- ── STATS ── -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="s-icon si-blue"><i class="fas fa-id-badge"></i></div>
        <div><div class="s-num"><?= number_format($stats['tong'] ?? 0) ?></div><div class="s-lbl">Tổng nhân viên</div></div>
    </div>
    <div class="stat-card">
        <div class="s-icon si-green"><i class="fas fa-user-check"></i></div>
        <div><div class="s-num"><?= number_format($stats['hoat_dong'] ?? 0) ?></div><div class="s-lbl">Đang làm việc</div></div>
    </div>
    <div class="stat-card">
        <div class="s-icon si-red"><i class="fas fa-user-slash"></i></div>
        <div><div class="s-num"><?= number_format($stats['da_nghi'] ?? 0) ?></div><div class="s-lbl">Đã nghỉ việc</div></div>
    </div>
    <div class="stat-card">
        <div class="s-icon si-amber"><i class="fas fa-tools"></i></div>
        <div><div class="s-num"><?= number_format($stats['ky_thuat'] ?? 0) ?></div><div class="s-lbl">Kỹ thuật viên</div></div>
    </div>
    <div class="stat-card">
        <div class="s-icon si-purple"><i class="fas fa-triangle-exclamation"></i></div>
        <div><div class="s-num"><?= count($incompleteList) ?></div><div class="s-lbl">Chưa cập nhật</div></div>
    </div>
</div>

<!-- ── FILTER BAR ── -->
<form method="GET" class="filter-bar">
    <div class="fg" style="flex:2;min-width:190px;">
        <label>Tìm kiếm</label>
        <input type="text" name="search" placeholder="Tên, SĐT, email, phòng ban..." value="<?= htmlspecialchars($search) ?>">
    </div>
    <div class="fg">
        <label>Chức vụ</label>
        <select name="filter_cv">
            <option value="">Tất cả</option>
            <?php foreach($chucVuList as $cv): ?>
            <option value="<?= $cv ?>" <?= ($filter_cv==$cv)?'selected':'' ?>><?= $cv ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="fg">
        <label>Trạng thái</label>
        <select name="filter_tt">
            <option value="">Tất cả</option>
            <option value="1" <?= ($filter_tt==='1')?'selected':'' ?>>Đang làm</option>
            <option value="0" <?= ($filter_tt==='0')?'selected':'' ?>>Đã nghỉ</option>
        </select>
    </div>
    <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Lọc</button>
    <a href="quan_ly_nhan_vien.php" class="btn-clear"><i class="fas fa-times"></i> Xóa lọc</a>
    <a href="quan_ly_nhan_vien.php?export_csv=1" class="btn-export"><i class="fas fa-file-csv"></i> Xuất CSV</a>
</form>

<!-- ── TABLE ── -->
<div class="tbl-wrap">
    <div class="tbl-top">
        <div class="tbl-ttl">
            <i class="fas fa-id-badge" style="color:var(--pri);"></i>
            Danh sách nhân viên
            <?php if($result): ?><span class="cnt-badge"><?= $result->num_rows ?> nhân viên</span><?php endif; ?>
        </div>
        <button class="btn-add" onclick="openAddModal()"><i class="fas fa-plus"></i> Thêm Nhân Viên</button>
    </div>

    <table id="mainTable">
        <thead>
            <tr>
                <th onclick="sortTable(0)">Mã NV<span style="margin-left:4px;opacity:.4;font-size:10px;">⇅</span></th>
                <th onclick="sortTable(1)">Nhân Viên<span style="margin-left:4px;opacity:.4;font-size:10px;">⇅</span></th>
                <th>Chức Vụ</th>
                <th>Phòng Ban</th>
                <th onclick="sortTable(4)">Ngày Sinh<span style="margin-left:4px;opacity:.4;font-size:10px;">⇅</span></th>
                <th onclick="sortTable(5)">Ngày Vào Làm<span style="margin-left:4px;opacity:.4;font-size:10px;">⇅</span></th>
                <th>Thao Tác</th>
            </tr>
        </thead>
        <tbody id="tableBody">
        <?php
        $badgeMap = [
            'Nhân viên'      => 'b-nv',
            'Kỹ thuật viên'  => 'b-kt',
            'Trưởng phòng'   => 'b-tp',
            'Quản lý'        => 'b-ql',
            'Giám đốc'       => 'b-gd',
        ];
        if($result && $result->num_rows > 0):
            while($row = $result->fetch_assoc()):
                $bc  = $badgeMap[$row['chucVu']] ?? 'b-nv';
                $av  = nvAvatarInitials($row['tenNV']);
                $avc = nvAvatarColor($row['tenNV']);
                $age = '';
                if(!empty($row['ngaySinh'])) {
                    $dob = new DateTime($row['ngaySinh']);
                    $age = $dob->diff(new DateTime())->y . ' tuổi';
                }
                // Kiểm tra thiếu thông tin
                $isIncomplete = empty($row['soDienThoai']) || empty($row['ngaySinh']) || empty($row['diaChi']) || empty($row['email']);
        ?>
        <tr style="<?= $isIncomplete ? 'background:rgba(251,191,36,.04);' : '' ?>">
            <td>
                <span class="staff-id">#<?= $row['maNV'] ?></span>
                <?php if($isIncomplete): ?>
                <span title="Chưa cập nhật đầy đủ" style="display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:50%;background:#fef3c7;color:#d97706;font-size:9px;margin-left:4px;border:1px solid rgba(217,119,6,.3);">!</span>
                <?php endif; ?>
            </td>
            <td>
                <div class="av-wrap">
                    <div class="avatar" style="background:<?= $avc['bg'] ?>;color:<?= $avc['fg'] ?>;"><?= $av ?></div>
                    <div>
                        <div class="staff-name"><?= htmlspecialchars($row['tenNV']) ?></div>
                        <div class="staff-meta">
                            <?php if($row['soDienThoai']): ?>
                            <span><i class="fas fa-phone-alt"></i><?= htmlspecialchars($row['soDienThoai']) ?></span>
                            <?php endif; ?>
                            <?php if($row['email']): ?>
                            <span><i class="fas fa-envelope"></i><?= htmlspecialchars($row['email']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="status-row">
                            <span class="dot <?= $row['trangThai'] ? 'on' : 'off' ?>"></span>
                            <?= $row['trangThai'] ? 'Đang làm việc' : 'Đã nghỉ' ?>
                        </div>
                    </div>
                </div>
            </td>
            <td><span class="badge <?= $bc ?>"><?= htmlspecialchars($row['chucVu']) ?></span></td>
            <td>
                <?php if($row['phongBan']): ?>
                <span style="font-size:12.5px;color:#374151;"><?= htmlspecialchars($row['phongBan']) ?></span>
                <?php else: ?>
                <span style="color:var(--muted);font-size:12px;">—</span>
                <?php endif; ?>
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
                <div class="date-cell"><?= date('d/m/Y', strtotime($row['ngayVaoLam'])) ?></div>
                <?php
                    $daysDiff = (new DateTime())->diff(new DateTime($row['ngayVaoLam']))->days;
                    $years = floor($daysDiff / 365);
                    $months = floor(($daysDiff % 365) / 30);
                    $tenure = $years > 0 ? "$years năm" : ($months > 0 ? "$months tháng" : "$daysDiff ngày");
                ?>
                <div class="date-sub"><?= $tenure ?> công tác</div>
            </td>
            <td>
                <div class="act-btns">
                    <button type="button" class="btn-ico e" title="Chỉnh sửa"
                        onclick="openEditModal(<?= $row['maNV'] ?>,'<?= addslashes(htmlspecialchars($row['tenNV'])) ?>','<?= $row['soDienThoai'] ?? '' ?>','<?= addslashes($row['email'] ?? '') ?>','<?= addslashes($row['diaChi'] ?? '') ?>','<?= $row['ngaySinh'] ?? '' ?>','<?= addslashes($row['chucVu']) ?>','<?= addslashes($row['phongBan'] ?? '') ?>',<?= $row['trangThai'] ?>,'<?= $row['ngayVaoLam'] ?>')">
                        <i class="fas fa-pen"></i>
                    </button>
                    <button type="button" class="btn-ico d" title="Xóa"
                        onclick="confirmDelete('quan_ly_nhan_vien.php?xoa_id=<?= $row['maNV'] ?>','<?= addslashes(htmlspecialchars($row['tenNV'])) ?>')">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </td>
        </tr>
        <?php endwhile; else: ?>
        <tr><td colspan="7" class="empty-cell">
            <div class="empty-ico"><i class="fas fa-id-badge"></i></div>
            <div class="empty-t">Không tìm thấy nhân viên nào</div>
            <div class="empty-s">Thử thay đổi bộ lọc hoặc thêm nhân viên mới</div>
        </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

</main>

<!-- ── MODAL THÊM / SỬA ── -->
<div class="modal-ov" id="staffModal">
    <div class="modal-box">
        <div class="m-head">
            <div class="m-icon"><i class="fas fa-id-badge"></i></div>
            <div class="m-title">
                <h2 id="modalTitle">Thêm Nhân Viên Mới</h2>
                <p id="modalSub">Điền đầy đủ thông tin để tạo hồ sơ nhân viên</p>
            </div>
            <button class="m-close" onclick="closeModal()"><i class="fas fa-times"></i></button>
        </div>

        <div class="m-body">
            <?php if(!empty($formErrors)): ?>
            <div style="background:rgba(220,38,38,.05);border:1px solid rgba(220,38,38,.18);border-radius:9px;padding:12px 14px;margin-bottom:16px;">
                <div style="font-size:13px;font-weight:700;color:#991b1b;display:flex;align-items:center;gap:7px;">
                    <i class="fas fa-exclamation-triangle"></i> Dữ liệu không hợp lệ
                </div>
                <ul style="margin:7px 0 0 18px;">
                    <?php foreach($formErrors as $e): ?>
                    <li style="font-size:12px;color:#b91c1c;margin-bottom:3px;"><?= htmlspecialchars($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <form method="POST" id="staffForm">
                <input type="hidden" name="maNV" id="fld_id">

                <!-- THÔNG TIN CÁ NHÂN -->
                <div class="f-section">
                    <div class="f-sec-title"><i class="fas fa-id-card"></i> Thông Tin Cá Nhân</div>
                    <div class="f-grid">
                        <div class="field">
                            <label><i class="fas fa-user"></i> Họ và Tên <span class="req">*</span></label>
                            <input type="text" name="tenNV" id="fld_ten" placeholder="Nguyễn Văn A" value="<?= htmlspecialchars($old['tenNV'] ?? '') ?>">
                            <span class="f-hint"><i class="fas fa-info-circle"></i> Tối thiểu 3 ký tự</span>
                            <span class="f-err" id="err_ten"><i class="fas fa-exclamation-circle"></i><span></span></span>
                        </div>
                        <div class="field">
                            <label><i class="fas fa-phone-alt"></i> Số Điện Thoại</label>
                            <input type="tel" name="soDienThoai" id="fld_sdt" placeholder="0912345678" maxlength="10" value="<?= htmlspecialchars($old['soDienThoai'] ?? '') ?>">
                            <span class="f-hint"><i class="fas fa-info-circle"></i> Đúng 10 chữ số</span>
                            <span class="f-err" id="err_sdt"><i class="fas fa-exclamation-circle"></i><span></span></span>
                        </div>
                        <div class="field">
                            <label><i class="fas fa-envelope"></i> Email</label>
                            <input type="email" name="email" id="fld_email" placeholder="example@gmail.com" value="<?= htmlspecialchars($old['email'] ?? '') ?>">
                            <span class="f-err" id="err_email"><i class="fas fa-exclamation-circle"></i><span></span></span>
                        </div>
                        <div class="field">
                            <label><i class="fas fa-birthday-cake"></i> Ngày Sinh</label>
                            <input type="date" name="ngaySinh" id="fld_ns" value="<?= htmlspecialchars($old['ngaySinh'] ?? '') ?>">
                            <span class="f-err" id="err_ns"><i class="fas fa-exclamation-circle"></i><span></span></span>
                        </div>
                        <div class="field f-full">
                            <label><i class="fas fa-map-marker-alt"></i> Địa Chỉ</label>
                            <input type="text" name="diaChi" id="fld_diachi" placeholder="Số nhà, đường, phường, quận, tỉnh/TP" value="<?= htmlspecialchars($old['diaChi'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <!-- THÔNG TIN CÔNG VIỆC -->
                <div class="f-section">
                    <div class="f-sec-title"><i class="fas fa-briefcase"></i> Thông Tin Công Việc</div>
                    <div class="f-grid">
                        <div class="field">
                            <label><i class="fas fa-user-tie"></i> Chức Vụ</label>
                            <select name="chucVu" id="fld_chucvu">
                                <?php foreach($chucVuList as $cv): ?>
                                <option value="<?= $cv ?>" <?= (($old['chucVu'] ?? 'Nhân viên')==$cv)?'selected':'' ?>><?= $cv ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label><i class="fas fa-building"></i> Phòng Ban</label>
                            <select name="phongBan" id="fld_phongban">
                                <option value="">— Chọn phòng ban —</option>
                                <?php foreach($phongBanList as $pb): ?>
                                <option value="<?= $pb ?>" <?= (($old['phongBan'] ?? '')==$pb)?'selected':'' ?>><?= $pb ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label><i class="fas fa-calendar-alt"></i> Ngày Vào Làm</label>
                            <input type="date" name="ngayVaoLam" id="fld_ngayvl" value="<?= htmlspecialchars($old['ngayVaoLam'] ?? date('Y-m-d')) ?>">
                            <span class="f-hint"><i class="fas fa-info-circle"></i> Không lớn hơn hôm nay</span>
                            <span class="f-err" id="err_ngayvl"><i class="fas fa-exclamation-circle"></i><span></span></span>
                        </div>
                        <div class="field">
                            <label><i class="fas fa-toggle-on"></i> Trạng Thái</label>
                            <select name="trangThai" id="fld_tt">
                                <option value="1">🟢 Đang làm việc</option>
                                <option value="0">🔴 Đã nghỉ việc</option>
                            </select>
                        </div>
                    </div>
                </div>

                <button type="submit" name="luu_nhan_vien" id="hiddenSubmit" style="display:none;"></button>
            </form>
        </div>

        <div class="m-foot">
            <button class="btn-cancel" onclick="closeModal()"><i class="fas fa-times"></i> Hủy</button>
            <button class="btn-save" onclick="submitForm()"><i class="fas fa-save"></i> <span id="btnTxt">Lưu Nhân Viên</span></button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<script>
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
        let cmp = isNaN(an)||isNaN(bn) ? at.localeCompare(bt,'vi') : an - bn;
        return sortDir[col] ? cmp : -cmp;
    });
    rows.forEach(r => tb.appendChild(r));
}

// ── MODAL ──
const modal = document.getElementById('staffModal');
const TODAY = new Date().toISOString().split('T')[0];
document.getElementById('fld_ns').max     = TODAY;
document.getElementById('fld_ngayvl').max = TODAY;

function openAddModal() {
    document.getElementById('staffForm').reset();
    document.getElementById('fld_id').value = '';
    document.getElementById('fld_ngayvl').value = TODAY;
    document.getElementById('modalTitle').textContent = 'Thêm Nhân Viên Mới';
    document.getElementById('modalSub').textContent   = 'Điền đầy đủ thông tin để tạo hồ sơ nhân viên';
    document.getElementById('btnTxt').textContent     = 'Lưu Nhân Viên';
    clearAllErrors();
    modal.classList.add('open');
}

function openEditModal(id, ten, sdt, email, diaChi, ns, chucVu, phongBan, tt, ngayVL) {
    document.getElementById('fld_id').value       = id;
    document.getElementById('fld_ten').value      = ten;
    document.getElementById('fld_sdt').value      = sdt || '';
    document.getElementById('fld_email').value    = email || '';
    document.getElementById('fld_diachi').value   = diaChi || '';
    document.getElementById('fld_ns').value       = ns || '';
    document.getElementById('fld_chucvu').value   = chucVu;
    document.getElementById('fld_phongban').value = phongBan || '';
    document.getElementById('fld_tt').value       = String(tt);
    document.getElementById('fld_ngayvl').value   = ngayVL || TODAY;
    document.getElementById('modalTitle').textContent = 'Chỉnh Sửa Thông Tin';
    document.getElementById('modalSub').textContent   = 'Cập nhật hồ sơ nhân viên #' + id;
    document.getElementById('btnTxt').textContent     = 'Cập Nhật';
    clearAllErrors();
    modal.classList.add('open');
}

function closeModal() { modal.classList.remove('open'); }
modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });

// Auto-reopen nếu có lỗi server
<?php if(!empty($formErrors)): ?>
window.addEventListener('DOMContentLoaded', () => {
    <?php if(!empty($old['maNV'])): ?>
    openEditModal('<?= $old['maNV'] ?>','<?= addslashes($old['tenNV'] ?? '') ?>','<?= addslashes($old['soDienThoai'] ?? '') ?>','<?= addslashes($old['email'] ?? '') ?>','<?= addslashes($old['diaChi'] ?? '') ?>','<?= $old['ngaySinh'] ?? '' ?>','<?= addslashes($old['chucVu'] ?? 'Nhân viên') ?>','<?= addslashes($old['phongBan'] ?? '') ?>','<?= $old['trangThai'] ?? '1' ?>','<?= $old['ngayVaoLam'] ?? date('Y-m-d') ?>');
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
     ['fld_ns','err_ns'],['fld_ngayvl','err_ngayvl']].forEach(([f,e]) => clrErr(f,e));
}

function validateForm() {
    let ok = true;
    clearAllErrors();
    const ten = document.getElementById('fld_ten').value.trim();
    if (!ten) { setErr('fld_ten','err_ten','Họ tên không được để trống'); ok=false; }
    else if (ten.length < 3) { setErr('fld_ten','err_ten','Họ tên phải có ít nhất 3 ký tự'); ok=false; }

    const sdt = document.getElementById('fld_sdt').value.trim();
    if (sdt && !/^[0-9]{10}$/.test(sdt)) { setErr('fld_sdt','err_sdt','Số điện thoại phải đúng 10 chữ số'); ok=false; }

    const email = document.getElementById('fld_email').value.trim();
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { setErr('fld_email','err_email','Email không đúng định dạng'); ok=false; }

    const ns = document.getElementById('fld_ns').value;
    if (ns && ns > TODAY) { setErr('fld_ns','err_ns','Ngày sinh không được lớn hơn hôm nay'); ok=false; }

    const nvl = document.getElementById('fld_ngayvl').value;
    if (nvl && nvl > TODAY) { setErr('fld_ngayvl','err_ngayvl','Ngày vào làm không được lớn hơn hôm nay'); ok=false; }

    return ok;
}

function submitForm() {
    if (!validateForm()) {
        document.querySelector('.f-err.show')?.scrollIntoView({ behavior:'smooth', block:'center' });
        return;
    }
    document.getElementById('hiddenSubmit').click();
}

document.getElementById('fld_ten').addEventListener('input',  () => clrErr('fld_ten','err_ten'));
document.getElementById('fld_sdt').addEventListener('input',  function() { this.value=this.value.replace(/\D/g,'').slice(0,10); clrErr('fld_sdt','err_sdt'); });
document.getElementById('fld_email').addEventListener('input', () => clrErr('fld_email','err_email'));
document.getElementById('fld_ns').addEventListener('change',  () => clrErr('fld_ns','err_ns'));
document.getElementById('fld_ngayvl').addEventListener('change',() => clrErr('fld_ngayvl','err_ngayvl'));

// ── XÓA ──
function confirmDelete(url, name) {
    Swal.fire({
        title: 'Xóa nhân viên?',
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
</body>
</html>