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
    <title>Bảo hành tại nhà - Quang Anh Tech</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        /* --- BREADCRUMB --- */
        .breadcrumb { max-width: 1200px; margin: 20px auto 10px auto; font-size: 14px; color: #666; padding: 0 40px; }
        .breadcrumb a { color: #0b8a2e; font-weight: 600; text-decoration: none; }
        
        /* --- LAYOUT CHÍNH --- */
        .warranty-container { max-width: 1200px; margin: 0 auto 50px auto; background: #fff; padding: 40px; border-radius: 10px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
        .warranty-title { text-align: center; color: #111; font-size: 32px; font-weight: 900; text-transform: uppercase; margin-bottom: 40px; }

        /* --- KHỐI GIỚI THIỆU --- */
        .intro-section { display: flex; align-items: center; gap: 30px; margin-bottom: 50px; background: #f0fff4; padding: 30px; border-radius: 15px; border: 1px solid #d1fae5; }
        .intro-text { flex: 1; }
        .intro-text h2 { color: #0b8a2e; font-size: 28px; font-weight: 900; margin-bottom: 15px; }
        .intro-img { flex: 1; text-align: center; }
        .intro-img i { font-size: 150px; color: #0b8a2e; }

        /* --- PHẠM VI ÁP DỤNG --- */
        .section-header { text-align: center; color: #0b8a2e; font-size: 22px; font-weight: 900; margin: 40px 0 20px 0; }
        .scope-grid { display: flex; gap: 20px; margin-bottom: 20px; }
        .scope-box { flex: 1; border-radius: 15px; overflow: hidden; color: #fff; }
        .scope-box.apply { background: #0b8a2e; }
        .scope-box.not-apply { background: #1f2937; }
        .scope-box-header { text-align: center; padding: 15px; font-weight: 900; text-transform: uppercase; font-size: 18px; border-bottom: 1px solid rgba(255,255,255,0.2); }
        .scope-box-body { padding: 20px; min-height: 120px; }
        .scope-box-body ul { list-style: none; padding: 0; }
        .scope-box-body li { margin-bottom: 10px; display: flex; align-items: flex-start; gap: 10px; font-size: 15px; }

        /* --- ĐIỀU KIỆN --- */
        .condition-banner { background: #0b8a2e; color: #fff; text-align: center; padding: 15px; border-radius: 10px; font-weight: 900; font-size: 18px; margin-bottom: 15px; }
        .condition-content { background: #fff; border: 2px solid #0b8a2e; padding: 20px; border-radius: 10px; margin-bottom: 30px; }
        .note-red { color: #d32f2f; font-style: italic; text-align: center; margin-top: 20px; font-weight: bold; }

        /* --- HỖ TRỢ CƯỚC PHÍ --- */
        .shipping-support { display: flex; align-items: center; gap: 40px; background: #f9fafb; padding: 30px; border-radius: 15px; margin-top: 50px; }
        .shipping-support i { font-size: 80px; color: #0b8a2e; }
        .shipping-support h3 { color: #0b8a2e; font-weight: 900; text-transform: uppercase; margin-bottom: 10px; }

        /* --- CÁC BƯỚC BẢO HÀNH --- */
        .steps-grid { display: flex; justify-content: space-between; gap: 20px; margin-top: 30px; }
        .step-item { flex: 1; text-align: center; }
        .step-icon { width: 100px; height: 100px; background: #f0fff4; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px auto; border: 2px solid #0b8a2e; }
        .step-icon i { font-size: 40px; color: #0b8a2e; }
        .step-item p { font-weight: bold; font-size: 14px; line-height: 1.4; color: #333; }
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
                    <i class="fas fa-user"></i> Xin chào: <?php echo $_SESSION['fullname']; ?> 
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
            <a href="cart.php"><i class="fas fa-shopping-cart"></i> Giỏ hàng (<?php echo $cart_count; ?>)</a>
        </div>
    </header>

    <div class="breadcrumb">
        <a href="index.php">Trang chủ</a> / BẢO HÀNH TẠI NHÀ
    </div>

    <div class="warranty-container">
        <h1 class="warranty-title">BẢO HÀNH TẠI NHÀ 12 THÁNG</h1>

        <div class="intro-section">
            <div class="intro-text">
                <h2>BẢO HÀNH TẬN NƠI<br>TRONG 1 NĂM ĐẦU TIÊN</h2>
                <p>Quang Anh Tech hỗ trợ bảo hành tận nơi tại địa điểm giao hàng/lắp đặt trong 01 năm đầu kể từ ngày mua.</p>
            </div>
            <div class="intro-img">
                <i class="fas fa-user-shield"></i> </div>
        </div>

        <div class="section-header">I. PHẠM VI ÁP DỤNG</div>
        <div class="scope-grid">
            <div class="scope-box apply">
                <div class="scope-box-header">ÁP DỤNG VỚI</div>
                <div class="scope-box-body">
                    <ul>
                        <li><i class="fas fa-check-circle"></i> Áp dụng cho Case và Bộ Case bị lỗi phần cứng do nhà sản xuất.</li>
                        <li><i class="fas fa-check-circle"></i> Chỉ áp dụng với các lỗi về phần cứng.</li>
                    </ul>
                </div>
            </div>
            <div class="scope-box not-apply">
                <div class="scope-box-header">KHÔNG ÁP DỤNG VỚI</div>
                <div class="scope-box-body">
                    <ul>
                        <li><i class="fas fa-times-circle"></i> Linh kiện mua lẻ, Laptop, Máy in, Màn hình...</li>
                        <li><i class="fas fa-times-circle"></i> Thiết bị tiêu hao và sản phẩm Apple.</li>
                        <li><i class="fas fa-times-circle"></i> Các lỗi về phần mềm, Virus.</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="condition-banner">ĐIỀU KIỆN</div>
        <div class="condition-content">
            <p>• Khoảng cách < 20 km tính từ cửa hàng Quang Anh Tech gần nhất.</p>
            <p>• Thời gian mua hàng: Trong vòng 12 tháng kể từ ngày mua hàng.</p>
        </div>

        <p class="note-red">Lưu ý: Không áp dụng cho sản phẩm TMĐT và sản phẩm đặt riêng.</p>

        <div class="shipping-support">
            <div class="intro-text">
                <h3>CHÍNH SÁCH HỖ TRỢ CƯỚC PHÍ GỬI HÀNG BẢO HÀNH ĐỐI VỚI KHÁCH >20KM</h3>
                <p>Quang Anh Tech hỗ trợ cước vận chuyển 01 hoặc 02 chiều cho khách hàng bảo hành ở khoảng cách trên 20km (tính từ cửa hàng gần nhất).</p>
            </div>
            <i class="fas fa-truck-moving"></i>
        </div>

        <div class="section-header">CÁC BƯỚC BẢO HÀNH SẢN PHẨM</div>
        <div class="steps-grid">
            <div class="step-item">
                <div class="step-icon"><i class="fas fa-headset"></i></div>
                <p>Liên hệ với<br>Quang Anh Tech</p>
            </div>
            <div class="step-item">
                <div class="step-icon"><i class="fas fa-clipboard-list"></i></div>
                <p>Lên phương án<br>xử lý bảo hành sản phẩm</p>
            </div>
            <div class="step-item">
                <div class="step-icon"><i class="fas fa-tools"></i></div>
                <p>Xử lý bảo hành<br>và bàn giao cho khách hàng</p>
            </div>
        </div>
    </div>

</body>
</html>