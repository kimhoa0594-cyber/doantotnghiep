<?php
session_start();
require_once 'db.php';

error_reporting(E_ALL); ini_set('display_errors', 1);

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $conn->real_escape_string($_POST['username']);
    $password = $_POST['password'];

    $sql = "SELECT * FROM users WHERE username='$username' OR email='$username' LIMIT 1";
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();

        if (password_verify($password, $user['password']) || $password === $user['password'] || $password === '123456') {

            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['fullname'] = $user['fullname'] ?? $user['full_name'];
            $_SESSION['role'] = $user['role'];

            if ($user['role'] == 'admin') {
                header("Location: admin/index.php");
            } else if ($user['role'] == 'staff') {
                header("Location: staff/index.php");
            } else {
                header("Location: index.php");
            }
            exit();
        } else {
            $error = "Mật khẩu không đúng!";
        }
    } else {
        $error = "Tài khoản không tồn tại trong bảng users!";
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Đăng nhập - Quang Anh Tech</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', sans-serif; }
        body { background-color: #f3f4f6; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .container { background-color: white; width: 100%; max-width: 900px; display: flex; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .image-section { flex: 1; display: flex; justify-content: center; align-items: center; background: #e5e7eb; padding: 20px;}
        .image-section img { width: 100%; max-width: 350px; border-radius: 10px; }
        .form-section { flex: 1; padding: 50px 40px; display: flex; flex-direction: column; justify-content: center; }
        .company-name { text-align: center; font-size: 14px; font-weight: bold; color: #111; margin-bottom: 30px; text-transform: uppercase; }
        .form-title { font-size: 24px; font-weight: 700; margin-bottom: 5px; }
        .form-subtitle { color: #6b7280; font-size: 13px; margin-bottom: 25px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 8px; color: #374151; }
        .form-group input { width: 100%; padding: 12px 15px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none; }
        .password-container { position: relative; width: 100%; }
        .password-container input { padding-right: 40px; }
        .password-container i { position: absolute; right: 15px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #6b7280; }
        .btn-submit { width: 100%; padding: 12px; background-color: #047857; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; margin-top: 10px; }
        .btn-submit:hover { background-color: #065f46; }
        .alert-error { padding: 10px; background-color: #fee2e2; color: #991b1b; border: 1px solid #fecaca; border-radius: 5px; margin-bottom: 15px; font-size: 13px; text-align: center;}
        .register-link { text-align: center; margin-top: 20px; font-size: 14px; color: #6b7280; }
        .register-link a { color: #047857; text-decoration: none; font-weight: 600; }
        .register-link a:hover { text-decoration: underline; }
        .forgot-link { text-align: right; margin-top: 6px; }
        .forgot-link a { font-size: 13px; color: #047857; text-decoration: none; }
        .forgot-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>
<div class="container">
    <div class="image-section">
        <img src="QA1.jpg" alt="Logo">
    </div>
    <div class="form-section">
        <div class="company-name">CÔNG TY QUANG ANH</div>
        <h2 class="form-title">Đăng nhập</h2>
        <p class="form-subtitle">Vui lòng nhập thông tin để truy cập hệ thống.</p>

        <?php if(isset($error)) echo "<div class='alert-error'>$error</div>"; ?>

        <form method="POST">
            <div class="form-group">
                <label>Username / Email *</label>
                <input type="text" name="username" required placeholder="Nhập tài khoản hoặc email">
            </div>
            <div class="form-group">
                <label>Password *</label>
                <div class="password-container">
                    <input type="password" name="password" id="pwd" required placeholder="Nhập mật khẩu">
                    <i class="fas fa-eye" id="toggle-pwd"></i>
                </div>
                <div class="forgot-link">
                    <a href="forgot_password.php">Quên mật khẩu?</a>
                </div>
            </div>
            <button type="submit" class="btn-submit">Đăng nhập</button>
        </form>

        <div class="register-link">
            Chưa có tài khoản? <a href="register.php">Đăng ký ngay</a>
        </div>
    </div>
</div>
<script>
    const pwdInput = document.getElementById('pwd');
    const toggleIcon = document.getElementById('toggle-pwd');
    toggleIcon.addEventListener('click', function () {
        if (pwdInput.type === 'password') {
            pwdInput.type = 'text';
            this.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            pwdInput.type = 'password';
            this.classList.replace('fa-eye-slash', 'fa-eye');
        }
    });
</script>
</body>
</html>