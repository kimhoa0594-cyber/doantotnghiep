<?php
session_start();
require_once 'db.php';

// Kiểm tra nếu người dùng chưa qua bước đăng ký (chưa có session) thì bắt quay lại
if (!isset($_SESSION['temp_reg'])) {
    header("Location: register.php");
    exit();
}

$error = "";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Lấy 6 chữ số từ 6 ô input và ghép lại thành chuỗi
    $user_otp = "";
    if(isset($_POST['otp_digits']) && is_array($_POST['otp_digits'])){
        $user_otp = implode('', $_POST['otp_digits']);
    }

    // Kiểm tra mã người dùng nhập với mã trong Session
    if ($user_otp == $_SESSION['temp_reg']['otp']) {
        // ĐÁNH DẤU XÁC MINH THÀNH CÔNG
        $_SESSION['temp_reg']['verified'] = true; 
        
        // CHUYỂN SANG TRANG THIẾT LẬP TÊN TÀI KHOẢN VÀ MẬT KHẨU
        header("Location: create_account.php");
        exit();
    } else {
        $error = "Mã xác minh không chính xác. Vui lòng kiểm tra lại!";
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Xác minh OTP - Quang Anh Tech</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', sans-serif; }
        body { background: #f3f4f6; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        
        .verify-card {
            background: white; width: 100%; max-width: 420px;
            padding: 40px; border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            text-align: center;
        }

        .icon-box {
            width: 65px; height: 65px; background: #ecfdf5; color: #047857;
            border-radius: 50%; display: flex; justify-content: center; align-items: center;
            margin: 0 auto 25px; font-size: 28px;
        }

        h2 { color: #111827; font-size: 24px; margin-bottom: 10px; }
        p { color: #6b7280; font-size: 14px; line-height: 1.6; margin-bottom: 30px; }
        .target-email { color: #047857; font-weight: 700; text-decoration: underline; }

        /* Giao diện 6 ô nhập mã */
        .otp-group {
            display: flex; justify-content: center; gap: 10px; margin-bottom: 25px;
        }
        .otp-digit {
            width: 45px; height: 55px; border: 2px solid #e5e7eb;
            border-radius: 10px; text-align: center; font-size: 22px;
            font-weight: 800; color: #111827; outline: none; transition: 0.3s;
        }
        .otp-digit:focus { border-color: #047857; box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1); }

        .error-msg {
            background: #fef2f2; color: #dc2626; padding: 12px;
            border-radius: 10px; font-size: 13px; margin-bottom: 20px;
            border: 1px solid #fee2e2;
        }

        .btn-verify {
            width: 100%; padding: 14px;
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            color: white; border: none; border-radius: 10px;
            font-weight: 700; cursor: pointer; font-size: 15px;
            text-transform: uppercase; letter-spacing: 1px; transition: 0.3s;
        }
        .btn-verify:hover { opacity: 0.9; transform: translateY(-1px); }

        .footer-text { margin-top: 25px; font-size: 14px; color: #6b7280; }
        .footer-text a { color: #047857; text-decoration: none; font-weight: 700; }
    </style>
</head>
<body>

<div class="verify-card">
    <div class="icon-box">
        <i class="fas fa-user-check"></i>
    </div>
    <h2>Xác minh mã OTP</h2>
    <p>Vui lòng nhập mã xác minh 6 số đã được gửi tới địa chỉ Email:<br>
       <span class="target-email"><?php echo $_SESSION['temp_reg']['contact']; ?></span>
    </p>

    <?php if($error != ""): ?>
        <div class="error-msg">
            <i class="fas fa-times-circle"></i> <?php echo $error; ?>
        </div>
    <?php endif; ?>

    <form method="POST" id="otp-form">
        <div class="otp-group">
            <input type="text" name="otp_digits[]" class="otp-digit" maxlength="1" required pattern="\d*">
            <input type="text" name="otp_digits[]" class="otp-digit" maxlength="1" required pattern="\d*">
            <input type="text" name="otp_digits[]" class="otp-digit" maxlength="1" required pattern="\d*">
            <input type="text" name="otp_digits[]" class="otp-digit" maxlength="1" required pattern="\d*">
            <input type="text" name="otp_digits[]" class="otp-digit" maxlength="1" required pattern="\d*">
            <input type="text" name="otp_digits[]" class="otp-digit" maxlength="1" required pattern="\d*">
        </div>
        <button type="submit" class="btn-verify">Xác nhận và Tiếp tục</button>
    </form>

    <div class="footer-text">
        Bạn chưa nhận được mã? <a href="register.php">Gửi lại mã mới</a>
    </div>
</div>

<script>
    // Xử lý tự động chuyển ô khi nhập mã (UX cực xịn)
    const inputs = document.querySelectorAll('.otp-digit');
    
    inputs.forEach((input, index) => {
        // Khi nhập xong 1 số, nhảy sang ô tiếp theo
        input.addEventListener('input', (e) => {
            if (e.target.value.length === 1 && index < inputs.length - 1) {
                inputs[index + 1].focus();
            }
        });

        // Khi nhấn phím Xóa (Backspace), quay lại ô trước đó
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Backspace' && e.target.value.length === 0 && index > 0) {
                inputs[index - 1].focus();
            }
        });

        // Chỉ cho phép nhập số
        input.addEventListener('keypress', (e) => {
            if (!/[0-9]/.test(e.key)) {
                e.preventDefault();
            }
        });
    });
</script>

</body>
</html>