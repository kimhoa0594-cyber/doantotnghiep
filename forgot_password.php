<?php
session_start();
date_default_timezone_set('Asia/Ho_Chi_Minh');
require_once 'db.php';

error_reporting(E_ALL); ini_set('display_errors', 1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once 'vendor/phpmailer/Exception.php';
require_once 'vendor/phpmailer/PHPMailer.php';
require_once 'vendor/phpmailer/SMTP.php';

$step = $_SESSION['forgot_step'] ?? 1;
$success = '';
$error = '';

// ===================== BƯỚC 1: Nhập Email =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_otp') {
    $email = trim($conn->real_escape_string($_POST['email']));

    $sql = "SELECT * FROM users WHERE email='$email' LIMIT 1";
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();

        // Tạo mã OTP 6 chữ số
        $otp = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Lưu OTP vào database (dùng NOW() của MySQL để tránh lệch timezone)
        $conn->query("UPDATE users SET otp_code='$otp', otp_expiry = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE email='$email'");

        // Gửi email OTP qua PHPMailer
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';           // ✅ Đã sửa
            $mail->SMTPAuth   = true;
            $mail->Username   = 'kimhoa0594@gmail.com';
            $mail->Password   = 'twedlojqvgslpyys';         // App Password Gmail
            $mail->SMTPSecure = 'tls';
            $mail->Port       = 587;
            $mail->CharSet    = 'UTF-8';

            $mail->setFrom('kimhoa0594@gmail.com', 'Quang Anh Tech');
            $mail->addAddress($email, $user['fullname'] ?? $user['full_name'] ?? '');

            $mail->isHTML(true);
            $mail->Subject = 'Mã OTP đặt lại mật khẩu - Quang Anh Tech';
            $mail->Body = "
                <div style='font-family:Segoe UI,sans-serif;max-width:500px;margin:auto;padding:30px;border:1px solid #e5e7eb;border-radius:12px;'>
                    <h2 style='color:#047857;text-align:center;'>🔐 Đặt lại mật khẩu</h2>
                    <p>Xin chào <strong>" . htmlspecialchars($user['fullname'] ?? $user['full_name'] ?? $email) . "</strong>,</p>
                    <p>Chúng tôi nhận được yêu cầu đặt lại mật khẩu cho tài khoản của bạn.</p>
                    <p>Mã OTP của bạn là:</p>
                    <div style='text-align:center;margin:20px 0;'>
                        <span style='font-size:36px;font-weight:bold;letter-spacing:10px;color:#047857;background:#ecfdf5;padding:15px 30px;border-radius:10px;display:inline-block;'>$otp</span>
                    </div>
                    <p style='color:#6b7280;font-size:13px;'>Mã này có hiệu lực trong <strong>10 phút</strong>. Không chia sẻ mã này với bất kỳ ai.</p>
                    <hr style='border:none;border-top:1px solid #e5e7eb;margin:20px 0;'>
                    <p style='color:#9ca3af;font-size:12px;text-align:center;'>Công ty Quang Anh Tech</p>
                </div>
            ";

            $mail->send();

            // Lưu vào session để xác thực bước tiếp theo
            $_SESSION['forgot_email'] = $email;
            $_SESSION['forgot_step']  = 2;
            header("Location: forgot_password.php");
            exit();

        } catch (Exception $e) {
            $error = "Không thể gửi email. Lỗi: " . $mail->ErrorInfo;
        }
    } else {
        $error = "Email này chưa được đăng ký trong hệ thống!";
    }
}

// ===================== BƯỚC 2: Xác nhận OTP =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_otp') {
    $otp_input = trim($_POST['otp']);
    $email = $_SESSION['forgot_email'] ?? '';

    $sql = "SELECT * FROM users WHERE email='$email' AND otp_code='$otp_input' AND otp_expiry >= NOW() LIMIT 1";
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        $_SESSION['forgot_step']          = 3;
        $_SESSION['forgot_otp_verified']  = true;
        header("Location: forgot_password.php");
        exit();
    } else {
        $error = "Mã OTP không đúng hoặc đã hết hạn!";
        $step = 2;
    }
}

// ===================== BƯỚC 3: Đặt mật khẩu mới =====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    if (!($_SESSION['forgot_otp_verified'] ?? false)) {
        header("Location: forgot_password.php");
        exit();
    }

    $new_password  = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    $email = $_SESSION['forgot_email'] ?? '';

    if (strlen($new_password) < 6) {
        $error = "Mật khẩu phải có ít nhất 6 ký tự!";
        $step = 3;
    } elseif ($new_password !== $confirm_password) {
        $error = "Mật khẩu xác nhận không khớp!";
        $step = 3;
    } else {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $conn->query("UPDATE users SET password='$hashed', otp_code=NULL, otp_expiry=NULL WHERE email='$email'");

        // Xóa session quên mật khẩu
        unset($_SESSION['forgot_step'], $_SESSION['forgot_email'], $_SESSION['forgot_otp_verified']);

        $success = "Đặt lại mật khẩu thành công! Bạn có thể đăng nhập ngay.";
        $step = 0; // Hiển thị màn hình thành công
    }
}

