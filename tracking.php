<?php
require_once 'db.php';

$maPhieu = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$error = null;
$phieu = null;
$lich_su = null;

if ($maPhieu > 0) {
    // Lấy thông tin phiếu sửa chữa
    $phieu = $conn->query("SELECT * FROM phieu_sua_chua WHERE maPhieu = $maPhieu")->fetch_assoc();
    
    if ($phieu) {
        // Lấy thông tin khách hàng
        $khach = $conn->query("SELECT tenKH, soDienThoai FROM khach_hang WHERE maKH = " . $phieu['maKH'])->fetch_assoc();
        
        // Lấy lịch sử cập nhật trạng thái
        $lich_su = $conn->query("SELECT * FROM lich_su_sua_chua WHERE maPhieu = $maPhieu ORDER BY thoiGian ASC");
        
        // Lấy ảnh minh họa (nếu có)
        $anh = $conn->query("SELECT * FROM anh_phieu_sua WHERE maPhieu = $maPhieu AND loaiAnh = 'sau' LIMIT 1");
        $anh_sau = $anh->fetch_assoc();
    } else {
        $error = "Không tìm thấy phiếu sửa chữa #SC-$maPhieu";
    }
} else {
    $error = "Mã phiếu không hợp lệ";
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Tra cứu tiến độ sửa chữa | Quang Anh Tech</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 20px;
        }
        .tracking-card {
            max-width: 550px;
            margin: 0 auto;
            background: white;
            border-radius: 24px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #10b981, #059669);
            padding: 25px 20px;
            text-align: center;
            color: white;
        }
        .header h1 {
            font-size: 22px;
            margin-bottom: 5px;
        }
        .header p {
            font-size: 13px;
            opacity: 0.9;
        }
        .status-badge {
            display: inline-block;
            padding: 6px 16px;
            border-radius: 30px;
            font-size: 13px;
            font-weight: bold;
        }
        .status-processing { background: #fef3c7; color: #92400e; }
        .status-done { background: #d1fae5; color: #065f46; }
        .status-waiting { background: #fee2e2; color: #991b1b; }
        .status-pending { background: #e2e8f0; color: #475569; }
        .timeline {
            position: relative;
            padding-left: 30px;
            margin: 20px 0;
        }
        .timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 5px;
            bottom: 5px;
            width: 2px;
            background: #e2e8f0;
        }
        .timeline-item {
            position: relative;
            margin-bottom: 20px;
        }
        .timeline-dot {
            position: absolute;
            left: -30px;
            top: 2px;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            background: #cbd5e1;
            border: 2px solid white;
            box-shadow: 0 0 0 2px #cbd5e1;
        }
        .timeline-dot.completed {
            background: #10b981;
            box-shadow: 0 0 0 2px #10b981;
        }
        .timeline-dot.current {
            background: #f59e0b;
            box-shadow: 0 0 0 2px #f59e0b;
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse {
            0% { box-shadow: 0 0 0 2px #f59e0b; }
            50% { box-shadow: 0 0 0 6px rgba(245,158,11,0.3); }
            100% { box-shadow: 0 0 0 2px #f59e0b; }
        }
        .timeline-content {
            background: #f8fafc;
            padding: 10px 14px;
            border-radius: 12px;
        }
        .timeline-time {
            font-size: 11px;
            color: #94a3b8;
        }
        .info-row {
            display: flex;
            padding: 10px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .info-label {
            width: 100px;
            font-weight: 600;
            color: #475569;
        }
        .info-value {
            flex: 1;
            color: #1e293b;
        }
        .contact-btn {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }
        .btn-call {
            flex: 1;
            background: #10b981;
            color: white;
            border: none;
            padding: 12px;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            text-align: center;
        }
        .btn-zalo {
            flex: 1;
            background: #0068ff;
            color: white;
            border: none;
            padding: 12px;
            border-radius: 12px;
            font-weight: 600;
            text-decoration: none;
            text-align: center;
        }
        .footer {
            background: #f8fafc;
            padding: 15px;
            text-align: center;
            font-size: 11px;
            color: #94a3b8;
        }
        .error-card {
            text-align: center;
            padding: 40px;
        }
    </style>
</head>
<body>
    <div class="tracking-card">
        <div class="header">
            <i class="fas fa-tools fa-2x mb-2"></i>
            <h1>QUANG ANH TECH</h1>
            <p>Tra cứu tiến độ sửa chữa</p>
        </div>
        
        <div class="p-4">
            <?php if ($error): ?>
                <div class="error-card">
                    <i class="fas fa-search fa-4x text-muted mb-3 d-block"></i>
                    <h5 class="text-danger"><?= htmlspecialchars($error) ?></h5>
                    <p class="text-muted mt-3">Vui lòng kiểm tra lại mã phiếu trên phiếu sửa chữa của bạn.</p>
                    <a href="index.php" class="btn btn-success mt-2">Về trang chủ</a>
                </div>
            <?php elseif ($phieu): ?>
                <!-- Mã phiếu -->
                <div class="text-center mb-3">
                    <span class="badge bg-dark px-3 py-2">Mã phiếu: SC-<?= $maPhieu ?></span>
                </div>
                
                <!-- Thông tin cơ bản -->
                <div class="bg-light rounded p-3 mb-4">
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-user me-2"></i> Khách hàng</div>
                        <div class="info-value"><?= htmlspecialchars($khach['tenKH'] ?? '—') ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-laptop me-2"></i> Thiết bị</div>
                        <div class="info-value"><?= htmlspecialchars($phieu['tenThietBi'] ?? '—') ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-calendar me-2"></i> Ngày nhận</div>
                        <div class="info-value"><?= date('d/m/Y', strtotime($phieu['ngayNhan'])) ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-calendar-check me-2"></i> Ngày hẹn trả</div>
                        <div class="info-value"><?= !empty($phieu['ngayTra']) ? date('d/m/Y', strtotime($phieu['ngayTra'])) : 'Chưa xác định' ?></div>
                    </div>
                    <div class="info-row">
                        <div class="info-label"><i class="fas fa-chart-line me-2"></i> Trạng thái</div>
                        <div class="info-value">
                            <?php
                            $status_class = 'status-pending';
                            if ($phieu['trangThai'] == 'Đang xử lý' || $phieu['trangThai'] == 'Đang kiểm tra') $status_class = 'status-processing';
                            if ($phieu['trangThai'] == 'Đã bàn giao' || $phieu['trangThai'] == 'Đã sửa xong') $status_class = 'status-done';
                            if ($phieu['trangThai'] == 'Chờ linh kiện') $status_class = 'status-waiting';
                            ?>
                            <span class="status-badge <?= $status_class ?>"><?= htmlspecialchars($phieu['trangThai']) ?></span>
                        </div>
                    </div>
                </div>
                
                <!-- Timeline lịch sử -->
                <h6 class="fw-bold mb-3"><i class="fas fa-history text-info me-2"></i>LỊCH SỬ XỬ LÝ</h6>
                <div class="timeline">
                    <?php 
                    $trang_thai_list = ['Tiếp nhận', 'Đang kiểm tra', 'Đang xử lý', 'Chờ linh kiện', 'Đã sửa xong', 'Đã bàn giao'];
                    $current_status = $phieu['trangThai'];
                    $completed_statuses = [];
                    
                    // Lấy danh sách trạng thái đã hoàn thành từ log
                    $log_statuses = [];
                    if ($lich_su && $lich_su->num_rows > 0) {
                        while ($log = $lich_su->fetch_assoc()) {
                            $log_statuses[] = $log['trangThaiMoi'];
                        }
                    }
                    ?>
                    
                    <?php foreach ($trang_thai_list as $index => $status): 
                        $is_completed = in_array($status, $log_statuses);
                        $is_current = ($status == $current_status);
                        $dot_class = 'completed';
                        if ($is_current) $dot_class = 'current';
                        elseif (!$is_completed && !$is_current) $dot_class = '';
                    ?>
                    <div class="timeline-item">
                        <div class="timeline-dot <?= $dot_class ?>"></div>
                        <div class="timeline-content">
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="fw-semibold <?= ($is_completed || $is_current) ? 'text-success' : 'text-muted' ?>">
                                    <?php if ($is_completed): ?>
                                        <i class="fas fa-check-circle text-success me-1"></i>
                                    <?php elseif ($is_current): ?>
                                        <i class="fas fa-spinner fa-pulse me-1"></i>
                                    <?php else: ?>
                                        <i class="far fa-circle me-1"></i>
                                    <?php endif ?>
                                    <?= $status ?>
                                </span>
                                <?php if ($is_completed): ?>
                                    <span class="timeline-time">
                                        <?php
                                        // Tìm thời gian hoàn thành từ log
                                        $time_log = $conn->query("SELECT thoiGian FROM lich_su_sua_chua WHERE maPhieu = $maPhieu AND trangThaiMoi = '$status' ORDER BY thoiGian DESC LIMIT 1");
                                        if ($time_log && $time_log->num_rows > 0) {
                                            $t = $time_log->fetch_assoc();
                                            echo date('H:i d/m', strtotime($t['thoiGian']));
                                        }
                                        ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Mô tả lỗi -->
                <?php if (!empty($phieu['moTaLoi'])): ?>
                <div class="mt-3 p-3 bg-warning bg-opacity-10 rounded">
                    <small class="text-muted d-block mb-1">📝 GHI CHÚ TỪ KỸ THUẬT VIÊN</small>
                    <p class="mb-0"><?= nl2br(htmlspecialchars($phieu['moTaLoi'])) ?></p>
                </div>
                <?php endif; ?>
                
                <!-- Nút liên hệ -->
                <div class="contact-btn">
                    <a href="tel:0934322199" class="btn-call">
                        <i class="fas fa-phone-alt me-2"></i> Gọi ngay
                    </a>
                    <a href="https://zalo.me/0934322199" class="btn-zalo" target="_blank">
                        <i class="fab fa-zalo me-2"></i> Chat Zalo
                    </a>
                </div>
                
            <?php endif; ?>
        </div>
        
        <div class="footer">
            <i class="fas fa-shield-alt me-1"></i> Máy Tính Quang Anh - Uy tín tạo niềm tin<br>
            CS1: 57 Nguyễn Bình, Hải Phòng | ☎ 0982.459.566
        </div>
    </div>
</body>
</html>