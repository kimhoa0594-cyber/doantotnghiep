<?php 
session_start(); 
require_once 'db.php';

// Khởi tạo biến giỏ hàng để hiển thị số lượng trên Header
$cart_count = 0;
if(isset($_SESSION['cart'])) { 
    foreach($_SESSION['cart'] as $item) $cart_count += $item['quantity']; 
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Chính sách trả góp - Quang Anh Tech</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    
    <style>
        /* CSS giữ nguyên cho Header giống index.php */
        .nav-header .main-nav a { transition: 0.2s ease; padding-bottom: 4px; }
        .nav-header .main-nav a:hover { color: #facc15 !important; }
        .nav-header .main-nav a.active-menu { color: #facc15 !important; border-bottom: 3px solid #facc15 !important; font-weight: bold; }

        /* --- NÚT LIÊN HỆ NỔI --- */
        .floating-contact { position: fixed; bottom: 30px; right: 20px; display: flex; flex-direction: column; gap: 15px; z-index: 9999; }
        .float-btn { width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; text-decoration: none; box-shadow: 0 4px 10px rgba(0,0,0,0.2); transition: transform 0.2s ease; background-color: white; }
        .float-btn:hover { transform: scale(1.1); }
        .float-btn img { width: 100%; height: 100%; border-radius: 50%; object-fit: cover; }
        .btn-phone { background-color: #0b8a2e; color: white; font-size: 22px; position: relative; }
        .btn-phone::before { content: ''; position: absolute; width: 100%; height: 100%; background-color: #0b8a2e; border-radius: 50%; z-index: -1; opacity: 0.7; animation: pulse-ring 1.5s infinite ease-out; }
        @keyframes pulse-ring { 0% { transform: scale(1); opacity: 0.7; } 100% { transform: scale(1.6); opacity: 0; } }

        /* --- CSS RIÊNG CHO TRANG CHÍNH SÁCH --- */
        .policy-page-wrapper {
            max-width: 1200px;
            margin: 40px auto;
            padding: 40px;
            background-color: #fff;
            border-radius: 12px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            color: #333;
            line-height: 1.6;
        }
        .policy-page-wrapper h1 {
            color: #0b8a2e;
            text-align: center;
            font-size: 28px;
            font-weight: 900;
            margin-bottom: 30px;
            text-transform: uppercase;
        }
        .policy-page-wrapper h3 {
            color: #047857;
            font-size: 18px;
            margin-top: 30px;
            margin-bottom: 15px;
            display: inline-block;
            border-bottom: 2px solid #0b8a2e;
            padding-bottom: 5px;
        }
        .policy-page-wrapper p { margin-bottom: 15px; font-size: 15px; }
        .policy-page-wrapper ul { margin-left: 25px; margin-bottom: 15px; }
        .policy-page-wrapper li { margin-bottom: 10px; font-size: 15px; }
        .policy-page-wrapper .highlight-box {
            background-color: #f0fff4;
            border-left: 4px solid #0b8a2e;
            padding: 15px 20px;
            border-radius: 5px;
            margin: 20px 0;
            font-weight: bold;
            color: #065f46;
        }
        .policy-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            margin-bottom: 25px;
        }
        .policy-table th, .policy-table td {
            border: 1px solid #e5e7eb;
            padding: 12px;
            text-align: left;
        }
        .policy-table th { background-color: #f9fafb; color: #111; font-weight: bold; }
        /* --- BREADCRUMB --- */
        .breadcrumb {
            max-width: 1200px;
            margin: 20px auto 10px auto; /* Chỉnh lại khoảng cách trên dưới cho cân đối */
            font-size: 14px; /* Thu nhỏ chữ lại cho giống form mẫu */
            color: #666; /* Màu chữ xám nhạt tinh tế hơn */
            padding: 0 40px;
        }
        .breadcrumb a {
            color: #0b8a2e; /* Màu xanh chủ đạo */
            font-weight: 600; /* In đậm vừa phải */
            text-decoration: none; /* Bỏ đường gạch chân xấu xí mặc định */
        }
        .breadcrumb a:hover {
            color: #047857; /* Đậm hơn một chút khi di chuột vào */
            text-decoration: none;
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
                <a href="login.php" style="background:var(--main-green); color:white; padding: 5px 15px; border-radius:15px; font-weight:bold; text-decoration:none;"><i class="fas fa-sign-in-alt"></i> Đăng nhập / Đăng ký</a>
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
            <a href="cart.php"><i class="fas fa-shopping-cart"></i> Giỏ hàng</a>
        </div>
        <div class="search-bar">
            <input type="text" placeholder="Nhập tên sản phẩm...">
            <button class="search-btn"><i class="fas fa-search"></i></button>
        </div>
        <div class="header-icons">
            <div class="header-icon"><i class="fas fa-file-invoice"></i><span>Xây dựng cấu hình</span></div>
            <div class="header-icon"><i class="fas fa-heart"></i><span class="count">0</span><span>Yêu thích</span></div>
            <div class="header-icon" onclick="window.location.href='cart.php'" style="cursor:pointer;">
                <i class="fas fa-shopping-cart"></i>
                <span class="count"><?php echo $cart_count; ?></span>
                <span>Giỏ hàng</span>
            </div>
        </div>
    </header>

    <nav class="nav-header">
        <div class="all-categories-btn"><i class="fas fa-bars"></i> TẤT CẢ DANH MỤC</div>
        <div class="main-nav">
            <a href="index.php">TRANG CHỦ</a>
            <a href="index.php?category=pc_gaming_intel">PC GAMING</a>
            <a href="index.php?category=laptop">LAPTOP</a>
            <a href="index.php?category=linh_kien">LINH KIỆN PC</a>
            <a href="#">BLOG</a>
        </div>
    </nav>
    <div class="breadcrumb">
        <a href="index.php">Trang chủ</a> / CHÍNH SÁCH TRẢ GÓP
    </div>

    <div class="policy-page-wrapper">
        <h1><i class="fas fa-dollar-sign"></i> CHÍNH SÁCH TRẢ GÓP TẠI QUANG ANH TECH</h1>
        
        <div class="highlight-box">
            Quang Anh Tech hỗ trợ khách hàng mua sắm dễ dàng hơn với nhiều hình thức trả góp linh hoạt, lãi suất ưu đãi lên đến 0%. Thủ tục xét duyệt nhanh chóng, nhận máy ngay trong ngày!
        </div>

        <h3>1. Trả góp qua thẻ tín dụng (Lãi suất 0%)</h3>
        <p>Áp dụng cho khách hàng đang sở hữu thẻ tín dụng (Credit Card) của hơn 25 ngân hàng liên kết (Vietcombank, Techcombank, VPBank, Sacombank, BIDV...).</p>
        <ul>
            <li><strong>Lãi suất:</strong> 0% trong suốt kỳ hạn trả góp.</li>
            <li><strong>Kỳ hạn:</strong> 3, 6, 9, 12 tháng.</li>
            <li><strong>Thủ tục:</strong> Không cần duyệt hồ sơ, không cần chứng minh thu nhập. Chỉ cần quẹt thẻ qua máy mPOS trực tiếp tại cửa hàng hoặc thanh toán online.</li>
            <li><strong>Phí chuyển đổi:</strong> Có thu một khoản phí nhỏ chuyển đổi trả góp (tùy thuộc vào từng ngân hàng và kỳ hạn). Nhân viên sẽ báo trước phí này cho quý khách.</li>
        </ul>

        <h3>2. Trả góp qua Công ty tài chính (HD Saison, Home Credit, Mcredit)</h3>
        <p>Dành cho khách hàng chưa có thẻ tín dụng, áp dụng với các sản phẩm có giá trị từ 3.000.000 VNĐ trở lên.</p>
        <table class="policy-table">
            <thead>
                <tr>
                    <th>Điều kiện cơ bản</th>
                    <th>Chi tiết yêu cầu</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Độ tuổi</td>
                    <td>Công dân Việt Nam từ đủ 18 đến 60 tuổi.</td>
                </tr>
                <tr>
                    <td>Giấy tờ bắt buộc</td>
                    <td>Căn cước công dân (CCCD) gắn chip còn thời hạn sử dụng.</td>
                </tr>
                <tr>
                    <td>Giấy tờ bổ sung (nếu có)</td>
                    <td>Bằng lái xe hoặc Sổ hộ khẩu (giúp tăng tỷ lệ duyệt và hưởng lãi suất thấp hơn).</td>
                </tr>
                <tr>
                    <td>Mức trả trước</td>
                    <td>Từ 10% đến 70% giá trị sản phẩm.</td>
                </tr>
                <tr>
                    <td>Thời gian xét duyệt</td>
                    <td>Nhanh chóng chỉ từ 15 - 30 phút.</td>
                </tr>
            </tbody>
        </table>

        <h3>3. Quy trình mua hàng trả góp</h3>
        <ul>
            <li><strong>Bước 1:</strong> Khách hàng chọn sản phẩm ưng ý tại website hoặc trực tiếp tại Showroom Quang Anh Tech.</li>
            <li><strong>Bước 2:</strong> Nhận tư vấn từ nhân viên về các gói trả góp phù hợp nhất với điều kiện tài chính.</li>
            <li><strong>Bước 3:</strong> Cung cấp giấy tờ cần thiết (đối với công ty tài chính) hoặc tiến hành quẹt thẻ tín dụng.</li>
            <li><strong>Bước 4:</strong> Chờ hệ thống xét duyệt (khoảng 15 phút).</li>
            <li><strong>Bước 5:</strong> Ký hợp đồng điện tử / giấy tờ, thanh toán khoản trả trước và nhận ngay sản phẩm.</li>
        </ul>

        <h3>4. Những câu hỏi thường gặp</h3>
        <p><strong>- Sinh viên có được mua trả góp không?</strong><br>
        Có. Chỉ cần bạn đủ 18 tuổi và có CCCD gắn chip là có thể làm thủ tục. Tuy nhiên, nếu có thêm thẻ sinh viên hoặc người thân bảo lãnh, tỷ lệ duyệt sẽ cao hơn.</p>
        
        <p><strong>- Tôi có thể trả dứt điểm khoản vay trước hạn được không?</strong><br>
        Được. Bạn có thể thanh lý hợp đồng trước thời hạn. Phí thanh lý trước hạn sẽ phụ thuộc vào quy định của từng công ty tài chính tại thời điểm đó (thường từ 3-5% trên dư nợ còn lại).</p>

        <div style="text-align: center; margin-top: 40px;">
            <p><i>Mọi thắc mắc về quá trình trả góp, vui lòng liên hệ Hotline hoặc Zalo để được hỗ trợ 24/7.</i></p>
            <a href="tel:09xxxxxxxx" style="display: inline-block; background-color: #0b8a2e; color: white; padding: 10px 25px; border-radius: 25px; text-decoration: none; font-weight: bold; margin-top: 10px;"><i class="fas fa-phone-alt"></i> Gọi tư vấn ngay</a>
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