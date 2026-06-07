<?php
session_start();
require_once '../db.php'; 

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

// --- XỬ LÝ TÌM KIẾM, LỌC & SẮP XẾP ---
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$filter_rank = isset($_GET['filter_rank']) ? $conn->real_escape_string($_GET['filter_rank']) : '';
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'max_id';
$order = isset($_GET['order']) ? $_GET['order'] : 'DESC';

$sql = "SELECT 
            MAX(id) as max_id,
            full_name, username, phone, email, address, rank,
            COUNT(id) as auto_total_orders, 
            SUM(service_count) as auto_service_count,
            GROUP_CONCAT(purchase_history SEPARATOR '\n') as combined_purchase,
            GROUP_CONCAT(service_history SEPARATOR '\n') as combined_service,
            MAX(created_at) as latest_date
        FROM customers 
        WHERE 1=1";

if ($search != '') {
    $sql .= " AND (full_name LIKE '%$search%' OR phone LIKE '%$search%' OR email LIKE '%$search%' OR address LIKE '%$search%' OR id LIKE '%$search%')";
}
if ($filter_rank != '') {
    $sql .= " AND rank = '$filter_rank'";
}

$sql .= " GROUP BY phone"; 

$sort_column = ($sort_by == 'full_name') ? 'full_name' : (($sort_by == 'rank') ? 'rank' : (($sort_by == 'latest_date') ? 'latest_date' : 'max_id'));
$sql .= " ORDER BY $sort_column $order";

$result = $conn->query($sql);

// --- LOGIC XÓA (NÂNG CẤP CHI TIẾT TỐI ĐA) ---
if (isset($_GET['delete_id'])) {
    $id = (int)$_GET['delete_id'];
    $admin_user = isset($_SESSION['username']) ? $_SESSION['username'] : 'Admin';

    // 1. Lấy toàn bộ thông tin khách hàng trước khi xóa
    $check_cus = $conn->query("SELECT * FROM customers WHERE id = $id");
    $c = $check_cus->fetch_assoc();
    
    if ($c) {
        // 2. Tạo chuỗi nội dung cực kỳ chi tiết
        $detail_log = "Đã xóa khách hàng: " . $c['full_name'] . " | " .
                      "SĐT: " . ($c['phone'] ? $c['phone'] : 'N/A') . " | " .
                      "Email: " . ($c['email'] ? $c['email'] : 'N/A') . " | " .
                      "Đ/C: " . ($c['address'] ? $c['address'] : 'N/A') . " | " .
                      "Loại: " . $c['rank'] . " (ID gốc #" . $id . ")";
        
        // 3. Ghi log vào system_logs
        $stmt_log = $conn->prepare("INSERT INTO system_logs (user_name, action_type, target_object, description) VALUES (?, 'DELETE', 'Khách hàng', ?)");
        $stmt_log->bind_param("ss", $admin_user, $detail_log);
        $stmt_log->execute();

        // 4. Xóa khách hàng khỏi database
        $conn->query("DELETE FROM customers WHERE id = $id");
    }

    header("Location: customers.php");
    exit();
}

