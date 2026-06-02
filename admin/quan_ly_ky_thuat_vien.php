<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
    header("Location: ../login.php"); exit();
}

// ================================================================
// TẠO BẢNG
// ================================================================
$conn->query("CREATE TABLE IF NOT EXISTS `ky_thuat_vien` (
    `maKTV`       int(11)      NOT NULL AUTO_INCREMENT,
    `tenKTV`      varchar(100) NOT NULL,
    `email`       varchar(100) DEFAULT NULL,
    `soDienThoai` varchar(15)  DEFAULT NULL,
    `diaChi`      varchar(255) DEFAULT NULL,
    `ngaySinh`    date         DEFAULT NULL,
    `chuyenMon`   varchar(150) DEFAULT NULL,
    `ghiChu`      text         DEFAULT NULL,
    `ngayTaoTK`   date         DEFAULT NULL,
    `ngayCapNhat` datetime     DEFAULT NULL,
    `trangThai`   tinyint(1)   DEFAULT 1,
    PRIMARY KEY (`maKTV`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ================================================================
// ĐỒNG BỘ từ nhan_vien & users
// ================================================================
$sync = $conn->query("SELECT n.tenNV, n.email, n.ngayVaoLam FROM nhan_vien n
    WHERE n.chucVu = 'Kỹ thuật viên' AND n.trangThai = 1
    AND n.email NOT IN (SELECT COALESCE(email,'') FROM ky_thuat_vien)");
if ($sync && $sync->num_rows > 0) {
    while ($s = $sync->fetch_assoc()) {
        $ins = $conn->prepare("INSERT INTO ky_thuat_vien (tenKTV, email, ngayTaoTK, trangThai) VALUES (?,?,?,1)");
        $ins->bind_param("sss", $s['tenNV'], $s['email'], $s['ngayVaoLam']);
        $ins->execute(); $ins->close();
    }
}
$syncU = $conn->query("SELECT u.username, u.email, DATE(u.created_at) AS cdate FROM users u
    WHERE u.role='technician' AND u.status=1
    AND u.email NOT IN (SELECT COALESCE(email,'') FROM ky_thuat_vien)");
if ($syncU && $syncU->num_rows > 0) {
    while ($s = $syncU->fetch_assoc()) {
        $ins = $conn->prepare("INSERT INTO ky_thuat_vien (tenKTV, email, ngayTaoTK, trangThai) VALUES (?,?,?,1)");
        $ins->bind_param("sss", $s['username'], $s['email'], $s['cdate']);
        $ins->execute(); $ins->close();
    }
}

$msg = ""; $msg_type = "info";
$today = date('Y-m-d');

// ================================================================
// CẬP NHẬT THÔNG TIN
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_ktv'])) {
    $id    = (int)$_POST['maKTV'];
    $ten   = trim($_POST['tenKTV']);
    $ns    = trim($_POST['ngaySinh']);
    $sdt   = trim($_POST['soDienThoai']);
    $dc    = trim($_POST['diaChi']);
    $email = trim($_POST['email']);
    $cm    = trim($_POST['chuyenMon']);
    $ghi   = trim($_POST['ghiChu']);
    $ngayTaoTK = trim($_POST['ngayTaoTK'] ?? '');

    // --- SERVER-SIDE VALIDATION ---
    $errors = [];
    if (empty($ten))  $errors[] = "Họ và tên không được để trống.";
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors[] = "Gmail không hợp lệ (phải có @).";
    if (!preg_match('/^[0-9]{10}$/', $sdt))
        $errors[] = "Số điện thoại phải đúng 10 chữ số.";
    if (empty($dc))   $errors[] = "Địa chỉ không được để trống.";
    if (empty($ns))   $errors[] = "Ngày sinh không được để trống.";
    elseif ($ns > $today) $errors[] = "Ngày sinh không được lớn hơn ngày hiện tại.";
    if (!empty($ngayTaoTK) && $ngayTaoTK > $today)
        $errors[] = "Ngày đăng ký tài khoản không được lớn hơn ngày hiện tại.";

    if (!empty($errors)) {
        $msg = "⚠️ " . implode("<br>⚠️ ", $errors);
        $msg_type = "warning";
    } else {
        $ns_val = $ns ?: null;
        $ntk_val = !empty($ngayTaoTK) ? $ngayTaoTK : null;
        $stmt = $conn->prepare("UPDATE ky_thuat_vien SET tenKTV=?, ngaySinh=?, soDienThoai=?, diaChi=?, email=?, chuyenMon=?, ghiChu=?, ngayTaoTK=?, ngayCapNhat=NOW() WHERE maKTV=?");
        $stmt->bind_param("ssssssssi", $ten, $ns_val, $sdt, $dc, $email, $cm, $ghi, $ntk_val, $id);
        if ($stmt->execute()) {
            $msg = "✅ Cập nhật thông tin kỹ thuật viên <strong>" . htmlspecialchars($ten) . "</strong> thành công!";
            $msg_type = "success";
        } else {
            $msg = "❌ Có lỗi khi cập nhật!"; $msg_type = "danger";
        }
        $stmt->close();
    }
}

// ================================================================
// XÓA (soft delete)
// ================================================================
if (isset($_GET['del'])) {
    $conn->query("UPDATE ky_thuat_vien SET trangThai=0 WHERE maKTV=" . (int)$_GET['del']);
    header("Location: quan_ly_ky_thuat_vien.php?msg=" . urlencode("Đã xóa kỹ thuật viên!") . "&mtype=warning");
    exit();
}
if (isset($_GET['msg'])) {
    $msg = htmlspecialchars($_GET['msg']);
    $msg_type = $_GET['mtype'] ?? 'info';
}

// ================================================================
// TRUY VẤN
// ================================================================
$search = trim($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all';

$pending_col = "(tenKTV IS NULL OR tenKTV='' OR ngaySinh IS NULL OR soDienThoai IS NULL OR soDienThoai='' OR diaChi IS NULL OR diaChi='' OR email IS NULL OR email='')";
$pending_k   = "(k.tenKTV IS NULL OR k.tenKTV='' OR k.ngaySinh IS NULL OR k.soDienThoai IS NULL OR k.soDienThoai='' OR k.diaChi IS NULL OR k.diaChi='' OR k.email IS NULL OR k.email='')";

$total_ktv = 0; $pending_ktv = 0;
$r1 = $conn->query("SELECT COUNT(*) c FROM ky_thuat_vien WHERE trangThai=1");
if ($r1) $total_ktv = (int)$r1->fetch_assoc()['c'];
$r2 = $conn->query("SELECT COUNT(*) c FROM ky_thuat_vien WHERE trangThai=1 AND $pending_col");
if ($r2) $pending_ktv = (int)$r2->fetch_assoc()['c'];
$updated_ktv = $total_ktv - $pending_ktv;

$where_parts = ["k.trangThai=1"];
$params_arr = []; $types_str = "";
if (!empty($search)) {
    $where_parts[] = "(k.tenKTV LIKE ? OR k.email LIKE ? OR k.soDienThoai LIKE ?)";
    $sw = "%$search%";
    $params_arr = [$sw,$sw,$sw]; $types_str="sss";
}
if ($filter==='pending')      $where_parts[] = $pending_k;
elseif ($filter==='updated')  $where_parts[] = "NOT $pending_k";

$where = "WHERE ".implode(" AND ",$where_parts);
$sql = "SELECT k.*, CASE WHEN $pending_k THEN 1 ELSE 0 END AS chua_cap_nhat
        FROM ky_thuat_vien k $where ORDER BY k.maKTV DESC";
$sl = $conn->prepare($sql);
if (!$sl) die("SQL Error: ".$conn->error);
if (!empty($types_str)) $sl->bind_param($types_str,...$params_arr);
$sl->execute();
$result = $sl->get_result();
if (!$result) die("Result Error: ".$sl->error);

$rows = [];
while ($row = $result->fetch_assoc()) $rows[] = $row;

// Lấy thông tin tài khoản user (username) theo email
$emailList = array_filter(array_column($rows, 'email'));
$userMap = [];
if (!empty($emailList)) {
    $placeholders = implode(',', array_fill(0, count($emailList), '?'));
    $uq = $conn->prepare("SELECT username, email, created_at, status FROM users WHERE email IN ($placeholders) AND role='technician'");
    $types_u = str_repeat('s', count($emailList));
    $uq->bind_param($types_u, ...array_values($emailList));
    $uq->execute();
    $ur = $uq->get_result();
    while ($u = $ur->fetch_assoc()) $userMap[$u['email']] = $u;
    $uq->close();
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Quản lý Kỹ Thuật Viên — Quang Anh Tech</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root {
    --primary: #b45309;
    --primary-dark: #92400e;
    --primary-light: #fef9c3;
    --sidebar-bg: #0f172a;
    --cr: 14px;
}
* { box-sizing: border-box; }
body { background: #f1f5f9; font-family: 'Inter', 'Segoe UI', sans-serif; color: #0f172a; margin: 0; }

/* ── SIDEBAR ── */
.sidebar { width:260px; height:100vh; position:fixed; top:0; left:0; background:var(--sidebar-bg); color:white; z-index:100; display:flex; flex-direction:column; }
.sidebar-logo { padding:22px 24px 18px; border-bottom:1px solid rgba(255,255,255,.07); }
.sidebar-logo h4 { font-size:17px; font-weight:700; margin:0; }
.sidebar nav { padding:10px 0; flex:1; overflow-y:auto; }
.sidebar .nav-link { color:#94a3b8; padding:10px 24px; margin:1px 10px; border-radius:10px; font-size:13.5px; font-weight:500; text-decoration:none; display:flex; align-items:center; gap:10px; transition:all .18s; }
.sidebar .nav-link:hover { background:rgba(255,255,255,.08); color:white; }
.sidebar .nav-link.active { background:var(--primary); color:white; }
.sidebar .nav-link i { width:18px; text-align:center; }
.sidebar-bottom { padding:12px 10px 20px; border-top:1px solid rgba(255,255,255,.07); }

/* ── MAIN ── */
.main-wrap { margin-left:260px; padding:28px 32px; min-height:100vh; }

/* ── STAT CARDS ── */
.stat-card { background:white; border-radius:var(--cr); padding:20px 22px; box-shadow:0 2px 8px rgba(0,0,0,.07); display:flex; align-items:center; gap:14px; transition:transform .2s,box-shadow .2s; text-decoration:none; color:inherit; }
.stat-card:hover { transform:translateY(-3px); box-shadow:0 8px 20px rgba(0,0,0,.1); }
.stat-icon { width:46px; height:46px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }

/* ── TABLE CARD ── */
.table-card { background:white; border-radius:20px; padding:28px; box-shadow:0 4px 20px rgba(0,0,0,.05); }
.table > thead > tr > th { font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.5px; padding-bottom:12px; border-bottom:2px solid #f1f5f9; white-space:nowrap; }
.table > tbody > tr > td { padding:12px 10px; vertical-align:middle; font-size:13.5px; }
.table > tbody > tr:hover { background:#fafafa; }

/* ── BADGES ── */
.badge-pending { background:#fef9c3; color:#92400e; border:1px solid #fde68a; padding:4px 10px; border-radius:20px; font-size:11px; font-weight:700; white-space:nowrap; }
.badge-ok      { background:#dcfce7; color:#15803d; border:1px solid #86efac; padding:4px 10px; border-radius:20px; font-size:11px; font-weight:700; white-space:nowrap; }

/* ── BTN ── */
.btn-lbl { display:inline-flex; align-items:center; gap:5px; padding:6px 12px; border-radius:8px; border:1.5px solid; font-size:12px; font-weight:600; cursor:pointer; transition:all .18s; white-space:nowrap; background:white; }
.btn-lbl-edit { color:#16a34a; border-color:#bbf7d0; background:#f0fdf4; }
.btn-lbl-edit:hover { background:#16a34a; color:white; border-color:#16a34a; }
.btn-lbl-view { color:#2563eb; border-color:#bfdbfe; background:#eff6ff; }
.btn-lbl-view:hover { background:#2563eb; color:white; border-color:#2563eb; }
.btn-lbl-del { color:#ef4444; border-color:#fecaca; background:#fef2f2; }
.btn-lbl-del:hover { background:#ef4444; color:white; border-color:#ef4444; }

/* ── ALERT PENDING ── */
.alert-pending-box { background:linear-gradient(135deg,#fffbeb,#fef9c3); border:1.5px solid #fde68a; border-radius:14px; padding:0; overflow:hidden; }
.alert-pending-strip { background:linear-gradient(135deg,#b45309,#d97706); width:6px; flex-shrink:0; }

/* ── SEARCH ── */
.search-box { background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:10px; padding:4px 12px; display:flex; align-items:center; gap:8px; }
.search-box input { border:none; background:transparent; padding:7px 0; font-size:14px; outline:none; width:100%; }

/* ── MODAL ── */
.modal-card { border:none; border-radius:20px; box-shadow:0 20px 60px rgba(0,0,0,.18); overflow:hidden; }
.modal-hdr { background:linear-gradient(135deg,#92400e,#b45309); color:white; padding:20px 24px; }
.modal-hdr-view { background:linear-gradient(135deg,#1e3a8a,#2563eb); color:white; padding:0; }

/* ── TAB ── */
.tab-nav { display:flex; gap:0; border-bottom:2px solid #f1f5f9; margin-bottom:20px; }
.tab-btn { padding:10px 20px; border:none; background:none; font-size:13px; font-weight:600; color:#64748b; cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-2px; display:flex; align-items:center; gap:7px; transition:all .18s; }
.tab-btn.active { color:#b45309; border-bottom-color:#b45309; }
.tab-btn:hover { color:#b45309; }
.tab-pane { display:none; }
.tab-pane.active { display:block; }

/* ── FORM CONTROLS ── */
.form-ctrl { background:#f8fafc; border:1.5px solid #e2e8f0; border-radius:10px; padding:9px 13px; font-size:14px; width:100%; transition:border-color .2s,box-shadow .2s; font-family:inherit; }
.form-ctrl:focus { border-color:#b45309; box-shadow:0 0 0 3px rgba(180,83,9,.12); outline:none; background:white; }
.form-ctrl.is-invalid { border-color:#ef4444; box-shadow:0 0 0 3px rgba(239,68,68,.1); }
.form-ctrl.is-valid { border-color:#16a34a; }
.label-sm { font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.5px; margin-bottom:5px; display:block; }
.field-err { font-size:11.5px; color:#ef4444; margin-top:4px; display:none; }
.field-err.show { display:block; }

/* ── VIEW MODAL INFO ── */
.info-section-title { font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.5px; padding:8px 0 10px; border-bottom:1.5px solid #f1f5f9; margin-bottom:14px; display:flex; align-items:center; gap:7px; }
.info-row { display:flex; align-items:flex-start; gap:12px; padding:10px 14px; border-radius:10px; background:#f8fafc; margin-bottom:8px; }
.info-icon { width:34px; height:34px; border-radius:9px; display:flex; align-items:center; justify-content:center; font-size:13px; flex-shrink:0; }
.info-label { font-size:10.5px; font-weight:700; color:#94a3b8; text-transform:uppercase; letter-spacing:.4px; margin-bottom:2px; }
.info-val { font-size:14px; font-weight:600; color:#0f172a; word-break:break-all; }
.info-val.empty { color:#94a3b8; font-style:italic; font-weight:400; }

/* ── AVATAR KTV ── */
.ktv-avatar-lg { width:72px; height:72px; border-radius:18px; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:28px; border:3px solid rgba(255,255,255,.3); flex-shrink:0; }
.ktv-avatar-sm { width:38px; height:38px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:15px; flex-shrink:0; background:#fef9c3; color:#b45309; }

/* ── STATUS DOT ── */
.status-dot { width:8px; height:8px; border-radius:50%; display:inline-block; margin-right:5px; }
.dot-on  { background:#22c55e; }
.dot-off { background:#ef4444; }

/* ── ACCOUNT CARD ── */
.account-card { background:linear-gradient(135deg,#0f172a,#1e293b); border-radius:12px; padding:16px 18px; color:white; }
.account-card .acc-label { font-size:10px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.5px; margin-bottom:3px; }
.account-card .acc-val { font-size:15px; font-weight:700; color:white; font-family:'Courier New',monospace; }
.pass-hidden { letter-spacing:3px; font-size:18px; }

@media(max-width:768px){ .sidebar{display:none;} .main-wrap{margin-left:0;padding:16px;} }
</style>
</head>
<body>

<!-- SIDEBAR -->
<div class="sidebar">
    <div class="sidebar-logo">
        <h4 class="text-white">QA <span style="color:#fbbf24">TECH</span> <small class="text-secondary fw-normal" style="font-size:13px">Admin</small></h4>
    </div>
    <nav>
        <a class="nav-link" href="index.php"><i class="fas fa-th-large"></i> Tổng quan</a>
        <a class="nav-link" href="users.php"><i class="fas fa-user-shield"></i> Phân quyền</a>
        <a class="nav-link" href="quan_ly_nhan_vien.php"><i class="fas fa-id-badge"></i> Quản lý nhân viên</a>
        <a class="nav-link active" href="quan_ly_ky_thuat_vien.php">
            <i class="fas fa-tools"></i> Kỹ thuật viên
            <?php if($pending_ktv>0): ?><span class="ms-auto badge" style="background:#ef4444;color:white;font-size:10px;padding:3px 7px;border-radius:20px"><?=$pending_ktv?></span><?php endif; ?>
        </a>
        <a class="nav-link" href="quan_ly_khach_hang.php"><i class="fas fa-users"></i> Khách hàng</a>
        <a class="nav-link" href="promotions.php"><i class="fas fa-percentage"></i> Khuyến mãi</a>
        <a class="nav-link" href="advertising.php"><i class="fas fa-ad"></i> Quảng bá</a>
        <a class="nav-link" href="system_logs.php"><i class="fas fa-clipboard-list"></i> Nhật ký</a>
    </nav>
    <div class="sidebar-bottom">
        <a class="nav-link text-danger" href="../logout.php"><i class="fas fa-sign-out-alt"></i> Đăng xuất</a>
    </div>
</div>

<!-- MAIN -->
<div class="main-wrap">

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold mb-1" style="font-size:23px"><i class="fas fa-tools me-2" style="color:#b45309"></i>Quản lý Kỹ Thuật Viên</h2>
            <p class="text-secondary mb-0 small">Quản lý hồ sơ và thông tin chi tiết của toàn bộ kỹ thuật viên.</p>
        </div>
        <a href="users.php?tab=technician" class="btn btn-sm fw-semibold px-4 py-2 rounded-3 text-white" style="background:#b45309">
            <i class="fas fa-user-plus me-2"></i>Tạo tài khoản KTV mới
        </a>
    </div>

    <!-- Stat Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-icon" style="background:#fef9c3;color:#b45309"><i class="fas fa-hard-hat"></i></div>
                <div><div class="small text-secondary">Tổng kỹ thuật viên</div><div class="fw-bold fs-4"><?=$total_ktv?></div></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-icon" style="background:#fef2f2;color:#ef4444"><i class="fas fa-clock"></i></div>
                <div><div class="small text-secondary">Chưa cập nhật hồ sơ</div><div class="fw-bold fs-4 text-danger"><?=$pending_ktv?></div></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="stat-icon" style="background:#dcfce7;color:#16a34a"><i class="fas fa-check-circle"></i></div>
                <div><div class="small text-secondary">Hồ sơ đầy đủ</div><div class="fw-bold fs-4 text-success"><?=$updated_ktv?></div></div>
            </div>
        </div>
    </div>

    <!-- THÔNG BÁO PENDING -->
    <?php
    $alerts = $conn->query("SELECT maKTV, tenKTV, email, ngayTaoTK FROM ky_thuat_vien WHERE trangThai=1 AND $pending_col ORDER BY maKTV DESC LIMIT 5");
    if ($alerts && $alerts->num_rows > 0):
    ?>
    <div class="mb-4">
        <?php while($al = $alerts->fetch_assoc()):
            $ngayTao = !empty($al['ngayTaoTK']) ? date('d/m/Y', strtotime($al['ngayTaoTK'])) : date('d/m/Y');
            $idAl = 'alert_ktv_'.$al['maKTV'];
        ?>
        <div class="alert-pending-box d-flex mb-2" id="<?=$idAl?>">
            <div class="alert-pending-strip"></div>
            <div class="d-flex align-items-center flex-grow-1 px-3 py-3 gap-3">
                <div style="font-size:24px;flex-shrink:0">⚠️</div>
                <div class="flex-grow-1">
                    <div class="fw-bold mb-1" style="color:#92400e;font-size:14px">
                        Kỹ thuật viên <span style="color:#b45309"><?=htmlspecialchars($al['tenKTV'])?></span>
                        vừa được tạo tài khoản vào ngày <strong><?=$ngayTao?></strong> và <span class="text-danger">chưa cập nhật thông tin</span>.
                    </div>
                    <div class="small" style="color:#78350f"><i class="fas fa-info-circle me-1"></i>Điền đầy đủ <strong>họ tên, ngày sinh, SĐT, địa chỉ, gmail</strong> để quản lý nhân viên tốt hơn.</div>
                    <button onclick="openEdit(<?=$al['maKTV']?>)" class="btn btn-sm fw-semibold rounded-3 px-3 text-white mt-2" style="background:#b45309;border:none;font-size:12px">
                        <i class="fas fa-pen me-1"></i>Cập nhật ngay
                    </button>
                </div>
                <button class="btn-close" onclick="document.getElementById('<?=$idAl?>').remove()"></button>
            </div>
        </div>
        <?php endwhile; ?>
        <?php if($pending_ktv>5): ?>
        <div class="text-center mt-2">
            <a href="?filter=pending" class="btn btn-sm btn-outline-warning rounded-3 fw-semibold px-4">
                <i class="fas fa-list me-1"></i>Xem tất cả <?=$pending_ktv?> KTV chưa cập nhật
            </a>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Alert msg -->
    <?php if(!empty($msg)): ?>
    <div class="alert alert-<?=$msg_type?> alert-dismissible fade show border-0 rounded-3 shadow-sm mb-4">
        <?=$msg?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- TABLE CARD -->
    <div class="table-card">

        <!-- Toolbar -->
        <div class="row g-3 mb-4 align-items-center">
            <div class="col-lg-5">
                <form method="GET" class="d-flex gap-2">
                    <div class="search-box flex-grow-1">
                        <i class="fas fa-search text-secondary" style="font-size:13px"></i>
                        <input type="text" name="search" placeholder="Tìm tên, email, SĐT..." value="<?=htmlspecialchars($search)?>">
                        <input type="hidden" name="filter" value="<?=htmlspecialchars($filter)?>">
                    </div>
                    <button type="submit" class="btn btn-sm px-3 py-2 rounded-3 text-white fw-semibold" style="background:var(--primary);white-space:nowrap">
                        <i class="fas fa-filter me-1"></i>Lọc
                    </button>
                    <?php if(!empty($search)): ?><a href="?filter=<?=$filter?>" class="btn btn-sm btn-light px-3 py-2 rounded-3">Xóa</a><?php endif; ?>
                </form>
            </div>
            <div class="col-lg-7 d-flex gap-2 justify-content-end flex-wrap">
                <a href="?filter=all" class="btn btn-sm rounded-3 fw-semibold <?=$filter=='all'?'text-white':'btn-light'?>" style="<?=$filter=='all'?'background:#b45309':''?>">Tất cả <span class="badge bg-secondary ms-1"><?=$total_ktv?></span></a>
                <a href="?filter=pending" class="btn btn-sm rounded-3 fw-semibold <?=$filter=='pending'?'text-white':'btn-light'?>" style="<?=$filter=='pending'?'background:#ef4444':''?>">Chưa cập nhật <span class="badge bg-danger ms-1"><?=$pending_ktv?></span></a>
                <a href="?filter=updated" class="btn btn-sm rounded-3 fw-semibold <?=$filter=='updated'?'text-white':'btn-light'?>" style="<?=$filter=='updated'?'background:#16a34a':''?>">Đã đầy đủ <span class="badge bg-success ms-1"><?=$updated_ktv?></span></a>
            </div>
        </div>

        <!-- Table -->
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width:44px">#</th>
                        <th>KỸ THUẬT VIÊN</th>
                        <th>SĐT</th>
                        <th>NGÀY SINH</th>
                        <th>ĐỊA CHỈ</th>
                        <th>TÀI KHOẢN</th>
                        <th>HỒ SƠ</th>
                        <th>NGÀY TẠO TK</th>
                        <th class="text-end">THAO TÁC</th>
                    </tr>
                </thead>
                <tbody>
                <?php if(empty($rows)): ?>
                <tr><td colspan="9" class="text-center text-secondary py-5">
                    <i class="fas fa-tools fa-2x mb-2 d-block text-muted"></i>Không có kỹ thuật viên nào.
                </td></tr>
                <?php else: ?>
                <?php $stt=1; foreach($rows as $row):
                    $uInfo = isset($row['email']) && isset($userMap[$row['email']]) ? $userMap[$row['email']] : null;
                ?>
                <tr id="row_<?=$row['maKTV']?>">
                    <td class="text-secondary small"><?=$stt++?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="ktv-avatar-sm"><?=strtoupper(substr($row['tenKTV']??'K',0,1))?></div>
                            <div>
                                <div class="fw-semibold" style="font-size:13.5px"><?=htmlspecialchars($row['tenKTV'])?></div>
                                <div class="text-secondary" style="font-size:12px"><?=htmlspecialchars($row['email']??'—')?></div>
                            </div>
                        </div>
                    </td>
                    <td class="small"><?=htmlspecialchars($row['soDienThoai']??'')?:('<span class="text-danger small">Chưa có</span>')?></td>
                    <td class="small"><?=!empty($row['ngaySinh'])?date('d/m/Y',strtotime($row['ngaySinh'])):('<span class="text-danger small">Chưa có</span>')?></td>
                    <td class="small" style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?=htmlspecialchars($row['diaChi']??'')?>">
                        <?=htmlspecialchars($row['diaChi']??'')?:('<span class="text-danger small">Chưa có</span>')?>
                    </td>
                    <td>
                        <?php if($uInfo): ?>
                        <div style="font-size:12px">
                            <span class="fw-semibold"><i class="fas fa-user me-1 text-secondary"></i><?=htmlspecialchars($uInfo['username'])?></span><br>
                            <span class="<?=$uInfo['status']==1?'text-success':'text-danger'?>">
                                <span class="status-dot <?=$uInfo['status']==1?'dot-on':'dot-off'?>"></span>
                                <?=$uInfo['status']==1?'Hoạt động':'Bị khóa'?>
                            </span>
                        </div>
                        <?php else: ?><span class="text-secondary small">—</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if($row['chua_cap_nhat']): ?>
                        <span class="badge-pending"><i class="fas fa-clock me-1"></i>Chưa đủ</span>
                        <?php else: ?>
                        <span class="badge-ok"><i class="fas fa-check me-1"></i>Đầy đủ</span>
                        <?php endif; ?>
                    </td>
                    <td class="small text-secondary"><?=!empty($row['ngayTaoTK'])?date('d/m/Y',strtotime($row['ngayTaoTK'])):('—')?></td>
                    <td class="text-end">
                        <div class="d-flex gap-1 justify-content-end">
                            <button onclick="openView(<?=$row['maKTV']?>)" class="btn-lbl btn-lbl-view" title="Xem chi tiết">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button onclick="openEdit(<?=$row['maKTV']?>)" class="btn-lbl btn-lbl-edit" title="Cập nhật hồ sơ">
                                <i class="fas fa-pen-to-square"></i> Cập nhật
                            </button>
                            <button onclick="confirmDel(<?=$row['maKTV']?>,'<?=htmlspecialchars(addslashes($row['tenKTV']))?>')" class="btn-lbl btn-lbl-del" title="Xóa">
                                <i class="fas fa-trash-can"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ============================================================ MODAL XEM CHI TIẾT -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content modal-card">
            <!-- Header gradient -->
            <div class="modal-hdr-view">
                <div style="background:linear-gradient(135deg,#92400e,#b45309);padding:24px 28px 0">
                    <div class="d-flex align-items-center gap-4">
                        <div id="v_avatar" class="ktv-avatar-lg" style="background:rgba(255,255,255,.15);color:white"></div>
                        <div class="flex-grow-1">
                            <h4 class="fw-bold mb-0 text-white" id="v_name">—</h4>
                            <div class="text-white opacity-75 small mt-1" id="v_email_head">—</div>
                            <div class="mt-2 d-flex gap-2 flex-wrap" id="v_badges"></div>
                        </div>
                        <button type="button" class="btn-close btn-close-white align-self-start" data-bs-dismiss="modal"></button>
                    </div>
                    <!-- Quick actions -->
                    <div class="d-flex gap-2 mt-4 pb-0">
                        <button onclick="switchToEdit()" class="btn btn-sm fw-semibold flex-fill rounded-top-3 py-2" style="background:rgba(255,255,255,.15);color:white;border:none;border-radius:8px 8px 0 0 !important">
                            <i class="fas fa-pen me-1"></i>Chỉnh sửa
                        </button>
                        <button onclick="confirmDelCurrent()" class="btn btn-sm fw-semibold flex-fill rounded-top-3 py-2" style="background:rgba(239,68,68,.3);color:white;border:none;border-radius:8px 8px 0 0 !important">
                            <i class="fas fa-trash me-1"></i>Xóa
                        </button>
                    </div>
                </div>
            </div>
            <div class="modal-body p-0">
                <!-- Tabs -->
                <div class="px-4 pt-3">
                    <div class="tab-nav">
                        <button class="tab-btn active" onclick="switchViewTab('personal',this)"><i class="fas fa-user"></i> Thông tin cá nhân</button>
                        <button class="tab-btn" onclick="switchViewTab('account',this)"><i class="fas fa-key"></i> Tài khoản hệ thống</button>
                    </div>
                </div>
                <!-- Tab: Thông tin cá nhân -->
                <div class="tab-pane active px-4 pb-4" id="vt_personal">
                    <div class="info-section-title"><i class="fas fa-address-card" style="color:#b45309"></i> Thông tin hồ sơ</div>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="info-icon" style="background:#fef9c3;color:#b45309"><i class="fas fa-user"></i></div>
                                <div><div class="info-label">Họ và tên</div><div class="info-val" id="v_ten">—</div></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="info-icon" style="background:#f0f9ff;color:#0ea5e9"><i class="fas fa-cake-candles"></i></div>
                                <div><div class="info-label">Ngày sinh</div><div class="info-val" id="v_ns">—</div></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="info-icon" style="background:#f0fdf4;color:#16a34a"><i class="fas fa-phone"></i></div>
                                <div><div class="info-label">Số điện thoại</div><div class="info-val" id="v_sdt">—</div></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="info-icon" style="background:#fef3c7;color:#d97706"><i class="fas fa-envelope"></i></div>
                                <div><div class="info-label">Gmail</div><div class="info-val" id="v_gmail">—</div></div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="info-row">
                                <div class="info-icon" style="background:#fdf4ff;color:#9333ea"><i class="fas fa-location-dot"></i></div>
                                <div><div class="info-label">Địa chỉ</div><div class="info-val" id="v_dc">—</div></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="info-icon" style="background:#eff6ff;color:#2563eb"><i class="fas fa-wrench"></i></div>
                                <div><div class="info-label">Chuyên môn</div><div class="info-val" id="v_cm">—</div></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-row">
                                <div class="info-icon" style="background:#f8fafc;color:#64748b"><i class="fas fa-calendar-plus"></i></div>
                                <div><div class="info-label">Ngày tạo tài khoản</div><div class="info-val" id="v_ngaytk">—</div></div>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="info-row">
                                <div class="info-icon" style="background:#f8fafc;color:#64748b"><i class="fas fa-note-sticky"></i></div>
                                <div><div class="info-label">Ghi chú</div><div class="info-val" id="v_ghi">—</div></div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Tab: Tài khoản -->
                <div class="tab-pane px-4 pb-4" id="vt_account">
                    <div class="info-section-title"><i class="fas fa-shield-halved" style="color:#2563eb"></i> Thông tin đăng nhập hệ thống</div>
                    <div id="v_account_content">
                        <div class="account-card mb-3">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="acc-label">Tên đăng nhập</div>
                                    <div class="acc-val" id="v_username">—</div>
                                </div>
                                <div class="col-md-6">
                                    <div class="acc-label">Mật khẩu (đã mã hóa)</div>
                                    <div class="acc-val pass-hidden">••••••••••</div>
                                </div>
                                <div class="col-md-6">
                                    <div class="acc-label">Trạng thái tài khoản</div>
                                    <div class="acc-val" id="v_acc_status">—</div>
                                </div>
                                <div class="col-md-6">
                                    <div class="acc-label">Ngày tạo tài khoản</div>
                                    <div class="acc-val" id="v_acc_created">—</div>
                                </div>
                            </div>
                        </div>
                        <div id="v_no_account" class="d-none text-center py-4">
                            <i class="fas fa-user-slash fa-2x text-muted mb-2 d-block"></i>
                            <div class="text-secondary small">Chưa có tài khoản đăng nhập liên kết</div>
                            <a href="users.php?tab=technician" class="btn btn-sm btn-outline-primary rounded-3 mt-2 px-4">Tạo tài khoản</a>
                        </div>
                        <div class="p-3 rounded-3" style="background:#f0f9ff;border:1px solid #bae6fd">
                            <i class="fas fa-info-circle text-info me-2"></i>
                            <span class="small" style="color:#0369a1">Mật khẩu được mã hóa bảo mật một chiều. Nếu cần reset, vào <a href="users.php" class="fw-bold text-primary">Phân quyền</a> để cập nhật.</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-2">
                <button type="button" class="btn btn-light w-100 rounded-3 py-2 fw-semibold" data-bs-dismiss="modal">
                    <i class="fas fa-xmark me-1"></i>Đóng
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ MODAL CẬP NHẬT -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <form class="modal-content modal-card" method="POST" id="editForm" novalidate>
            <div class="modal-hdr d-flex align-items-center justify-content-between" style="background:linear-gradient(135deg,#92400e,#b45309);padding:20px 24px">
                <div>
                    <h5 class="mb-0 fw-bold text-white"><i class="fas fa-user-edit me-2"></i>Cập nhật hồ sơ kỹ thuật viên</h5>
                    <div class="opacity-75 small mt-1 text-white" id="modal_subtitle">Điền đầy đủ để hoàn tất hồ sơ</div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 pt-4 pb-2">
                <input type="hidden" name="update_ktv" value="1">
                <input type="hidden" name="maKTV" id="f_id">

                <!-- Tabs trong modal edit -->
                <div class="tab-nav">
                    <button type="button" class="tab-btn active" onclick="switchEditTab('personal',this)"><i class="fas fa-address-card"></i> Thông tin cá nhân</button>
                    <button type="button" class="tab-btn" onclick="switchEditTab('extra',this)"><i class="fas fa-clipboard"></i> Thông tin bổ sung</button>
                </div>

                <!-- Tab: Cá nhân -->
                <div class="tab-pane active" id="et_personal">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="label-sm">Họ và tên <span class="text-danger">*</span></label>
                            <input type="text" name="tenKTV" id="f_ten" class="form-ctrl" placeholder="Nhập họ và tên đầy đủ">
                            <div class="field-err" id="err_ten">Họ và tên không được để trống</div>
                        </div>
                        <div class="col-md-6">
                            <label class="label-sm">Ngày sinh <span class="text-danger">*</span></label>
                            <input type="date" name="ngaySinh" id="f_ns" class="form-ctrl">
                            <div class="field-err" id="err_ns">Ngày sinh không được lớn hơn hôm nay</div>
                        </div>
                        <div class="col-md-6">
                            <label class="label-sm">Số điện thoại <span class="text-danger">*</span></label>
                            <div style="position:relative">
                                <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:13px">+84</span>
                                <input type="text" name="soDienThoai" id="f_sdt" class="form-ctrl" style="padding-left:42px" placeholder="0901234567" maxlength="10">
                            </div>
                            <div class="field-err" id="err_sdt">Số điện thoại phải đúng 10 chữ số</div>
                        </div>
                        <div class="col-md-6">
                            <label class="label-sm">Gmail <span class="text-danger">*</span></label>
                            <div style="position:relative">
                                <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:13px"><i class="fas fa-envelope"></i></span>
                                <input type="email" name="email" id="f_email" class="form-ctrl" style="padding-left:36px" placeholder="example@gmail.com">
                            </div>
                            <div class="field-err" id="err_email">Gmail phải có @ và đúng định dạng</div>
                        </div>
                        <div class="col-12">
                            <label class="label-sm">Địa chỉ <span class="text-danger">*</span></label>
                            <input type="text" name="diaChi" id="f_dc" class="form-ctrl" placeholder="Số nhà, đường, quận/huyện, tỉnh/thành phố">
                            <div class="field-err" id="err_dc">Địa chỉ không được để trống</div>
                        </div>
                    </div>
                </div>

                <!-- Tab: Bổ sung -->
                <div class="tab-pane" id="et_extra">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="label-sm">Ngày đăng ký tài khoản</label>
                            <input type="date" name="ngayTaoTK" id="f_ntk" class="form-ctrl">
                            <div class="field-err" id="err_ntk">Ngày đăng ký không được lớn hơn hôm nay</div>
                            <div class="small text-secondary mt-1"><i class="fas fa-info-circle me-1"></i>Tự động lấy từ hệ thống, chỉ chỉnh khi cần thiết.</div>
                        </div>
                        <div class="col-12">
                            <label class="label-sm">Chuyên môn / Kỹ năng</label>
                            <input type="text" name="chuyenMon" id="f_cm" class="form-ctrl" placeholder="VD: Sửa laptop, cài đặt mạng LAN, camera...">
                        </div>
                        <div class="col-12">
                            <label class="label-sm">Ghi chú</label>
                            <textarea name="ghiChu" id="f_ghi" class="form-ctrl" rows="3" placeholder="Ghi chú thêm về kỹ thuật viên này..." style="resize:none"></textarea>
                        </div>
                    </div>
                    <!-- Hiển thị thông tin tài khoản (readonly) -->
                    <div class="mt-4">
                        <div class="info-section-title"><i class="fas fa-key" style="color:#b45309"></i> Tài khoản đăng nhập</div>
                        <div id="edit_acc_info" class="account-card">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <div class="acc-label">Tên đăng nhập</div>
                                    <div class="acc-val" id="ea_username">—</div>
                                </div>
                                <div class="col-md-6">
                                    <div class="acc-label">Trạng thái</div>
                                    <div class="acc-val" id="ea_status">—</div>
                                </div>
                                <div class="col-12">
                                    <div class="acc-label">Email đăng nhập</div>
                                    <div class="acc-val" id="ea_email">—</div>
                                </div>
                            </div>
                        </div>
                        <div class="small text-secondary mt-2"><i class="fas fa-lock me-1"></i>Để thay đổi mật khẩu, vui lòng vào trang <a href="users.php" class="text-primary fw-semibold">Phân quyền</a>.</div>
                    </div>
                </div>

                <!-- Note -->
                <div class="mt-3 p-3 rounded-3" style="background:#fef9c3;border:1px solid #fde68a">
                    <i class="fas fa-circle-info" style="color:#b45309"></i>
                    <span class="small ms-1" style="color:#92400e">Thông báo sẽ <strong>tự động tắt</strong> khi bạn điền đầy đủ tất cả trường có dấu <span class="text-danger">*</span>.</span>
                </div>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-2 gap-2">
                <button type="button" class="btn btn-light flex-fill rounded-3 py-2 fw-semibold" data-bs-dismiss="modal">
                    <i class="fas fa-xmark me-1"></i>Hủy
                </button>
                <button type="submit" class="btn text-white flex-fill rounded-3 py-2 fw-semibold" style="background:#b45309" id="btnSave">
                    <i class="fas fa-save me-1"></i>Lưu thông tin
                </button>
            </div>
        </form>
    </div>
</div>

<!-- DATA JSON -->
<script>
const TODAY = '<?= $today ?>';
const ktvData = <?= json_encode(array_column($rows, null, 'maKTV'), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
const userMap = <?= json_encode($userMap, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;

let _currentId = null;

// ── FORMAT DATE dd/mm/yyyy ──
function fmtDate(d) {
    if (!d) return '<span class="empty">Chưa cập nhật</span>';
    const p = d.substring(0,10).split('-');
    if (p.length < 3) return d;
    return p[2]+'/'+p[1]+'/'+p[0];
}
function fmtVal(v) {
    return v ? v : '<span class="empty">Chưa cập nhật</span>';
}

// ── VIEW TABS ──
function switchViewTab(tab, btn) {
    document.querySelectorAll('#viewModal .tab-btn').forEach(b=>b.classList.remove('active'));
    document.querySelectorAll('#viewModal .tab-pane').forEach(p=>p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('vt_'+tab).classList.add('active');
}
function switchEditTab(tab, btn) {
    document.querySelectorAll('#editModal .tab-btn').forEach(b=>b.classList.remove('active'));
    document.querySelectorAll('#editModal .tab-pane').forEach(p=>p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('et_'+tab).classList.add('active');
}

// ── OPEN VIEW ──
function openView(id) {
    const k = ktvData[id]; if (!k) return;
    _currentId = id;
    const initial = (k.tenKTV||'K').charAt(0).toUpperCase();
    document.getElementById('v_avatar').innerText = initial;
    document.getElementById('v_name').innerText = k.tenKTV || '—';
    document.getElementById('v_email_head').innerText = k.email || '—';

    // Badges
    const bd = document.getElementById('v_badges');
    bd.innerHTML = '';
    if (k.chua_cap_nhat == 1) {
        bd.innerHTML += '<span style="background:rgba(239,68,68,.2);color:#fca5a5;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700"><i class="fas fa-clock me-1"></i>Chưa đầy đủ hồ sơ</span>';
    } else {
        bd.innerHTML += '<span style="background:rgba(34,197,94,.2);color:#86efac;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700"><i class="fas fa-check me-1"></i>Hồ sơ đầy đủ</span>';
    }

    // Info
    document.getElementById('v_ten').innerHTML = fmtVal(k.tenKTV);
    document.getElementById('v_ns').innerHTML  = k.ngaySinh ? fmtDate(k.ngaySinh) : '<span class="empty">Chưa cập nhật</span>';
    document.getElementById('v_sdt').innerHTML = fmtVal(k.soDienThoai);
    document.getElementById('v_gmail').innerHTML = fmtVal(k.email);
    document.getElementById('v_dc').innerHTML  = fmtVal(k.diaChi);
    document.getElementById('v_cm').innerHTML  = fmtVal(k.chuyenMon);
    document.getElementById('v_ghi').innerHTML = fmtVal(k.ghiChu);
    document.getElementById('v_ngaytk').innerHTML = k.ngayTaoTK ? fmtDate(k.ngayTaoTK) : '<span class="empty">—</span>';

    // Account tab
    const uInfo = k.email ? userMap[k.email] : null;
    const noAcc = document.getElementById('v_no_account');
    const accC  = document.getElementById('v_account_content');
    if (uInfo) {
        noAcc.classList.add('d-none');
        document.getElementById('v_username').innerText = uInfo.username || '—';
        document.getElementById('v_acc_status').innerHTML = uInfo.status==1
            ? '<span style="color:#4ade80"><i class="fas fa-circle-check me-1"></i>Đang hoạt động</span>'
            : '<span style="color:#f87171"><i class="fas fa-ban me-1"></i>Bị khóa</span>';
        const cd = uInfo.created_at ? uInfo.created_at.substring(0,10) : '';
        document.getElementById('v_acc_created').innerHTML = cd ? fmtDate(cd) : '—';
    } else {
        noAcc.classList.remove('d-none');
        document.getElementById('v_username').innerText = '—';
        document.getElementById('v_acc_status').innerText = '—';
        document.getElementById('v_acc_created').innerText = '—';
    }

    // Reset tabs
    document.querySelectorAll('#viewModal .tab-btn').forEach((b,i)=>b.classList.toggle('active',i===0));
    document.getElementById('vt_personal').classList.add('active');
    document.getElementById('vt_account').classList.remove('active');

    new bootstrap.Modal(document.getElementById('viewModal')).show();
}

// ── OPEN EDIT ──
function openEdit(id) {
    const k = ktvData[id]; if (!k) return;
    _currentId = id;

    // Reset lỗi
    document.querySelectorAll('.field-err').forEach(e=>e.classList.remove('show'));
    document.querySelectorAll('.form-ctrl').forEach(e=>e.classList.remove('is-invalid','is-valid'));

    document.getElementById('f_id').value    = k.maKTV;
    document.getElementById('f_ten').value   = k.tenKTV || '';
    document.getElementById('f_ns').value    = k.ngaySinh ? k.ngaySinh.substring(0,10) : '';
    document.getElementById('f_sdt').value   = k.soDienThoai || '';
    document.getElementById('f_email').value = k.email || '';
    document.getElementById('f_dc').value    = k.diaChi || '';
    document.getElementById('f_cm').value    = k.chuyenMon || '';
    document.getElementById('f_ghi').value   = k.ghiChu || '';
    document.getElementById('f_ntk').value   = k.ngayTaoTK ? k.ngayTaoTK.substring(0,10) : '';
    document.getElementById('f_ntk').max     = TODAY;
    document.getElementById('f_ns').max      = TODAY;
    document.getElementById('modal_subtitle').innerText = 'Đang cập nhật: '+(k.tenKTV||'#'+id);

    // Account info in edit modal
    const uInfo = k.email ? userMap[k.email] : null;
    const eAcc = document.getElementById('edit_acc_info');
    if (uInfo) {
        document.getElementById('ea_username').innerText = uInfo.username || '—';
        document.getElementById('ea_email').innerText    = uInfo.email || '—';
        document.getElementById('ea_status').innerHTML   = uInfo.status==1
            ? '<span style="color:#4ade80"><i class="fas fa-circle-check me-1"></i>Hoạt động</span>'
            : '<span style="color:#f87171"><i class="fas fa-ban me-1"></i>Bị khóa</span>';
        eAcc.style.display = '';
    } else {
        eAcc.style.display = 'none';
    }

    // Reset tabs về personal
    document.querySelectorAll('#editModal .tab-btn').forEach((b,i)=>b.classList.toggle('active',i===0));
    document.getElementById('et_personal').classList.add('active');
    document.getElementById('et_extra').classList.remove('active');

    new bootstrap.Modal(document.getElementById('editModal')).show();
}

function switchToEdit() {
    bootstrap.Modal.getInstance(document.getElementById('viewModal')).hide();
    setTimeout(()=>{ if(_currentId) openEdit(_currentId); }, 300);
}

// ── VALIDATION ──
function validateField(id, errId, condition) {
    const el = document.getElementById(id);
    const er = document.getElementById(errId);
    if (condition) { el.classList.add('is-invalid'); el.classList.remove('is-valid'); er.classList.add('show'); return false; }
    else { el.classList.remove('is-invalid'); el.classList.add('is-valid'); er.classList.remove('show'); return true; }
}

document.getElementById('f_ten').addEventListener('input', function(){
    validateField('f_ten','err_ten', this.value.trim()==='');
});
document.getElementById('f_ns').addEventListener('change', function(){
    validateField('f_ns','err_ns', this.value && this.value > TODAY);
});
document.getElementById('f_sdt').addEventListener('input', function(){
    this.value = this.value.replace(/\D/g,'').substring(0,10);
    validateField('f_sdt','err_sdt', !/^\d{10}$/.test(this.value));
});
document.getElementById('f_email').addEventListener('input', function(){
    validateField('f_email','err_email', !this.value.includes('@') || !this.value.includes('.'));
});
document.getElementById('f_dc').addEventListener('input', function(){
    validateField('f_dc','err_dc', this.value.trim()==='');
});
document.getElementById('f_ntk').addEventListener('change', function(){
    validateField('f_ntk','err_ntk', this.value && this.value > TODAY);
});

document.getElementById('editForm').addEventListener('submit', function(e) {
    let ok = true;
    ok = validateField('f_ten',  'err_ten',   document.getElementById('f_ten').value.trim()==='') && ok;
    ok = validateField('f_ns',   'err_ns',    !document.getElementById('f_ns').value || document.getElementById('f_ns').value > TODAY) && ok;
    ok = validateField('f_sdt',  'err_sdt',   !/^\d{10}$/.test(document.getElementById('f_sdt').value)) && ok;
    const em = document.getElementById('f_email').value;
    ok = validateField('f_email','err_email', !em.includes('@') || !em.includes('.')) && ok;
    ok = validateField('f_dc',   'err_dc',    document.getElementById('f_dc').value.trim()==='') && ok;
    const ntk = document.getElementById('f_ntk').value;
    ok = validateField('f_ntk',  'err_ntk',   ntk && ntk > TODAY) && ok;
    if (!ok) {
        e.preventDefault();
        // Switch về tab có lỗi
        const personalFields = ['f_ten','f_ns','f_sdt','f_email','f_dc'];
        const hasPersonalErr = personalFields.some(f=>document.getElementById(f).classList.contains('is-invalid'));
        if (hasPersonalErr) {
            document.querySelectorAll('#editModal .tab-btn').forEach((b,i)=>b.classList.toggle('active',i===0));
            document.getElementById('et_personal').classList.add('active');
            document.getElementById('et_extra').classList.remove('active');
        }
    }
});

// ── DELETE ──
function confirmDel(id, name) {
    Swal.fire({
        title: 'Xóa kỹ thuật viên?',
        html: `<p class="mb-1">Bạn muốn xóa <strong>${name}</strong> khỏi danh sách?</p><small class="text-muted">Tài khoản đăng nhập vẫn được giữ nguyên.</small>`,
        icon: 'warning', showCancelButton: true,
        confirmButtonColor: '#ef4444', cancelButtonColor: '#64748b',
        confirmButtonText: '<i class="fas fa-trash me-1"></i>Xóa',
        cancelButtonText: 'Hủy', customClass:{popup:'rounded-4'}
    }).then(r=>{ if(r.isConfirmed) window.location.href=`quan_ly_ky_thuat_vien.php?del=${id}`; });
}
function confirmDelCurrent() {
    if (!_currentId) return;
    const k = ktvData[_currentId];
    bootstrap.Modal.getInstance(document.getElementById('viewModal')).hide();
    setTimeout(()=>confirmDel(_currentId, k?.tenKTV||'#'+_currentId), 300);
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>