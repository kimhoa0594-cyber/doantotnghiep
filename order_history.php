<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php"); exit();
}

$user_id = $_SESSION['user_id'];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Lịch sử mua hàng - Quang Anh Tech</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
        body { background-color: #f4f6f8; padding: 40px; }
        .container { max-width: 1000px; margin: 0 auto; background: white; padding: 30px; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; border-bottom: 2px solid #0b8a2e; padding-bottom: 15px; }
        .order-card { border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 20px; position: relative;}
        .order-id { font-weight: bold; color: #64748b; font-size: 14px; margin-bottom: 10px; display: block;}
        .order-items { font-size: 15px; color: #1e293b; line-height: 1.6; }
        .order-status { position: absolute; top: 20px; right: 20px; padding: 6px 15px; border-radius: 50px; font-weight: bold; font-size: 12px;}
        .total { margin-top: 15px; font-size: 18px; font-weight: 800; color: #ef4444; text-align: right;}
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1 style="color:#0b8a2e;"><i class="fas fa-history"></i> Lịch Sử Mua Hàng</h1>
        <a href="index.php" style="text-decoration:none; color:#64748b; font-weight:bold;"><i class="fas fa-arrow-left"></i> Quay lại</a>
    </div>

    <?php
    $res = $conn->query("SELECT * FROM customer_orders WHERE user_id = $user_id ORDER BY id DESC");
    if($res->num_rows > 0):
        while($row = $res->fetch_assoc()):
            $color = '#f59e0b';
            if($row['status'] == 'Đã hoàn thành') $color = '#10b981';
            if($row['status'] == 'Đã hủy') $color = '#ef4444';
    ?>
    <div class="order-card">
        <span class="order-id">Mã đơn hàng: #QA-<?php echo $row['id']; ?> | Ngày đặt: <?php echo date('d/m/Y', strtotime($row['order_date'])); ?></span>
        <span class="order-status" style="background: <?php echo $color; ?>22; color: <?php echo $color; ?>;">
            <i class="fas fa-circle" style="font-size:8px;"></i> <?php echo $row['status']; ?>
        </span>
        <div class="order-items"><?php echo nl2br($row['purchased_items']); ?></div>
        <div class="total">Tổng tiền: <?php echo $row['total_amount']; ?></div>
    </div>
    <?php endwhile; else: ?>
        <p style="text-align:center; padding: 50px; color:#94a3b8;">Bạn chưa có đơn hàng nào.</p>
    <?php endif; ?>
</div>
</body>
</html>