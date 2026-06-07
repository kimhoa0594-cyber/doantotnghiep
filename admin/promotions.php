<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php"); exit();
}

$msg = ""; $msg_type = "info";

// --- BẬT/TẮT TRẠNG THÁI NHANH ---
if (isset($_GET['toggle_status']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $current_status = (int)$_GET['toggle_status'];
    $new_status = ($current_status == 1) ? 0 : 1;
    $conn->query("UPDATE promotions SET status = $new_status WHERE id = $id");
    header("Location: promotions.php"); exit();
}

// --- XỬ LÝ LƯU & CẬP NHẬT ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    function uploadImage($file) {
        if (isset($file) && $file['error'] === 0) {
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = time() . '_' . uniqid() . '.' . $ext;
            $target = "../uploads/promotions/" . $filename;
            if (!is_dir('../uploads/promotions/')) mkdir('../uploads/promotions/', 0777, true);
            if (move_uploaded_file($file['tmp_name'], $target)) return $filename;
        }
        return null;
    }

    if (isset($_POST['add_promo'])) {
        $p_name = $conn->real_escape_string($_POST['p_name'] ?? '');
        $p_code = strtoupper($conn->real_escape_string($_POST['p_code'] ?? ''));
        $p_desc = $conn->real_escape_string($_POST['p_desc'] ?? '');
        $p_address = $conn->real_escape_string($_POST['p_address'] ?? '');
        $p_type = $_POST['p_type'] ?? 'percent';
        $p_value = (float)($_POST['p_value'] ?? 0);
        $p_min_order = (float)($_POST['p_min_order'] ?? 0);
        $p_max_disc = (float)($_POST['p_max_disc'] ?? 0);
        $p_limit = (int)($_POST['p_limit'] ?? 0);
        $p_target = $_POST['p_target'] ?? 'all';
        $p_start = $_POST['p_start'] ?? date('Y-m-d');
        $p_end = $_POST['p_end'] ?? date('Y-m-d');
        $image = uploadImage($_FILES['p_image'] ?? null);

        $check = $conn->query("SELECT id FROM promotions WHERE code = '$p_code'");
        if ($check && $check->num_rows > 0) {
            $msg = "Lỗi: Mã khuyến mãi này đã tồn tại trong hệ thống!"; $msg_type = "danger";
        } else {
            $sql = "INSERT INTO promotions (name, code, description, office_address, image, type, value, min_order_value, max_discount, usage_limit, target_user, start_date, end_date, status) 
                    VALUES ('$p_name', '$p_code', '$p_desc', '$p_address', '$image', '$p_type', $p_value, $p_min_order, $p_max_disc, $p_limit, '$p_target', '$p_start', '$p_end', 1)";
            if ($conn->query($sql)) { $msg = "✓ Khuyến mãi mới đã được phát hành thành công!"; $msg_type = "success"; }
        }
    }

    if (isset($_POST['edit_promo'])) {
        $id = (int)$_POST['p_id'];
        $p_name = $conn->real_escape_string($_POST['p_name'] ?? '');
        $p_desc = $conn->real_escape_string($_POST['p_desc'] ?? '');
        $p_address = $conn->real_escape_string($_POST['p_address'] ?? '');
        $p_type = $_POST['p_type'] ?? 'percent';
        $p_value = (float)($_POST['p_value'] ?? 0);
        $p_min_order = (float)($_POST['p_min_order'] ?? 0);
        $p_max_disc = (float)($_POST['p_max_disc'] ?? 0);
        $p_limit = (int)($_POST['p_limit'] ?? 0);
        $p_start = $_POST['p_start'] ?? date('Y-m-d');
        $p_end = $_POST['p_end'] ?? date('Y-m-d');
        $p_status = (int)($_POST['p_status'] ?? 1);

        $update_img = "";
        $new_img = uploadImage($_FILES['p_image'] ?? null);
        if ($new_img) { $update_img = ", image='$new_img'"; }

        $sql = "UPDATE promotions SET name='$p_name', description='$p_desc', office_address='$p_address', type='$p_type', value=$p_value, min_order_value=$p_min_order, max_discount=$p_max_disc, usage_limit=$p_limit, start_date='$p_start', end_date='$p_end', status=$p_status $update_img WHERE id=$id";
        if ($conn->query($sql)) { $msg = "✓ Đã cập nhật khuyến mãi thành công!"; $msg_type = "success"; }
    }
}

if (isset($_GET['del'])) {
    $id = (int)$_GET['del'];
    $conn->query("DELETE FROM promotions WHERE id = $id");
    header("Location: promotions.php?status=deleted"); exit();
}

// Thống kê
$total_all    = $conn->query("SELECT COUNT(*) as c FROM promotions")->fetch_assoc()['c'] ?? 0;
$total_active = $conn->query("SELECT COUNT(*) as c FROM promotions WHERE status = 1 AND end_date >= CURDATE()")->fetch_assoc()['c'] ?? 0;
$total_exp    = $conn->query("SELECT COUNT(*) as c FROM promotions WHERE status = 0 OR end_date < CURDATE()")->fetch_assoc()['c'] ?? 0;
$total_used   = $conn->query("SELECT SUM(used_count) as total FROM promotions")->fetch_assoc()['total'] ?? 0;
$total_limit  = $conn->query("SELECT SUM(usage_limit) as total FROM promotions WHERE status=1")->fetch_assoc()['total'] ?? 1;
$usage_rate   = $total_limit > 0 ? round(($total_used / $total_limit) * 100) : 0;

// Lọc & Tìm kiếm
$search = $conn->real_escape_string($_GET['search'] ?? '');
$filter = $_GET['filter'] ?? 'all';
$sort   = $_GET['sort'] ?? 'id_desc';

$where = "WHERE (name LIKE '%$search%' OR code LIKE '%$search%')";
if ($filter == 'active')  $where .= " AND status = 1 AND end_date >= CURDATE()";
if ($filter == 'expired') $where .= " AND (status = 0 OR end_date < CURDATE())";
if ($filter == 'soon')    $where .= " AND status = 1 AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)";

