<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php"); exit();
}

$msg = ""; $msg_type = "info";

// --- XỬ LÝ BẬT/TẮT TRẠNG THÁI NHANH (MỚI) ---
if (isset($_GET['toggle_status']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $current_status = (int)$_GET['toggle_status'];
    $new_status = ($current_status == 1) ? 0 : 1;
    $conn->query("UPDATE promotions SET status = $new_status WHERE id = $id");
    header("Location: promotions.php"); exit();
}

// --- 1. XỬ LÝ LƯU & CẬP NHẬT (Giữ nguyên logic cũ) ---
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
            $msg = "Lỗi: Mã này đã tồn tại!"; $msg_type = "danger";
        } else {
            $sql = "INSERT INTO promotions (name, code, description, office_address, image, type, value, min_order_value, max_discount, usage_limit, target_user, start_date, end_date, status) 
                    VALUES ('$p_name', '$p_code', '$p_desc', '$p_address', '$image', '$p_type', $p_value, $p_min_order, $p_max_disc, $p_limit, '$p_target', '$p_start', '$p_end', 1)";
            if ($conn->query($sql)) { $msg = "Khuyến mãi mới đã được tạo thành công!"; $msg_type = "success"; }
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
        if ($conn->query($sql)) { $msg = "Đã cập nhật chi tiết khuyến mãi!"; $msg_type = "success"; }
    }
}

if (isset($_GET['del'])) {
    $id = (int)$_GET['del'];
    $conn->query("DELETE FROM promotions WHERE id = $id");
    header("Location: promotions.php?status=deleted"); exit();
}

// Thống kê nhanh
$total_active = $conn->query("SELECT id FROM promotions WHERE status = 1 AND end_date >= CURDATE()")->num_rows;
$total_used = $conn->query("SELECT SUM(used_count) as total FROM promotions")->fetch_assoc()['total'] ?? 0;

// Xử lý Lọc & Tìm kiếm
$search = $_GET['search'] ?? '';
$filter = $_GET['filter'] ?? 'all';
$sort = $_GET['sort'] ?? 'id_desc';

$where = "WHERE (name LIKE '%$search%' OR code LIKE '%$search%')";
if ($filter == 'active') $where .= " AND status = 1 AND end_date >= CURDATE()";
if ($filter == 'expired') $where .= " AND (status = 0 OR end_date < CURDATE())";

