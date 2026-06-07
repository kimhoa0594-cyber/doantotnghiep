<?php
// file: nhanvien/thong_tin_ca_nhan.php
session_start();
require_once '../db.php';

// Kiểm tra đăng nhập và role nhân viên
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'nhan_vien' && $_SESSION['role'] !== 'employee')) {
    header("Location: ../login.php");
    exit();
}

$employee_name = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Nhân viên';
$ma_nv = $_SESSION['user_id'] ?? 0;
$username = $_SESSION['username'] ?? '';

// Lấy thông tin nhân viên từ database
$employee_info = null;
$stmt = $conn->prepare("SELECT * FROM nhan_vien WHERE maNV = ? AND trangThai = 1");
$stmt->bind_param("i", $ma_nv);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $employee_info = $result->fetch_assoc();
    $employee_name = $employee_info['tenNV'] ?? $employee_name;
}
$stmt->close();

// Lấy thông tin user từ bảng users (nếu có liên kết)
$user_info = null;
$stmt = $conn->prepare("SELECT username, email, fullname, role, created_at FROM users WHERE id = ?");
$stmt->bind_param("i", $ma_nv);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $user_info = $result->fetch_assoc();
}
$stmt->close();

// Xử lý cập nhật thông tin
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $tenNV = $conn->real_escape_string(trim($_POST['tenNV']));
    $email = $conn->real_escape_string(trim($_POST['email']));
    $soDienThoai = $conn->real_escape_string(trim($_POST['soDienThoai']));
    $diaChi = $conn->real_escape_string(trim($_POST['diaChi']));
    $ngaySinh = !empty($_POST['ngaySinh']) ? $conn->real_escape_string($_POST['ngaySinh']) : null;
    
    $errors = [];
    if (empty($tenNV)) $errors[] = "Họ tên không được để trống";
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Email không hợp lệ";
    if (!empty($soDienThoai) && !preg_match('/^[0-9]{10}$/', $soDienThoai)) $errors[] = "Số điện thoại phải đúng 10 chữ số";
    if (!empty($ngaySinh) && $ngaySinh > date('Y-m-d')) $errors[] = "Ngày sinh không được lớn hơn hôm nay";
    
    if (empty($errors)) {
        $sql = "UPDATE nhan_vien SET tenNV='$tenNV', email='$email', soDienThoai='$soDienThoai', 
                diaChi='$diaChi', ngaySinh=" . ($ngaySinh ? "'$ngaySinh'" : "NULL") . " WHERE maNV=$ma_nv";
        if ($conn->query($sql)) {
            $_SESSION['fullname'] = $tenNV;
            $_SESSION['success'] = "Cập nhật thông tin thành công!";
            header("Location: thong_tin_ca_nhan.php");
            exit();
        } else {
            $_SESSION['error'] = "Lỗi: " . $conn->error;
        }
    } else {
        $_SESSION['error'] = implode(", ", $errors);
    }
}