// --- LOGIC LƯU (CŨNG CẬP NHẬT CHI TIẾT) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save_customer'])) {
    $id = $_POST['customer_id'];
    $full_name = $conn->real_escape_string($_POST['full_name']);
    $username = $conn->real_escape_string($_POST['username']);
    $phone = $conn->real_escape_string($_POST['phone']);
    $email = $conn->real_escape_string($_POST['email']);
    $address = $conn->real_escape_string($_POST['address']);
    $rank = $conn->real_escape_string($_POST['rank']);
    $purchase_history = $conn->real_escape_string($_POST['purchase_history']);
    $service_history = $conn->real_escape_string($_POST['service_history']); 
    $total_orders = (int)$_POST['total_orders'];
    $service_count = (int)$_POST['service_count'];
    $admin_user = isset($_SESSION['username']) ? $_SESSION['username'] : 'Admin';

    if (!empty($id)) {
        $sql_act = "UPDATE customers SET full_name='$full_name', username='$username', phone='$phone', email='$email', address='$address', rank='$rank', purchase_history='$purchase_history', service_history='$service_history', total_orders=$total_orders, service_count=$service_count WHERE id=$id";
        $log_type = "UPDATE";
        $log_desc = "Cập nhật KH: $full_name | SĐT: $phone | Loại: $rank";
    } else {
        $sql_act = "INSERT INTO customers (full_name, username, phone, email, address, rank, purchase_history, service_history, total_orders, service_count, created_at) VALUES ('$full_name', '$username', '$phone', '$email', '$address', '$rank', '$purchase_history', '$service_history', $total_orders, $service_count, NOW())";
        $log_type = "INSERT";
        $log_desc = "Thêm KH mới: $full_name | SĐT: $phone | Loại: $rank";
    }
    
    if($conn->query($sql_act)) {
        $stmt_log = $conn->prepare("INSERT INTO system_logs (user_name, action_type, target_object, description) VALUES (?, ?, 'Khách hàng', ?)");
        $stmt_log->bind_param("sss", $admin_user, $log_type, $log_desc);
        $stmt_log->execute();
    }

    header("Location: customers.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Quản Lý Khách Hàng - QA Tech</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --sidebar-width: 280px; --primary: #10b981; --secondary: #3b82f6; --dark: #0f172a; --light-bg: #f8fafc; --danger: #ef4444; --warning: #f59e0b; }
        body { margin: 0; font-family: 'Inter', sans-serif; background: var(--light-bg); display: flex; color: #1e293b; }
        .sidebar { width: var(--sidebar-width); background: var(--dark); height: 100vh; position: fixed; color: white; display: flex; flex-direction: column; z-index: 100; }
        .sidebar-brand { padding: 30px; font-size: 22px; font-weight: 800; color: var(--primary); text-align: center; border-bottom: 1px solid #1e293b; }
        .sidebar-nav { flex: 1; padding: 20px 0; }
        .nav-link { padding: 14px 25px; display: flex; align-items: center; color: #94a3b8; text-decoration: none; font-weight: 500; transition: 0.2s; border-left: 4px solid transparent; }
        .nav-link i { margin-right: 15px; width: 20px; font-size: 18px; }
        .nav-link:hover, .nav-link.active { background: #1e293b; color: white; border-left-color: var(--primary); }
        .main { margin-left: var(--sidebar-width); width: calc(100% - var(--sidebar-width)); padding: 40px; box-sizing: border-box; }
        .search-bar { background: white; padding: 20px; border-radius: 15px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); margin-bottom: 30px; border: 1px solid #f1f5f9; display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
        .search-bar .group { display: flex; flex-direction: column; gap: 5px; }
        .search-bar label { font-size: 12px; font-weight: 700; color: #64748b; }
        .search-bar input, .search-bar select { padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 13px; }
        .btn-search { background: var(--dark); color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-weight: 600; }
        .table-container { background: white; border-radius: 20px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.05); overflow: hidden; border: 1px solid #f1f5f9; }
        .c-table { width: 100%; border-collapse: collapse; }
        .c-table th { background: #f8fafc; padding: 18px; text-align: left; font-size: 12px; font-weight: 700; color: #64748b; text-transform: uppercase; }
        .c-table td { padding: 20px 18px; border-bottom: 1px solid #f1f5f9; vertical-align: top; font-size: 13px; }
        .badge { padding: 5px 10px; border-radius: 6px; font-size: 11px; font-weight: 800; }
        .badge-vip { background: #fff7ed; color: #c2410c; border: 1px solid #fdba74; }
        .badge-loyal { background: #f0fdf4; color: #15803d; border: 1px solid #86efac; }
        .badge-new { background: #f1f5f9; color: #475569; }
        .history-tag { background: #f1f5f9; padding: 6px 10px; border-radius: 6px; font-size: 11px; margin-bottom: 5px; border-left: 3px solid var(--secondary); display: block; }
        .service-tag { border-left-color: var(--danger); background: #fef2f2; }
        .btn-add { background: var(--primary); color: white; border: none; padding: 12px 24px; border-radius: 10px; font-weight: 700; cursor: pointer; }
        .modal { display: none; position: fixed; top:0; left:0; width:100%; height:100%; background: rgba(15,23,42,0.6); z-index:1000; justify-content: center; align-items: center; backdrop-filter: blur(4px); }
        .modal-content { background: white; width: 850px; padding: 40px; border-radius: 20px; max-height: 90vh; overflow-y: auto; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 15px; }
        input, select, textarea { width: 100%; padding: 12px; border: 1px solid #e2e8f0; border-radius: 8px; margin-top: 5px; box-sizing: border-box; }
    </style>
</head>
<body>
    <div class="sidebar">
        
        <div class="sidebar-brand"><h4 class="fw-bold mb-0 text-white">QA TECH <span class="text-success">ADMIN</span></h4></div>
        <div class="sidebar-nav">
            <a href="index.php" class="nav-link"><i class="fas fa-th-large"></i>Tổng quan</a>
            <a href="customers.php" class="nav-link active"><i class="fas fa-user-shield"></i>Quản lý khách hàng</a>
            
        </div>
        <div style="padding: 20px; margin-top: auto;">
            <a href="../logout.php" class="nav-link" style="color: var(--danger);"><i class="fas fa-power-off"></i>Đăng xuất</a>
        </div>
    </div>

    <div class="main">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
            <div>
                <h1 style="margin:0; font-size: 30px; font-weight: 800;">Quản Lý Khách Hàng</h1>
                <p style="color: #64748b;">Dữ liệu khách hàng</p>
            </div>
            <button class="btn-add" onclick="openModal()"><i class="fas fa-plus"></i> Thêm Khách Hàng</button>
        </div>

        <form method="GET" class="search-bar">
            <div class="group" style="flex: 2;">
                <label>TÌM KIẾM</label>
                <input type="text" name="search" placeholder="Tên, mã, SĐT, email, địa chỉ..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="group">
                <label>HẠNG</label>
                <select name="filter_rank">
                    <option value="">Tất cả</option>
                    <option value="New" <?= ($filter_rank=='New')?'selected':'' ?>>Mới</option>
                    <option value="Loyal" <?= ($filter_rank=='Loyal')?'selected':'' ?>>Thân thiết</option>
                    <option value="VIP" <?= ($filter_rank=='VIP')?'selected':'' ?>>VIP</option>
                </select>
            </div>
            <div class="group">
                <label>SẮP XẾP</label>
                <select name="sort_by">
                    <option value="max_id" <?= ($sort_by=='max_id')?'selected':'' ?>>Mặc định (ID)</option>
                    <option value="full_name" <?= ($sort_by=='full_name')?'selected':'' ?>>Tên (A-Z)</option>
                    <option value="latest_date" <?= ($sort_by=='latest_date')?'selected':'' ?>>Ngày mới nhất</option>
                </select>
            </div>
            <div class="group">
                <label>THỨ TỰ</label>
                <select name="order">
                    <option value="DESC" <?= ($order=='DESC')?'selected':'' ?>>Giảm dần</option>
                    <option value="ASC" <?= ($order=='ASC')?'selected':'' ?>>Tăng dần</option>
                </select>
            </div>
            <button type="submit" class="btn-search"><i class="fas fa-filter"></i> Lọc</button>
            <a href="customers.php" style="font-size: 12px; color: #64748b; text-decoration: none;">Xóa lọc</a>
        </form>

        <div class="table-container">
            <table class="c-table">
                <thead>
                    <tr>
                        <th>Họ Tên & Tài khoản</th>
                        <th>Liên Hệ & Địa chỉ</th>
                        <th>Hạng & Tổng đơn</th>
                        <th>Lịch Sử Mua Hàng</th>
                        <th>Lịch Sử Bảo Trì & Sửa Chữa</th>
                        <th>Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($result && $result->num_rows > 0): while($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <div style="font-weight: 700;"><?= $row['full_name'] ?></div>
                            <div style="font-size: 11px; color: #64748b;">Mã KH: #<?= $row['max_id'] ?></div>
                            <div style="font-size: 11px; color: #94a3b8;">@<?= $row['username'] ?></div>
                        </td>
                        <td>
                            <div><i class="fas fa-phone-alt"></i> <?= $row['phone'] ?></div>
                            <div style="font-size: 12px; color: #64748b;"><?= $row['email'] ?></div>
                            <div style="font-size: 11px; color: #94a3b8; max-width: 150px;"><?= $row['address'] ?></div>
                        </td>
                        <td>
                            <span class="badge <?= ($row['rank']=='VIP')?'badge-vip':(($row['rank']=='Loyal')?'badge-loyal':'badge-new') ?>"><?= $row['rank'] ?></span>
                            <div style="font-size: 11px; margin-top:5px; font-weight: 700;">Mua: <?= $row['auto_total_orders'] ?> lần</div>
                        </td>
                        <td>
                            <?php $orders = explode("\n", $row['combined_purchase']); foreach($orders as $o) { if(trim($o)) echo "<div class='history-tag'><i class='fas fa-caret-right'></i> ".htmlspecialchars($o)."</div>"; } ?>
                        </td>
                        <td>
                            <div style="color: var(--danger); font-size: 11px; font-weight: 700;">Sửa: <?= $row['auto_service_count'] ?> lần</div>
                            <?php $services = explode("\n", $row['combined_service']); foreach($services as $s) { if(trim($s)) echo "<div class='history-tag service-tag'>".htmlspecialchars($s)."</div>"; } ?>
                        </td>
                        <td>
                            <button onclick='editCustomer(<?= json_encode($row) ?>)' style="color: var(--warning); background:none; border:none; cursor:pointer;"><i class="fas fa-edit"></i></button>
                            <a href="customers.php?delete_id=<?= $row['max_id'] ?>" onclick="return confirm('Xóa khách hàng này?')" style="color: var(--danger); margin-left:10px;"><i class="fas fa-trash-alt"></i></a>
                        </td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="6" style="text-align: center; padding: 30px;">Không tìm thấy khách hàng.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="customerModal" class="modal">
        <div class="modal-content">
            <h2 id="modalTitle">Thông Tin Khách Hàng</h2>
            <form method="POST">
                <input type="hidden" name="customer_id" id="form_id">
                <div class="form-row">
                    <div><label>Họ Tên</label><input type="text" name="full_name" id="form_name" required></div>
                    <div><label>Username</label><input type="text" name="username" id="form_user" required></div>
                </div>
                <div class="form-row">
                    <div><label>Số điện thoại</label><input type="text" name="phone" id="form_phone"></div>
                    <div><label>Email</label><input type="email" name="email" id="form_email"></div>
                </div>
                <div style="margin-bottom: 15px;"><label>Địa chỉ</label><input type="text" name="address" id="form_address"></div>
                <div class="form-row">
                    <div><label>Loại khách hàng</label>
                        <select name="rank" id="form_rank">
                            <option value="New">Khách mới</option>
                            <option value="Loyal">Thân thiết</option>
                            <option value="VIP">VIP</option>
                        </select>
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <div style="flex:1;"><label>Số sản phẩm</label><input type="number" name="total_orders" id="form_total_orders"></div>
                        <div style="flex:1;"><label>Số lần bảo trì / sửa chữa</label><input type="number" name="service_count" id="form_service_count"></div>
                    </div>
                </div>
                <div class="form-row">
                    <div><label>Lịch sử mua</label><textarea name="purchase_history" id="form_purchase" rows="3"></textarea></div>
                    <div><label>Lịch sử bảo trì / sửa chữa</label><textarea name="service_history" id="form_service" rows="3"></textarea></div>
                </div>
                <div style="text-align: right; margin-top: 20px;">
                    <button type="button" onclick="closeModal()" style="padding:10px 20px; border:none; margin-right:10px; border-radius:8px; cursor:pointer;">Đóng</button>
                    <button type="submit" name="save_customer" class="btn-add">Lưu Dữ Liệu</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const modal = document.getElementById('customerModal');
        function openModal() {
            document.getElementById('form_id').value = "";
            document.querySelector('form[method="POST"]').reset();
            modal.style.display = 'flex';
        }
        function editCustomer(data) {
            document.getElementById('form_id').value = data.max_id;
            document.getElementById('form_name').value = data.full_name;
            document.getElementById('form_user').value = data.username;
            document.getElementById('form_phone').value = data.phone;
            document.getElementById('form_email').value = data.email;
            document.getElementById('form_address').value = data.address;
            document.getElementById('form_rank').value = data.rank;
            document.getElementById('form_total_orders').value = data.auto_total_orders;
            document.getElementById('form_service_count').value = data.auto_service_count;
            document.getElementById('form_purchase').value = data.combined_purchase;
            document.getElementById('form_service').value = data.combined_service;
            modal.style.display = 'flex';
        }
        function closeModal() { modal.style.display = 'none'; }
        window.onclick = function(event) { if (event.target == modal) closeModal(); }
    </script>
</body>
</html>