<?php
session_start();
require_once 'db.php';

// Nếu giỏ hàng trống thì không cho vào trang thanh toán, đẩy về trang chủ
if (!isset($_SESSION['cart']) || count($_SESSION['cart']) == 0) {
    header("Location: index.php");
    exit();
}

// --- XỬ LÝ KHI KHÁCH BẤM NÚT ĐẶT HÀNG ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['place_order'])) {
    $name = $conn->real_escape_string($_POST['fullname']);
    $phone = $conn->real_escape_string($_POST['phone']);
    $address = $conn->real_escape_string($_POST['address']);
    $note = $conn->real_escape_string($_POST['note']);

    // 1. Gom các sản phẩm trong giỏ thành một chuỗi văn bản
    $items_str = "";
    $total_amount = 0;
    foreach ($_SESSION['cart'] as $item) {
        $items_str .= $item['quantity'] . "x " . $item['title'] . " (" . $item['price'] . ")\n";
        $total_amount += $item['price_number'] * $item['quantity'];
    }
    
    // Format lại tổng tiền
    $total_formatted = number_format($total_amount, 0, ',', '.') . 'đ';
    
    // 2. Đưa địa chỉ và ghi chú vào cột 'used_services' (Dịch vụ / Ghi chú)
    $services_and_notes = "Giao hàng tới: $address.\nGhi chú: $note";

    // Lấy ID người dùng từ session nếu họ đã đăng nhập
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : "NULL";

    // Lưu vào Database (đã thêm user_id và status mặc định)
    $sql = "INSERT INTO customer_orders (customer_name, phone, purchased_items, used_services, total_amount, user_id, status) 
            VALUES ('$name', '$phone', '$items_str', '$services_and_notes', '$total_formatted', $user_id, 'Chờ xác nhận')";
            
    if ($conn->query($sql) === TRUE) {
        // 4. Đặt hàng thành công -> Xóa sạch giỏ hàng
        unset($_SESSION['cart']);
        
        // Hiện thông báo và chuyển về trang chủ
        echo "<script>
            alert('Đặt hàng thành công! Quang Anh Tech sẽ sớm liên hệ với bạn qua SĐT $phone để xác nhận.');
            window.location='index.php';
        </script>";
        exit();
    } else {
        $error = "Có lỗi xảy ra trong quá trình đặt hàng, vui lòng thử lại!";
    }
}

