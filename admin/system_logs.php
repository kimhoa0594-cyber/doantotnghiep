<?php
session_start();
require_once '../db.php'; 

// Thiết lập múi giờ Việt Nam để khớp với thời gian thực tế tại VN
date_default_timezone_set('Asia/Ho_Chi_Minh');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

// --- XỬ LÝ BỘ LỌC ---
$search_user = isset($_GET['search_user']) ? $conn->real_escape_string($_GET['search_user']) : '';
$action_filter = isset($_GET['action_filter']) ? $conn->real_escape_string($_GET['action_filter']) : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

$sql = "SELECT * FROM system_logs WHERE 1=1";
if ($search_user) $sql .= " AND user_name LIKE '%$search_user%'";
if ($action_filter) $sql .= " AND action_type = '$action_filter'";
if ($date_from) $sql .= " AND DATE(created_at) >= '$date_from'";
if ($date_to) $sql .= " AND DATE(created_at) <= '$date_to'";
$sql .= " ORDER BY created_at DESC";

$result = $conn->query($sql);

// --- XỬ LÝ XUẤT CSV (BÁO CÁO) ---
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Bao_cao_nhat_ky_'.date('d-m-Y').'.csv');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); 
    fputcsv($output, array('ID', 'Nhân viên', 'Hành động', 'Đối tượng', 'Chi tiết', 'Thời gian'));
    // Lưu ý: Xuất CSV dựa trên kết quả đã lọc
    while ($row_exp = $result->fetch_assoc()) {
        fputcsv($output, array($row_exp['id'], $row_exp['user_name'], $row_exp['action_type'], $row_exp['target_object'], $row_exp['description'], $row_exp['created_at']));
    }
    fclose($output);
    exit();
}

// --- THỐNG KÊ DASHBOARD MINI (Sửa lỗi không cập nhật) ---
$today = date('Y-m-d');
// Query đếm trực tiếp từng loại hành động trong ngày hôm nay
$stats_query = $conn->query("SELECT action_type, COUNT(*) as count FROM system_logs WHERE DATE(created_at) = '$today' GROUP BY action_type");

$counts = ['INSERT' => 0, 'UPDATE' => 0, 'DELETE' => 0, 'LOGIN' => 0];

