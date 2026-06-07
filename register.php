<?php
session_start();
require_once 'db.php';
require_once 'includes/mailer.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $conn->real_escape_string($_POST['email']);
    $otp = rand(100000, 999999);
    
    // Lưu thông tin vào session để xác nhận ở trang verify_otp.php
    $_SESSION['temp_reg'] = [
        'contact' => $email,
        'otp'     => $otp
    ];
    
    // Nội dung email gửi đi (Đã thiết kế đẹp mắt)
    $subject = "MA XAC MINH OTP - QUANG ANH TECH";
    $content = "
        <div style='font-family: Arial, sans-serif; border: 2px solid #047857; padding: 25px; border-radius: 15px; max-width: 500px;'>
            <h2 style='color: #047857; text-align: center;'>QUANG ANH TECHNOLOGY</h2>
            <hr style='border: 0.5px solid #eee;'>
            <p style='font-size: 16px;'>Chào bạn,</p>
            <p style='font-size: 16px;'>Bạn đang thực hiện đăng ký tài khoản tại hệ thống của chúng tôi.</p>
            <p style='font-size: 16px; font-weight: bold;'>Mã xác minh OTP của bạn là:</p>
            <div style='background: #f0fdf4; padding: 15px; text-align: center; border-radius: 10px;'>
                <span style='font-size: 32px; font-weight: 900; color: #047857; letter-spacing: 10px;'>$otp</span>
            </div>
            <p style='font-size: 12px; color: #666; margin-top: 20px;'>Lưu ý: Mã này chỉ có hiệu lực trong 5 phút. Vui lòng không chia sẻ mã này với người khác.</p>
        </div>
    ";
    
    if (sendQuangAnhMail($email, $subject, $content)) {
        header("Location: verify_otp.php");
        exit();
    } else {
        $error = "Không thể gửi email. Vui lòng kiểm tra lại!";
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Đăng ký tài khoản khách hàng - Quang Anh Tech</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', sans-serif; }
        body { background-color: #f3f4f6; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        
        .container { 
            background-color: white; width: 100%; max-width: 900px; 
            display: flex; border-radius: 20px; overflow: hidden; 
            box-shadow: 0 15px 40px rgba(0,0,0,0.12); 
        }

        /* Cột trái: Ảnh thương hiệu */
        .image-section { 
            flex: 1.2; background: #f8fafc; display: flex; 
            justify-content: center; align-items: center; padding: 40px;
        }
        .image-section img { width: 100%; object-fit: contain; border-radius: 15px; }

        /* Cột phải: Form đăng ký */
        .form-section { flex: 1; padding: 50px 45px; display: flex; flex-direction: column; justify-content: center; }
        
        .company-name { text-align: center; font-size: 13px; font-weight: 800; color: #047857; margin-bottom: 25px; text-transform: uppercase; letter-spacing: 1.5px; }
        .form-title { font-size: 28px; font-weight: 700; margin-bottom: 8px; color: #111827; }
        .form-subtitle { color: #6b7280; font-size: 14px; margin-bottom: 35px; }

        .form-group { margin-bottom: 25px; }
        .form-group label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 10px; color: #374151; }
        
        .input-group { position: relative; }
        .input-group i { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #9ca3af; }
        
        input {
            width: 100%; padding: 14px 15px 14px 45px;
            border: 1.5px solid #e5e7eb; border-radius: 12px; font-size: 15px; outline: none; transition: 0.3s;
        }
        input:focus { border-color: #059669; box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1); }

        .btn-submit {
            width: 100%; padding: 15px;
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
            color: white; border: none; border-radius: 12px;
            font-weight: 700; cursor: pointer; font-size: 16px; margin-top: 10px;
            text-transform: uppercase; transition: 0.3s;
            box-shadow: 0 4px 12px rgba(4, 120, 87, 0.2);
        }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 6px 15px rgba(4, 120, 87, 0.3); }

        .error-alert { background: #fef2f2; color: #dc2626; padding: 12px; border-radius: 10px; margin-bottom: 20px; font-size: 14px; border: 1px solid #fee2e2; }

        .footer-link { text-align: center; margin-top: 30px; font-size: 14px; color: #6b7280; }
        .footer-link a { color: #059669; text-decoration: none; font-weight: 700; }
        .footer-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="container">
    <div class="image-section">
        <img src="QA1.jpg" alt="Quang Anh Tech Logo">
    </div>

    <div class="form-section">
        <div class="company-name">Quang Anh Technology</div>
        <h2 class="form-title">Đăng ký mới</h2>
        <p class="form-subtitle">Nhập địa chỉ Email của bạn để nhận mã xác minh OTP.</p>

        <?php if(isset($error)) echo "<div class='error-alert'><i class='fas fa-exclamation-circle'></i> $error</div>"; ?>

        <form method="POST">
            <div class="form-group">
                <label>Địa chỉ Email *</label>
                <div class="input-group">
                    <i class="fas fa-envelope"></i>
                    <input type="email" name="email" placeholder="vi-du@gmail.com" required>
                </div>
            </div>

            <button type="submit" class="btn-submit">Gửi mã OTP qua Email</button>
        </form>

        <div class="footer-link">
            Bạn đã có tài khoản? <a href="login.php">Đăng nhập</a>
        </div>
    </div>
</div>

</body>
</html>