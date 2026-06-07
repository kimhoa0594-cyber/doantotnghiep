<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php"); exit();
}

// 1. XỬ LÝ CẬP NHẬT TRẠNG THÁI (MỚI)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_status'])) {
    $order_id = (int)$_POST['order_id'];
    $new_status = $conn->real_escape_string($_POST['status']);
    $conn->query("UPDATE customer_orders SET status = '$new_status' WHERE id = $order_id");
    header("Location: customers.php"); exit();
}

// 2. XUẤT EXCEL (Giữ nguyên)
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    header("Content-Type: application/vnd.ms-excel; charset=utf-8");
    header("Content-Disposition: attachment; filename=Danh_Sach_Don_Hang.xls");
    echo '<meta charset="utf-8"><table border="1"><tr><th>STT</th><th>Khách</th><th>SĐT</th><th>Sản Phẩm</th><th>Tổng Tiền</th><th>Trạng Thái</th></tr>';
    $res = $conn->query("SELECT * FROM customer_orders ORDER BY id DESC");
    $stt = 1;
    while($r = $res->fetch_assoc()){
        echo "<tr><td>".$stt++."</td><td>".$r['customer_name']."</td><td>".$r['phone']."</td><td>".$r['purchased_items']."</td><td>".$r['total_amount']."</td><td>".$r['status']."</td></tr>";
    }
    echo '</table>'; exit();
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Quản trị Đơn hàng - Quang Anh Tech</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { box-sizing: border-box; font-family: 'Segoe UI', sans-serif; }
        body { background-color: #f3f4f6; padding: 20px; }
        .admin-container { max-width: 1300px; margin: 0 auto; background: white; padding: 30px; border-radius: 15px; box-shadow: 0 5px 15px rgba(0,0,0,0.05); }
        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; border-bottom: 2px solid #eee; padding-bottom: 20px;}
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { border: 1px solid #e5e7eb; padding: 12px; text-align: left; }
        th { background: #0b8a2e; color: white; }
        .status-badge { padding: 5px 10px; border-radius: 20px; font-size: 11px; font-weight: bold; }
        .status-cho { background: #fef3c7; color: #92400e; }
        .status-giao { background: #dbeafe; color: #1e40af; }
        .status-xong { background: #dcfce7; color: #166534; }
        .status-huy { background: #fee2e2; color: #991b1b; }
        select { padding: 5px; border-radius: 5px; border: 1px solid #ddd; font-size: 12px; }
        .btn-update { background: #0b8a2e; color: white; border: none; padding: 5px 10px; border-radius: 4px; cursor: pointer; }
    </style>
</head>
<body>
<div class="admin-container">
    <div class="header">
        <h1 style="color:#0b8a2e;"><i class="fas fa-tasks"></i> QUẢN LÝ ĐƠN HÀNG & TRẠNG THÁI</h1>
        <div>
            <a href="customers.php?export=excel" style="background:#10b981; color:white; padding:10px 15px; border-radius:8px; text-decoration:none; font-weight:bold; margin-right:10px;"><i class="fas fa-file-excel"></i> Xuất Excel</a>
            <a href="index.php" style="background:#64748b; color:white; padding:10px 15px; border-radius:8px; text-decoration:none; font-weight:bold;">Về Trang Chủ</a>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>STT</th>
                <th>Khách Hàng</th>
                <th>Sản Phẩm Đã Đặt</th>
                <th>Tổng Tiền</th>
                <th>Trạng Thái Hiện Tại</th>
                <th>Cập Nhật Trạng Thái</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $res = $conn->query("SELECT * FROM customer_orders ORDER BY id DESC");
            $stt = 1;
            while($row = $res->fetch_assoc()):
                $stt_class = '';
                if($row['status'] == 'Chờ xác nhận') $stt_class = 'status-cho';
                elseif($row['status'] == 'Đang giao hàng') $stt_class = 'status-giao';
                elseif($row['status'] == 'Đã hoàn thành') $stt_class = 'status-xong';
                else $stt_class = 'status-huy';
            ?>
            <tr>
                <td><?php echo $stt++; ?></td>
                <td><b><?php echo $row['customer_name']; ?></b><br><small><?php echo $row['phone']; ?></small></td>
                <td><small><?php echo nl2br($row['purchased_items']); ?></small></td>
                <td style="color:red; font-weight:bold;"><?php echo $row['total_amount']; ?></td>
                <td><span class="status-badge <?php echo $stt_class; ?>"><?php echo $row['status']; ?></span></td>
                <td>
                    <form method="POST" style="display:flex; gap:5px;">
                        <input type="hidden" name="order_id" value="<?php echo $row['id']; ?>">
                        <select name="status">
                            <option value="Chờ xác nhận" <?php if($row['status']=='Chờ xác nhận') echo 'selected'; ?>>Chờ xác nhận</option>
                            <option value="Đang giao hàng" <?php if($row['status']=='Đang giao hàng') echo 'selected'; ?>>Đang giao hàng</option>
                            <option value="Đã hoàn thành" <?php if($row['status']=='Đã hoàn thành') echo 'selected'; ?>>Đã hoàn thành</option>
                            <option value="Đã hủy" <?php if($row['status']=='Đã hủy') echo 'selected'; ?>>Đã hủy</option>
                        </select>
                        <button type="submit" name="update_status" class="btn-update">Lưu</button>
                    </form>
                </td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>
</body>
</html>