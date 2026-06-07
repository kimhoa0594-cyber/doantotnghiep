<?php 
session_start(); 
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Chính sách đổi trả hàng 3 ngày - Quang Anh Tech</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    
    <style>
        /* CSS dùng chung cho Header/Nav (Giữ nguyên của hệ thống) */
        .nav-header .main-nav a { transition: 0.2s ease; padding-bottom: 4px; }
        .nav-header .main-nav a:hover { color: #facc15 !important; }

        /* =========================================
           CSS RIÊNG CHO TRANG CHÍNH SÁCH ĐỔI TRẢ
           ========================================= */
        .policy-page {
            max-width: 1200px;
            margin: 0 auto 50px auto;
            padding: 20px;
            font-family: 'Arial', sans-serif;
            color: #333;
            background-color: #fff;
        }

        .breadcrumb { font-size: 14px; margin-bottom: 30px; color: #666; }
        .breadcrumb a { color: #0b8a2e; text-decoration: none; font-weight: bold; }

        .page-title {
            text-align: center;
            font-size: 32px;
            font-weight: 900;
            color: #333;
            margin-bottom: 50px;
            text-transform: uppercase;
        }

        /* Phần 1: Giới thiệu */
        .policy-intro {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 60px;
            gap: 30px;
        }
        .policy-intro-text h2 {
            font-size: 36px;
            color: #126c2d;
            font-weight: 900;
            line-height: 1.2;
            margin-bottom: 20px;
        }
        .policy-intro-text p { font-size: 16px; font-weight: bold; line-height: 1.5; margin-bottom: 20px; }
        .policy-intro-text .note {
            font-style: italic;
            font-size: 15px;
            border-top: 5px solid #126c2d;
            padding-top: 15px;
            display: inline-block;
        }
        .policy-intro-img { flex: 1; text-align: center; }
        .policy-intro-img i { font-size: 180px; color: #126c2d; } /* Placeholder ảnh */

        /* Tiêu đề các mục */
        .section-heading {
            text-align: center;
            font-size: 28px;
            font-weight: 900;
            color: #126c2d;
            margin-bottom: 30px;
            text-transform: uppercase;
        }

        /* Phần 2: Phạm vi áp dụng */
        .scope-section {
            display: flex;
            gap: 40px;
            margin-bottom: 60px;
            align-items: center;
        }
        .scope-boxes {
            flex: 1;
            border: 2px solid #126c2d;
            border-radius: 15px;
            overflow: hidden;
        }
        .scope-box-title {
            background-color: #126c2d;
            color: white;
            text-align: center;
            padding: 15px;
            font-size: 22px;
            font-weight: bold;
        }
        .scope-box-content { padding: 20px; font-size: 16px; line-height: 1.6; }
        
        .scope-img { flex: 1; text-align: center; }
        .scope-img i { font-size: 150px; color: #555; margin: 0 10px; }

        /* Phần 3: Điều kiện & Khấu trừ */
        .conditions-section {
            border: 2px solid #126c2d;
            border-radius: 15px;
            overflow: hidden;
            margin-bottom: 60px;
        }
        .cond-header {
            background-color: #126c2d;
            color: white;
            text-align: center;
            padding: 15px;
            font-size: 22px;
            font-weight: bold;
        }
        .cond-list {
            display: flex;
            gap: 30px;
            padding: 20px;
            background-color: #f9fdfa;
        }
        .cond-list ul { flex: 1; list-style-type: none; padding: 0; margin: 0; }
        .cond-list ul li {
            margin-bottom: 15px;
            font-size: 16px;
            line-height: 1.5;
            position: relative;
            padding-left: 15px;
        }
        .cond-list ul li::before {
            content: '-'; position: absolute; left: 0; font-weight: bold;
        }

        /* Bảng khấu trừ */
        .deduction-table { width: 100%; border-collapse: collapse; }
        .deduction-table th {
            background-color: #126c2d;
            color: white;
            padding: 15px;
            font-size: 18px;
            text-align: center;
            border: 1px solid #126c2d;
        }
        .deduction-table td {
            padding: 15px;
            text-align: center;
            font-size: 16px;
            border: 1px solid #126c2d;
            background-color: #fff;
        }

        /* Phần 4: Quy trình & Thanh toán */
        .process-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 50px;
            padding: 0 20px;
        }
        .process-content { flex: 1; }
        .process-content ul { list-style: none; padding: 0; }
        .process-content ul li {
            font-size: 17px; font-weight: bold; color: #126c2d; margin-bottom: 15px; line-height: 1.5;
        }
        .process-content ul ul { padding-left: 20px; margin-top: 10px; }
        .process-content ul ul li { color: #333; font-weight: normal; font-size: 16px; }
        .process-content ul ul li::before { content: '- '; }
        
        .contact-note {
            font-style: italic;
            color: #88c79c;
            margin-top: 20px;
            font-size: 16px;
        }
        
        .process-icon { flex: 0.5; text-align: center; }
        .process-icon i { font-size: 160px; color: #3b82f6; } /* Icon xe bus xanh dương */
    </style>
</head>
<body>

    <div class="top-bar">
        <div class="info-list">
            <span class="info-item"><i class="fas fa-eye"></i> Xem tại cửa hàng</span>
            <span class="info-item"><i class="fas fa-phone-alt"></i> Liên hệ</span>
            <span class="info-item"><i class="fas fa-headset"></i> HOTLINE: 09xx.xxx.xxx</span>
        </div>
        <div class="auth-links">
            <a href="index.php" style="color:white; text-decoration:none;"><i class="fas fa-arrow-left"></i> Quay lại trang chủ</a>
        </div>
    </div>

    <header class="main-header">
        <a href="index.php" class="logo"><span style="color: #0b8a2e; font-size: 28px; font-weight: 900; letter-spacing: -1px;">QUANG ANH TECH</span></a>
        </header>
    <main class="policy-page">
        <div class="breadcrumb">
            <a href="index.php">Trang chủ</a> / CHÍNH SÁCH ĐỔI TRẢ HÀNG 3 NGÀY
        </div>

        <h1 class="page-title">CHÍNH SÁCH ĐỔI TRẢ HÀNG 3 NGÀY</h1>

        <div class="policy-intro">
            <div class="policy-intro-text">
                <h2>DÙNG THỬ 3 NGÀY<br>KHÔNG ƯNG HOÀN TIỀN</h2>
                <p>Quang Anh Tech nhận lại sản phẩm và hoàn tiền 100%<br>giá trị sản phẩm trong vòng 03 ngày kể từ ngày mua hàng.</p>
                <div class="note">
                    *Thời gian hoàn tiền được thực hiện từ thứ<br>
                    hai đến thứ sáu (trừ ngày nghỉ lễ, Tết theo<br>
                    quy định của Nhà nước).
                </div>
            </div>
            <div class="policy-intro-img">
                <i class="fas fa-people-carry"></i> 
            </div>
        </div>

        <h2 class="section-heading">I. PHẠM VI ÁP DỤNG</h2>
        <div class="scope-section">
            <div class="scope-boxes">
                <div class="scope-box-title">ÁP DỤNG VỚI</div>
                <div class="scope-box-content">
                    - Case và bộ CASE khi mua tại Quang Anh Tech
                </div>
                <div class="scope-box-title" style="border-top: 2px solid white;">KHÔNG ÁP DỤNG VỚI</div>
                <div class="scope-box-content">
                    - Linh kiện mua lẻ, Laptop, máy in, máy fax, máy chiếu, các thiết bị tiêu hao, các sản phẩm Apple<br>
                    - Khách hàng mua bằng phương thức trả góp
                </div>
            </div>
            <div class="scope-img">
                <i class="fas fa-desktop"></i>
                <i class="fas fa-keyboard"></i>
            </div>
        </div>

        <div class="conditions-section">
            <div class="cond-header">ĐIỀU KIỆN</div>
            <div class="cond-list">
                <ul>
                    <li>Chỉ tiếp nhận xử lý tại các cửa hàng của Quang Anh Tech</li>
                    <li>Thời gian mua hàng: 03 ngày kể từ ngày mua hàng</li>
                </ul>
                <ul>
                    <li>Sản phẩm còn đầy đủ các vỏ hộp, sách hướng dẫn, phiếu bảo hành, hóa đơn và các phụ kiện kèm theo</li>
                    <li>Sản phẩm không bị móp méo, xước vỡ và nằm trong các tình trạng không được bảo hành, hoạt động bình thường</li>
                </ul>
            </div>
            
            <table class="deduction-table">
                <thead>
                    <tr>
                        <th style="width: 50%;">TRƯỜNG HỢP</th>
                        <th style="width: 50%; border-left: 1px solid white;">MỨC KHẤU TRỪ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Mất vỏ hộp</td>
                        <td>25% giá trị sản phẩm</td>
                    </tr>
                    <tr>
                        <td>Từ ngày thứ 4 tới ngày 15</td>
                        <td>25% giá trị sản phẩm</td>
                    </tr>
                    <tr>
                        <td>Sau 15 ngày hoặc thiếu hộp/phụ kiện</td>
                        <td>Theo thỏa thuận</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h2 class="section-heading">QUY TRÌNH & THANH TOÁN</h2>
        <div class="process-section">
            <div class="process-content">
                <ul>
                    <li>* Khách hàng vui lòng mang sản phẩm đến chi nhánh nơi mua hàng.</li>
                    <li>* Đối với Khách tỉnh xa:
                        <ul>
                            <li>Gửi hàng qua nhà xe</li>
                            <li>Phát sinh phí hỗ trợ xử lý: 120.000đ + phí vận chuyển</li>
                            <li>Nếu lúc mua được miễn phí ship qua nhà xe thì lúc trả sẽ thu lại chi phí này</li>
                            <li>Hoàn tiền trong tối đa 08 giờ làm việc sau khi kiểm tra & xác nhận đầy đủ</li>
                        </ul>
                    </li>
                </ul>
                <div class="contact-note">Mọi chi tiết xin vui lòng liên hệ hotline để được giải đáp!</div>
            </div>
            <div class="process-icon">
                 <i class="fas fa-bus-alt"></i>
            </div>
        </div>

    </main>
</body>
</html>