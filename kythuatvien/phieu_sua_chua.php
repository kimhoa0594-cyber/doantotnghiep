<?php
session_start();
require_once '../db.php';
date_default_timezone_set('Asia/Ho_Chi_Minh');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'technician') {
    header("Location: ../login.php"); exit();
}

$tenKTV = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Kỹ thuật viên';
$username = $_SESSION['username'];

// Tạo bảng nếu chưa có
$conn->query("CREATE TABLE IF NOT EXISTS `phieu_sua_chua` (
    `maPhieu`     INT AUTO_INCREMENT PRIMARY KEY,
    `maKH`        INT DEFAULT NULL,
    `tenKH`       VARCHAR(100) NOT NULL,
    `soDienThoai` VARCHAR(20)  DEFAULT NULL,
    `tenThietBi`  VARCHAR(150) NOT NULL,
    `serial_number` VARCHAR(100) DEFAULT NULL,
    `moTaLoi`     TEXT         DEFAULT NULL,
    `ngayNhan`    DATE         DEFAULT (CURRENT_DATE),
    `ngayTraDuKien` DATE       DEFAULT NULL,
    `ngayTraThucTe` DATE       DEFAULT NULL,
    `chiPhi`      DECIMAL(12,0) DEFAULT 0,
    `chi_phi_goc` DECIMAL(12,0) DEFAULT 0,
    `chiPhiLinhKien` DECIMAL(12,0) DEFAULT 0,
    `chiPhiCong`   DECIMAL(12,0) DEFAULT 0,
    `trangThai`   VARCHAR(50)  DEFAULT 'Chờ xử lý',
    `mucDoUuTien` VARCHAR(20)  DEFAULT 'Bình thường',
    `ghiChu`      TEXT         DEFAULT NULL,
    `kyThuatVien` VARCHAR(100) DEFAULT NULL,
    `created_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Thêm cột chi_phi_goc nếu thiếu
$colCheck = $conn->query("SHOW COLUMNS FROM phieu_sua_chua LIKE 'chi_phi_goc'");
if ($colCheck && $colCheck->num_rows == 0) {
    $conn->query("ALTER TABLE phieu_sua_chua ADD COLUMN `chi_phi_goc` DECIMAL(12,0) DEFAULT 0 AFTER `chiPhi`");
}

// Thêm cột serial_number nếu thiếu
$colCheck = $conn->query("SHOW COLUMNS FROM phieu_sua_chua LIKE 'serial_number'");
if ($colCheck && $colCheck->num_rows == 0) {
    $conn->query("ALTER TABLE phieu_sua_chua ADD COLUMN `serial_number` VARCHAR(100) DEFAULT NULL");
}

// Tạo bảng ảnh phiếu sửa chữa nếu chưa có
$conn->query("CREATE TABLE IF NOT EXISTS `anh_phieu_sua` (
    `maAnh` int(11) NOT NULL AUTO_INCREMENT,
    `maPhieu` int(11) NOT NULL,
    `duongDan` varchar(255) NOT NULL,
    `loaiAnh` varchar(50) DEFAULT 'truoc',
    `moTa` text DEFAULT NULL,
    `nguoiUpload` varchar(100) DEFAULT 'Admin',
    `ngayUpload` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`maAnh`),
    FOREIGN KEY (`maPhieu`) REFERENCES `phieu_sua_chua`(`maPhieu`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Thêm phiếu mới
$msg = ''; $msg_type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['them_phieu'])) {
    $tenKH       = trim($_POST['tenKH'] ?? '');
    $sdt         = trim($_POST['soDienThoai'] ?? '');
    $thietBi     = trim($_POST['tenThietBi'] ?? '');
    $serial      = trim($_POST['serial_number'] ?? '');
    $moTa        = trim($_POST['moTaLoi'] ?? '');
    $ngayNhan    = $_POST['ngayNhan'] ?? date('Y-m-d');
    $ngayTra     = $_POST['ngayTraDuKien'] ?: null;
    $chiPhiLinhKien = (int)($_POST['chiPhiLinhKien'] ?? 0);
    $chiPhiCong  = (int)($_POST['chiPhiCong'] ?? 0);
    $chiPhi      = $chiPhiLinhKien + $chiPhiCong;
    $chi_phi_goc = $chiPhi;
    $trangThai   = $_POST['trangThai'] ?? 'Chờ xử lý';
    $mucDoUuTien = $_POST['mucDoUuTien'] ?? 'Bình thường';
    $ghiChu      = trim($_POST['ghiChu'] ?? '');

    // Tìm hoặc thêm khách hàng
    $maKH = null;
    if ($sdt) {
        $checkTable = $conn->query("SHOW TABLES LIKE 'khach_hang'");
        if ($checkTable && $checkTable->num_rows > 0) {
            $r = $conn->prepare("SELECT maKH FROM khach_hang WHERE soDienThoai = ? LIMIT 1");
            $r->bind_param("s", $sdt); 
            $r->execute();
            $row = $r->get_result()->fetch_assoc();
            if ($row) {
                $maKH = $row['maKH'];
            } else {
                $insertKH = $conn->prepare("INSERT INTO khach_hang (tenKH, soDienThoai) VALUES (?, ?)");
                $insertKH->bind_param("ss", $tenKH, $sdt);
                if ($insertKH->execute()) {
                    $maKH = $insertKH->insert_id;
                }
                $insertKH->close();
            }
            $r->close();
        }
    }

    $stmt = $conn->prepare("INSERT INTO phieu_sua_chua (maKH,tenKH,soDienThoai,tenThietBi,serial_number,moTaLoi,ngayNhan,ngayTraDuKien,chiPhiLinhKien,chiPhiCong,chiPhi,chi_phi_goc,trangThai,mucDoUuTien,ghiChu) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param("isssssssiiiiisss", $maKH,$tenKH,$sdt,$thietBi,$serial,$moTa,$ngayNhan,$ngayTra,$chiPhiLinhKien,$chiPhiCong,$chiPhi,$chi_phi_goc,$trangThai,$mucDoUuTien,$ghiChu);
    if ($stmt->execute()) {
        $msg = "✅ Tạo phiếu sửa chữa thành công!"; $msg_type = "success";
    } else {
        $msg = "❌ Có lỗi xảy ra: " . $stmt->error; $msg_type = "danger";
    }
    $stmt->close();
}

// Cập nhật trạng thái
if (isset($_GET['cap_nhat']) && isset($_GET['tt'])) {
    $id = (int)$_GET['cap_nhat'];
    $tt = $_GET['tt'];
    $stmt = $conn->prepare("UPDATE phieu_sua_chua SET trangThai=?, updated_at=NOW() WHERE maPhieu=?");
    $stmt->bind_param("si", $tt, $id);
    $stmt->execute();
    $stmt->close();
    header("Location: phieu_sua_chua.php?msg=cap_nhat_ok"); exit();
}

// Cập nhật phiếu sửa chữa (KTV cập nhật)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sua_phieu_sua'])) {
    $maPhieu    = (int)$_POST['maPhieu'];
    $tenThietBi = $conn->real_escape_string($_POST['tenThietBi'] ?? '');
    $serial     = $conn->real_escape_string($_POST['serial_number'] ?? '');
    $moTaLoi    = $conn->real_escape_string($_POST['moTaLoi'] ?? '');
    $ngayNhan   = $conn->real_escape_string($_POST['ngayNhan']);
    $ngayTra    = !empty($_POST['ngayTraDuKien']) ? "'" . $conn->real_escape_string($_POST['ngayTraDuKien']) . "'" : 'NULL';
    $chiPhi     = (double)($_POST['chiPhi'] ?? 0);
    $chiPhiLinhKien = (double)($_POST['chiPhiLinhKien'] ?? 0);
    $chiPhiCong = (double)($_POST['chiPhiCong'] ?? 0);
    $trangThai  = $conn->real_escape_string($_POST['trangThai']);
    $mucDoUuTien = $conn->real_escape_string($_POST['mucDoUuTien'] ?? 'Bình thường');
    $ghiChu     = $conn->real_escape_string($_POST['ghiChu'] ?? '');
    
    $conn->query("UPDATE phieu_sua_chua SET 
        tenThietBi='$tenThietBi', 
        serial_number='$serial',
        moTaLoi='$moTaLoi', 
        ngayNhan='$ngayNhan', 
        ngayTraDuKien=$ngayTra, 
        chiPhi=$chiPhi,
        chiPhiLinhKien=$chiPhiLinhKien,
        chiPhiCong=$chiPhiCong,
        trangThai='$trangThai', 
        mucDoUuTien='$mucDoUuTien',
        ghiChu='$ghiChu',
        updated_at=NOW() 
        WHERE maPhieu=$maPhieu");
    
    header("Location: phieu_sua_chua.php?msg=update_ok"); exit();
}

// Cập nhật ngày trả thực tế
if (isset($_GET['tra_thiet_bi']) && isset($_GET['ngay_tra'])) {
    $id = (int)$_GET['tra_thiet_bi'];
    $ngayTra = $_GET['ngay_tra'];
    $stmt = $conn->prepare("UPDATE phieu_sua_chua SET ngayTraThucTe=?, trangThai='Đã bàn giao', updated_at=NOW() WHERE maPhieu=?");
    $stmt->bind_param("si", $ngayTra, $id);
    $stmt->execute();
    $stmt->close();
    header("Location: phieu_sua_chua.php?msg=tra_ok"); exit();
}

// Xóa phiếu
if (isset($_GET['xoa']) && is_numeric($_GET['xoa'])) {
    $id = (int)$_GET['xoa'];
    $stmt = $conn->prepare("DELETE FROM phieu_sua_chua WHERE maPhieu=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
    header("Location: phieu_sua_chua.php?msg=xoa_ok"); exit();
}

// Lấy chi tiết phiếu cho modal (AJAX) - GIỐNG ADMIN
if (isset($_GET['chitiet']) && is_numeric($_GET['chitiet'])) {
    $id = (int)$_GET['chitiet'];
    $stmt = $conn->prepare("SELECT ps.*, kh.loaiKhachHang FROM phieu_sua_chua ps 
        LEFT JOIN khach_hang kh ON ps.maKH = kh.maKH
        WHERE ps.maPhieu=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $chiTietPhieu = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($chiTietPhieu) {
        // Lấy ảnh của phiếu
        $anhArr = [];
        $anhRes = $conn->query("SELECT * FROM anh_phieu_sua WHERE maPhieu={$chiTietPhieu['maPhieu']} LIMIT 20");
        while ($a = $anhRes->fetch_assoc()) $anhArr[] = $a;
        
        // Tính toán thời gian
        $ngayNhanTs  = strtotime($chiTietPhieu['ngayNhan']);
        $ngayTraTs   = $chiTietPhieu['ngayTraDuKien'] ? strtotime($chiTietPhieu['ngayTraDuKien']) : null;
        $homNayTs    = strtotime(date('Y-m-d'));
        $soDaSua     = (int)floor(($homNayTs - $ngayNhanTs) / 86400);
        $soConLai    = $ngayTraTs ? (int)floor(($ngayTraTs - $homNayTs) / 86400) : null;
        $tongNgay    = $ngayTraTs ? (int)floor(($ngayTraTs - $ngayNhanTs) / 86400) : null;
        $pctProgress = ($tongNgay && $tongNgay > 0) ? min(100, max(0, round($soDaSua / $tongNgay * 100))) : null;
        $isQuaHan    = $ngayTraTs && $homNayTs > $ngayTraTs && !in_array($chiTietPhieu['trangThai'], ['Đã sửa xong','Đã bàn giao']);
        $isDone      = in_array($chiTietPhieu['trangThai'], ['Đã sửa xong','Đã bàn giao']);
        
        // Timeline steps
        $allSteps = ['Tiếp nhận','Đang kiểm tra','Đang xử lý','Chờ linh kiện','Đã sửa xong','Đã bàn giao'];
        $stepIdx  = array_search($chiTietPhieu['trangThai'], $allSteps);
        if ($stepIdx === false) $stepIdx = 0;
        $stepIcons = ['📥','🔍','⚙️','📦','✅','🚀'];
        $stepColors = ['#64748b','#3b82f6','#f59e0b','#ef4444','#10b981','#8b5cf6'];
        
        // Nhóm ảnh theo loại
        $anhGroups = [];
        foreach ($anhArr as $a) {
            $g = $a['loaiAnh'] ?? 'khac';
            $anhGroups[$g][] = $a;
        }
        $groupLabels = ['truoc'=>'📸 Trước sửa','sau'=>'✅ Sau sửa','loi'=>'⚠️ Lỗi / Hỏng','linh_kien'=>'🔩 Linh kiện','khac'=>'📎 Khác'];
?>
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body>
<div class="container-fluid p-0">
    <div class="modal-header" style="background:linear-gradient(135deg,#1c1917,#292524,#44403c);padding:20px 24px 16px;border-bottom:none;">
        <div style="display:flex;align-items:center;gap:14px;">
            <div style="width:50px;height:50px;background:linear-gradient(135deg,#fbbf24,#f59e0b);border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:24px;">🔧</div>
            <div>
                <div style="font-size:18px;font-weight:800;color:#fff;">Phiếu sửa chữa <span style="color:#fbbf24;">#SC-<?= str_pad($chiTietPhieu['maPhieu'],5,'0',STR_PAD_LEFT) ?></span></div>
                <div style="font-size:12px;color:rgba(255,255,255,.55);margin-top:3px;">
                    <?= htmlspecialchars($chiTietPhieu['tenThietBi'] ?? '—') ?> &nbsp;·&nbsp; Nhận: <?= date('d/m/Y', $ngayNhanTs) ?>
                    <?php if ($isQuaHan): ?><span style="background:#ef4444;color:#fff;font-size:10px;padding:2px 8px;border-radius:10px;margin-left:8px;">⚠️ QUÁ HẠN</span><?php endif; ?>
                </div>
            </div>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" style="opacity:.6;"></button>
    </div>

    <div class="modal-body p-0">
        <div class="row g-0">
            <!-- Cột trái -->
            <div class="col-lg-6" style="padding:22px 20px 22px 24px;border-right:1px solid #f1f5f9;">
                
                <!-- Timeline -->
                <div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;margin-bottom:12px;display:flex;align-items:center;gap:6px;">
                    <i class="fas fa-route"></i> Tiến trình xử lý
                    <span style="flex:1;height:1px;background:#e2e8f0;"></span>
                </div>
                <div style="position:relative;padding-left:28px;margin-bottom:20px;">
                    <div style="position:absolute;left:10px;top:8px;bottom:8px;width:2px;background:linear-gradient(180deg,<?= $stepColors[$stepIdx] ?>,#e2e8f0);border-radius:2px;"></div>
                    <?php foreach ($allSteps as $si => $step):
                        $isPast    = $si < $stepIdx;
                        $isCurrent = $si === $stepIdx;
                        $dotColor  = $isPast ? '#10b981' : ($isCurrent ? $stepColors[$si] : '#e2e8f0');
                        $textColor = $isPast ? '#10b981' : ($isCurrent ? $stepColors[$si] : '#cbd5e1');
                    ?>
                    <div style="position:relative;display:flex;align-items:center;gap:10px;padding:5px 0;">
                        <div style="position:absolute;left:-22px;width:<?= $isCurrent ? '20px' : '16px' ?>;height:<?= $isCurrent ? '20px' : '16px' ?>;border-radius:50%;background:<?= $dotColor ?>;border:3px solid <?= $isCurrent ? $stepColors[$si] : ($isPast ? '#10b981' : '#e2e8f0') ?>;box-shadow:<?= $isCurrent ? '0 0 0 4px '.($stepColors[$si]).'22' : 'none' ?>;display:flex;align-items:center;justify-content:center;z-index:1;">
                            <?php if ($isPast): ?><span style="color:#fff;font-size:8px;">✓</span><?php endif; ?>
                        </div>
                        <div>
                            <div style="font-size:<?= $isCurrent ? '13px' : '12px' ?>;font-weight:<?= $isCurrent ? '700' : '400' ?>;color:<?= $textColor ?>;">
                                <?= $stepIcons[$si] ?> <?= $step ?>
                                <?php if ($isCurrent): ?><span style="background:<?= $stepColors[$si] ?>;color:#fff;font-size:10px;padding:1px 7px;border-radius:10px;margin-left:6px;">Hiện tại</span><?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Thời gian -->
                <div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;margin-bottom:10px;display:flex;align-items:center;gap:6px;">
                    <i class="fas fa-clock"></i> Thời gian thực hiện
                    <span style="flex:1;height:1px;background:#e2e8f0;"></span>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px;">
                    <div style="background:#f8fafc;border-radius:10px;padding:11px 13px;">
                        <div style="font-size:10px;color:#94a3b8;">Ngày nhận</div>
                        <div style="font-size:15px;font-weight:700;color:#0f172a;"><?= date('d/m/Y', $ngayNhanTs) ?></div>
                    </div>
                    <div style="background:<?= $isQuaHan ? '#fff1f2' : '#f8fafc' ?>;border-radius:10px;padding:11px 13px;">
                        <div style="font-size:10px;color:<?= $isQuaHan ? '#ef4444' : '#94a3b8' ?>;">Ngày trả dự kiến</div>
                        <div style="font-size:15px;font-weight:700;color:<?= $isQuaHan ? '#ef4444' : '#0f172a' ?>;"><?= $ngayTraTs ? date('d/m/Y', $ngayTraTs) : '—' ?></div>
                    </div>
                    <div style="background:#f0fdf4;border-radius:10px;padding:11px 13px;">
                        <div style="font-size:10px;color:#94a3b8;">Đã sửa được</div>
                        <div style="font-size:15px;font-weight:700;color:#059669;"><?= $soDaSua ?> ngày</div>
                    </div>
                    <div style="background:<?= $soConLai !== null && $soConLai < 0 ? '#fff1f2' : '#f8fafc' ?>;border-radius:10px;padding:11px 13px;">
                        <div style="font-size:10px;color:#94a3b8;"><?= $isDone ? 'Kết quả' : 'Còn lại' ?></div>
                        <div style="font-size:15px;font-weight:700;color:<?= $isDone ? '#059669' : ($soConLai === null ? '#94a3b8' : ($soConLai < 0 ? '#ef4444' : '#0f172a')) ?>;">
                            <?php if ($isDone): ?>✅ Hoàn thành
                            <?php elseif ($soConLai === null): ?>—
                            <?php elseif ($soConLai < 0): ?>Quá <?= abs($soConLai) ?> ngày
                            <?php elseif ($soConLai === 0): ?>Hôm nay!
                            <?php else: ?><?= $soConLai ?> ngày
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Thanh tiến độ -->
                <?php if ($pctProgress !== null && !$isDone): ?>
                <div style="margin-bottom:14px;">
                    <div style="display:flex;justify-content:space-between;font-size:11px;color:#64748b;margin-bottom:5px;">
                        <span>Tiến độ thời gian</span>
                        <span style="font-weight:700;color:<?= $isQuaHan ? '#ef4444' : '#f59e0b' ?>;"><?= $pctProgress ?>%</span>
                    </div>
                    <div style="background:#e2e8f0;border-radius:20px;height:8px;overflow:hidden;">
                        <div style="width:<?= min(100,$pctProgress) ?>%;height:100%;border-radius:20px;background:<?= $isQuaHan ? 'linear-gradient(90deg,#ef4444,#dc2626)' : ($pctProgress > 80 ? 'linear-gradient(90deg,#f59e0b,#d97706)' : 'linear-gradient(90deg,#10b981,#059669)') ?>;"></div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Chi phí -->
                <div style="background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:1.5px solid #86efac;border-radius:12px;padding:14px;margin-bottom:14px;">
                    <div style="font-size:11px;color:#166534;font-weight:700;text-transform:uppercase;margin-bottom:8px;">💰 Chi phí sửa chữa</div>
                    <?php if (isset($chiTietPhieu['chi_phi_goc']) && $chiTietPhieu['chi_phi_goc'] > 0 && $chiTietPhieu['chi_phi_goc'] != $chiTietPhieu['chiPhi']): ?>
                    <div style="font-size:12px;color:#64748b;text-decoration:line-through;margin-bottom:2px;">Gốc: <?= number_format($chiTietPhieu['chi_phi_goc'],0,',','.') ?> đ</div>
                    <div style="font-size:10px;color:#ef4444;margin-bottom:4px;">– Voucher giảm: <?= number_format($chiTietPhieu['chi_phi_goc'] - $chiTietPhieu['chiPhi'],0,',','.') ?> đ</div>
                    <?php endif; ?>
                    <div style="font-size:22px;font-weight:800;color:#059669;"><?= $chiTietPhieu['chiPhi'] > 0 ? number_format($chiTietPhieu['chiPhi'],0,',','.').' đ' : '<span style="color:#94a3b8;font-size:15px;">Chưa xác định</span>' ?></div>
                    <div style="display:flex;gap:12px;margin-top:8px;font-size:11px;color:#047857;">
                        <span>🔧 Công: <?= number_format($chiTietPhieu['chiPhiCong'] ?? 0,0,',','.') ?>đ</span>
                        <span>⚙️ Linh kiện: <?= number_format($chiTietPhieu['chiPhiLinhKien'] ?? 0,0,',','.') ?>đ</span>
                    </div>
                </div>

                <!-- Mô tả lỗi -->
                <div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                    <i class="fas fa-clipboard-list"></i> Mô tả lỗi / Ghi chú kỹ thuật
                    <span style="flex:1;height:1px;background:#e2e8f0;"></span>
                </div>
                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:13px;white-space:pre-wrap;font-size:13px;line-height:1.7;color:#374151;min-height:80px;"><?= $chiTietPhieu['moTaLoi'] ? nl2br(htmlspecialchars($chiTietPhieu['moTaLoi'])) : '<span style="color:#cbd5e1;">Không có mô tả</span>' ?></div>
                
                <?php if (!empty($chiTietPhieu['ghiChu'])): ?>
                <div style="margin-top:12px;">
                    <div style="font-size:11px;font-weight:700;color:#94a3b8;margin-bottom:5px;"><i class="fas fa-sticky-note"></i> Ghi chú nội bộ</div>
                    <div style="background:#fef3c7;border:1px solid #fde68a;border-radius:10px;padding:10px;font-size:12px;color:#92400e;"><?= nl2br(htmlspecialchars($chiTietPhieu['ghiChu'])) ?></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Cột phải: Ảnh -->
            <div class="col-lg-6" style="padding:22px 24px 22px 20px;background:#fafafa;">
                <div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;margin-bottom:12px;display:flex;align-items:center;gap:6px;">
                    <i class="fas fa-images"></i> Ảnh thiết bị (<?= count($anhArr) ?>)
                    <span style="flex:1;height:1px;background:#e2e8f0;"></span>
                </div>

                <?php if (empty($anhArr)): ?>
                <div style="border:2px dashed #e2e8f0;border-radius:12px;padding:40px 20px;text-align:center;">
                    <div style="font-size:36px;margin-bottom:10px;">📷</div>
                    <div style="color:#94a3b8;font-size:13px;">Chưa có ảnh đính kèm</div>
                </div>
                <?php else: ?>
                <?php foreach ($anhGroups as $gKey => $gAnhs): ?>
                <div style="margin-bottom:14px;">
                    <div style="font-size:11px;font-weight:600;color:#64748b;margin-bottom:7px;"><?= $groupLabels[$gKey] ?? ucfirst($gKey) ?> (<?= count($gAnhs) ?>)</div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(80px,1fr));gap:6px;">
                        <?php foreach ($gAnhs as $anh): ?>
                        <div style="position:relative;aspect-ratio:1;border-radius:10px;overflow:hidden;border:2px solid #e2e8f0;cursor:pointer;" onclick="showBigImgModal('../<?= htmlspecialchars($anh['duongDan']) ?>')">
                            <img src="../<?= htmlspecialchars($anh['duongDan']) ?>" style="width:100%;height:100%;object-fit:cover;display:block;">
                            <?php if (!empty($anh['moTa'])): ?>
                            <div style="position:absolute;bottom:0;left:0;right:0;background:linear-gradient(transparent,rgba(0,0,0,.6));padding:4px 5px;font-size:9px;color:#fff;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;"><?= htmlspecialchars($anh['moTa']) ?></div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; endif; ?>

                <!-- Thông tin thêm -->
                <div style="margin-top:auto;background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:12px;font-size:12px;color:#64748b;margin-top:16px;">
                    <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                        <span>Mã phiếu</span>
                        <strong style="color:#f59e0b;">#SC-<?= str_pad($chiTietPhieu['maPhieu'],5,'0',STR_PAD_LEFT) ?></strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                        <span>Khách hàng</span>
                        <strong><?= htmlspecialchars($chiTietPhieu['tenKH'] ?? '—') ?></strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                        <span>SĐT</span>
                        <strong><?= htmlspecialchars($chiTietPhieu['soDienThoai'] ?? '—') ?></strong>
                    </div>
                    <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                        <span>Mức độ ưu tiên</span>
                        <strong><?= htmlspecialchars($chiTietPhieu['mucDoUuTien'] ?? 'Bình thường') ?></strong>
                    </div>
                    <div style="height:1px;background:#f1f5f9;margin:8px 0;"></div>
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <span><i class="fas fa-user-cog me-1" style="color:#3b82f6;"></i>Kỹ thuật viên</span>
                        <?php if (!empty($chiTietPhieu['kyThuatVien'])): ?>
                        <span style="display:inline-flex;align-items:center;gap:5px;">
                            <span style="width:20px;height:20px;background:linear-gradient(135deg,#3b82f6,#2563eb);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:9px;"><?= mb_strtoupper(mb_substr($chiTietPhieu['kyThuatVien'],0,1)) ?></span>
                            <strong style="color:#1e40af;"><?= htmlspecialchars($chiTietPhieu['kyThuatVien']) ?></strong>
                        </span>
                        <?php else: ?>
                        <span style="color:#f59e0b;">⚠ Chưa phân công</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-footer" style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:12px 24px;">
        <button class="btn btn-outline-secondary" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>Đóng</button>
        <button class="btn btn-warning" onclick="window.location.href='phieu_sua_chua.php?edit=<?= $chiTietPhieu['maPhieu'] ?>'"><i class="fas fa-edit me-1"></i>Cập nhật</button>
        <button class="btn btn-primary" onclick="window.open('phieu_sua_chua.php?in_phieu=<?= $chiTietPhieu['maPhieu'] ?>', '_blank')"><i class="fas fa-print me-1"></i>In phiếu</button>
    </div>
</div>
<script>
function showBigImgModal(src) {
    Swal.fire({ imageUrl: src, imageAlt: 'Ảnh thiết bị', showCloseButton: true, showConfirmButton: false, width: 'auto', background: '#1e293b' });
}
</script>
</body>
</html>
<?php
        exit();
    } else {
        echo '<div class="alert alert-danger m-3">Không tìm thấy phiếu sửa chữa</div>';
        exit();
    }
}

// In phiếu sửa chữa
if (isset($_GET['in_phieu']) && is_numeric($_GET['in_phieu'])) {
    $id = (int)$_GET['in_phieu'];
    $stmt = $conn->prepare("SELECT ps.*, kh.loaiKhachHang FROM phieu_sua_chua ps 
        LEFT JOIN khach_hang kh ON ps.maKH = kh.maKH
        WHERE ps.maPhieu=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $phieu = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$phieu) { echo "Không tìm thấy phiếu"; exit(); }
    
    $ngayNhanTs = strtotime($phieu['ngayNhan']);
    $ngayTraTs = $phieu['ngayTraDuKien'] ? strtotime($phieu['ngayTraDuKien']) : null;
    $homNayTs = strtotime(date('Y-m-d'));
    $soDaSua = (int)floor(($homNayTs - $ngayNhanTs) / 86400);
    $tongNgay = $ngayTraTs ? (int)floor(($ngayTraTs - $ngayNhanTs) / 86400) : null;
    $isDone = in_array($phieu['trangThai'], ['Đã sửa xong','Đã bàn giao']);
    $isQuaHan = $ngayTraTs && $homNayTs > $ngayTraTs && !$isDone;
    $allSteps = ['Tiếp nhận','Đang kiểm tra','Đang xử lý','Chờ linh kiện','Đã sửa xong','Đã bàn giao'];
    $stepIdx = array_search($phieu['trangThai'], $allSteps);
    if ($stepIdx === false) $stepIdx = 0;
    
    header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="vi">
<head><meta charset="UTF-8"><title>Phiếu sửa chữa #SC-<?= str_pad($phieu['maPhieu'],5,'0',STR_PAD_LEFT) ?></title>
<style>
    *{margin:0;padding:0;box-sizing:border-box;}
    body{font-family:'Be Vietnam Pro',Arial,sans-serif;padding:30px;font-size:13px;color:#1c1917;}
    @media print{@page{size:A4;margin:12mm;}body{padding:0;}}
    .invoice-co{font-size:16px;font-weight:800;color:#059669;}
    table{border-collapse:collapse;width:100%;}
    td,th{padding:8px 10px;border:1px solid #e2e8f0;vertical-align:top;}
    th{background:#f1f5f9;font-weight:600;}
    .text-center{text-align:center;}
    .fw-bold{font-weight:700;}
    .timeline-inline{display:flex;gap:0;margin:12px 0;}
    .timeline-step{flex:1;text-align:center;position:relative;}
    .timeline-dot{width:20px;height:20px;border-radius:50%;margin:0 auto 4px;display:flex;align-items:center;justify-content:center;font-size:9px;color:#fff;}
</style>
</head>
<body>
<div style="max-width:1000px;margin:0 auto;">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:20px;padding-bottom:16px;border-bottom:2px solid #f59e0b;">
        <div>
            <div class="invoice-co">CÔNG TY TNHH TM &amp; PT CN QUANG ANH</div>
            <div style="font-size:11px;color:#64748b;margin-top:4px;">
                CS1: 57 Nguyễn Bình, Hải Phòng &nbsp;|&nbsp; CS2: 81 Quán Nam, Hải Phòng<br>
                CS Kỹ thuật: 59 Nguyễn Bình &nbsp;|&nbsp; ☎ 0982.459.566
            </div>
        </div>
        <div style="text-align:right;">
            <div style="background:linear-gradient(135deg,#fbbf24,#f59e0b);color:#fff;font-size:13px;font-weight:800;padding:6px 14px;border-radius:8px;">PHIẾU SỬA CHỮA</div>
            <div style="font-size:18px;font-weight:800;color:#f59e0b;margin-top:5px;">#SC-<?= str_pad($phieu['maPhieu'],5,'0',STR_PAD_LEFT) ?></div>
            <div style="font-size:10px;color:#94a3b8;">Ngày in: <?= date('d/m/Y H:i') ?></div>
        </div>
    </div>

    <div style="background:<?= $isDone ? 'linear-gradient(135deg,#f0fdf4,#dcfce7)' : ($isQuaHan ? 'linear-gradient(135deg,#fff1f2,#fee2e2)' : 'linear-gradient(135deg,#fff7ed,#fef3c7)') ?>;border-radius:10px;padding:12px 16px;margin-bottom:18px;display:flex;align-items:center;gap:12px;border:1.5px solid <?= $isDone ? '#86efac' : ($isQuaHan ? '#fca5a5' : '#fbbf24') ?>;">
        <div style="font-size:24px;"><?= $isDone ? '✅' : ($isQuaHan ? '⚠️' : '🔧') ?></div>
        <div><div style="font-size:14px;font-weight:800;"><?= htmlspecialchars($phieu['trangThai']) ?></div></div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
        <div style="background:#f8fafc;border-radius:10px;padding:14px;">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#94a3b8;margin-bottom:8px;">Thông tin khách hàng</div>
            <div style="font-size:14px;font-weight:700;"><?= htmlspecialchars($phieu['tenKH'] ?? '—') ?></div>
            <div style="font-size:12px;color:#64748b;">📞 <?= htmlspecialchars($phieu['soDienThoai'] ?? '—') ?></div>
        </div>
        <div style="background:#f8fafc;border-radius:10px;padding:14px;">
            <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#94a3b8;margin-bottom:8px;">Thông tin thiết bị</div>
            <div style="font-size:14px;font-weight:700;"><?= htmlspecialchars($phieu['tenThietBi'] ?? '—') ?></div>
            <?php if (!empty($phieu['serial_number'])): ?>
            <div style="font-size:12px;color:#64748b;">🔢 Serial: <?= htmlspecialchars($phieu['serial_number']) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:16px;">
        <div style="background:#f8fafc;border-radius:8px;padding:10px;text-align:center;">
            <div style="font-size:10px;color:#94a3b8;">Ngày nhận</div>
            <div style="font-size:13px;font-weight:700;"><?= date('d/m/Y', $ngayNhanTs) ?></div>
        </div>
        <div style="background:#f8fafc;border-radius:8px;padding:10px;text-align:center;">
            <div style="font-size:10px;color:#94a3b8;">Ngày trả dự kiến</div>
            <div style="font-size:13px;font-weight:700;"><?= $ngayTraTs ? date('d/m/Y', $ngayTraTs) : '—' ?></div>
        </div>
        <div style="background:#f0fdf4;border-radius:8px;padding:10px;text-align:center;">
            <div style="font-size:10px;color:#94a3b8;">Đã sửa được</div>
            <div style="font-size:13px;font-weight:700;color:#059669;"><?= $soDaSua ?> ngày</div>
        </div>
        <div style="background:#f8fafc;border-radius:8px;padding:10px;text-align:center;">
            <div style="font-size:10px;color:#94a3b8;">Tổng thời gian</div>
            <div style="font-size:13px;font-weight:700;"><?= $tongNgay ? $tongNgay.' ngày' : '—' ?></div>
        </div>
    </div>

    <div class="timeline-inline" style="margin-bottom:16px;">
        <?php foreach ($allSteps as $si => $step):
            $isPast = $si < $stepIdx;
            $isCurrent = $si === $stepIdx;
        ?>
        <div class="timeline-step">
            <?php if ($si < count($allSteps)-1): ?>
            <div style="position:absolute;top:10px;left:50%;right:-50%;height:2px;background:<?= ($isPast || $isCurrent) ? ($isCurrent ? '#f59e0b' : '#10b981') : '#e2e8f0' ?>;z-index:0;"></div>
            <?php endif; ?>
            <div class="timeline-dot" style="background:<?= $isCurrent ? '#f59e0b' : ($isPast ? '#10b981' : '#e2e8f0') ?>;position:relative;z-index:1;"><?= $isPast ? '✓' : '' ?></div>
            <div style="font-size:8px;color:<?= $isCurrent ? '#f59e0b' : ($isPast ? '#10b981' : '#cbd5e1') ?>;"><?= $step ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <table style="margin-bottom:16px;">
        <tr><td style="font-weight:600;width:30%;">Mô tả lỗi</td><td><?= nl2br(htmlspecialchars($phieu['moTaLoi'] ?? '—')) ?></td></tr>
        <tr><td style="font-weight:600;">Mức độ ưu tiên</td><td><?= htmlspecialchars($phieu['mucDoUuTien'] ?? 'Bình thường') ?></td></tr>
        <tr><td style="font-weight:600;">Kỹ thuật viên</td><td><?= !empty($phieu['kyThuatVien']) ? htmlspecialchars($phieu['kyThuatVien']) : 'Chưa phân công' ?></td></tr>
        <?php if (!empty($phieu['ghiChu'])): ?>
        <tr><td style="font-weight:600;">Ghi chú</td><td><?= nl2br(htmlspecialchars($phieu['ghiChu'])) ?></td></tr>
        <?php endif; ?>
        <tr style="background:#fff7ed;"><td style="font-weight:700;color:#92400e;">Chi phí thanh toán</td><td style="font-size:16px;font-weight:800;color:#d97706;"><?= $phieu['chiPhi'] > 0 ? number_format($phieu['chiPhi'],0,',','.').' đ' : 'Chưa xác định' ?></td></tr>
    </table>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:30px;margin-top:24px;">
        <div style="text-align:center;border:1px dashed #e2e8f0;border-radius:10px;padding:20px 10px 12px;">
            <div style="height:50px;"></div><div style="height:1px;background:#e2e8f0;margin-bottom:6px;"></div>
            <div style="font-size:12px;font-weight:700;">Khách hàng xác nhận</div>
            <div style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($phieu['tenKH'] ?? '') ?></div>
        </div>
        <div style="text-align:center;border:1px dashed #e2e8f0;border-radius:10px;padding:20px 10px 12px;">
            <div style="height:50px;"></div><div style="height:1px;background:#e2e8f0;margin-bottom:6px;"></div>
            <div style="font-size:12px;font-weight:700;">Kỹ thuật viên phụ trách</div>
            <div style="font-size:11px;font-weight:600;color:#1e40af;"><?= !empty($phieu['kyThuatVien']) ? htmlspecialchars($phieu['kyThuatVien']) : 'Chưa phân công' ?></div>
        </div>
    </div>

    <div style="text-align:center;font-size:10px;color:#94a3b8;margin-top:20px;padding-top:10px;border-top:1px solid #e2e8f0;">Quang Anh Tech – Đồng hành cùng bạn</div>
</div>
<script>window.onload=()=>setTimeout(()=>window.print(),500);</script>
</body>
</html>
<?php
    exit();
}

// Modal sửa phiếu (cho KTV)
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM phieu_sua_chua WHERE maPhieu=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $phieuSua = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$phieuSua) { header("Location: phieu_sua_chua.php"); exit(); }
    
    $trangThaiList = ['Chờ xử lý', 'Đang sửa', 'Chờ linh kiện', 'Đã sửa xong', 'Đã bàn giao', 'Không sửa được'];
    $mucDoUuTienList = ['Khẩn cấp', 'Cao', 'Bình thường', 'Thấp'];
?>
<!DOCTYPE html>
<html lang="vi">
<head><meta charset="UTF-8"><title>Sửa phiếu sửa chữa #SC-<?= str_pad($id,5,'0',STR_PAD_LEFT) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    body{font-family:'Be Vietnam Pro',sans-serif;background:#f1f5f9;padding:20px;}
    .edit-container{max-width:900px;margin:0 auto;}
    .form-card{background:#fff;border-radius:16px;border:1px solid #e2e8f0;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,.08);}
    .form-header{background:linear-gradient(135deg,#1c1917,#292524);padding:20px 24px;color:#fff;}
    .form-body{padding:24px;}
    .btn-save{background:linear-gradient(135deg,#f59e0b,#d97706);border:none;border-radius:10px;padding:10px 24px;font-weight:600;color:#fff;}
    .btn-save:hover{background:linear-gradient(135deg,#d97706,#b45309);color:#fff;}
</style>
</head>
<body>
<div class="edit-container">
    <div class="form-card">
        <div class="form-header">
            <div style="display:flex;align-items:center;justify-content:space-between;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <div style="width:44px;height:44px;background:linear-gradient(135deg,#fbbf24,#f59e0b);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;">✏️</div>
                    <div><div style="font-size:18px;font-weight:800;">Cập nhật phiếu sửa chữa</div><div style="font-size:12px;opacity:.7;">#SC-<?= str_pad($id,5,'0',STR_PAD_LEFT) ?></div></div>
                </div>
                <a href="phieu_sua_chua.php" class="btn btn-sm btn-outline-light">← Quay lại</a>
            </div>
        </div>
        <form method="POST" class="form-body">
            <input type="hidden" name="maPhieu" value="<?= $id ?>">
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label">Tên thiết bị / Model</label>
                    <input type="text" name="tenThietBi" class="form-control" value="<?= htmlspecialchars($phieuSua['tenThietBi']) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Serial / IMEI</label>
                    <input type="text" name="serial_number" class="form-control" value="<?= htmlspecialchars($phieuSua['serial_number'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">Mô tả lỗi</label>
                    <textarea name="moTaLoi" class="form-control" rows="4"><?= htmlspecialchars($phieuSua['moTaLoi'] ?? '') ?></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label">Ghi chú nội bộ</label>
                    <textarea name="ghiChu" class="form-control" rows="2"><?= htmlspecialchars($phieuSua['ghiChu'] ?? '') ?></textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Ngày nhận</label>
                    <input type="date" name="ngayNhan" class="form-control" value="<?= $phieuSua['ngayNhan'] ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Ngày trả dự kiến</label>
                    <input type="date" name="ngayTraDuKien" class="form-control" value="<?= $phieuSua['ngayTraDuKien'] ?? '' ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Mức độ ưu tiên</label>
                    <select name="mucDoUuTien" class="form-select">
                        <?php foreach ($mucDoUuTienList as $ut): ?>
                        <option value="<?= $ut ?>" <?= ($phieuSua['mucDoUuTien']==$ut)?'selected':'' ?>><?= $ut ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Chi phí linh kiện (VNĐ)</label>
                    <input type="number" name="chiPhiLinhKien" class="form-control" value="<?= $phieuSua['chiPhiLinhKien'] ?? 0 ?>" min="0" step="1000">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Chi phí công (VNĐ)</label>
                    <input type="number" name="chiPhiCong" class="form-control" value="<?= $phieuSua['chiPhiCong'] ?? 0 ?>" min="0" step="1000">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Tổng chi phí</label>
                    <input type="number" name="chiPhi" class="form-control" value="<?= $phieuSua['chiPhi'] ?? 0 ?>" min="0" step="1000">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Trạng thái</label>
                    <select name="trangThai" class="form-select">
                        <?php foreach ($trangThaiList as $tt): ?>
                        <option value="<?= $tt ?>" <?= ($phieuSua['trangThai']==$tt)?'selected':'' ?>><?= $tt ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Kỹ thuật viên</label>
                    <input type="text" name="kyThuatVien" class="form-control" value="<?= htmlspecialchars($phieuSua['kyThuatVien'] ?? $tenKTV) ?>">
                </div>
            </div>
            <div class="mt-4 d-flex justify-content-end gap-2">
                <a href="phieu_sua_chua.php" class="btn btn-outline-secondary">Hủy</a>
                <button type="submit" name="sua_phieu_sua" class="btn btn-save"><i class="fas fa-save me-1"></i> Lưu cập nhật</button>
            </div>
        </form>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
    exit();
}

// Lấy tất cả phiếu sửa chữa - SẮP XẾP THEO TÊN KHÁCH HÀNG A-Z
$sql = "SELECT * FROM phieu_sua_chua ORDER BY tenKH ASC, maPhieu DESC";
$result = $conn->query($sql);

// Nhóm dữ liệu theo khách hàng
$groupedData = [];
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $customerName = $row['tenKH'] ?? 'Khách hàng không xác định';
        if (!isset($groupedData[$customerName])) {
            $groupedData[$customerName] = [];
        }
        $groupedData[$customerName][] = $row;
    }
}

// Thống kê
$totalPhieu = $result ? $result->num_rows : 0;
$stats = [];
$trangThaiList = ['Chờ xử lý', 'Đang sửa', 'Chờ linh kiện', 'Đã sửa xong', 'Đã bàn giao', 'Không sửa được'];
foreach ($trangThaiList as $tt) {
    $stats[$tt] = 0;
}
if ($result) {
    $result->data_seek(0);
    while ($row = $result->fetch_assoc()) {
        if (isset($stats[$row['trangThai']])) {
            $stats[$row['trangThai']]++;
        }
    }
}

$trangThaiList2 = $trangThaiList;
$mucDoUuTienList = ['Khẩn cấp', 'Cao', 'Bình thường', 'Thấp'];

$ttColors = [
    'Chờ xử lý'       => ['bg'=>'#fef3c7', 'color'=>'#d97706', 'icon'=>'fa-clock'],
    'Đang sửa'        => ['bg'=>'#dbeafe', 'color'=>'#2563eb', 'icon'=>'fa-wrench'],
    'Chờ linh kiện'   => ['bg'=>'#fce7f3', 'color'=>'#db2777', 'icon'=>'fa-box'],
    'Đã sửa xong'     => ['bg'=>'#d1fae5', 'color'=>'#059669', 'icon'=>'fa-check-circle'],
    'Đã bàn giao'     => ['bg'=>'#f0fdf4', 'color'=>'#16a34a', 'icon'=>'fa-handshake'],
    'Không sửa được'  => ['bg'=>'#fee2e2', 'color'=>'#dc2626', 'icon'=>'fa-times-circle'],
];

$priorityColors = [
    'Khẩn cấp' => ['bg'=>'#fee2e2', 'color'=>'#dc2626'],
    'Cao' => ['bg'=>'#fed7aa', 'color'=>'#ea580c'],
    'Bình thường' => ['bg'=>'#e2e8f0', 'color'=>'#475569'],
    'Thấp' => ['bg'=>'#d1fae5', 'color'=>'#059669'],
];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Quản lý phiếu sửa chữa – Kỹ thuật viên</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
:root{
    --primary:#0f766e;
    --primary-dark:#0d6460;
    --primary-light:#ccfbf1;
    --sidebar-bg:#0f172a;
    --sidebar-w:260px;
    --surface:#fff;
    --surface-2:#f8fafc;
    --border:#e2e8f0;
    --text-primary:#0f172a;
    --text-muted:#64748b;
    --radius:14px;
    --shadow-sm:0 1px 3px rgba(0,0,0,.06);
    --shadow-md:0 4px 16px rgba(0,0,0,.08);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Be Vietnam Pro',sans-serif;background:var(--surface-2);color:var(--text-primary);font-size:14px}
.sidebar{width:var(--sidebar-w);height:100vh;position:fixed;top:0;left:0;background:var(--sidebar-bg);display:flex;flex-direction:column;z-index:1000}
.sidebar-brand{padding:22px 18px 18px;border-bottom:1px solid rgba(255,255,255,.07)}
.brand-logo{display:flex;align-items:center;gap:12px;text-decoration:none}
.brand-icon{width:40px;height:40px;background:var(--primary);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;color:#fff}
.brand-name{font-size:14px;font-weight:800;color:#fff}.brand-sub{font-size:10px;color:#64748b;text-transform:uppercase;letter-spacing:1px}
.sidebar-user{padding:14px 18px;border-bottom:1px solid rgba(255,255,255,.07);display:flex;align-items:center;gap:10px}
.user-avatar{width:34px;height:34px;border-radius:50%;background:var(--primary);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:14px;flex-shrink:0}
.user-name{font-size:12.5px;font-weight:700;color:#e2e8f0}.user-role{font-size:10px;color:#64748b}
.sidebar-nav{flex:1;padding:14px 0;overflow-y:auto}
.nav-label{font-size:9px;font-weight:700;letter-spacing:1.4px;text-transform:uppercase;color:#334155;padding:14px 18px 5px}
.nav-link-item{display:flex;align-items:center;gap:11px;padding:10px 18px;color:#94a3b8;text-decoration:none;font-size:13px;font-weight:500;transition:all .2s;position:relative}
.nav-link-item:hover{color:#e2e8f0;background:rgba(255,255,255,.04)}
.nav-link-item.active{color:#fff;background:rgba(15,118,110,.15)}
.nav-icon{width:30px;height:30px;display:flex;align-items:center;justify-content:center;border-radius:7px;font-size:12px;flex-shrink:0;background:rgba(255,255,255,.05)}
.nav-link-item.active .nav-icon{background:rgba(15,118,110,.3);color:#5eead4}
.sidebar-footer{padding:14px 18px;border-top:1px solid rgba(255,255,255,.07)}
.logout-btn{display:flex;align-items:center;gap:9px;padding:9px 11px;color:#f87171;text-decoration:none;font-size:12.5px;font-weight:600;border-radius:9px;transition:all .2s}
.logout-btn:hover{background:rgba(239,68,68,.1);color:#fca5a5}
.main-content{margin-left:var(--sidebar-w);min-height:100vh}
.topbar{height:60px;background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 28px;gap:14px;position:sticky;top:0;z-index:100;box-shadow:var(--shadow-sm)}
.topbar-title{font-size:15px;font-weight:700;color:var(--text-primary);flex:1}
.live-clock{font-size:12px;color:var(--text-muted);font-weight:600}
.btn-them{padding:9px 18px;background:var(--primary);color:#fff;border:none;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:7px;text-decoration:none}
.btn-them:hover{background:var(--primary-dark);color:#fff}
.page-body{padding:24px 28px}
.alert-box{padding:12px 16px;border-radius:10px;margin-bottom:18px;font-size:13px;font-weight:600}
.alert-success{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0}
.alert-danger{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
.stats-cards{display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap}
.stat-card{background:var(--surface);border-radius:var(--radius);padding:12px 16px;flex:1;min-width:100px;text-align:center;border:1px solid var(--border);box-shadow:var(--shadow-sm);cursor:pointer;transition:all .2s}
.stat-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-md)}
.stat-count{font-size:20px;font-weight:800;color:var(--primary)}
.stat-label{font-size:11px;font-weight:600;color:var(--text-muted);margin-top:4px}
.filter-bar{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:16px 20px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:20px;box-shadow:var(--shadow-sm)}
.search-input{flex:2;min-width:200px;padding:9px 14px;border:1px solid var(--border);border-radius:9px;font-size:13px;font-family:inherit;background:#f8fafc}
.search-input:focus{outline:none;border-color:var(--primary);background:#fff}
.filter-select{padding:9px 12px;border:1px solid var(--border);border-radius:9px;font-size:13px;font-family:inherit;background:#f8fafc}
.btn-search{padding:9px 18px;background:var(--primary);color:#fff;border:none;border-radius:9px;font-size:13px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:7px}
.btn-search:hover{background:var(--primary-dark)}
.btn-reset{padding:9px 14px;background:#f1f5f9;color:var(--text-muted);border:1px solid var(--border);border-radius:9px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none}
.btn-reset:hover{background:#e2e8f0}
.data-card{background:var(--surface);border-radius:var(--radius);border:1px solid var(--border);overflow:auto;box-shadow:var(--shadow-sm)}
.data-table{width:100%;border-collapse:collapse;font-size:13px;min-width:900px}
.data-table th{background:#f8fafc;padding:11px 14px;text-align:left;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border);white-space:nowrap}
.data-table td{padding:11px 14px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.data-table tr:hover td{background:#f8fafc}
.customer-group-header{
    background: linear-gradient(135deg, #f0fdf4, #dcfce7);
    margin-top: 16px;
    border-left: 4px solid var(--primary);
    border-radius: 8px 8px 0 0;
    padding: 12px 16px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.customer-group-header:first-child{margin-top:0}
.customer-group-header i{font-size:16px;color:#059669}
.customer-group-header .customer-name{font-weight:700;font-size:15px;color:#065f46}
.customer-group-header .customer-count{font-size:12px;color:#64748b;font-weight:normal;margin-left:8px;background:white;padding:2px 8px;border-radius:20px}
.badge-tt{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;white-space:nowrap}
.badge-priority{display:inline-flex;padding:3px 8px;border-radius:12px;font-size:10px;font-weight:700}
.btn-group-actions{display:flex;gap:5px;flex-wrap:wrap}
.btn-action{padding:5px 10px;border:none;border-radius:7px;font-size:11px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:4px;transition:all .2s}
.btn-view{background:#e2e8f0;color:#475569}.btn-view:hover{background:#cbd5e1}
.btn-edit{background:#dbeafe;color:#2563eb}.btn-edit:hover{background:#2563eb;color:#fff}
.btn-delete{background:#fee2e2;color:#dc2626}.btn-delete:hover{background:#dc2626;color:#fff}
.btn-handover{background:#d1fae5;color:#059669}.btn-handover:hover{background:#059669;color:#fff}
.modal-header{background:linear-gradient(135deg,#1c1917,#292524);color:#fff;border-bottom:none;}
.modal-header .btn-close{filter:brightness(0) invert(1);}
</style>
</head>
<body>
<aside class="sidebar">
    <div class="sidebar-brand">
        <a href="trang_chu.php" class="brand-logo">
            <div class="brand-icon"><i class="fas fa-tools"></i></div>
            <div><div class="brand-name">Quang Anh Tech</div><div class="brand-sub">Kỹ thuật viên</div></div>
        </a>
    </div>
    <div class="sidebar-user">
        <div class="user-avatar"><?= strtoupper(mb_substr($tenKTV,0,1)) ?></div>
        <div><div class="user-name"><?= htmlspecialchars($tenKTV) ?></div><div class="user-role">Kỹ thuật viên</div></div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-label">Tổng quan</div>
        <a href="index.php" class="nav-link-item"><div class="nav-icon"><i class="fas fa-home"></i></div> Trang chủ</a>
        <div class="nav-label">Quản lý</div>
        <a href="quan_ly_khach_hang.php" class="nav-link-item"><div class="nav-icon"><i class="fas fa-users"></i></div> Khách hàng</a>
        <a href="phieu_sua_chua.php" class="nav-link-item active"><div class="nav-icon"><i class="fas fa-screwdriver-wrench"></i></div> Tất cả phiếu</a>
        <a href="bao_hanh.php" class="nav-link-item"><div class="nav-icon"><i class="fas fa-shield-alt"></i></div> Bảo hành</a>
        <div class="nav-label">Tài khoản</div>
        <a href="doi_mat_khau.php" class="nav-link-item"><div class="nav-icon"><i class="fas fa-key"></i></div> Đổi mật khẩu</a>
    </nav>
    <div class="sidebar-footer">
        <a href="../logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Đăng xuất</a>
    </div>
</aside>

<main class="main-content">
    <div class="topbar">
        <div class="topbar-title"><i class="fas fa-screwdriver-wrench me-2" style="color:var(--primary)"></i>Quản lý phiếu sửa chữa</div>
        <div class="live-clock" id="live-clock"></div>
        <button class="btn-them ms-3" data-bs-toggle="modal" data-bs-target="#modalThem"><i class="fas fa-plus"></i> Tạo phiếu mới</button>
    </div>
    <div class="page-body">
        <?php if ($msg): ?>
        <div class="alert-box alert-<?= $msg_type ?>"><?= $msg ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['msg']) && in_array($_GET['msg'], ['cap_nhat_ok','cp_ok','tra_ok','xoa_ok','update_ok'])): ?>
        <div class="alert-box alert-success"><?= ['cap_nhat_ok'=>'✅ Cập nhật trạng thái thành công!','cp_ok'=>'✅ Cập nhật chi phí thành công!','tra_ok'=>'✅ Đã bàn giao thiết bị!','xoa_ok'=>'🗑️ Đã xóa phiếu!','update_ok'=>'✅ Cập nhật phiếu thành công!'][$_GET['msg']] ?></div>
        <?php endif; ?>

        <!-- Thống kê nhanh -->
        <div class="stats-cards">
            <div class="stat-card" onclick="location.href='phieu_sua_chua.php'">
                <div class="stat-count"><?= $totalPhieu ?></div>
                <div class="stat-label">Tổng phiếu</div>
            </div>
            <?php foreach ($trangThaiList2 as $tt): $count = $stats[$tt] ?? 0; ?>
            <div class="stat-card" onclick="location.href='phieu_sua_chua.php?filter_tt=<?= urlencode($tt) ?>'">
                <div class="stat-count"><?= $count ?></div>
                <div class="stat-label"><?= $tt ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Filter & Search -->
        <form method="GET" class="filter-bar" id="filterForm">
            <input type="text" name="search" id="searchInput" class="search-input" placeholder="🔍 Tìm theo mã phiếu, tên KH, SĐT, thiết bị..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
            <select name="filter_tt" class="filter-select" style="width:140px">
                <option value="">Trạng thái</option>
                <?php foreach ($trangThaiList2 as $tt): ?>
                <option value="<?= $tt ?>" <?= ($_GET['filter_tt']??'')===$tt?'selected':'' ?>><?= $tt ?></option>
                <?php endforeach; ?>
            </select>
            <select name="filter_uu_tien" class="filter-select" style="width:120px">
                <option value="">Ưu tiên</option>
                <?php foreach ($mucDoUuTienList as $ut): ?>
                <option value="<?= $ut ?>" <?= ($_GET['filter_uu_tien']??'')===$ut?'selected':'' ?>><?= $ut ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-search"><i class="fas fa-search"></i> Tìm</button>
            <a href="phieu_sua_chua.php" class="btn-reset"><i class="fas fa-rotate-right"></i> Reset</a>
        </form>

        <div class="data-card">
            <table class="data-table" id="repairTable">
                <thead>
                <tr>
                    <th>Mã phiếu</th>
                    <th>Thiết bị / Serial</th>
                    <th>Mô tả lỗi</th>
                    <th>Ngày nhận</th>
                    <th>Ngày trả dự kiến</th>
                    <th>Chi phí</th>
                    <th>Ưu tiên</th>
                    <th>Trạng thái</th>
                    <th style="text-align:center">Thao tác</th>
                </tr>
                </thead>
                <tbody>
                    <?php if (!empty($groupedData)): ?>
                        <?php $first = true; ?>
                        <?php foreach ($groupedData as $customerName => $repairs): ?>
                            <!-- Header nhóm khách hàng -->
                            <tr class="customer-group-row">
                                <td colspan="9" style="padding:0;">
                                    <div class="customer-group-header" style="<?= $first ? '' : 'margin-top:12px;' ?>">
                                        <i class="fas fa-user-circle"></i>
                                        <span class="customer-name"><?= htmlspecialchars($customerName) ?></span>
                                        <span class="customer-count">(<?= count($repairs) ?> phiếu sửa chữa)</span>
                                    </div>
                                    <!-- Hiển thị SĐT của khách hàng -->
                                    <div style="padding:6px 16px 8px 52px; background:#fff; border-bottom:1px solid #e2e8f0; font-size:12px; color:#64748b;">
                                        <i class="fas fa-phone-alt"></i> <?= htmlspecialchars($repairs[0]['soDienThoai'] ?? '—') ?>
                                    </div>
                                 </div>
                            </tr>
                            <?php $first = false; ?>
                            <?php foreach ($repairs as $r):
                                $tt = $r['trangThai'];
                                $c  = $ttColors[$tt] ?? ['bg'=>'#f1f5f9','color'=>'#64748b', 'icon'=>'fa-question'];
                                $p  = $priorityColors[$r['mucDoUuTien']] ?? ['bg'=>'#e2e8f0','color'=>'#475569'];
                            ?>
                            <tr data-search="<?= strtolower(($r['tenKH']??'') . ' ' . $r['tenThietBi'] . ' ' . ($r['soDienThoai']??'') . ' ' . ($r['serial_number']??'')) ?>">
                                <td><strong style="color:var(--primary);font-size:13px">#SC-<?= $r['maPhieu'] ?></strong></td>
                                <td>
                                    <div style="font-weight:600"><?= htmlspecialchars($r['tenThietBi']) ?></div>
                                    <?php if ($r['serial_number']): ?>
                                    <div style="font-size:10px;color:#94a3b8">Serial: <?= htmlspecialchars($r['serial_number']) ?></div>
                                    <?php endif; ?>
                                 </div>
                                <td style="font-size:12px;color:var(--text-muted);max-width:200px">
                                    <?= mb_strlen($r['moTaLoi'] ?? '') > 60 ? mb_substr($r['moTaLoi'], 0, 60) . '...' : ($r['moTaLoi'] ?? '—') ?>
                                 </div>
                                <td style="font-size:12px"><?= $r['ngayNhan'] ? date('d/m/Y', strtotime($r['ngayNhan'])) : '—' ?> </div>
                                <td style="font-size:12px"><?= $r['ngayTraDuKien'] ? date('d/m/Y', strtotime($r['ngayTraDuKien'])) : '—' ?> </div>
                                <td style="font-weight:700;color:var(--primary);white-space:nowrap"><?= number_format($r['chiPhi']) ?>đ</div>
                                <td>
                                    <span class="badge-priority" style="background:<?= $p['bg'] ?>;color:<?= $p['color'] ?>">
                                        <i class="fas fa-flag"></i> <?= $r['mucDoUuTien'] ?>
                                    </span>
                                 </div>
                                <td><span class="badge-tt" style="background:<?= $c['bg'] ?>;color:<?= $c['color'] ?>"><i class="fas <?= $c['icon'] ?>"></i> <?= $tt ?></span></div>
                                <td>
                                    <div class="btn-group-actions">
                                        <button class="btn-action btn-view" onclick="xemChiTiet(<?= $r['maPhieu'] ?>)"><i class="fas fa-eye"></i></button>
                                        <button class="btn-action btn-edit" onclick="suaPhieu(<?= $r['maPhieu'] ?>)"><i class="fas fa-edit"></i></button>
                                        <?php if ($tt !== 'Đã bàn giao' && $tt !== 'Không sửa được'): ?>
                                        <button class="btn-action btn-handover" onclick="banGiaoThietBi(<?= $r['maPhieu'] ?>)"><i class="fas fa-handshake"></i></button>
                                        <?php endif; ?>
                                        <button class="btn-action btn-delete" onclick="xoaPhieu(<?= $r['maPhieu'] ?>)"><i class="fas fa-trash"></i></button>
                                    </div>
                                 </div>
                            </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--text-muted)">
                            <i class="fas fa-clipboard-list" style="font-size:32px;opacity:.3;display:block;margin-bottom:10px"></i>
                            Chưa có phiếu sửa chữa nào
                         </div></tr>
                    <?php endif; ?>
                </tbody>
             </div>
        </div>
    </div>
</main>

<!-- Modal tạo phiếu mới -->
<div class="modal fade" id="modalThem" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#0f766e,#0d6460);">
                <h5 class="modal-title"><i class="fas fa-plus me-2"></i>Tạo phiếu sửa chữa mới</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Tên khách hàng *</label>
                            <input type="text" name="tenKH" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Số điện thoại</label>
                            <input type="tel" name="soDienThoai" class="form-control" placeholder="0xxxxxxxxx">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Tên thiết bị *</label>
                            <input type="text" name="tenThietBi" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Serial / IMEI</label>
                            <input type="text" name="serial_number" class="form-control">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Mô tả lỗi</label>
                            <textarea name="moTaLoi" class="form-control" rows="3"></textarea>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Ngày nhận</label>
                            <input type="date" name="ngayNhan" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Ngày trả dự kiến</label>
                            <input type="date" name="ngayTraDuKien" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Chi phí linh kiện</label>
                            <input type="number" name="chiPhiLinhKien" class="form-control" value="0" min="0" step="1000">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Chi phí công</label>
                            <input type="number" name="chiPhiCong" class="form-control" value="0" min="0" step="1000">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Trạng thái</label>
                            <select name="trangThai" class="form-select">
                                <?php foreach ($trangThaiList2 as $tt): ?>
                                <option value="<?= $tt ?>"><?= $tt ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Mức độ ưu tiên</label>
                            <select name="mucDoUuTien" class="form-select">
                                <?php foreach ($mucDoUuTienList as $ut): ?>
                                <option value="<?= $ut ?>"><?= $ut ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Ghi chú</label>
                            <input type="text" name="ghiChu" class="form-control">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" name="them_phieu" class="btn btn-success"><i class="fas fa-save me-1"></i>Lưu phiếu</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal xem chi tiết -->
<div class="modal fade" id="modalChiTiet" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-alt me-2"></i>Chi tiết phiếu sửa chữa</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" id="chiTietContent">
                <div class="text-center p-4"><i class="fas fa-spinner fa-spin"></i> Đang tải...</div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function updateClock(){
    const now=new Date(),pad=n=>String(n).padStart(2,'0'),days=['CN','T2','T3','T4','T5','T6','T7'];
    document.getElementById('live-clock').textContent=days[now.getDay()]+' '+pad(now.getHours())+':'+pad(now.getMinutes())+':'+pad(now.getSeconds());
}
updateClock();setInterval(updateClock,1000);

function xemChiTiet(id) {
    const modal = new bootstrap.Modal(document.getElementById('modalChiTiet'));
    document.getElementById('chiTietContent').innerHTML = '<div class="text-center p-4"><i class="fas fa-spinner fa-spin"></i> Đang tải...</div>';
    modal.show();
    
    fetch(`phieu_sua_chua.php?chitiet=${id}`)
        .then(response => response.text())
        .then(html => {
            document.getElementById('chiTietContent').innerHTML = html;
        })
        .catch(() => {
            document.getElementById('chiTietContent').innerHTML = '<div class="alert alert-danger m-3">Lỗi tải dữ liệu</div>';
        });
}

function suaPhieu(id) {
    window.location.href = `phieu_sua_chua.php?edit=${id}`;
}

function banGiaoThietBi(id) {
    const today = new Date().toISOString().split('T')[0];
    Swal.fire({
        title: 'Bàn giao thiết bị',
        text: 'Xác nhận đã bàn giao thiết bị cho khách hàng?',
        icon: 'question',
        confirmButtonColor: '#059669',
        confirmButtonText: 'Xác nhận bàn giao',
        showCancelButton: true,
        cancelButtonText: 'Hủy'
    }).then(r => {
        if (r.isConfirmed) {
            window.location.href = `phieu_sua_chua.php?tra_thiet_bi=${id}&ngay_tra=${today}`;
        }
    });
}

function xoaPhieu(id) {
    Swal.fire({
        title: 'Xóa phiếu sửa chữa',
        text: 'Bạn có chắc muốn xóa phiếu này? Hành động không thể hoàn tác!',
        icon: 'warning',
        confirmButtonColor: '#dc2626',
        confirmButtonText: 'Xóa',
        showCancelButton: true,
        cancelButtonText: 'Hủy'
    }).then(r => {
        if (r.isConfirmed) {
            window.location.href = `phieu_sua_chua.php?xoa=${id}`;
        }
    });
}

// Filter tìm kiếm
function filterTable() {
    const input = document.getElementById('searchInput');
    if (!input) return;
    const filter = input.value.toLowerCase();
    const rows = document.querySelectorAll('#repairTable tbody tr');
    let currentGroup = null;
    
    rows.forEach(row => {
        if (row.classList && row.classList.contains('customer-group-row')) {
            currentGroup = row;
            return;
        }
        const searchData = row.getAttribute('data-search') || '';
        const shouldShow = searchData.includes(filter) || filter === '';
        row.style.display = shouldShow ? '' : 'none';
        
        if (currentGroup) {
            let hasVisible = false;
            let nextRow = currentGroup.nextElementSibling;
            while (nextRow && !(nextRow.classList && nextRow.classList.contains('customer-group-row'))) {
                if (nextRow.style.display !== 'none') {
                    hasVisible = true;
                    break;
                }
                nextRow = nextRow.nextElementSibling;
            }
            currentGroup.style.display = hasVisible ? '' : 'none';
            // Ẩn dòng SĐT nếu không có phiếu nào hiển thị
            const phoneRow = currentGroup.nextElementSibling;
            if (phoneRow && phoneRow.style && phoneRow.style.display !== 'none') {
                phoneRow.style.display = hasVisible ? '' : 'none';
            }
        }
    });
}

document.getElementById('searchInput')?.addEventListener('keyup', filterTable);
</script>
</body>
</html>