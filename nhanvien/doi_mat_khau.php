<?php
// file: nhanvien/doi_mat_khau.php
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

// Xử lý đổi mật khẩu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    $errors = [];
    
    // Kiểm tra mật khẩu cũ
    if (empty($current_password)) {
        $errors[] = "Vui lòng nhập mật khẩu hiện tại";
    }
    
    // Kiểm tra mật khẩu mới
    if (empty($new_password)) {
        $errors[] = "Vui lòng nhập mật khẩu mới";
    } elseif (strlen($new_password) < 6) {
        $errors[] = "Mật khẩu mới phải có ít nhất 6 ký tự";
    }
    
    // Kiểm tra xác nhận mật khẩu
    if ($new_password !== $confirm_password) {
        $errors[] = "Mật khẩu xác nhận không khớp";
    }
    
    if (empty($errors)) {
        // Lấy mật khẩu hiện tại từ database
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $ma_nv);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            // Kiểm tra mật khẩu cũ (MD5)
            if (md5($current_password) !== $user['password']) {
                $errors[] = "Mật khẩu hiện tại không chính xác";
            } else {
                // Cập nhật mật khẩu mới
                $new_hashed = md5($new_password);
                $update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $update->bind_param("si", $new_hashed, $ma_nv);
                if ($update->execute()) {
                    $_SESSION['success'] = "Đổi mật khẩu thành công!";
                    header("Location: doi_mat_khau.php");
                    exit();
                } else {
                    $errors[] = "Lỗi: " . $conn->error;
                }
            }
        } else {
            $errors[] = "Không tìm thấy thông tin người dùng";
        }
        $stmt->close();
    }
    
    if (!empty($errors)) {
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
<title>Đổi mật khẩu — QA Tech (Nhân viên)</title>
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

/* Form */
.password-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
    max-width: 600px;
    margin: 0 auto;
}
.password-header {
    background: linear-gradient(135deg, #0f172a, #1e3a5f);
    padding: 24px;
    text-align: center;
}
.password-header-icon {
    width: 70px;
    height: 70px;
    background: rgba(255,255,255,.1);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 16px;
    font-size: 28px;
    color: #fff;
}
.password-header h3 {
    color: #fff;
    font-size: 18px;
    font-weight: 700;
    margin-bottom: 5px;
}
.password-header p {
    color: rgba(255,255,255,.7);
    font-size: 13px;
}
.password-body {
    padding: 28px;
}
.form-label-custom {
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 6px;
    color: var(--text-primary);
}
.input-icon-group {
    position: relative;
}
.input-icon-group i {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    font-size: 14px;
}
.input-icon-group input {
    padding-left: 40px;
}
.form-control-custom {
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 12px 14px;
    font-size: 14px;
    font-family: inherit;
    width: 100%;
}
.form-control-custom:focus {
    border-color: var(--blue-primary);
    box-shadow: 0 0 0 3px rgba(37,99,235,.1);
    outline: none;
}
.password-strength {
    margin-top: 8px;
}
.strength-bar {
    height: 4px;
    border-radius: 2px;
    background: #e2e8f0;
    margin-top: 6px;
    overflow: hidden;
}
.strength-fill {
    height: 100%;
    width: 0%;
    transition: width .3s, background .3s;
}
.strength-text {
    font-size: 11px;
    margin-top: 4px;
    display: inline-block;
}
.btn-change {
    background: var(--blue-primary);
    color: #fff;
    border: none;
    padding: 12px 28px;
    border-radius: 10px;
    font-weight: 700;
    font-size: 14px;
    cursor: pointer;
    width: 100%;
    transition: all .2s;
}
.btn-change:hover { background: var(--blue-dark); transform: translateY(-1px); }
.btn-cancel {
    background: transparent;
    color: var(--text-muted);
    border: 1px solid var(--border);
    padding: 12px 20px;
    border-radius: 10px;
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    text-align: center;
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
.alert-warning { background: #fef3c7; border: 1px solid #fbbf24; color: #92400e; }

/* Security tips */
.security-tips {
    background: #f8fafc;
    border-radius: 10px;
    padding: 16px;
    margin-top: 20px;
}
.security-tips h6 {
    font-size: 12px;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 8px;
}
.security-tips ul {
    margin: 0;
    padding-left: 18px;
    font-size: 12px;
    color: var(--text-muted);
}
.security-tips li {
    margin-bottom: 4px;
}

@media (max-width: 768px) {
    .sidebar { transform: translateX(-100%); }
    .main { margin-left: 0; padding: 16px; }
    .topbar { margin: -16px -16px 20px -16px; }
    .password-body { padding: 20px; }
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
        <a href="thong_tin_ca_nhan.php" class="nav-link-item">
            <div class="nav-icon"><i class="fas fa-user-circle"></i></div> Thông tin cá nhân
        </a>
        <a href="doi_mat_khau.php" class="nav-link-item active">
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
            <span class="fw-bold">Đổi mật khẩu</span>
            <span class="text-muted">/</span>
            <span class="text-muted">Bảo mật tài khoản</span>
        </div>
        <div class="dropdown">
            <a href="#" class="avatar-btn dropdown-toggle text-decoration-none" data-bs-toggle="dropdown">
                <img src="https://ui-avatars.com/api/?name=<?= urlencode($employee_name) ?>&background=2563eb&color=fff&bold=true"
                     class="avatar-img" alt="avatar">
                <span class="avatar-name"><?= htmlspecialchars($employee_name) ?></span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 mt-2">
                <li><a class="dropdown-item" href="thong_tin_ca_nhan.php"><i class="fas fa-user-circle me-2"></i> Thông tin cá nhân</a></li>
                <li><a class="dropdown-item active" href="doi_mat_khau.php"><i class="fas fa-key me-2"></i> Đổi mật khẩu</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Đăng xuất</a></li>
            </ul>
        </div>
    </div>

    <div class="page-header">
        <h1>Đổi mật khẩu</h1>
        <p><?= $greeting ?>, <?= htmlspecialchars($employee_name) ?>! Hãy bảo vệ tài khoản của bạn</p>
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

    <!-- Form đổi mật khẩu -->
    <div class="password-card">
        <div class="password-header">
            <div class="password-header-icon">
                <i class="fas fa-lock"></i>
            </div>
            <h3>Thay đổi mật khẩu</h3>
            <p>Vui lòng nhập mật khẩu hiện tại và mật khẩu mới</p>
        </div>
        
        <form method="POST" class="password-body">
            <div class="mb-4">
                <label class="form-label-custom">Mật khẩu hiện tại</label>
                <div class="input-icon-group">
                    <i class="fas fa-key"></i>
                    <input type="password" name="current_password" class="form-control-custom" 
                           placeholder="Nhập mật khẩu hiện tại" required>
                </div>
            </div>
            
            <div class="mb-4">
                <label class="form-label-custom">Mật khẩu mới</label>
                <div class="input-icon-group">
                    <i class="fas fa-lock"></i>
                    <input type="password" name="new_password" id="new_password" class="form-control-custom" 
                           placeholder="Ít nhất 6 ký tự" required>
                </div>
                <div class="password-strength">
                    <div class="strength-bar">
                        <div class="strength-fill" id="strengthFill"></div>
                    </div>
                    <span class="strength-text" id="strengthText"></span>
                </div>
            </div>
            
            <div class="mb-4">
                <label class="form-label-custom">Xác nhận mật khẩu mới</label>
                <div class="input-icon-group">
                    <i class="fas fa-check-circle"></i>
                    <input type="password" name="confirm_password" id="confirm_password" class="form-control-custom" 
                           placeholder="Nhập lại mật khẩu mới" required>
                </div>
                <div id="matchMessage" class="form-text" style="font-size: 11px;"></div>
            </div>
            
            <div class="d-flex gap-3">
                <a href="trang_chu.php" class="btn-cancel" style="flex: 1;">
                    <i class="fas fa-times"></i> Hủy bỏ
                </a>
                <button type="submit" name="change_password" class="btn-change" style="flex: 2;">
                    <i class="fas fa-save"></i> Đổi mật khẩu
                </button>
            </div>
        </form>
    </div>

    <!-- Security Tips -->
    <div class="security-tips" style="max-width: 600px; margin: 20px auto 0;">
        <h6><i class="fas fa-shield-alt me-2"></i> Mẹo bảo mật:</h6>
        <ul>
            <li>Mật khẩu nên có ít nhất 6 ký tự</li>
            <li>Kết hợp chữ hoa, chữ thường, số và ký tự đặc biệt</li>
            <li>Không sử dụng mật khẩu giống với tài khoản khác</li>
            <li>Không chia sẻ mật khẩu với bất kỳ ai</li>
            <li>Đăng xuất khi không sử dụng máy tính</li>
        </ul>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Kiểm tra độ mạnh mật khẩu
function checkPasswordStrength(password) {
    let strength = 0;
    if (password.length >= 6) strength++;
    if (password.length >= 8) strength++;
    if (/[a-z]/.test(password) && /[A-Z]/.test(password)) strength++;
    if (/[0-9]/.test(password)) strength++;
    if (/[^a-zA-Z0-9]/.test(password)) strength++;
    
    const fill = document.getElementById('strengthFill');
    const text = document.getElementById('strengthText');
    
    if (password.length === 0) {
        fill.style.width = '0%';
        text.textContent = '';
        return;
    }
    
    let percent = Math.min(100, strength * 20);
    fill.style.width = percent + '%';
    
    if (strength <= 1) {
        fill.style.background = '#ef4444';
        text.textContent = 'Yếu';
        text.style.color = '#ef4444';
    } else if (strength <= 3) {
        fill.style.background = '#f59e0b';
        text.textContent = 'Trung bình';
        text.style.color = '#f59e0b';
    } else {
        fill.style.background = '#10b981';
        text.textContent = 'Mạnh';
        text.style.color = '#10b981';
    }
}

// Kiểm tra mật khẩu xác nhận
function checkMatch() {
    const newPass = document.getElementById('new_password').value;
    const confirmPass = document.getElementById('confirm_password').value;
    const message = document.getElementById('matchMessage');
    
    if (confirmPass.length === 0) {
        message.textContent = '';
        return;
    }
    
    if (newPass === confirmPass) {
        message.innerHTML = '<i class="fas fa-check-circle"></i> Mật khẩu xác nhận khớp';
        message.style.color = '#10b981';
    } else {
        message.innerHTML = '<i class="fas fa-exclamation-circle"></i> Mật khẩu xác nhận không khớp';
        message.style.color = '#ef4444';
    }
}

document.getElementById('new_password').addEventListener('input', function() {
    checkPasswordStrength(this.value);
    checkMatch();
});

document.getElementById('confirm_password').addEventListener('input', checkMatch);
</script>
</body>
</html>