$order_by = match($sort) {
    'date_asc'   => "end_date ASC",
    'value_desc' => "value DESC",
    'used_desc'  => "used_count DESC",
    default      => "id DESC"
};
$promos = $conn->query("SELECT * FROM promotions $where ORDER BY $order_by");
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Quản lý Khuyến mãi — QA Tech Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root {
    --green-50:  #f0fdf4;
    --green-100: #dcfce7;
    --green-200: #bbf7d0;
    --green-400: #4ade80;
    --green-500: #22c55e;
    --green-600: #16a34a;
    --green-700: #15803d;
    --green-800: #166534;
    --green-900: #14532d;
    --gray-50:  #f8fafc;
    --gray-100: #f1f5f9;
    --gray-200: #e2e8f0;
    --gray-400: #94a3b8;
    --gray-600: #475569;
    --gray-800: #1e293b;
    --gray-900: #0f172a;
    --sidebar-w: 268px;
    --radius-sm: 8px;
    --radius-md: 12px;
    --radius-lg: 18px;
    --radius-xl: 24px;
    --shadow-sm: 0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
    --shadow-md: 0 4px 16px rgba(0,0,0,.07), 0 2px 6px rgba(0,0,0,.04);
    --shadow-lg: 0 12px 32px rgba(0,0,0,.10), 0 4px 10px rgba(0,0,0,.05);
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Be Vietnam Pro', sans-serif; background: #f0f4f0; color: var(--gray-800); min-height: 100vh; }

/* ─── SIDEBAR ─── */
.sidebar {
    position: fixed; top: 0; left: 0; width: var(--sidebar-w); height: 100vh;
    background: var(--gray-900); display: flex; flex-direction: column;
    padding: 0; z-index: 200; overflow: hidden;
}
.sidebar-brand {
    padding: 28px 24px 20px;
    border-bottom: 1px solid rgba(255,255,255,.06);
    flex-shrink: 0;
}
.sidebar-brand .logo-icon {
    width: 38px; height: 38px; background: var(--green-600); border-radius: 10px;
    display: inline-flex; align-items: center; justify-content: center;
    color: #fff; font-size: 17px; margin-bottom: 10px;
}
.sidebar-brand h4 { color: #fff; font-size: 15px; font-weight: 700; letter-spacing: .3px; }
.sidebar-brand p  { color: var(--gray-400); font-size: 11.5px; margin-top: 2px; }
.sidebar-nav { flex: 1; overflow-y: auto; padding: 14px 14px; }
.nav-section { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--gray-400); padding: 0 10px; margin: 18px 0 8px; }
.nav-link {
    display: flex; align-items: center; gap: 11px; padding: 10px 12px;
    border-radius: var(--radius-sm); color: var(--gray-400); font-size: 13.5px;
    font-weight: 500; text-decoration: none; transition: .18s; margin-bottom: 3px;
}
.nav-link i { width: 18px; font-size: 14px; text-align: center; flex-shrink: 0; }
.nav-link:hover { background: rgba(255,255,255,.06); color: #fff; }
.nav-link.active { background: var(--green-700); color: #fff; }
.sidebar-footer {
    padding: 16px 14px; border-top: 1px solid rgba(255,255,255,.06); flex-shrink: 0;
}

/* ─── MAIN ─── */
.main { margin-left: var(--sidebar-w); min-height: 100vh; }
.topbar {
    background: #fff; border-bottom: 1px solid var(--gray-200);
    padding: 0 36px; height: 64px; display: flex; align-items: center;
    justify-content: space-between; position: sticky; top: 0; z-index: 100;
    box-shadow: var(--shadow-sm);
}
.topbar-search {
    display: flex; align-items: center; gap: 10px;
    background: var(--gray-50); border: 1px solid var(--gray-200);
    border-radius: var(--radius-sm); padding: 7px 14px; min-width: 260px;
}
.topbar-search input { border: none; background: transparent; outline: none; font-size: 13.5px; color: var(--gray-800); width: 100%; font-family: inherit; }
.topbar-search i { color: var(--gray-400); font-size: 13px; }
.topbar-right { display: flex; align-items: center; gap: 12px; }
.topbar-badge { position: relative; }
.topbar-badge .dot { position: absolute; top: 4px; right: 4px; width: 7px; height: 7px; background: var(--green-500); border-radius: 50%; border: 1px solid #fff; }
.topbar-avatar { width: 34px; height: 34px; border-radius: 50%; background: linear-gradient(135deg, var(--green-500), var(--green-700)); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 13px; font-weight: 700; cursor: pointer; }
.icon-btn { width: 34px; height: 34px; border-radius: var(--radius-sm); background: var(--gray-50); border: 1px solid var(--gray-200); display: flex; align-items: center; justify-content: center; color: var(--gray-600); font-size: 14px; cursor: pointer; transition: .15s; text-decoration: none; }
.icon-btn:hover { background: var(--gray-100); color: var(--gray-800); }

.page-content { padding: 32px 36px 48px; }

/* ─── PAGE HEADER ─── */
.page-header { display: flex; align-items: flex-end; justify-content: space-between; margin-bottom: 28px; }
.page-title { font-size: 22px; font-weight: 800; color: var(--gray-900); letter-spacing: -.3px; }
.page-sub { color: var(--gray-400); font-size: 13px; margin-top: 4px; }
.breadcrumb-wrap { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--gray-400); margin-bottom: 6px; }
.breadcrumb-wrap span { color: var(--green-600); font-weight: 600; }

/* ─── STAT CARDS ─── */
.stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
.stat-card {
    background: #fff; border-radius: var(--radius-lg); padding: 20px 22px;
    border: 1px solid var(--gray-200); box-shadow: var(--shadow-sm);
    transition: .2s; position: relative; overflow: hidden;
}
.stat-card::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
    background: var(--accent, var(--green-500));
}
.stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
.stat-label { font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: var(--gray-400); margin-bottom: 10px; display: flex; align-items: center; gap: 7px; }
.stat-label i { font-size: 13px; }
.stat-value { font-size: 30px; font-weight: 800; color: var(--gray-900); line-height: 1; }
.stat-meta { font-size: 12px; color: var(--gray-400); margin-top: 8px; }
.stat-badge { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 20px; }
.stat-badge.up { background: var(--green-100); color: var(--green-800); }
.progress-thin { height: 4px; border-radius: 2px; background: var(--gray-100); margin-top: 10px; overflow: hidden; }
.progress-thin-bar { height: 100%; border-radius: 2px; background: var(--green-500); transition: width .5s; }

/* ─── TOOLBAR ─── */
.toolbar {
    background: #fff; border-radius: var(--radius-md); border: 1px solid var(--gray-200);
    padding: 14px 18px; margin-bottom: 20px; display: flex; align-items: center;
    gap: 12px; flex-wrap: wrap; box-shadow: var(--shadow-sm);
}
.filter-pills { display: flex; gap: 6px; flex-wrap: wrap; flex: 1; }
.filter-pill {
    padding: 6px 16px; border-radius: 20px; font-size: 12.5px; font-weight: 600;
    color: var(--gray-600); background: var(--gray-50); border: 1px solid var(--gray-200);
    text-decoration: none; transition: .15s; display: flex; align-items: center; gap: 6px;
}
.filter-pill:hover { background: var(--gray-100); color: var(--gray-800); }
.filter-pill.active { background: var(--green-700); color: #fff; border-color: var(--green-700); }
.filter-pill .pill-count { background: rgba(255,255,255,.25); border-radius: 10px; padding: 0px 6px; font-size: 11px; }
.filter-pill:not(.active) .pill-count { background: var(--gray-200); color: var(--gray-600); }
.toolbar-divider { width: 1px; height: 24px; background: var(--gray-200); flex-shrink: 0; }
.sort-select {
    border: 1px solid var(--gray-200); border-radius: var(--radius-sm); padding: 7px 12px;
    font-size: 13px; color: var(--gray-700); background: var(--gray-50); outline: none; cursor: pointer;
    font-family: inherit; font-weight: 500;
}
.search-wrap {
    display: flex; align-items: center; gap: 8px;
    background: var(--gray-50); border: 1px solid var(--gray-200);
    border-radius: var(--radius-sm); padding: 7px 12px; min-width: 220px;
}
.search-wrap input { border: none; background: transparent; outline: none; font-size: 13px; color: var(--gray-800); width: 100%; font-family: inherit; }
.search-wrap i { color: var(--gray-400); font-size: 12px; }

/* ─── BTN PRIMARY ─── */
.btn-primary-green {
    background: var(--green-600); color: #fff; border: none; border-radius: var(--radius-sm);
    padding: 9px 20px; font-size: 13.5px; font-weight: 700; font-family: inherit;
    cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: .15s;
    white-space: nowrap;
}
.btn-primary-green:hover { background: var(--green-700); }
.btn-ghost {
    background: transparent; color: var(--gray-600); border: 1px solid var(--gray-200);
    border-radius: var(--radius-sm); padding: 9px 18px; font-size: 13px; font-weight: 600;
    font-family: inherit; cursor: pointer; transition: .15s;
}
.btn-ghost:hover { background: var(--gray-50); color: var(--gray-800); }
.btn-danger-outline {
    background: transparent; color: #dc2626; border: 1px solid #fecaca;
    border-radius: var(--radius-sm); padding: 9px 18px; font-size: 13px; font-weight: 600;
    font-family: inherit; cursor: pointer; transition: .15s;
}
.btn-danger-outline:hover { background: #fef2f2; }

/* ─── ALERT ─── */
.alert-custom {
    padding: 14px 18px; border-radius: var(--radius-md); margin-bottom: 20px;
    font-size: 13.5px; font-weight: 600; display: flex; align-items: center; gap: 10px;
    border-left: 4px solid;
}
.alert-success { background: var(--green-50); color: var(--green-800); border-color: var(--green-500); }
.alert-danger   { background: #fef2f2; color: #991b1b; border-color: #f87171; }
.alert-info     { background: #eff6ff; color: #1e40af; border-color: #60a5fa; }

/* ─── PROMO CARDS GRID ─── */
.promo-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 18px; }

.promo-card {
    background: #fff; border-radius: var(--radius-xl); border: 1px solid var(--gray-200);
    box-shadow: var(--shadow-sm); overflow: hidden; display: flex; flex-direction: column;
    transition: .22s; position: relative;
}
.promo-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-lg); border-color: var(--green-200); }

.promo-card.is-expired { opacity: .72; }
.promo-card.is-expired:hover { transform: none; box-shadow: var(--shadow-sm); }

/* Banner area */
.promo-banner {
    position: relative; height: 130px; overflow: hidden;
    background: linear-gradient(135deg, var(--green-800) 0%, var(--green-600) 60%, var(--green-400) 100%);
}
.promo-banner img { width: 100%; height: 100%; object-fit: cover; }
.promo-banner-overlay {
    position: absolute; inset: 0;
    background: linear-gradient(to bottom, rgba(0,0,0,.1) 0%, rgba(0,0,0,.55) 100%);
    display: flex; align-items: flex-end; padding: 14px 16px;
}
.promo-banner-value {
    color: #fff; font-size: 28px; font-weight: 800; line-height: 1; letter-spacing: -1px;
    text-shadow: 0 2px 8px rgba(0,0,0,.3);
}
.promo-banner-type { color: rgba(255,255,255,.7); font-size: 11px; font-weight: 600; letter-spacing: .5px; }

/* Status badge */
.status-badge {
    position: absolute; top: 10px; right: 10px;
    font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px;
    padding: 3px 10px; border-radius: 20px;
}
.badge-active  { background: var(--green-500); color: #fff; }
.badge-paused  { background: var(--gray-400); color: #fff; }
.badge-expired { background: var(--gray-200); color: var(--gray-600); }
.badge-soon    { background: #f59e0b; color: #fff; animation: pulse-badge 1.5s infinite; }
@keyframes pulse-badge { 0%,100%{opacity:1} 50%{opacity:.7} }

/* Card body */
.promo-body { padding: 16px 18px; flex: 1; display: flex; flex-direction: column; gap: 12px; }
.promo-name { font-size: 14px; font-weight: 700; color: var(--gray-900); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.promo-code-wrap {
    display: inline-flex; align-items: center; gap: 8px;
    border: 1.5px dashed var(--green-400); border-radius: var(--radius-sm);
    padding: 6px 12px; background: var(--green-50); cursor: pointer;
    transition: .15s; width: 100%; justify-content: center;
}
.promo-code-wrap:hover { background: var(--green-100); border-color: var(--green-600); }
.promo-code-wrap .code-text { font-size: 13px; font-weight: 800; color: var(--green-700); letter-spacing: 2px; }
.promo-code-wrap i { color: var(--green-500); font-size: 12px; }

.promo-meta { display: flex; flex-direction: column; gap: 6px; }
.promo-meta-row { display: flex; align-items: center; justify-content: space-between; font-size: 12px; }
.promo-meta-label { color: var(--gray-400); display: flex; align-items: center; gap: 6px; }
.promo-meta-label i { width: 14px; font-size: 11px; text-align: center; }
.promo-meta-val { font-weight: 700; color: var(--gray-800); }

/* Usage bar */
.usage-bar-wrap { margin-top: 2px; }
.usage-bar-header { display: flex; justify-content: space-between; align-items: center; font-size: 11.5px; margin-bottom: 6px; }
.usage-bar-header .used-num { font-weight: 700; color: var(--gray-800); }
.usage-bar-header .limit-num { color: var(--gray-400); }
.usage-bar { height: 5px; background: var(--gray-100); border-radius: 3px; overflow: hidden; }
.usage-bar-fill { height: 100%; border-radius: 3px; transition: width .5s; }
.usage-bar-fill.low  { background: var(--green-500); }
.usage-bar-fill.mid  { background: #f59e0b; }
.usage-bar-fill.high { background: #ef4444; }

/* Card footer */
.promo-footer {
    border-top: 1px solid var(--gray-100); padding: 12px 18px;
    display: flex; align-items: center; gap: 8px;
}
.action-btn {
    flex: 1; padding: 7px 0; border-radius: var(--radius-sm); border: 1px solid var(--gray-200);
    background: var(--gray-50); color: var(--gray-600); font-size: 12px; font-weight: 600;
    font-family: inherit; cursor: pointer; transition: .15s; display: flex; align-items: center;
    justify-content: center; gap: 6px; text-decoration: none;
}
.action-btn:hover { background: var(--gray-100); color: var(--gray-900); }
.action-btn.edit:hover { background: #eff6ff; color: #1d4ed8; border-color: #bfdbfe; }
.action-btn.view:hover { background: var(--green-50); color: var(--green-700); border-color: var(--green-200); }
.action-btn.danger:hover { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }

/* Dropdown menu */
.promo-more { position: relative; }
.more-btn {
    width: 30px; height: 30px; border-radius: var(--radius-sm); background: var(--gray-50);
    border: 1px solid var(--gray-200); cursor: pointer; display: flex; align-items: center;
    justify-content: center; color: var(--gray-500); font-size: 13px; transition: .15s;
}
.more-btn:hover { background: var(--gray-100); }

/* Empty state */
.empty-state {
    text-align: center; padding: 64px 20px; color: var(--gray-400);
    background: #fff; border-radius: var(--radius-xl); border: 1px dashed var(--gray-200);
}
.empty-state .empty-icon { font-size: 48px; margin-bottom: 16px; opacity: .3; }
.empty-state h5 { font-size: 15px; font-weight: 700; color: var(--gray-600); margin-bottom: 6px; }
.empty-state p { font-size: 13px; }

/* ─── MODALS ─── */
.modal-content {
    border: none; border-radius: var(--radius-xl); box-shadow: var(--shadow-lg);
    font-family: 'Be Vietnam Pro', sans-serif;
}
.modal-header-custom {
    padding: 24px 28px 20px; border-bottom: 1px solid var(--gray-100);
    display: flex; align-items: center; justify-content: space-between;
}
.modal-title-custom { font-size: 16px; font-weight: 800; color: var(--gray-900); display: flex; align-items: center; gap: 10px; }
.modal-title-icon { width: 34px; height: 34px; border-radius: var(--radius-sm); background: var(--green-100); color: var(--green-700); display: flex; align-items: center; justify-content: center; font-size: 15px; }
.modal-close { width: 30px; height: 30px; border-radius: var(--radius-sm); background: var(--gray-100); border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; color: var(--gray-500); font-size: 14px; transition: .15s; }
.modal-close:hover { background: var(--gray-200); color: var(--gray-800); }
.modal-body-custom { padding: 24px 28px; }
.modal-footer-custom { padding: 16px 28px 24px; border-top: 1px solid var(--gray-100); display: flex; gap: 10px; justify-content: flex-end; }

/* Form styles */
.form-section { margin-bottom: 24px; }
.form-section-title { font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: var(--green-700); margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
.form-section-title::after { content: ''; flex: 1; height: 1px; background: var(--green-100); }
.form-label-custom { font-size: 12px; font-weight: 700; color: var(--gray-600); margin-bottom: 6px; display: block; }
.form-control-custom {
    width: 100%; padding: 9px 13px; border: 1px solid var(--gray-200); border-radius: var(--radius-sm);
    font-size: 13.5px; color: var(--gray-800); font-family: inherit; outline: none; transition: .15s;
    background: #fff;
}
.form-control-custom:focus { border-color: var(--green-500); box-shadow: 0 0 0 3px rgba(34,197,94,.1); }
.form-select-custom {
    appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2394a3b8' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 12px center;
    padding-right: 32px;
}
.img-preview-area {
    border: 2px dashed var(--gray-200); border-radius: var(--radius-md); height: 130px;
    display: flex; align-items: center; justify-content: center; overflow: hidden;
    background: var(--gray-50); margin-bottom: 10px; cursor: pointer; transition: .15s;
    position: relative;
}
.img-preview-area:hover { border-color: var(--green-400); background: var(--green-50); }
.img-preview-area img { width: 100%; height: 100%; object-fit: cover; }
.img-preview-placeholder { text-align: center; color: var(--gray-400); }
.img-preview-placeholder i { font-size: 24px; margin-bottom: 6px; display: block; }
.img-preview-placeholder span { font-size: 12px; }

/* Toggle switch */
.toggle-wrap { display: flex; align-items: center; gap: 10px; }
.toggle-switch { position: relative; display: inline-block; width: 42px; height: 22px; }
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-slider { position: absolute; cursor: pointer; inset: 0; background: var(--gray-200); border-radius: 22px; transition: .2s; }
.toggle-slider::before { content: ''; position: absolute; width: 16px; height: 16px; border-radius: 50%; background: #fff; left: 3px; top: 3px; transition: .2s; box-shadow: var(--shadow-sm); }
.toggle-switch input:checked + .toggle-slider { background: var(--green-500); }
.toggle-switch input:checked + .toggle-slider::before { transform: translateX(20px); }
.toggle-label { font-size: 13px; font-weight: 600; color: var(--gray-700); }

/* View modal detail rows */
.detail-row { display: flex; justify-content: space-between; align-items: center; padding: 10px 0; border-bottom: 1px solid var(--gray-100); font-size: 13.5px; }
.detail-row:last-child { border-bottom: none; }
.detail-key { color: var(--gray-500); font-weight: 500; display: flex; align-items: center; gap: 8px; }
.detail-key i { width: 16px; font-size: 13px; color: var(--green-600); }
.detail-val { font-weight: 700; color: var(--gray-800); }

/* Toast */
.toast-wrap { position: fixed; bottom: 24px; right: 24px; z-index: 9999; }
.toast-item {
    background: var(--gray-900); color: #fff; border-radius: var(--radius-md);
    padding: 12px 18px; font-size: 13px; font-weight: 600; display: flex; align-items: center;
    gap: 10px; box-shadow: var(--shadow-lg); min-width: 240px;
    animation: slideUp .25s ease; margin-top: 10px;
}
@keyframes slideUp { from{transform:translateY(20px);opacity:0} to{transform:translateY(0);opacity:1} }
.toast-item i { color: var(--green-400); font-size: 15px; }

/* View modal banner */
.view-banner {
    height: 200px; position: relative; background-size: cover; background-position: center;
    background-color: linear-gradient(135deg, var(--green-800), var(--green-500));
    border-radius: var(--radius-xl) var(--radius-xl) 0 0; overflow: hidden;
}
.view-banner-overlay {
    position: absolute; inset: 0;
    background: linear-gradient(to bottom, transparent 20%, rgba(0,0,0,.7));
    display: flex; align-items: flex-end; padding: 24px;
}
.view-banner-content h2 { color: #fff; font-size: 22px; font-weight: 800; margin-bottom: 4px; }
.view-banner-content .view-code { color: rgba(255,255,255,.8); font-size: 13px; font-weight: 600; letter-spacing: 2px; }

/* Responsive */
@media (max-width: 1024px) {
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 768px) {
    .sidebar { transform: translateX(-100%); }
    .main { margin-left: 0; }
    .page-content { padding: 20px 16px 40px; }
    .topbar { padding: 0 16px; }
}
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
    <div class="sidebar-brand">
        <div class="logo-icon"><i class="fas fa-leaf"></i></div>
        <h4>QA Tech Admin</h4>
        <p>Quản lý hệ thống</p>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-section">Tổng quan</div>
        <a class="nav-link" href="index.php"><i class="fas fa-chart-pie"></i> Dashboard</a>
        <a class="nav-link" href="orders.php"><i class="fas fa-shopping-bag"></i> Đơn hàng</a>
        <a class="nav-link" href="products.php"><i class="fas fa-box"></i> Sản phẩm</a>
        <div class="nav-section">Marketing</div>
        <a class="nav-link active" href="promotions.php"><i class="fas fa-tag"></i> Khuyến mãi</a>
        <a class="nav-link" href="banners.php"><i class="fas fa-images"></i> Banner</a>
        <div class="nav-section">Hệ thống</div>
        <a class="nav-link" href="users.php"><i class="fas fa-users"></i> Người dùng</a>
        <a class="nav-link" href="settings.php"><i class="fas fa-cog"></i> Cài đặt</a>
    </nav>
    <div class="sidebar-footer">
        <a class="nav-link" href="../logout.php" style="color:#ef4444;">
            <i class="fas fa-sign-out-alt"></i> Đăng xuất
        </a>
    </div>
</aside>

<!-- MAIN -->
<div class="main">
    <!-- Topbar -->
    <header class="topbar">
        <form class="topbar-search" method="GET">
            <i class="fas fa-search"></i>
            <input type="text" name="search" placeholder="Tìm khuyến mãi, mã..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
        </form>
        <div class="topbar-right">
            <div class="topbar-badge">
                <a href="#" class="icon-btn"><i class="fas fa-bell"></i></a>
                <span class="dot"></span>
            </div>
            <a href="settings.php" class="icon-btn"><i class="fas fa-cog"></i></a>
            <div class="topbar-avatar" title="Admin">A</div>
        </div>
    </header>

    <div class="page-content">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <div class="breadcrumb-wrap">
                    <i class="fas fa-home" style="font-size:11px;"></i>
                    <i class="fas fa-chevron-right" style="font-size:9px;"></i>
                    <span>Khuyến mãi</span>
                </div>
                <h1 class="page-title">Chiến dịch khuyến mãi</h1>
                <p class="page-sub">Quản lý và theo dõi tất cả mã giảm giá, ưu đãi của hệ thống</p>
            </div>
            <button class="btn-primary-green" onclick="openAddModal()">
                <i class="fas fa-plus"></i> Tạo khuyến mãi mới
            </button>
        </div>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card" style="--accent: var(--green-500);">
                <div class="stat-label"><i class="fas fa-tag" style="color:var(--green-600)"></i> Tổng mã</div>
                <div class="stat-value"><?= $total_all ?></div>
                <div class="stat-meta">Toàn bộ chiến dịch</div>
            </div>
            <div class="stat-card" style="--accent: #22c55e;">
                <div class="stat-label"><i class="fas fa-play-circle" style="color:#22c55e"></i> Đang chạy</div>
                <div class="stat-value"><?= $total_active ?></div>
                <div class="stat-meta">
                    <span class="stat-badge up"><i class="fas fa-arrow-up"></i> Hiệu lực</span>
                </div>
            </div>
            <div class="stat-card" style="--accent: #f59e0b;">
                <div class="stat-label"><i class="fas fa-fire" style="color:#f59e0b"></i> Lượt dùng</div>
                <div class="stat-value"><?= number_format($total_used) ?></div>
                <div class="stat-meta">Tổng lượt sử dụng</div>
                <div class="progress-thin"><div class="progress-thin-bar" style="width:<?= min($usage_rate, 100) ?>%;background:#f59e0b;"></div></div>
            </div>
            <div class="stat-card" style="--accent: #94a3b8;">
                <div class="stat-label"><i class="fas fa-clock" style="color:#94a3b8"></i> Hết hạn</div>
                <div class="stat-value"><?= $total_exp ?></div>
                <div class="stat-meta">Đã đóng hoặc tạm dừng</div>
            </div>
        </div>

        <!-- Alert -->
        <?php if($msg): ?>
        <div class="alert-custom alert-<?= $msg_type == 'danger' ? 'danger' : 'success' ?>">
            <i class="fas <?= $msg_type == 'danger' ? 'fa-exclamation-circle' : 'fa-check-circle' ?>"></i>
            <?= $msg ?>
        </div>
        <?php endif; ?>
        <?php if(isset($_GET['status']) && $_GET['status'] == 'deleted'): ?>
        <div class="alert-custom alert-success">
            <i class="fas fa-trash-alt"></i> Đã xóa khuyến mãi thành công.
        </div>
        <?php endif; ?>

        <!-- Toolbar -->
        <div class="toolbar">
            <div class="filter-pills">
                <a href="?filter=all" class="filter-pill <?= $filter=='all'?'active':'' ?>">
                    Tất cả <span class="pill-count"><?= $total_all ?></span>
                </a>
                <a href="?filter=active" class="filter-pill <?= $filter=='active'?'active':'' ?>">
                    <i class="fas fa-circle" style="font-size:7px;color:var(--green-400)"></i>
                    Đang chạy <span class="pill-count"><?= $total_active ?></span>
                </a>
                <a href="?filter=expired" class="filter-pill <?= $filter=='expired'?'active':'' ?>">
                    Đã đóng <span class="pill-count"><?= $total_exp ?></span>
                </a>
                <a href="?filter=soon" class="filter-pill <?= $filter=='soon'?'active':'' ?>">
                    <i class="fas fa-fire" style="font-size:10px;color:#f59e0b"></i>
                    Sắp hết
                </a>
            </div>
            <div class="toolbar-divider"></div>
            <form method="GET" style="display:flex;gap:8px;align-items:center;">
                <input type="hidden" name="filter" value="<?= $filter ?>">
                <select name="sort" class="sort-select" onchange="this.form.submit()">
                    <option value="id_desc"   <?= $sort=='id_desc'?'selected':'' ?>>Mới nhất</option>
                    <option value="date_asc"  <?= $sort=='date_asc'?'selected':'' ?>>Sắp hết hạn</option>
                    <option value="value_desc"<?= $sort=='value_desc'?'selected':'' ?>>Giá trị cao nhất</option>
                    <option value="used_desc" <?= $sort=='used_desc'?'selected':'' ?>>Dùng nhiều nhất</option>
                </select>
                <div class="search-wrap">
                    <i class="fas fa-search"></i>
                    <input type="text" name="search" placeholder="Tìm tên, mã..." value="<?= htmlspecialchars($search) ?>">
                </div>
                <button type="submit" class="btn-primary-green" style="padding:8px 14px;">
                    <i class="fas fa-filter"></i>
                </button>
            </form>
        </div>

        <!-- Promo Grid -->
        <div class="promo-grid">
        <?php if($promos && $promos->num_rows > 0): while($p = $promos->fetch_assoc()):
            $end_ts    = strtotime($p['end_date']);
            $is_expired = $end_ts < time() || $p['status'] == 0;
            $is_soon    = !$is_expired && ($end_ts - time() < 259200);
            $percent    = ($p['usage_limit'] > 0) ? ($p['used_count'] / $p['usage_limit']) * 100 : 0;
            $bar_class  = $percent >= 80 ? 'high' : ($percent >= 50 ? 'mid' : 'low');
            $val_fmt    = $p['type'] == 'percent' ? $p['value'].'%' : number_format($p['value']).'đ';
            $days_left  = ceil(($end_ts - time()) / 86400);
        ?>
        <div class="promo-card <?= $is_expired ? 'is-expired' : '' ?>">
            <!-- Banner -->
            <div class="promo-banner">
                <?php if(!empty($p['image'])): ?>
                    <img src="../uploads/promotions/<?= $p['image'] ?>" alt="banner">
                    <div class="promo-banner-overlay">
                        <div>
                            <div class="promo-banner-value"><?= $val_fmt ?></div>
                            <div class="promo-banner-type"><?= $p['type']=='percent' ? 'Giảm phần trăm' : 'Giảm cố định' ?></div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="promo-banner-overlay" style="background:none; align-items:center; padding:0 16px; justify-content:center; flex-direction:column; gap:4px;">
                        <div style="color:#fff;font-size:36px;font-weight:800;letter-spacing:-2px;"><?= $val_fmt ?></div>
                        <div style="color:rgba(255,255,255,.6);font-size:12px;font-weight:600;"><?= $p['type']=='percent' ? 'GIẢM PHẦN TRĂM' : 'GIẢM CỐ ĐỊNH' ?></div>
                    </div>
                <?php endif; ?>

                <!-- Status badge -->
                <span class="status-badge <?=
                    $p['status']==0 ? 'badge-paused' :
                    ($is_expired ? 'badge-expired' :
                    ($is_soon ? 'badge-soon' : 'badge-active'))
                ?>">
                    <?= $p['status']==0 ? 'Tạm dừng' : ($is_expired ? 'Hết hạn' : ($is_soon ? '🔥 Sắp hết' : 'Đang chạy')) ?>
                </span>
            </div>

            <!-- Body -->
            <div class="promo-body">
                <div class="promo-name" title="<?= htmlspecialchars($p['name']) ?>"><?= htmlspecialchars($p['name']) ?></div>

                <div class="promo-code-wrap" onclick="copyCode('<?= $p['code'] ?>')" title="Nhấn để sao chép">
                    <i class="fas fa-ticket-alt" style="color:var(--green-500);font-size:13px;"></i>
                    <span class="code-text"><?= $p['code'] ?></span>
                    <i class="far fa-copy"></i>
                </div>

                <div class="promo-meta">
                    <div class="promo-meta-row">
                        <span class="promo-meta-label"><i class="fas fa-shopping-cart"></i> Đơn tối thiểu</span>
                        <span class="promo-meta-val"><?= number_format($p['min_order_value']) ?>đ</span>
                    </div>
                    <?php if($p['max_discount'] > 0): ?>
                    <div class="promo-meta-row">
                        <span class="promo-meta-label"><i class="fas fa-hand-holding-usd"></i> Giảm tối đa</span>
                        <span class="promo-meta-val"><?= number_format($p['max_discount']) ?>đ</span>
                    </div>
                    <?php endif; ?>
                    <div class="promo-meta-row">
                        <span class="promo-meta-label"><i class="fas fa-calendar-alt"></i> Hết hạn</span>
                        <span class="promo-meta-val <?= $is_soon ? 'text-warning' : '' ?>" style="<?= $is_soon ? 'color:#d97706' : '' ?>">
                            <?= date('d/m/Y', $end_ts) ?>
                            <?php if(!$is_expired && $days_left > 0): ?>
                                <small style="color:var(--gray-400);font-weight:500;"> (còn <?= $days_left ?> ngày)</small>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>

                <!-- Usage bar -->
                <div class="usage-bar-wrap">
                    <div class="usage-bar-header">
                        <span>Lượt sử dụng</span>
                        <div>
                            <span class="used-num"><?= $p['used_count'] ?></span>
                            <span class="limit-num"> / <?= $p['usage_limit'] ?></span>
                        </div>
                    </div>
                    <div class="usage-bar">
                        <div class="usage-bar-fill <?= $bar_class ?>" style="width:<?= min($percent,100) ?>%"></div>
                    </div>
                </div>
            </div>

            <!-- Footer actions -->
            <div class="promo-footer">
                <button class="action-btn view" onclick='openView(<?= json_encode($p) ?>)'>
                    <i class="fas fa-eye"></i> Chi tiết
                </button>
                <button class="action-btn edit" onclick='openEdit(<?= json_encode($p) ?>)'>
                    <i class="fas fa-pen"></i> Sửa
                </button>
                <a class="action-btn <?= $p['status']==1 ? '' : '' ?>"
                   href="?toggle_status=<?= $p['status'] ?>&id=<?= $p['id'] ?>&filter=<?= $filter ?>"
                   title="<?= $p['status']==1 ? 'Tạm dừng' : 'Kích hoạt' ?>"
                   style="flex:0;width:36px;font-size:14px;">
                    <i class="fas <?= $p['status']==1 ? 'fa-pause' : 'fa-play' ?>"></i>
                </a>
                <a class="action-btn danger" href="?del=<?= $p['id'] ?>"
                   onclick="return confirm('Xóa khuyến mãi «<?= addslashes($p['name']) ?>»? Thao tác không thể hoàn tác!')"
                   style="flex:0;width:36px;font-size:14px;">
                    <i class="fas fa-trash-alt"></i>
                </a>
            </div>
        </div>
        <?php endwhile; else: ?>
        <div class="empty-state" style="grid-column:1/-1;">
            <div class="empty-icon"><i class="fas fa-tags"></i></div>
            <h5>Không tìm thấy khuyến mãi</h5>
            <p>Thử thay đổi bộ lọc hoặc tạo một chiến dịch mới</p>
        </div>
        <?php endif; ?>
        </div>
    </div><!-- /page-content -->
</div><!-- /main -->


<!-- ══════════ MODAL: THÊM MỚI ══════════ -->
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-xl modal-dialog-centered">
<form class="modal-content" method="POST" enctype="multipart/form-data">
    <div class="modal-header-custom">
        <div class="modal-title-custom">
            <div class="modal-title-icon"><i class="fas fa-plus"></i></div>
            Tạo khuyến mãi mới
        </div>
        <button type="button" class="modal-close" data-bs-dismiss="modal"><i class="fas fa-times"></i></button>
    </div>
    <div class="modal-body-custom">
        <div class="row g-4">
            <!-- Left: image + desc -->
            <div class="col-md-4">
                <div class="form-section">
                    <div class="form-section-title"><i class="fas fa-image"></i> Hình ảnh & mô tả</div>
                    <label class="form-label-custom">Ảnh banner</label>
                    <div class="img-preview-area" id="add_preview" onclick="document.getElementById('add_img_input').click()">
                        <div class="img-preview-placeholder">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <span>Nhấn để chọn ảnh banner</span>
                        </div>
                    </div>
                    <input type="file" name="p_image" id="add_img_input" style="display:none" accept="image/*"
                           onchange="previewImg(this,'add_preview')">

                    <label class="form-label-custom mt-3">Mô tả khuyến mãi</label>
                    <textarea name="p_desc" class="form-control-custom" rows="5"
                              placeholder="Điều khoản và điều kiện áp dụng..."></textarea>

                    <label class="form-label-custom mt-3">Địa chỉ / phạm vi áp dụng</label>
                    <input type="text" name="p_address" class="form-control-custom" placeholder="VD: Toàn quốc, 123 Nguyễn Trãi...">
                </div>
            </div>
            <!-- Right: config -->
            <div class="col-md-8">
                <div class="form-section">
                    <div class="form-section-title"><i class="fas fa-sliders-h"></i> Thông số chiến dịch</div>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label-custom">Tên khuyến mãi *</label>
                            <input type="text" name="p_name" class="form-control-custom" required placeholder="VD: Flash Sale Tháng 6">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-custom">Mã voucher *</label>
                            <input type="text" name="p_code" class="form-control-custom" required placeholder="VD: FLASH30"
                                   style="font-weight:800;letter-spacing:2px;text-transform:uppercase;"
                                   oninput="this.value=this.value.toUpperCase()">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-custom">Loại giảm</label>
                            <select name="p_type" class="form-control-custom form-select-custom">
                                <option value="percent">Phần trăm (%)</option>
                                <option value="fixed">Số tiền cố định (đ)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-custom">Giá trị *</label>
                            <input type="number" name="p_value" class="form-control-custom" required min="0" placeholder="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-custom">Giảm tối đa (đ)</label>
                            <input type="number" name="p_max_disc" class="form-control-custom" value="0" min="0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Đơn hàng tối thiểu (đ)</label>
                            <input type="number" name="p_min_order" class="form-control-custom" value="0" min="0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Giới hạn lượt dùng</label>
                            <input type="number" name="p_limit" class="form-control-custom" value="100" min="1">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Đối tượng áp dụng</label>
                            <select name="p_target" class="form-control-custom form-select-custom">
                                <option value="all">Tất cả khách hàng</option>
                                <option value="new">Khách mới</option>
                                <option value="vip">Thành viên VIP</option>
                            </select>
                        </div>
                        <div class="col-md-6"><!-- spacer --></div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Ngày bắt đầu</label>
                            <input type="date" name="p_start" class="form-control-custom" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Ngày kết thúc</label>
                            <input type="date" name="p_end" class="form-control-custom" value="<?= date('Y-m-d', strtotime('+1 month')) ?>">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="modal-footer-custom">
        <button type="button" class="btn-ghost" data-bs-dismiss="modal">Hủy bỏ</button>
        <button type="submit" name="add_promo" class="btn-primary-green">
            <i class="fas fa-paper-plane"></i> Phát hành khuyến mãi
        </button>
    </div>
</form>
</div>
</div>


<!-- ══════════ MODAL: CHỈNH SỬA ══════════ -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-xl modal-dialog-centered">
<form class="modal-content" method="POST" enctype="multipart/form-data">
    <input type="hidden" name="p_id" id="edit_id">
    <div class="modal-header-custom">
        <div class="modal-title-custom">
            <div class="modal-title-icon" style="background:#eff6ff;color:#1d4ed8;"><i class="fas fa-pen"></i></div>
            Chỉnh sửa khuyến mãi
        </div>
        <div style="display:flex;align-items:center;gap:14px;">
            <div class="toggle-wrap">
                <span class="toggle-label" style="font-size:12px;color:var(--gray-500);">Trạng thái:</span>
                <label class="toggle-switch">
                    <input type="checkbox" id="edit_status_toggle" onchange="document.getElementById('edit_status').value = this.checked ? 1 : 0">
                    <span class="toggle-slider"></span>
                </label>
                <select name="p_status" id="edit_status" style="display:none;"></select>
                <span id="edit_status_label" class="toggle-label">Đang chạy</span>
            </div>
            <button type="button" class="modal-close" data-bs-dismiss="modal"><i class="fas fa-times"></i></button>
        </div>
    </div>
    <div class="modal-body-custom">
        <div class="row g-4">
            <div class="col-md-4">
                <div class="form-section">
                    <div class="form-section-title"><i class="fas fa-image"></i> Hình ảnh & mô tả</div>
                    <div class="img-preview-area" id="edit_preview" onclick="document.getElementById('edit_img_input').click()"></div>
                    <input type="file" name="p_image" id="edit_img_input" style="display:none" accept="image/*"
                           onchange="previewImg(this,'edit_preview')">
                    <label class="form-label-custom mt-3">Mô tả</label>
                    <textarea name="p_desc" id="edit_desc" class="form-control-custom" rows="5"></textarea>
                    <label class="form-label-custom mt-3">Địa chỉ áp dụng</label>
                    <input type="text" name="p_address" id="edit_address" class="form-control-custom">
                </div>
            </div>
            <div class="col-md-8">
                <div class="form-section">
                    <div class="form-section-title"><i class="fas fa-sliders-h"></i> Cập nhật thông số</div>
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label-custom">Tên chiến dịch</label>
                            <input type="text" name="p_name" id="edit_name" class="form-control-custom" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-custom">Loại giảm</label>
                            <select name="p_type" id="edit_type" class="form-control-custom form-select-custom">
                                <option value="percent">Phần trăm (%)</option>
                                <option value="fixed">Số tiền cố định (đ)</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-custom">Giá trị</label>
                            <input type="number" name="p_value" id="edit_value" class="form-control-custom" min="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-custom">Giảm tối đa (đ)</label>
                            <input type="number" name="p_max_disc" id="edit_max_disc" class="form-control-custom" min="0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Đơn tối thiểu (đ)</label>
                            <input type="number" name="p_min_order" id="edit_min_order" class="form-control-custom" min="0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Giới hạn lượt dùng</label>
                            <input type="number" name="p_limit" id="edit_limit" class="form-control-custom" min="1">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Ngày bắt đầu</label>
                            <input type="date" name="p_start" id="edit_start" class="form-control-custom">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Ngày kết thúc</label>
                            <input type="date" name="p_end" id="edit_end" class="form-control-custom">
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="modal-footer-custom">
        <button type="button" class="btn-ghost" data-bs-dismiss="modal">Hủy bỏ</button>
        <button type="submit" name="edit_promo" class="btn-primary-green">
            <i class="fas fa-save"></i> Lưu thay đổi
        </button>
    </div>
</form>
</div>
</div>


<!-- ══════════ MODAL: XEM CHI TIẾT ══════════ -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
<div class="modal-dialog modal-lg modal-dialog-centered">
<div class="modal-content" style="overflow:hidden;">
    <div class="view-banner" id="v_banner">
        <div class="view-banner-overlay">
            <div class="view-banner-content">
                <h2 id="v_name"></h2>
                <div class="view-code" id="v_code_display"></div>
            </div>
        </div>
    </div>
    <div class="modal-body-custom">
        <div class="row g-4">
            <div class="col-md-7">
                <p style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--green-700);margin-bottom:10px;">
                    <i class="fas fa-align-left"></i> Mô tả khuyến mãi
                </p>
                <p id="v_desc" style="font-size:13.5px;color:var(--gray-600);white-space:pre-line;min-height:80px;line-height:1.7;"></p>
                <div style="background:var(--green-50);border-radius:var(--radius-sm);padding:10px 14px;display:flex;align-items:center;gap:8px;margin-top:12px;">
                    <i class="fas fa-map-marker-alt" style="color:var(--green-600);font-size:13px;"></i>
                    <span id="v_address" style="font-size:13px;color:var(--green-800);font-weight:600;"></span>
                </div>
            </div>
            <div class="col-md-5">
                <p style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--green-700);margin-bottom:10px;">
                    <i class="fas fa-info-circle"></i> Thông số ưu đãi
                </p>
                <div class="detail-row">
                    <span class="detail-key"><i class="fas fa-percent"></i> Mức giảm</span>
                    <span class="detail-val" id="v_value" style="color:var(--green-700);font-size:16px;"></span>
                </div>
                <div class="detail-row">
                    <span class="detail-key"><i class="fas fa-shopping-cart"></i> Đơn tối thiểu</span>
                    <span class="detail-val" id="v_min"></span>
                </div>
                <div class="detail-row">
                    <span class="detail-key"><i class="fas fa-users"></i> Đã sử dụng</span>
                    <span class="detail-val" id="v_used"></span>
                </div>
                <div class="detail-row">
                    <span class="detail-key"><i class="fas fa-calendar-times"></i> Hết hạn</span>
                    <span class="detail-val" id="v_end"></span>
                </div>
                <div class="detail-row">
                    <span class="detail-key"><i class="fas fa-users-cog"></i> Đối tượng</span>
                    <span class="detail-val" id="v_target"></span>
                </div>
            </div>
        </div>
        <div style="margin-top:20px;display:flex;gap:10px;">
            <button class="btn-ghost" style="flex:1;" data-bs-dismiss="modal">Đóng</button>
            <button class="btn-primary-green" id="v_copy_btn" style="flex:1.5;" onclick="">
                <i class="fas fa-copy"></i> Sao chép mã
            </button>
        </div>
    </div>
</div>
</div>
</div>


<!-- Toast -->
<div class="toast-wrap" id="toastWrap"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function showToast(msg, icon = 'fa-check-circle') {
    const wrap = document.getElementById('toastWrap');
    const el = document.createElement('div');
    el.className = 'toast-item';
    el.innerHTML = `<i class="fas ${icon}"></i> ${msg}`;
    wrap.appendChild(el);
    setTimeout(() => el.remove(), 3000);
}

function copyCode(code) {
    navigator.clipboard.writeText(code).then(() => {
        showToast(`Đã sao chép mã <b>${code}</b>`);
    });
}

function previewImg(input, containerId) {
    const c = document.getElementById(containerId);
    if (input.files && input.files[0]) {
        const r = new FileReader();
        r.onload = e => { c.innerHTML = `<img src="${e.target.result}">`; };
        r.readAsDataURL(input.files[0]);
    }
}

function openAddModal() {
    new bootstrap.Modal(document.getElementById('addModal')).show();
}

function openEdit(p) {
    document.getElementById('edit_id').value     = p.id;
    document.getElementById('edit_name').value   = p.name;
    document.getElementById('edit_desc').value   = p.description || '';
    document.getElementById('edit_address').value= p.office_address || '';
    document.getElementById('edit_type').value   = p.type;
    document.getElementById('edit_value').value  = p.value;
    document.getElementById('edit_min_order').value = p.min_order_value;
    document.getElementById('edit_max_disc').value  = p.max_discount;
    document.getElementById('edit_limit').value  = p.usage_limit;
    document.getElementById('edit_start').value  = p.start_date;
    document.getElementById('edit_end').value    = p.end_date;
    document.getElementById('edit_status').value = p.status;

    const toggle = document.getElementById('edit_status_toggle');
    toggle.checked = p.status == 1;
    document.getElementById('edit_status_label').textContent = p.status == 1 ? 'Đang chạy' : 'Tạm dừng';
    toggle.onchange = function() {
        document.getElementById('edit_status').value = this.checked ? 1 : 0;
        document.getElementById('edit_status_label').textContent = this.checked ? 'Đang chạy' : 'Tạm dừng';
    };

    const preview = document.getElementById('edit_preview');
    preview.innerHTML = p.image
        ? `<img src="../uploads/promotions/${p.image}">`
        : `<div class="img-preview-placeholder"><i class="fas fa-cloud-upload-alt"></i><span>Nhấn để thay ảnh</span></div>`;

    new bootstrap.Modal(document.getElementById('editModal')).show();
}

function openView(p) {
    document.getElementById('v_name').innerText = p.name;
    document.getElementById('v_code_display').innerText = '✦ ' + p.code + ' ✦';
    document.getElementById('v_desc').innerText = p.description || 'Chưa có mô tả chi tiết.';
    document.getElementById('v_address').innerText = p.office_address || 'Áp dụng toàn hệ thống';
    document.getElementById('v_value').innerText = p.type == 'percent'
        ? p.value + '%'
        : new Intl.NumberFormat('vi-VN').format(p.value) + 'đ';
    document.getElementById('v_min').innerText  = new Intl.NumberFormat('vi-VN').format(p.min_order_value) + 'đ';
    document.getElementById('v_used').innerText = p.used_count + ' / ' + p.usage_limit + ' lượt';
    const d = new Date(p.end_date);
    document.getElementById('v_end').innerText = d.toLocaleDateString('vi-VN');
    const targets = {all:'Tất cả khách hàng', new:'Khách hàng mới', vip:'Thành viên VIP'};
    document.getElementById('v_target').innerText = targets[p.target_user] || p.target_user || 'Tất cả';

    const banner = document.getElementById('v_banner');
    banner.style.backgroundImage = p.image
        ? `url('../uploads/promotions/${p.image}')`
        : `linear-gradient(135deg, var(--green-800), var(--green-500))`;
    banner.style.backgroundSize = 'cover';
    banner.style.backgroundPosition = 'center';

    document.getElementById('v_copy_btn').onclick = () => copyCode(p.code);

    new bootstrap.Modal(document.getElementById('viewModal')).show();
}
</script>
</body>
</html>