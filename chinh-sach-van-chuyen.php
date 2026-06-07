<?php 
session_start(); 
require_once 'db.php';

$cart_count = 0;
if(isset($_SESSION['cart'])) { 
    foreach($_SESSION['cart'] as $item) $cart_count += $item['quantity']; 
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Chính sách vận chuyển - Quang Anh Tech</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        /* --- HEADER & NAV (Giữ nguyên như các trang khác) --- */
        .nav-header .main-nav a { transition: 0.2s ease; padding-bottom: 4px; }
        .nav-header .main-nav a:hover { color: #facc15 !important; }
        .nav-header .main-nav a.active-menu { color: #facc15 !important; border-bottom: 3px solid #facc15 !important; font-weight: bold; }

        /* --- BREADCRUMB --- */
        .breadcrumb { max-width: 1200px; margin: 20px auto 10px auto; font-size: 14px; color: #666; padding: 0 40px; }
        .breadcrumb a { color: #0b8a2e; font-weight: 600; text-decoration: none; }
        .breadcrumb a:hover { color: #047857; text-decoration: none; }

        /* --- NÚT LIÊN HỆ NỔI --- */
        .floating-contact { position: fixed; bottom: 30px; right: 20px; display: flex; flex-direction: column; gap: 15px; z-index: 9999; }
        .float-btn { width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; text-decoration: none; box-shadow: 0 4px 10px rgba(0,0,0,0.2); transition: transform 0.2s ease; background-color: white; }
        .float-btn:hover { transform: scale(1.1); }
        .float-btn img { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; }
        .btn-phone { background-color: #0b8a2e; color: white; font-size: 22px; position: relative; }
        .btn-phone::before { content: ''; position: absolute; width: 100%; height: 100%; background-color: #0b8a2e; border-radius: 50%; z-index: -1; opacity: 0.7; animation: pulse-ring 1.5s infinite ease-out; }
        @keyframes pulse-ring { 0% { transform: scale(1); opacity: 0.7; } 100% { transform: scale(1.6); opacity: 0; } }

        /* --- LAYOUT CHÍNH SÁCH VẬN CHUYỂN --- */
        .shipping-container { max-width: 1200px; margin: 0 auto 50px auto; background: #fff; padding: 40px; border-radius: 10px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
        
        .shipping-banner-title { text-align: center; color: #333; font-size: 36px; font-weight: 900; margin-bottom: 40px; text-transform: uppercase; }
        
        /* Banner thiết kế bằng CSS thay cho ảnh */
        .simulated-banner { background: linear-gradient(135deg, #e8f5e9, #c8e6c9); border-radius: 15px; padding: 40px; display: flex; align-items: center; justify-content: space-between; margin-bottom: 50px; overflow: hidden; position: relative; }
        .simulated-banner h1 { font-size: 50px; color: #065f46; font-weight: 900; margin: 0; line-height: 1.1; text-transform: uppercase; z-index: 2;}
        .simulated-banner .banner-icon { font-size: 150px; color: #0b8a2e; opacity: 0.2; position: absolute; right: 50px; top: -20px; z-index: 1;}
        .simulated-banner .banner-icon-front { font-size: 100px; color: #0b8a2e; z-index: 2; }

        .section-title { text-align: center; color: #065f46; font-size: 26px; font-weight: 900; margin: 40px 0 20px 0; text-transform: uppercase; }
        
        /* Khung màu xanh lá đậm */
        .green-box { background-color: #065f46; color: white; border-radius: 20px; padding: 35px; margin-bottom: 25px; display: flex; align-items: center; justify-content: space-between; gap: 30px; box-shadow: 0 10px 20px rgba(6, 95, 70, 0.2); }
        .green-box-content { flex: 1; }
        .green-box-graphic { flex-shrink: 0; display: flex; flex-direction: column; align-items: center; gap: 15px; }
        
        .green-box h3 { font-size: 28px; font-weight: 900; margin-bottom: 10px; line-height: 1.3; }
        .green-box p { font-size: 16px; margin-bottom: 15px; line-height: 1.6; }
        .note-yellow { color: #facc15; font-style: italic; font-weight: 600; font-size: 15px; }
        
        /* Chữ FREE 20KM */
        .free-badge { border: 3px solid #facc15; border-radius: 15px; padding: 15px 30px; text-align: center; box-shadow: 0 0 20px rgba(250, 204, 21, 0.3); }
        .free-badge .text-free { font-size: 32px; font-weight: 900; color: #fff; text-shadow: 2px 2px 0 #111; display: block; line-height: 1;}
        .free-badge .text-20km { font-size: 24px; font-weight: 900; color: #facc15; display: block; }

        /* Danh sách trong khung xanh */
        .green-list { list-style: none; padding: 0; margin: 0; }
        .green-list li { font-size: 16px; margin-bottom: 20px; padding-left: 20px; position: relative; line-height: 1.5; }
        .green-list li::before { content: '-'; position: absolute; left: 0; font-weight: bold; }

        .graphic-icons { font-size: 60px; color: rgba(255,255,255,0.9); display: flex; gap: 20px; background: rgba(0,0,0,0.1); padding: 20px; border-radius: 15px;}
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
        <a href="index.php">Trang chủ</a> / CHÍNH SÁCH VẬN CHUYỂN
    </div>

    <div class="shipping-container">
        
        <h1 class="shipping-banner-title">CHÍNH SÁCH VẬN CHUYỂN</h1>

        <div class="simulated-banner">
            <h1>CHÍNH SÁCH<br>VẬN CHUYỂN</h1>
            <i class="fas fa-map-marked-alt banner-icon"></i>
            <i class="fas fa-motorcycle banner-icon-front"></i>
        </div>

        <h2 class="section-title">I. ĐỐI VỚI BỘ/ CASE MÁY TÍNH</h2>
        
        <div class="green-box">
            <div class="green-box-content">
                <h3>Miễn phí vận chuyển với bán kính 20Km tính từ điểm bán</h3>
                <p class="note-yellow">Lưu ý: Freeship 20km từ chi nhánh. Từ 21km trở lên thu thêm phí phát sinh 10k/km (trên 50 km giao qua đơn vị vận chuyển)</p>
            </div>
            <div class="green-box-graphic">
                <div class="free-badge">
                    <span class="text-free">FREE</span>
                    <span class="text-20km">20KM</span>
                </div>
            </div>
        </div>

        <div class="green-box">
            <div class="green-box-content">
                <p>- Đối với khách hàng ở các tỉnh, chúng tôi áp dụng hình thức gửi hàng qua xe khách đến điểm nhận gần nhất hoặc giao hàng tận nhà qua dịch vụ bưu điện. Nhận hàng thanh toán tiền.</p>
                <p style="margin-top: 25px;">- Khách hàng ở tỉnh khi thanh toán trước 100% giá trị đơn hàng. Công ty sẽ hỗ trợ chi phí vận chuyển khi gửi lên tới 200K cho mỗi Case/Bộ Case.</p>
            </div>
            <div class="green-box-graphic graphic-icons">
                <i class="fas fa-bus-alt" title="Gửi xe khách"></i>
                <i class="fas fa-mail-bulk" title="Gửi bưu điện"></i>
            </div>
        </div>

        <h2 class="section-title" style="margin-top: 50px;">II. ĐỐI VỚI LINH KIỆN LẺ</h2>

        <div class="green-box">
            <div class="green-box-content">
                <ul class="green-list">
                    <li>Giao hàng đi các tỉnh, áp dụng gửi hàng qua bưu điện, hoặc gửi xe khách. Khách hàng thanh toán tiền hàng + chi phí cho bên vận chuyển</li>
                    <li>Linh kiện lẻ giao tối đa 15km, phí tính 6k/km hoặc theo grab</li>
                    <li>Giao hàng bằng đơn vị vận chuyển, Khách hàng thanh toán tiền hàng + chi phí vận chuyển cho đơn</li>
                    <li>Giao linh kiện lẻ, nếu khách yêu cầu lắp đặt tại nhà thì thêm phí lắp đặt 100K (Nếu có cài đặt Win hoặc các phần Khác thì tính thêm phí cài đặt riêng từ 100k trở lên), ngoài tiền ship ra.</li>
                    <li>Đối với khách đã mua có tổng lịch sử giá trị mua hàng từ 100 triệu trở lên thì được free lắp đặt</li>
                </ul>
            </div>
            <div class="green-box-graphic graphic-icons" style="flex-direction: column; background: none;">
                <i class="fas fa-tools" style="font-size: 80px;"></i>
                <i class="fas fa-box-open" style="font-size: 50px;"></i>
            </div>
        </div>

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