$step = $_SESSION['forgot_step'] ?? $step;
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Quên mật khẩu - Quang Anh Tech</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', sans-serif; }
        body { background-color: #f3f4f6; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
        .container { background-color: white; width: 100%; max-width: 900px; display: flex; border-radius: 20px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .image-section { flex: 1; display: flex; justify-content: center; align-items: center; background: #e5e7eb; padding: 20px; }
        .image-section img { width: 100%; max-width: 350px; border-radius: 10px; }
        .form-section { flex: 1; padding: 50px 40px; display: flex; flex-direction: column; justify-content: center; }
        .company-name { text-align: center; font-size: 14px; font-weight: bold; color: #111; margin-bottom: 30px; text-transform: uppercase; }
        .form-title { font-size: 24px; font-weight: 700; margin-bottom: 5px; }
        .form-subtitle { color: #6b7280; font-size: 13px; margin-bottom: 25px; line-height: 1.6; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 8px; color: #374151; }
        .form-group input { width: 100%; padding: 12px 15px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; outline: none; transition: border-color 0.2s; }
        .form-group input:focus { border-color: #047857; }
        .password-container { position: relative; width: 100%; }
        .password-container input { padding-right: 40px; }
        .password-container i { position: absolute; right: 15px; top: 50%; transform: translateY(-50%); cursor: pointer; color: #6b7280; }
        .btn-submit { width: 100%; padding: 12px; background-color: #047857; color: white; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; margin-top: 10px; font-size: 15px; }
        .btn-submit:hover { background-color: #065f46; }
        .alert-error { padding: 10px; background-color: #fee2e2; color: #991b1b; border: 1px solid #fecaca; border-radius: 5px; margin-bottom: 15px; font-size: 13px; text-align: center; }
        .alert-success { padding: 10px; background-color: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; border-radius: 5px; margin-bottom: 15px; font-size: 13px; text-align: center; }
        .back-link { text-align: center; margin-top: 20px; font-size: 14px; }
        .back-link a { color: #047857; text-decoration: none; font-weight: 600; }
        .back-link a:hover { text-decoration: underline; }

        /* Bước indicator */
        .steps { display: flex; justify-content: center; gap: 8px; margin-bottom: 30px; }
        .step-dot { width: 10px; height: 10px; border-radius: 50%; background: #d1d5db; transition: background 0.3s; }
        .step-dot.active { background: #047857; }
        .step-dot.done { background: #6ee7b7; }

        /* OTP input */
        .otp-input { letter-spacing: 8px; font-size: 22px; font-weight: bold; text-align: center; }

        /* Resend OTP */
        .resend-row { text-align: center; margin-top: 10px; font-size: 13px; color: #6b7280; }
        .resend-row a { color: #047857; text-decoration: none; font-weight: 600; cursor: pointer; }
        .resend-row a:hover { text-decoration: underline; }

        /* Success screen */
        .success-screen { text-align: center; padding: 20px 0; }
        .success-screen .icon { font-size: 60px; color: #047857; margin-bottom: 20px; }
        .success-screen h3 { font-size: 22px; margin-bottom: 10px; }
        .success-screen p { color: #6b7280; margin-bottom: 25px; }
        .success-screen a { display: inline-block; padding: 12px 30px; background-color: #047857; color: white; border-radius: 8px; text-decoration: none; font-weight: bold; }

        /* Countdown */
        #countdown { font-weight: bold; color: #047857; }
    </style>
</head>
<body>
<div class="container">
    <div class="image-section">
        <img src="QA1.jpg" alt="Logo">
    </div>
    <div class="form-section">
        <div class="company-name">QUANG ANH TECH</div>

        <?php if ($step === 0): ?>
        <!-- ===== THÀNH CÔNG ===== -->
        <div class="success-screen">
            <div class="icon"><i class="fas fa-check-circle"></i></div>
            <h3>Đặt lại mật khẩu thành công!</h3>
            <p>Mật khẩu mới của bạn đã được cập nhật.<br>Hãy đăng nhập lại để tiếp tục.</p>
            <a href="login.php"><i class="fas fa-sign-in-alt"></i> Đăng nhập ngay</a>
        </div>

        <?php elseif ($step === 1): ?>
        <!-- ===== BƯỚC 1: NHẬP EMAIL ===== -->
        <div class="steps">
            <div class="step-dot active"></div>
            <div class="step-dot"></div>
            <div class="step-dot"></div>
        </div>
        <h2 class="form-title">Quên mật khẩu</h2>
        <p class="form-subtitle">Nhập địa chỉ email đã đăng ký. Chúng tôi sẽ gửi mã OTP xác nhận về email của bạn.</p>

        <?php if ($error) echo "<div class='alert-error'>$error</div>"; ?>

        <form method="POST">
            <input type="hidden" name="action" value="send_otp">
            <div class="form-group">
                <label>Địa chỉ Email *</label>
                <input type="email" name="email" required placeholder="Nhập email đã đăng ký" autofocus>
            </div>
            <button type="submit" class="btn-submit"><i class="fas fa-paper-plane"></i> Gửi mã OTP</button>
        </form>

        <div class="back-link">
            <a href="login.php"><i class="fas fa-arrow-left"></i> Quay lại đăng nhập</a>
        </div>

        <?php elseif ($step === 2): ?>
        <!-- ===== BƯỚC 2: NHẬP OTP ===== -->
        <div class="steps">
            <div class="step-dot done"></div>
            <div class="step-dot active"></div>
            <div class="step-dot"></div>
        </div>
        <h2 class="form-title">Xác nhận OTP</h2>
        <p class="form-subtitle">
            Mã OTP gồm 6 chữ số đã được gửi đến email:<br>
            <strong><?= htmlspecialchars($_SESSION['forgot_email'] ?? '') ?></strong><br>
            <small style="color:#9ca3af;">Mã có hiệu lực trong 10 phút.</small>
        </p>

        <?php if ($error) echo "<div class='alert-error'>$error</div>"; ?>

        <form method="POST">
            <input type="hidden" name="action" value="verify_otp">
            <div class="form-group">
                <label>Mã OTP *</label>
                <input type="text" name="otp" class="otp-input" maxlength="6" required
                       placeholder="_ _ _ _ _ _" autocomplete="one-time-code" autofocus
                       oninput="this.value=this.value.replace(/[^0-9]/g,'')">
            </div>
            <button type="submit" class="btn-submit"><i class="fas fa-check"></i> Xác nhận OTP</button>
        </form>

        <div class="resend-row">
            Không nhận được mã?
            <a href="forgot_password.php?resend=1">Gửi lại OTP</a>
            &nbsp;|&nbsp;
            <span id="countdown">10:00</span>
        </div>

        <div class="back-link">
            <a href="forgot_password.php?reset=1"><i class="fas fa-arrow-left"></i> Nhập lại email</a>
        </div>

        <?php elseif ($step === 3): ?>
        <!-- ===== BƯỚC 3: MẬT KHẨU MỚI ===== -->
        <div class="steps">
            <div class="step-dot done"></div>
            <div class="step-dot done"></div>
            <div class="step-dot active"></div>
        </div>
        <h2 class="form-title">Đặt mật khẩu mới</h2>
        <p class="form-subtitle">Tạo mật khẩu mới cho tài khoản của bạn. Mật khẩu phải có ít nhất 6 ký tự.</p>

        <?php if ($error) echo "<div class='alert-error'>$error</div>"; ?>

        <form method="POST">
            <input type="hidden" name="action" value="reset_password">
            <div class="form-group">
                <label>Mật khẩu mới *</label>
                <div class="password-container">
                    <input type="password" name="new_password" id="pwd1" required placeholder="Nhập mật khẩu mới">
                    <i class="fas fa-eye" id="toggle-pwd1"></i>
                </div>
            </div>
            <div class="form-group">
                <label>Xác nhận mật khẩu *</label>
                <div class="password-container">
                    <input type="password" name="confirm_password" id="pwd2" required placeholder="Nhập lại mật khẩu mới">
                    <i class="fas fa-eye" id="toggle-pwd2"></i>
                </div>
            </div>
            <button type="submit" class="btn-submit"><i class="fas fa-lock"></i> Đặt lại mật khẩu</button>
        </form>

        <?php endif; ?>
    </div>
</div>

<?php
// Xử lý resend OTP (redirect về bước 1)
if (isset($_GET['reset'])) {
    unset($_SESSION['forgot_step'], $_SESSION['forgot_email'], $_SESSION['forgot_otp_verified']);
}
// Xử lý gửi lại OTP (xóa bước, giữ email)
if (isset($_GET['resend'])) {
    $email = $_SESSION['forgot_email'] ?? '';
    unset($_SESSION['forgot_step']);
    if ($email) {
        $_POST['action'] = 'send_otp';
        $_POST['email']  = $email;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        // Tự redirect lại để xử lý gửi OTP
        header("Location: forgot_password.php");
        exit();
    }
}
?>

<script>
    // Toggle hiện/ẩn mật khẩu bước 3
    function togglePassword(inputId, iconId) {
        const input = document.getElementById(inputId);
        const icon  = document.getElementById(iconId);
        if (!input) return;
        icon.addEventListener('click', function() {
            if (input.type === 'password') {
                input.type = 'text';
                this.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                this.classList.replace('fa-eye-slash', 'fa-eye');
            }
        });
    }
    togglePassword('pwd1', 'toggle-pwd1');
    togglePassword('pwd2', 'toggle-pwd2');

    // Đếm ngược 10 phút cho bước OTP
    const cdEl = document.getElementById('countdown');
    if (cdEl) {
        let total = 10 * 60;
        const timer = setInterval(() => {
            total--;
            if (total <= 0) { clearInterval(timer); cdEl.textContent = 'Hết hạn'; cdEl.style.color='#dc2626'; return; }
            const m = String(Math.floor(total / 60)).padStart(2, '0');
            const s = String(total % 60).padStart(2, '0');
            cdEl.textContent = m + ':' + s;
        }, 1000);
    }
</script>
</body>
</html>