$hour = (int)date('H');
$greeting = $hour < 12 ? 'Chào buổi sáng' : ($hour < 18 ? 'Chào buổi chiều' : 'Chào buổi tối');
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Thông tin cá nhân — QA Tech (Nhân viên)</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<style>
:root {
    --blue-primary: #2563eb;
    --blue-dark: #1d4ed8;
    --blue-light: #dbeafe;
    --sidebar-bg: #0f172a;
    --sidebar-w: 268px;
    --surface: #ffffff;
    --surface-2: #f8fafc;
    --border: #e2e8f0;
    --text-primary: #0f172a;
    --text-muted: #64748b;
    --radius: 14px;
    --shadow-sm: 0 1px 3px rgba(0,0,0,.06);
    --shadow-md: 0 4px 16px rgba(0,0,0,.08);
}
*,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Be Vietnam Pro', sans-serif;
    background: #f1f5f9;
    color: var(--text-primary);
}
::-webkit-scrollbar { width: 5px; }
::-webkit-scrollbar-track { background: #e2e8f0; }
::-webkit-scrollbar-thumb { background: var(--blue-primary); border-radius: 99px; }

/* Sidebar */
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
.brand-logo { display: flex; align-items: center; gap: 12px; }
.brand-icon {
    width: 40px; height: 40px;
    background: linear-gradient(135deg, var(--blue-primary), #3b82f6);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; color: white;
}
.brand-name { font-size: 15px; font-weight: 800; color: #fff; }
.brand-sub { font-size: 10px; font-weight: 500; color: #64748b; text-transform: uppercase; }
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

/* Main */
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
.avatar-btn {
    display: flex; align-items: center; gap: 10px;
    padding: 6px 12px 6px 6px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 40px;
    text-decoration: none;
    color: var(--text-primary);
}
.avatar-img { width: 32px; height: 32px; border-radius: 50%; }
.avatar-name { font-size: 13px; font-weight: 600; }

/* Page header */
.page-header { margin-bottom: 24px; }
.page-header h1 { font-size: 22px; font-weight: 800; color: var(--text-primary); }
.page-header p { font-size: 13px; color: var(--text-muted); margin-top: 4px; }

/* Profile */
.profile-cover {
    background: linear-gradient(135deg, #0f172a, #1e3a5f, #1e40af);
    border-radius: var(--radius) var(--radius) 0 0;
    height: 120px;
    position: relative;
}
.profile-avatar {
    position: absolute;
    bottom: -50px;
    left: 30px;
}
.profile-avatar-img {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    border: 4px solid #fff;
    background: linear-gradient(135deg, var(--blue-primary), #3b82f6);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 40px;
    font-weight: 700;
    color: #fff;
    box-shadow: var(--shadow-md);
}
.profile-card {
    background: var(--surface);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    overflow: hidden;
    margin-bottom: 24px;
}
.profile-info {
    padding: 60px 30px 24px 30px;
    border-bottom: 1px solid var(--border);
}
.profile-name { font-size: 20px; font-weight: 800; margin-bottom: 5px; }
.profile-role {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    background: var(--blue-light);
    color: var(--blue-primary);
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    margin-top: 6px;
}
.profile-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
    padding: 20px 30px;
    background: #f8fafc;
}
.stat-item {
    text-align: center;
}
.stat-value { font-size: 20px; font-weight: 800; color: var(--text-primary); }
.stat-label { font-size: 11px; color: var(--text-muted); margin-top: 3px; }

/* Form */
.form-section {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 24px;
    margin-bottom: 20px;
}
.form-section-title {
    font-size: 14px;
    font-weight: 700;
    margin-bottom: 20px;
    padding-bottom: 12px;
    border-bottom: 2px solid var(--border);
    display: flex;
    align-items: center;
    gap: 8px;
}
.form-section-title i { color: var(--blue-primary); }
.form-label-custom {
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 6px;
    color: var(--text-primary);
}
.form-control-custom {
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 10px 14px;
    font-size: 13.5px;
    font-family: inherit;
}
.form-control-custom:focus {
    border-color: var(--blue-primary);
    box-shadow: 0 0 0 3px rgba(37,99,235,.1);
}
.form-control-custom:disabled {
    background: #f8fafc;
    color: var(--text-muted);
}
.btn-update {
    background: var(--blue-primary);
    color: #fff;
    border: none;
    padding: 10px 24px;
    border-radius: 10px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
}
.btn-update:hover { background: var(--blue-dark); }
.btn-cancel {
    background: transparent;
    color: var(--text-muted);
    border: 1px solid var(--border);
    padding: 10px 20px;
    border-radius: 10px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    text-decoration: none;
}
.btn-cancel:hover { background: #f1f5f9; }

/* Alert */
.alert-custom {
    padding: 12px 18px;
    border-radius: 10px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.alert-success { background: #d1fae5; border: 1px solid #86efac; color: #065f46; }
.alert-danger { background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; }

/* Info row */
.info-row {
    display: flex;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid #f1f5f9;
}
.info-row:last-child { border-bottom: none; }
.info-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    background: var(--blue-light);
    color: var(--blue-primary);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 14px;
}
.info-label { font-size: 12px; color: var(--text-muted); margin-bottom: 2px; }
.info-value { font-size: 14px; font-weight: 600; color: var(--text-primary); }

@media (max-width: 768px) {
    .sidebar { transform: translateX(-100%); }
    .main { margin-left: 0; padding: 16px; }
    .topbar { margin: -16px -16px 20px -16px; }
    .profile-stats { grid-template-columns: 1fr; gap: 12px; }
    .profile-avatar-img { width: 80px; height: 80px; font-size: 30px; }
    .profile-info { padding: 50px 20px 20px; }
}
</style>
</head>
<body>

<!-- Sidebar -->
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
            <div class="nav-icon"><i class="fas fa-chart-pie"></i></div> Trang chủ
        </a>
        <div class="nav-section-label">Quản lý</div>
        <a href="quan_ly_khach_hang.php" class="nav-link-item">
            <div class="nav-icon"><i class="fas fa-users"></i></div> Khách hàng
        </a>
        <div class="nav-section-label">Nội dung</div>
        <a href="quan_ly_bai_viet.php" class="nav-link-item">
            <div class="nav-icon"><i class="fas fa-newspaper"></i></div> Quản lý bài viết
        </a>
        <a href="dang_bai_moi.php" class="nav-link-item">
            <div class="nav-icon"><i class="fas fa-pen-fancy"></i></div> Đăng bài mới
        </a>
        <div class="nav-section-label">Marketing</div>
        <a href="khuyen_mai.php" class="nav-link-item">
            <div class="nav-icon"><i class="fas fa-percentage"></i></div> Khuyến mãi
        </a>
        <div class="nav-section-label">Cá nhân</div>
        <a href="thong_tin_ca_nhan.php" class="nav-link-item active">
            <div class="nav-icon"><i class="fas fa-user-circle"></i></div> Thông tin cá nhân
        </a>
        <a href="doi_mat_khau.php" class="nav-link-item">
            <div class="nav-icon"><i class="fas fa-key"></i></div> Đổi mật khẩu
        </a>
    </nav>
    <div class="sidebar-footer">
        <a href="../logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i> Đăng xuất
        </a>
    </div>
</aside>

<!-- Main -->
<main class="main">
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <span class="fw-bold">Thông tin cá nhân</span>
            <span class="text-muted">/</span>
            <span class="text-muted">Hồ sơ của tôi</span>
        </div>
        <div class="dropdown">
            <a href="#" class="avatar-btn dropdown-toggle text-decoration-none" data-bs-toggle="dropdown">
                <img src="https://ui-avatars.com/api/?name=<?= urlencode($employee_name) ?>&background=2563eb&color=fff&bold=true"
                     class="avatar-img" alt="avatar">
                <span class="avatar-name"><?= htmlspecialchars($employee_name) ?></span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 mt-2">
                <li><a class="dropdown-item active" href="thong_tin_ca_nhan.php"><i class="fas fa-user-circle me-2"></i> Thông tin cá nhân</a></li>
                <li><a class="dropdown-item" href="doi_mat_khau.php"><i class="fas fa-key me-2"></i> Đổi mật khẩu</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Đăng xuất</a></li>
            </ul>
        </div>
    </div>

    <div class="page-header">
        <h1>Thông tin cá nhân</h1>
        <p><?= $greeting ?>, <?= htmlspecialchars($employee_name) ?>! 👋 Hãy cập nhật thông tin của bạn</p>
    </div>

    <!-- Alert -->
    <?php if(isset($_SESSION['success'])): ?>
    <div class="alert-custom alert-success">
        <i class="fas fa-check-circle"></i> <?= $_SESSION['success']; unset($_SESSION['success']); ?>
    </div>
    <?php endif; ?>
    <?php if(isset($_SESSION['error'])): ?>
    <div class="alert-custom alert-danger">
        <i class="fas fa-exclamation-triangle"></i> <?= $_SESSION['error']; unset($_SESSION['error']); ?>
    </div>
    <?php endif; ?>

    <div class="row">
        <!-- Cột trái: Profile Card -->
        <div class="col-lg-4">
            <div class="profile-card">
                <div class="profile-cover">
                    <div class="profile-avatar">
                        <div class="profile-avatar-img">
                            <?= mb_substr($employee_name, 0, 1, 'UTF-8') ?>
                        </div>
                    </div>
                </div>
                <div class="profile-info">
                    <div class="profile-name"><?= htmlspecialchars($employee_name) ?></div>
                    <span class="profile-role">
                        <i class="fas fa-briefcase"></i> Nhân viên QA Tech
                    </span>
                </div>
                <div class="profile-stats">
                    <div class="stat-item">
                        <div class="stat-value"><?= $_SESSION['role'] ?? 'Nhân viên' ?></div>
                        <div class="stat-label">Vai trò</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?= date('d/m/Y') ?></div>
                        <div class="stat-label">Hôm nay</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value"><?= date('H:i') ?></div>
                        <div class="stat-label">Giờ làm việc</div>
                    </div>
                </div>
            </div>

            <!-- Thông tin nhanh -->
            <div class="form-section">
                <div class="form-section-title">
                    <i class="fas fa-info-circle"></i> Thông tin nhanh
                </div>
                <div class="info-row">
                    <div class="info-icon"><i class="fas fa-user"></i></div>
                    <div>
                        <div class="info-label">Tên đăng nhập</div>
                        <div class="info-value"><?= htmlspecialchars($username) ?></div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-icon"><i class="fas fa-calendar-alt"></i></div>
                    <div>
                        <div class="info-label">Ngày tham gia</div>
                        <div class="info-value"><?= isset($user_info['created_at']) ? date('d/m/Y', strtotime($user_info['created_at'])) : '--/--/----' ?></div>
                    </div>
                </div>
                <div class="info-row">
                    <div class="info-icon"><i class="fas fa-clock"></i></div>
                    <div>
                        <div class="info-label">Phiên đăng nhập</div>
                        <div class="info-value"><?= date('d/m/Y H:i:s') ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cột phải: Form cập nhật -->
        <div class="col-lg-8">
            <form method="POST">
                <div class="form-section">
                    <div class="form-section-title">
                        <i class="fas fa-edit"></i> Cập nhật thông tin
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label-custom">Họ và tên <span class="text-danger">*</span></label>
                            <input type="text" name="tenNV" class="form-control form-control-custom" 
                                   value="<?= htmlspecialchars($employee_info['tenNV'] ?? $employee_name) ?>" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label-custom">Số điện thoại</label>
                            <input type="tel" name="soDienThoai" class="form-control form-control-custom" 
                                   value="<?= htmlspecialchars($employee_info['soDienThoai'] ?? '') ?>" 
                                   placeholder="0912345678" maxlength="10">
                            <div class="form-text text-muted small">Đúng 10 chữ số</div>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label-custom">Email</label>
                            <input type="email" name="email" class="form-control form-control-custom" 
                                   value="<?= htmlspecialchars($employee_info['email'] ?? '') ?>" 
                                   placeholder="nhanvien@example.com">
                        </div>
                        
                        <div class="col-12">
                            <label class="form-label-custom">Địa chỉ</label>
                            <input type="text" name="diaChi" class="form-control form-control-custom" 
                                   value="<?= htmlspecialchars($employee_info['diaChi'] ?? '') ?>" 
                                   placeholder="Số nhà, đường, phường/xã, quận/huyện, tỉnh/TP">
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label-custom">Ngày sinh</label>
                            <input type="date" name="ngaySinh" class="form-control form-control-custom" 
                                   value="<?= htmlspecialchars($employee_info['ngaySinh'] ?? '') ?>" 
                                   max="<?= date('Y-m-d') ?>">
                            <div class="form-text text-muted small">Không được lớn hơn hôm nay</div>
                        </div>
                        
                        <div class="col-md-6">
                            <label class="form-label-custom">Chức vụ</label>
                            <input type="text" class="form-control form-control-custom" 
                                   value="<?= htmlspecialchars($employee_info['chucVu'] ?? 'Nhân viên') ?>" disabled>
                            <div class="form-text text-muted small">Liên hệ quản trị viên để thay đổi</div>
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-3">
                    <a href="trang_chu.php" class="btn-cancel">
                        <i class="fas fa-times"></i> Hủy bỏ
                    </a>
                    <button type="submit" name="update_profile" class="btn-update">
                        <i class="fas fa-save"></i> Lưu thay đổi
                    </button>
                </div>
            </form>

            <!-- Lưu ý -->
            <div class="alert-custom" style="background: #fef3c7; border-color: #fbbf24; color: #92400e; margin-top: 20px;">
                <i class="fas fa-info-circle"></i>
                <div>
                    <strong>Lưu ý:</strong> Tên đăng nhập và vai trò không thể tự thay đổi. 
                    Vui lòng liên hệ quản trị viên nếu cần thay đổi.
                </div>
            </div>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Validate số điện thoại chỉ nhập số
document.querySelector('input[name="soDienThoai"]').addEventListener('input', function() {
    this.value = this.value.replace(/\D/g, '').slice(0, 10);
});
</script>
</body>
</html>