// Tính toán tổng tiền để hiển thị
$subtotal = 0;
$total_items = 0;
foreach ($_SESSION['cart'] as $item) {
    $subtotal += $item['price_number'] * $item['quantity'];
    $total_items += $item['quantity'];
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Thanh toán - Quang Anh Tech</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
        body { background-color: #f4f6f8; color: #333; }
        
        .header-simple { background: white; padding: 20px 40px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e2e8f0; box-shadow: 0 2px 10px rgba(0,0,0,0.02);}
        .header-simple .logo { color: #0b8a2e; text-decoration: none; font-weight: 900; font-size: 26px; letter-spacing: -1px;}
        .header-simple .back-link { color: #475569; text-decoration: none; font-size: 15px; font-weight: 600; transition: color 0.2s;}
        .header-simple .back-link:hover { color: #0b8a2e; }

        .checkout-container { max-width: 1200px; margin: 40px auto; padding: 0 20px; }
        .checkout-title { font-size: 28px; font-weight: 800; margin-bottom: 30px; color: #1e293b; display: flex; align-items: center; gap: 10px;}
        .checkout-title i { color: #0b8a2e; }
        
        .checkout-layout { display: grid; grid-template-columns: 1.8fr 1fr; gap: 30px; }
        
        /* Form thông tin giao hàng */
        .checkout-form-section { background: white; border-radius: 16px; padding: 35px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); }
        .checkout-form-section h3 { margin-bottom: 25px; font-size: 18px; font-weight: 700; color: #1e293b; border-bottom: 2px solid #f1f5f9; padding-bottom: 15px;}
        
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 14px; color: #475569; }
        .form-group input, .form-group textarea { width: 100%; padding: 14px 16px; border: 1.5px solid #e2e8f0; border-radius: 10px; font-size: 14px; background: #f8fafc; outline: none; transition: 0.3s; }
        .form-group input:focus, .form-group textarea:focus { border-color: #0b8a2e; background: white; box-shadow: 0 0 0 4px rgba(11, 138, 46, 0.1); }
        
        .two-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }

        /* Tóm tắt đơn hàng */
        .order-summary { background: white; padding: 30px; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); height: fit-content; position: sticky; top: 20px;}
        .order-summary h3 { margin-bottom: 25px; font-size: 18px; font-weight: 800; color: #1e293b; }
        
        .cart-item-mini { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px dashed #e2e8f0;}
        .cart-item-mini .item-name { font-size: 14px; font-weight: 600; color: #334155; line-height: 1.4; padding-right: 15px;}
        .cart-item-mini .item-qty { font-size: 12px; color: #64748b; margin-top: 5px; }
        .cart-item-mini .item-price { font-weight: 700; color: #0b8a2e; font-size: 14px; white-space: nowrap;}

        .summary-row { display: flex; justify-content: space-between; margin-bottom: 18px; font-size: 15px; color: #475569;}
        .summary-row.total { font-weight: 900; color: #0f172a; font-size: 20px; border-top: 1px solid #e2e8f0; padding-top: 20px; margin-top: 10px;}
        .summary-row.total .price { color: #ef4444; }
        
        .btn-checkout { display: block; width: 100%; text-align: center; border: none; background: #0b8a2e; color: white; padding: 16px; border-radius: 12px; font-weight: bold; font-size: 16px; margin-top: 25px; transition: all 0.3s; box-shadow: 0 4px 15px rgba(11, 138, 46, 0.2); cursor: pointer;}
        .btn-checkout:hover { background: #047857; transform: translateY(-2px); box-shadow: 0 6px 20px rgba(11, 138, 46, 0.3);}
        
        .alert-error { padding: 12px; background-color: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; border-radius: 8px; margin-bottom: 20px; text-align: center; font-weight: 500;}
    </style>
</head>
<body>
    <div class="header-simple">
        <a href="index.php" class="logo">QUANG ANH TECH</a>
        <a href="cart.php" class="back-link"><i class="fas fa-arrow-left"></i> Quay lại Giỏ hàng</a>
    </div>

    <div class="checkout-container">
        <h1 class="checkout-title"><i class="fas fa-money-check-alt"></i> Thanh Toán Đơn Hàng</h1>
        
        <?php if(isset($error)) echo "<div class='alert-error'>$error</div>"; ?>

        <form method="POST" class="checkout-layout">
            <div class="checkout-form-section">
                <h3>Thông Tin Nhận Hàng</h3>
                
                <div class="two-cols">
                    <div class="form-group">
                        <label>Họ và Tên *</label>
                        <input type="text" name="fullname" placeholder="Nhập họ và tên người nhận" required value="<?php echo isset($_SESSION['full_name']) && $_SESSION['role'] !== 'admin' ? $_SESSION['full_name'] : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label>Số điện thoại *</label>
                        <input type="text" name="phone" placeholder="Nhập số điện thoại liên hệ" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Địa chỉ nhận hàng chi tiết *</label>
                    <input type="text" name="address" placeholder="Ví dụ: Số 123, Đường X, Phường Y, Quận Z..." required>
                </div>

                <div class="form-group">
                    <label>Ghi chú đơn hàng (Tùy chọn)</label>
                    <textarea name="note" rows="3" placeholder="Ghi chú thêm về giờ giao hàng, lắp đặt..."></textarea>
                </div>
                
                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; padding: 15px; border-radius: 10px; margin-top: 10px;">
                    <span style="color: #166534; font-size: 14px; font-weight: 600;"><i class="fas fa-shield-alt"></i> Phương thức thanh toán:</span>
                    <p style="color: #15803d; font-size: 13px; margin-top: 5px;">Thanh toán tiền mặt khi nhận hàng (COD) hoặc Chuyển khoản trực tiếp cho nhân viên giao hàng.</p>
                </div>
            </div>

            <div class="order-summary">
                <h3>Đơn Hàng Của Bạn</h3>
                
                <div style="max-height: 300px; overflow-y: auto; margin-bottom: 20px; padding-right: 10px;">
                    <?php foreach($_SESSION['cart'] as $item): 
                        $item_subtotal = $item['price_number'] * $item['quantity'];
                    ?>
                    <div class="cart-item-mini">
                        <div>
                            <div class="item-name"><?php echo $item['title']; ?></div>
                            <div class="item-qty">Số lượng: <b><?php echo $item['quantity']; ?></b></div>
                        </div>
                        <div class="item-price"><?php echo number_format($item_subtotal, 0, ',', '.') . 'đ'; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="summary-row">
                    <span>Tạm tính (<?php echo $total_items; ?> SP):</span>
                    <span style="font-weight: 600; color: #0f172a;"><?php echo number_format($subtotal, 0, ',', '.') . 'đ'; ?></span>
                </div>
                <div class="summary-row">
                    <span>Phí vận chuyển:</span>
                    <span style="color: #0b8a2e; font-weight: bold;">Miễn phí</span>
                </div>
                <div class="summary-row total">
                    <span>Tổng thanh toán:</span>
                    <span class="price"><?php echo number_format($subtotal, 0, ',', '.') . 'đ'; ?></span>
                </div>
                
                <button type="submit" name="place_order" class="btn-checkout">XÁC NHẬN ĐẶT HÀNG</button>
            </div>
        </form>
    </div>
</body>
</html>