if ($stats_query) {
    while($s = $stats_query->fetch_assoc()) { 
        $counts[$s['action_type']] = $s['count']; 
    }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Nhật Ký Hoạt Động - QA Tech</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --primary: #10b981; --secondary: #3b82f6; --danger: #ef4444; --warning: #f59e0b; --dark: #0f172a; --light-bg: #f8fafc; --sidebar-width: 280px; }
        body { margin: 0; font-family: 'Inter', sans-serif; background: var(--light-bg); display: flex; color: #1e293b; }
        
        /* SIDEBAR */
        .sidebar { width: var(--sidebar-width); background: var(--dark); height: 100vh; position: fixed; color: white; display: flex; flex-direction: column; }
        .sidebar-brand { padding: 30px; font-size: 22px; font-weight: 800; color: var(--primary); text-align: center; border-bottom: 1px solid #1e293b; }
        .nav-link { padding: 15px 25px; display: flex; align-items: center; color: #94a3b8; text-decoration: none; font-weight: 500; transition: 0.2s; }
        .nav-link:hover, .nav-link.active { background: #1e293b; color: white; border-left: 4px solid var(--primary); }
        .nav-link i { margin-right: 15px; width: 20px; }

        .main { margin-left: var(--sidebar-width); width: calc(100% - var(--sidebar-width)); padding: 40px; box-sizing: border-box; }

        /* DASHBOARD MINI */
        .stat-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px; }
        .stat-box { background: white; padding: 20px; border-radius: 16px; border: 1px solid #e2e8f0; display: flex; align-items: center; gap: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .stat-box i { font-size: 20px; width: 45px; height: 45px; border-radius: 12px; display: flex; align-items: center; justify-content: center; color: white; }
        .stat-val { font-size: 24px; font-weight: 800; display: block; }
        .stat-label { font-size: 11px; color: #64748b; font-weight: 700; text-transform: uppercase; }

        /* BỘ LỌC */
        .filter-card { background: white; padding: 25px; border-radius: 16px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); margin-bottom: 30px; display: flex; gap: 15px; align-items: flex-end; border: 1px solid #e2e8f0; }
        .filter-group { display: flex; flex-direction: column; gap: 5px; flex: 1; }
        .filter-group label { font-size: 11px; font-weight: 800; color: #64748b; }
        .filter-input { padding: 10px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 13px; font-family: inherit; }
        .btn-apply { background: var(--dark); color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer; font-weight: 700; transition: 0.2s; }
        .btn-apply:hover { opacity: 0.9; }
        .btn-export { background: var(--primary); color: white; text-decoration: none; padding: 12px 20px; border-radius: 10px; font-weight: 700; display: flex; align-items: center; gap: 8px; font-size: 14px; }

        /* TABLE */
        .log-container { background: white; border-radius: 20px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 10px 15px rgba(0,0,0,0.05); }
        .log-table { width: 100%; border-collapse: collapse; }
        .log-table th { background: #f8fafc; padding: 15px 20px; text-align: left; font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; border-bottom: 2px solid #f1f5f9; }
        .log-table td { padding: 15px 20px; border-bottom: 1px solid #f1f5f9; font-size: 14px; vertical-align: middle; }
        
        .badge { padding: 5px 10px; border-radius: 6px; font-size: 10px; font-weight: 800; color: white; text-transform: uppercase; }
        .bg-insert { background: var(--primary); }
        .bg-update { background: var(--warning); }
        .bg-delete { background: var(--danger); }
        .bg-login { background: var(--secondary); }
        
        .time-box { line-height: 1.2; }
        .time-date { font-size: 12px; font-weight: 600; color: var(--dark); }
        .time-hour { font-size: 11px; color: #94a3b8; }
    </style>
</head>
<body>

    <div class="sidebar">
        
        <div class="sidebar-brand"><h4 class="fw-bold mb-0 text-white">QA TECH<span class="text-success">ADMIN</span></h4></div>
        <div class="sidebar-nav">
            <a href="index.php" class="nav-link"><i class="fas fa-box"></i>Tổng quan</a>
            
            <a href="activities.php" class="nav-link active"><i class="fas fa-history"></i> Nhật Ký Hoạt Động</a>
        </div>
        <div style="padding: 20px; margin-top: auto;">
            <a href="../logout.php" class="nav-link" style="color: var(--danger);"><i class="fas fa-power-off"></i> Đăng xuất</a>
        </div>
    </div>

    <div class="main">
        <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 30px;">
            <div>
                <h1 style="margin:0; font-size: 30px; font-weight: 800;">Hoạt Động Hệ Thống</h1>
                <p style="color: #64748b; margin-top: 5px;">Kiểm soát lịch sử thay đổi và an toàn dữ liệu</p>
            </div>
            <a href="?export=true&search_user=<?=$search_user?>&action_filter=<?=$action_filter?>&date_from=<?=$date_from?>&date_to=<?=$date_to?>" class="btn-export">
                <i class="fas fa-download"></i> Tải Báo Cáo (CSV)
            </a>
        </div>

        <div class="stat-row">
            <div class="stat-box">
                <i class="fas fa-plus-circle bg-insert"></i>
                <div><span class="stat-val"><?=$counts['INSERT']?></span><span class="stat-label">Thêm mới hôm nay</span></div>
            </div>
            <div class="stat-box">
                <i class="fas fa-edit bg-update"></i>
                <div><span class="stat-val"><?=$counts['UPDATE']?></span><span class="stat-label">Cập nhật hôm nay</span></div>
            </div>
            <div class="stat-box">
                <i class="fas fa-trash-alt bg-delete"></i>
                <div><span class="stat-val"><?=$counts['DELETE']?></span><span class="stat-label">Đã xóa hôm nay</span></div>
            </div>
            <div class="stat-box">
                <i class="fas fa-user-shield bg-login"></i>
                <div><span class="stat-val"><?=$counts['LOGIN']?></span><span class="stat-label">Truy cập hệ thống</span></div>
            </div>
        </div>

        <form method="GET" class="filter-card">
            <div class="filter-group">
                <label>NHÂN VIÊN</label>
                <input type="text" name="search_user" class="filter-input" placeholder="Tên admin..." value="<?=$search_user?>">
            </div>
            <div class="filter-group">
                <label>LOẠI HÀNH ĐỘNG</label>
                <select name="action_filter" class="filter-input">
                    <option value="">Tất cả</option>
                    <option value="INSERT" <?=$action_filter=='INSERT'?'selected':''?>>THÊM MỚI</option>
                    <option value="UPDATE" <?=$action_filter=='UPDATE'?'selected':''?>>CẬP NHẬT</option>
                    <option value="DELETE" <?=$action_filter=='DELETE'?'selected':''?>>XÓA DỮ LIỆU</option>
                    <option value="LOGIN" <?=$action_filter=='LOGIN'?'selected':''?>>ĐĂNG NHẬP</option>
                </select>
            </div>
            <div class="filter-group">
                <label>TỪ NGÀY</label>
                <input type="date" name="date_from" class="filter-input" value="<?=$date_from?>">
            </div>
            <div class="filter-group">
                <label>ĐẾN NGÀY</label>
                <input type="date" name="date_to" class="filter-input" value="<?=$date_to?>">
            </div>
            <button type="submit" class="btn-apply">LỌC DỮ LIỆU</button>
            <a href="activities.php" style="font-size: 12px; color: #94a3b8; text-decoration: none; margin-left: 15px;">Xóa lọc</a>
        </form>

        <div class="log-container">
            <table class="log-table">
                <thead>
                    <tr>
                        <th style="width: 130px;">Thời gian</th>
                        <th>Nhân viên</th>
                        <th>Hành động</th>
                        <th>Đối tượng</th>
                        <th>Nội dung chi tiết</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($result && $result->num_rows > 0): while($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <div class="time-box">
                                <div class="time-date"><?=date('d/m/Y', strtotime($row['created_at']))?></div>
                                <div class="time-hour"><?=date('H:i:s', strtotime($row['created_at']))?></div>
                            </div>
                        </td>
                        <td><strong style="color: var(--dark);"><?=htmlspecialchars($row['user_name'])?></strong></td>
                        <td><span class="badge bg-<?=strtolower($row['action_type'])?>"><?=$row['action_type']?></span></td>
                        <td><span style="font-weight: 600; color: var(--secondary);"><?=$row['target_object']?></span></td>
                        <td style="color: #64748b; font-size: 13px;"><?=htmlspecialchars($row['description'])?></td>
                    </tr>
                    <?php endwhile; else: ?>
                    <tr><td colspan="5" style="text-align: center; padding: 40px; color: #94a3b8;">Không có dữ liệu nhật ký phù hợp.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>