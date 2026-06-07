<?php 
session_start(); 
require_once 'db.php';

$cart_count = 0;
if(isset($_SESSION['cart'])) { 
    foreach($_SESSION['cart'] as $item) $cart_count += $item['quantity']; 
}

// Xử lý khi người dùng ấn nút Gửi form
$success_msg = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Ở đây sau này bạn có thể viết code lưu vào Database hoặc gửi Email
    // Tạm thời hiển thị thông báo thành công
    $success_msg = "Cảm ơn bạn đã gửi ý kiến! Chúng tôi sẽ tiếp nhận và phản hồi lại trong thời gian sớm nhất.";
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Góp ý khách hàng - Quang Anh Tech</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        /* --- HEADER & NAV --- */
        .nav-header .main-nav a { transition: 0.2s ease; padding-bottom: 4px; }
        .nav-header .main-nav a:hover { color: #facc15 !important; }
        .nav-header .main-nav a.active-menu { color: #facc15 !important; border-bottom: 3px solid #facc15 !important; font-weight: bold; }

        /* --- BREADCRUMB --- */
        .breadcrumb { max-width: 1200px; margin: 20px auto 10px auto; font-size: 14px; color: #666; padding: 0 40px; }
        .breadcrumb a { color: #0b8a2e; font-weight: 600; text-decoration: none; }
        .breadcrumb a:hover { color: #047857; text-decoration: underline; }

        /* --- NÚT LIÊN HỆ NỔI --- */
        .floating-contact { position: fixed; bottom: 30px; right: 20px; display: flex; flex-direction: column; gap: 15px; z-index: 9999; }
        .float-btn { width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; text-decoration: none; box-shadow: 0 4px 10px rgba(0,0,0,0.2); transition: transform 0.2s ease; background-color: white; }
        .float-btn:hover { transform: scale(1.1); }
        .float-btn img { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; }
        .btn-phone { background-color: #0b8a2e; color: white; font-size: 22px; position: relative; }
        .btn-phone::before { content: ''; position: absolute; width: 100%; height: 100%; background-color: #0b8a2e; border-radius: 50%; z-index: -1; opacity: 0.7; animation: pulse-ring 1.5s infinite ease-out; }
        @keyframes pulse-ring { 0% { transform: scale(1); opacity: 0.7; } 100% { transform: scale(1.6); opacity: 0; } }

        /* --- FORM GÓP Ý --- */
        .feedback-container {
            max-width: 650px;
            margin: 0 auto 50px auto;
            background: #fff;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            color: #333;
        }
        .feedback-title {
            text-align: center;
            font-size: 24px;
            font-weight: 800;
            margin-bottom: 5px;
            text-transform: uppercase;
            color: #222;
        }
        .feedback-subtitle {
            text-align: center;
            font-size: 15px;
            color: #555;
            margin-bottom: 30px;
        }
        .feedback-subtitle span {
            color: #e50000;
            font-weight: bold;
        }
        
        .form-group { margin-bottom: 20px; }
        .form-group label {
            display: block;
            font-weight: 700;
            margin-bottom: 8px;
            font-size: 14px;
        }
        .form-group label span { color: red; }
        
        .form-control {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 15px;
            outline: none;
            transition: border-color 0.2s;
            box-sizing: border-box;
        }
        .form-control:focus {
            border-color: #0b8a2e;
        }
        textarea.form-control {
            resize: vertical;
            height: 120px;
        }

        .btn-submit-container {
            text-align: center;
            margin-top: 30px;
        }
        .btn-submit {
            background-color: #00a651;
            color: white;
            border: none;
            padding: 12px 60px;
            border-radius: 5px;
            font-size: 18px;
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .btn-submit:hover {
            background-color: #008f45;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            padding: 15px;
            border: 1px solid #c3e6cb;
            border-radius: 5px;
            margin-bottom: 25px;
            text-align: center;
            font-weight: bold;
        }
    </style>
</head>
<body style="background-color: #f3f4f6;">

    <div class="top-bar">
        <div class="info-list">
            <span class="info-item"><i class="fas fa-eye"></i> Xem tại cửa hàng</span>
            <span class="info-item"><i class="fas fa-phone-alt"></i> Liên hệ</span>
            <span class="info-item"><i class="fas fa-headset"></i> HOTLINE: 09xx.xxx.xxx</span>
        </div>
        <div class="auth-links">
            <?php if(isset($_SESSION['user_id'])): ?>
                <a href="order_history.php" style="text-decoration: underline; color: #0b8a2e; font-weight: bold;">
                    <i class="fas fa-user"></i> Xin chào: <?php echo isset($_SESSION['fullname']) ? $_SESSION['fullname'] : 'Khách'; ?> 
                </a>
                <a href="logout.php" style="color: #ef4444; margin-left: 15px;"><i class="fas fa-sign-out-alt"></i> Đăng xuất</a>
            <?php else: ?>
                <a href="login.php" style="background:var(--main-green); color:white; padding: 5px 15px; border-radius:15px; font-weight:bold; text-decoration:none;"><i class="fas fa-sign-in-alt"></i> Đăng nhập</a>
            <?php endif; ?>
        </div>
    </div>

    <header class="main-header">
        <a href="index.php" class="logo"><span style="color: #0b8a2e; font-size: 28px; font-weight: 900; letter-spacing: -1px;">QUANG ANH TECH</span></a>
        <div class="header-right">
            <?php if(isset($_SESSION['user_id'])): ?>
                <div class="user-logged-in" style="display: flex; align-items: center; gap: 15px;">
                    <a href="profile.php" style="color: white; text-decoration: none;"><i class="fas fa-user-circle"></i> Chào, <?php echo isset($_SESSION['fullname']) ? $_SESSION['fullname'] : 'Khách'; ?></a>
                    <a href="logout.php" class="btn-logout" style="background: #ff4d4d; padding: 5px 10px; border-radius: 4px; color: white;">Thoát</a>
                </div>
            <?php else: ?>
                <a href="login.php"><i class="fas fa-user"></i> Đăng nhập</a>
                <a href="register.php"><i class="fas fa-user-plus"></i> Đăng ký</a>
            <?php endif; ?>
            <a href="cart.php"><i class="fas fa-shopping-cart"></i> Giỏ hàng (<?php echo $cart_count; ?>)</a>
        </div>
    </header>

    <div class="breadcrumb">
        <a href="index.php">Trang chủ</a> / GỬI Ý KIẾN PHẢN HỒI
    </div>

    <div class="feedback-container">
        <div class="feedback-title">GỬI Ý KIẾN, PHẢN HỒI</div>
        <div class="feedback-subtitle">
            HOTLINE: <span>1900 5392</span><br>
            (thời gian làm việc từ 8h30 - 18h00 hàng ngày)
        </div>

        <?php if($success_msg != ''): ?>
            <div class="alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $success_msg; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label>Họ và tên <span>(*)</span></label>
                <input type="text" name="hoten" class="form-control" required placeholder="Nhập họ và tên của bạn">
            </div>

            <div class="form-group">
                <label>Số điện thoại <span>(*)</span></label>
                <input type="tel" name="sdt" class="form-control" required placeholder="Nhập số điện thoại liên hệ">
            </div>

            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" class="form-control" placeholder="Nhập email (không bắt buộc)">
            </div>

            <div class="form-group">
                <label>Chi nhánh cửa hàng</label>
                <select name="chinhanh" class="form-control">
                    <option value="Hải Phòng">Hải Phòng: Số 57 Nguyễn Bình, Hải Phòng</option>
                    <option value="Hải Phòng">Hải Phòng: Số 81 Quán Nam, Hải Phòng</option>
                    <option value="Online">Mua hàng Online / Website</option>
                </select>
            </div>

            <div class="form-group">
                <label>Ý kiến, phản hồi của bạn <span>(*)</span></label>
                <textarea name="noidung" class="form-control" required placeholder="Nhập nội dung ý kiến hoặc vấn đề bạn đang gặp phải..."></textarea>
            </div>

            <div class="btn-submit-container">
                <button type="submit" class="btn-submit">Gửi</button>
            </div>
        </form>
    </div>

    <div class="floating-contact">
        <a href="https://m.me/your_facebook_page_id" target="_blank" class="float-btn btn-messenger" title="Chat Messenger">
            <img src="https://upload.wikimedia.org/wikipedia/commons/b/be/Facebook_Messenger_logo_2020.svg" alt="Messenger">
        </a>
        <a href="https://zalo.me/09xxxxxxxx" target="_blank" class="float-btn btn-zalo" title="Chat Zalo">
            <img src="https://cdn.haitrieu.com/wp-content/uploads/2022/01/Logo-Zalo-App-Rec.png" alt="Zalo" style="padding: 2px;">
        </a>
        <a href="tel:09xxxxxxxx" class="float-btn btn-phone" title="Gọi ngay">
            <i class="fas fa-phone-alt"></i>
        </a>
    </div>

</body>
</html>