$order_by = ($sort == 'date_asc') ? "end_date ASC" : (($sort == 'value_desc') ? "value DESC" : "id DESC");
$promos = $conn->query("SELECT * FROM promotions $where ORDER BY $order_by");
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Quản lý Khuyến mãi - QA Tech Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;700&display=swap');
        :root { --primary: #f59e0b; --dark: #0f172a; --bg: #f8fafc; }
        body { background: var(--bg); font-family: 'Plus Jakarta Sans', sans-serif; color: #1e293b; }
        .sidebar { width: 260px; height: 100vh; position: fixed; background: var(--dark); color: white; padding: 20px; z-index: 100;}
        .sidebar .nav-link { color: #94a3b8; padding: 12px 20px; border-radius: 12px; margin-bottom: 5px; transition: 0.3s; text-decoration: none; display: block; }
        .sidebar .nav-link.active { background: var(--primary); color: white; }
        .main-content { margin-left: 260px; padding: 40px; }
        
        .stat-card { border: none; border-radius: 20px; transition: 0.3s; }
        .stat-card:hover { transform: translateY(-5px); }

        .voucher-card { background: white; border-radius: 20px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.05); overflow: hidden; height: 100%; transition: 0.3s; display: flex; flex-direction: column; position: relative; }
        .voucher-card:hover { transform: translateY(-5px); box-shadow: 0 15px 35px rgba(0,0,0,0.1); }
        .voucher-img { width: 100%; height: 140px; object-fit: cover; background: #e2e8f0; }
        .voucher-header { background: linear-gradient(135deg, #f59e0b, #fbbf24); padding: 15px; color: white; text-align: center; }
        .voucher-body { padding: 20px; flex-grow: 1; text-align: center; }
        .voucher-code { font-size: 1.1rem; font-weight: 800; border: 2px dashed #fbbf24; padding: 5px 12px; border-radius: 8px; color: #d97706; display: inline-flex; align-items: center; gap: 8px; background: #fffbeb; cursor: pointer; }
        
        .filter-tab { border-radius: 12px; padding: 8px 20px; font-weight: 600; color: #64748b; text-decoration: none; transition: 0.3s; }
        .filter-tab.active { background: var(--dark); color: white; }
        
        .progress { height: 6px; background: #f1f5f9; border-radius: 10px; margin: 15px 0; }
        .img-preview { width: 100%; height: 150px; border-radius: 15px; border: 2px dashed #cbd5e1; display: flex; align-items: center; justify-content: center; overflow: hidden; background: #f8fafc; margin-bottom: 10px; }
        .label-group { font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; margin-bottom: 5px; display: block; }
        
        /* Toast Notification */
        .toast-container { position: fixed; bottom: 20px; right: 20px; z-index: 1060; }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="mb-5 px-3"><h4 class="fw-bold mb-0 text-white">QA TECH <span class="text-success">ADMIN</span></h4></div>
    <nav>
        <a class="nav-link" href="index.php"><i class="fas fa-home me-2"></i> Tổng quan</a>
        
        <a class="nav-link active" href="promotions.php"><i class="fas fa-percentage me-2"></i> Khuyến mãi</a>
        <a class="nav-link text-danger mt-5" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i>Đăng xuất</a>
    </nav>
</div>

<div class="main-content">
    <div class="d-flex justify-content-between align-items-end mb-4">
        <div>
            <h2 class="fw-bold mb-1">Chiến dịch khuyến mãi</h2>
            <p class="text-secondary">Nâng cao hiệu quả bán hàng bằng các khuyến mãi và mã giảm giá.</p>
        </div>
        <button class="btn btn-warning text-white shadow-sm fw-bold px-4 py-2 rounded-3" data-bs-toggle="modal" data-bs-target="#addPromoModal">
            <i class="fas fa-plus me-2"></i> Tạo mã mới
        </button>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card stat-card shadow-sm p-3">
                <div class="d-flex align-items-center">
                    <div class="bg-primary bg-opacity-10 p-3 rounded-3 me-3"><i class="fas fa-ticket-alt text-primary"></i></div>
                    <div><h6 class="mb-0 text-muted small">Đang chạy</h6><h4 class="fw-bold mb-0"><?= $total_active ?></h4></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card shadow-sm p-3">
                <div class="d-flex align-items-center">
                    <div class="bg-success bg-opacity-10 p-3 rounded-3 me-3"><i class="fas fa-check-circle text-success"></i></div>
                    <div><h6 class="mb-0 text-muted small">Lượt sử dụng</h6><h4 class="fw-bold mb-0"><?= number_format($total_used) ?></h4></div>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white p-3 rounded-4 shadow-sm mb-4">
        <div class="row align-items-center">
            <div class="col-md-6 d-flex gap-2">
                <a href="?filter=all" class="filter-tab <?= $filter == 'all' ? 'active' : '' ?>">Tất cả</a>
                <a href="?filter=active" class="filter-tab <?= $filter == 'active' ? 'active' : '' ?>">Đang chạy</a>
                <a href="?filter=expired" class="filter-tab <?= $filter == 'expired' ? 'active' : '' ?>">Đã đóng</a>
            </div>
            <div class="col-md-6">
                <form class="d-flex gap-2">
                    <input type="hidden" name="filter" value="<?= $filter ?>">
                    <input type="text" name="search" class="form-control border-0 bg-light rounded-3 px-3" placeholder="Tìm tên hoặc mã..." value="<?= htmlspecialchars($search) ?>">
                    <button type="submit" class="btn btn-dark rounded-3"><i class="fas fa-search"></i></button>
                </form>
            </div>
        </div>
    </div>

    <?php if($msg): ?>
        <div class="alert alert-<?= $msg_type ?> rounded-4 border-0 shadow-sm mb-4"><?= $msg ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <?php if($promos && $promos->num_rows > 0): while($p = $promos->fetch_assoc()): 
            $end_ts = strtotime($p['end_date']);
            $is_expired = $end_ts < time() || $p['status'] == 0;
            $is_soon = !$is_expired && ($end_ts - time() < 259200); // Còn dưới 3 ngày
            $percent = ($p['usage_limit'] > 0) ? ($p['used_count'] / $p['usage_limit']) * 100 : 0;
        ?>
        <div class="col-md-4">
            <div class="voucher-card">
                <?php if($is_soon): ?>
                    <span class="badge bg-danger position-absolute top-0 start-0 m-3 shadow-sm" style="z-index: 5;">SẮP HẾT HẠN</span>
                <?php endif; ?>

                <?php if(!empty($p['image'])): ?>
                    <img src="../uploads/promotions/<?= $p['image'] ?>" class="voucher-img" alt="promo">
                <?php else: ?>
                    <div class="voucher-img d-flex align-items-center justify-content-center text-muted small">Chưa có ảnh banner</div>
                <?php endif; ?>

                <div class="voucher-header" style="<?= $is_expired ? 'filter: grayscale(1); opacity: 0.8;' : '' ?>">
                    <h3 class="fw-bold mb-0"><?= $p['type'] == 'percent' ? $p['value'].'%' : number_format($p['value']).'đ' ?></h3>
                </div>
                <div class="voucher-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <span class="badge rounded-pill <?= $is_expired ? 'bg-secondary' : 'bg-success' ?>">
                            <?= $p['status'] == 0 ? 'Tạm ngưng' : ($is_expired ? 'Hết hạn' : 'Đang chạy') ?>
                        </span>
                        <div class="dropdown">
                            <button class="btn btn-link text-muted p-0" data-bs-toggle="dropdown"><i class="fas fa-ellipsis-v"></i></button>
                            <ul class="dropdown-menu border-0 shadow-lg rounded-3">
                                <li><button class="dropdown-item py-2" onclick='openEdit(<?= json_encode($p) ?>)'><i class="fas fa-edit me-2 text-primary"></i> Sửa khuyến mãi</button></li>
                                <li><button class="dropdown-item py-2" onclick='openView(<?= json_encode($p) ?>)'><i class="fas fa-eye me-2 text-info"></i> Xem thông tin ưu đãi</button></li>
                                <li>
                                    <a class="dropdown-item py-2 <?= $p['status']==1 ? 'text-warning' : 'text-success' ?>" href="?toggle_status=<?= $p['status'] ?>&id=<?= $p['id'] ?>">
                                        <i class="fas <?= $p['status']==1 ? 'fa-pause' : 'fa-play' ?> me-2"></i> <?= $p['status']==1 ? 'Tạm dừng mã' : 'Kích hoạt lại' ?>
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item py-2 text-danger" href="?del=<?= $p['id'] ?>" onclick="return confirm('Xác nhận xóa mã?')"><i class="fas fa-trash me-2"></i> Xóa mã</a></li>
                            </ul>
                        </div>
                    </div>
                    <h6 class="fw-bold mb-2 text-truncate"><?= $p['name'] ?></h6>
                    <div class="voucher-code mb-2" onclick="copyToClipboard('<?= $p['code'] ?>')">
                        <?= $p['code'] ?> <i class="far fa-copy small opacity-50"></i>
                    </div>
                    
                    <div class="progress"><div class="progress-bar <?= $percent > 80 ? 'bg-danger' : 'bg-warning' ?>" style="width: <?= $percent ?>%"></div></div>
                    <div class="d-flex justify-content-between small text-muted">
                        <span>Hạn: <?= date('d/m/Y', strtotime($p['end_date'])) ?></span>
                        <span><?= $p['used_count'] ?>/<?= $p['usage_limit'] ?> lượt</span>
                    </div>
                </div>
            </div>
        </div>
        <?php endwhile; else: ?>
            <div class="col-12 text-center py-5 text-muted"><i class="fas fa-folder-open fa-3x mb-3 opacity-20"></i><p>Không tìm thấy khuyến mãi nào.</p></div>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="addPromoModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <form class="modal-content border-0 shadow-lg rounded-5" method="POST" enctype="multipart/form-data">
            <div class="modal-body p-5">
                <div class="row g-4">
                    <div class="col-md-4 border-end">
                        <h5 class="fw-bold mb-4">Hình ảnh & Mô tả</h5>
                        <label class="label-group">Ảnh banner khuyến mãi</label>
                        <div class="img-preview" id="add_preview"><span class="text-muted small">Chưa chọn ảnh</span></div>
                        <input type="file" name="p_image" class="form-control mb-3" onchange="previewImg(this, 'add_preview')">
                        <label class="label-group">Mô tả chi tiết</label>
                        <textarea name="p_desc" class="form-control rounded-3" rows="6" placeholder="Khách hàng sẽ thấy nội dung này..."></textarea>
                        <label class="label-group mt-3">Địa chỉ áp dụng</label>
                        <input type="text" name="p_address" class="form-control rounded-3" placeholder="Ví dụ: Toàn quốc">
                    </div>
                    <div class="col-md-8">
                        <h5 class="fw-bold mb-4">Thông số cấu hình</h5>
                        <div class="row g-3">
                            <div class="col-md-8"><label class="label-group">Tên ưu đãi</label><input type="text" name="p_name" class="form-control rounded-3" required></div>
                            <div class="col-md-4"><label class="label-group">Mã ưu đãi</label><input type="text" name="p_code" class="form-control rounded-3 fw-bold text-primary" required></div>
                            <div class="col-md-4"><label class="label-group">Loại giảm</label><select name="p_type" class="form-select rounded-3"><option value="percent">Phần trăm (%)</option><option value="fixed">Số tiền (đ)</option></select></div>
                            <div class="col-md-4"><label class="label-group">Giá trị</label><input type="number" name="p_value" class="form-control rounded-3" required></div>
                            <div class="col-md-4"><label class="label-group">Giảm tối đa (đ)</label><input type="number" name="p_max_disc" class="form-control rounded-3" value="0"></div>
                            <div class="col-md-6"><label class="label-group">Đơn tối thiểu (đ)</label><input type="number" name="p_min_order" class="form-control rounded-3" value="0"></div>
                            <div class="col-md-6"><label class="label-group">Giới hạn dùng</label><input type="number" name="p_limit" class="form-control rounded-3" value="100"></div>
                            <div class="col-md-6"><label class="label-group">Bắt đầu</label><input type="date" name="p_start" class="form-control rounded-3" value="<?= date('Y-m-d') ?>"></div>
                            <div class="col-md-6"><label class="label-group">Kết thúc</label><input type="date" name="p_end" class="form-control rounded-3" value="<?= date('Y-m-d', strtotime('+1 month')) ?>"></div>
                        </div>
                        <div class="mt-5 d-flex gap-2">
                            <button type="button" class="btn btn-light px-4 py-3 rounded-4" data-bs-dismiss="modal">Đóng</button>
                            <button type="submit" name="add_promo" class="btn btn-warning text-white flex-grow-1 py-3 rounded-4 fw-bold shadow">XÁC NHẬN PHÁT HÀNH MÃ</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <form class="modal-content border-0 shadow-lg rounded-5" method="POST" enctype="multipart/form-data">
            <div class="modal-body p-5">
                <input type="hidden" name="p_id" id="edit_id">
                <div class="row g-4">
                    <div class="col-md-4 border-end">
                        <h5 class="fw-bold mb-4">Hình ảnh & Mô tả</h5>
                        <div class="img-preview" id="edit_preview"></div>
                        <input type="file" name="p_image" class="form-control mb-3" onchange="previewImg(this, 'edit_preview')">
                        <label class="label-group">Mô tả chi tiết</label>
                        <textarea name="p_desc" id="edit_desc" class="form-control rounded-3" rows="6"></textarea>
                        <label class="label-group mt-3">Địa chỉ công ty</label>
                        <input type="text" name="p_address" id="edit_address" class="form-control rounded-3">
                    </div>
                    <div class="col-md-8">
                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <h5 class="fw-bold mb-0">Cập nhật ưu đãi</h5>
                            <select name="p_status" id="edit_status" class="form-select w-auto rounded-3">
                                <option value="1">Đang chạy</option>
                                <option value="0">Tạm dừng</option>
                            </select>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-12"><label class="label-group">Tên chiến dịch</label><input type="text" name="p_name" id="edit_name" class="form-control rounded-3" required></div>
                            <div class="col-md-4"><label class="label-group">Loại giảm</label><select name="p_type" id="edit_type" class="form-select rounded-3"><option value="percent">Phần trăm (%)</option><option value="fixed">Số tiền (đ)</option></select></div>
                            <div class="col-md-4"><label class="label-group">Giá trị</label><input type="number" name="p_value" id="edit_value" class="form-control rounded-3"></div>
                            <div class="col-md-4"><label class="label-group">Giảm tối đa</label><input type="number" name="p_max_disc" id="edit_max_disc" class="form-control rounded-3"></div>
                            <div class="col-md-6"><label class="label-group">Đơn tối thiểu</label><input type="number" name="p_min_order" id="edit_min_order" class="form-control rounded-3"></div>
                            <div class="col-md-6"><label class="label-group">Lượt dùng</label><input type="number" name="p_limit" id="edit_limit" class="form-control rounded-3"></div>
                            <div class="col-md-6"><label class="label-group">Bắt đầu</label><input type="date" name="p_start" id="edit_start" class="form-control rounded-3"></div>
                            <div class="col-md-6"><label class="label-group">Kết thúc</label><input type="date" name="p_end" id="edit_end" class="form-control rounded-3"></div>
                        </div>
                        <div class="mt-5 d-flex gap-2">
                            <button type="button" class="btn btn-light px-4 py-3 rounded-4" data-bs-dismiss="modal">Hủy</button>
                            <button type="submit" name="edit_promo" class="btn btn-dark text-white flex-grow-1 py-3 rounded-4 fw-bold">CẬP NHẬT</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-5 overflow-hidden">
            <div id="v_banner" style="height: 220px; background: #eee; position: relative; background-size:cover; background-position:center;">
                <div style="position:absolute; bottom:0; left:0; right:0; padding:30px; background:linear-gradient(transparent, rgba(0,0,0,0.8)); color:white;">
                    <h2 id="v_name" class="fw-bold mb-0"></h2>
                    <p id="v_code_display" class="mb-0 opacity-75 fw-bold"></p>
                </div>
            </div>
            <div class="modal-body p-4">
                <div class="row g-4">
                    <div class="col-md-7">
                        <h6 class="label-group mb-3">Mô tả khuyến mãi</h6>
                        <p id="v_desc" class="text-muted small" style="white-space: pre-line; min-height: 100px;"></p>
                        <hr>
                        <p class="small text-muted mb-0"><i class="fas fa-map-marker-alt me-2"></i><span id="v_address"></span></p>
                    </div>
                    <div class="col-md-5">
                        <div class="bg-light p-3 rounded-4 h-100">
                            <h6 class="label-group mb-3">Thông số chi tiết</h6>
                            <div class="d-flex justify-content-between mb-2 small"><span>Giảm:</span> <b id="v_value" class="text-warning"></b></div>
                            <div class="d-flex justify-content-between mb-2 small"><span>Đơn tối thiểu:</span> <b id="v_min"></b></div>
                            <div class="d-flex justify-content-between mb-2 small"><span>Lượt dùng:</span> <b id="v_used"></b></div>
                            <div class="d-flex justify-content-between mb-2 small"><span>Hết hạn:</span> <b id="v_end"></b></div>
                        </div>
                    </div>
                </div>
                <button class="btn btn-dark w-100 mt-4 py-3 rounded-4 fw-bold" data-bs-dismiss="modal">Đóng</button>
            </div>
        </div>
    </div>
</div>

<div class="toast-container">
    <div id="copyToast" class="toast align-items-center text-white bg-dark border-0 rounded-3" role="alert" aria-live="assertive" aria-atomic="true">
        <div class="d-flex">
            <div class="toast-body"><i class="fas fa-check-circle text-success me-2"></i> Đã sao chép mã vào bộ nhớ!</div>
        </div>
    </div>
</div>

<script>
function previewImg(input, containerId) {
    const container = document.getElementById(containerId);
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            container.innerHTML = `<img src="${e.target.result}" style="width:100%; height:100%; object-fit:cover;">`;
        }
        reader.readAsDataURL(input.files[0]);
    }
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text);
    const toast = new bootstrap.Toast(document.getElementById('copyToast'));
    toast.show();
}

function openEdit(p) {
    document.getElementById('edit_id').value = p.id;
    document.getElementById('edit_name').value = p.name;
    document.getElementById('edit_desc').value = p.description || '';
    document.getElementById('edit_address').value = p.office_address || '';
    document.getElementById('edit_type').value = p.type;
    document.getElementById('edit_value').value = p.value;
    document.getElementById('edit_min_order').value = p.min_order_value;
    document.getElementById('edit_max_disc').value = p.max_discount;
    document.getElementById('edit_limit').value = p.usage_limit;
    document.getElementById('edit_start').value = p.start_date;
    document.getElementById('edit_end').value = p.end_date;
    document.getElementById('edit_status').value = p.status;
    const preview = document.getElementById('edit_preview');
    preview.innerHTML = p.image ? `<img src="../uploads/promotions/${p.image}" style="width:100%; height:100%; object-fit:cover;">` : '<span class="text-muted small">No image</span>';
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

function openView(p) {
    document.getElementById('v_name').innerText = p.name;
    document.getElementById('v_code_display').innerText = 'CODE: ' + p.code;
    document.getElementById('v_desc').innerText = p.description || 'Chưa có mô tả.';
    document.getElementById('v_address').innerText = p.office_address || 'Toàn hệ thống.';
    document.getElementById('v_value').innerText = p.type == 'percent' ? p.value+'%' : new Intl.NumberFormat('vi-VN').format(p.value)+'đ';
    document.getElementById('v_min').innerText = new Intl.NumberFormat('vi-VN').format(p.min_order_value)+'đ';
    document.getElementById('v_used').innerText = p.used_count + ' / ' + p.usage_limit;
    document.getElementById('v_end').innerText = p.end_date;
    const banner = document.getElementById('v_banner');
    banner.style.backgroundImage = p.image ? `url('../uploads/promotions/${p.image}')` : 'linear-gradient(135deg, #f59e0b, #fbbf24)';
    new bootstrap.Modal(document.getElementById('viewModal')).show();
}
</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>