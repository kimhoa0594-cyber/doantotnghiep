<?php
// file: kythuatvien/quan_ly_khach_hang.php
session_start();
require_once '../db.php';
date_default_timezone_set('Asia/Ho_Chi_Minh');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'technician') {
    header("Location: ../login.php"); exit();
}

$tenKTV = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Kỹ thuật viên';

// ── ĐẢM BẢO BẢNG TỒN TẠI ──
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
  `maKH` int(11) DEFAULT NULL,
  `ngayNhan` date DEFAULT current_timestamp(),
  `trangThai` varchar(50) DEFAULT 'Tiếp nhận',
  PRIMARY KEY (`maPhieu`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ── LỌC & PHÂN TRANG ──
$search      = trim($_GET['search'] ?? '');
$filter_loai = $_GET['filter_loai'] ?? '';
$filter_tt   = $_GET['filter_tt'] ?? '';
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = 15;
$offset      = ($page - 1) * $perPage;

$esc_search      = $conn->real_escape_string($search);
$esc_filter_loai = $conn->real_escape_string($filter_loai);
$esc_filter_tt   = $conn->real_escape_string($filter_tt);

$where = "WHERE 1=1";
if ($search)      $where .= " AND (kh.tenKH LIKE '%$esc_search%' OR kh.soDienThoai LIKE '%$esc_search%' OR kh.email LIKE '%$esc_search%')";
if ($filter_loai) $where .= " AND kh.loaiKhachHang='$esc_filter_loai'";
if ($filter_tt !== '') $where .= " AND kh.trangThai='$esc_filter_tt'";

$countSql = "SELECT COUNT(DISTINCT kh.maKH) as c FROM khach_hang kh $where";
$totalRow  = (int)($conn->query($countSql)->fetch_assoc()['c'] ?? 0);
$totalPage = max(1, ceil($totalRow / $perPage));

$sql = "SELECT kh.maKH, kh.tenKH, kh.soDienThoai, kh.email, kh.diaChi, kh.ngaySinh,
            kh.loaiKhachHang, kh.ngayDangKy, kh.trangThai,
            COUNT(DISTINCT dh.maDH) as tongLanMua,
            COUNT(DISTINCT psc.maPhieu) as tongLanSua,
            COALESCE(SUM(dh.tongTien),0) as tongChiTieu
        FROM khach_hang kh
        LEFT JOIN don_hang dh ON kh.maKH = dh.maKH
        LEFT JOIN phieu_sua_chua psc ON kh.maKH = psc.maKH
        $where
        GROUP BY kh.maKH ORDER BY kh.maKH DESC
        LIMIT $perPage OFFSET $offset";
$result = $conn->query($sql);

// ── THỐNG KÊ ──
$stats = $conn->query("SELECT COUNT(*) as tong, SUM(trangThai=1) as hoat_dong,
    SUM(trangThai=0) as da_khoa, SUM(loaiKhachHang='Trung thành') as vip
    FROM khach_hang")->fetch_assoc();

// ── PHÂN BỐ GIAI ĐOẠN ──
$distRes = $conn->query("SELECT loaiKhachHang, COUNT(*) as cnt FROM khach_hang GROUP BY loaiKhachHang");
$dist = [];
while ($d = $distRes->fetch_assoc()) $dist[$d['loaiKhachHang']] = (int)$d['cnt'];

// ── SINH NHẬT HÔM NAY ──
$todayMD = date('m-d');
$bdayRes = $conn->query("SELECT maKH,tenKH,ngaySinh FROM khach_hang
    WHERE DATE_FORMAT(ngaySinh,'%m-%d')='$todayMD' AND ngaySinh IS NOT NULL AND trangThai=1");
$birthdays = [];
while ($b = $bdayRes->fetch_assoc()) $birthdays[] = $b;

// ── HELPERS ──
function avatarColor($name) {
    $colors = [
        ['bg'=>'#d1fae5','fg'=>'#065f46'],['bg'=>'#dbeafe','fg'=>'#1e40af'],
        ['bg'=>'#ede9fe','fg'=>'#4c1d95'],['bg'=>'#fef3c7','fg'=>'#78350f'],
        ['bg'=>'#fee2e2','fg'=>'#7f1d1d'],['bg'=>'#e0f2fe','fg'=>'#0c4a6e'],
        ['bg'=>'#fce7f3','fg'=>'#831843'],['bg'=>'#f0fdf4','fg'=>'#14532d'],
    ];
    return $colors[abs(crc32($name)) % count($colors)];
}
function avatarInitials($name) {
    $parts = preg_split('/\s+/', trim($name));
    if (count($parts) >= 2) return mb_strtoupper(mb_substr($parts[0],0,1).mb_substr(end($parts),0,1));
    return mb_strtoupper(mb_substr($name,0,2));
}

$loaiList = [
    'Khách truy cập'=>'#64748b','Quan tâm'=>'#3b82f6','Đã sử dụng'=>'#16c79a',
    'Quay lại'=>'#8b5cf6','Thân thiết'=>'#f59e0b','Trung thành'=>'#f97316','Ngừng sử dụng'=>'#ef4444',
];
$badgeMap = [
    'Khách truy cập'=>['b-visitor','🌐'],'Quan tâm'=>['b-interest','❤️'],
    'Đã sử dụng'=>['b-used','✅'],'Quay lại'=>['b-return','🔄'],
    'Thân thiết'=>['b-loyal','⭐'],'Trung thành'=>['b-vip','💎'],'Ngừng sử dụng'=>['b-inactive','⏸'],
];
$chartLabels = $chartData = $chartColors = [];
$total = array_sum($dist);
foreach($loaiList as $n => $c) {
    if(isset($dist[$n]) && $dist[$n] > 0) { $chartLabels[]=$n; $chartData[]=$dist[$n]; $chartColors[]=$c; }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Khách Hàng — Kỹ Thuật Viên</title>
<link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@300;400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
:root {
    --sw: 260px;
    --pri: #0f766e; --pri-d: #0d6460; --pri-l: #14b8a6; --pri-g: rgba(15,118,110,.10);
    --pri-bdr: rgba(15,118,110,.22);
    --blue: #1d4ed8; --purple: #6d28d9; --red: #dc2626; --amber: #d97706;
    --sidebar-bg: #0f172a;
    --bg: #f8fafc; --bg2: #ffffff; --bg3: #f0fdfa;
    --card: #ffffff; --bdr: rgba(15,118,110,.13);
    --text: #0f172a; --muted: #64748b;
    --r: 14px; --sh: 0 4px 20px rgba(15,118,110,.09);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{font-family:'Be Vietnam Pro',sans-serif;background:var(--bg);color:var(--text);display:flex;min-height:100vh;font-size:14px;line-height:1.6}
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:#f1f5f9}
::-webkit-scrollbar-thumb{background:var(--pri);border-radius:99px}

/* ── SIDEBAR ── */
.sidebar{width:var(--sw);background:var(--sidebar-bg);height:100vh;position:fixed;display:flex;flex-direction:column;z-index:200;box-shadow:4px 0 20px rgba(0,0,0,.18)}
.sb-brand{padding:24px 20px 18px;border-bottom:1px solid rgba(255,255,255,.07)}
.brand-logo{display:flex;align-items:center;gap:12px;text-decoration:none}
.brand-icon{width:40px;height:40px;background:var(--pri);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;color:#fff;flex-shrink:0}
.brand-name{font-size:14px;font-weight:800;color:#fff;line-height:1.2}
.brand-sub{font-size:10px;color:rgba(255,255,255,.4);letter-spacing:1px;text-transform:uppercase}
.sb-user{padding:14px 18px;border-bottom:1px solid rgba(255,255,255,.07);display:flex;align-items:center;gap:10px}
.user-av{width:34px;height:34px;border-radius:50%;background:var(--pri);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:14px;flex-shrink:0}
.user-name{font-size:12.5px;font-weight:700;color:#e2e8f0}
.user-role{font-size:10px;color:#64748b}
.sb-nav{flex:1;padding:14px 10px;overflow-y:auto}
.nav-sec{font-size:9px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:#334155;padding:12px 12px 4px}
.nav-a{display:flex;align-items:center;gap:11px;padding:10px 13px;border-radius:9px;color:rgba(255,255,255,.65);text-decoration:none;font-weight:500;font-size:13px;transition:all .18s;margin-bottom:2px}
.nav-a i{width:16px;font-size:13px;text-align:center}
.nav-a:hover{background:rgba(255,255,255,.07);color:#fff}
.nav-a.active{background:var(--pri);color:#fff;font-weight:700}
.sb-foot{padding:14px 10px;border-top:1px solid rgba(255,255,255,.07)}
.logout-a{display:flex;align-items:center;gap:10px;padding:10px 13px;border-radius:9px;color:#f87171;text-decoration:none;font-size:13px;font-weight:600;transition:all .2s}
.logout-a:hover{background:rgba(239,68,68,.1);color:#fca5a5}

/* ── MAIN ── */
.main{margin-left:var(--sw);width:calc(100% - var(--sw));padding:28px 32px;min-height:100vh;background:var(--bg)}

/* ── TOPBAR ── */
.topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px}
.page-title h1{font-size:22px;font-weight:800;color:var(--pri);letter-spacing:-.4px}
.page-title p{font-size:12.5px;color:var(--muted);margin-top:3px}
.live-clock{font-size:12px;color:var(--muted);font-weight:600;background:#fff;padding:7px 14px;border-radius:9px;border:1px solid var(--bdr)}

/* ── BIRTHDAY BANNER ── */
.bday-banner{background:linear-gradient(135deg,#ecfdf5,#d1fae5);border:1px solid rgba(245,158,11,.35);border-radius:var(--r);padding:14px 20px;margin-bottom:20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;animation:slideDown .4s ease;box-shadow:var(--sh)}
.bday-icon{font-size:28px;flex-shrink:0}
.bday-text strong{color:#92400e;font-weight:700;font-size:14px}
.bday-text p{font-size:12.5px;color:#1f2937;margin-top:3px}
.bday-names{display:flex;flex-wrap:wrap;gap:8px;margin-top:6px}
.bday-chip{background:rgba(245,158,11,.14);border:1px solid rgba(245,158,11,.3);color:#78350f;font-size:12px;padding:3px 12px;border-radius:99px;font-weight:600}

/* ── DASHBOARD ROW ── */
.dashboard-row{display:grid;grid-template-columns:1fr 300px;gap:16px;margin-bottom:20px}
.stats-col{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.stat-card{background:var(--card);border:1px solid var(--bdr);border-radius:var(--r);padding:18px;display:flex;align-items:center;gap:14px;box-shadow:var(--sh);transition:all .2s}
.stat-card:hover{transform:translateY(-2px);box-shadow:0 8px 28px rgba(15,118,110,.14)}
.s-icon{width:44px;height:44px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:19px;flex-shrink:0}
.si-green{background:rgba(15,118,110,.10);color:var(--pri)}
.si-blue{background:rgba(29,78,216,.10);color:var(--blue)}
.si-red{background:rgba(220,38,38,.10);color:var(--red)}
.si-amber{background:rgba(217,119,6,.10);color:var(--amber)}
.s-num{font-size:26px;font-weight:800;color:#111827;line-height:1;font-family:'DM Mono',monospace}
.s-lbl{font-size:11.5px;color:var(--muted);margin-top:3px}

/* ── CHART CARD ── */
.chart-card{background:var(--card);border:1px solid var(--bdr);border-radius:var(--r);padding:18px;display:flex;flex-direction:column;box-shadow:var(--sh)}
.chart-card-title{font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1.2px;margin-bottom:14px}
.chart-inner{display:flex;align-items:center;gap:16px;flex:1}
.chart-canvas-wrap{width:110px;height:110px;flex-shrink:0}
.chart-legend{flex:1;display:flex;flex-direction:column;gap:6px}
.legend-item{display:flex;align-items:center;gap:8px;font-size:11.5px}
.legend-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.legend-name{color:#1f2937;flex:1}
.legend-pct{color:var(--muted);font-family:'DM Mono',monospace;font-size:11px}

/* ── FILTER BAR ── */
.filter-bar{background:var(--card);border:1px solid var(--bdr);border-radius:var(--r);padding:15px 18px;margin-bottom:18px;display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;box-shadow:var(--sh)}
.fg{display:flex;flex-direction:column;gap:4px}
.fg label{font-size:10px;letter-spacing:1.5px;text-transform:uppercase;color:var(--muted);font-weight:700}
.fg input,.fg select{background:#f8fffe;border:1.5px solid var(--bdr);color:#111827;padding:8px 12px;border-radius:8px;font-family:'Be Vietnam Pro',sans-serif;font-size:13px;outline:none;min-width:150px;transition:border-color .2s}
.fg input:focus,.fg select:focus{border-color:var(--pri);box-shadow:0 0 0 3px rgba(15,118,110,.08)}
.fg input::placeholder{color:#9ca3af}
.btn-filter{background:var(--pri);color:#fff;border:none;padding:8px 18px;border-radius:8px;font-weight:700;font-size:13px;cursor:pointer;font-family:'Be Vietnam Pro',sans-serif;display:flex;align-items:center;gap:7px;transition:all .2s}
.btn-filter:hover{background:var(--pri-d)}
.btn-clear{background:transparent;color:var(--muted);border:1.5px solid var(--bdr);padding:8px 14px;border-radius:8px;font-size:12px;cursor:pointer;font-family:'Be Vietnam Pro',sans-serif;text-decoration:none;display:flex;align-items:center;gap:5px;transition:all .2s}
.btn-clear:hover{color:var(--pri);border-color:var(--pri)}

/* ── TABLE ── */
.tbl-wrap{background:var(--card);border:1px solid var(--bdr);border-radius:var(--r);overflow:hidden;box-shadow:var(--sh)}
.tbl-top{padding:14px 20px;border-bottom:1.5px solid var(--bdr);display:flex;justify-content:space-between;align-items:center;background:var(--bg3)}
.tbl-ttl{font-weight:700;font-size:14px;color:#111827;display:flex;align-items:center;gap:8px}
.cnt-badge{background:rgba(15,118,110,.12);color:var(--pri);font-size:11px;padding:2px 9px;border-radius:99px;font-family:'DM Mono',monospace;border:1px solid var(--pri-bdr)}
.read-only-tag{display:flex;align-items:center;gap:6px;font-size:11.5px;color:var(--muted);background:#f1f5f9;padding:5px 12px;border-radius:8px;border:1px solid var(--bdr)}

table{width:100%;border-collapse:collapse}
thead th{background:var(--bg3);padding:11px 16px;text-align:left;font-size:11px;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted);font-weight:700;border-bottom:1.5px solid var(--bdr);white-space:nowrap;cursor:pointer;user-select:none;transition:color .2s}
thead th:hover{color:var(--pri)}
thead th .sort-icon{margin-left:5px;opacity:.4;font-size:10px}
thead th.sorted .sort-icon{opacity:1;color:var(--pri)}
tbody tr{border-bottom:1px solid rgba(15,118,110,.06);transition:background .15s}
tbody tr:last-child{border-bottom:none}
tbody tr:hover{background:#f0fdfa}
tbody td{padding:13px 16px;vertical-align:middle;color:#111827}

/* ── AVATAR ── */
.av-wrap{display:flex;align-items:center;gap:11px}
.avatar{width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0;border:2px solid rgba(15,118,110,.12)}
.cust-name{font-weight:700;color:#111827;font-size:13.5px;white-space:nowrap}
.cust-meta{display:flex;flex-direction:column;gap:2px;margin-top:3px}
.cust-meta span{font-size:11.5px;color:var(--muted);display:flex;align-items:center;gap:5px}
.cust-meta i{width:11px;color:var(--pri-l);font-size:10px}
.cust-id{font-family:'DM Mono',monospace;font-size:12px;color:var(--muted);background:var(--bg3);padding:2px 8px;border-radius:5px;display:inline-block;border:1px solid var(--bdr);font-weight:600}

/* ── TOOLTIP ── */
.tooltip-wrap{position:relative;cursor:default}
.tooltip-box{position:absolute;left:0;top:calc(100% + 8px);z-index:999;background:#fff;border:1.5px solid var(--bdr);border-radius:12px;padding:14px 16px;width:240px;box-shadow:0 12px 40px rgba(15,118,110,.16);opacity:0;pointer-events:none;transform:translateY(-6px);transition:opacity .2s,transform .2s}
.tooltip-wrap:hover .tooltip-box{opacity:1;pointer-events:auto;transform:none}
.tt-row{display:flex;align-items:flex-start;gap:8px;font-size:12px;color:#374151;margin-bottom:8px}
.tt-row:last-child{margin-bottom:0}
.tt-row i{color:var(--pri-l);width:13px;margin-top:2px;font-size:11px}
.tt-divider{border:none;border-top:1px solid #d1fae5;margin:10px 0}
.tt-stat{display:flex;justify-content:space-between;font-size:11.5px}
.tt-stat .lbl{color:var(--muted)}
.tt-stat .val{color:#111827;font-weight:700;font-family:'DM Mono',monospace}

/* ── BADGES ── */
.badge{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:99px;font-size:11px;font-weight:700;white-space:nowrap}
.b-visitor{background:#f1f5f9;color:#475569;border:1px solid #cbd5e1}
.b-interest{background:rgba(29,78,216,.08);color:#1d4ed8;border:1px solid rgba(29,78,216,.2)}
.b-used{background:rgba(15,118,110,.09);color:#065f46;border:1px solid rgba(15,118,110,.2)}
.b-return{background:rgba(109,40,217,.08);color:#6d28d9;border:1px solid rgba(109,40,217,.2)}
.b-loyal{background:rgba(217,119,6,.10);color:#92400e;border:1px solid rgba(217,119,6,.22)}
.b-vip{background:rgba(234,88,12,.10);color:#9a3412;border:1px solid rgba(234,88,12,.22)}
.b-inactive{background:rgba(220,38,38,.08);color:#991b1b;border:1px solid rgba(220,38,38,.2)}
.status-row{display:flex;align-items:center;gap:5px;margin-top:5px;font-size:11px;color:var(--muted)}
.dot{width:6px;height:6px;border-radius:50%;flex-shrink:0}
.dot.on{background:#059669;box-shadow:0 0 5px rgba(5,150,105,.4)}
.dot.off{background:var(--red)}

/* ── GIAO DỊCH ── */
.tx-row{display:flex;align-items:center;gap:6px;font-size:12px;margin-bottom:4px}
.tx-ico{width:20px;height:20px;border-radius:5px;display:flex;align-items:center;justify-content:center;font-size:10px}
.tx-ico.g{background:rgba(15,118,110,.10);color:var(--pri)}
.tx-ico.r{background:rgba(220,38,38,.10);color:var(--red)}
.tx-num{font-weight:700;color:#111827;font-family:'DM Mono',monospace}
.date-cell{font-size:12.5px;font-family:'DM Mono',monospace;color:#111827}
.date-sub{font-size:11px;color:var(--muted);margin-top:3px}

/* ── DETAIL BUTTON ── */
.btn-detail{display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:8px;background:var(--pri);color:#fff;text-decoration:none;font-size:12px;font-weight:700;transition:all .2s;border:none;cursor:pointer}
.btn-detail:hover{background:var(--pri-d);color:#fff;transform:translateY(-1px);box-shadow:0 4px 12px rgba(15,118,110,.25)}
.btn-detail i{font-size:11px}

/* ── EMPTY ── */
.empty-cell{text-align:center;padding:60px !important}
.empty-ico{font-size:38px;opacity:.25;margin-bottom:12px}
.empty-t{font-weight:700;color:#111827;margin-bottom:6px}
.empty-s{font-size:13px;color:var(--muted)}

/* ── PAGINATION ── */
.pagination-wrap{display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-top:1px solid var(--bdr);flex-wrap:wrap;gap:10px}
.page-info{font-size:12px;color:var(--muted)}
.page-links{display:flex;gap:4px}
.page-btn{width:34px;height:34px;display:flex;align-items:center;justify-content:center;border-radius:8px;border:1px solid var(--bdr);text-decoration:none;font-size:13px;font-weight:600;color:var(--text);background:#fff;transition:all .2s}
.page-btn:hover{background:var(--bg3);border-color:var(--pri);color:var(--pri)}
.page-btn.active{background:var(--pri);color:#fff;border-color:var(--pri)}
.page-btn.disabled{opacity:.4;pointer-events:none}

/* ── ALERT ── */
.alert{display:flex;align-items:center;gap:12px;padding:12px 16px;border-radius:11px;margin-bottom:18px;animation:slideDown .3s ease}
.alert-ok{background:rgba(15,118,110,.07);border:1px solid rgba(15,118,110,.2);color:#064e3b}
@keyframes slideDown{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:none}}
</style>
</head>
<body>

<!-- ── SIDEBAR ── -->
<aside class="sidebar">
    <div class="sb-brand">
        <a href="trang_chu.php" class="brand-logo">
            <div class="brand-icon"><i class="fas fa-tools"></i></div>
            <div>
                <div class="brand-name">Quang Anh Tech</div>
                <div class="brand-sub">Kỹ thuật viên</div>
            </div>
        </a>
    </div>
    <div class="sb-user">
        <div class="user-av"><?= strtoupper(mb_substr($tenKTV, 0, 1)) ?></div>
        <div>
            <div class="user-name"><?= htmlspecialchars($tenKTV) ?></div>
            <div class="user-role">Kỹ thuật viên</div>
        </div>
    </div>
    <nav class="sb-nav">
        <div class="nav-sec">Tổng Quan</div>
        <a href="index.php" class="nav-a"><i class="fas fa-home"></i> Trang Chủ</a>
        <div class="nav-sec">Quản Lý</div>
        <a href="quan_ly_khach_hang.php" class="nav-a active"><i class="fas fa-users"></i> Khách Hàng</a>
        <a href="phieu_sua_chua.php" class="nav-a"><i class="fas fa-screwdriver-wrench"></i> Tất cả phiếu</a>
        
        <a href="bao_hanh.php" class="nav-a"><i class="fas fa-shield-alt"></i> Bảo hành</a>
        <div class="nav-sec">Tài Khoản</div>
        <a href="doi_mat_khau.php" class="nav-a"><i class="fas fa-key"></i> Đổi Mật Khẩu</a>
    </nav>
    <div class="sb-foot">
        <a href="../logout.php" class="logout-a"><i class="fas fa-sign-out-alt"></i> Đăng Xuất</a>
    </div>
</aside>

<!-- ── MAIN ── -->
<main class="main">

<!-- TOPBAR -->
<div class="topbar">
    <div class="page-title">
        <h1><i class="fas fa-users" style="font-size:20px;margin-right:8px;"></i>Quản Lý Khách Hàng</h1>
        <p>Danh sách khách hàng — chỉ xem</p>
    </div>
    <div class="live-clock" id="live-clock"></div>
</div>

<!-- BIRTHDAY BANNER -->
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

<!-- STATS + CHART -->
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
    <div class="chart-card">
        <div class="chart-card-title"><i class="fas fa-chart-pie" style="color:var(--pri);margin-right:5px;"></i> Phân Bố Giai Đoạn</div>
        <?php if($total > 0): ?>
        <div class="chart-inner">
            <div class="chart-canvas-wrap">
                <canvas id="donutChart" width="110" height="110"></canvas>
            </div>
            <div class="chart-legend">
                <?php foreach($chartLabels as $i => $lbl):
                    $pct = $total > 0 ? round($chartData[$i]/$total*100) : 0; ?>
                <div class="legend-item">
                    <div class="legend-dot" style="background:<?= $chartColors[$i] ?>"></div>
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

<!-- FILTER BAR -->
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
            <option value="<?= $l ?>" <?= $filter_loai===$l?'selected':'' ?>><?= $l ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="fg">
        <label>Trạng thái</label>
        <select name="filter_tt">
            <option value="">Tất cả</option>
            <option value="1" <?= $filter_tt==='1'?'selected':'' ?>>Hoạt động</option>
            <option value="0" <?= $filter_tt==='0'?'selected':'' ?>>Đã khóa</option>
        </select>
    </div>
    <button type="submit" class="btn-filter"><i class="fas fa-filter"></i> Lọc</button>
    <a href="quan_ly_khach_hang.php" class="btn-clear"><i class="fas fa-times"></i> Xóa lọc</a>
</form>

<!-- TABLE -->
<div class="tbl-wrap">
    <div class="tbl-top">
        <div class="tbl-ttl">
            <i class="fas fa-users" style="color:var(--pri);"></i>
            Danh sách
            <span class="cnt-badge"><?= number_format($totalRow) ?> khách hàng</span>
        </div>
        <div class="read-only-tag"><i class="fas fa-eye"></i> Chỉ xem</div>
    </div>

    <table id="mainTable">
        <thead>
            <tr>
                <th onclick="sortTable(0)">Mã KH <span class="sort-icon">⇅</span></th>
                <th onclick="sortTable(1)">Khách Hàng <span class="sort-icon">⇅</span></th>
                <th>Giai Đoạn</th>
                <th>Giao Dịch</th>
                <th onclick="sortTable(4)">Ngày ĐK <span class="sort-icon">⇅</span></th>
                <th>Trạng Thái</th>
                <th style="text-align:center;">Chi Tiết</th>
            </tr>
        </thead>
        <tbody id="tableBody">
        <?php
        if ($result && $result->num_rows > 0):
            while ($row = $result->fetch_assoc()):
                [$bc, $bi] = $badgeMap[$row['loaiKhachHang']] ?? ['b-visitor','🌐'];
                $av  = avatarInitials($row['tenKH']);
                $avc = avatarColor($row['tenKH']);
                $age = '';
                if (!empty($row['ngaySinh'])) {
                    $age = (new DateTime($row['ngaySinh']))->diff(new DateTime())->y . ' tuổi';
                }
                $chiTieu = number_format($row['tongChiTieu'] ?? 0, 0, ',', '.');
        ?>
        <tr>
            <td><span class="cust-id">#<?= $row['maKH'] ?></span></td>
            <td>
                <div class="tooltip-wrap">
                    <div class="av-wrap">
                        <div class="avatar" style="background:<?= $avc['bg'] ?>;color:<?= $avc['fg'] ?>;"><?= $av ?></div>
                        <div>
                            <div class="cust-name"><?= htmlspecialchars($row['tenKH']) ?></div>
                            <div class="cust-meta">
                                <span><i class="fas fa-phone-alt"></i><?= htmlspecialchars($row['soDienThoai'] ?? '—') ?></span>
                                <?php if ($row['email']): ?>
                                <span><i class="fas fa-envelope"></i><?= htmlspecialchars($row['email']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <!-- TOOLTIP -->
                    <div class="tooltip-box">
                        <?php if ($row['diaChi']): ?>
                        <div class="tt-row"><i class="fas fa-map-marker-alt"></i><?= htmlspecialchars($row['diaChi']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($row['ngaySinh'])): ?>
                        <div class="tt-row"><i class="fas fa-birthday-cake"></i><?= date('d/m/Y', strtotime($row['ngaySinh'])) ?> (<?= $age ?>)</div>
                        <?php endif; ?>
                        <div class="tt-row"><i class="fas fa-calendar-alt"></i>Đăng ký: <?= date('d/m/Y', strtotime($row['ngayDangKy'])) ?></div>
                        <hr class="tt-divider">
                        <div class="tt-stat"><span class="lbl">Tổng đơn hàng</span><span class="val"><?= $row['tongLanMua'] ?> đơn</span></div>
                        <div class="tt-stat" style="margin-top:5px;"><span class="lbl">Tổng chi tiêu</span><span class="val"><?= $chiTieu ?>đ</span></div>
                        <div class="tt-stat" style="margin-top:5px;"><span class="lbl">Phiếu sửa chữa</span><span class="val"><?= $row['tongLanSua'] ?> phiếu</span></div>
                    </div>
                </div>
            </td>
            <td>
                <span class="badge <?= $bc ?>"><?= $bi ?> <?= htmlspecialchars($row['loaiKhachHang']) ?></span>
            </td>
            <td>
                <div class="tx-row">
                    <div class="tx-ico g"><i class="fas fa-box"></i></div>
                    <span class="tx-num"><?= $row['tongLanMua'] ?></span>
                    <span style="color:var(--muted);font-size:11.5px;">đơn hàng</span>
                </div>
                <div class="tx-row">
                    <div class="tx-ico r"><i class="fas fa-tools"></i></div>
                    <span class="tx-num"><?= $row['tongLanSua'] ?></span>
                    <span style="color:var(--muted);font-size:11.5px;">phiếu sửa</span>
                </div>
            </td>
            <td>
                <div class="date-cell"><?= date('d/m/Y', strtotime($row['ngayDangKy'])) ?></div>
            </td>
            <td>
                <div style="display:flex;align-items:center;gap:6px;font-size:12.5px;font-weight:600;">
                    <span class="dot <?= $row['trangThai'] ? 'on' : 'off' ?>"></span>
                    <?= $row['trangThai'] ? 'Hoạt động' : 'Đã khóa' ?>
                </div>
            </td>
            <td style="text-align:center;">
                <a href="chi_tiet_khach_hang_ktv.php?id=<?= $row['maKH'] ?>" class="btn-detail" title="Xem chi tiết khách hàng">
                    <i class="fas fa-eye"></i> Xem
                </a>
            </td>
        </tr>
        <?php endwhile; else: ?>
        <tr><td colspan="6" class="empty-cell">
            <div class="empty-ico"><i class="fas fa-users-slash"></i></div>
            <div class="empty-t">Không tìm thấy khách hàng nào</div>
            <div class="empty-s">Thử thay đổi bộ lọc tìm kiếm</div>
        </td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ($totalPage > 1): ?>
    <div class="pagination-wrap">
        <div class="page-info">Trang <?= $page ?> / <?= $totalPage ?> · <?= number_format($totalRow) ?> kết quả</div>
        <div class="page-links">
            <?php
            $q = $_GET; $q['page'] = $page - 1; $prevQ = http_build_query($q);
            $q['page'] = $page + 1; $nextQ = http_build_query($q);
            ?>
            <a href="?<?= $prevQ ?>" class="page-btn <?= $page<=1?'disabled':'' ?>"><i class="fas fa-chevron-left"></i></a>
            <?php for($i=max(1,$page-2);$i<=min($totalPage,$page+2);$i++):
                $q['page']=$i; ?>
            <a href="?<?= http_build_query($q) ?>" class="page-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <a href="?<?= $nextQ ?>" class="page-btn <?= $page>=$totalPage?'disabled':'' ?>"><i class="fas fa-chevron-right"></i></a>
        </div>
    </div>
    <?php endif; ?>
</div>

</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// CLOCK
function updateClock(){
    const now=new Date(),pad=n=>String(n).padStart(2,'0');
    const days=['CN','T2','T3','T4','T5','T6','T7'];
    document.getElementById('live-clock').textContent=days[now.getDay()]+' '+pad(now.getHours())+':'+pad(now.getMinutes())+':'+pad(now.getSeconds());
}
updateClock(); setInterval(updateClock,1000);

// DONUT CHART
<?php if($total > 0): ?>
(function(){
    const ctx=document.getElementById('donutChart');
    if(!ctx) return;
    new Chart(ctx,{
        type:'doughnut',
        data:{
            labels:<?= json_encode($chartLabels) ?>,
            datasets:[{
                data:<?= json_encode($chartData) ?>,
                backgroundColor:<?= json_encode($chartColors) ?>,
                borderWidth:2,borderColor:'#ffffff',hoverBorderWidth:0
            }]
        },
        options:{responsive:false,cutout:'72%',plugins:{legend:{display:false},tooltip:{callbacks:{label:ctx=>' '+ctx.label+': '+ctx.raw}}}}
    });
})();
<?php endif; ?>

// SORT TABLE
let sortDir={};
function sortTable(col){
    const tb=document.getElementById('tableBody');
    const rows=Array.from(tb.querySelectorAll('tr'));
    if(!rows.length||rows[0].cells.length===1) return;
    sortDir[col]=!sortDir[col];
    rows.sort((a,b)=>{
        const at=a.cells[col]?.innerText.trim()??'';
        const bt=b.cells[col]?.innerText.trim()??'';
        const an=parseFloat(at.replace(/\D/g,'')),bn=parseFloat(bt.replace(/\D/g,''));
        let cmp=isNaN(an)||isNaN(bn)?at.localeCompare(bt,'vi'):an-bn;
        return sortDir[col]?cmp:-cmp;
    });
    rows.forEach(r=>tb.appendChild(r));
    document.querySelectorAll('thead th').forEach((th,i)=>{
        th.classList.toggle('sorted',i===col);
        const si=th.querySelector('.sort-icon');
        if(si) si.textContent=i===col?(sortDir[col]?'↑':'↓'):'⇅';
    });
}
</script>
</body>
</html>