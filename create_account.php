<?php
session_start();
require_once 'db.php';

$error = "";
$show_success = false;
$username_saved = "";

// 1. Bảo mật: Phải qua bước OTP mới được vào đây
if (!isset($_SESSION['temp_reg']['verified']) || $_SESSION['temp_reg']['verified'] !== true) {
    header("Location: register.php");
    exit();
}

// 2. Xử lý khi người dùng nhấn nút "Hoàn tất đăng ký"
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username_saved = $conn->real_escape_string($_POST['username']);
    $password = $_POST['password'];
    $email = $_SESSION['temp_reg']['contact'];

    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    // Khởi tạo câu lệnh SQL
    $sql = "INSERT INTO users (username, password, email, role) VALUES ('$username_saved', '$hashed_password', '$email', 'user')";
    
    try {
        if ($conn->query($sql)) {
            unset($_SESSION['temp_reg']);
            $show_success = true; // Kích hoạt Modal thành công
        }
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() == 1062) {
            $error = "Tên đăng nhập này đã tồn tại!";
        } else {
            $error = "Lỗi hệ thống: " . $e->getMessage() . ". (Vui lòng chỉnh cột 'role' thành VARCHAR(50) trong phpMyAdmin)";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Hoàn tất đăng ký - Quang Anh Tech</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', sans-serif; }
        body { background-color: #f3f4f6; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        
        .container { 
            background: white; width: 100%; max-width: 900px; 
            display: flex; border-radius: 20px; overflow: hidden; 
            box-shadow: 0 15px 35px rgba(0,0,0,0.1); 
        }

        .image-section { 
            flex: 1.2; background: #f8fafc; display: flex; 
            justify-content: center; align-items: center; padding: 40px;
        }
        .image-section img { width: 100%; border-radius: 15px; }

        .form-section { flex: 1; padding: 40px; display: flex; flex-direction: column; justify-content: center; }
        
        .company-name { text-align: center; font-size: 12px; font-weight: 800; color: #047857; margin-bottom: 20px; text-transform: uppercase; }
        h2 { font-size: 26px; color: #111827; margin-bottom: 5px; font-weight: 700; }
        p.subtitle { color: #6b7280; font-size: 14px; margin-bottom: 25px; }

        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #374151; }
        
        .input-group { position: relative; }
        .input-group i.main-icon { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #9ca3af; }
        .toggle-password { position: absolute; right: 15px; top: 50%; transform: translateY(-50%); color: #9ca3af; cursor: pointer; }
        
        input {
            width: 100%; padding: 12px 40px 12px 45px;
            border: 1px solid #d1d5db; border-radius: 10px; font-size: 14px; outline: none; transition: 0.3s;
        }
        input:focus { border-color: #047857; box-shadow: 0 0 0 3px rgba(4, 120, 87, 0.1); }

        .btn-submit {
            width: 100%; padding: 14px;
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            color: white; border: none; border-radius: 10px;
            font-weight: 700; cursor: pointer; font-size: 15px; margin-top: 10px;
            text-transform: uppercase; transition: 0.3s;
        }
        .btn-submit:disabled { background: #9ca3af; cursor: not-allowed; }

        .btn-back {
            display: block; width: 100%; text-align: center; padding: 12px; margin-top: 10px;
            color: #6b7280; text-decoration: none; font-size: 14px; font-weight: 600;
            border: 1px solid #d1d5db; border-radius: 10px; transition: 0.3s;
        }
        .btn-back:hover { background: #f9fafb; color: #111827; }

        .error-alert { background: #fef2f2; color: #dc2626; padding: 10px; border-radius: 8px; margin-bottom: 15px; font-size: 13px; border: 1px solid #fee2e2; }

        /* --- MODAL STYLE --- */
        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.6); backdrop-filter: blur(5px);
            display: none; justify-content: center; align-items: center; z-index: 1000;
        }
        .custom-modal {
            background: white; padding: 35px; border-radius: 25px;
            width: 90%; max-width: 400px; text-align: center;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            animation: slideUp 0.4s ease-out;
        }
        @keyframes slideUp { from { transform: translateY(30px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

        .success-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.7); backdrop-filter: blur(8px);
            display: <?php echo $show_success ? 'flex' : 'none'; ?>; 
            justify-content: center; align-items: center; z-index: 2000;
        }
        .success-icon { font-size: 60px; color: #10b981; margin-bottom: 15px; }
        .btn-modal-primary {
            display: block; width: 100%; padding: 14px; background: #047857;
            color: white; text-decoration: none; border-radius: 12px;
            font-weight: 700; margin-top: 20px;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="image-section"><img src="QA1.jpg"></div>

    <div class="form-section">
        <div class="company-name">Quang Anh Technology</div>
        <h2>Thiết lập tài khoản</h2>
        <p class="subtitle">Email: <b><?php echo $_SESSION['temp_reg']['contact']; ?></b></p>

        <?php if($error != "") echo "<div class='error-alert'><i class='fas fa-exclamation-circle'></i> $error</div>"; ?>

        <form method="POST" id="registerForm">
            <div class="form-group">
                <label>Tên đăng nhập</label>
                <div class="input-group">
                    <i class="fas fa-user-circle main-icon"></i>
                    <input type="text" name="username" value="<?php echo $username_saved; ?>" placeholder="Nhập tên đăng nhập" required>
                </div>
            </div>

            <div class="form-group">
                <label>Mật khẩu</label>
                <div class="input-group">
                    <i class="fas fa-lock main-icon"></i>
                    <input type="password" name="password" id="password" placeholder="••••••••" required>
                    <i class="fas fa-eye toggle-password" onclick="togglePass('password', this)"></i>
                </div>
            </div>

            <div class="form-group">
                <label>Xác nhận mật khẩu</label>
                <div class="input-group">
                    <i class="fas fa-check-double main-icon"></i>
                    <input type="password" id="confirm_password" placeholder="••••••••" required>
                    <i class="fas fa-eye toggle-password" onclick="togglePass('confirm_password', this)"></i>
                </div>
                <div id="msg" style="font-size: 12px; margin-top: 5px; font-weight: 600;"></div>
            </div>

            <button type="submit" class="btn-submit" id="submitBtn" disabled>Hoàn tất đăng ký</button>
            <a href="javascript:void(0)" class="btn-back" onclick="openConfirmModal()">Quay lại đăng nhập</a>
        </form>
    </div>
</div>

<div class="modal-overlay" id="confirmExitModal">
    <div class="custom-modal">
        <i class="fas fa-exclamation-triangle" style="font-size: 50px; color: #f59e0b;"></i>
        <h3 style="margin: 15px 0 10px;">Hủy đăng ký?</h3>
        <p style="color: #6b7280; font-size: 14px;">Dữ liệu của bạn sẽ bị xóa bỏ. Bạn có chắc chắn không?</p>
        <div style="display: flex; gap: 10px; margin-top: 25px;">
            <button onclick="window.location.href='login.php'" style="flex:1; padding:12px; border-radius:10px; border:none; background:#fef2f2; color:#dc2626; font-weight:700; cursor:pointer;">Rời đi</button>
            <button onclick="closeConfirmModal()" style="flex:1; padding:12px; border-radius:10px; border:none; background:#047857; color:white; font-weight:700; cursor:pointer;">Ở lại</button>
        </div>
    </div>
</div>

<div class="success-overlay" id="successModal">
    <div class="custom-modal">
        <div class="success-icon"><i class="fas fa-check-circle"></i></div>
        <h3>Thành công!</h3>
        <p style="color: #6b7280; margin-top: 10px;">Tài khoản đã được khởi tạo.<br>Chào mừng bạn đã đăng ký thành công tài khoản trên website của Quang Anh.</p>
        <a href="login.php" class="btn-modal-primary">Đăng nhập ngay</a>
    </div>
</div>

<script>
    // 1. Ẩn hiện mật khẩu
    function togglePass(id, el) {
        const input = document.getElementById(id);
        if (input.type === "password") {
            input.type = "text";
            el.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            input.type = "password";
            el.classList.replace('fa-eye-slash', 'fa-eye');
        }
    }

    // 2. Kiểm tra mật khẩu khớp thời gian thực
    const password = document.getElementById('password');
    const confirm_password = document.getElementById('confirm_password');
    const msg = document.getElementById('msg');
    const btn = document.getElementById('submitBtn');

    function validate() {
        if (confirm_password.value === "") {
            msg.innerHTML = "";
            btn.disabled = true;
        } else if (password.value === confirm_password.value) {
            msg.style.color = "#059669";
            msg.innerHTML = "<i class='fas fa-check'></i> Mật khẩu trùng khớp";
            btn.disabled = false;
        } else {
            msg.style.color = "#dc2626";
            msg.innerHTML = "<i class='fas fa-times'></i> Mật khẩu chưa khớp";
            btn.disabled = true;
        }
    }
    password.addEventListener('keyup', validate);
    confirm_password.addEventListener('keyup', validate);

    // 3. Xử lý Modals
    function openConfirmModal() { document.getElementById('confirmExitModal').style.display = 'flex'; }
    function closeConfirmModal() { document.getElementById('confirmExitModal').style.display = 'none'; }
</script>

</body>
</html>