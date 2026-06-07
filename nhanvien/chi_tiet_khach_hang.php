<?php
session_start();
require_once '../db.php';
date_default_timezone_set('Asia/Ho_Chi_Minh');

/* ─── Kiểm tra quyền Admin ─── */
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'nhan_vien') {
    header("Location: ../trang_chu.php"); exit();
}
if (!isset($_GET['id'])) {
    header("Location: quan_ly_khach_hang.php"); exit();
}

$maKH       = (int)$_GET['id'];
$admin_user = $_SESSION['username'] ?? 'Admin';


// ========== HỆ THỐNG NHẮC NHỞ THÔNG MINH ==========

// Mảng lưu các thông báo
$reminders = [];
$alerts = [];

// 1. KIỂM TRA THIẾU NHẬT KÝ GIAO TIẾP
// Lấy đơn hàng và phiếu sửa chữa trong 7 ngày gần đây (tính từ hôm nay)
$recentOrders = $conn->query("SELECT maDH, ngayDat FROM don_hang WHERE maKH = $maKH AND ngayDat >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) ORDER BY ngayDat DESC");
$recentRepairs = $conn->query("SELECT maPhieu, ngayNhan FROM phieu_sua_chua WHERE maKH = $maKH AND ngayNhan >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) ORDER BY ngayNhan DESC");

// Lấy nhật ký trong 7 ngày gần đây (tính từ thời điểm hiện tại)
$recentLogs = $conn->query("SELECT maNK, thoiGian FROM nhat_ky_giao_tiep WHERE maKH = $maKH AND DATE(thoiGian) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");

$hasRecentOrder = ($recentOrders->num_rows > 0);
$hasRecentRepair = ($recentRepairs->num_rows > 0);
$hasRecentLog = ($recentLogs->num_rows > 0);

// Lấy phiếu sửa chữa mới nhất (vừa tạo) để hiển thị thông tin chi tiết
$newestRepair = null;
if ($hasRecentRepair) {
    $newestRepair = $recentRepairs->fetch_assoc();
    $recentRepairs->data_seek(0); // reset lại con trỏ
}

// Nếu có đơn hàng mới HOẶC phiếu sửa chữa mới, nhưng chưa có nhật ký
if (($hasRecentOrder || $hasRecentRepair) && !$hasRecentLog) {
    $activityList = [];
    
    // Lấy danh sách đơn hàng gần đây
    if ($hasRecentOrder) {
        $orderCount = $recentOrders->num_rows;
        $activityList[] = "$orderCount đơn hàng mới";
    }
    
    // Lấy danh sách phiếu sửa chữa gần đây
    if ($hasRecentRepair) {
        $repairCount = $recentRepairs->num_rows;
        $activityList[] = "$repairCount phiếu sửa chữa mới";
    }
    
    $message = "Khách hàng có hoạt động mới (" . implode(', ', $activityList) . ") trong 7 ngày qua nhưng chưa có ghi nhận nhật ký. Hãy thêm nhật ký để theo dõi lịch sử tương tác.";
    
    $reminders[] = [
        'type' => 'warning',
        'icon' => 'fas fa-clock',
        'title' => 'Thiếu nhật ký giao tiếp!',
        'message' => $message,
        'action' => 'scrollToForm',
        'actionText' => 'Thêm nhật ký ngay'
    ];
}

// 2. KIỂM TRA ĐƠN HÀNG MỚI CHƯA CÓ BẢO HÀNH (cho sản phẩm có bảo hành)
// Lấy các đơn hàng trong 30 ngày gần đây có sản phẩm cần bảo hành
$ordersWithoutWarranty = $conn->query("
    SELECT DISTINCT dh.maDH, dh.ngayDat, ctdh.tenSanPham 
    FROM don_hang dh
    JOIN chi_tiet_don_hang ctdh ON dh.maDH = ctdh.maDH
    LEFT JOIN bao_hanh bh ON bh.maKH = dh.maKH 
        AND (bh.tenSP = ctdh.tenSanPham 
             OR bh.tenSP LIKE CONCAT('%', ctdh.tenSanPham, '%')
             OR ctdh.tenSanPham LIKE CONCAT('%', bh.tenSP, '%'))
    WHERE dh.maKH = $maKH 
        AND dh.ngayDat >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        AND bh.maBaoHanh IS NULL
        AND (ctdh.tenSanPham LIKE '%laptop%' 
            OR ctdh.tenSanPham LIKE '%máy tính%' 
            OR ctdh.tenSanPham LIKE '%màn hình%'
            OR ctdh.tenSanPham LIKE '%điện thoại%'
            OR ctdh.tenSanPham LIKE '%sạc%'
            OR ctdh.tenSanPham LIKE '%balo%')
    LIMIT 5
");

if ($ordersWithoutWarranty->num_rows > 0) {
    $productList = [];
    while ($row = $ordersWithoutWarranty->fetch_assoc()) {
        $productList[] = htmlspecialchars($row['tenSanPham']);
    }
    $reminders[] = [
        'type' => 'danger',
        'icon' => 'fas fa-shield-alt',
        'title' => 'Thiếu bảo hành cho sản phẩm!',
        'message' => 'Đơn hàng có sản phẩm cần bảo hành: ' . implode(', ', array_slice($productList, 0, 3)) . (count($productList) > 3 ? '...' : '') . '. Hãy tạo phiếu bảo hành ngay.',
        'action' => 'openBaoHanhModal',
        'actionText' => 'Tạo bảo hành'
    ];
}

// 3. KIỂM TRA PHIẾU SỬA CHỮA KHÔNG CÓ ẢNH
$repairsWithoutImages = $conn->query("
    SELECT ps.maPhieu, ps.tenThietBi, ps.ngayNhan
    FROM phieu_sua_chua ps
    LEFT JOIN anh_phieu_sua aps ON ps.maPhieu = aps.maPhieu
    WHERE ps.maKH = $maKH 
        AND ps.trangThai NOT IN ('Đã bàn giao', 'Đã sửa xong')
        AND aps.maAnh IS NULL
    ORDER BY ps.ngayNhan DESC
    LIMIT 3
");

if ($repairsWithoutImages->num_rows > 0) {
    $repairList = [];
    while ($row = $repairsWithoutImages->fetch_assoc()) {
        $repairList[] = '#SC-' . $row['maPhieu'] . ' (' . htmlspecialchars($row['tenThietBi']) . ')';
    }
    $reminders[] = [
        'type' => 'info',
        'icon' => 'fas fa-camera',
        'title' => 'Thiếu ảnh phiếu sửa chữa!',
        'message' => 'Các phiếu sửa chữa chưa có ảnh đính kèm: ' . implode(', ', $repairList) . '. Ảnh giúp lưu trữ trạng thái thiết bị.',
        'action' => 'switchToKyThuatTab',
        'actionText' => 'Xem phiếu sửa chữa'
    ];
}

// 4. KIỂM TRA VOUCHER SẮP HẾT HẠN
$expiringVouchers = $conn->query("
    SELECT maVoucher, loaiVoucher, giaTriGiam, ngayHetHan 
    FROM voucher_khach_hang 
    WHERE maKH = $maKH 
        AND trangThai = 'Chưa dùng'
        AND ngayHetHan IS NOT NULL
        AND ngayHetHan BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY ngayHetHan ASC
");

if ($expiringVouchers->num_rows > 0) {
    $voucherList = [];
    while ($row = $expiringVouchers->fetch_assoc()) {
        $daysLeft = ceil((strtotime($row['ngayHetHan']) - time()) / 86400);
        $loaiVC = $row['loaiVoucher'];
        if ($loaiVC === 'sua_chua_50pct' || $loaiVC === 'sinh_nhat_sc' || $loaiVC === 'tu_soan_sc') {
            $typeText = '🔧 Sửa chữa 50%';
        } elseif ($loaiVC === 'mua_hang_1trieu' || $loaiVC === 'tu_soan_mh') {
            $typeText = '💻 Mua laptop -1tr';
        } elseif ($loaiVC === 'sinh_nhat_10pct') {
            $typeText = '🎂 Sinh nhật 10%';
        } elseif (str_starts_with($loaiVC, 'tu_soan')) {
            $typeText = '✏️ Tùy chỉnh – ' . htmlspecialchars($row['giaTriGiam']);
        } else {
            $typeText = htmlspecialchars($row['giaTriGiam']);
        }
        $voucherList[] = "$typeText (còn $daysLeft ngày)";
    }
    $alerts[] = [
        'type' => 'warning',
        'icon' => 'fas fa-hourglass-half',
        'title' => 'Voucher sắp hết hạn!',
        'message' => 'Khách hàng có ' . $expiringVouchers->num_rows . ' voucher sẽ hết hạn trong 30 ngày tới: ' . implode(', ', $voucherList),
        'action' => 'switchToVoucherTab',
        'actionText' => 'Xem voucher'
    ];
}

// 5. KIỂM TRA QUÁ HẠN SỬA CHỮA
$overdueRepairs = $conn->query("
    SELECT maPhieu, tenThietBi, ngayNhan, ngayTra, trangThai
    FROM phieu_sua_chua 
    WHERE maKH = $maKH 
        AND ngayTra IS NOT NULL 
        AND ngayTra < CURDATE()
        AND trangThai NOT IN ('Đã bàn giao', 'Đã sửa xong')
    ORDER BY ngayTra ASC
");

if ($overdueRepairs->num_rows > 0) {
    $overdueList = [];
    while ($row = $overdueRepairs->fetch_assoc()) {
        $overdueDays = ceil((time() - strtotime($row['ngayTra'])) / 86400);
        $overdueList[] = '#SC-' . $row['maPhieu'] . ' - ' . htmlspecialchars($row['tenThietBi']) . " (quá hạn $overdueDays ngày)";
    }
    $alerts[] = [
        'type' => 'danger',
        'icon' => 'fas fa-exclamation-triangle',
        'title' => 'Phiếu sửa chữa quá hạn!',
        'message' => 'Các phiếu đã quá hạn trả: ' . implode(', ', array_slice($overdueList, 0, 2)) . (count($overdueList) > 2 ? '...' : ''),
        'action' => 'switchToKyThuatTab',
        'actionText' => 'Xử lý ngay'
    ];
}



// 7. KIỂM TRA CHIẾN DỊCH CHĂM SÓC (khách hàng lâu ngày không tương tác)
$lastActivity = $conn->query("
    SELECT MAX(ngayDat) as last_order 
    FROM don_hang 
    WHERE maKH = $maKH
")->fetch_assoc();

$lastRepairActivity = $conn->query("
    SELECT MAX(ngayNhan) as last_repair 
    FROM phieu_sua_chua 
    WHERE maKH = $maKH
")->fetch_assoc();

$lastOrderDate = $lastActivity['last_order'] ?? null;
$lastRepairDate = $lastRepairActivity['last_repair'] ?? null;
$lastDate = max($lastOrderDate, $lastRepairDate);

$lastOrderDate = $lastActivity['last_order'] ?? null;
$lastRepairDate = $lastActivity['last_repair'] ?? null;
$lastDate = max($lastOrderDate, $lastRepairDate);

if ($lastDate && strtotime($lastDate) < strtotime('-90 days')) {
    $daysInactive = floor((time() - strtotime($lastDate)) / 86400);
    $alerts[] = [
        'type' => 'secondary',
        'icon' => 'fas fa-bell',
        'title' => 'Khách hàng ít tương tác!',
        'message' => "Đã $daysInactive ngày kể từ lần giao dịch cuối cùng (" . date('d/m/Y', strtotime($lastDate)) . "). Cân nhắc chương trình chăm sóc để kích hoạt lại.",
        'action' => 'scrollToForm',
        'actionText' => 'Ghi nhận liên hệ'
    ];
}


/* ─── Helper: thêm cột nếu chưa tồn tại ─── */
function addColSafe($conn, $table, $col, $def) {
    $r = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
    if ($r && $r->num_rows == 0) $conn->query("ALTER TABLE `$table` ADD `$col` $def");
}

/* ─── Kiểm tra & tự động nâng hạng + cấp voucher ─── */
function kiemTraVaNangHang($conn, $maKH, $admin_user) {
    $kh = $conn->query("SELECT loaiKhachHang FROM khach_hang WHERE maKH=$maKH")->fetch_assoc();
    if (!$kh) return false;
    $hangHienTai = $kh['loaiKhachHang'] ?? 'Khách truy cập';

    // Không nâng hạng nếu đã là VIP
    if ($hangHienTai === 'Khách hàng VIP') return false;

    $tong_don = (int)$conn->query("SELECT COUNT(*) as c FROM don_hang WHERE maKH=$maKH")->fetch_assoc()['c'];
    $tong_sc  = (int)$conn->query("SELECT COUNT(*) as c FROM phieu_sua_chua WHERE maKH=$maKH")->fetch_assoc()['c'];

    $ngayHetHan = date('Y-m-d', strtotime('+1 year'));

    // ── Nâng lên VIP (ưu tiên kiểm tra trước) ──
    if ($tong_don >= 10 && $tong_sc >= 10 && $hangHienTai !== 'Khách hàng VIP') {
        // Nếu đang ở thân thiết → VIP: cấp thêm 2 bộ voucher (tổng 3 bộ VIP)
        // Nếu nhảy thẳng từ truy cập → VIP: cấp đủ 3 bộ
        $soBoVoucher = ($hangHienTai === 'Khách hàng thân thiết') ? 2 : 3;
        for ($vi = 1; $vi <= $soBoVoucher; $vi++) {
            $conn->query("INSERT INTO voucher_khach_hang (maKH, loaiVoucher, giaTriGiam, moTa, ngayHetHan) VALUES
                ($maKH, 'sua_chua_50pct',  'Giảm 50%',          'Voucher giảm 50% sửa chữa ({$vi}/{$soBoVoucher}) – Phần thưởng Hạng VIP', '$ngayHetHan'),
                ($maKH, 'mua_hang_1trieu', 'Giảm 1.000.000đ',   'Voucher giảm 1.000.000đ mua laptop ({$vi}/{$soBoVoucher}) – Phần thưởng Hạng VIP', '$ngayHetHan')");
        }
        $conn->query("UPDATE khach_hang SET loaiKhachHang='Khách hàng VIP', ngayLenHangVIP=NOW() WHERE maKH=$maKH");
        // Ghi nhật ký tự động
        $conn->query("INSERT INTO nhat_ky_giao_tiep (maKH, nguoiPhuTrach, hinhThuc, noiDung, thoiGian)
            VALUES ($maKH, '$admin_user', '⭐ Nâng hạng tự động',
            '🏆 Khách hàng được tự động nâng lên hạng VIP (≥10 đơn hàng & ≥10 phiếu sửa chữa). Đã cấp {$soBoVoucher} bộ voucher VIP.', NOW())");
        return 'vip';
    }

    // ── Nâng lên Thân thiết ──
    if ($tong_don >= 5 && $tong_sc >= 5 && $hangHienTai === 'Khách truy cập') {
        $conn->query("INSERT INTO voucher_khach_hang (maKH, loaiVoucher, giaTriGiam, moTa, ngayHetHan) VALUES
            ($maKH, 'sua_chua_50pct',  'Giảm 50%',         'Voucher giảm 50% chi phí sửa chữa – Phần thưởng Hạng Thân thiết', '$ngayHetHan'),
            ($maKH, 'mua_hang_1trieu', 'Giảm 1.000.000đ',  'Voucher giảm 1.000.000đ mua laptop – Phần thưởng Hạng Thân thiết', '$ngayHetHan')");
        $conn->query("UPDATE khach_hang SET loaiKhachHang='Khách hàng thân thiết', ngayLenHangThanThiet=NOW() WHERE maKH=$maKH");
        // Ghi nhật ký tự động
        $conn->query("INSERT INTO nhat_ky_giao_tiep (maKH, nguoiPhuTrach, hinhThuc, noiDung, thoiGian)
            VALUES ($maKH, '$admin_user', '⭐ Nâng hạng tự động',
            '💛 Khách hàng được tự động nâng lên hạng Thân thiết (≥5 đơn hàng & ≥5 phiếu sửa chữa). Đã cấp 1 bộ voucher Thân thiết.', NOW())");
        return 'than_thiet';
    }

    return false; // chưa đủ điều kiện hoặc đã đúng hạng
}

/* ══════════════════════════════════════════════════════════
   KHỞI TẠO BẢNG
═══════════════════════════════════════════════════════════ */
$conn->query("CREATE TABLE IF NOT EXISTS `khach_hang` (
    `maKH` int(11) NOT NULL AUTO_INCREMENT,
    `tenKH` varchar(100) NOT NULL,
    `diaChi` varchar(255) DEFAULT NULL,
    `soDienThoai` varchar(15) DEFAULT NULL,
    `email` varchar(100) DEFAULT NULL,
    `loaiKhachHang` varchar(50) DEFAULT 'Khách truy cập',
    `ngayDangKy` date DEFAULT (CURRENT_DATE),
    `trangThai` tinyint(1) DEFAULT 1,
    PRIMARY KEY (`maKH`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `don_hang` (
    `maDH` int(11) NOT NULL AUTO_INCREMENT,
    `maKH` int(11) NOT NULL,
    `ngayDat` date DEFAULT (CURRENT_DATE),
    `diaChiGiaoHang` varchar(255) DEFAULT NULL,
    `phuongThucThanhToan` varchar(100) DEFAULT 'Tiền mặt',
    `tinhTrangThanhToan` varchar(50) DEFAULT 'Chưa thanh toán',
    `kenhBanHang` varchar(50) DEFAULT 'Tại shop',
    `tongTien` double DEFAULT 0,
    `trangThai` varchar(50) DEFAULT 'Chờ duyệt',
    `ghiChu` text DEFAULT NULL,
    PRIMARY KEY (`maDH`),
    FOREIGN KEY (`maKH`) REFERENCES `khach_hang`(`maKH`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `chi_tiet_don_hang` (
    `maCTDH` int(11) NOT NULL AUTO_INCREMENT,
    `maDH` int(11) NOT NULL,
    `maSanPham` varchar(50) DEFAULT NULL,
    `tenSanPham` varchar(255) NOT NULL,
    `soLuong` int(11) DEFAULT 1,
    `donGia` double DEFAULT 0,
    `thanhTien` double DEFAULT 0,
    PRIMARY KEY (`maCTDH`),
    FOREIGN KEY (`maDH`) REFERENCES `don_hang`(`maDH`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `phieu_sua_chua` (
    `maPhieu` int(11) NOT NULL AUTO_INCREMENT,
    `maKH` int(11) NOT NULL,
    `maThietBi` int(11) DEFAULT NULL,
    `tenThietBi` varchar(200) DEFAULT NULL,
    `moTaLoi` text DEFAULT NULL,
    `ngayNhan` date DEFAULT (CURRENT_DATE),
    `ngayTra` date DEFAULT NULL,
    `chiPhi` double DEFAULT 0,
    `trangThai` varchar(50) DEFAULT 'Tiếp nhận',
    PRIMARY KEY (`maPhieu`),
    FOREIGN KEY (`maKH`) REFERENCES `khach_hang`(`maKH`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `bao_hanh` (
    `maBaoHanh` int(11) NOT NULL AUTO_INCREMENT,
    `maKH` int(11) NOT NULL,
    `tenSP` varchar(200) NOT NULL,
    `ngayBatDau` date NOT NULL,
    `ngayKetThuc` date NOT NULL,
    `thoiHan` varchar(50) DEFAULT NULL,
    `dieuKienBaoHanh` text DEFAULT NULL,
    `trangThai` varchar(50) DEFAULT 'Còn bảo hành',
    PRIMARY KEY (`maBaoHanh`),
    FOREIGN KEY (`maKH`) REFERENCES `khach_hang`(`maKH`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `nhat_ky_giao_tiep` (
    `maNK` int(11) NOT NULL AUTO_INCREMENT,
    `maKH` int(11) NOT NULL,
    `nguoiPhuTrach` varchar(100) NOT NULL,
    `hinhThuc` varchar(50) NOT NULL,
    `noiDung` text NOT NULL,
    `thoiGian` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`maNK`),
    FOREIGN KEY (`maKH`) REFERENCES `khach_hang`(`maKH`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `bo_tai_lieu` (
    `maBo` int(11) NOT NULL AUTO_INCREMENT,
    `maKH` int(11) NOT NULL,
    `loaiTaiLieu` varchar(100) NOT NULL,
    `tenTaiLieu` varchar(255) NOT NULL,
    `ngayTaiLen` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`maBo`),
    FOREIGN KEY (`maKH`) REFERENCES `khach_hang`(`maKH`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$conn->query("CREATE TABLE IF NOT EXISTS `chi_tiet_tai_lieu` (
    `maCT` int(11) NOT NULL AUTO_INCREMENT,
    `maBo` int(11) NOT NULL,
    `duongDan` varchar(255) NOT NULL,
    PRIMARY KEY (`maCT`),
    FOREIGN KEY (`maBo`) REFERENCES `bo_tai_lieu`(`maBo`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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

// ── Bảng điều kiện thăng hạng (admin tự chỉnh) ──
$conn->query("CREATE TABLE IF NOT EXISTS `dieu_kien_hang` (
    `maHang`        INT(11) NOT NULL AUTO_INCREMENT,
    `tenHang`       VARCHAR(100) NOT NULL,
    `icon`          VARCHAR(20)  DEFAULT '🥇',
    `mauSac`        VARCHAR(20)  DEFAULT '#d97706',
    `minDon`        INT(11) DEFAULT 0,
    `minSuaChua`    INT(11) DEFAULT 0,
    `soVoucherSC`   INT(11) DEFAULT 1,
    `soVoucherMua`  INT(11) DEFAULT 1,
    `thuTu`         INT(11) DEFAULT 0,
    PRIMARY KEY (`maHang`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Seed dữ liệu mặc định nếu bảng rỗng
$ckSeed = $conn->query("SELECT COUNT(*) as c FROM dieu_kien_hang")->fetch_assoc();
if ((int)$ckSeed['c'] === 0) {
    $conn->query("INSERT INTO dieu_kien_hang (tenHang, icon, mauSac, minDon, minSuaChua, soVoucherSC, soVoucherMua, thuTu) VALUES
        ('Thân thiết', '🥇', '#d97706', 5,  5,  1, 1, 1),
        ('VIP',        '👑', '#7c3aed', 10, 10, 3, 3, 2)");
}

addColSafe($conn, 'khach_hang', 'ngaySinh', 'DATE DEFAULT NULL COMMENT \'Ngay sinh khach hang\'');
addColSafe($conn, 'don_hang', 'ghiChu', 'TEXT DEFAULT NULL');
addColSafe($conn, 'don_hang', 'kenhBanHang', "VARCHAR(50) DEFAULT 'Tại shop'");
addColSafe($conn, 'don_hang', 'phuongThucThanhToan', "VARCHAR(50) DEFAULT 'Tiền mặt'");
addColSafe($conn, 'don_hang', 'tinhTrangThanhToan', "VARCHAR(50) DEFAULT 'Chưa thanh toán'");
addColSafe($conn, 'don_hang', 'la_don_laptop', "TINYINT(1) DEFAULT 0 COMMENT '1 = đơn có laptop, admin tự tick'");
addColSafe($conn, 'chi_tiet_don_hang', 'maSanPham', 'VARCHAR(50) DEFAULT NULL');
addColSafe($conn, 'phieu_sua_chua', 'maThietBi', 'INT DEFAULT NULL');
addColSafe($conn, 'phieu_sua_chua', 'chi_phi_goc', "DOUBLE DEFAULT 0 COMMENT 'Chi phi goc truoc khi ap voucher'");

// Mở rộng cột loaiAnh nếu chưa hỗ trợ giá trị 'loi'
$colInfo = $conn->query("SHOW COLUMNS FROM `anh_phieu_sua` LIKE 'loaiAnh'")->fetch_assoc();
if ($colInfo && strpos($colInfo['Type'], 'loi') === false) {
    $conn->query("ALTER TABLE `anh_phieu_sua` MODIFY `loaiAnh` VARCHAR(20) DEFAULT 'truoc'");
}
addColSafe($conn, 'bao_hanh', 'thoiHan', 'VARCHAR(50) DEFAULT NULL');

/* ══════════════════════════════════════════════════════════
   XỬ LÝ POST: THÊM MỚI
═══════════════════════════════════════════════════════════ */

/* ── Helper validate phía server ── */
function validatePhoneServer($sdt) {
    return empty($sdt) || preg_match('/^[0-9]{10}$/', $sdt);
}
function validateEmailServer($email) {
    return empty($email) || (strpos($email, '@') !== false && filter_var($email, FILTER_VALIDATE_EMAIL));
}
function validateDateNotFutureServer($date) {
    return empty($date) || $date <= date('Y-m-d');
}


// Thêm nhật ký giao tiếp
if (isset($_POST['them_nhat_ky'])) {
    $hinhThuc = $conn->real_escape_string($_POST['hinhThuc']);
    $noiDung  = $conn->real_escape_string($_POST['noiDung']);
    $conn->query("INSERT INTO nhat_ky_giao_tiep (maKH, nguoiPhuTrach, hinhThuc, noiDung) VALUES ($maKH, '$admin_user', '$hinhThuc', '$noiDung')");
    header("Location: chi_tiet_khach_hang.php?id=$maKH&tab=nhat-ky"); exit();
}

// Thêm đơn hàng chi tiết
if (isset($_POST['them_don_hang_chi_tiet'])) {
    $ngayDat   = $conn->real_escape_string($_POST['ngayDat']);
    $trangThai = $conn->real_escape_string($_POST['trangThai']);
    $ghiChu    = $conn->real_escape_string($_POST['ghiChu'] ?? '');
    $kenh      = $conn->real_escape_string($_POST['kenhBanHang'] ?? 'Tại shop');
    $pttt      = $conn->real_escape_string($_POST['phuongThucThanhToan'] ?? 'Tiền mặt');
    $tttt      = $conn->real_escape_string($_POST['tinhTrangThanhToan'] ?? 'Chưa thanh toán');
    $diaChiGH  = $conn->real_escape_string($_POST['diaChiGiaoHang'] ?? '');
    $laDonLaptop = (isset($_POST['co_laptop']) && $_POST['co_laptop'] == '1') ? 1 : 0;
    if (!validateDateNotFutureServer($ngayDat)) {
        $_SESSION['_qa_err'] = 'Ngày đặt hàng không được lớn hơn ngày hiện tại.';
        header("Location: chi_tiet_khach_hang.php?id=$maKH&tab=don-hang"); exit();
    }
    $conn->query("INSERT INTO don_hang (maKH, ngayDat, trangThai, ghiChu, kenhBanHang, phuongThucThanhToan, tinhTrangThanhToan, diaChiGiaoHang, tongTien, la_don_laptop)
                  VALUES ($maKH, '$ngayDat', '$trangThai', '$ghiChu', '$kenh', '$pttt', '$tttt', '$diaChiGH', 0, $laDonLaptop)");
    $maDH = $conn->insert_id;
    $tongTienDon = 0;
    if (isset($_POST['tenSP']) && is_array($_POST['tenSP'])) {
        for ($i = 0; $i < count($_POST['tenSP']); $i++) {
            if (!empty($_POST['tenSP'][$i])) {
                $maSanPham = $conn->real_escape_string($_POST['maSP'][$i] ?? '');
                $tenSP     = $conn->real_escape_string($_POST['tenSP'][$i]);
                $soLuong   = (int)($_POST['soLuong'][$i] ?? 1);
                $donGia    = (double)($_POST['donGia'][$i] ?? 0);
                $thanhTien = $soLuong * $donGia;
                $tongTienDon += $thanhTien;
                $conn->query("INSERT INTO chi_tiet_don_hang (maDH, maSanPham, tenSanPham, soLuong, donGia, thanhTien)
                              VALUES ($maDH, '$maSanPham', '$tenSP', $soLuong, $donGia, $thanhTien)");
            }
        }
    }
    // Áp dụng voucher đơn hàng - hỗ trợ tất cả loại voucher (mua hàng, sinh nhật, tùy chỉnh)
    $giam_mh = 0;
    $maVoucherDung = (int)($_POST['ap_voucher_mua_hang'] ?? 0);
    $laDonLaptop   = (isset($_POST['co_laptop']) && $_POST['co_laptop'] == '1') ? 1 : 0;
    if ($maVoucherDung > 0) {
        $vRow = $conn->query("SELECT * FROM voucher_khach_hang WHERE maVoucher=$maVoucherDung AND maKH=$maKH AND trangThai='Chưa dùng' AND (ngayHetHan IS NULL OR ngayHetHan >= CURDATE())")->fetch_assoc();
        if ($vRow) {
            $loaiV       = $vRow['loaiVoucher'];
            $loaiGiam    = $vRow['loai_giam'] ?? 'vnd';
            $giaTriSo    = (double)($vRow['gia_tri_so'] ?? 0);
            $soTienToiDa = (double)($vRow['so_tien_toi_da'] ?? 0);
            if ($loaiV === 'mua_hang_1trieu') {
                $giam_mh = min(1000000, $tongTienDon);
            } elseif ($loaiV === 'sinh_nhat_10pct') {
                $giamTinh = round($tongTienDon * 10 / 100);
                $giam_mh  = $soTienToiDa > 0 ? min($giamTinh, $soTienToiDa) : $giamTinh;
                $giam_mh  = min($giam_mh, $tongTienDon);
            } elseif ($loaiGiam === 'pct' && $giaTriSo > 0) {
                $giamTinh = round($tongTienDon * $giaTriSo / 100);
                $giam_mh  = $soTienToiDa > 0 ? min($giamTinh, $soTienToiDa) : $giamTinh;
                $giam_mh  = min($giam_mh, $tongTienDon);
            } elseif ($loaiGiam === 'vnd' && $giaTriSo > 0) {
                $giam_mh  = $soTienToiDa > 0 ? min($giaTriSo, $soTienToiDa) : $giaTriSo;
                $giam_mh  = min($giam_mh, $tongTienDon);
            } else {
                $giam_mh = min(1000000, $tongTienDon); // fallback
            }
            $conn->query("UPDATE voucher_khach_hang SET trangThai='Đã dùng' WHERE maVoucher=$maVoucherDung");
        }
    }
    $tongTienSauGiam = max(0, $tongTienDon - $giam_mh);
    if ($giam_mh > 0) {
        $ghiChuVoucher = $conn->real_escape_string(($ghiChu ? $ghiChu . "\n" : '') . "✅ Voucher giảm " . number_format($giam_mh, 0, ',', '.') . "đ (VC-" . str_pad($maVoucherDung, 5, '0', STR_PAD_LEFT) . ") | Gốc: " . number_format($tongTienDon,0,',','.') . "đ");
        $conn->query("UPDATE don_hang SET tongTien=$tongTienSauGiam, ghiChu='$ghiChuVoucher', la_don_laptop=$laDonLaptop WHERE maDH=$maDH");
    } else {
        $conn->query("UPDATE don_hang SET tongTien=$tongTienDon, la_don_laptop=$laDonLaptop WHERE maDH=$maDH");
    }
    // Tự động thêm nhật ký khi tạo đơn hàng (ĐẶT TRƯỚC header)
$autoLogOrder = "🛒 Tạo đơn hàng #QA-$maDH - Tổng tiền: " . number_format($tongTienSauGiam, 0, ',', '.') . "đ";
$conn->query("INSERT INTO nhat_ky_giao_tiep (maKH, nguoiPhuTrach, hinhThuc, noiDung, thoiGian) 
              VALUES ($maKH, '$admin_user', '🛒 Tạo đơn hàng', '$autoLogOrder', NOW())");
    // Tự động kiểm tra & nâng hạng sau khi tạo đơn
    $rankResult = kiemTraVaNangHang($conn, $maKH, $admin_user);
    $rankParam  = $rankResult ? '&rank_up=1' : '';
    header("Location: chi_tiet_khach_hang.php?id=$maKH&tab=don-hang$rankParam"); exit();
    
}

// Thêm phiếu sửa chữa
if (isset($_POST['them_phieu_sua'])) {
    $tenThietBi = $conn->real_escape_string($_POST['tenThietBi'] ?? '');
    $moTaLoi    = $conn->real_escape_string($_POST['moTaLoi'] ?? '');
    $ngayNhan   = $conn->real_escape_string($_POST['ngayNhan']);
    $ngayTra    = !empty($_POST['ngayTra']) ? "'" . $conn->real_escape_string($_POST['ngayTra']) . "'" : 'NULL';
    $chiPhi     = (double)($_POST['chiPhi'] ?? 0);
    $trangThai  = $conn->real_escape_string($_POST['trangThai']);
    $chiPhiGoc  = $chiPhi; // lưu giá gốc trước khi áp voucher
    if (!validateDateNotFutureServer($ngayNhan)) {
        $_SESSION['_qa_err'] = 'Ngày nhận sửa chữa không được lớn hơn ngày hiện tại.';
        header("Location: chi_tiet_khach_hang.php?id=$maKH&tab=ky-thuat"); exit();
    }
    // Áp dụng voucher sửa chữa – hỗ trợ cả 50% cố định và voucher tự soạn
    $giam_sc = 0;
    $maVoucherSC = (int)($_POST['ap_voucher_sua_chua'] ?? 0);
    if ($maVoucherSC > 0 && $chiPhi > 0) {
        $vRow = $conn->query("SELECT * FROM voucher_khach_hang WHERE maVoucher=$maVoucherSC AND maKH=$maKH AND trangThai='Chưa dùng'")->fetch_assoc();
        if ($vRow) {
            $loaiV = $vRow['loaiVoucher'];
            $loaiGiam = $vRow['loai_giam'] ?? 'pct';
            $giaTriSo = (double)($vRow['gia_tri_so'] ?? 50);
            $soTienToiDa = (double)($vRow['so_tien_toi_da'] ?? 0);
            if ($loaiV === 'sua_chua_50pct' || $loaiV === 'sinh_nhat_sc') {
                // Voucher cố định 50%, tối đa 500k
                $giam_sc = min(round($chiPhi * 0.5), 500000);
            } elseif ($loaiGiam === 'pct' && $giaTriSo > 0) {
                $giamTinh = round($chiPhi * $giaTriSo / 100);
                $giam_sc = $soTienToiDa > 0 ? min($giamTinh, $soTienToiDa) : $giamTinh;
                $giam_sc = min($giam_sc, $chiPhi);
            } elseif ($loaiGiam === 'vnd' && $giaTriSo > 0) {
                $giam_sc = $soTienToiDa > 0 ? min($giaTriSo, $soTienToiDa) : $giaTriSo;
                $giam_sc = min($giam_sc, $chiPhi);
            } else {
                $giam_sc = min(round($chiPhi * 0.5), 500000); // fallback
            }
            $chiPhi = max(0, $chiPhi - $giam_sc);
            $conn->query("UPDATE voucher_khach_hang SET trangThai='Đã dùng' WHERE maVoucher=$maVoucherSC");
            $moTaLoi .= ($moTaLoi ? " | " : "") . "✅ Voucher SC giảm " . number_format($giam_sc, 0, ',', '.') . "đ (VC-" . str_pad($maVoucherSC, 5, '0', STR_PAD_LEFT) . ")";
        }
    }
    $moTaLoiEsc = $conn->real_escape_string($moTaLoi);
    $conn->query("INSERT INTO phieu_sua_chua (maKH, tenThietBi, moTaLoi, ngayNhan, ngayTra, chiPhi, chi_phi_goc, trangThai)
                  VALUES ($maKH, '$tenThietBi', '$moTaLoiEsc', '$ngayNhan', $ngayTra, $chiPhi, $chiPhiGoc, '$trangThai')");
    $maPhieuMoi = $conn->insert_id;

    // Lưu ảnh đính kèm upload từ form tạo phiếu (anhThietBi[])
    if ($maPhieuMoi && isset($_FILES['anhThietBi']) && !empty($_FILES['anhThietBi']['name'][0])) {
        $loaiAnh_up  = $conn->real_escape_string($_POST['loaiAnhUpload'] ?? 'truoc');
        $moTaAnh_up  = $conn->real_escape_string($_POST['moTaAnh'] ?? '');
        $upload_dir  = '../uploads/repair_images/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
        $allowedExts = ['jpg','jpeg','png','webp'];
        $maxSize     = 5 * 1024 * 1024; // 5MB
        $count       = min(5, count($_FILES['anhThietBi']['name']));
        for ($ai = 0; $ai < $count; $ai++) {
            if ($_FILES['anhThietBi']['error'][$ai] !== 0) continue;
            if ($_FILES['anhThietBi']['size'][$ai] > $maxSize) continue;
            $ext = strtolower(pathinfo($_FILES['anhThietBi']['name'][$ai], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExts)) continue;
            $fname = time() . '_' . $maPhieuMoi . '_' . $ai . '_' . $loaiAnh_up . '.' . $ext;
            if (move_uploaded_file($_FILES['anhThietBi']['tmp_name'][$ai], $upload_dir . $fname)) {
                $duongDan = $conn->real_escape_string('uploads/repair_images/' . $fname);
                $conn->query("INSERT INTO anh_phieu_sua (maPhieu, duongDan, loaiAnh, moTa, nguoiUpload)
                              VALUES ($maPhieuMoi, '$duongDan', '$loaiAnh_up', '$moTaAnh_up', '$admin_user')");
            }
        }
    }

    // Tự động thêm nhật ký khi tạo phiếu sửa chữa (ĐẶT TRƯỚC header)
$autoLogContent = "🔧 Tạo phiếu sửa chữa #SC-$maPhieuMoi - Thiết bị: $tenThietBi - Tình trạng: $trangThai";
if (!empty($moTaLoi)) {
    $autoLogContent .= " - Mô tả: " . substr($moTaLoi, 0, 100);
}
$conn->query("INSERT INTO nhat_ky_giao_tiep (maKH, nguoiPhuTrach, hinhThuc, noiDung, thoiGian) 
              VALUES ($maKH, '$admin_user', '🔧 Tạo phiếu sửa chữa', '$autoLogContent', NOW())");

    // Tự động kiểm tra & nâng hạng sau khi tạo phiếu sửa
    $rankResult = kiemTraVaNangHang($conn, $maKH, $admin_user);
    $rankParam  = $rankResult ? '&rank_up=1' : '';
    header("Location: chi_tiet_khach_hang.php?id=$maKH&tab=ky-thuat$rankParam"); exit();
    
}

// Thêm bảo hành
if (isset($_POST['them_bao_hanh'])) {
    $tenSP       = $conn->real_escape_string($_POST['tenSP']);
    $ngayBD      = $conn->real_escape_string($_POST['ngayBatDau']);
    $ngayKT      = $conn->real_escape_string($_POST['ngayKetThuc']);
    $thoiHan     = $conn->real_escape_string($_POST['thoiHan'] ?? '');
    $dieuKien    = $conn->real_escape_string($_POST['dieuKienBaoHanh'] ?? '');
    $trangThai   = $conn->real_escape_string($_POST['trangThai']);
    $conn->query("INSERT INTO bao_hanh (maKH, tenSP, ngayBatDau, ngayKetThuc, thoiHan, dieuKienBaoHanh, trangThai)
                  VALUES ($maKH, '$tenSP', '$ngayBD', '$ngayKT', '$thoiHan', '$dieuKien', '$trangThai')");
    header("Location: chi_tiet_khach_hang.php?id=$maKH&tab=ky-thuat"); exit();
}

// Tải lên tài liệu
if (isset($_POST['tai_len_tai_lieu'])) {
    $loaiTaiLieu = $conn->real_escape_string($_POST['loaiTaiLieu']);
    $tenTaiLieu  = $conn->real_escape_string($_POST['tenTaiLieu']);
    $upload_dir  = '../uploads/documents/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
    if (isset($_FILES['fileTaiLieu']) && !empty($_FILES['fileTaiLieu']['name'][0])) {
        $conn->query("INSERT INTO bo_tai_lieu (maKH, loaiTaiLieu, tenTaiLieu) VALUES ($maKH, '$loaiTaiLieu', '$tenTaiLieu')");
        $maBo = $conn->insert_id;
        for ($i = 0; $i < count($_FILES['fileTaiLieu']['name']); $i++) {
            if ($_FILES['fileTaiLieu']['error'][$i] == 0) {
                $file_name   = time() . '_' . $i . '_' . basename($_FILES['fileTaiLieu']['name'][$i]);
                $target_file = $upload_dir . $file_name;
                $allowed_ext = ['jpg','jpeg','png','pdf','docx','doc'];
                if (in_array(strtolower(pathinfo($target_file, PATHINFO_EXTENSION)), $allowed_ext)) {
                    if (move_uploaded_file($_FILES['fileTaiLieu']['tmp_name'][$i], $target_file))
                        $conn->query("INSERT INTO chi_tiet_tai_lieu (maBo, duongDan) VALUES ($maBo, 'uploads/documents/$file_name')");
                }
            }
        }
    }
    header("Location: chi_tiet_khach_hang.php?id=$maKH&tab=tai-lieu"); exit();
}

// Upload ảnh sửa chữa
if (isset($_POST['upload_anh_sua'])) {
    $maPhieu     = (int)$_POST['maPhieu'];
    $loaiAnh     = $conn->real_escape_string($_POST['loaiAnh']);
    $moTa        = $conn->real_escape_string($_POST['moTa'] ?? '');
    $nguoiUpload = $admin_user;
    $upload_dir  = '../uploads/repair_images/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
    if (isset($_FILES['fileAnh']) && $_FILES['fileAnh']['error'] == 0) {
        $ext = strtolower(pathinfo($_FILES['fileAnh']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png'])) {
            $fname = time() . '_' . $maPhieu . '_' . $loaiAnh . '.' . $ext;
            if (move_uploaded_file($_FILES['fileAnh']['tmp_name'], $upload_dir . $fname))
                $conn->query("INSERT INTO anh_phieu_sua (maPhieu, duongDan, loaiAnh, moTa, nguoiUpload)
                              VALUES ($maPhieu, 'uploads/repair_images/$fname', '$loaiAnh', '$moTa', '$nguoiUpload')");
        }
    }
    header("Location: chi_tiet_khach_hang.php?id=$maKH&tab=ky-thuat"); exit();
}

/* ══════════════════════════════════════════════════════════
   XỬ LÝ POST: CẬP NHẬT
═══════════════════════════════════════════════════════════ */

// Sửa thông tin khách hàng
if (isset($_POST['sua_thong_tin_kh'])) {
    $tenKH      = $conn->real_escape_string($_POST['tenKH']);
    $sdt        = $conn->real_escape_string($_POST['soDienThoai']);
    $email      = $conn->real_escape_string($_POST['email']);
    $diaChi     = $conn->real_escape_string($_POST['diaChi']);
    $loaiKH     = $conn->real_escape_string($_POST['loaiKhachHang']);
    $ts         = (int)($_POST['trangThai'] ?? 1);
    $ngayDangKy = $conn->real_escape_string($_POST['ngayDangKy'] ?? date('Y-m-d'));
    $ngaySinhRaw = trim($_POST['ngaySinh'] ?? '');
    $ngaySinhSQL = !empty($ngaySinhRaw) ? "'" . $conn->real_escape_string($ngaySinhRaw) . "'" : 'NULL';
    $errMsg  = [];
    if (!validatePhoneServer($sdt))  $errMsg[] = 'Số điện thoại phải đúng 10 chữ số.';
    if (!validateEmailServer($email)) $errMsg[] = 'Email không hợp lệ (phải có @).';
    if (!validateDateNotFutureServer($ngayDangKy)) $errMsg[] = 'Ngày đăng ký không được lớn hơn ngày hiện tại.';
    if (!empty($ngaySinhRaw) && !validateDateNotFutureServer($ngaySinhRaw)) $errMsg[] = 'Ngày sinh không được lớn hơn ngày hiện tại.';
    if (empty($errMsg)) {
        $conn->query("UPDATE khach_hang SET tenKH='$tenKH', soDienThoai='$sdt', email='$email', diaChi='$diaChi', loaiKhachHang='$loaiKH', trangThai=$ts, ngayDangKy='$ngayDangKy', ngaySinh=$ngaySinhSQL WHERE maKH=$maKH");
        header("Location: chi_tiet_khach_hang.php?id=$maKH"); exit();
    } else {
        $_SESSION['_qa_err'] = implode(' ', $errMsg);
        header("Location: chi_tiet_khach_hang.php?id=$maKH"); exit();
    }
}

// Sửa đơn hàng
if (isset($_POST['sua_don_hang'])) {
    $maDH  = (int)$_POST['maDH'];
    $ngayDat = $conn->real_escape_string($_POST['ngayDat']);
    $trangThai = $conn->real_escape_string($_POST['trangThai']);
    $ghiChu  = $conn->real_escape_string($_POST['ghiChu'] ?? '');
    $kenh    = $conn->real_escape_string($_POST['kenhBanHang'] ?? 'Tại shop');
    $pttt    = $conn->real_escape_string($_POST['phuongThucThanhToan'] ?? 'Tiền mặt');
    $tttt    = $conn->real_escape_string($_POST['tinhTrangThanhToan'] ?? 'Chưa thanh toán');
    if (!validateDateNotFutureServer($ngayDat)) {
        $_SESSION['_qa_err'] = 'Ngày đặt hàng không được lớn hơn ngày hiện tại.';
        header("Location: chi_tiet_khach_hang.php?id=$maKH&tab=don-hang"); exit();
    }
    $conn->query("UPDATE don_hang SET ngayDat='$ngayDat', trangThai='$trangThai', ghiChu='$ghiChu', kenhBanHang='$kenh', phuongThucThanhToan='$pttt', tinhTrangThanhToan='$tttt' WHERE maDH=$maDH");
    header("Location: chi_tiet_khach_hang.php?id=$maKH&tab=don-hang"); exit();
}

// Sửa nhật ký
if (isset($_POST['sua_nhat_ky'])) {
    $maNK     = (int)$_POST['maNK'];
    $hinhThuc = $conn->real_escape_string($_POST['hinhThuc']);
    $noiDung  = $conn->real_escape_string($_POST['noiDung']);
    $conn->query("UPDATE nhat_ky_giao_tiep SET hinhThuc='$hinhThuc', noiDung='$noiDung' WHERE maNK=$maNK AND maKH=$maKH");
    header("Location: chi_tiet_khach_hang.php?id=$maKH&tab=nhat-ky"); exit();
}

// Sửa phiếu sửa chữa
if (isset($_POST['sua_phieu_sua'])) {
    $maPhieu    = (int)$_POST['maPhieu'];
    $tenThietBi = $conn->real_escape_string($_POST['tenThietBi'] ?? '');
    $moTaLoi    = $conn->real_escape_string($_POST['moTaLoi'] ?? '');
    $ngayNhan   = $conn->real_escape_string($_POST['ngayNhan']);
    $ngayTra    = !empty($_POST['ngayTra']) ? "'" . $conn->real_escape_string($_POST['ngayTra']) . "'" : 'NULL';
    $chiPhi     = (double)($_POST['chiPhi'] ?? 0);
    $trangThai  = $conn->real_escape_string($_POST['trangThai']);
    $conn->query("UPDATE phieu_sua_chua SET tenThietBi='$tenThietBi', moTaLoi='$moTaLoi', ngayNhan='$ngayNhan', ngayTra=$ngayTra, chiPhi=$chiPhi, trangThai='$trangThai' WHERE maPhieu=$maPhieu");
    header("Location: chi_tiet_khach_hang.php?id=$maKH&tab=ky-thuat"); exit();
}

// Sửa bảo hành
if (isset($_POST['sua_bao_hanh'])) {
    $maBaoHanh   = (int)$_POST['maBaoHanh'];
    $tenSP       = $conn->real_escape_string($_POST['tenSP']);
    $ngayBD      = $conn->real_escape_string($_POST['ngayBatDau']);
    $ngayKT      = $conn->real_escape_string($_POST['ngayKetThuc']);
    $thoiHan     = $conn->real_escape_string($_POST['thoiHan'] ?? '');
    $dieuKien    = $conn->real_escape_string($_POST['dieuKienBaoHanh'] ?? '');
    $trangThai   = $conn->real_escape_string($_POST['trangThai']);
    $conn->query("UPDATE bao_hanh SET tenSP='$tenSP', ngayBatDau='$ngayBD', ngayKetThuc='$ngayKT', thoiHan='$thoiHan', dieuKienBaoHanh='$dieuKien', trangThai='$trangThai' WHERE maBaoHanh=$maBaoHanh");
    header("Location: chi_tiet_khach_hang.php?id=$maKH&tab=ky-thuat"); exit();
}

/* ══════════════════════════════════════════════════════════
   XỬ LÝ GET: XÓA
═══════════════════════════════════════════════════════════ */
if (isset($_GET['xoa_nk'])) {
    $maNK_xoa = (int)$_GET['xoa_nk'];
    // Kiểm tra nhật ký có phải loại chúc sinh nhật không
    $nk_check = $conn->query("SELECT hinhThuc, thoiGian FROM nhat_ky_giao_tiep WHERE maNK=$maNK_xoa AND maKH=$maKH LIMIT 1");
    if ($nk_check && $nk_row = $nk_check->fetch_assoc()) {
        if ($nk_row['hinhThuc'] === '🎂 Chúc sinh nhật') {
            // Xóa voucher sinh nhật được tạo cùng thời điểm (±1 phút), còn Chưa dùng
            $thoiGianNK = $conn->real_escape_string($nk_row['thoiGian']);
            $conn->query("DELETE FROM voucher_khach_hang
                          WHERE maKH = $maKH
                            AND trangThai = 'Chưa dùng'
                            AND loaiVoucher IN ('sinh_nhat_10pct','sinh_nhat_sc','tu_soan_sc','tu_soan_mh','tu_soan')
                            AND ngayTao BETWEEN DATE_SUB('$thoiGianNK', INTERVAL 1 MINUTE)
                                            AND DATE_ADD('$thoiGianNK', INTERVAL 1 MINUTE)");
        }
    }
    $conn->query("DELETE FROM nhat_ky_giao_tiep WHERE maNK=$maNK_xoa AND maKH=$maKH");
    header("Location: chi_tiet_khach_hang.php?id=$maKH&tab=nhat-ky"); exit();
}
if (isset($_GET['xoa_dh']))    { $conn->query("DELETE FROM don_hang WHERE maDH=" . (int)$_GET['xoa_dh']); header("Location: chi_tiet_khach_hang.php?id=$maKH&tab=don-hang"); exit(); }
if (isset($_GET['xoa_ps']))    { $conn->query("DELETE FROM phieu_sua_chua WHERE maPhieu=" . (int)$_GET['xoa_ps']); header("Location: chi_tiet_khach_hang.php?id=$maKH&tab=ky-thuat"); exit(); }
if (isset($_GET['xoa_bh']))    { $conn->query("DELETE FROM bao_hanh WHERE maBaoHanh=" . (int)$_GET['xoa_bh']); header("Location: chi_tiet_khach_hang.php?id=$maKH&tab=ky-thuat"); exit(); }

// ── CRUD điều kiện thăng hạng ──
if (isset($_POST['them_dieu_kien_hang'])) {
    $tenHang   = $conn->real_escape_string(trim($_POST['tenHang'] ?? ''));
    $icon      = $conn->real_escape_string(trim($_POST['icon'] ?? '🏅'));
    $mauSac    = $conn->real_escape_string(trim($_POST['mauSac'] ?? '#374151'));
    $minDon    = (int)($_POST['minDon'] ?? 0);
    $minSC     = (int)($_POST['minSuaChua'] ?? 0);
    $soVC_SC   = (int)($_POST['soVoucherSC'] ?? 0);
    $soVC_MH   = (int)($_POST['soVoucherMua'] ?? 0);
    $thuTu     = (int)$conn->query("SELECT IFNULL(MAX(thuTu),0)+1 as n FROM dieu_kien_hang")->fetch_assoc()['n'];
    if ($tenHang !== '') {
        $conn->query("INSERT INTO dieu_kien_hang (tenHang,icon,mauSac,minDon,minSuaChua,soVoucherSC,soVoucherMua,thuTu)
                      VALUES ('$tenHang','$icon','$mauSac',$minDon,$minSC,$soVC_SC,$soVC_MH,$thuTu)");
    }
    header("Location: chi_tiet_khach_hang.php?id=$maKH#rank-conditions"); exit();
}
if (isset($_POST['sua_dieu_kien_hang'])) {
    $maHang    = (int)$_POST['maHang'];
    $tenHang   = $conn->real_escape_string(trim($_POST['tenHang'] ?? ''));
    $icon      = $conn->real_escape_string(trim($_POST['icon'] ?? '🏅'));
    $mauSac    = $conn->real_escape_string(trim($_POST['mauSac'] ?? '#374151'));
    $minDon    = (int)($_POST['minDon'] ?? 0);
    $minSC     = (int)($_POST['minSuaChua'] ?? 0);
    $soVC_SC   = (int)($_POST['soVoucherSC'] ?? 0);
    $soVC_MH   = (int)($_POST['soVoucherMua'] ?? 0);
    $conn->query("UPDATE dieu_kien_hang SET tenHang='$tenHang',icon='$icon',mauSac='$mauSac',minDon=$minDon,minSuaChua=$minSC,soVoucherSC=$soVC_SC,soVoucherMua=$soVC_MH WHERE maHang=$maHang");
    header("Location: chi_tiet_khach_hang.php?id=$maKH#rank-conditions"); exit();
}
if (isset($_GET['xoa_dk_hang'])) {
    $conn->query("DELETE FROM dieu_kien_hang WHERE maHang=" . (int)$_GET['xoa_dk_hang']);
    header("Location: chi_tiet_khach_hang.php?id=$maKH#rank-conditions"); exit();
}
if (isset($_GET['xoa_bo_tl'])) {
    $maBo = (int)$_GET['xoa_bo_tl'];
    $files = $conn->query("SELECT duongDan FROM chi_tiet_tai_lieu WHERE maBo=$maBo");
    while ($f = $files->fetch_assoc()) { @unlink('../' . $f['duongDan']); }
    $conn->query("DELETE FROM bo_tai_lieu WHERE maBo=$maBo AND maKH=$maKH");
    header("Location: chi_tiet_khach_hang.php?id=$maKH&tab=tai-lieu"); exit();
}
if (isset($_GET['xoa_anh'])) {
    $maAnh = (int)$_GET['xoa_anh'];
    $r = $conn->query("SELECT duongDan FROM anh_phieu_sua WHERE maAnh=$maAnh");
    if ($r && $r->num_rows) { $a = $r->fetch_assoc(); @unlink('../' . $a['duongDan']); }
    $conn->query("DELETE FROM anh_phieu_sua WHERE maAnh=$maAnh");
    header("Location: chi_tiet_khach_hang.php?id=$maKH&tab=ky-thuat"); exit();
}

/* ══════════════════════════════════════════════════════════
   LẤY DỮ LIỆU
═══════════════════════════════════════════════════════════ */
$khach_hang = $conn->query("SELECT * FROM khach_hang WHERE maKH=$maKH")->fetch_assoc();
if (!$khach_hang) { echo "<div style='padding:60px;font-family:sans-serif;color:red;text-align:center;'><h2>Khách hàng không tồn tại!</h2><a href='quan_ly_khach_hang.php'>← Quay lại</a></div>"; exit(); }

$dieu_kien_hang_res = $conn->query("SELECT * FROM dieu_kien_hang ORDER BY thuTu ASC, maHang ASC");
$don_hang        = $conn->query("SELECT * FROM don_hang WHERE maKH=$maKH ORDER BY ngayDat DESC");
$phieu_sua       = $conn->query("SELECT * FROM phieu_sua_chua WHERE maKH=$maKH ORDER BY ngayNhan DESC");
$bao_hanh        = $conn->query("SELECT * FROM bao_hanh WHERE maKH=$maKH ORDER BY ngayBatDau DESC");
$nhat_ky         = $conn->query("SELECT * FROM nhat_ky_giao_tiep WHERE maKH=$maKH ORDER BY thoiGian DESC");
$bo_tai_lieu_res = $conn->query("SELECT * FROM bo_tai_lieu WHERE maKH=$maKH ORDER BY ngayTaiLen DESC");

$tong_don  = $don_hang->num_rows;
$tong_sc   = $phieu_sua->num_rows;
$tong_bh   = $bao_hanh->num_rows;
$tong_nk   = $nhat_ky->num_rows;
$tongTien  = $conn->query("SELECT SUM(tongTien) as total FROM don_hang WHERE maKH=$maKH")->fetch_assoc()['total'] ?? 0;

$don_hang->data_seek(0); $phieu_sua->data_seek(0); $bao_hanh->data_seek(0); $nhat_ky->data_seek(0); $bo_tai_lieu_res->data_seek(0);

// ========== KIỂM TRA SINH NHẬT ==========
$birthdayAlert = null;
if (!empty($khach_hang['ngaySinh'])) {
    $ngaySinh = $khach_hang['ngaySinh'];
    $today = date('Y-m-d');
    $bdThisYear = date('Y') . '-' . date('m-d', strtotime($ngaySinh));
    $daysUntilBirthday = (int)floor((strtotime($bdThisYear) - strtotime($today)) / 86400);
    // Nếu đã qua sinh nhật năm nay, tính cho năm sau
    if ($daysUntilBirthday < 0) {
        $bdNextYear = (date('Y')+1) . '-' . date('m-d', strtotime($ngaySinh));
        $daysUntilBirthday = (int)floor((strtotime($bdNextYear) - strtotime($today)) / 86400);
    }
    // Tính tuổi chính xác bằng DateTime::diff
    $dtSinh  = new DateTime($ngaySinh);
    $dtToday = new DateTime($today);
    $tuoiKH  = (int)$dtToday->diff($dtSinh)->y;   // tuổi đã tròn tính đến hôm nay
    $tuoiSapTron = $tuoiKH + 1;                     // tuổi sẽ tròn vào sinh nhật sắp tới

    // Kiểm tra xem đã gửi lời chúc sinh nhật trong năm nay chưa
    $namHienTai = date('Y');
    $bdDaGuiNamNay = $conn->query("
        SELECT maNK FROM nhat_ky_giao_tiep
        WHERE maKH = $maKH
          AND hinhThuc = '🎂 Chúc sinh nhật'
          AND YEAR(thoiGian) = $namHienTai
        LIMIT 1
    ");
    $daDGuiSinhNhat = ($bdDaGuiNamNay && $bdDaGuiNamNay->num_rows > 0);

    if (!$daDGuiSinhNhat) {
        if ($daysUntilBirthday === 0) {
            // Hôm nay đúng sinh nhật → tuổi = $tuoiKH (đã tròn hôm nay, không cộng thêm)
            $birthdayAlert = ['type'=>'birthday_today', 'days'=>0, 'tuoi'=>$tuoiKH, 'ngaySinh'=>$ngaySinh];
            $alerts[] = [
                'type' => 'success',
                'icon' => 'fas fa-birthday-cake',
                'title' => '🎂 Hôm nay là sinh nhật khách hàng!',
                'message' => htmlspecialchars($khach_hang['tenKH']) . ' tròn ' . $tuoiKH . ' tuổi hôm nay (' . date('d/m', strtotime($ngaySinh)) . '). Hãy gửi lời chúc và ưu đãi sinh nhật!',
                'action' => 'openBirthdayModal',
                'actionText' => 'Gửi lời chúc ngay'
            ];
        } elseif ($daysUntilBirthday <= 7) {
            // Sắp sinh nhật → tuổi sẽ tròn = $tuoiSapTron
            $birthdayAlert = ['type'=>'birthday_soon', 'days'=>$daysUntilBirthday, 'tuoi'=>$tuoiSapTron, 'ngaySinh'=>$ngaySinh];
            $reminders[] = [
                'type' => 'info',
                'icon' => 'fas fa-birthday-cake',
                'title' => "🎁 Sinh nhật trong $daysUntilBirthday ngày nữa!",
                'message' => htmlspecialchars($khach_hang['tenKH']) . ' sẽ tròn ' . $tuoiSapTron . ' tuổi vào ngày ' . date('d/m', strtotime($ngaySinh)) . '. Chuẩn bị ưu đãi sinh nhật sớm!',
                'action' => 'openBirthdayModal',
                'actionText' => 'Soạn lời chúc'
            ];
        }
    }
    // Nếu đã gửi rồi thì không hiển thị thông báo, $birthdayAlert vẫn = null
}

// ========== KIỂM TRA THIẾU THÔNG TIN LIÊN HỆ ==========
// Đoạn này PHẢI ĐẶT SAU KHI ĐÃ LẤY $khach_hang TỪ DATABASE
$missingInfo = [];
if (empty($khach_hang['soDienThoai'])) $missingInfo[] = 'Số điện thoại';
if (empty($khach_hang['email'])) $missingInfo[] = 'Email';
if (empty($khach_hang['diaChi'])) $missingInfo[] = 'Địa chỉ';

if (!empty($missingInfo)) {
    $reminders[] = [
        'type' => 'warning',
        'icon' => 'fas fa-address-card',
        'title' => 'Thiếu thông tin liên hệ!',
        'message' => 'Hồ sơ khách hàng thiếu: ' . implode(', ', $missingInfo) . '. Cập nhật để liên lạc dễ dàng hơn.',
        'action' => 'openEditModal',
        'actionText' => 'Cập nhật ngay'
    ];
}
/* ══════════════════════════════════════════════════════════
   LOGIC KHÁCH HÀNG THÂN THIẾT / VIP
═══════════════════════════════════════════════════════════ */

// Tạo bảng voucher nếu chưa tồn tại
$conn->query("CREATE TABLE IF NOT EXISTS `voucher_khach_hang` (
    `maVoucher` int(11) NOT NULL AUTO_INCREMENT,
    `maKH` int(11) NOT NULL,
    `loaiVoucher` varchar(100) NOT NULL COMMENT 'sua_chua_50pct hoac mua_hang_1trieu',
    `giaTriGiam` varchar(100) NOT NULL,
    `moTa` varchar(255) DEFAULT NULL,
    `ngayTao` datetime DEFAULT CURRENT_TIMESTAMP,
    `ngayHetHan` date DEFAULT NULL,
    `trangThai` varchar(30) DEFAULT 'Chưa dùng' COMMENT 'Chưa dùng / Đã dùng',
    PRIMARY KEY (`maVoucher`),
    FOREIGN KEY (`maKH`) REFERENCES `khach_hang`(`maKH`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

addColSafe($conn, 'khach_hang', 'loaiKhachHang', "VARCHAR(50) DEFAULT 'Khách truy cập'");
// Các cột hỗ trợ voucher tự soạn (giá trị linh hoạt: % hoặc VNĐ)
addColSafe($conn, 'voucher_khach_hang', 'loai_giam', "VARCHAR(10) DEFAULT 'pct' COMMENT 'pct = phan tram, vnd = tien mat'");
addColSafe($conn, 'voucher_khach_hang', 'gia_tri_so', "DOUBLE DEFAULT 0 COMMENT 'Gia tri so: 10 = 10%, 500000 = 500000d'");
addColSafe($conn, 'voucher_khach_hang', 'so_tien_toi_da', "DOUBLE DEFAULT 0 COMMENT 'So tien toi da duoc giam (0 = khong gioi han)'");

// Migration: cập nhật loaiVoucher cho voucher sinh nhật cũ (sua_chua_50pct + moTa có chữ 'sinh nhật') → sinh_nhat_sc
$conn->query("UPDATE voucher_khach_hang SET loaiVoucher='sinh_nhat_sc' WHERE loaiVoucher='sua_chua_50pct' AND moTa LIKE '%sinh nhật%'");
// Migration: cập nhật ngayHetHan cho voucher sinh nhật chưa dùng mà chưa có hoặc đã hết hạn
// Chỉ cập nhật nếu trangThai='Chưa dùng' và ngayHetHan đã qua hoặc NULL
$conn->query("UPDATE voucher_khach_hang SET ngayHetHan=DATE_ADD(ngayTao, INTERVAL 1 MONTH) WHERE loaiVoucher IN ('sinh_nhat_10pct','sinh_nhat_sc') AND trangThai='Chưa dùng' AND (ngayHetHan IS NULL OR ngayHetHan < CURDATE())");
addColSafe($conn, 'khach_hang', 'ngayLenHangThanThiet', 'DATETIME DEFAULT NULL');
addColSafe($conn, 'khach_hang', 'ngayLenHangVIP', 'DATETIME DEFAULT NULL');

// Tự động kiểm tra & nâng hạng khi mở trang (catch data cũ chưa được nâng)
$rankOnLoad = kiemTraVaNangHang($conn, $maKH, $admin_user);
if ($rankOnLoad) {
    // Reload lại trang để lấy dữ liệu mới nhất từ DB
    $khach_hang = $conn->query("SELECT * FROM khach_hang WHERE maKH=$maKH")->fetch_assoc();
}

// Lấy danh sách voucher của khách hàng
$vouchers_res   = $conn->query("SELECT * FROM voucher_khach_hang WHERE maKH=$maKH ORDER BY ngayTao DESC");
$vouchers_chua_dung = $conn->query("SELECT * FROM voucher_khach_hang WHERE maKH=$maKH AND trangThai='Chưa dùng' ORDER BY ngayTao DESC");
$so_voucher_con = $vouchers_chua_dung ? $vouchers_chua_dung->num_rows : 0;

// Xử lý POST: đánh dấu voucher đã dùng
if (isset($_POST['danh_dau_voucher_da_dung'])) {
    $maVoucher = (int)$_POST['maVoucher'];
    $conn->query("UPDATE voucher_khach_hang SET trangThai='Đã dùng' WHERE maVoucher=$maVoucher AND maKH=$maKH");
    header("Location: chi_tiet_khach_hang.php?id=$maKH&tab=voucher"); exit();
}

// Xử lý POST: sửa thông tin voucher
if (isset($_POST['sua_voucher'])) {
    $maVoucher   = (int)$_POST['maVoucher'];
    $moTa        = $conn->real_escape_string($_POST['moTa'] ?? '');
    $giaTriGiam  = $conn->real_escape_string($_POST['giaTriGiam'] ?? '');
    $ngayHetHan  = !empty($_POST['ngayHetHan']) ? "'" . $conn->real_escape_string($_POST['ngayHetHan']) . "'" : 'NULL';
    $trangThai   = $conn->real_escape_string($_POST['trangThai'] ?? 'Chưa dùng');
    $conn->query("UPDATE voucher_khach_hang SET moTa='$moTa', giaTriGiam='$giaTriGiam', ngayHetHan=$ngayHetHan, trangThai='$trangThai' WHERE maVoucher=$maVoucher AND maKH=$maKH");
    header("Location: chi_tiet_khach_hang.php?id=$maKH&tab=voucher"); exit();
}

// Xử lý GET: xóa voucher
if (isset($_GET['xoa_voucher'])) {
    $maVoucher = (int)$_GET['xoa_voucher'];
    $conn->query("DELETE FROM voucher_khach_hang WHERE maVoucher=$maVoucher AND maKH=$maKH");
    header("Location: chi_tiet_khach_hang.php?id=$maKH&tab=voucher"); exit();
}

// Xử lý POST: cấp voucher thủ công cho admin
if (isset($_POST['cap_voucher_thu_cong'])) {
    $loai_vc  = $conn->real_escape_string($_POST['loai_voucher_cap']);
    $so_luong = max(1, min(10, (int)($_POST['so_luong_cap'] ?? 1)));
    $ngayHetHan = date('Y-m-d', strtotime('+1 year'));
    $loaiKH_hien = $khach_hang['loaiKhachHang'];
    for ($ci = 0; $ci < $so_luong; $ci++) {
        if ($loai_vc === 'sua_chua_50pct') {
            $moTa = "Voucher giảm 50% chi phí sửa chữa – Cấp thủ công bởi $admin_user ($loaiKH_hien)";
            $giaTriGiam = 'Giảm 50%';
        } else {
            $moTa = "Voucher giảm 1.000.000đ mua laptop – Cấp thủ công bởi $admin_user ($loaiKH_hien)";
            $giaTriGiam = 'Giảm 1.000.000đ';
        }
        $conn->query("INSERT INTO voucher_khach_hang (maKH, loaiVoucher, giaTriGiam, moTa, ngayHetHan)
                      VALUES ($maKH, '$loai_vc', '$giaTriGiam', '$moTa', '$ngayHetHan')");
    }
    header("Location: chi_tiet_khach_hang.php?id=$maKH&tab=voucher"); exit();
}

// Cấp voucher sinh nhật + ghi nhật ký cùng lúc
if (isset($_POST['bd_mode'])) {
    $bdMode = $_POST['bd_mode'];

    if ($bdMode === 'tu_soan') {
        // ── Tự soạn voucher ──
        $loaiVcTuSoan   = $conn->real_escape_string(trim($_POST['loaiVcTuSoan'] ?? 'tu_soan'));
        $tenVcTuSoan    = $conn->real_escape_string(trim($_POST['tenVcTuSoan'] ?? 'Voucher tùy chỉnh'));
        $giaTriVcRaw    = trim($_POST['giaTriVcTuSoan'] ?? '');
        $loaiGiaTri     = $conn->real_escape_string($_POST['loaiGiaTriVc'] ?? 'pct');
        $soTienToiDa    = (double)($_POST['soTienToiDa'] ?? 0);
        $ngayHetHanVC   = !empty($_POST['ngayHetHanVc']) ? $conn->real_escape_string($_POST['ngayHetHanVc']) : date('Y-m-d', strtotime('+30 days'));
        $kenhGui        = $conn->real_escape_string($_POST['kenhGui'] ?? 'Không rõ');
        // Ưu tiên lấy từ textarea submit thẳng (bdMessageCustomDirect), fallback về hidden input
        $noiDungTinRaw  = $_POST['bdMessageCustomDirect'] ?? $_POST['bdMessageCustom'] ?? '';
        // Chuẩn hóa: convert literal \r\n \n (từ JS) và real \r\n (từ browser) thành \n thật
        $noiDungTinRaw  = str_replace(['\\r\\n', '\\r', '\\n'], "\n", $noiDungTinRaw);
        $noiDungTinRaw  = str_replace("\r\n", "\n", $noiDungTinRaw);
        $noiDungTinRaw  = str_replace("\r", "\n", $noiDungTinRaw);
        $noiDungTinRaw  = trim($noiDungTinRaw);

        if ($loaiGiaTri === 'pct') {
            $pctVal = max(1, min(100, (int)$giaTriVcRaw));
            $giaTriGiam = "Giảm {$pctVal}%";
            $giaTriVcEsc = $conn->real_escape_string((string)$pctVal);
        } else {
            $vndVal = max(0, (double)$giaTriVcRaw);
            $giaTriGiam = 'Giảm ' . number_format($vndVal, 0, ',', '.') . 'đ';
            $giaTriVcEsc = $conn->real_escape_string((string)$vndVal);
        }
        $soTienToiDaEsc = $conn->real_escape_string((string)$soTienToiDa);
        $moTaVC = "🎂 {$tenVcTuSoan} – Cấp bởi {$admin_user} ngày " . date('d/m/Y') . ($soTienToiDa > 0 ? " (tối đa " . number_format($soTienToiDa,0,',','.') . "đ)" : "");
        $moTaVCesc = $conn->real_escape_string($moTaVC);

        $conn->query("INSERT INTO voucher_khach_hang (maKH, loaiVoucher, giaTriGiam, moTa, ngayHetHan, loai_giam, gia_tri_so, so_tien_toi_da)
                      VALUES ($maKH, '$loaiVcTuSoan', '$giaTriGiam', '$moTaVCesc', '$ngayHetHanVC', '$loaiGiaTri', '$giaTriVcEsc', '$soTienToiDaEsc')");
        $maVoucherMoi = $conn->insert_id;

        $tenKH_log   = $khach_hang['tenKH'] ?? 'Khách hàng';
        $ngaySinhLog = !empty($khach_hang['ngaySinh']) ? date('d/m/Y', strtotime($khach_hang['ngaySinh'])) : 'N/A';
        $vcCode = 'VC-' . str_pad($maVoucherMoi, 5, '0', STR_PAD_LEFT);

        $logLines = [];
        $logLines[] = "📋 Hình thức: Lời chúc sinh nhật kèm Voucher tùy chỉnh";
        $logLines[] = "📱 Kênh gửi: $kenhGui";
        $logLines[] = "🎂 Khách hàng: $tenKH_log (Ngày sinh: $ngaySinhLog)";
        $logLines[] = "👤 Nhân viên thực hiện: $admin_user";
        $logLines[] = "🕐 Thời gian: " . date('H:i - d/m/Y');
        $logLines[] = "🎁 Voucher đã cấp: $vcCode – $giaTriGiam (hạn đến " . date('d/m/Y', strtotime($ngayHetHanVC)) . ")";
        if (!empty($noiDungTinRaw)) {
            $logLines[] = "💬 Nội dung tin:";
            $logLines[] = mb_substr($noiDungTinRaw, 0, 500);
        }

        // Ghép bằng xuống dòng thật, KHÔNG escape trước khi implode
        $logFullRaw = implode("\n", $logLines);
        $logFull = $conn->real_escape_string($logFullRaw);
        $thoiGianNow = date('Y-m-d H:i:s');
        $conn->query("INSERT INTO nhat_ky_giao_tiep (maKH, nguoiPhuTrach, hinhThuc, noiDung, thoiGian)
                      VALUES ($maKH, '$admin_user', '🎂 Chúc sinh nhật', '$logFull', '$thoiGianNow')");
        header("Location: chi_tiet_khach_hang.php?id=$maKH&tab=voucher&bd_done=1"); exit();

    } else {
        // ── Chúc mừng tiêu chuẩn (chuc / uudai_10pct / voucher_sua_chua) ──
        $loaiUuDai  = $conn->real_escape_string($_POST['loaiUuDai'] ?? '');
        $noiDungLog = $conn->real_escape_string($_POST['noiDungLog'] ?? '');
        $kenhGui    = $conn->real_escape_string($_POST['kenhGui'] ?? 'Không rõ');
        $ngaySinhKH = $khach_hang['ngaySinh'] ?? '';
        $ngayHetHanVC = date('Y-m-d', strtotime('+1 month'));

        $maVoucherMoi = null;
        if ($loaiUuDai === 'uudai_10pct') {
            $giaTriGiam = 'Giảm 10%';
            $moTaVC     = "🎂 Voucher sinh nhật – Giảm 10% dịch vụ & mua hàng (cấp bởi $admin_user ngày " . date('d/m/Y') . ")";
            $moTaVCesc  = $conn->real_escape_string($moTaVC);
            $conn->query("INSERT INTO voucher_khach_hang (maKH, loaiVoucher, giaTriGiam, moTa, ngayHetHan)
                          VALUES ($maKH, 'sinh_nhat_10pct', '$giaTriGiam', '$moTaVCesc', '$ngayHetHanVC')");
            $maVoucherMoi = $conn->insert_id;
        } elseif ($loaiUuDai === 'voucher_sua_chua') {
            $giaTriGiam = 'Giảm 50%';
            $moTaVC     = "🎂 Voucher sinh nhật – Giảm 50% sửa chữa (tối đa 500.000đ) (cấp bởi $admin_user ngày " . date('d/m/Y') . ")";
            $moTaVCesc  = $conn->real_escape_string($moTaVC);
            $conn->query("INSERT INTO voucher_khach_hang (maKH, loaiVoucher, giaTriGiam, moTa, ngayHetHan)
                          VALUES ($maKH, 'sinh_nhat_sc', '$giaTriGiam', '$moTaVCesc', '$ngayHetHanVC')");
            $maVoucherMoi = $conn->insert_id;
        }

        $tenKH_log   = $khach_hang['tenKH'] ?? 'Khách hàng';
        $ngaySinhLog = !empty($khach_hang['ngaySinh']) ? date('d/m/Y', strtotime($khach_hang['ngaySinh'])) : 'N/A';
        $loaiTxtLog  = [
            'uudai_10pct'      => 'Lời chúc sinh nhật kèm Ưu đãi giảm 10%',
            'voucher_sua_chua' => 'Lời chúc sinh nhật kèm Voucher sửa chữa 50%',
            ''                 => 'Lời chúc sinh nhật'
        ][$loaiUuDai] ?? 'Lời chúc sinh nhật';

        $logLines = [];
        $logLines[] = "📋 Hình thức: $loaiTxtLog";
        $logLines[] = "📱 Kênh gửi: $kenhGui";
        $logLines[] = "🎂 Khách hàng: $tenKH_log (Ngày sinh: $ngaySinhLog)";
        $logLines[] = "👤 Nhân viên thực hiện: $admin_user";
        $logLines[] = "🕐 Thời gian: " . date('H:i - d/m/Y');

        if ($maVoucherMoi) {
            $vcCode = 'VC-' . str_pad($maVoucherMoi, 5, '0', STR_PAD_LEFT);
            $logLines[] = "🎁 Voucher đã cấp: $vcCode (hạn sử dụng đến " . date('d/m/Y', strtotime($ngayHetHanVC)) . ")";
        }

        $logFull = implode("\n", $logLines);
        $logFull = $conn->real_escape_string($logFull);
        $thoiGianNow = date('Y-m-d H:i:s');
        $conn->query("INSERT INTO nhat_ky_giao_tiep (maKH, nguoiPhuTrach, hinhThuc, noiDung, thoiGian)
                      VALUES ($maKH, '$admin_user', '🎂 Chúc sinh nhật', '$logFull', '$thoiGianNow')");
        $redirectTab = $maVoucherMoi ? 'voucher' : 'nhat-ky';
        header("Location: chi_tiet_khach_hang.php?id=$maKH&tab=$redirectTab&bd_done=1"); exit();
    }
}

// Số đơn & sửa cần thêm để lên hạng tiếp (tính lại sau khi nâng hạng)
$hangHienTai_display = $khach_hang['loaiKhachHang'] ?? 'Khách truy cập';
$don_can_thanh_thiet = max(0, 5  - $tong_don);
$sc_can_thanh_thiet  = max(0, 5  - $tong_sc);
$don_can_vip         = max(0, 10 - $tong_don);
$sc_can_vip          = max(0, 10 - $tong_sc);

// Tính % tiến độ
$pct_don_tt  = min(100, round($tong_don / 5  * 100));
$pct_sc_tt   = min(100, round($tong_sc  / 5  * 100));
$pct_don_vip = min(100, round($tong_don / 10 * 100));
$pct_sc_vip  = min(100, round($tong_sc  / 10 * 100));

// Voucher còn dùng được theo loại (để gợi ý trong modal)
$v_mh_list = []; // voucher mua hàng
$v_sc_list = []; // voucher sửa chữa
$v_dh_all  = []; // TẤT CẢ voucher có thể áp cho đơn hàng (dùng trong Shopee-style picker)
$vtmp = $conn->query("SELECT * FROM voucher_khach_hang WHERE maKH=$maKH AND trangThai='Chưa dùng' AND (ngayHetHan IS NULL OR ngayHetHan >= CURDATE()) ORDER BY ngayTao ASC");
while ($vr = $vtmp->fetch_assoc()) {
    if ($vr['loaiVoucher'] === 'mua_hang_1trieu') { $v_mh_list[] = $vr; $v_dh_all[] = $vr; }
    if ($vr['loaiVoucher'] === 'sua_chua_50pct')  $v_sc_list[] = $vr;
    // Voucher tự soạn: thêm vào danh sách tương ứng
    if ($vr['loaiVoucher'] === 'tu_soan_sc') $v_sc_list[] = $vr;
    if ($vr['loaiVoucher'] === 'tu_soan_mh') { $v_mh_list[] = $vr; $v_dh_all[] = $vr; }
    if ($vr['loaiVoucher'] === 'tu_soan')    { $v_sc_list[] = $vr; $v_mh_list[] = $vr; $v_dh_all[] = $vr; }
    // Voucher sinh nhật áp dụng cho đơn hàng
    if ($vr['loaiVoucher'] === 'sinh_nhat_10pct') { $v_dh_all[] = $vr; }
}
// Chuẩn bị JSON metadata voucher cho JS tính toán tự động
$v_dh_json = [];
foreach ($v_dh_all as $vj) {
    $loaiGiam = $vj['loai_giam'] ?? 'vnd';
    $giaTriSo = (double)($vj['gia_tri_so'] ?? 0);
    $toiDa    = (double)($vj['so_tien_toi_da'] ?? 0);
    // Tính giá trị hiển thị & loại giảm
    if ($vj['loaiVoucher'] === 'mua_hang_1trieu') { $loaiGiam = 'vnd'; $giaTriSo = 1000000; }
    if ($vj['loaiVoucher'] === 'sinh_nhat_10pct') { $loaiGiam = 'pct'; $giaTriSo = 10; }
    $v_dh_json[] = [
        'maVoucher'  => $vj['maVoucher'],
        'loai'       => $vj['loaiVoucher'],
        'loaiGiam'   => $loaiGiam,
        'giaTriSo'   => $giaTriSo,
        'toiDa'      => $toiDa,
        'giaTriGiam' => $vj['giaTriGiam'],
        'moTa'       => $vj['moTa'],
        'han'        => $vj['ngayHetHan'] ?? null,
    ];
}

$activeTab = $_GET['tab'] ?? 'nhat-ky';

// Helper badge màu
function badgeTS($ts) {
    $map = ['Đã hoàn thành'=>'bg-success','Đang giao'=>'bg-info','Đã hủy'=>'bg-danger','Đã sửa xong'=>'bg-success','Đã bàn giao'=>'bg-success','Còn bảo hành'=>'bg-success','Hết bảo hành'=>'bg-danger','Tiếp nhận'=>'bg-secondary','Đang xử lý'=>'bg-warning','Đang kiểm tra'=>'bg-info','Chờ linh kiện'=>'bg-warning'];
    $cls = $map[$ts] ?? 'bg-secondary';
    return "<span class='badge $cls rounded-pill'>$ts</span>";
}
function badgeTT($tt) {
    return $tt == 'Đã thanh toán' ? "<span class='badge bg-success rounded-pill'>$tt</span>" : "<span class='badge bg-warning text-dark rounded-pill'>$tt</span>";
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Hồ Sơ: <?= htmlspecialchars($khach_hang['tenKH']) ?> | QA Tech</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.7.12/sweetalert2.min.css">
<link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
<style>
/* ── Reset & Base ── */
*, *::before, *::after { box-sizing: border-box; }
:root {
    --c-bg:       #f1f5f9;
    --c-surface:  #ffffff;
    --c-border:   #e2e8f0;
    --c-primary:  #10b981;
    --c-pdk:      #059669;
    --c-plight:   #d1fae5;
    --c-text:     #0f172a;
    --c-muted:    #64748b;
    --c-sidebar:  #0f172a;
    --c-sdark:    #1e293b;
    --c-danger:   #ef4444;
    --c-warn:     #f59e0b;
    --c-info:     #3b82f6;
    --c-purple:   #8b5cf6;
    --sidebar-w:  64px;
    --sidebar-xl: 230px;
    --radius:     12px;
    --shadow:     0 1px 3px rgba(0,0,0,.08), 0 4px 16px rgba(0,0,0,.04);
}
html, body { height: 100%; }
body {
    font-family: 'Be Vietnam Pro', sans-serif;
    background: var(--c-bg);
    color: var(--c-text);
    font-size: 14px;
    margin: 0;
}

/* ══ SIDEBAR ══ */
.sidebar {
    position: fixed; top: 0; left: 0; bottom: 0;
    width: var(--sidebar-w);
    background: var(--c-sidebar);
    display: flex; flex-direction: column;
    z-index: 200;
    transition: width .25s cubic-bezier(.4,0,.2,1);
    overflow: hidden;
}
.sidebar:hover { width: var(--sidebar-xl); }
.sidebar-logo {
    height: 64px; min-height: 64px;
    display: flex; align-items: center;
    padding: 0 16px; gap: 12px;
    border-bottom: 1px solid var(--c-sdark);
    overflow: hidden;
}
.logo-circle {
    width: 32px; height: 32px; flex-shrink: 0;
    background: var(--c-primary);
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 15px; color: #fff;
}
.logo-name { color: #fff; font-weight: 700; font-size: 14px; white-space: nowrap; opacity: 0; transition: opacity .2s .05s; }
.sidebar:hover .logo-name { opacity: 1; }
.sidebar-nav { flex: 1; padding: 12px 8px; display: flex; flex-direction: column; gap: 2px; overflow-y: auto; overflow-x: hidden; }
.nav-lnk {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 12px; border-radius: 9px;
    color: #94a3b8; text-decoration: none;
    font-size: 13px; font-weight: 500;
    white-space: nowrap; overflow: hidden;
    transition: background .15s, color .15s;
}
.nav-lnk i { font-size: 15px; flex-shrink: 0; width: 20px; text-align: center; }
.nav-lnk span { opacity: 0; transition: opacity .15s .05s; }
.sidebar:hover .nav-lnk span { opacity: 1; }
.nav-lnk:hover { background: var(--c-sdark); color: #e2e8f0; }
.nav-lnk.active { background: var(--c-primary); color: #fff; }
.sidebar-footer { padding: 12px 8px; border-top: 1px solid var(--c-sdark); }
.nav-lnk.danger:hover { background: #450a0a; color: var(--c-danger); }

/* ══ MAIN ══ */
.main-wrap {
    margin-left: var(--sidebar-w);
    min-height: 100vh;
    transition: margin-left .25s;
    display: flex; flex-direction: column;
}

/* ── TOP BAR ── */
.topbar {
    position: sticky; top: 0; z-index: 100;
    background: rgba(255,255,255,.92);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border-bottom: 1px solid var(--c-border);
    height: 56px;
    display: flex; align-items: center;
    padding: 0 24px; gap: 16px;
}
.topbar-breadcrumb { display: flex; align-items: center; gap: 8px; flex: 1; font-size: 13px; color: var(--c-muted); }
.topbar-breadcrumb a { color: var(--c-muted); text-decoration: none; }
.topbar-breadcrumb a:hover { color: var(--c-primary); }
.topbar-breadcrumb .sep { color: #cbd5e1; }
.topbar-breadcrumb .current { color: var(--c-text); font-weight: 600; }
.topbar-actions { display: flex; align-items: center; gap: 8px; }

/* ── HERO ── */
.hero {
    background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 55%, #064e3b 100%);
    padding: 28px 28px 0;
    position: relative;
    overflow: hidden;
}
.hero::before {
    content: '';
    position: absolute; inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%2310b981' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}
.hero-inner { position: relative; display: flex; align-items: flex-end; gap: 24px; padding-bottom: 0; }
.hero-avatar {
    width: 80px; height: 80px; flex-shrink: 0;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--c-primary), var(--c-pdk));
    display: flex; align-items: center; justify-content: center;
    font-size: 32px; font-weight: 800; color: #fff;
    border: 3px solid rgba(255,255,255,.25);
    box-shadow: 0 8px 24px rgba(0,0,0,.25);
    margin-bottom: -20px;
}
.hero-info { flex: 1; padding-bottom: 20px; }
.hero-name { font-size: 22px; font-weight: 800; color: #fff; margin: 0 0 6px; }
.hero-meta { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
.hero-pill {
    display: inline-flex; align-items: center; gap: 5px;
    background: rgba(255,255,255,.1);
    border: 1px solid rgba(255,255,255,.15);
    color: rgba(255,255,255,.85);
    border-radius: 20px; padding: 3px 10px; font-size: 12px;
}
.hero-pill.active-pill { background: rgba(16,185,129,.2); border-color: rgba(16,185,129,.4); color: #6ee7b7; }
.hero-pill.inactive-pill { background: rgba(239,68,68,.15); border-color: rgba(239,68,68,.3); color: #fca5a5; }
.hero-actions { display: flex; gap: 8px; padding-bottom: 24px; align-self: flex-end; }

/* ── STATS BAR ── */
.stats-bar {
    background: var(--c-surface);
    border-bottom: 1px solid var(--c-border);
    display: flex; align-items: stretch;
}
.stat-item {
    flex: 1; display: flex; align-items: center; gap: 12px;
    padding: 14px 20px; border-right: 1px solid var(--c-border);
    cursor: default;
}
.stat-item:last-child { border-right: none; }
.stat-icon-w {
    width: 40px; height: 40px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; flex-shrink: 0;
}
.stat-num { font-size: 20px; font-weight: 700; line-height: 1; }
.stat-lbl { font-size: 11.5px; color: var(--c-muted); margin-top: 2px; }

/* ── LAYOUT ── */
.content-area {
    flex: 1;
    display: grid;
    grid-template-columns: 300px 1fr;
    gap: 20px;
    padding: 24px 24px 40px;
    align-items: start;
}
@media (max-width: 1100px) { .content-area { grid-template-columns: 1fr; } }

/* ── LEFT PANEL ── */
.left-panel { display: flex; flex-direction: column; gap: 16px; }
.panel-card {
    background: var(--c-surface);
    border-radius: var(--radius);
    border: 1px solid var(--c-border);
    box-shadow: var(--shadow);
    overflow: hidden;
}
.panel-card-header {
    padding: 14px 18px;
    border-bottom: 1px solid var(--c-border);
    display: flex; align-items: center; justify-content: space-between;
    font-weight: 700; font-size: 13.5px;
}
.panel-card-body { padding: 18px; }

/* Info Rows */
.info-item {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 10px 0; border-bottom: 1px solid #f8fafc;
}
.info-item:last-child { border-bottom: none; padding-bottom: 0; }
.info-ico {
    width: 30px; height: 30px; border-radius: 8px;
    background: #f1f5f9; display: flex; align-items: center; justify-content: center;
    color: var(--c-muted); font-size: 12px; flex-shrink: 0;
}
.info-k { font-size: 11px; color: var(--c-muted); margin-bottom: 1px; }
.info-v { font-size: 13.5px; font-weight: 500; line-height: 1.35; word-break: break-word; }

/* Quick Form */
.quick-form { display: flex; flex-direction: column; gap: 10px; }
.quick-form textarea { resize: none; min-height: 80px; }

/* ── TABS SECTION (RIGHT) ── */
.tabs-section {
    background: var(--c-surface);
    border-radius: var(--radius);
    border: 1px solid var(--c-border);
    box-shadow: var(--shadow);
    overflow: hidden;
}
.tabs-nav {
    display: flex;
    border-bottom: 2px solid var(--c-border);
    padding: 0 4px;
    background: #fafbfc;
    overflow-x: auto;
    scrollbar-width: none;
}
.tabs-nav::-webkit-scrollbar { display: none; }
.tab-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 14px 18px;
    border: none; background: none;
    font-family: inherit; font-size: 13.5px; font-weight: 600;
    color: var(--c-muted); cursor: pointer;
    border-bottom: 3px solid transparent; margin-bottom: -2px;
    white-space: nowrap;
    transition: color .15s, border-color .15s;
}
.tab-btn:hover { color: var(--c-text); }
.tab-btn.active { color: var(--c-primary); border-bottom-color: var(--c-primary); }
.tab-btn .cnt {
    font-size: 11px; font-weight: 700;
    padding: 2px 7px; border-radius: 10px;
    background: #f1f5f9; color: var(--c-muted);
    transition: background .15s, color .15s;
}
.tab-btn.active .cnt { background: var(--c-primary); color: #fff; }
.tab-pane { display: none; padding: 24px; }
.tab-pane.active { display: block; }

/* ── TIMELINE ── */
.timeline { position: relative; padding-left: 28px; }
.timeline::before {
    content: ''; position: absolute; left: 9px; top: 0; bottom: 0; width: 2px;
    background: linear-gradient(to bottom, var(--c-primary), var(--c-plight));
    border-radius: 2px;
}
.tl-item { position: relative; margin-bottom: 20px; }
.tl-dot {
    position: absolute; left: -28px; top: 8px;
    width: 12px; height: 12px; border-radius: 50%;
    background: var(--c-primary);
    border: 2px solid var(--c-surface);
    box-shadow: 0 0 0 2px var(--c-primary);
}
.tl-card {
    background: #fafbfc;
    border: 1px solid var(--c-border);
    border-radius: 10px;
    padding: 12px 14px;
    transition: box-shadow .15s;
}
.tl-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.07); }
.tl-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
.tl-meta { font-size: 12px; color: var(--c-muted); }
.tl-type { font-weight: 700; color: var(--c-pdk); font-size: 13.5px; margin: 3px 0; }
.tl-body {
    font-size: 13px; color: #334155;
    display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical;
    overflow: hidden; margin-top: 6px;
    background: #fff; border: 1px solid var(--c-border);
    border-radius: 7px; padding: 8px 10px;
    white-space: pre-wrap; word-break: break-word;
}

/* ── TABLES ── */
.tbl { width: 100%; border-collapse: collapse; font-size: 13.5px; }
.tbl thead th {
    background: #f8fafc; font-weight: 600; font-size: 12px;
    color: var(--c-muted); text-transform: uppercase; letter-spacing: .4px;
    padding: 10px 14px; border-bottom: 2px solid var(--c-border);
    white-space: nowrap;
}
.tbl tbody td { padding: 12px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
.tbl tbody tr:last-child td { border-bottom: none; }
.tbl tbody tr:hover td { background: #fafbff; }

/* ── SECTION HEADER ── */
.sec-hdr { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; gap: 12px; }
.sec-title { font-size: 15px; font-weight: 700; display: flex; align-items: center; gap: 8px; }

/* ── EMPTY STATE ── */
.empty-box { text-align: center; padding: 48px 24px; }
.empty-box i { font-size: 40px; color: #cbd5e1; display: block; margin-bottom: 12px; }
.empty-box p { margin: 0; color: var(--c-muted); font-size: 14px; }

/* ── BUTTONS ── */
.btn-primary-qa {
    display: inline-flex; align-items: center; gap: 6px;
    background: var(--c-primary); color: #fff; border: none;
    padding: 8px 16px; border-radius: 9px;
    font-family: inherit; font-size: 13px; font-weight: 600;
    cursor: pointer; text-decoration: none;
    transition: background .15s, transform .1s;
}
.btn-primary-qa:hover { background: var(--c-pdk); color: #fff; }
.btn-outline-qa {
    display: inline-flex; align-items: center; gap: 6px;
    background: #fff; color: var(--c-text);
    border: 1px solid var(--c-border);
    padding: 7px 14px; border-radius: 9px;
    font-family: inherit; font-size: 13px; font-weight: 500;
    cursor: pointer; text-decoration: none;
    transition: border-color .15s, color .15s, background .15s;
}
.btn-outline-qa:hover { border-color: var(--c-primary); color: var(--c-primary); background: var(--c-plight); }
.btn-ico {
    display: inline-flex; align-items: center; justify-content: center;
    width: 30px; height: 30px; border-radius: 7px;
    border: 1px solid var(--c-border); background: #fff;
    color: var(--c-muted); font-size: 12px;
    cursor: pointer; transition: all .15s;
}
.btn-ico:hover { border-color: var(--c-primary); color: var(--c-primary); background: var(--c-plight); }
.btn-ico.del:hover { border-color: var(--c-danger); color: var(--c-danger); background: #fee2e2; }

/* ── MODALS ── */
.modal-content { border-radius: 14px; border: none; box-shadow: 0 20px 60px rgba(0,0,0,.18); font-family: 'Be Vietnam Pro', sans-serif; }
.modal-header { border-bottom: 1px solid #f1f5f9; padding: 18px 22px; }
.modal-title { font-weight: 700; font-size: 15px; }
.modal-body { padding: 22px; }
.modal-footer { border-top: 1px solid #f1f5f9; padding: 14px 22px; }
.form-label { font-weight: 600; font-size: 13px; margin-bottom: 5px; color: var(--c-text); }
.form-control, .form-select {
    border-radius: 8px; border-color: var(--c-border);
    font-size: 13.5px; font-family: 'Be Vietnam Pro', sans-serif;
}
.form-control:focus, .form-select:focus { border-color: var(--c-primary); box-shadow: 0 0 0 3px rgba(16,185,129,.12); }
.form-text { font-size: 12px; color: var(--c-muted); }

/* ── ITEM ROWS (orders) ── */
.product-row { display: flex; gap: 8px; align-items: center; margin-bottom: 8px; }
.product-row .form-control { flex: 1; }

/* ── STATUS CHIPS (inline) ── */
.chip {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 10px; border-radius: 20px;
    font-size: 12px; font-weight: 600;
}
.chip-success { background: #d1fae5; color: #065f46; }
.chip-warning { background: #fef3c7; color: #78350f; }
.chip-danger  { background: #fee2e2; color: #991b1b; }
.chip-info    { background: #dbeafe; color: #1e40af; }
.chip-gray    { background: #f1f5f9; color: #475569; }
.chip-purple  { background: #ede9fe; color: #5b21b6; }

/* Warranty progress */
.warranty-bar { height: 6px; border-radius: 3px; background: #e2e8f0; overflow: hidden; margin-top: 4px; }
.warranty-fill { height: 100%; border-radius: 3px; background: linear-gradient(90deg, var(--c-primary), #34d399); transition: width .5s; }

/* Document grid */
.doc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
.doc-card {
    background: #f8fafc; border: 1px solid var(--c-border); border-radius: 10px;
    padding: 14px; display: flex; flex-direction: column; gap: 8px;
    transition: box-shadow .15s;
}
.doc-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.08); }
.doc-icon { font-size: 24px; }
.doc-name { font-weight: 600; font-size: 13px; }
.doc-type { font-size: 11.5px; color: var(--c-muted); }
.doc-files { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; }
.doc-file-link {
    font-size: 11.5px; background: #fff; border: 1px solid var(--c-border);
    border-radius: 5px; padding: 2px 8px; color: var(--c-info); text-decoration: none;
    display: inline-flex; align-items: center; gap: 4px;
}
.doc-file-link:hover { background: #dbeafe; }

/* Image thumb grid */
.img-thumb-grid { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 8px; }
.img-thumb {
    width: 70px; height: 70px; object-fit: cover;
    border-radius: 8px; border: 2px solid var(--c-border);
    cursor: pointer; transition: border-color .15s, transform .15s;
}
.img-thumb:hover { border-color: var(--c-primary); transform: scale(1.05); }

/* Invoice print area */
.invoice-co { font-weight: 800; font-size: 15px; color: var(--c-pdk); }
@media print {
    .sidebar, .topbar, .hero-actions, .btn-ico, .btn-primary-qa, .btn-outline-qa, .modal-footer { display: none !important; }
    .main-wrap { margin-left: 0 !important; }
    .hero { padding-bottom: 20px; }
    .hero-avatar { margin-bottom: 0; }
    .tabs-nav { display: none; }
    .tab-pane { display: block !important; }
}
/* Scrollbar nice */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }

/* ══ VIP / LOYALTY STYLES ══ */
.rank-badge-vip {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 5px 14px; border-radius: 30px; font-weight: 700; font-size: 13px;
    background: linear-gradient(135deg,#7c3aed,#a855f7);
    color: #fff; box-shadow: 0 2px 10px rgba(124,58,237,.35);
}
.rank-badge-thanh-thiet {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 5px 14px; border-radius: 30px; font-weight: 700; font-size: 13px;
    background: linear-gradient(135deg,#d97706,#f59e0b);
    color: #fff; box-shadow: 0 2px 10px rgba(217,119,6,.35);
}
.rank-badge-thuong {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 5px 14px; border-radius: 30px; font-weight: 700; font-size: 13px;
    background: #e2e8f0; color: #64748b;
}
.loyalty-card {
    background: linear-gradient(135deg,#0f172a,#1e3a5f);
    border-radius: 16px; padding: 20px; color: #fff;
    position: relative; overflow: hidden;
}
.loyalty-card::before {
    content: ''; position: absolute; top: -40px; right: -40px;
    width: 160px; height: 160px; border-radius: 50%;
    background: rgba(255,255,255,.04);
}
.loyalty-card.vip-card { background: linear-gradient(135deg,#2e1065,#4c1d95,#5b21b6); }
.loyalty-card.thanh-thiet-card { background: linear-gradient(135deg,#78350f,#92400e,#b45309); }
.progress-rank { height: 8px; border-radius: 4px; background: rgba(255,255,255,.15); overflow: hidden; margin: 8px 0; }
.progress-rank-bar { height: 100%; border-radius: 4px; background: linear-gradient(90deg,#10b981,#34d399); transition: width .6s ease; }
.voucher-card {
    border: 2px dashed var(--c-border); border-radius: 12px;
    padding: 14px 16px; display: flex; align-items: center; gap: 14px;
    transition: border-color .15s, background .15s;
}
.voucher-card:hover { border-color: var(--c-primary); background: #f0fdf4; }
.voucher-card.used { opacity: .55; background: #f8fafc; }
.voucher-icon {
    width: 46px; height: 46px; border-radius: 12px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: 20px;
}
.voucher-icon.sc   { background: #fef3c7; color: #d97706; }
.voucher-icon.mh   { background: #dbeafe; color: #2563eb; }
.voucher-code {
    font-family: monospace; font-size: 13px; font-weight: 700;
    background: #f1f5f9; border-radius: 6px; padding: 2px 8px;
    color: var(--c-pdk); letter-spacing: 1px;
}

/* ========== REMINDER / NOTIFICATION STYLES ========== */
.reminder-area {
    background: transparent;
    border-radius: 12px;
    margin-bottom: 16px;
}

.reminder-card {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 14px 20px;
    background: white;
    border-radius: 12px;
    border-left: 4px solid;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
    transition: all 0.2s ease;
    cursor: pointer;
}

.reminder-card:hover {
    transform: translateX(4px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}

.reminder-warning {
    border-left-color: #f59e0b;
    background: linear-gradient(135deg, #fff, #fffbeb);
}

.reminder-danger {
    border-left-color: #ef4444;
    background: linear-gradient(135deg, #fff, #fef2f2);
}

.reminder-info {
    border-left-color: #3b82f6;
    background: linear-gradient(135deg, #fff, #eff6ff);
}

.reminder-secondary {
    border-left-color: #64748b;
    background: linear-gradient(135deg, #fff, #f8fafc);
}

.reminder-success {
    border-left-color: #10b981;
    background: linear-gradient(135deg, #fff, #f0fdf4);
}

.reminder-icon {
    width: 44px;
    height: 44px;
    border-radius: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
}

.reminder-warning .reminder-icon {
    background: #fef3c7;
    color: #d97706;
}

.reminder-danger .reminder-icon {
    background: #fee2e2;
    color: #dc2626;
}

.reminder-info .reminder-icon {
    background: #dbeafe;
    color: #2563eb;
}

.reminder-secondary .reminder-icon {
    background: #f1f5f9;
    color: #475569;
}

.reminder-success .reminder-icon {
    background: #d1fae5;
    color: #059669;
}

.reminder-content {
    flex: 1;
}

.reminder-title {
    font-weight: 700;
    font-size: 14px;
    margin-bottom: 4px;
    color: #1e293b;
}

.reminder-message {
    font-size: 12px;
    color: #64748b;
    line-height: 1.5;
}

.reminder-action {
    flex-shrink: 0;
}

.reminder-btn {
    font-size: 12px;
    font-weight: 600;
    color: #10b981;
    white-space: nowrap;
    transition: all 0.2s;
}

.reminder-card:hover .reminder-btn {
    transform: translateX(2px);
    color: #059669;
}

.reminder-warning .reminder-btn { color: #d97706; }
.reminder-danger .reminder-btn { color: #dc2626; }
.reminder-info .reminder-btn { color: #2563eb; }
.reminder-secondary .reminder-btn { color: #475569; }

/* Responsive */
@media (max-width: 768px) {
    .reminder-card {
        flex-wrap: wrap;
        padding: 12px 16px;
    }
    .reminder-action {
        width: 100%;
        margin-left: 56px;
    }
    .reminder-btn {
        font-size: 11px;
    }
}

</style>
</head>
<body>
<?php if (!empty($_SESSION['_qa_err'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof Swal !== 'undefined') {
        Swal.fire({ icon: 'error', title: 'Dữ liệu không hợp lệ', text: <?= json_encode($_SESSION['_qa_err']) ?>, confirmButtonColor: '#ef4444' });
    }
});
</script>
<?php unset($_SESSION['_qa_err']); endif; ?>

<!-- ══ SIDEBAR ══ -->
<nav class="sidebar">
    <div class="sidebar-logo">
        <div class="logo-circle">Q</div>
        <div class="logo-name">QA TECH</div>
    </div>
    <div class="sidebar-nav">
        <a href="trang_chu.php" class="nav-lnk"><i class="fas fa-th-large"></i><span>Tổng quan</span></a>
        <a href="quan_ly_khach_hang.php" class="nav-lnk active"><i class="fas fa-users"></i><span>Khách hàng</span></a>
        <a href="quan_ly_san_pham.php" class="nav-lnk"><i class="fas fa-box-open"></i><span>Sản phẩm</span></a>
        <a href="quan_ly_don_hang.php" class="nav-lnk"><i class="fas fa-shopping-bag"></i><span>Đơn hàng</span></a>
        <a href="don_hang_online.php" class="nav-lnk" id="online-order-link" style="position:relative">
            <i class="fas fa-globe"></i><span>Đơn Online</span>
            <span id="online-order-badge" style="display:none;background:#ef4444;color:#fff;font-size:10px;font-weight:800;padding:1px 6px;border-radius:10px;margin-left:auto;opacity:0;transition:opacity .15s .05s"></span>
        </a>
        <a href="quan_ly_bao_hanh.php" class="nav-lnk"><i class="fas fa-shield-alt"></i><span>Bảo hành</span></a>
        <a href="quan_ly_sua_chua.php" class="nav-lnk"><i class="fas fa-tools"></i><span>Sửa chữa</span></a>
    </div>
    <div class="sidebar-footer">
        <a href="../logout.php" class="nav-lnk danger"><i class="fas fa-sign-out-alt"></i><span>Đăng xuất</span></a>
    </div>
</nav>

<!-- ══ MAIN WRAP ══ -->
<div class="main-wrap">

<!-- TOP BAR -->
<div class="topbar">
    <div class="topbar-breadcrumb">
        <a href="trang_chu.php"><i class="fas fa-home"></i></a>
        <span class="sep">/</span>
        <a href="quan_ly_khach_hang.php">Khách hàng</a>
        <span class="sep">/</span>
        <span class="current"><?= htmlspecialchars($khach_hang['tenKH']) ?></span>
    </div>
    <div class="topbar-actions">
        <button class="btn-outline-qa" onclick="printLichSu()"><i class="fas fa-print"></i> In hồ sơ</button>
        <button class="btn-outline-qa" onclick="exportExcelLichSu()"><i class="fas fa-file-excel text-success"></i> Xuất Excel</button>
        <button class="btn-primary-qa" data-bs-toggle="modal" data-bs-target="#editKHModal"><i class="fas fa-user-edit"></i> Sửa hồ sơ</button>
    </div>
</div>

<!-- ══ HERO ══ -->
<div class="hero">
    <div class="hero-inner">
        <div class="hero-avatar"><?= mb_substr($khach_hang['tenKH'], 0, 1, 'UTF-8') ?></div>
        <div class="hero-info">
            <h1 class="hero-name"><?= htmlspecialchars($khach_hang['tenKH']) ?></h1>
            <div class="hero-meta">
                <?php
                $loaiKH_display = $khach_hang['loaiKhachHang'];
                if ($loaiKH_display === 'Khách hàng VIP') {
                    echo '<span class="hero-pill" style="background:linear-gradient(135deg,rgba(124,58,237,.4),rgba(168,85,247,.3));border-color:rgba(167,139,250,.5);color:#e9d5ff;font-weight:700;"><i class="fas fa-crown" style="color:#f59e0b;"></i> Khách hàng VIP</span>';
                } elseif ($loaiKH_display === 'Khách hàng thân thiết') {
                    echo '<span class="hero-pill" style="background:linear-gradient(135deg,rgba(217,119,6,.35),rgba(245,158,11,.25));border-color:rgba(251,191,36,.4);color:#fde68a;font-weight:700;"><i class="fas fa-medal" style="color:#fbbf24;"></i> Khách hàng thân thiết</span>';
                } else {
                    echo '<span class="hero-pill"><i class="fas fa-tag"></i> ' . htmlspecialchars($loaiKH_display) . '</span>';
                }
                ?>

                <?php if ($khach_hang['soDienThoai']): ?>
                <span class="hero-pill"><i class="fas fa-phone"></i> <?= htmlspecialchars($khach_hang['soDienThoai']) ?></span>
                <?php endif; ?>
                <?php if ($khach_hang['email']): ?>
                <span class="hero-pill"><i class="fas fa-envelope"></i> <?= htmlspecialchars($khach_hang['email']) ?></span>
                <?php endif; ?>
                <span class="hero-pill <?= $khach_hang['trangThai'] ? 'active-pill' : 'inactive-pill' ?>">
                    <i class="fas fa-circle" style="font-size:7px;"></i>
                    <?= $khach_hang['trangThai'] ? 'Đang hoạt động' : 'Tạm khóa' ?>
                </span>
                <span class="hero-pill"><i class="fas fa-calendar"></i> Đăng ký: <?= date('d/m/Y', strtotime($khach_hang['ngayDangKy'])) ?></span>
            </div>
        </div>
        <div class="hero-actions">
            <a href="tel:<?= htmlspecialchars($khach_hang['soDienThoai']) ?>" class="btn-outline-qa" style="background:rgba(255,255,255,.1);border-color:rgba(255,255,255,.2);color:#fff;">
                <i class="fas fa-phone"></i> Gọi ngay
            </a>
        </div>
    </div>
</div>

<!-- ══ KHU VỰC THÔNG BÁO & NHẮC NHỞ ══ -->
<?php if (!empty($reminders) || !empty($alerts)): ?>
<div class="reminder-area" style="margin-bottom: 20px;">
    <?php foreach ($reminders as $reminder): ?>
    <div class="reminder-card reminder-<?= $reminder['type'] ?> mb-2" style="cursor: pointer;" onclick="<?= $reminder['action'] ?>()">
        <div class="reminder-icon"><i class="<?= $reminder['icon'] ?>"></i></div>
        <div class="reminder-content">
            <div class="reminder-title"><?= $reminder['title'] ?></div>
            <div class="reminder-message"><?= $reminder['message'] ?></div>
        </div>
        <div class="reminder-action">
            <span class="reminder-btn"><?= $reminder['actionText'] ?> <i class="fas fa-arrow-right"></i></span>
        </div>
    </div>
    <?php endforeach; ?>
    
    <?php foreach ($alerts as $alert): ?>
    <div class="reminder-card reminder-<?= $alert['type'] ?> mb-2" onclick="<?= $alert['action'] ?>()">
        <div class="reminder-icon"><i class="<?= $alert['icon'] ?>"></i></div>
        <div class="reminder-content">
            <div class="reminder-title"><?= $alert['title'] ?></div>
            <div class="reminder-message"><?= $alert['message'] ?></div>
        </div>
        <div class="reminder-action">
            <span class="reminder-btn"><?= $alert['actionText'] ?> <i class="fas fa-arrow-right"></i></span>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ══ STATS BAR ══ -->
<div class="stats-bar">
    <div class="stat-item" onclick="switchTab('don-hang')" style="cursor:pointer;" title="Xem đơn hàng">
        <div class="stat-icon-w" style="background:#d1fae5;color:#059669;"><i class="fas fa-shopping-bag"></i></div>
        <div><div class="stat-num"><?= $tong_don ?></div><div class="stat-lbl">Đơn hàng</div></div>
    </div>
    <div class="stat-item" onclick="switchTab('ky-thuat')" style="cursor:pointer;" title="Xem sửa chữa">
        <div class="stat-icon-w" style="background:#fef3c7;color:#d97706;"><i class="fas fa-tools"></i></div>
        <div><div class="stat-num"><?= $tong_sc ?></div><div class="stat-lbl">Sửa chữa</div></div>
    </div>
    <div class="stat-item" onclick="switchTab('ky-thuat')" style="cursor:pointer;" title="Xem bảo hành">
        <div class="stat-icon-w" style="background:#dbeafe;color:#2563eb;"><i class="fas fa-shield-alt"></i></div>
        <div><div class="stat-num"><?= $tong_bh ?></div><div class="stat-lbl">Bảo hành</div></div>
    </div>
    <div class="stat-item" onclick="switchTab('nhat-ky')" style="cursor:pointer;" title="Xem nhật ký">
        <div class="stat-icon-w" style="background:#ede9fe;color:#7c3aed;"><i class="fas fa-comments"></i></div>
        <div><div class="stat-num"><?= $tong_nk ?></div><div class="stat-lbl">Giao tiếp</div></div>
    </div>
    <div class="stat-item" style="background:linear-gradient(135deg,#f0fdf4,#dcfce7);">
        <div class="stat-icon-w" style="background:var(--c-primary);color:#fff;"><i class="fas fa-coins"></i></div>
        <div>
            <div class="stat-num" style="color:var(--c-pdk);font-size:16px;"><?= number_format($tongTien, 0, ',', '.') ?> đ</div>
            <div class="stat-lbl">Tổng chi tiêu</div>
        </div>
    </div>
</div>

<!-- ══ CONTENT AREA ══ -->
<div class="content-area">

    <!-- ── LEFT PANEL ── -->
    <div class="left-panel">

        <!-- Thông tin khách hàng -->
        <div class="panel-card">
            <div class="panel-card-header">
                <span><i class="fas fa-id-card text-primary me-2"></i>Thông tin liên hệ</span>
                <button class="btn-ico" data-bs-toggle="modal" data-bs-target="#editKHModal" title="Chỉnh sửa">
                    <i class="fas fa-edit"></i>
                </button>
            </div>
            <div class="panel-card-body">
                <div class="info-item">
                    <div class="info-ico" style="background:#d1fae5;color:#059669;"><i class="fas fa-phone"></i></div>
                    <div><div class="info-k">Điện thoại</div><div class="info-v"><?= htmlspecialchars($khach_hang['soDienThoai'] ?: '—') ?></div></div>
                </div>
                <div class="info-item">
                    <div class="info-ico" style="background:#dbeafe;color:#2563eb;"><i class="fas fa-envelope"></i></div>
                    <div><div class="info-k">Email</div><div class="info-v" style="font-size:13px;"><?= htmlspecialchars($khach_hang['email'] ?: '—') ?></div></div>
                </div>
                <div class="info-item">
                    <div class="info-ico" style="background:#fef3c7;color:#d97706;"><i class="fas fa-map-marker-alt"></i></div>
                    <div><div class="info-k">Địa chỉ</div><div class="info-v" style="font-size:13px;"><?= htmlspecialchars($khach_hang['diaChi'] ?: '—') ?></div></div>
                </div>
                <div class="info-item">
                    <div class="info-ico" style="background:#ede9fe;color:#7c3aed;"><i class="fas fa-star"></i></div>
                    <div>
                        <div class="info-k">Phân loại</div>
                        <div class="info-v">
                        <?php
                        $lkh_disp = $khach_hang['loaiKhachHang'];
                        if ($lkh_disp === 'Khách hàng VIP'):
                        ?><span class="rank-badge-vip" style="font-size:12px;padding:3px 10px;"><i class="fas fa-crown" style="color:#fbbf24;"></i> VIP</span>
                        <?php elseif ($lkh_disp === 'Khách hàng thân thiết'): ?>
                        <span class="rank-badge-thanh-thiet" style="font-size:12px;padding:3px 10px;"><i class="fas fa-medal"></i> Thân thiết</span>
                        <?php else: ?>
                        <?= htmlspecialchars($lkh_disp) ?>
                        <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="info-item">
                    <div class="info-ico"><i class="fas fa-calendar-plus"></i></div>
                    <div><div class="info-k">Ngày đăng ký</div><div class="info-v"><?= date('d/m/Y', strtotime($khach_hang['ngayDangKy'])) ?></div></div>
                </div>
                <?php
                // Hiển thị ngày sinh nếu có
                if (!empty($khach_hang['ngaySinh'])):
                    $dtSinhHT  = new DateTime($khach_hang['ngaySinh']);
                    $dtTodayHT = new DateTime();
                    $tuoiHT    = (int)$dtTodayHT->diff($dtSinhHT)->y;
                    $bdThisYearDisp = date('Y') . '-' . date('m-d', strtotime($khach_hang['ngaySinh']));
                    $daysLeft = (int)floor((strtotime($bdThisYearDisp) - strtotime(date('Y-m-d'))) / 86400);
                    if ($daysLeft < 0) { $bdNextYearDisp = (date('Y')+1) . '-' . date('m-d', strtotime($khach_hang['ngaySinh'])); $daysLeft = (int)floor((strtotime($bdNextYearDisp) - strtotime(date('Y-m-d'))) / 86400); }
                    $bdColor = $daysLeft === 0 ? '#dc2626' : ($daysLeft <= 7 ? '#d97706' : '#7c3aed');
                    $bdBg    = $daysLeft === 0 ? '#fee2e2' : ($daysLeft <= 7 ? '#fef3c7' : '#ede9fe');
                    $bdHint  = $daysLeft === 0 ? '🎂 Hôm nay!' : ($daysLeft <= 7 ? "⏰ $daysLeft ngày nữa" : "$tuoiHT tuổi");
                ?>
                <div class="info-item" style="cursor:pointer;" onclick="openBirthdayModal()" title="Click để gửi lời chúc">
                    <div class="info-ico" style="background:<?= $bdBg ?>;color:<?= $bdColor ?>;"><i class="fas fa-birthday-cake"></i></div>
                    <div style="flex:1;">
                        <div class="info-k">Ngày sinh</div>
                        <div class="info-v" style="display:flex;align-items:center;gap:6px;">
                            <?= date('d/m/Y', strtotime($khach_hang['ngaySinh'])) ?>
                            <span style="font-size:11px;background:<?= $bdBg ?>;color:<?= $bdColor ?>;padding:1px 7px;border-radius:10px;font-weight:600;"><?= $bdHint ?></span>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="info-item">
                    <div class="info-ico" style="background:#f1f5f9;color:#94a3b8;"><i class="fas fa-birthday-cake"></i></div>
                    <div><div class="info-k">Ngày sinh</div><div class="info-v" style="color:#94a3b8;font-size:12px;font-style:italic;">Chưa cập nhật — <a href="javascript:void(0)" onclick="openEditModal()" style="color:var(--c-primary);">Thêm ngay</a></div></div>
                </div>
                <?php endif; ?>
                <div class="info-item">
                    <div class="info-ico" style="background:<?= $khach_hang['trangThai'] ? '#d1fae5' : '#fee2e2' ?>;color:<?= $khach_hang['trangThai'] ? '#059669' : '#dc2626' ?>;"><i class="fas fa-toggle-on"></i></div>
                    <div><div class="info-k">Trạng thái</div><div class="info-v"><?= $khach_hang['trangThai'] ? '<span style="color:#059669;">✔ Đang hoạt động</span>' : '<span style="color:#dc2626;">✘ Tạm khóa</span>' ?></div></div>
                </div>
            </div>
        </div>

        <!-- Ghi nhận nhanh -->
        <div class="panel-card">
            <div class="panel-card-header">
                <span><i class="fas fa-bolt text-warning me-2"></i>Ghi nhận giao tiếp nhanh</span>
            </div>
            <div class="panel-card-body">
                <form method="POST" class="quick-form">
                    <div>
                        <label class="form-label">Hình thức</label>
                        <select name="hinhThuc" class="form-select" required>
                            <option value="Gọi điện tư vấn">📞 Gọi điện tư vấn</option>
                            <option value="Khách đến cửa hàng">🏪 Khách đến cửa hàng</option>
                            <option value="Ký hợp đồng">📋 Ký hợp đồng</option>
                            <option value="Phàn nàn / Khiếu nại">⚠️ Phàn nàn / Khiếu nại</option>
                            <option value="Hỗ trợ kỹ thuật">🔧 Hỗ trợ kỹ thuật</option>
                            <option value="Chăm sóc sau bán">💚 Chăm sóc sau bán</option>
                            <option value="Tư vấn trực tuyến">💬 Tư vấn trực tuyến (Zalo/FB)</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Nội dung</label>
                        <textarea name="noiDung" class="form-control" placeholder="Tóm tắt nội dung trao đổi..." required></textarea>
                    </div>
                    <button type="submit" name="them_nhat_ky" class="btn-primary-qa justify-content-center w-100">
                        <i class="fas fa-save"></i> Lưu nhật ký
                    </button>
                </form>
            </div>
        </div>

        <!-- Thêm nhanh phiếu sửa chữa -->
        <div class="panel-card">
            <div class="panel-card-header">
                <span><i class="fas fa-plus-circle text-success me-2"></i>Thao tác nhanh</span>
            </div>
            <div class="panel-card-body" style="display:flex;flex-direction:column;gap:8px;">
                <button class="btn-outline-qa w-100 justify-content-center" data-bs-toggle="modal" data-bs-target="#addDHModal" style="padding:10px;">
                    <i class="fas fa-shopping-cart text-success"></i> Tạo đơn hàng mới
                </button>
                <button class="btn-outline-qa w-100 justify-content-center" data-bs-toggle="modal" data-bs-target="#addPSModal" style="padding:10px;">
                    <i class="fas fa-tools text-warning"></i> Tạo phiếu sửa chữa
                </button>
                <button class="btn-outline-qa w-100 justify-content-center" data-bs-toggle="modal" data-bs-target="#addBHModal" style="padding:10px;">
                    <i class="fas fa-shield-alt text-info"></i> Thêm bảo hành
                </button>
                <button class="btn-outline-qa w-100 justify-content-center" data-bs-toggle="modal" data-bs-target="#addTLModal" style="padding:10px;">
                    <i class="fas fa-file-upload text-purple"></i> Tải lên tài liệu
                </button>
            </div>
        </div>

        <!-- ── HẠNG KHÁCH HÀNG & TIẾN ĐỘ ── -->
        <div class="panel-card">
            <div class="panel-card-header">
                <span><i class="fas fa-crown text-warning me-2"></i>Hạng khách hàng</span>
            </div>
            <div class="panel-card-body" style="padding:14px;">
                <?php
                $lkh = $khach_hang['loaiKhachHang'];
                if ($lkh === 'Khách hàng VIP'): ?>
                <div class="loyalty-card vip-card mb-3">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                        <span style="font-size:28px;">👑</span>
                        <div>
                            <div style="font-size:16px;font-weight:800;">Khách hàng VIP</div>
                            <div style="font-size:11px;opacity:.75;">Hạng cao nhất – Đặc quyền tối đa</div>
                        </div>
                    </div>
                    <div style="font-size:12px;opacity:.8;line-height:1.7;">
                        ✔ <?= $tong_don ?> đơn hàng &nbsp;·&nbsp; ✔ <?= $tong_sc ?> lần sửa chữa
                    </div>
                    <?php if ($so_voucher_con > 0): ?>
                    <div style="margin-top:10px;background:rgba(255,255,255,.12);border-radius:8px;padding:8px 12px;font-size:12px;">
                        🎟️ <strong><?= $so_voucher_con ?> voucher</strong> chưa sử dụng
                    </div>
                    <?php endif; ?>
                </div>
                <?php elseif ($lkh === 'Khách hàng thân thiết'): ?>
                <div class="loyalty-card thanh-thiet-card mb-3">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                        <span style="font-size:28px;">🥇</span>
                        <div>
                            <div style="font-size:16px;font-weight:800;">Khách hàng thân thiết</div>
                            <div style="font-size:11px;opacity:.75;">Đang tiến đến VIP</div>
                        </div>
                    </div>
                    <div style="font-size:12px;opacity:.85;">Đơn hàng: <?= $tong_don ?>/10</div>
                    <div class="progress-rank"><div class="progress-rank-bar" style="width:<?= $pct_don_vip ?>%;"></div></div>
                    <div style="font-size:12px;opacity:.85;margin-top:6px;">Sửa chữa: <?= $tong_sc ?>/10</div>
                    <div class="progress-rank"><div class="progress-rank-bar" style="width:<?= $pct_sc_vip ?>%;"></div></div>
                    <?php if ($don_can_vip > 0 || $sc_can_vip > 0): ?>
                    <div style="font-size:11px;opacity:.75;margin-top:8px;">
                        Cần thêm <?= $don_can_vip ?> đơn & <?= $sc_can_vip ?> lần sửa để lên <strong>VIP</strong>
                    </div>
                    <?php else: ?>
                    <div style="font-size:11px;color:#34d399;margin-top:8px;font-weight:700;">✅ Đủ điều kiện lên VIP – Sẽ tự động nâng hạng!</div>
                    <?php endif; ?>
                    <?php if ($so_voucher_con > 0): ?>
                    <div style="margin-top:10px;background:rgba(255,255,255,.12);border-radius:8px;padding:8px 12px;font-size:12px;">
                        🎟️ <strong><?= $so_voucher_con ?> voucher</strong> chưa sử dụng
                    </div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="loyalty-card mb-3" style="background:linear-gradient(135deg,#334155,#475569);">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                        <span style="font-size:28px;">🛒</span>
                        <div>
                            <div style="font-size:15px;font-weight:700;">Khách truy cập</div>
                            <div style="font-size:11px;opacity:.7;">Mua thêm để nhận ưu đãi</div>
                        </div>
                    </div>
                    <div style="font-size:12px;opacity:.85;">Đơn hàng: <?= $tong_don ?>/5 để thân thiết</div>
                    <div class="progress-rank"><div class="progress-rank-bar" style="width:<?= $pct_don_tt ?>%;"></div></div>
                    <div style="font-size:12px;opacity:.85;margin-top:6px;">Sửa chữa: <?= $tong_sc ?>/5 để thân thiết</div>
                    <div class="progress-rank"><div class="progress-rank-bar" style="width:<?= $pct_sc_tt ?>%;"></div></div>
                    <?php if ($don_can_thanh_thiet > 0 || $sc_can_thanh_thiet > 0): ?>
                    <div style="font-size:11px;opacity:.75;margin-top:8px;">
                        Cần thêm <?= $don_can_thanh_thiet ?> đơn & <?= $sc_can_thanh_thiet ?> lần sửa để lên <strong>Thân thiết</strong>
                    </div>
                    <?php else: ?>
                    <div style="font-size:11px;color:#34d399;margin-top:8px;font-weight:700;">✅ Đủ điều kiện lên Thân thiết – Sẽ tự động nâng hạng!</div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Điều kiện hạng -->
                <div id="rank-conditions" style="font-size:12px;color:var(--c-muted);line-height:1.8;">
                    <div style="font-weight:700;margin-bottom:8px;color:var(--c-text);display:flex;align-items:center;justify-content:space-between;">
                        <span>Điều kiện thăng hạng:</span>
                        <button class="btn-ico" style="width:24px;height:24px;font-size:10px;background:var(--c-plight);color:var(--c-primary);border-color:var(--c-primary);"
                            data-bs-toggle="modal" data-bs-target="#addDKHangModal" title="Thêm hạng mới">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                    <?php
                    $allHang = [];
                    while ($hrow = $dieu_kien_hang_res->fetch_assoc()) $allHang[] = $hrow;
                    foreach ($allHang as $hrow): ?>
                    <div style="margin-bottom:8px;padding:8px 10px;background:#f8fafc;border:1px solid var(--c-border);border-radius:8px;position:relative;">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:4px;">
                            <div>
                                <span style="color:<?= htmlspecialchars($hrow['mauSac']) ?>;font-weight:700;"><?= htmlspecialchars($hrow['icon']) ?> <?= htmlspecialchars($hrow['tenHang']) ?>:</span>
                                ≥<?= $hrow['minDon'] ?> đơn &amp; ≥<?= $hrow['minSuaChua'] ?> sửa chữa
                                <div style="padding-left:14px;font-size:11px;color:var(--c-muted);">→ <?= $hrow['soVoucherSC'] ?> voucher SC 50% + <?= $hrow['soVoucherMua'] ?> voucher mua -1tr</div>
                            </div>
                            <div style="display:flex;gap:3px;flex-shrink:0;">
                                <button class="btn-ico" style="width:22px;height:22px;font-size:10px;"
                                    data-bs-toggle="modal" data-bs-target="#edtDKHang_<?= $hrow['maHang'] ?>" title="Sửa">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="btn-ico del" style="width:22px;height:22px;font-size:10px;"
                                    onclick="confirmDel('chi_tiet_khach_hang.php?id=<?= $maKH ?>&xoa_dk_hang=<?= $hrow['maHang'] ?>','Xóa hạng «<?= htmlspecialchars(addslashes($hrow['tenHang'])) ?>»?')" title="Xóa">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Modal sửa điều kiện hạng -->
                    <div class="modal fade" id="edtDKHang_<?= $hrow['maHang'] ?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered modal-sm">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" style="font-size:14px;"><i class="fas fa-edit text-warning me-2"></i>Sửa hạng «<?= htmlspecialchars($hrow['tenHang']) ?>»</h5>
                                    <button class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <form method="POST">
                                    <input type="hidden" name="maHang" value="<?= $hrow['maHang'] ?>">
                                    <div class="modal-body" style="display:flex;flex-direction:column;gap:10px;">
                                        <div class="row g-2">
                                            <div class="col-7">
                                                <label class="form-label" style="font-size:12px;">Tên hạng</label>
                                                <input type="text" name="tenHang" class="form-control form-control-sm" value="<?= htmlspecialchars($hrow['tenHang']) ?>" required>
                                            </div>
                                            <div class="col-3">
                                                <label class="form-label" style="font-size:12px;">Icon</label>
                                                <input type="text" name="icon" class="form-control form-control-sm" value="<?= htmlspecialchars($hrow['icon']) ?>">
                                            </div>
                                            <div class="col-2">
                                                <label class="form-label" style="font-size:12px;">Màu</label>
                                                <input type="color" name="mauSac" class="form-control form-control-sm p-0 border-0" value="<?= htmlspecialchars($hrow['mauSac']) ?>" style="height:30px;">
                                            </div>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-6">
                                                <label class="form-label" style="font-size:12px;">Tối thiểu đơn hàng</label>
                                                <input type="number" name="minDon" class="form-control form-control-sm" value="<?= $hrow['minDon'] ?>" min="0" required>
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label" style="font-size:12px;">Tối thiểu sửa chữa</label>
                                                <input type="number" name="minSuaChua" class="form-control form-control-sm" value="<?= $hrow['minSuaChua'] ?>" min="0" required>
                                            </div>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-6">
                                                <label class="form-label" style="font-size:12px;">Voucher SC 50%</label>
                                                <input type="number" name="soVoucherSC" class="form-control form-control-sm" value="<?= $hrow['soVoucherSC'] ?>" min="0" required>
                                            </div>
                                            <div class="col-6">
                                                <label class="form-label" style="font-size:12px;">Voucher mua -1tr</label>
                                                <input type="number" name="soVoucherMua" class="form-control form-control-sm" value="<?= $hrow['soVoucherMua'] ?>" min="0" required>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer" style="padding:10px 14px;">
                                        <button type="button" class="btn-outline-qa" data-bs-dismiss="modal" style="font-size:12px;padding:5px 12px;">Hủy</button>
                                        <button type="submit" name="sua_dieu_kien_hang" class="btn-primary-qa" style="font-size:12px;padding:5px 12px;"><i class="fas fa-save"></i> Lưu</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($allHang)): ?>
                    <div style="text-align:center;color:var(--c-muted);font-size:12px;padding:10px 0;"><i class="fas fa-info-circle me-1"></i>Chưa có hạng nào. Nhấn <strong>+</strong> để thêm.</div>
                    <?php endif; ?>
                </div>

                <!-- Modal thêm điều kiện hạng mới -->
                <div class="modal fade" id="addDKHangModal" tabindex="-1">
                    <div class="modal-dialog modal-dialog-centered modal-sm">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" style="font-size:14px;"><i class="fas fa-plus-circle text-success me-2"></i>Thêm hạng mới</h5>
                                <button class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <form method="POST">
                                <div class="modal-body" style="display:flex;flex-direction:column;gap:10px;">
                                    <div class="row g-2">
                                        <div class="col-7">
                                            <label class="form-label" style="font-size:12px;">Tên hạng <span class="text-danger">*</span></label>
                                            <input type="text" name="tenHang" class="form-control form-control-sm" placeholder="VD: Bạch Kim" required>
                                        </div>
                                        <div class="col-3">
                                            <label class="form-label" style="font-size:12px;">Icon</label>
                                            <input type="text" name="icon" class="form-control form-control-sm" value="🏅" placeholder="🏅">
                                        </div>
                                        <div class="col-2">
                                            <label class="form-label" style="font-size:12px;">Màu</label>
                                            <input type="color" name="mauSac" class="form-control form-control-sm p-0 border-0" value="#374151" style="height:30px;">
                                        </div>
                                    </div>
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <label class="form-label" style="font-size:12px;">Tối thiểu đơn hàng</label>
                                            <input type="number" name="minDon" class="form-control form-control-sm" value="0" min="0" required>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label" style="font-size:12px;">Tối thiểu sửa chữa</label>
                                            <input type="number" name="minSuaChua" class="form-control form-control-sm" value="0" min="0" required>
                                        </div>
                                    </div>
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <label class="form-label" style="font-size:12px;">Voucher SC 50%</label>
                                            <input type="number" name="soVoucherSC" class="form-control form-control-sm" value="1" min="0" required>
                                        </div>
                                        <div class="col-6">
                                            <label class="form-label" style="font-size:12px;">Voucher mua -1tr</label>
                                            <input type="number" name="soVoucherMua" class="form-control form-control-sm" value="1" min="0" required>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer" style="padding:10px 14px;">
                                    <button type="button" class="btn-outline-qa" data-bs-dismiss="modal" style="font-size:12px;padding:5px 12px;">Hủy</button>
                                    <button type="submit" name="them_dieu_kien_hang" class="btn-primary-qa" style="font-size:12px;padding:5px 12px;"><i class="fas fa-plus"></i> Thêm</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <?php if ($so_voucher_con > 0): ?>
                <button class="btn-primary-qa w-100 justify-content-center mt-3" onclick="switchTab('voucher',this)" style="padding:10px;">
                    <i class="fas fa-ticket-alt"></i> Xem <?= $so_voucher_con ?> voucher còn lại
                </button>
                <?php endif; ?>
            </div>
        </div>

    </div><!-- /left-panel -->

    <!-- ── TABS SECTION ── -->
    <div class="tabs-section">
        <div class="tabs-nav" id="mainTabsNav">
            <button class="tab-btn <?= $activeTab=='nhat-ky'?'active':'' ?>" onclick="switchTab('nhat-ky',this)">
                <i class="fas fa-history"></i> Nhật ký <span class="cnt"><?= $tong_nk ?></span>
            </button>
            <button class="tab-btn <?= $activeTab=='don-hang'?'active':'' ?>" onclick="switchTab('don-hang',this)">
                <i class="fas fa-shopping-cart"></i> Đơn hàng <span class="cnt"><?= $tong_don ?></span>
            </button>
            <button class="tab-btn <?= $activeTab=='ky-thuat'?'active':'' ?>" onclick="switchTab('ky-thuat',this)">
                <i class="fas fa-tools"></i> Kỹ thuật <span class="cnt"><?= $tong_sc + $tong_bh ?></span>
            </button>
            <button class="tab-btn <?= $activeTab=='tai-lieu'?'active':'' ?>" onclick="switchTab('tai-lieu',this)">
                <i class="fas fa-folder-open"></i> Hồ sơ / Tài liệu
            </button>
            <button class="tab-btn <?= $activeTab=='voucher'?'active':'' ?>" onclick="switchTab('voucher',this)">
                <i class="fas fa-ticket-alt"></i> Voucher
                <?php if ($so_voucher_con > 0): ?><span class="cnt" style="background:#7c3aed;"><?= $so_voucher_con ?></span><?php endif; ?>
            </button>
        </div>

        <!-- ┌─ TAB: NHẬT KÝ ─┐ -->
        <div class="tab-pane <?= $activeTab=='nhat-ky'?'active':'' ?>" id="tab-nhat-ky">
            <?php
            $modals_nk = '';
            if ($nhat_ky->num_rows > 0):
            ?>
            <div class="timeline">
            <?php while ($nk = $nhat_ky->fetch_assoc()): ?>
                <div class="tl-item">
                    <div class="tl-dot"></div>
                    <div class="tl-card">
                        <div class="tl-top">
                            <div>
                                <div class="tl-meta"><?= date('d/m/Y – H:i', strtotime($nk['thoiGian'])) ?> &nbsp;·&nbsp; <strong><?= htmlspecialchars($nk['nguoiPhuTrach']) ?></strong></div>
                                <div class="tl-type"><?= htmlspecialchars($nk['hinhThuc']) ?></div>
                            </div>
                            <div class="d-flex gap-1 flex-shrink-0">
                                <button class="btn-ico" data-bs-toggle="modal" data-bs-target="#detNK_<?= $nk['maNK'] ?>" title="Xem chi tiết"><i class="fas fa-eye"></i></button>
                                <button class="btn-ico" data-bs-toggle="modal" data-bs-target="#edtNK_<?= $nk['maNK'] ?>" title="Chỉnh sửa"><i class="fas fa-edit"></i></button>
                                <button class="btn-ico del" onclick="confirmDel('chi_tiet_khach_hang.php?id=<?= $maKH ?>&xoa_nk=<?= $nk['maNK'] ?>&tab=nhat-ky','Xóa nhật ký này?')" title="Xóa"><i class="fas fa-trash"></i></button>
                            </div>
                        </div>
                        <div class="tl-body"><?= htmlspecialchars($nk['noiDung']) ?></div>
                    </div>
                </div>
            <?php
                ob_start();
            ?>
            <!-- Modal xem chi tiết NK -->
            <div class="modal fade" id="detNK_<?= $nk['maNK'] ?>" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-comments text-success me-2"></i>Chi tiết giao tiếp</h5>
                            <button class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-2 mb-3">
                                <div class="col-4 text-center p-3 bg-light rounded">
                                    <small class="text-muted d-block">Thời gian</small>
                                    <strong style="font-size:13px;"><?= date('d/m/Y H:i', strtotime($nk['thoiGian'])) ?></strong>
                                </div>
                                <div class="col-4 text-center p-3 bg-light rounded">
                                    <small class="text-muted d-block">Nhân sự</small>
                                    <strong style="font-size:13px;"><?= htmlspecialchars($nk['nguoiPhuTrach']) ?></strong>
                                </div>
                                <div class="col-4 text-center p-3 bg-light rounded">
                                    <small class="text-muted d-block">Hình thức</small>
                                    <strong style="font-size:13px;color:var(--c-pdk);"><?= htmlspecialchars($nk['hinhThuc']) ?></strong>
                                </div>
                            </div>
                            <div style="background:#f8fafc;border:1px solid var(--c-border);border-radius:9px;padding:14px;white-space:pre-wrap;font-size:14px;line-height:1.7;"><?= htmlspecialchars($nk['noiDung']) ?></div>
                        </div>
                        <div class="modal-footer"><button class="btn-outline-qa" data-bs-dismiss="modal">Đóng</button></div>
                    </div>
                </div>
            </div>
            <!-- Modal sửa NK -->
            <div class="modal fade" id="edtNK_<?= $nk['maNK'] ?>" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-edit text-warning me-2"></i>Cập nhật nhật ký</h5>
                            <button class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form method="POST">
                            <div class="modal-body">
                                <input type="hidden" name="maNK" value="<?= $nk['maNK'] ?>">
                                <div class="mb-3">
                                    <label class="form-label">Hình thức</label>
                                    <select name="hinhThuc" class="form-select">
                                        <?php foreach (['Gọi điện tư vấn','Khách đến cửa hàng','Ký hợp đồng','Phàn nàn / Khiếu nại','Hỗ trợ kỹ thuật','Chăm sóc sau bán','Tư vấn trực tuyến'] as $ht): ?>
                                        <option value="<?= $ht ?>" <?= $nk['hinhThuc']==$ht?'selected':'' ?>><?= $ht ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="form-label">Nội dung</label>
                                    <textarea name="noiDung" class="form-control" rows="4" required><?= htmlspecialchars($nk['noiDung']) ?></textarea>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button class="btn-outline-qa" type="button" data-bs-dismiss="modal">Hủy</button>
                                <button type="submit" name="sua_nhat_ky" class="btn-primary-qa"><i class="fas fa-save"></i> Lưu</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php
                $modals_nk .= ob_get_clean();
            endwhile; ?>
            </div>
            <?php else: ?>
            <div class="empty-box"><i class="fas fa-history"></i><p>Chưa có nhật ký giao tiếp nào.<br>Hãy ghi nhận từ bảng bên trái!</p></div>
            <?php endif; ?>
            <?= $modals_nk ?>
        </div><!-- /tab-nhat-ky -->

        <!-- ┌─ TAB: ĐƠN HÀNG ─┐ -->
        <div class="tab-pane <?= $activeTab=='don-hang'?'active':'' ?>" id="tab-don-hang">
            <div class="sec-hdr">
                <div class="sec-title"><i class="fas fa-shopping-bag text-success"></i> Lịch sử đơn hàng</div>
                <button class="btn-primary-qa" data-bs-toggle="modal" data-bs-target="#addDHModal"><i class="fas fa-plus"></i> Tạo đơn mới</button>
            </div>
            <?php
            $modals_dh = '';
            if ($don_hang->num_rows > 0):
            ?>
            <div class="table-responsive">
                <table class="tbl" id="tblDonHang">
                    <thead><tr>
                        <th>Mã đơn</th><th>Ngày</th><th>Sản phẩm</th><th>Kênh bán</th>
                        <th>Tổng tiền</th><th>Thanh toán</th><th>Trạng thái</th><th class="text-center">Tác vụ</th>
                    </tr></thead>
                    <tbody>
                    <?php while ($dh = $don_hang->fetch_assoc()):
                        $maDH_c   = $dh['maDH'];
                        $ctdh_res = $conn->query("SELECT * FROM chi_tiet_don_hang WHERE maDH=$maDH_c");
                        $items    = [];
                        while ($i = $ctdh_res->fetch_assoc()) $items[] = $i;
                        $ts_chip = match($dh['trangThai']) {
                            'Đã hoàn thành' => 'chip-success',
                            'Đang giao'     => 'chip-info',
                            'Đã hủy'        => 'chip-danger',
                            default         => 'chip-gray'
                        };
                        $tt_chip = $dh['tinhTrangThanhToan'] == 'Đã thanh toán' ? 'chip-success' : 'chip-warning';
                        // Danh sách tên sản phẩm
                        $tenSPList = array_map(fn($it) => htmlspecialchars($it['tenSanPham']), $items);
                    ?>
                    <tr>
                        <td>
                            <strong style="color:var(--c-pdk);">#QA-<?= $maDH_c ?></strong><br>
                            <small class="text-muted"><?= count($items) ?> mặt hàng</small>
                            <?php if (!empty($dh['la_don_laptop'])): ?>
                            <br><span class="chip chip-info" style="font-size:11px;margin-top:3px;">💻 Laptop</span>
                            <?php endif; ?>
                        </td>
                        <td><?= date('d/m/Y', strtotime($dh['ngayDat'])) ?></td>
                        <td style="max-width:220px;">
                            <?php if (empty($tenSPList)): ?>
                                <span class="text-muted" style="font-size:12px;font-style:italic;">—</span>
                            <?php else: ?>
                                <?php foreach (array_slice($tenSPList, 0, 2) as $idx => $spName): ?>
                                <div style="font-size:12.5px;font-weight:500;line-height:1.4;
                                    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:210px;"
                                    title="<?= $spName ?>">
                                    <span style="color:var(--c-muted);margin-right:3px;"><?= $idx+1 ?>.</span><?= $spName ?>
                                </div>
                                <?php endforeach; ?>
                                <?php if (count($tenSPList) > 2): ?>
                                <small class="text-muted" style="font-size:11px;">
                                    +<?= count($tenSPList)-2 ?> sản phẩm khác
                                </small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td><span class="chip chip-gray"><?= htmlspecialchars($dh['kenhBanHang'] ?? 'N/A') ?></span></td>
                        <td style="font-weight:700;color:var(--c-pdk);"><?= number_format($dh['tongTien'],0,',','.') ?> đ</td>
                        <td><span class="chip <?= $tt_chip ?>"><?= htmlspecialchars($dh['tinhTrangThanhToan'] ?? '') ?></span></td>
                        <td><span class="chip <?= $ts_chip ?>"><?= htmlspecialchars($dh['trangThai']) ?></span></td>
                        <td>
                            <div class="d-flex gap-1 justify-content-center">
                                <button class="btn-ico" data-bs-toggle="modal" data-bs-target="#detDH_<?= $maDH_c ?>" title="Xem hóa đơn"><i class="fas fa-file-invoice"></i></button>
                                <button class="btn-ico" data-bs-toggle="modal" data-bs-target="#edtDH_<?= $maDH_c ?>" title="Sửa"><i class="fas fa-edit"></i></button>
                                <button class="btn-ico del" onclick="confirmDel('chi_tiet_khach_hang.php?id=<?= $maKH ?>&xoa_dh=<?= $maDH_c ?>&tab=don-hang','Xóa đơn hàng #QA-<?= $maDH_c ?>?')" title="Xóa"><i class="fas fa-trash"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php ob_start(); ?>
                    <!-- Modal xem hóa đơn -->
                    <div class="modal fade" id="detDH_<?= $maDH_c ?>" tabindex="-1">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title"><i class="fas fa-file-invoice-dollar text-success me-2"></i>Hóa đơn #QA-<?= $maDH_c ?>
                                        <?php if (!empty($dh['la_don_laptop'])): ?><span class="badge ms-2" style="background:#3b82f6;font-size:11px;font-weight:600;">💻 Đơn Laptop</span><?php endif; ?>
                                    </h5>
                                    <button class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body" id="invoiceArea_<?= $maDH_c ?>">
                                    <div class="row mb-4 pb-3" style="border-bottom:2px solid var(--c-border);">
                                        <div class="col-sm-7">
                                            <div class="invoice-co">CÔNG TY TNHH TM &amp; PT CN QUANG ANH</div>
                                            <small class="text-muted d-block mt-1">CS1: 57 Nguyễn Bình, Hải Phòng &nbsp;|&nbsp; CS2: 81 Quán Nam, Hải Phòng</small>
                                            <small class="text-muted d-block">CS Kỹ thuật: 59 Nguyễn Bình &nbsp;|&nbsp; ☎ 0982.459.566</small>
                                        </div>
                                        <div class="col-sm-5 text-sm-end mt-2 mt-sm-0">
                                            <div style="font-size:13px;"><strong>Khách hàng:</strong> <?= htmlspecialchars($khach_hang['tenKH']) ?></div>
                                            <div style="font-size:12px;color:var(--c-muted);">
                                                SĐT: <?= htmlspecialchars($khach_hang['soDienThoai'] ?? '') ?><br>
                                                ĐC: <?= htmlspecialchars($dh['diaChiGiaoHang'] ?: ($khach_hang['diaChi'] ?? 'N/A')) ?>
                                            </div>
                                        </div>
                                    </div>
                                    <table class="tbl mb-3">
                                        <thead><tr>
                                            <th style="width:36px;" class="text-center">STT</th>
                                            <th style="width:80px;">Mã SP</th>
                                            <th>Tên sản phẩm / Dịch vụ</th>
                                            <th class="text-center">SL</th>
                                            <th class="text-end">Đơn giá</th>
                                            <th class="text-end">Thành tiền</th>
                                        </tr></thead>
                                        <tbody>
                                        <?php foreach ($items as $idx => $item): ?>
                                        <tr>
                                            <td class="text-center"><?= $idx+1 ?></td>
                                            <td><small class="text-muted"><?= htmlspecialchars($item['maSanPham'] ?? '') ?></small></td>
                                            <td class="fw-semibold"><?= htmlspecialchars($item['tenSanPham']) ?></td>
                                            <td class="text-center"><?= $item['soLuong'] ?></td>
                                            <td class="text-end"><?= number_format($item['donGia'],0,',','.') ?></td>
                                            <td class="text-end fw-bold" style="color:var(--c-pdk);"><?= number_format($item['thanhTien'],0,',','.') ?> đ</td>
                                        </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr style="background:#f8fafc;">
                                                <td colspan="5" class="text-end fw-bold py-2">TỔNG CỘNG:</td>
                                                <td class="text-end fw-bold" style="font-size:16px;color:var(--c-danger);"><?= number_format($dh['tongTien'],0,',','.') ?> đ</td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                    <div class="row g-2">
                                        <div class="col-md-6">
                                            <div class="p-3 bg-light rounded border" style="font-size:13px;">
                                                <div><strong>Ngày đặt:</strong> <?= date('d/m/Y', strtotime($dh['ngayDat'])) ?></div>
                                                <div><strong>Kênh bán:</strong> <?= htmlspecialchars($dh['kenhBanHang'] ?? '') ?></div>
                                                <div><strong>Phương thức TT:</strong> <?= htmlspecialchars($dh['phuongThucThanhToan'] ?? '') ?></div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="p-3 bg-light rounded border" style="font-size:13px;">
                                                <div><strong>TT Thanh toán:</strong> <?= htmlspecialchars($dh['tinhTrangThanhToan'] ?? '') ?></div>
                                                <div><strong>Trạng thái:</strong> <?= htmlspecialchars($dh['trangThai']) ?></div>
                                                <?php if ($dh['ghiChu']): ?><div><strong>Ghi chú:</strong> <?= htmlspecialchars($dh['ghiChu']) ?></div><?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row mt-5 text-center">
                                        <div class="col-6"><div class="border-top pt-3"><small class="text-muted">Khách hàng ký tên<br>(Ký, ghi rõ họ tên)</small></div></div>
                                        <div class="col-6"><div class="border-top pt-3"><small class="text-muted">Nhân viên phụ trách<br>(Ký, ghi rõ họ tên)</small></div></div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button class="btn-outline-qa" data-bs-dismiss="modal">Đóng</button>
                                    <button class="btn-primary-qa" onclick="printArea('invoiceArea_<?= $maDH_c ?>')"><i class="fas fa-print"></i> In hóa đơn</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Modal sửa đơn hàng -->
                    <div class="modal fade" id="edtDH_<?= $maDH_c ?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title"><i class="fas fa-edit text-warning me-2"></i>Sửa đơn hàng #QA-<?= $maDH_c ?></h5>
                                    <button class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <form method="POST">
                                    <div class="modal-body">
                                        <input type="hidden" name="maDH" value="<?= $maDH_c ?>">
                                        <div class="row g-3">
                                            <div class="col-md-6">
                                                <label class="form-label">Ngày đặt</label>
                                                <input type="date" name="ngayDat" class="form-control" value="<?= $dh['ngayDat'] ?>" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Kênh bán hàng</label>
                                                <select name="kenhBanHang" class="form-select">
                                                    <?php foreach (['Tại shop','Website','Zalo','Facebook','Điện thoại','Đại lý'] as $k): ?>
                                                    <option <?= ($dh['kenhBanHang']==$k)?'selected':'' ?>><?= $k ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Phương thức thanh toán</label>
                                                <select name="phuongThucThanhToan" class="form-select">
                                                    <?php foreach (['Tiền mặt','Chuyển khoản','Thẻ ngân hàng','Ví điện tử','Công nợ'] as $p): ?>
                                                    <option <?= ($dh['phuongThucThanhToan']==$p)?'selected':'' ?>><?= $p ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Tình trạng thanh toán</label>
                                                <select name="tinhTrangThanhToan" class="form-select">
                                                    <?php foreach (['Chưa thanh toán','Đã thanh toán','Thanh toán một phần'] as $t): ?>
                                                    <option <?= ($dh['tinhTrangThanhToan']==$t)?'selected':'' ?>><?= $t ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-12">
                                                <label class="form-label">Trạng thái đơn</label>
                                                <select name="trangThai" class="form-select">
                                                    <?php foreach (['Chờ duyệt','Đang xử lý','Đang giao','Đã hoàn thành','Đã hủy'] as $ts): ?>
                                                    <option <?= ($dh['trangThai']==$ts)?'selected':'' ?>><?= $ts ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label">Ghi chú</label>
                                                <textarea name="ghiChu" class="form-control" rows="2"><?= htmlspecialchars($dh['ghiChu'] ?? '') ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button class="btn-outline-qa" type="button" data-bs-dismiss="modal">Hủy</button>
                                        <button type="submit" name="sua_don_hang" class="btn-primary-qa"><i class="fas fa-save"></i> Lưu</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php
                        $modals_dh .= ob_get_clean();
                    endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-box"><i class="fas fa-shopping-bag"></i><p>Chưa có đơn hàng nào.<br><button class="btn-primary-qa mt-2" data-bs-toggle="modal" data-bs-target="#addDHModal"><i class="fas fa-plus"></i> Tạo đơn đầu tiên</button></p></div>
            <?php endif; ?>
            <?= $modals_dh ?>
        </div><!-- /tab-don-hang -->

        <!-- ┌─ TAB: KỸ THUẬT (Sửa chữa + Bảo hành) ─┐ -->
        <div class="tab-pane <?= $activeTab=='ky-thuat'?'active':'' ?>" id="tab-ky-thuat">

            <!-- Phiếu sửa chữa -->
            <div class="sec-hdr">
                <div class="sec-title"><i class="fas fa-tools text-warning"></i> Phiếu sửa chữa</div>
                <button class="btn-primary-qa" data-bs-toggle="modal" data-bs-target="#addPSModal"><i class="fas fa-plus"></i> Tạo phiếu mới</button>
            </div>
            <?php
            $modals_ps = '';
            if ($phieu_sua->num_rows > 0):
            ?>
            <div class="table-responsive mb-5">
                <table class="tbl" id="tblSuaChua">
                    <thead><tr>
                        <th>Mã phiếu</th><th>Thiết bị</th><th>Ngày nhận</th>
                        <th>Ngày trả</th><th>Chi phí</th><th>Trạng thái</th><th class="text-center">Tác vụ</th>
                    </tr></thead>
                    <tbody>
                    <?php while ($ps = $phieu_sua->fetch_assoc()):
                        $anh_ps = $conn->query("SELECT * FROM anh_phieu_sua WHERE maPhieu={$ps['maPhieu']} LIMIT 4");
                        $ts_chip = match($ps['trangThai']) {
                            'Đã sửa xong','Đã bàn giao' => 'chip-success',
                            'Đang xử lý','Đang kiểm tra' => 'chip-info',
                            'Chờ linh kiện' => 'chip-warning',
                            'Tiếp nhận' => 'chip-gray',
                            default => 'chip-gray'
                        };
                    ?>
                    <tr>
                        <td><strong style="color:var(--c-warn);">#SC-<?= $ps['maPhieu'] ?></strong></td>
                        <td>
                            <div style="font-weight:600;"><?= htmlspecialchars($ps['tenThietBi'] ?? '—') ?></div>
                            <?php if ($ps['moTaLoi']): ?><small class="text-muted" style="display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden;"><?= htmlspecialchars($ps['moTaLoi']) ?></small><?php endif; ?>
                        </td>
                        <td><?= date('d/m/Y', strtotime($ps['ngayNhan'])) ?></td>
                        <td><?= $ps['ngayTra'] ? date('d/m/Y', strtotime($ps['ngayTra'])) : '<span class="text-muted">—</span>' ?></td>
                        <td style="font-weight:700;<?= $ps['chiPhi']>0?'color:var(--c-danger);':'' ?>"><?= $ps['chiPhi']>0 ? number_format($ps['chiPhi'],0,',','.').' đ' : '—' ?></td>
                        <td><span class="chip <?= $ts_chip ?>"><?= htmlspecialchars($ps['trangThai']) ?></span></td>
                        <td>
                            <div class="d-flex gap-1 justify-content-center">
                                <button class="btn-ico" data-bs-toggle="modal" data-bs-target="#detPS_<?= $ps['maPhieu'] ?>" title="Xem chi tiết"><i class="fas fa-eye"></i></button>
                                <button class="btn-ico" data-bs-toggle="modal" data-bs-target="#edtPS_<?= $ps['maPhieu'] ?>" title="Sửa"><i class="fas fa-edit"></i></button>
                                <button class="btn-ico" data-bs-toggle="modal" data-bs-target="#printPS_<?= $ps['maPhieu'] ?>" title="In phiếu"><i class="fas fa-print"></i></button>
                                <button class="btn-ico" data-bs-toggle="modal" data-bs-target="#uploadImgPS_<?= $ps['maPhieu'] ?>" title="Thêm ảnh"><i class="fas fa-camera"></i></button>
                                <button class="btn-ico del" onclick="confirmDel('chi_tiet_khach_hang.php?id=<?= $maKH ?>&xoa_ps=<?= $ps['maPhieu'] ?>&tab=ky-thuat','Xóa phiếu sửa chữa #SC-<?= $ps['maPhieu'] ?>?')" title="Xóa"><i class="fas fa-trash"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php
                    // Tính toán thông tin thời gian cho phiếu
                    $ngayNhanTs  = strtotime($ps['ngayNhan']);
                    $ngayTraTs   = $ps['ngayTra'] ? strtotime($ps['ngayTra']) : null;
                    $hомNayTs    = strtotime(date('Y-m-d'));
                    $soDaSua     = (int)floor(($hомNayTs - $ngayNhanTs) / 86400);
                    $soConLai    = $ngayTraTs ? (int)floor(($ngayTraTs - $hомNayTs) / 86400) : null;
                    $tongNgay    = $ngayTraTs ? (int)floor(($ngayTraTs - $ngayNhanTs) / 86400) : null;
                    $pctProgress = ($tongNgay && $tongNgay > 0) ? min(100, max(0, round($soDaSua / $tongNgay * 100))) : null;
                    $isQuaHan    = $ngayTraTs && $hомNayTs > $ngayTraTs && !in_array($ps['trangThai'], ['Đã sửa xong','Đã bàn giao']);
                    $isDone      = in_array($ps['trangThai'], ['Đã sửa xong','Đã bàn giao']);

                    // Timeline steps
                    $allSteps = ['Tiếp nhận','Đang kiểm tra','Đang xử lý','Chờ linh kiện','Đã sửa xong','Đã bàn giao'];
                    $stepIdx  = array_search($ps['trangThai'], $allSteps);
                    if ($stepIdx === false) $stepIdx = 0;

                    $stepIcons = ['📥','🔍','⚙️','📦','✅','🚀'];
                    $stepColors = ['#64748b','#3b82f6','#f59e0b','#ef4444','#10b981','#8b5cf6'];

                    // Lấy ảnh đã có (rewind)
                    $anh_ps->data_seek(0);
                    $anhArr = [];
                    while ($a = $anh_ps->fetch_assoc()) $anhArr[] = $a;

                    ob_start();
                    ?>

                    <!-- ══ MODAL XEM CHI TIẾT PHIẾU ══ -->
                    <div class="modal fade" id="detPS_<?= $ps['maPhieu'] ?>" tabindex="-1">
                        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
                            <div class="modal-content" style="border:none;border-radius:18px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.2);">

                                <!-- Header gradient -->
                                <div style="background:linear-gradient(135deg,#1c1917,#292524,#44403c);padding:20px 24px 16px;position:relative;overflow:hidden;">
                                    <div style="position:absolute;inset:0;background:radial-gradient(ellipse at 80% 50%,rgba(251,191,36,.15),transparent 70%);"></div>
                                    <div style="position:relative;z-index:1;display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
                                        <div style="display:flex;align-items:center;gap:14px;">
                                            <div style="width:50px;height:50px;background:linear-gradient(135deg,#fbbf24,#f59e0b);border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:24px;box-shadow:0 4px 14px rgba(251,191,36,.4);flex-shrink:0;">🔧</div>
                                            <div>
                                                <div style="font-size:18px;font-weight:800;color:#fff;letter-spacing:-.01em;">Phiếu sửa chữa <span style="color:#fbbf24;">#SC-<?= str_pad($ps['maPhieu'],5,'0',STR_PAD_LEFT) ?></span></div>
                                                <div style="font-size:12px;color:rgba(255,255,255,.55);margin-top:3px;">
                                                    <?= htmlspecialchars($ps['tenThietBi'] ?? '—') ?> &nbsp;·&nbsp; Nhận: <?= date('d/m/Y', $ngayNhanTs) ?>
                                                    <?php if ($isQuaHan): ?><span style="background:#ef4444;color:#fff;font-size:10px;padding:2px 8px;border-radius:10px;margin-left:8px;font-weight:700;">⚠️ QUÁ HẠN</span><?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                        <button class="btn-close btn-close-white" data-bs-dismiss="modal" style="opacity:.6;flex-shrink:0;"></button>
                                    </div>
                                </div>

                                <div class="modal-body p-0">
                                    <div class="row g-0">

                                        <!-- Cột trái: thông tin + timeline -->
                                        <div class="col-lg-6" style="padding:22px 20px 22px 24px;border-right:1px solid #f1f5f9;">

                                            <!-- Timeline tiến trình -->
                                            <div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;margin-bottom:12px;display:flex;align-items:center;gap:6px;">
                                                <i class="fas fa-route"></i> Tiến trình xử lý
                                                <span style="flex:1;height:1px;background:#e2e8f0;display:block;margin-left:6px;"></span>
                                            </div>
                                            <div style="position:relative;padding-left:28px;margin-bottom:20px;">
                                                <!-- Đường dọc -->
                                                <div style="position:absolute;left:10px;top:8px;bottom:8px;width:2px;background:linear-gradient(180deg,<?= $stepColors[$stepIdx] ?>,#e2e8f0);border-radius:2px;"></div>
                                                <?php foreach ($allSteps as $si => $step):
                                                    $isPast    = $si < $stepIdx;
                                                    $isCurrent = $si === $stepIdx;
                                                    $isFuture  = $si > $stepIdx;
                                                    $dotColor  = $isPast ? '#10b981' : ($isCurrent ? $stepColors[$si] : '#e2e8f0');
                                                    $textColor = $isPast ? '#10b981' : ($isCurrent ? $stepColors[$si] : '#cbd5e1');
                                                ?>
                                                <div style="position:relative;display:flex;align-items:center;gap:10px;padding:5px 0;<?= $isCurrent ? 'margin:-2px 0;' : '' ?>">
                                                    <!-- Dot -->
                                                    <div style="position:absolute;left:-22px;width:<?= $isCurrent ? '20px' : '16px' ?>;height:<?= $isCurrent ? '20px' : '16px' ?>;border-radius:50%;background:<?= $dotColor ?>;border:3px solid <?= $isCurrent ? $stepColors[$si] : ($isPast ? '#10b981' : '#e2e8f0') ?>;box-shadow:<?= $isCurrent ? '0 0 0 4px '.($stepColors[$si]).'22' : 'none' ?>;display:flex;align-items:center;justify-content:center;font-size:<?= $isCurrent ? '9px' : '8px' ?>;<?= $isCurrent ? 'margin-left:-2px;' : '' ?>z-index:1;">
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

                                            <!-- Thông tin thời gian -->
                                            <div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;margin-bottom:10px;display:flex;align-items:center;gap:6px;">
                                                <i class="fas fa-clock"></i> Thời gian thực hiện
                                                <span style="flex:1;height:1px;background:#e2e8f0;display:block;margin-left:6px;"></span>
                                            </div>
                                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px;">
                                                <div style="background:#f8fafc;border-radius:10px;padding:11px 13px;">
                                                    <div style="font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Ngày nhận</div>
                                                    <div style="font-size:15px;font-weight:700;color:#0f172a;margin-top:2px;"><?= date('d/m/Y', $ngayNhanTs) ?></div>
                                                </div>
                                                <div style="background:<?= $isQuaHan ? '#fff1f2' : '#f8fafc' ?>;border-radius:10px;padding:11px 13px;<?= $isQuaHan ? 'border:1px solid #fca5a5;' : '' ?>">
                                                    <div style="font-size:10px;color:<?= $isQuaHan ? '#ef4444' : '#94a3b8' ?>;text-transform:uppercase;letter-spacing:.05em;">Ngày trả<?= $isDone ? '' : ' (dự kiến)' ?></div>
                                                    <div style="font-size:15px;font-weight:700;color:<?= $isQuaHan ? '#ef4444' : '#0f172a' ?>;margin-top:2px;"><?= $ngayTraTs ? date('d/m/Y', $ngayTraTs) : '—' ?></div>
                                                </div>
                                                <div style="background:<?= $soDaSua > 7 ? '#fff7ed' : '#f0fdf4' ?>;border-radius:10px;padding:11px 13px;">
                                                    <div style="font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;">Đã sửa được</div>
                                                    <div style="font-size:15px;font-weight:700;color:<?= $soDaSua > 7 ? '#d97706' : '#059669' ?>;margin-top:2px;"><?= $soDaSua ?> ngày</div>
                                                </div>
                                                <div style="background:<?= $soConLai !== null && $soConLai < 0 ? '#fff1f2' : ($soConLai !== null && $soConLai <= 1 ? '#fff7ed' : '#f8fafc') ?>;border-radius:10px;padding:11px 13px;">
                                                    <div style="font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:.05em;"><?= $isDone ? 'Kết quả' : 'Còn lại' ?></div>
                                                    <div style="font-size:15px;font-weight:700;margin-top:2px;color:<?= $isDone ? '#059669' : ($soConLai === null ? '#94a3b8' : ($soConLai < 0 ? '#ef4444' : ($soConLai <= 1 ? '#d97706' : '#0f172a'))) ?>;">
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
                                                    <span style="font-weight:700;color:<?= $isQuaHan ? '#ef4444' : '#f59e0b' ?>;"><?= $pctProgress ?>%<?= $isQuaHan ? ' (Quá hạn!)' : '' ?></span>
                                                </div>
                                                <div style="background:#e2e8f0;border-radius:20px;height:8px;overflow:hidden;">
                                                    <div style="width:<?= min(100,$pctProgress) ?>%;height:100%;border-radius:20px;background:<?= $isQuaHan ? 'linear-gradient(90deg,#ef4444,#dc2626)' : ($pctProgress > 80 ? 'linear-gradient(90deg,#f59e0b,#d97706)' : 'linear-gradient(90deg,#10b981,#059669)') ?>;transition:width .5s;"></div>
                                                </div>
                                            </div>
                                            <?php endif; ?>

                                            <!-- Chi phí -->
                                            <div style="background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:1.5px solid #86efac;border-radius:12px;padding:14px;margin-bottom:14px;">
                                                <div style="font-size:11px;color:#166534;font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px;">💰 Chi phí sửa chữa</div>
                                                <?php if (isset($ps['chi_phi_goc']) && $ps['chi_phi_goc'] > 0 && $ps['chi_phi_goc'] != $ps['chiPhi']): ?>
                                                <div style="font-size:12px;color:#64748b;text-decoration:line-through;margin-bottom:2px;">Gốc: <?= number_format($ps['chi_phi_goc'],0,',','.') ?> đ</div>
                                                <div style="font-size:10px;color:#ef4444;margin-bottom:4px;">– Voucher giảm: <?= number_format($ps['chi_phi_goc'] - $ps['chiPhi'],0,',','.') ?> đ</div>
                                                <?php endif; ?>
                                                <div style="font-size:22px;font-weight:800;color:#059669;"><?= $ps['chiPhi'] > 0 ? number_format($ps['chiPhi'],0,',','.').' đ' : '<span style="color:#94a3b8;font-size:15px;">Chưa xác định</span>' ?></div>
                                            </div>

                                            <!-- Mô tả lỗi -->
                                            <div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                                                <i class="fas fa-clipboard-list"></i> Mô tả / Ghi chú kỹ thuật
                                                <span style="flex:1;height:1px;background:#e2e8f0;"></span>
                                            </div>
                                            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:13px;white-space:pre-wrap;font-size:13px;line-height:1.7;color:#374151;min-height:80px;"><?= $ps['moTaLoi'] ? htmlspecialchars($ps['moTaLoi']) : '<span style="color:#cbd5e1;font-style:italic;">Không có mô tả</span>' ?></div>
                                        </div>

                                        <!-- Cột phải: ảnh -->
                                        <div class="col-lg-6" style="padding:22px 24px 22px 20px;background:#fafafa;">
                                            <div style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;margin-bottom:12px;display:flex;align-items:center;gap:6px;">
                                                <i class="fas fa-images"></i> Ảnh thiết bị (<?= count($anhArr) ?>)
                                                <span style="flex:1;height:1px;background:#e2e8f0;"></span>
                                                <button type="button" class="btn btn-sm" style="font-size:11px;padding:2px 10px;border:1px solid #e2e8f0;border-radius:8px;background:#fff;color:#64748b;" data-bs-toggle="modal" data-bs-target="#uploadImgPS_<?= $ps['maPhieu'] ?>">
                                                    <i class="fas fa-plus"></i> Thêm ảnh
                                                </button>
                                            </div>

                                            <?php if (empty($anhArr)): ?>
                                            <div style="border:2px dashed #e2e8f0;border-radius:12px;padding:40px 20px;text-align:center;">
                                                <div style="font-size:36px;margin-bottom:10px;">📷</div>
                                                <div style="color:#94a3b8;font-size:13px;">Chưa có ảnh đính kèm</div>
                                                <button type="button" class="btn btn-sm mt-3" style="background:#f0fdf4;border:1px solid #86efac;color:#059669;border-radius:8px;font-size:12px;" data-bs-toggle="modal" data-bs-target="#uploadImgPS_<?= $ps['maPhieu'] ?>">
                                                    <i class="fas fa-camera me-1"></i> Tải ảnh lên
                                                </button>
                                            </div>
                                            <?php else:
                                                // Nhóm ảnh theo loại
                                                $anhGroups = [];
                                                foreach ($anhArr as $a) {
                                                    $g = $a['loaiAnh'] ?? 'khac';
                                                    $anhGroups[$g][] = $a;
                                                }
                                                $groupLabels = ['truoc'=>'📸 Trước sửa','sau'=>'✅ Sau sửa','loi'=>'⚠️ Lỗi / Hỏng','linh_kien'=>'🔩 Linh kiện','khac'=>'📎 Khác'];
                                            ?>
                                            <?php foreach ($anhGroups as $gKey => $gAnhs): ?>
                                            <div style="margin-bottom:14px;">
                                                <div style="font-size:11px;font-weight:600;color:#64748b;margin-bottom:7px;"><?= $groupLabels[$gKey] ?? ucfirst($gKey) ?> (<?= count($gAnhs) ?>)</div>
                                                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(80px,1fr));gap:6px;">
                                                    <?php foreach ($gAnhs as $anh): ?>
                                                    <div style="position:relative;aspect-ratio:1;border-radius:10px;overflow:hidden;border:2px solid #e2e8f0;cursor:pointer;transition:border-color .15s;" onmouseover="this.style.borderColor='#f59e0b'" onmouseout="this.style.borderColor='#e2e8f0'" onclick="showBigImg('../<?= htmlspecialchars($anh['duongDan']) ?>')">
                                                        <img src="../<?= htmlspecialchars($anh['duongDan']) ?>" style="width:100%;height:100%;object-fit:cover;display:block;">
                                                        <?php if (!empty($anh['moTa'])): ?>
                                                        <div style="position:absolute;bottom:0;left:0;right:0;background:linear-gradient(transparent,rgba(0,0,0,.6));padding:4px 5px;font-size:9px;color:#fff;line-height:1.2;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;"><?= htmlspecialchars($anh['moTa']) ?></div>
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
                                                    <strong style="color:#f59e0b;">#SC-<?= str_pad($ps['maPhieu'],5,'0',STR_PAD_LEFT) ?></strong>
                                                </div>
                                                <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
                                                    <span>Hạng KH</span>
                                                    <strong><?= htmlspecialchars($khach_hang['loaiKhachHang']) ?></strong>
                                                </div>
                                                <div style="display:flex;justify-content:space-between;">
                                                    <span>Số ảnh</span>
                                                    <strong><?= count($anhArr) ?> ảnh</strong>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="modal-footer" style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:12px 24px;">
                                    <button class="btn-outline-qa" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i>Đóng</button>
                                    <button class="btn btn-sm" style="background:#f0fdf4;border:1px solid #86efac;color:#059669;border-radius:10px;padding:7px 16px;font-size:13px;font-weight:600;" data-bs-toggle="modal" data-bs-target="#edtPS_<?= $ps['maPhieu'] ?>"><i class="fas fa-edit me-1"></i>Cập nhật</button>
                                    <button class="btn-primary-qa" style="background:linear-gradient(135deg,#f59e0b,#d97706);border-color:transparent;" onclick="printPS_<?= $ps['maPhieu'] ?>()"><i class="fas fa-print me-1"></i>In phiếu</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ══ MODAL SỬA / CẬP NHẬT PHIẾU ══ -->
                    <div class="modal fade" id="edtPS_<?= $ps['maPhieu'] ?>" tabindex="-1">
                        <div class="modal-dialog modal-lg modal-dialog-centered">
                            <div class="modal-content" style="border:none;border-radius:16px;overflow:hidden;box-shadow:0 16px 50px rgba(0,0,0,.15);">
                                <div style="background:linear-gradient(135deg,#1e3a8a,#2563eb,#3b82f6);padding:18px 24px;">
                                    <div style="display:flex;align-items:center;justify-content:space-between;">
                                        <div>
                                            <div style="font-size:16px;font-weight:800;color:#fff;">✏️ Cập nhật phiếu <span style="color:#93c5fd;">#SC-<?= str_pad($ps['maPhieu'],5,'0',STR_PAD_LEFT) ?></span></div>
                                            <div style="font-size:12px;color:rgba(255,255,255,.6);margin-top:2px;"><?= htmlspecialchars($ps['tenThietBi'] ?? '') ?></div>
                                        </div>
                                        <button class="btn-close btn-close-white" data-bs-dismiss="modal" style="opacity:.6;"></button>
                                    </div>
                                </div>
                                <form method="POST">
                                    <div class="modal-body" style="padding:22px 24px;">
                                        <input type="hidden" name="maPhieu" value="<?= $ps['maPhieu'] ?>">

                                        <!-- Trạng thái nổi bật -->
                                        <div style="margin-bottom:18px;">
                                            <label style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;display:flex;align-items:center;gap:6px;margin-bottom:10px;">
                                                <i class="fas fa-route"></i> Cập nhật trạng thái
                                                <span style="flex:1;height:1px;background:#e2e8f0;"></span>
                                            </label>
                                            <div class="status-pill-group">
                                                <?php foreach ($allSteps as $si => $step):
                                                    $sid = 'edt_sp_' . $ps['maPhieu'] . '_' . $si;
                                                    $vals = ['Tiếp nhận'=>'tiep_nhan','Đang kiểm tra'=>'kiem_tra','Đang xử lý'=>'xu_ly','Chờ linh kiện'=>'linh_kien_e','Đã sửa xong'=>'sua_xong','Đã bàn giao'=>'ban_giao'];
                                                    $val = $vals[$step];
                                                ?>
                                                <input type="radio" class="status-pill" name="trangThai" id="<?= $sid ?>" value="<?= $step ?>" <?= ($ps['trangThai']===$step)?'checked':'' ?>>
                                                <label for="<?= $sid ?>" style="<?= ($ps['trangThai']===$step) ? 'background:'.$stepColors[$si].';color:#fff;border-color:transparent;' : '' ?>">
                                                    <?= $stepIcons[$si] ?> <?= $step ?>
                                                </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>

                                        <div style="height:1px;background:#f1f5f9;margin:16px 0;"></div>

                                        <!-- Thông tin cơ bản -->
                                        <label style="font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;display:flex;align-items:center;gap:6px;margin-bottom:12px;">
                                            <i class="fas fa-laptop"></i> Thông tin phiếu
                                            <span style="flex:1;height:1px;background:#e2e8f0;"></span>
                                        </label>
                                        <div class="row g-3">
                                            <div class="col-12">
                                                <label class="form-label fw-semibold" style="font-size:13px;">Tên thiết bị / Model</label>
                                                <input type="text" name="tenThietBi" class="form-control" value="<?= htmlspecialchars($ps['tenThietBi'] ?? '') ?>" required>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label fw-semibold" style="font-size:13px;">Ngày nhận</label>
                                                <input type="date" name="ngayNhan" class="form-control" value="<?= $ps['ngayNhan'] ?>" required>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label fw-semibold" style="font-size:13px;">Ngày trả dự kiến</label>
                                                <input type="date" name="ngayTra" class="form-control" value="<?= $ps['ngayTra'] ?? '' ?>">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label fw-semibold" style="font-size:13px;">Chi phí (đ)</label>
                                                <input type="number" name="chiPhi" class="form-control" value="<?= $ps['chiPhi'] ?? 0 ?>" min="0" step="1000">
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label fw-semibold" style="font-size:13px;">Mô tả lỗi / Ghi chú kỹ thuật</label>
                                                <textarea name="moTaLoi" class="form-control" rows="4" style="font-size:13px;resize:vertical;"><?= htmlspecialchars($ps['moTaLoi'] ?? '') ?></textarea>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer" style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:12px 24px;">
                                        <button class="btn-outline-qa" type="button" data-bs-dismiss="modal">Hủy</button>
                                        <button type="submit" name="sua_phieu_sua" class="btn-primary-qa" style="background:linear-gradient(135deg,#2563eb,#1d4ed8);border-color:transparent;">
                                            <i class="fas fa-save me-1"></i> Lưu cập nhật
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- ══ MODAL IN PHIẾU ══ -->
                    <div class="modal fade" id="printPSModal_<?= $ps['maPhieu'] ?>" tabindex="-1">
                        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                            <div class="modal-content" style="border:none;border-radius:16px;overflow:hidden;">
                                <div style="background:#f8fafc;border-bottom:1px solid #e2e8f0;padding:14px 24px;display:flex;align-items:center;justify-content:space-between;">
                                    <div style="font-weight:700;font-size:15px;color:#0f172a;"><i class="fas fa-print text-warning me-2"></i>Xem trước phiếu in</div>
                                    <button class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <!-- Nội dung in -->
                                <div class="modal-body p-0">
                                <div id="printPSArea_<?= $ps['maPhieu'] ?>" style="padding:28px 32px;background:#fff;font-family:'Be Vietnam Pro',sans-serif;">

                                    <!-- Header công ty -->
                                    <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:20px;padding-bottom:16px;border-bottom:2px solid #f59e0b;">
                                        <div>
                                            <div style="font-size:16px;font-weight:800;color:#1c1917;letter-spacing:-.01em;">CÔNG TY TNHH TM &amp; PT CN QUANG ANH</div>
                                            <div style="font-size:11px;color:#64748b;margin-top:4px;line-height:1.7;">
                                                CS1: 57 Nguyễn Bình, Hải Phòng &nbsp;|&nbsp; CS2: 81 Quán Nam, Hải Phòng<br>
                                                CS Kỹ thuật: 59 Nguyễn Bình &nbsp;|&nbsp; ☎ 0982.459.566
                                            </div>
                                        </div>
                                        <div style="text-align:right;">
                                            <div style="background:linear-gradient(135deg,#fbbf24,#f59e0b);color:#fff;font-size:13px;font-weight:800;padding:6px 14px;border-radius:8px;letter-spacing:.03em;">PHIẾU SỬA CHỮA</div>
                                            <div style="font-size:18px;font-weight:800;color:#f59e0b;margin-top:5px;">#SC-<?= str_pad($ps['maPhieu'],5,'0',STR_PAD_LEFT) ?></div>
                                            <div style="font-size:10px;color:#94a3b8;margin-top:2px;">Ngày in: <?= date('d/m/Y H:i') ?></div>
                                        </div>
                                    </div>

                                    <!-- Trạng thái nổi bật -->
                                    <div style="background:<?= match(true) { $isDone => 'linear-gradient(135deg,#f0fdf4,#dcfce7)', $isQuaHan => 'linear-gradient(135deg,#fff1f2,#fee2e2)', default => 'linear-gradient(135deg,#fff7ed,#fef3c7)' } ?>;border-radius:10px;padding:12px 16px;margin-bottom:18px;display:flex;align-items:center;gap:12px;border:1.5px solid <?= $isDone ? '#86efac' : ($isQuaHan ? '#fca5a5' : '#fbbf24') ?>;">
                                        <div style="font-size:24px;"><?= $isDone ? '✅' : ($isQuaHan ? '⚠️' : '🔧') ?></div>
                                        <div>
                                            <div style="font-size:14px;font-weight:800;color:<?= $isDone ? '#059669' : ($isQuaHan ? '#dc2626' : '#d97706') ?>;"><?= htmlspecialchars($ps['trangThai']) ?></div>
                                            <div style="font-size:11px;color:#64748b;margin-top:1px;">
                                                <?= $isDone ? 'Thiết bị đã được sửa chữa xong.' : ($isQuaHan ? 'Đã quá ngày trả dự kiến.' : 'Đang trong quá trình xử lý.') ?>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Thông tin khách + thiết bị -->
                                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
                                        <div style="background:#f8fafc;border-radius:10px;padding:14px;">
                                            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;margin-bottom:8px;">Thông tin khách hàng</div>
                                            <div style="font-size:14px;font-weight:700;color:#0f172a;"><?= htmlspecialchars($khach_hang['tenKH']) ?></div>
                                            <div style="font-size:12px;color:#64748b;margin-top:3px;">📞 <?= htmlspecialchars($khach_hang['soDienThoai'] ?? '—') ?></div>
                                            <?php if (!empty($khach_hang['diaChi'])): ?><div style="font-size:12px;color:#64748b;">📍 <?= htmlspecialchars($khach_hang['diaChi']) ?></div><?php endif; ?>
                                            <div style="margin-top:6px;font-size:11px;"><span style="background:#ede9fe;color:#5b21b6;padding:2px 8px;border-radius:6px;font-weight:600;"><?= htmlspecialchars($khach_hang['loaiKhachHang']) ?></span></div>
                                        </div>
                                        <div style="background:#f8fafc;border-radius:10px;padding:14px;">
                                            <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;margin-bottom:8px;">Thời gian</div>
                                            <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:5px;">
                                                <span style="color:#64748b;">Ngày nhận:</span>
                                                <strong><?= date('d/m/Y', $ngayNhanTs) ?></strong>
                                            </div>
                                            <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:5px;">
                                                <span style="color:#64748b;">Ngày trả (DK):</span>
                                                <strong><?= $ngayTraTs ? date('d/m/Y', $ngayTraTs) : '—' ?></strong>
                                            </div>
                                            <div style="display:flex;justify-content:space-between;font-size:12px;">
                                                <span style="color:#64748b;">Thời gian sửa:</span>
                                                <strong><?= $tongNgay ? $tongNgay.' ngày' : '—' ?></strong>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Chi tiết phiếu -->
                                    <table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:16px;">
                                        <tr style="background:#1c1917;">
                                            <th style="padding:8px 12px;color:#fff;font-weight:600;text-align:left;width:30%;border-radius:6px 0 0 0;">Hạng mục</th>
                                            <th style="padding:8px 12px;color:#fff;font-weight:600;text-align:left;border-radius:0 6px 0 0;">Chi tiết</th>
                                        </tr>
                                        <tr style="background:#f8fafc;">
                                            <td style="padding:9px 12px;font-weight:600;color:#64748b;border-bottom:1px solid #e2e8f0;">Thiết bị / Model</td>
                                            <td style="padding:9px 12px;font-weight:700;color:#0f172a;border-bottom:1px solid #e2e8f0;"><?= htmlspecialchars($ps['tenThietBi'] ?? '—') ?></td>
                                        </tr>
                                        <tr>
                                            <td style="padding:9px 12px;font-weight:600;color:#64748b;border-bottom:1px solid #e2e8f0;vertical-align:top;">Mô tả lỗi / Yêu cầu</td>
                                            <td style="padding:9px 12px;color:#374151;border-bottom:1px solid #e2e8f0;line-height:1.6;"><?= nl2br(htmlspecialchars($ps['moTaLoi'] ?? '—')) ?></td>
                                        </tr>
                                        <tr style="background:#f8fafc;">
                                            <td style="padding:9px 12px;font-weight:600;color:#64748b;border-bottom:1px solid #e2e8f0;">Trạng thái</td>
                                            <td style="padding:9px 12px;border-bottom:1px solid #e2e8f0;"><strong><?= htmlspecialchars($ps['trangThai']) ?></strong></td>
                                        </tr>
                                        <?php if (isset($ps['chi_phi_goc']) && $ps['chi_phi_goc'] > 0 && $ps['chi_phi_goc'] != $ps['chiPhi']): ?>
                                        <tr>
                                            <td style="padding:9px 12px;font-weight:600;color:#64748b;border-bottom:1px solid #e2e8f0;">Chi phí gốc</td>
                                            <td style="padding:9px 12px;text-decoration:line-through;color:#94a3b8;border-bottom:1px solid #e2e8f0;"><?= number_format($ps['chi_phi_goc'],0,',','.') ?> đ</td>
                                        </tr>
                                        <?php endif; ?>
                                        <tr style="background:#fff7ed;">
                                            <td style="padding:11px 12px;font-weight:700;color:#92400e;">Chi phí thanh toán</td>
                                            <td style="padding:11px 12px;font-size:16px;font-weight:800;color:#d97706;"><?= $ps['chiPhi'] > 0 ? number_format($ps['chiPhi'],0,',','.').' đ' : 'Chưa xác định' ?></td>
                                        </tr>
                                    </table>

                                    <!-- Timeline in -->
                                    <div style="background:#f8fafc;border-radius:10px;padding:14px;margin-bottom:20px;">
                                        <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;margin-bottom:10px;">Tiến trình xử lý</div>
                                        <div style="display:flex;gap:0;align-items:center;">
                                            <?php foreach ($allSteps as $si => $step):
                                                $isPast    = $si < $stepIdx;
                                                $isCurrent = $si === $stepIdx;
                                            ?>
                                            <div style="flex:1;text-align:center;position:relative;">
                                                <?php if ($si < count($allSteps)-1): ?>
                                                <div style="position:absolute;top:10px;left:50%;right:-50%;height:2px;background:<?= ($isPast || $isCurrent) ? $stepColors[$si] : '#e2e8f0' ?>;z-index:0;"></div>
                                                <?php endif; ?>
                                                <div style="position:relative;z-index:1;width:20px;height:20px;border-radius:50%;background:<?= $isCurrent ? $stepColors[$si] : ($isPast ? '#10b981' : '#e2e8f0') ?>;margin:0 auto 4px;display:flex;align-items:center;justify-content:center;font-size:9px;color:#fff;">
                                                    <?= $isPast ? '✓' : ($isCurrent ? '●' : '') ?>
                                                </div>
                                                <div style="font-size:8px;color:<?= $isCurrent ? $stepColors[$si] : ($isPast ? '#10b981' : '#cbd5e1') ?>;font-weight:<?= $isCurrent ? '700' : '400' ?>;line-height:1.2;"><?= $step ?></div>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>

                                    <!-- Chữ ký -->
                                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:30px;margin-top:24px;">
                                        <div style="text-align:center;border:1px dashed #e2e8f0;border-radius:10px;padding:20px 10px 12px;">
                                            <div style="height:50px;"></div>
                                            <div style="height:1px;background:#e2e8f0;margin-bottom:6px;"></div>
                                            <div style="font-size:12px;font-weight:700;color:#374151;">Khách hàng xác nhận</div>
                                            <div style="font-size:11px;color:#94a3b8;margin-top:2px;"><?= htmlspecialchars($khach_hang['tenKH']) ?></div>
                                        </div>
                                        <div style="text-align:center;border:1px dashed #e2e8f0;border-radius:10px;padding:20px 10px 12px;">
                                            <div style="height:50px;"></div>
                                            <div style="height:1px;background:#e2e8f0;margin-bottom:6px;"></div>
                                            <div style="font-size:12px;font-weight:700;color:#374151;">Kỹ thuật viên</div>
                                            <div style="font-size:11px;color:#94a3b8;margin-top:2px;"><?= htmlspecialchars($admin_user) ?></div>
                                        </div>
                                    </div>
                                </div>
                                </div><!-- /modal-body -->

                                <div class="modal-footer" style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:12px 24px;">
                                    <button class="btn-outline-qa" data-bs-dismiss="modal">Đóng</button>
                                    <button class="btn-primary-qa" style="background:linear-gradient(135deg,#f59e0b,#d97706);border-color:transparent;" onclick="printPS_<?= $ps['maPhieu'] ?>()">
                                        <i class="fas fa-print me-1"></i> In phiếu
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <script>
                    function printPS_<?= $ps['maPhieu'] ?>() {
                        const el = document.getElementById('printPSArea_<?= $ps['maPhieu'] ?>');
                        if (!el) return;
                        const w = window.open('','_blank','width=900,height=750');
                        w.document.write('<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Phieu SC #SC-<?= str_pad($ps['maPhieu'],5,'0',STR_PAD_LEFT) ?></title>');
                        w.document.write('<link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;600;700;800&display=swap" rel="stylesheet">');
                        w.document.write('<style>*{box-sizing:border-box;}body{margin:0;padding:24px;font-family:"Be Vietnam Pro",Arial,sans-serif;font-size:13px;color:#1c1917;}@media print{@page{margin:12mm;size:A4;}body{padding:0;}}</style>');
                        w.document.write('</head><body>');
                        w.document.write(el.innerHTML);
                        w.document.write('<script>window.onload=function(){setTimeout(function(){window.print();},600);}<\/script>');
                        w.document.write('</body></html>');
                        w.document.close();
                    }
                    </script>

                    <!-- ══ MODAL UPLOAD ẢNH ══ -->
                    <div class="modal fade" id="uploadImgPS_<?= $ps['maPhieu'] ?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content" style="border:none;border-radius:16px;overflow:hidden;">
                                <div style="background:linear-gradient(135deg,#0f172a,#1e293b);padding:16px 22px;display:flex;align-items:center;justify-content:space-between;">
                                    <div style="font-weight:700;color:#fff;font-size:15px;">📷 Thêm ảnh – <span style="color:#38bdf8;">#SC-<?= str_pad($ps['maPhieu'],5,'0',STR_PAD_LEFT) ?></span></div>
                                    <button class="btn-close btn-close-white" data-bs-dismiss="modal" style="opacity:.6;"></button>
                                </div>
                                <form method="POST" enctype="multipart/form-data">
                                    <div class="modal-body" style="padding:20px 22px;">
                                        <input type="hidden" name="maPhieu" value="<?= $ps['maPhieu'] ?>">

                                        <!-- Loại ảnh pills -->
                                        <div class="mb-3">
                                            <label style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;display:block;margin-bottom:8px;">Phân loại ảnh</label>
                                            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                                <?php
                                                $uploadOpts = ['truoc'=>'📸 Trước sửa','sau'=>'✅ Sau sửa','loi'=>'⚠️ Lỗi/Hỏng','linh_kien'=>'🔩 Linh kiện'];
                                                foreach ($uploadOpts as $uVal => $uLabel):
                                                    $uid = 'ul_'.$ps['maPhieu'].'_'.$uVal;
                                                ?>
                                                <input type="radio" name="loaiAnh" id="<?= $uid ?>" value="<?= $uVal ?>" <?= $uVal==='truoc'?'checked':'' ?> style="display:none;" class="loai-anh-radio-up">
                                                <label for="<?= $uid ?>" style="cursor:pointer;padding:6px 13px;border-radius:20px;font-size:12px;font-weight:600;border:2px solid #e2e8f0;color:#64748b;background:#fff;transition:all .15s;">
                                                    <?= $uLabel ?>
                                                </label>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>

                                        <!-- Drop zone đơn -->
                                        <div class="img-dropzone mb-3" style="padding:18px;" id="upZone_<?= $ps['maPhieu'] ?>"
                                            ondragover="event.preventDefault();this.classList.add('dragover')"
                                            ondragleave="this.classList.remove('dragover')"
                                            ondrop="event.preventDefault();this.classList.remove('dragover');document.getElementById('upFile_<?= $ps['maPhieu'] ?>').files=event.dataTransfer.files;previewUpImg(event.dataTransfer.files[0],'upPrev_<?= $ps['maPhieu'] ?>')">
                                            <input type="file" name="fileAnh" id="upFile_<?= $ps['maPhieu'] ?>" accept="image/jpeg,image/png,image/webp" onchange="previewUpImg(this.files[0],'upPrev_<?= $ps['maPhieu'] ?>')">
                                            <div style="font-size:26px;margin-bottom:6px;">📂</div>
                                            <div style="font-size:13px;font-weight:700;color:#475569;">Kéo thả hoặc <span style="color:#f59e0b;">chọn ảnh</span></div>
                                            <div style="font-size:11px;color:#94a3b8;margin-top:3px;">JPG, PNG, WEBP · Tối đa 5MB</div>
                                        </div>
                                        <img id="upPrev_<?= $ps['maPhieu'] ?>" src="#" style="max-width:100%;border-radius:10px;display:none;margin-bottom:10px;border:2px solid #e2e8f0;">

                                        <div>
                                            <label class="form-label fw-semibold" style="font-size:13px;">Ghi chú ảnh</label>
                                            <input type="text" name="moTa" class="form-control" style="font-size:13px;" placeholder="VD: Màn hình vỡ góc trên phải...">
                                        </div>
                                    </div>
                                    <div class="modal-footer" style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:12px 22px;">
                                        <button class="btn-outline-qa" type="button" data-bs-dismiss="modal">Hủy</button>
                                        <button type="submit" name="upload_anh_sua" class="btn-primary-qa" style="background:linear-gradient(135deg,#0ea5e9,#0284c7);border-color:transparent;">
                                            <i class="fas fa-upload me-1"></i> Tải lên
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <?php
                        $modals_ps .= ob_get_clean();
                    endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-box"><i class="fas fa-tools"></i><p>Chưa có phiếu sửa chữa nào.<br><button class="btn-primary-qa mt-2" data-bs-toggle="modal" data-bs-target="#addPSModal"><i class="fas fa-plus"></i> Tạo phiếu đầu tiên</button></p></div>
            <?php endif; ?>
            <?= $modals_ps ?>

            <!-- Bảo hành -->
            <div class="sec-hdr mt-3">
                <div class="sec-title"><i class="fas fa-shield-alt" style="color:var(--c-info);"></i> Bảo hành sản phẩm</div>
                <button class="btn-primary-qa" data-bs-toggle="modal" data-bs-target="#addBHModal"><i class="fas fa-plus"></i> Thêm bảo hành</button>
            </div>
            <?php
            $modals_bh = '';
            if ($bao_hanh->num_rows > 0):
            ?>
            <div class="table-responsive">
                <table class="tbl" id="tblBaoHanh">
                    <thead><tr>
                        <th>Sản phẩm</th><th>Bắt đầu</th><th>Kết thúc</th><th>Thời hạn</th><th>Trạng thái</th><th class="text-center">Tác vụ</th>
                    </tr></thead>
                    <tbody>
                    <?php while ($bh = $bao_hanh->fetch_assoc()):
                        $today     = new DateTime();
                        $ngayKT    = new DateTime($bh['ngayKetThuc']);
                        $ngayBD    = new DateTime($bh['ngayBatDau']);
                        $totalDays = max(1, $ngayBD->diff($ngayKT)->days);
                        $usedDays  = min($totalDays, max(0, $ngayBD->diff($today)->days));
                        $pct       = round($usedDays / $totalDays * 100);
                        $bh_chip   = $bh['trangThai']=='Còn bảo hành' ? 'chip-success' : 'chip-danger';
                    ?>
                    <tr>
                        <td style="font-weight:600;"><?= htmlspecialchars($bh['tenSP']) ?></td>
                        <td><?= date('d/m/Y', strtotime($bh['ngayBatDau'])) ?></td>
                        <td><?= date('d/m/Y', strtotime($bh['ngayKetThuc'])) ?></td>
                        <td>
                            <div><?= htmlspecialchars($bh['thoiHan'] ?? '—') ?></div>
                            <div class="warranty-bar"><div class="warranty-fill" style="width:<?= $pct ?>%;<?= $pct>80?'background:linear-gradient(90deg,#f59e0b,#ef4444)':'' ?>"></div></div>
                            <small class="text-muted"><?= $pct ?>% thời hạn</small>
                        </td>
                        <td><span class="chip <?= $bh_chip ?>"><?= htmlspecialchars($bh['trangThai']) ?></span></td>
                        <td>
                            <div class="d-flex gap-1 justify-content-center">
                                <button class="btn-ico" data-bs-toggle="modal" data-bs-target="#detBH_<?= $bh['maBaoHanh'] ?>" title="Xem phiếu bảo hành"><i class="fas fa-eye"></i></button>
                                <button class="btn-ico" data-bs-toggle="modal" data-bs-target="#edtBH_<?= $bh['maBaoHanh'] ?>" title="Sửa"><i class="fas fa-edit"></i></button>
                                <button class="btn-ico" data-bs-toggle="modal" data-bs-target="#printBH_<?= $bh['maBaoHanh'] ?>" title="In phiếu bảo hành"><i class="fas fa-print"></i></button>
                                <button class="btn-ico" onclick="saveBHPDF(<?= $bh['maBaoHanh'] ?>)" title="Lưu phiếu bảo hành" style="color:var(--c-info);"><i class="fas fa-save"></i></button>
                                <button class="btn-ico del" onclick="confirmDel('chi_tiet_khach_hang.php?id=<?= $maKH ?>&xoa_bh=<?= $bh['maBaoHanh'] ?>&tab=ky-thuat','Xóa bảo hành này?')" title="Xóa"><i class="fas fa-trash"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php ob_start(); ?>
                    <!-- Modal XEM phiếu bảo hành -->
                    <div class="modal fade" id="detBH_<?= $bh['maBaoHanh'] ?>" tabindex="-1">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header" style="background:linear-gradient(135deg,#1e3a8a,#2563eb);color:white;">
                                    <h5 class="modal-title" style="color:white;"><i class="fas fa-shield-alt me-2"></i>Phiếu Bảo Hành #BH-<?= $bh['maBaoHanh'] ?></h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body" id="viewBHArea_<?= $bh['maBaoHanh'] ?>">
                                    <div class="text-center mb-4 pb-3" style="border-bottom:2px solid #e2e8f0;">
                                        <h3 style="color:#059669;margin:0;font-weight:800;">CÔNG TY TNHH TM &amp; PT CN QUANG ANH</h3>
                                        <p class="text-muted mb-0" style="font-size:12px;">CS1: 57 Nguyễn Bình, Hải Phòng &nbsp;|&nbsp; CS2: 81 Quán Nam, Hải Phòng</p>
                                        <p class="text-muted mb-2" style="font-size:12px;">CS Kỹ thuật: 59 Nguyễn Bình &nbsp;|&nbsp; ☎ 0982.459.566 &nbsp;|&nbsp; 📞 0934.322.199</p>
                                        <div style="display:inline-block;background:linear-gradient(135deg,#1e3a8a,#2563eb);color:white;padding:6px 28px;border-radius:20px;font-weight:700;font-size:15px;letter-spacing:1px;">PHIẾU BẢO HÀNH</div>
                                        <div style="font-size:12px;color:#64748b;margin-top:4px;">Mã phiếu: #BH-<?= $bh['maBaoHanh'] ?></div>
                                    </div>
                                    <div class="row g-3 mb-3">
                                        <div class="col-md-6">
                                            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px;">
                                                <div style="font-weight:700;color:#065f46;margin-bottom:8px;font-size:12px;border-bottom:1px solid #bbf7d0;padding-bottom:4px;">👤 THÔNG TIN KHÁCH HÀNG</div>
                                                <div style="font-size:13px;"><b>Họ tên:</b> <?= htmlspecialchars($khach_hang['tenKH']) ?></div>
                                                <div style="font-size:13px;"><b>SĐT:</b> <?= htmlspecialchars($khach_hang['soDienThoai'] ?? '—') ?></div>
                                                <div style="font-size:13px;"><b>Địa chỉ:</b> <?= htmlspecialchars($khach_hang['diaChi'] ?? '—') ?></div>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:14px;">
                                                <div style="font-weight:700;color:#1e40af;margin-bottom:8px;font-size:12px;border-bottom:1px solid #bfdbfe;padding-bottom:4px;">💻 THÔNG TIN SẢN PHẨM</div>
                                                <div style="font-size:13px;"><b>Sản phẩm:</b> <?= htmlspecialchars($bh['tenSP']) ?></div>
                                                <div style="font-size:13px;"><b>Thời hạn:</b> <span style="font-weight:700;color:#1e40af;"><?= htmlspecialchars($bh['thoiHan'] ?? '—') ?></span></div>
                                                <div style="font-size:13px;"><b>Ngày bắt đầu:</b> <?= date('d/m/Y', strtotime($bh['ngayBatDau'])) ?></div>
                                                <div style="font-size:13px;"><b>Ngày hết hạn:</b> <span style="font-weight:700;color:#dc2626;"><?= date('d/m/Y', strtotime($bh['ngayKetThuc'])) ?></span></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="text-center mb-3">
                                        <span style="background:<?= $bh['trangThai']=='Còn bảo hành'?'#d1fae5':'#fee2e2' ?>;color:<?= $bh['trangThai']=='Còn bảo hành'?'#065f46':'#991b1b' ?>;padding:5px 20px;border-radius:20px;font-weight:700;font-size:13px;border:1px solid <?= $bh['trangThai']=='Còn bảo hành'?'#6ee7b7':'#fca5a5' ?>;">
                                            <?= $bh['trangThai']=='Còn bảo hành'?'✅ CÒN BẢO HÀNH':'❌ HẾT HIỆU LỰC' ?>
                                        </span>
                                    </div>
                                    <?php if ($bh['dieuKienBaoHanh']): ?>
                                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px;">
                                        <div style="font-weight:700;color:#1e293b;margin-bottom:8px;font-size:13px;"><i class="fas fa-file-alt me-2 text-primary"></i>Điều kiện &amp; Chính sách bảo hành</div>
                                        <pre style="font-size:12px;line-height:1.6;margin:0;white-space:pre-wrap;font-family:inherit;color:#334155;"><?= htmlspecialchars($bh['dieuKienBaoHanh']) ?></pre>
                                    </div>
                                    <?php endif; ?>
                                    <div class="row mt-4 text-center">
                                        <div class="col-6"><div style="border-top:1px solid #e2e8f0;padding-top:10px;"><small class="text-muted">Khách hàng xác nhận<br>(Ký, ghi rõ họ tên)</small></div></div>
                                        <div class="col-6"><div style="border-top:1px solid #e2e8f0;padding-top:10px;"><small class="text-muted">Đại diện Quang Anh<br>(Ký, đóng dấu)</small></div></div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button class="btn-outline-qa" data-bs-dismiss="modal">Đóng</button>
                                    <button class="btn-outline-qa" onclick="saveBHPDF(<?= $bh['maBaoHanh'] ?>)" style="color:var(--c-info);border-color:var(--c-info);"><i class="fas fa-save"></i> Lưu phiếu</button>
                                    <button class="btn-primary-qa" data-bs-toggle="modal" data-bs-target="#printBH_<?= $bh['maBaoHanh'] ?>"><i class="fas fa-print"></i> In phiếu</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Modal SỬA bảo hành -->
                    <div class="modal fade" id="edtBH_<?= $bh['maBaoHanh'] ?>" tabindex="-1">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header" style="background:linear-gradient(135deg,#1e3a8a,#2563eb);color:white;">
                                    <h5 class="modal-title" style="color:white;"><i class="fas fa-edit me-2"></i>Cập nhật phiếu bảo hành #BH-<?= $bh['maBaoHanh'] ?></h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <form method="POST">
                                    <div class="modal-body">
                                        <input type="hidden" name="maBaoHanh" value="<?= $bh['maBaoHanh'] ?>">
                                        <div class="row g-3">
                                            <div class="col-md-12">
                                                <label class="form-label">Tên sản phẩm / Serial Number <span class="text-danger">*</span></label>
                                                <input type="text" name="tenSP" class="form-control" value="<?= htmlspecialchars($bh['tenSP']) ?>" required>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Ngày bắt đầu <span class="text-danger">*</span></label>
                                                <input type="date" name="ngayBatDau" class="form-control" value="<?= $bh['ngayBatDau'] ?>" required onchange="calcWarrantyEndEdit(<?= $bh['maBaoHanh'] ?>)">
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Thời hạn bảo hành</label>
                                                <select name="thoiHan" class="form-select" id="thoiHanSelectEdit_<?= $bh['maBaoHanh'] ?>" onchange="calcWarrantyEndEdit(<?= $bh['maBaoHanh'] ?>)">
                                                    <?php foreach (['3 tháng','6 tháng','12 tháng','24 tháng','36 tháng'] as $th): ?>
                                                    <option value="<?= $th ?>" <?= ($bh['thoiHan']==$th)?'selected':'' ?>><?= $th ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-4">
                                                <label class="form-label">Ngày kết thúc <span class="text-danger">*</span></label>
                                                <input type="date" name="ngayKetThuc" class="form-control" id="ngayKetThucEdit_<?= $bh['maBaoHanh'] ?>" value="<?= $bh['ngayKetThuc'] ?>" required>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label">Điều kiện bảo hành <span class="text-danger">*</span></label>
                                                <textarea name="dieuKienBaoHanh" class="form-control" rows="10" style="font-size:12px;line-height:1.5;" required><?= htmlspecialchars($bh['dieuKienBaoHanh'] ?? '') ?></textarea>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Tình trạng</label>
                                                <select name="trangThai" class="form-select">
                                                    <?php foreach (['Còn bảo hành','Hết hạn','Đang xử lý'] as $ts): ?>
                                                    <option value="<?= $ts ?>" <?= ($bh['trangThai']==$ts)?'selected':'' ?>><?= $ts ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button class="btn-outline-qa" type="button" data-bs-dismiss="modal">Hủy</button>
                                        <button type="submit" name="sua_bao_hanh" class="btn-primary-qa"><i class="fas fa-save"></i> Lưu cập nhật</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Modal IN phiếu bảo hành -->
                    <div class="modal fade" id="printBH_<?= $bh['maBaoHanh'] ?>" tabindex="-1">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header" style="background:linear-gradient(135deg,#1e3a8a,#2563eb);color:white;">
                                    <h5 class="modal-title" style="color:white;"><i class="fas fa-print me-2"></i>In Phiếu Bảo Hành #BH-<?= $bh['maBaoHanh'] ?></h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body" id="printBHArea_<?= $bh['maBaoHanh'] ?>">
                                    <div class="text-center mb-4 pb-3" style="border-bottom:3px double #1e3a8a;">
                                        <h2 style="color:#059669;margin:0;font-weight:800;font-size:18px;">CÔNG TY TNHH TM &amp; PT CN QUANG ANH</h2>
                                        <p class="text-muted mb-0" style="font-size:11px;">CS1: 57 Nguyễn Bình, Hải Phòng &nbsp;|&nbsp; CS2: 81 Quán Nam, Hải Phòng &nbsp;|&nbsp; CS KT: 59 Nguyễn Bình</p>
                                        <p class="text-muted mb-2" style="font-size:11px;">☎ 0982.459.566 &nbsp;|&nbsp; 📞 0934.322.199</p>
                                        <div style="display:inline-block;background:linear-gradient(135deg,#1e3a8a,#2563eb);color:white;padding:8px 36px;border-radius:4px;font-weight:800;font-size:17px;letter-spacing:2px;">PHIẾU BẢO HÀNH</div>
                                        <div style="font-size:11px;color:#64748b;margin-top:4px;">Số phiếu: BH-<?= $bh['maBaoHanh'] ?> &nbsp;|&nbsp; Ngày cấp: <?= date('d/m/Y') ?></div>
                                    </div>
                                    <table style="width:100%;border-collapse:collapse;margin-bottom:16px;font-size:12.5px;">
                                        <tr>
                                            <td style="width:50%;vertical-align:top;padding-right:12px;">
                                                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:12px;">
                                                    <div style="font-weight:700;color:#065f46;margin-bottom:6px;border-bottom:1px solid #bbf7d0;padding-bottom:4px;font-size:12px;">👤 THÔNG TIN KHÁCH HÀNG</div>
                                                    <div><b>Họ tên:</b> <?= htmlspecialchars($khach_hang['tenKH']) ?></div>
                                                    <div><b>SĐT:</b> <?= htmlspecialchars($khach_hang['soDienThoai'] ?? '—') ?></div>
                                                    <div><b>Địa chỉ:</b> <?= htmlspecialchars($khach_hang['diaChi'] ?? '—') ?></div>
                                                </div>
                                            </td>
                                            <td style="width:50%;vertical-align:top;">
                                                <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px;">
                                                    <div style="font-weight:700;color:#1e40af;margin-bottom:6px;border-bottom:1px solid #bfdbfe;padding-bottom:4px;font-size:12px;">💻 THÔNG TIN SẢN PHẨM</div>
                                                    <div><b>Sản phẩm:</b> <?= htmlspecialchars($bh['tenSP']) ?></div>
                                                    <div><b>Thời hạn:</b> <span style="font-weight:700;color:#1e40af;"><?= htmlspecialchars($bh['thoiHan'] ?? '—') ?></span></div>
                                                    <div><b>Ngày bắt đầu:</b> <?= date('d/m/Y', strtotime($bh['ngayBatDau'])) ?></div>
                                                    <div><b>Ngày hết hạn:</b> <span style="font-weight:700;color:#dc2626;"><?= date('d/m/Y', strtotime($bh['ngayKetThuc'])) ?></span></div>
                                                </div>
                                            </td>
                                        </tr>
                                    </table>
                                    <div style="text-align:center;margin-bottom:14px;">
                                        <span style="background:<?= $bh['trangThai']=='Còn bảo hành'?'#d1fae5':'#fee2e2' ?>;color:<?= $bh['trangThai']=='Còn bảo hành'?'#065f46':'#991b1b' ?>;padding:4px 18px;border-radius:20px;font-weight:700;font-size:13px;border:1px solid <?= $bh['trangThai']=='Còn bảo hành'?'#6ee7b7':'#fca5a5' ?>;">
                                            <?= $bh['trangThai']=='Còn bảo hành'?'✅ CÒN BẢO HÀNH':'❌ HẾT HIỆU LỰC' ?>
                                        </span>
                                    </div>
                                    <?php if ($bh['dieuKienBaoHanh']): ?>
                                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px;margin-bottom:14px;">
                                        <div style="font-weight:700;color:#1e293b;margin-bottom:8px;font-size:12px;border-bottom:1px solid #e2e8f0;padding-bottom:6px;">📋 ĐIỀU KIỆN &amp; CHÍNH SÁCH BẢO HÀNH</div>
                                        <pre style="font-size:11px;line-height:1.55;margin:0;white-space:pre-wrap;font-family:Arial,sans-serif;color:#334155;"><?= htmlspecialchars($bh['dieuKienBaoHanh']) ?></pre>
                                    </div>
                                    <?php endif; ?>
                                    <table style="width:100%;margin-top:20px;">
                                        <tr>
                                            <td style="width:50%;text-align:center;padding-top:12px;border-top:1px solid #e2e8f0;">
                                                <div style="font-weight:700;font-size:12px;">Khách hàng xác nhận</div>
                                                <div style="height:50px;"></div>
                                                <small style="color:#64748b;font-size:11px;">(Ký, ghi rõ họ tên)</small>
                                            </td>
                                            <td style="width:50%;text-align:center;padding-top:12px;border-top:1px solid #e2e8f0;">
                                                <div style="font-weight:700;font-size:12px;">Đại diện Quang Anh</div>
                                                <div style="height:50px;"></div>
                                                <small style="color:#64748b;font-size:11px;">(Ký, đóng dấu)</small>
                                            </td>
                                        </tr>
                                    </table>
                                </div>
                                <div class="modal-footer">
                                    <button class="btn-outline-qa" data-bs-dismiss="modal">Đóng</button>
                                    <button class="btn-outline-qa" onclick="saveBHPDF(<?= $bh['maBaoHanh'] ?>)" style="color:var(--c-info);border-color:var(--c-info);"><i class="fas fa-save"></i> Lưu phiếu</button>
                                    <button class="btn-primary-qa" onclick="printArea('printBHArea_<?= $bh['maBaoHanh'] ?>')"><i class="fas fa-print"></i> In phiếu</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php
                        $modals_bh .= ob_get_clean();
                    endwhile; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-box" style="padding:24px;"><i class="fas fa-shield-alt" style="font-size:28px;"></i><p>Chưa có thông tin bảo hành.</p></div>
            <?php endif; ?>
            <?= $modals_bh ?>

        </div><!-- /tab-ky-thuat -->

        <!-- ┌─ TAB: TÀI LIỆU ─┐ -->
        <div class="tab-pane <?= $activeTab=='tai-lieu'?'active':'' ?>" id="tab-tai-lieu">

            <!-- ══ THỐNG KÊ NHANH ══ -->
            <?php
            $don_hang->data_seek(0);
            $bao_hanh->data_seek(0);
            $bo_tai_lieu_res->data_seek(0);
            $total_dh  = $don_hang->num_rows;
            $total_bh  = $bao_hanh->num_rows;
            $total_tl  = $bo_tai_lieu_res->num_rows;
            $bh_con    = $conn->query("SELECT COUNT(*) as c FROM bao_hanh WHERE maKH=$maKH AND trangThai='Còn bảo hành'")->fetch_assoc()['c'] ?? 0;
            ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:20px;">
                <div style="background:linear-gradient(135deg,#fffbeb,#fef3c7);border:1.5px solid #fde68a;border-radius:14px;padding:14px 16px;display:flex;align-items:center;gap:12px;">
                    <div style="font-size:28px;">🧾</div>
                    <div><div style="font-size:22px;font-weight:800;color:#d97706;"><?= $total_dh ?></div><div style="font-size:11px;color:#92400e;font-weight:600;">Hóa đơn</div></div>
                </div>
                <div style="background:linear-gradient(135deg,#eff6ff,#dbeafe);border:1.5px solid #bfdbfe;border-radius:14px;padding:14px 16px;display:flex;align-items:center;gap:12px;">
                    <div style="font-size:28px;">🛡️</div>
                    <div><div style="font-size:22px;font-weight:800;color:#2563eb;"><?= $total_bh ?></div><div style="font-size:11px;color:#1d4ed8;font-weight:600;">Phiếu bảo hành</div></div>
                </div>
                <div style="background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:1.5px solid #86efac;border-radius:14px;padding:14px 16px;display:flex;align-items:center;gap:12px;">
                    <div style="font-size:28px;">✅</div>
                    <div><div style="font-size:22px;font-weight:800;color:#059669;"><?= $bh_con ?></div><div style="font-size:11px;color:#065f46;font-weight:600;">Còn hiệu lực</div></div>
                </div>
                <div style="background:linear-gradient(135deg,#fdf4ff,#ede9fe);border:1.5px solid #c4b5fd;border-radius:14px;padding:14px 16px;display:flex;align-items:center;gap:12px;">
                    <div style="font-size:28px;">📁</div>
                    <div><div style="font-size:22px;font-weight:800;color:#7c3aed;"><?= $total_tl ?></div><div style="font-size:11px;color:#5b21b6;font-weight:600;">Tài liệu khác</div></div>
                </div>
            </div>

            <!-- ══ PHẦN 1: HÓA ĐƠN TỰ ĐỘNG ══ -->
            <div class="sec-hdr">
                <div class="sec-title"><i class="fas fa-file-invoice" style="color:var(--c-warn);"></i> Hóa đơn mua hàng
                    <span style="background:#fef3c7;color:#92400e;font-size:11px;font-weight:700;padding:2px 8px;border-radius:20px;margin-left:6px;"><?= $total_dh ?> hóa đơn</span>
                </div>
                <button class="btn-primary-qa" data-bs-toggle="modal" data-bs-target="#addDHModal"><i class="fas fa-plus"></i> Tạo hóa đơn</button>
            </div>

            <?php
            $don_hang->data_seek(0);
            if ($don_hang->num_rows > 0):
            ?>
            <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:24px;">
            <?php while ($dh = $don_hang->fetch_assoc()):
                $chi_tiet_dh = $conn->query("SELECT * FROM chi_tiet_don_hang WHERE maDH={$dh['maDH']}");
                $sp_list = [];
                while ($sp = $chi_tiet_dh->fetch_assoc()) $sp_list[] = $sp;
                $is_paid  = $dh['tinhTrangThanhToan'] === 'Đã thanh toán';
                $is_done  = in_array($dh['trangThai'], ['Đã hoàn thành','Đã giao']);
                $is_cancel = $dh['trangThai'] === 'Đã hủy';
                $border_color = $is_cancel ? '#fca5a5' : ($is_done ? '#86efac' : '#fde68a');
                $badge_bg     = $is_cancel ? '#fee2e2' : ($is_done ? '#d1fae5' : '#fef3c7');
                $badge_color  = $is_cancel ? '#991b1b' : ($is_done ? '#065f46' : '#92400e');
            ?>
            <div style="background:#fff;border:1.5px solid <?= $border_color ?>;border-radius:14px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06);">
                <!-- Header hóa đơn -->
                <div style="background:linear-gradient(135deg,#f8fafc,#f1f5f9);padding:12px 16px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e2e8f0;flex-wrap:wrap;gap:8px;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div style="font-size:20px;">🧾</div>
                        <div>
                            <div style="font-weight:800;font-size:14px;color:#0f172a;">Hóa đơn #QA-<?= str_pad($dh['maDH'],5,'0',STR_PAD_LEFT) ?></div>
                            <div style="font-size:11px;color:#64748b;margin-top:1px;">
                                <i class="fas fa-calendar-alt me-1"></i><?= date('d/m/Y', strtotime($dh['ngayDat'])) ?>
                                &nbsp;·&nbsp; <i class="fas fa-store me-1"></i><?= htmlspecialchars($dh['kenhBanHang'] ?? 'Tại shop') ?>
                            </div>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <span style="background:<?= $badge_bg ?>;color:<?= $badge_color ?>;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;"><?= htmlspecialchars($dh['trangThai']) ?></span>
                        <span style="background:<?= $is_paid ? '#d1fae5' : '#fef9c3' ?>;color:<?= $is_paid ? '#065f46' : '#854d0e' ?>;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;"><?= htmlspecialchars($dh['tinhTrangThanhToan']) ?></span>
                        <button class="btn-ico" onclick="toggleHoaDon(<?= $dh['maDH'] ?>)" title="Xem chi tiết" id="toggleBtn_<?= $dh['maDH'] ?>"><i class="fas fa-chevron-down"></i></button>
                        <button class="btn-ico" onclick="inHoaDon(<?= $dh['maDH'] ?>)" title="In hóa đơn" style="color:var(--c-info);"><i class="fas fa-print"></i></button>
                    </div>
                </div>
                <!-- Tóm tắt -->
                <div style="padding:10px 16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px;">
                    <div style="font-size:12px;color:#475569;">
                        <?= count($sp_list) ?> sản phẩm
                        <?php if (!empty($sp_list)): ?>
                            &nbsp;·&nbsp; <?= htmlspecialchars(mb_strimwidth($sp_list[0]['tenSanPham'], 0, 40, '...')) ?>
                            <?php if (count($sp_list) > 1): ?> <span style="color:#94a3b8;">+<?= count($sp_list)-1 ?> khác</span><?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:17px;font-weight:800;color:#d97706;"><?= number_format($dh['tongTien'],0,',','.') ?> đ</div>
                </div>
                <!-- Chi tiết (ẩn/hiện) -->
                <div id="hdDetail_<?= $dh['maDH'] ?>" style="display:none;">
                    <div style="padding:0 16px 14px;">
                        <table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:10px;">
                            <thead>
                                <tr style="background:#f1f5f9;">
                                    <th style="padding:7px 10px;text-align:left;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0;">Sản phẩm / Dịch vụ</th>
                                    <th style="padding:7px 10px;text-align:center;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0;">SL</th>
                                    <th style="padding:7px 10px;text-align:right;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0;">Đơn giá</th>
                                    <th style="padding:7px 10px;text-align:right;color:#64748b;font-weight:600;border-bottom:1px solid #e2e8f0;">Thành tiền</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($sp_list as $idx => $sp): ?>
                            <tr style="<?= $idx % 2 == 1 ? 'background:#f8fafc;' : '' ?>border-bottom:1px solid #f1f5f9;">
                                <td style="padding:8px 10px;font-weight:600;color:#1e293b;"><?= htmlspecialchars($sp['tenSanPham']) ?></td>
                                <td style="padding:8px 10px;text-align:center;color:#475569;"><?= $sp['soLuong'] ?></td>
                                <td style="padding:8px 10px;text-align:right;color:#475569;"><?= number_format($sp['donGia'],0,',','.') ?>đ</td>
                                <td style="padding:8px 10px;text-align:right;font-weight:700;color:#0f172a;"><?= number_format($sp['thanhTien'],0,',','.') ?>đ</td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background:linear-gradient(135deg,#fffbeb,#fef3c7);">
                                    <td colspan="3" style="padding:10px 10px;text-align:right;font-weight:700;color:#92400e;font-size:13px;">TỔNG THANH TOÁN:</td>
                                    <td style="padding:10px 10px;text-align:right;font-weight:800;color:#d97706;font-size:16px;"><?= number_format($dh['tongTien'],0,',','.') ?> đ</td>
                                </tr>
                            </tfoot>
                        </table>
                        <?php if (!empty($dh['phuongThucThanhToan']) || !empty($dh['ghiChu'])): ?>
                        <div style="display:flex;gap:12px;flex-wrap:wrap;font-size:12px;color:#64748b;">
                            <?php if (!empty($dh['phuongThucThanhToan'])): ?>
                            <span><i class="fas fa-credit-card me-1"></i><?= htmlspecialchars($dh['phuongThucThanhToan']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($dh['diaChiGiaoHang'])): ?>
                            <span><i class="fas fa-map-marker-alt me-1"></i><?= htmlspecialchars($dh['diaChiGiaoHang']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($dh['ghiChu'])): ?>
                            <span><i class="fas fa-sticky-note me-1"></i><?= htmlspecialchars(mb_strimwidth($dh['ghiChu'],0,80,'...')) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <div style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap;">
                            <button class="btn-outline-qa" style="font-size:12px;padding:5px 12px;" onclick="inHoaDon(<?= $dh['maDH'] ?>)">
                                <i class="fas fa-print"></i> In hóa đơn
                            </button>
                        </div>
                    </div>
                    <!-- Vùng in (ẩn) -->
                    <div id="printHD_<?= $dh['maDH'] ?>" style="display:none;">
                        <div class="text-center mb-3 pb-2" style="border-bottom:3px double #d97706;">
                            <h2 style="color:#059669;margin:0;font-weight:800;font-size:17px;">CÔNG TY TNHH TM &amp; PT CN QUANG ANH</h2>
                            <p style="font-size:11px;color:#64748b;margin:4px 0 2px;">CS1: 57 Nguyễn Bình &nbsp;|&nbsp; CS2: 81 Quán Nam &nbsp;|&nbsp; ☎ 0982.459.566 &nbsp;|&nbsp; 📞 0934.322.199</p>
                            <div style="display:inline-block;background:linear-gradient(135deg,#d97706,#f59e0b);color:#fff;padding:6px 32px;border-radius:4px;font-weight:800;font-size:16px;letter-spacing:2px;margin-top:4px;">HÓA ĐƠN BÁN HÀNG</div>
                            <div style="font-size:11px;color:#64748b;margin-top:3px;">Số: #QA-<?= str_pad($dh['maDH'],5,'0',STR_PAD_LEFT) ?> &nbsp;|&nbsp; Ngày: <?= date('d/m/Y', strtotime($dh['ngayDat'])) ?> &nbsp;|&nbsp; Kênh: <?= htmlspecialchars($dh['kenhBanHang'] ?? 'Tại shop') ?></div>
                        </div>
                        <div style="display:flex;gap:12px;margin-bottom:14px;font-size:12px;">
                            <div style="flex:1;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px;">
                                <div style="font-weight:700;color:#065f46;margin-bottom:5px;font-size:11px;border-bottom:1px solid #bbf7d0;padding-bottom:3px;">👤 KHÁCH HÀNG</div>
                                <div><b>Họ tên:</b> <?= htmlspecialchars($khach_hang['tenKH']) ?></div>
                                <div><b>SĐT:</b> <?= htmlspecialchars($khach_hang['soDienThoai'] ?? '—') ?></div>
                                <?php if (!empty($dh['diaChiGiaoHang'])): ?><div><b>Địa chỉ giao:</b> <?= htmlspecialchars($dh['diaChiGiaoHang']) ?></div><?php endif; ?>
                            </div>
                            <div style="flex:1;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px;">
                                <div style="font-weight:700;color:#92400e;margin-bottom:5px;font-size:11px;border-bottom:1px solid #fde68a;padding-bottom:3px;">💳 THANH TOÁN</div>
                                <div><b>Phương thức:</b> <?= htmlspecialchars($dh['phuongThucThanhToan'] ?? '—') ?></div>
                                <div><b>Tình trạng:</b> <?= htmlspecialchars($dh['tinhTrangThanhToan']) ?></div>
                                <div><b>Trạng thái đơn:</b> <?= htmlspecialchars($dh['trangThai']) ?></div>
                            </div>
                        </div>
                        <table style="width:100%;border-collapse:collapse;font-size:12px;margin-bottom:14px;">
                            <thead>
                                <tr style="background:#1c1917;">
                                    <th style="padding:8px 10px;color:#fff;text-align:left;border-radius:6px 0 0 0;">Sản phẩm / Dịch vụ</th>
                                    <th style="padding:8px 10px;color:#fff;text-align:center;">SL</th>
                                    <th style="padding:8px 10px;color:#fff;text-align:right;">Đơn giá</th>
                                    <th style="padding:8px 10px;color:#fff;text-align:right;border-radius:0 6px 0 0;">Thành tiền</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($sp_list as $idx => $sp): ?>
                            <tr style="<?= $idx % 2 == 1 ? 'background:#f8fafc;' : '' ?>border-bottom:1px solid #e2e8f0;">
                                <td style="padding:9px 10px;font-weight:600;"><?= htmlspecialchars($sp['tenSanPham']) ?></td>
                                <td style="padding:9px 10px;text-align:center;"><?= $sp['soLuong'] ?></td>
                                <td style="padding:9px 10px;text-align:right;"><?= number_format($sp['donGia'],0,',','.') ?>đ</td>
                                <td style="padding:9px 10px;text-align:right;font-weight:700;"><?= number_format($sp['thanhTien'],0,',','.') ?>đ</td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background:#fff7ed;">
                                    <td colspan="3" style="padding:10px;text-align:right;font-weight:700;color:#92400e;font-size:13px;">TỔNG THANH TOÁN:</td>
                                    <td style="padding:10px;text-align:right;font-weight:800;color:#d97706;font-size:17px;"><?= number_format($dh['tongTien'],0,',','.') ?> đ</td>
                                </tr>
                            </tfoot>
                        </table>
                        <?php if (!empty($dh['ghiChu'])): ?>
                        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px;font-size:12px;color:#475569;margin-bottom:14px;">
                            <b>Ghi chú:</b> <?= htmlspecialchars($dh['ghiChu']) ?>
                        </div>
                        <?php endif; ?>
                        <table style="width:100%;margin-top:20px;">
                            <tr>
                                <td style="width:50%;text-align:center;padding-top:12px;border-top:1px solid #e2e8f0;">
                                    <div style="font-weight:700;font-size:12px;">Khách hàng xác nhận</div>
                                    <div style="height:50px;"></div>
                                    <small style="color:#64748b;font-size:11px;">(Ký, ghi rõ họ tên)</small>
                                </td>
                                <td style="width:50%;text-align:center;padding-top:12px;border-top:1px solid #e2e8f0;">
                                    <div style="font-weight:700;font-size:12px;">Nhân viên bán hàng</div>
                                    <div style="height:50px;"></div>
                                    <small style="color:#64748b;font-size:11px;">(Ký, đóng dấu)</small>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
            </div>
            <?php else: ?>
            <div class="empty-box" style="margin-bottom:20px;"><i class="fas fa-file-invoice"></i><p>Chưa có hóa đơn nào.<br><button class="btn-primary-qa mt-2" data-bs-toggle="modal" data-bs-target="#addDHModal"><i class="fas fa-plus"></i> Tạo hóa đơn đầu tiên</button></p></div>
            <?php endif; ?>

            <!-- ══ PHẦN 2: PHIẾU BẢO HÀNH TỰ ĐỘNG ══ -->
            <div class="sec-hdr">
                <div class="sec-title"><i class="fas fa-shield-alt" style="color:var(--c-info);"></i> Phiếu bảo hành
                    <span style="background:#dbeafe;color:#1d4ed8;font-size:11px;font-weight:700;padding:2px 8px;border-radius:20px;margin-left:6px;"><?= $total_bh ?> phiếu</span>
                </div>
                <button class="btn-primary-qa" data-bs-toggle="modal" data-bs-target="#addBHModal"><i class="fas fa-plus"></i> Thêm bảo hành</button>
            </div>

            <?php
            $bao_hanh->data_seek(0);
            if ($bao_hanh->num_rows > 0):
            ?>
            <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:24px;">
            <?php while ($bh = $bao_hanh->fetch_assoc()):
                $today_dt  = new DateTime();
                $ngayKT_dt = new DateTime($bh['ngayKetThuc']);
                $ngayBD_dt = new DateTime($bh['ngayBatDau']);
                $totalDays = max(1, $ngayBD_dt->diff($ngayKT_dt)->days);
                $usedDays  = min($totalDays, max(0, $ngayBD_dt->diff($today_dt)->days));
                $pctBH     = round($usedDays / $totalDays * 100);
                $daysLeft  = max(0, (int)$today_dt->diff($ngayKT_dt)->days * ($today_dt <= $ngayKT_dt ? 1 : -1));
                $isCon     = $bh['trangThai'] === 'Còn bảo hành' && $today_dt <= $ngayKT_dt;
                $bh_border = $isCon ? '#86efac' : '#fca5a5';
                $bh_bar_color = $pctBH > 80 ? 'linear-gradient(90deg,#f59e0b,#ef4444)' : 'linear-gradient(90deg,#10b981,#059669)';
            ?>
            <div style="background:#fff;border:1.5px solid <?= $bh_border ?>;border-radius:14px;overflow:hidden;box-shadow:0 1px 4px rgba(0,0,0,.06);">
                <div style="background:linear-gradient(135deg,<?= $isCon ? '#f0fdf4,#d1fae5' : '#fff1f2,#fee2e2' ?>);padding:12px 16px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid <?= $bh_border ?>;flex-wrap:wrap;gap:8px;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <div style="font-size:22px;"><?= $isCon ? '🛡️' : '🔓' ?></div>
                        <div>
                            <div style="font-weight:800;font-size:14px;color:#0f172a;">Phiếu #BH-<?= str_pad($bh['maBaoHanh'],5,'0',STR_PAD_LEFT) ?> — <?= htmlspecialchars($bh['tenSP']) ?></div>
                            <div style="font-size:11px;color:#64748b;margin-top:1px;">
                                <i class="fas fa-calendar me-1"></i><?= date('d/m/Y', strtotime($bh['ngayBatDau'])) ?> → <?= date('d/m/Y', strtotime($bh['ngayKetThuc'])) ?>
                                &nbsp;·&nbsp; <i class="fas fa-clock me-1"></i><?= htmlspecialchars($bh['thoiHan'] ?? '—') ?>
                            </div>
                        </div>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <span style="background:<?= $isCon ? '#d1fae5' : '#fee2e2' ?>;color:<?= $isCon ? '#065f46' : '#991b1b' ?>;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;">
                            <?= $isCon ? '✅ Còn bảo hành' : '❌ Hết hiệu lực' ?>
                        </span>
                        <?php if ($isCon && $daysLeft > 0): ?>
                        <span style="background:#eff6ff;color:#1d4ed8;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;">⏳ Còn <?= $daysLeft ?> ngày</span>
                        <?php endif; ?>
                        <button class="btn-ico" onclick="toggleBaoHanh(<?= $bh['maBaoHanh'] ?>)" title="Xem chi tiết"><i class="fas fa-chevron-down"></i></button>
                        <button class="btn-ico" onclick="saveBHPDF(<?= $bh['maBaoHanh'] ?>)" title="In / Lưu PDF" style="color:var(--c-info);"><i class="fas fa-print"></i></button>
                    </div>
                </div>
                <!-- Thanh tiến độ bảo hành -->
                <div style="padding:10px 16px;">
                    <div style="display:flex;justify-content:space-between;font-size:11px;color:#64748b;margin-bottom:4px;">
                        <span>Đã sử dụng <?= $pctBH ?>% thời hạn bảo hành</span>
                        <span style="font-weight:700;color:<?= $pctBH > 80 ? '#ef4444' : '#10b981' ?>;"><?= $usedDays ?>/<?= $totalDays ?> ngày</span>
                    </div>
                    <div style="background:#e2e8f0;border-radius:20px;height:7px;overflow:hidden;">
                        <div style="width:<?= min(100,$pctBH) ?>%;height:100%;border-radius:20px;background:<?= $bh_bar_color ?>;transition:width .5s;"></div>
                    </div>
                </div>
                <!-- Chi tiết (ẩn/hiện) -->
                <div id="bhDetail_<?= $bh['maBaoHanh'] ?>" style="display:none;padding:0 16px 14px;">
                    <?php if (!empty($bh['dieuKienBaoHanh'])): ?>
                    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px;margin-bottom:10px;">
                        <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.07em;margin-bottom:6px;"><i class="fas fa-file-alt me-1"></i> Điều kiện bảo hành</div>
                        <pre style="font-size:12px;line-height:1.6;margin:0;white-space:pre-wrap;font-family:inherit;color:#334155;"><?= htmlspecialchars($bh['dieuKienBaoHanh']) ?></pre>
                    </div>
                    <?php endif; ?>
                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                        <button class="btn-outline-qa" style="font-size:12px;padding:5px 12px;" onclick="saveBHPDF(<?= $bh['maBaoHanh'] ?>)">
                            <i class="fas fa-print"></i> In / Lưu phiếu bảo hành
                        </button>
                        <button class="btn-outline-qa" style="font-size:12px;padding:5px 12px;" data-bs-toggle="modal" data-bs-target="#edtBH_<?= $bh['maBaoHanh'] ?>">
                            <i class="fas fa-edit"></i> Chỉnh sửa
                        </button>
                    </div>
                </div>
            </div>
            <?php endwhile; ?>
            </div>
            <?php else: ?>
            <div class="empty-box" style="margin-bottom:20px;"><i class="fas fa-shield-alt"></i><p>Chưa có phiếu bảo hành nào.<br><button class="btn-primary-qa mt-2" data-bs-toggle="modal" data-bs-target="#addBHModal"><i class="fas fa-plus"></i> Thêm bảo hành đầu tiên</button></p></div>
            <?php endif; ?>

            <!-- ══ PHẦN 3: TÀI LIỆU UPLOAD THỦ CÔNG ══ -->
            <div class="sec-hdr">
                <div class="sec-title"><i class="fas fa-folder-open" style="color:var(--c-purple);"></i> Tài liệu khác
                    <span style="background:#ede9fe;color:#5b21b6;font-size:11px;font-weight:700;padding:2px 8px;border-radius:20px;margin-left:6px;"><?= $total_tl ?> file</span>
                </div>
                <button class="btn-primary-qa" data-bs-toggle="modal" data-bs-target="#addTLModal"><i class="fas fa-upload"></i> Tải lên tài liệu</button>
            </div>
            <?php
            $bo_tai_lieu_res->data_seek(0);
            if ($bo_tai_lieu_res->num_rows > 0):
            ?>
            <div class="doc-grid">
            <?php while ($bo = $bo_tai_lieu_res->fetch_assoc()):
                $files_res = $conn->query("SELECT * FROM chi_tiet_tai_lieu WHERE maBo={$bo['maBo']}");
                $icon = match(true) {
                    str_contains(strtolower($bo['loaiTaiLieu']), 'hợp đồng') => '📋',
                    str_contains(strtolower($bo['loaiTaiLieu']), 'hóa đơn')  => '🧾',
                    str_contains(strtolower($bo['loaiTaiLieu']), 'ảnh')      => '🖼️',
                    str_contains(strtolower($bo['loaiTaiLieu']), 'bảo hành') => '🛡️',
                    default => '📁'
                };
            ?>
            <div class="doc-card">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="doc-icon"><?= $icon ?></div>
                    <button class="btn-ico del" onclick="confirmDel('chi_tiet_khach_hang.php?id=<?= $maKH ?>&xoa_bo_tl=<?= $bo['maBo'] ?>&tab=tai-lieu','Xóa bộ tài liệu này?')" title="Xóa"><i class="fas fa-trash"></i></button>
                </div>
                <div class="doc-name"><?= htmlspecialchars($bo['tenTaiLieu']) ?></div>
                <div class="doc-type"><?= htmlspecialchars($bo['loaiTaiLieu']) ?></div>
                <small class="text-muted"><?= date('d/m/Y H:i', strtotime($bo['ngayTaiLen'])) ?></small>
                <div class="doc-files">
                <?php while ($f = $files_res->fetch_assoc()):
                    $ext  = strtolower(pathinfo($f['duongDan'], PATHINFO_EXTENSION));
                    $fico = in_array($ext, ['jpg','jpeg','png']) ? '🖼️' : (in_array($ext, ['pdf']) ? '📄' : '📎');
                ?>
                    <a href="../<?= htmlspecialchars($f['duongDan']) ?>" target="_blank" class="doc-file-link"><?= $fico ?> <?= strtoupper($ext) ?></a>
                <?php endwhile; ?>
                </div>
            </div>
            <?php endwhile; ?>
            </div>
            <?php else: ?>
            <div class="empty-box"><i class="fas fa-folder-open"></i><p>Chưa có tài liệu đính kèm nào.<br><button class="btn-primary-qa mt-2" data-bs-toggle="modal" data-bs-target="#addTLModal"><i class="fas fa-upload"></i> Tải lên tài liệu đầu tiên</button></p></div>
            <?php endif; ?>

        </div><!-- /tab-tai-lieu -->

<script>
/* ── Toggle ẩn/hiện chi tiết hóa đơn ── */
function toggleHoaDon(id) {
    const el  = document.getElementById('hdDetail_' + id);
    const btn = document.getElementById('toggleBtn_' + id);
    const ico = btn ? btn.querySelector('i') : null;
    if (!el) return;
    const isOpen = el.style.display !== 'none';
    el.style.display = isOpen ? 'none' : 'block';
    if (ico) {
        ico.className = isOpen ? 'fas fa-chevron-down' : 'fas fa-chevron-up';
    }
}

/* ── Toggle ẩn/hiện chi tiết bảo hành ── */
function toggleBaoHanh(id) {
    const el = document.getElementById('bhDetail_' + id);
    if (!el) return;
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

/* ── In hóa đơn ── */
function inHoaDon(id) {
    const el = document.getElementById('printHD_' + id);
    if (!el) return;
    const w = window.open('', '_blank', 'width=900,height=700');
    w.document.write('<html><head><meta charset="UTF-8"><title>Hoa don #QA-' + String(id).padStart(5,'0') + '</title>');
    w.document.write('<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">');
    w.document.write('<style>body{font-family:Arial,sans-serif;padding:30px;font-size:13px;}@page{margin:12mm;size:A4;}</style>');
    w.document.write('</head><body>');
    w.document.write(el.innerHTML);
    w.document.write('<div style="margin-top:20px;text-align:center;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;padding-top:10px;">Để lưu PDF: Nhấn Ctrl+P → Chọn "Save as PDF"</div>');
    w.document.write('<script>window.onload=()=>setTimeout(()=>{window.print();},500);<\/script>');
    w.document.write('</body></html>');
    w.document.close();
}
</script>

        <!-- ┌─ TAB: VOUCHER ─┐ -->
        <div class="tab-pane <?= $activeTab=='voucher'?'active':'' ?>" id="tab-voucher">
            <div class="sec-hdr">
                <div class="sec-title">
                    <?php if ($khach_hang['loaiKhachHang'] === 'Khách hàng VIP'): ?>
                        <i class="fas fa-crown" style="color:#7c3aed;"></i> Voucher – Khách hàng VIP
                    <?php elseif ($khach_hang['loaiKhachHang'] === 'Khách hàng thân thiết'): ?>
                        <i class="fas fa-medal" style="color:#d97706;"></i> Voucher – Khách hàng thân thiết
                    <?php else: ?>
                        <i class="fas fa-ticket-alt text-muted"></i> Voucher ưu đãi
                    <?php endif; ?>
                </div>
                <button class="btn-primary-qa" data-bs-toggle="modal" data-bs-target="#capVoucherModal"><i class="fas fa-plus"></i> Cấp voucher thủ công</button>
            </div>

            <?php if ($khach_hang['loaiKhachHang'] === 'Khách truy cập'): ?>
            <!-- Tiến trình lên hạng Thân thiết -->
            <?php
                $pct_don = min(100, round($tong_don / 5 * 100));
                $pct_sc  = min(100, round($tong_sc  / 5 * 100));
            ?>
            <div style="background:linear-gradient(135deg,#f8fafc,#f0fdf4);border:2px dashed #86efac;border-radius:14px;padding:20px;margin-bottom:16px;">
                <div style="font-weight:700;font-size:14px;color:#065f46;margin-bottom:14px;"><i class="fas fa-medal me-2" style="color:#d97706;"></i>Tiến trình lên hạng <strong>Thân thiết</strong> (cần ≥5 đơn hàng VÀ ≥5 lần sửa chữa)</div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div style="font-size:13px;margin-bottom:4px;">🛒 Đơn hàng: <strong><?= $tong_don ?>/5</strong></div>
                        <div style="background:#e2e8f0;border-radius:20px;height:10px;overflow:hidden;">
                            <div style="width:<?= $pct_don ?>%;background:linear-gradient(90deg,#10b981,#059669);height:100%;border-radius:20px;transition:width .5s;"></div>
                        </div>
                        <small class="text-muted"><?= max(0,5-$tong_don) > 0 ? 'Cần thêm ' . max(0,5-$tong_don) . ' đơn' : '✅ Đã đủ điều kiện' ?></small>
                    </div>
                    <div class="col-md-6">
                        <div style="font-size:13px;margin-bottom:4px;">🔧 Lần sửa chữa: <strong><?= $tong_sc ?>/5</strong></div>
                        <div style="background:#e2e8f0;border-radius:20px;height:10px;overflow:hidden;">
                            <div style="width:<?= $pct_sc ?>%;background:linear-gradient(90deg,#f59e0b,#d97706);height:100%;border-radius:20px;transition:width .5s;"></div>
                        </div>
                        <small class="text-muted"><?= max(0,5-$tong_sc) > 0 ? 'Cần thêm ' . max(0,5-$tong_sc) . ' lần sửa' : '✅ Đã đủ điều kiện' ?></small>
                    </div>
                </div>
                <div style="margin-top:12px;font-size:12px;color:#64748b;background:#fff;border-radius:8px;padding:10px;">
                    🎁 <strong>Phần thưởng khi lên Thân thiết:</strong> 1 voucher giảm 50% lần sửa chữa tiếp theo + 1 voucher giảm 1.000.000đ lần mua laptop tiếp theo
                </div>
            </div>
            <div class="empty-box">
                <i class="fas fa-gift"></i>
                <p>Chưa có voucher nào được cấp.<br>
                <small class="text-muted">Hoàn thành điều kiện ở trên để nhận voucher tự động.</small></p>
            </div>
            <?php else: ?>

            <!-- Thống kê voucher -->
            <?php
            $vouchers_res->data_seek(0);
            $v_sc_con  = 0; $v_mh_con  = 0; $v_bd_con = 0; $v_custom_con = 0;
            $v_sc_dung = 0; $v_mh_dung = 0; $v_bd_dung = 0; $v_custom_dung = 0;
            $all_v = [];
            while ($v = $vouchers_res->fetch_assoc()) {
                $all_v[] = $v;
                if ($v['loaiVoucher'] === 'sua_chua_50pct')  { $v['trangThai'] === 'Chưa dùng' ? $v_sc_con++ : $v_sc_dung++; }
                if ($v['loaiVoucher'] === 'mua_hang_1trieu') { $v['trangThai'] === 'Chưa dùng' ? $v_mh_con++ : $v_mh_dung++; }
                if (in_array($v['loaiVoucher'], ['sinh_nhat_10pct','sinh_nhat_sc'])) { $v['trangThai'] === 'Chưa dùng' ? $v_bd_con++ : $v_bd_dung++; }
                if (in_array($v['loaiVoucher'], ['tu_soan','tu_soan_sc','tu_soan_mh'])) { $v['trangThai'] === 'Chưa dùng' ? $v_custom_con++ : $v_custom_dung++; }
            }
            ?>
            <div class="row g-3 mb-4">
                <div class="col-sm-6 col-lg-3">
                    <div style="background:#fef3c7;border-radius:12px;padding:16px;text-align:center;">
                        <div style="font-size:28px;">🔧</div>
                        <div style="font-size:22px;font-weight:800;color:#d97706;"><?= $v_sc_con ?></div>
                        <div style="font-size:12px;color:#92400e;">Voucher SC 50% còn lại</div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div style="background:#dbeafe;border-radius:12px;padding:16px;text-align:center;">
                        <div style="font-size:28px;">💻</div>
                        <div style="font-size:22px;font-weight:800;color:#2563eb;"><?= $v_mh_con ?></div>
                        <div style="font-size:12px;color:#1d4ed8;">Voucher mua hàng -1tr còn lại</div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div style="background:#fce7f3;border-radius:12px;padding:16px;text-align:center;">
                        <div style="font-size:28px;">🎂</div>
                        <div style="font-size:22px;font-weight:800;color:#be185d;"><?= $v_bd_con ?></div>
                        <div style="font-size:12px;color:#9d174d;">Voucher sinh nhật còn lại</div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3" style="<?= ($v_custom_con + $v_custom_dung) == 0 ? 'display:none;' : '' ?>">
                    <div style="background:#ecfeff;border-radius:12px;padding:16px;text-align:center;">
                        <div style="font-size:28px;">✏️</div>
                        <div style="font-size:22px;font-weight:800;color:#0e7490;"><?= $v_custom_con ?></div>
                        <div style="font-size:12px;color:#155e75;">Voucher tùy chỉnh còn lại</div>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-3">
                    <div style="background:#f0fdf4;border-radius:12px;padding:16px;text-align:center;">
                        <div style="font-size:28px;">✅</div>
                        <div style="font-size:22px;font-weight:800;color:#059669;"><?= $v_sc_dung + $v_mh_dung + $v_bd_dung ?></div>
                        <div style="font-size:12px;color:#065f46;">Voucher đã sử dụng</div>
                    </div>
                </div>
            </div>

            <!-- Tiến trình lên VIP (chỉ hiện khi đang là Thân thiết) -->
            <?php if ($khach_hang['loaiKhachHang'] === 'Khách hàng thân thiết'): ?>
            <div style="background:linear-gradient(135deg,#fdf4ff,#ede9fe);border:2px solid #c4b5fd;border-radius:14px;padding:18px;margin-bottom:16px;">
                <div style="font-weight:700;font-size:14px;color:#5b21b6;margin-bottom:14px;"><i class="fas fa-crown me-2"></i>Tiến trình lên hạng <strong>VIP</strong> (cần ≥10 đơn hàng VÀ ≥10 lần sửa chữa)</div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <div style="font-size:13px;margin-bottom:4px;">🛒 Đơn hàng: <strong><?= $tong_don ?>/10</strong></div>
                        <div style="background:#e2e8f0;border-radius:20px;height:10px;overflow:hidden;">
                            <div style="width:<?= $pct_don_vip ?>%;background:linear-gradient(90deg,#8b5cf6,#7c3aed);height:100%;border-radius:20px;"></div>
                        </div>
                        <small class="text-muted"><?= max(0,10-$tong_don) > 0 ? 'Cần thêm ' . max(0,10-$tong_don) . ' đơn' : '✅ Đã đủ điều kiện' ?></small>
                    </div>
                    <div class="col-md-6">
                        <div style="font-size:13px;margin-bottom:4px;">🔧 Lần sửa chữa: <strong><?= $tong_sc ?>/10</strong></div>
                        <div style="background:#e2e8f0;border-radius:20px;height:10px;overflow:hidden;">
                            <div style="width:<?= $pct_sc_vip ?>%;background:linear-gradient(90deg,#8b5cf6,#7c3aed);height:100%;border-radius:20px;"></div>
                        </div>
                        <small class="text-muted"><?= max(0,10-$tong_sc) > 0 ? 'Cần thêm ' . max(0,10-$tong_sc) . ' lần sửa' : '✅ Đã đủ điều kiện' ?></small>
                    </div>
                </div>
                <div style="margin-top:12px;font-size:12px;color:#6d28d9;background:#fff;border-radius:8px;padding:10px;">
                    👑 <strong>Phần thưởng khi lên VIP:</strong> 3 voucher giảm 50% chi phí sửa chữa + 3 voucher giảm 1.000.000đ mua laptop
                </div>
            </div>
            <?php endif; ?>

            <!-- Danh sách voucher -->
            <?php if (empty($all_v)): ?>
            <div class="empty-box"><i class="fas fa-ticket-alt"></i><p>Chưa có voucher nào được cấp.</p></div>
            <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:10px;">
            <?php foreach ($all_v as $v):
                $isDone   = $v['trangThai'] === 'Đã dùng';
                $isSC     = $v['loaiVoucher'] === 'sua_chua_50pct';
                $isBD     = in_array($v['loaiVoucher'], ['sinh_nhat_10pct', 'sinh_nhat_sc']);
                $isCustom = in_array($v['loaiVoucher'], ['tu_soan', 'tu_soan_sc', 'tu_soan_mh']);
                $vcCode   = 'VC-' . str_pad($v['maVoucher'], 5, '0', STR_PAD_LEFT);
                $hsd      = $v['ngayHetHan'] ? date('d/m/Y', strtotime($v['ngayHetHan'])) : 'Không giới hạn';
                $isExpired = $v['ngayHetHan'] && $v['ngayHetHan'] < date('Y-m-d');
                // Icon & màu cho từng loại
                if ($isBD)         { $vcIcon = '🎂'; $vcColor = '#be185d'; $vcBorder = '#f9a8d4'; $vcBg = 'linear-gradient(135deg,#fff,#fdf2f8)'; $icoBg = 'linear-gradient(135deg,#ec4899,#be185d)'; $icoClass = ''; }
                elseif ($isCustom) { $vcIcon = '✏️'; $vcColor = '#0e7490'; $vcBorder = '#67e8f9'; $vcBg = 'linear-gradient(135deg,#fff,#ecfeff)'; $icoBg = 'linear-gradient(135deg,#22d3ee,#0891b2)'; $icoClass = ''; }
                elseif ($isSC)     { $vcIcon = '🔧'; $vcColor = '#d97706'; $vcBorder = ''; $vcBg = ''; $icoBg = ''; $icoClass = 'sc'; }
                else               { $vcIcon = '💻'; $vcColor = '#2563eb'; $vcBorder = ''; $vcBg = ''; $icoBg = ''; $icoClass = 'mh'; }
            ?>
            <div class="voucher-card <?= $isDone ? 'used' : '' ?>" style="<?= ($isBD||$isCustom) ? "border-color:$vcBorder;background:$vcBg;" : '' ?>">
                <div class="voucher-icon <?= $icoClass ?>" style="<?= ($isBD||$isCustom) ? "background:$icoBg;" : '' ?>">
                    <?= $vcIcon ?>
                </div>
                <div style="flex:1;">
                    <div style="font-weight:700;font-size:14px;margin-bottom:3px;">
                        <?= htmlspecialchars($v['moTa']) ?>
                        <?php if ($isBD): ?>
                        <span style="font-size:11px;background:#fce7f3;color:#be185d;border:1px solid #f9a8d4;border-radius:6px;padding:1px 7px;margin-left:6px;font-weight:700;">🎂 Sinh nhật</span>
                        <?php elseif ($isCustom): ?>
                        <span style="font-size:11px;background:#cffafe;color:#0e7490;border:1px solid #67e8f9;border-radius:6px;padding:1px 7px;margin-left:6px;font-weight:700;">✏️ Tùy chỉnh</span>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                        <span class="voucher-code"><?= $vcCode ?></span>
                        <span style="font-size:12px;color:var(--c-muted);">HSD: <?= $hsd ?><?= $isExpired ? ' <span style="color:#dc2626;font-weight:600;">(Đã hết hạn)</span>' : '' ?></span>
                        <?php if ($isDone): ?>
                        <span class="chip chip-gray" style="font-size:11px;">✅ Đã dùng</span>
                        <?php elseif ($isExpired): ?>
                        <span class="chip chip-danger" style="font-size:11px;">⛔ Hết hạn</span>
                        <?php else: ?>
                        <span class="chip chip-success" style="font-size:11px;">🟢 Còn dùng được</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="font-size:20px;font-weight:800;color:<?= $vcColor ?>;white-space:nowrap;">
                    <?= htmlspecialchars($v['giaTriGiam']) ?>
                </div>
                <!-- Nhóm nút hành động -->
                <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
                    <?php if (!$isDone): ?>
                    <form method="POST" style="margin:0;">
                        <input type="hidden" name="maVoucher" value="<?= $v['maVoucher'] ?>">
                        <button type="submit" name="danh_dau_voucher_da_dung"
                            class="btn btn-sm"
                            style="background:#f1f5f9;border:1px solid var(--c-border);color:var(--c-muted);border-radius:8px;padding:5px 12px;font-size:12px;white-space:nowrap;"
                            onclick="return confirm('Đánh dấu voucher này là đã sử dụng?')">
                            Dùng voucher
                        </button>
                    </form>
                    <?php endif; ?>
                    <!-- Nút Xem -->
                    <button type="button" class="btn btn-sm"
                        style="background:#eff6ff;border:1px solid #bfdbfe;color:#2563eb;border-radius:8px;padding:5px 10px;font-size:12px;white-space:nowrap;"
                        onclick="xemVoucher(<?= htmlspecialchars(json_encode($v)) ?>)">
                        <i class="fas fa-eye"></i> Xem
                    </button>
                    <!-- Nút Sửa -->
                    <button type="button" class="btn btn-sm"
                        style="background:#f0fdf4;border:1px solid #86efac;color:#059669;border-radius:8px;padding:5px 10px;font-size:12px;white-space:nowrap;"
                        data-bs-toggle="modal" data-bs-target="#suaVoucherModal_<?= $v['maVoucher'] ?>">
                        <i class="fas fa-edit"></i> Sửa
                    </button>
                    <!-- Nút Xóa -->
                    <button type="button" class="btn btn-sm"
                        style="background:#fff1f2;border:1px solid #fca5a5;color:#dc2626;border-radius:8px;padding:5px 10px;font-size:12px;white-space:nowrap;"
                        onclick="xacNhanXoaVoucher('<?= $vcCode ?>', 'chi_tiet_khach_hang.php?id=<?= $maKH ?>&tab=voucher&xoa_voucher=<?= $v['maVoucher'] ?>')">
                        <i class="fas fa-trash"></i> Xóa
                    </button>
                </div>
            </div>

            <!-- Modal Sửa Voucher -->
            <div class="modal fade" id="suaVoucherModal_<?= $v['maVoucher'] ?>" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="fas fa-edit text-success me-2"></i>Sửa Voucher – <?= $vcCode ?></h5>
                            <button class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="maVoucher" value="<?= $v['maVoucher'] ?>">
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Loại voucher</label>
                                    <input type="text" class="form-control" value="<?= $isBD ? 'Sinh nhật' : ($isCustom ? 'Tùy chỉnh – ' . htmlspecialchars($v['giaTriGiam']) : ($isSC ? 'Sửa chữa 50%' : 'Mua hàng -1.000.000đ')) ?>" disabled style="background:#f8fafc;color:var(--c-muted);">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Giá trị giảm</label>
                                    <input type="text" name="giaTriGiam" class="form-control" value="<?= htmlspecialchars($v['giaTriGiam']) ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Mô tả</label>
                                    <textarea name="moTa" class="form-control" rows="3"><?= htmlspecialchars($v['moTa']) ?></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Hạn sử dụng</label>
                                    <input type="date" name="ngayHetHan" class="form-control" value="<?= $v['ngayHetHan'] ?? '' ?>">
                                    <small class="text-muted">Để trống = không giới hạn</small>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label fw-semibold">Trạng thái</label>
                                    <select name="trangThai" class="form-select">
                                        <option value="Chưa dùng" <?= $v['trangThai'] === 'Chưa dùng' ? 'selected' : '' ?>>🟢 Chưa dùng</option>
                                        <option value="Đã dùng" <?= $v['trangThai'] === 'Đã dùng' ? 'selected' : '' ?>>✅ Đã dùng</option>
                                    </select>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Hủy</button>
                                <button type="submit" name="sua_voucher" class="btn btn-success btn-sm"><i class="fas fa-save me-1"></i>Lưu thay đổi</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div><!-- /tab-voucher -->

    </div><!-- /tabs-section -->
</div><!-- /content-area -->
</div><!-- /main-wrap -->

<!-- ══════════════════════════════════════════════════════
     MODALS THÊM MỚI
═══════════════════════════════════════════════════════ -->

<!-- Modal Xem Chi Tiết Voucher -->
<div class="modal fade" id="xemVoucherModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" id="xemVoucherHeader">
                <h5 class="modal-title"><i class="fas fa-ticket-alt me-2"></i><span id="xemVoucherTitle">Chi tiết voucher</span></h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="xemVoucherBody" style="display:flex;flex-direction:column;gap:14px;">
                    <!-- Filled by JS -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Đóng</button>
            </div>
        </div>
    </div>
</div>

<script>
function xacNhanXoaVoucher(maVC, url) {
    Swal.fire({
        title: 'Xóa voucher ' + maVC + '?',
        text: 'Voucher sẽ bị xóa vĩnh viễn và không thể khôi phục.',
        icon: 'warning',
        iconColor: '#ef4444',
        showCancelButton: true,
        confirmButtonText: '<i class="fas fa-trash me-1"></i> Xóa ngay',
        cancelButtonText: 'Hủy bỏ',
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#64748b',
        reverseButtons: true,
        customClass: {
            popup: 'swal-popup-qa',
            title: 'swal-title-qa',
        }
    }).then(result => {
        if (result.isConfirmed) window.location.href = url;
    });
}

function xemVoucher(v) {
    const loai = v.loaiVoucher;
    const isSC      = loai === 'sua_chua_50pct' || loai === 'sinh_nhat_sc' || loai === 'tu_soan_sc';
    const isMH      = loai === 'mua_hang_1trieu' || loai === 'tu_soan_mh';
    const isBD      = loai === 'sinh_nhat_10pct' || loai === 'sinh_nhat_sc';
    const isCustom  = loai === 'tu_soan' || loai === 'tu_soan_sc' || loai === 'tu_soan_mh';
    const isDone    = v.trangThai === 'Đã dùng';
    const code      = 'VC-' + String(v.maVoucher).padStart(5, '0');
    const hsd       = v.ngayHetHan
        ? new Date(v.ngayHetHan).toLocaleDateString('vi-VN')
        : 'Không giới hạn';
    const ngayTao   = v.ngayTao
        ? new Date(v.ngayTao).toLocaleString('vi-VN')
        : '—';

    // Xác định icon, màu, mô tả loại
    let vcIcon, vcColor, vcDesc, loaiText;
    if (isBD) {
        vcIcon = '🎂'; vcColor = '#be185d';
        vcDesc = 'Voucher sinh nhật';
        loaiText = loai === 'sinh_nhat_10pct' ? 'Sinh nhật – Giảm 10%' : 'Sinh nhật – Sửa chữa 50%';
    } else if (isCustom) {
        vcIcon = '✏️'; vcColor = '#0e7490';
        vcDesc = 'Voucher tùy chỉnh';
        const apDung = loai === 'tu_soan_sc' ? 'Sửa chữa' : (loai === 'tu_soan_mh' ? 'Mua hàng' : 'Tất cả dịch vụ');
        loaiText = 'Tùy chỉnh – ' + apDung;
    } else if (isSC) {
        vcIcon = '🔧'; vcColor = '#d97706';
        vcDesc = 'Giảm 50% chi phí sửa chữa';
        loaiText = 'Sửa chữa 50%';
    } else {
        vcIcon = '💻'; vcColor = '#2563eb';
        vcDesc = 'Giảm 1.000.000đ mua laptop';
        loaiText = 'Mua hàng -1.000.000đ';
    }

    const headerBg  = isBD ? '#fce7f3' : (isCustom ? '#ecfeff' : (isSC ? '#fef3c7' : '#dbeafe'));
    const headerBdr = isBD ? '#f9a8d4' : (isCustom ? '#67e8f9' : (isSC ? '#fde68a' : '#bfdbfe'));

    document.getElementById('xemVoucherTitle').textContent = code;

    const headerEl = document.getElementById('xemVoucherHeader');
    headerEl.style.background = headerBg;
    headerEl.style.borderBottom = '1px solid ' + headerBdr;

    document.getElementById('xemVoucherBody').innerHTML = `
        <div style="text-align:center;padding:16px 0 8px;">
            <div style="font-size:48px;">${vcIcon}</div>
            <div style="font-size:26px;font-weight:800;color:${vcColor};margin-top:6px;">${v.giaTriGiam}</div>
            <div style="font-size:13px;color:#64748b;margin-top:4px;">${vcDesc}</div>
        </div>
        <hr style="margin:4px 0;">
        <table style="width:100%;font-size:13px;border-collapse:collapse;">
            <tr><td style="padding:7px 4px;color:#64748b;width:130px;"><i class="fas fa-hashtag me-1"></i>Mã voucher</td><td style="padding:7px 4px;font-weight:600;">${code}</td></tr>
            <tr style="background:#f8fafc;"><td style="padding:7px 4px;color:#64748b;"><i class="fas fa-tag me-1"></i>Loại</td><td style="padding:7px 4px;">${loaiText}</td></tr>
            <tr><td style="padding:7px 4px;color:#64748b;"><i class="fas fa-align-left me-1"></i>Mô tả</td><td style="padding:7px 4px;">${v.moTa || '—'}</td></tr>
            <tr style="background:#f8fafc;"><td style="padding:7px 4px;color:#64748b;"><i class="fas fa-calendar-alt me-1"></i>Ngày tạo</td><td style="padding:7px 4px;">${ngayTao}</td></tr>
            <tr><td style="padding:7px 4px;color:#64748b;"><i class="fas fa-hourglass-end me-1"></i>Hạn sử dụng</td><td style="padding:7px 4px;">${hsd}</td></tr>
            <tr style="background:#f8fafc;"><td style="padding:7px 4px;color:#64748b;"><i class="fas fa-info-circle me-1"></i>Trạng thái</td>
                <td style="padding:7px 4px;">${isDone
                    ? '<span style="background:#f1f5f9;border:1px solid #cbd5e1;border-radius:6px;padding:2px 10px;font-size:12px;">✅ Đã dùng</span>'
                    : '<span style="background:#d1fae5;border:1px solid #6ee7b7;border-radius:6px;padding:2px 10px;font-size:12px;color:#065f46;">🟢 Còn dùng được</span>'
                }</td>
            </tr>
        </table>
    `;

    new bootstrap.Modal(document.getElementById('xemVoucherModal')).show();
}
</script>

<div class="modal fade" id="editKHModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-edit text-success me-2"></i>Chỉnh sửa hồ sơ khách hàng</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Họ và tên</label>
                            <input type="text" name="tenKH" class="form-control" value="<?= htmlspecialchars($khach_hang['tenKH']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Số điện thoại</label>
                            <input type="text" name="soDienThoai" class="form-control" value="<?= htmlspecialchars($khach_hang['soDienThoai'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($khach_hang['email'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Địa chỉ</label>
                            <input type="text" name="diaChi" class="form-control" value="<?= htmlspecialchars($khach_hang['diaChi'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phân loại khách hàng</label>
                            <select name="loaiKhachHang" class="form-select">
                                <?php foreach (['Khách truy cập','Khách mua lẻ','Khách sỉ','Khách hàng thân thiết','Khách hàng VIP','Đối tác','Đại lý'] as $lkh): ?>
                                <option <?= ($khach_hang['loaiKhachHang']==$lkh)?'selected':'' ?>><?= $lkh ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text text-warning"><i class="fas fa-info-circle"></i> Hạng Thân thiết/VIP tự động cập nhật theo số đơn & sửa chữa.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Trạng thái tài khoản</label>
                            <select name="trangThai" class="form-select">
                                <option value="1" <?= $khach_hang['trangThai']?'selected':'' ?>>✔ Đang hoạt động</option>
                                <option value="0" <?= !$khach_hang['trangThai']?'selected':'' ?>>✘ Tạm khóa</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Ngày đăng ký</label>
                            <input type="date" name="ngayDangKy" class="form-control" value="<?= htmlspecialchars($khach_hang['ngayDangKy']) ?>" max="<?= date('Y-m-d') ?>" required>
                            <div class="form-text">Không được chọn ngày trong tương lai.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><i class="fas fa-birthday-cake text-danger me-1"></i>Ngày sinh</label>
                            <input type="date" name="ngaySinh" class="form-control" value="<?= htmlspecialchars($khach_hang['ngaySinh'] ?? '') ?>" max="<?= date('Y-m-d') ?>">
                            <div class="form-text">Dùng để nhắc sinh nhật & gửi ưu đãi.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-outline-qa" type="button" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" name="sua_thong_tin_kh" class="btn-primary-qa"><i class="fas fa-save"></i> Lưu thay đổi</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══ CSS cho Modal Tạo Đơn Hàng ═══ -->
<style>
#addDHModal .modal-content {
    border-radius: 18px; overflow: hidden; border: none;
    box-shadow: 0 24px 64px rgba(0,0,0,.22);
}
#addDHModal .dh-header {
    background: linear-gradient(135deg, #064e3b 0%, #065f46 55%, #047857 100%);
    padding: 20px 26px 16px; position: relative; overflow: hidden;
}
#addDHModal .dh-header::before {
    content: ''; position: absolute; inset: 0;
    background: radial-gradient(ellipse at 80% 40%, rgba(16,185,129,.22) 0%, transparent 65%);
    pointer-events: none;
}
#addDHModal .dh-badge {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 11px; padding: 3px 10px; border-radius: 20px;
    background: rgba(255,255,255,.11); color: rgba(255,255,255,.82);
    border: 1px solid rgba(255,255,255,.18);
}
#addDHModal .dh-badge.golden {
    background: rgba(251,191,36,.22); border-color: rgba(251,191,36,.4); color: #fef3c7;
}
#addDHModal .dh-body-grid {
    display: grid;
    grid-template-columns: 1fr 340px;
}
@media (max-width: 820px) {
    #addDHModal .dh-body-grid { grid-template-columns: 1fr; }
    #addDHModal .dh-right { border-left: none !important; border-top: 1px solid #e2e8f0; }
}
#addDHModal .dh-left {
    padding: 22px 20px 22px 26px;
    overflow-y: auto;
    max-height: calc(82vh - 140px);
}
#addDHModal .dh-right {
    padding: 22px 24px 22px 18px;
    background: #f8fafc;
    border-left: 1px solid #e2e8f0;
    overflow-y: auto;
    max-height: calc(82vh - 140px);
}
#addDHModal .sec-label-dh {
    font-size: 10.5px; font-weight: 700; letter-spacing: .08em;
    text-transform: uppercase; color: #94a3b8;
    margin-bottom: 10px; display: flex; align-items: center; gap: 6px;
}
#addDHModal .sec-label-dh::after {
    content: ''; flex: 1; height: 1px; background: #e2e8f0; margin-left: 4px;
}
#addDHModal .prod-hdr,
#addDHModal .prod-row {
    display: grid;
    grid-template-columns: 88px 1fr 58px 118px 108px 30px;
    gap: 6px; align-items: center; margin-bottom: 6px;
}
/* Voucher cards */
.vc-card {
    display: flex; align-items: center; gap: 10px;
    padding: 11px 12px; background: #fff;
    border: 2px solid #e2e8f0; border-radius: 11px;
    cursor: pointer; transition: border-color .16s, background .16s, box-shadow .16s;
    margin-bottom: 7px; user-select: none; position: relative;
}
.vc-card:hover { border-color: #10b981; background: #f0fdf4; box-shadow: 0 2px 10px rgba(16,185,129,.13); }
.vc-card.selected { border-color: #10b981; background: #ecfdf5; box-shadow: 0 2px 14px rgba(16,185,129,.22); }
.vc-card.no-voucher:hover { border-color: #94a3b8; background: #f8fafc; box-shadow: none; }
.vc-card.no-voucher.selected { border-color: #64748b; background: #f1f5f9; }
.vc-card.vc-disabled { opacity: .42; pointer-events: none; filter: grayscale(.35); }
.vc-lock-badge {
    display: none; position: absolute; top: 6px; right: 38px;
    font-size: 9.5px; background: #fef3c7; color: #92400e;
    border: 1px solid #fbbf24; border-radius: 4px;
    padding: 1px 6px; font-weight: 700;
}
.vc-card.vc-need-laptop .vc-lock-badge { display: inline-flex; align-items: center; gap: 3px; }
.vc-radio-dot {
    width: 18px; height: 18px; border-radius: 50%;
    border: 2px solid #e2e8f0; background: #fff; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    transition: background .16s, border-color .16s;
}
.vc-card.selected .vc-radio-dot { border-color: #10b981; background: #10b981; }
.vc-card.no-voucher.selected .vc-radio-dot { border-color: #64748b; background: #64748b; }
.vc-saving-badge {
    font-size: 12px; font-weight: 800; color: #dc2626;
    white-space: nowrap; opacity: .3; transition: opacity .2s;
}
.vc-saving-badge.active { opacity: 1; }
/* Total box */
.dh-total-box {
    background: #fff; border: 2px solid #e2e8f0;
    border-radius: 13px; overflow: hidden; margin-top: 14px;
}
.dh-total-row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 9px 15px; font-size: 13px; border-bottom: 1px solid #f1f5f9;
}
.dh-total-row:last-child { border-bottom: none; }
.dh-total-final {
    background: linear-gradient(135deg, #ecfdf5, #d1fae5);
    padding: 13px 15px;
    display: flex; justify-content: space-between; align-items: center;
}
.laptop-toggle-label {
    display: inline-flex; align-items: center; gap: 8px; cursor: pointer;
    background: #eff6ff; border: 1.5px solid #bfdbfe; border-radius: 9px;
    padding: 8px 13px; font-size: 12px; font-weight: 600; color: #1d4ed8;
    transition: background .15s, border-color .15s; user-select: none;
}
</style>

<!-- ═══════ Modal Tạo Đơn Hàng ═══════ -->
<div class="modal fade" id="addDHModal" tabindex="-1" aria-labelledby="addDHModalLabel">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable" style="max-width:1000px;">
        <div class="modal-content">

            <!-- HEADER -->
            <div class="dh-header">
                <div style="position:relative;z-index:1;">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;">
                        <div style="display:flex;align-items:center;gap:14px;">
                            <div style="width:46px;height:46px;background:rgba(255,255,255,.14);border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:22px;border:1px solid rgba(255,255,255,.22);box-shadow:0 4px 12px rgba(0,0,0,.15);">🛒</div>
                            <div>
                                <div id="addDHModalLabel" style="font-size:18px;font-weight:800;color:#fff;letter-spacing:-.01em;">Tạo đơn hàng mới</div>
                                <div style="font-size:12px;color:rgba(255,255,255,.6);margin-top:2px;">
                                    Khách hàng: <strong style="color:rgba(255,255,255,.92);"><?= htmlspecialchars($khach_hang['tenKH']) ?></strong>
                                </div>
                            </div>
                        </div>
                        <button class="btn-close btn-close-white" data-bs-dismiss="modal" style="opacity:.65;"></button>
                    </div>
                    <div style="display:flex;gap:7px;flex-wrap:wrap;margin-top:11px;">
                        <span class="dh-badge"><i class="fas fa-calendar-day me-1"></i><?= date('d/m/Y') ?></span>
                        <span class="dh-badge"><i class="fas fa-user-circle me-1"></i><?= htmlspecialchars($admin_user) ?></span>
                        <?php if ($so_voucher_con > 0): ?>
                        <span class="dh-badge golden"><i class="fas fa-ticket-alt me-1"></i><?= $so_voucher_con ?> voucher có thể dùng</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- FORM -->
            <form method="POST" id="formTaoDH" novalidate>
                <div class="dh-body-grid">

                    <!-- CỘT TRÁI -->
                    <div class="dh-left">

                        <div class="sec-label-dh"><i class="fas fa-clipboard-list"></i> Thông tin đơn hàng</div>
                        <div class="row g-2 mb-3">
                            <div class="col-6 col-md-3">
                                <label class="form-label" style="font-size:12px;font-weight:600;">Ngày đặt <span class="text-danger">*</span></label>
                                <input type="date" name="ngayDat" class="form-control form-control-sm"
                                    value="<?= date('Y-m-d') ?>" required max="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label" style="font-size:12px;font-weight:600;">Kênh bán</label>
                                <select name="kenhBanHang" class="form-select form-select-sm">
                                    <?php foreach (['Tại shop','Website','Zalo','Facebook','Điện thoại','Đại lý'] as $k): ?>
                                    <option><?= $k ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label" style="font-size:12px;font-weight:600;">Thanh toán</label>
                                <select name="phuongThucThanhToan" class="form-select form-select-sm">
                                    <?php foreach (['Tiền mặt','Chuyển khoản','Thẻ ngân hàng','Ví điện tử','Công nợ'] as $p): ?>
                                    <option><?= $p ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-3">
                                <label class="form-label" style="font-size:12px;font-weight:600;">Trạng thái TT</label>
                                <select name="tinhTrangThanhToan" class="form-select form-select-sm">
                                    <option>Chưa thanh toán</option>
                                    <option>Đã thanh toán</option>
                                    <option>Thanh toán một phần</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" style="font-size:12px;font-weight:600;">Trạng thái đơn</label>
                                <select name="trangThai" class="form-select form-select-sm">
                                    <?php foreach (['Chờ duyệt','Đang xử lý','Đang giao','Đã hoàn thành','Đã hủy'] as $ts): ?>
                                    <option><?= $ts ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label" style="font-size:12px;font-weight:600;">Địa chỉ giao hàng</label>
                                <input type="text" name="diaChiGiaoHang" class="form-control form-control-sm"
                                    value="<?= htmlspecialchars($khach_hang['diaChi'] ?? '') ?>"
                                    placeholder="Nhập địa chỉ giao hàng...">
                            </div>
                            <div class="col-12">
                                <label class="form-label" style="font-size:12px;font-weight:600;">Ghi chú</label>
                                <textarea name="ghiChu" class="form-control form-control-sm" rows="2"
                                    placeholder="Yêu cầu đặc biệt, ghi chú nội bộ..."></textarea>
                            </div>
                            <div class="col-12">
                                <label class="laptop-toggle-label">
                                    <input type="checkbox" name="co_laptop" value="1" id="chkCoLaptop"
                                        style="accent-color:#2563eb;" onchange="dhOnLaptopToggle()">
                                    💻 Đơn hàng có Laptop
                                    <span style="font-weight:400;color:#3b82f6;font-size:11px;">(bật để dùng được voucher Laptop)</span>
                                </label>
                            </div>
                        </div>

                        <div class="sec-label-dh"><i class="fas fa-boxes"></i> Sản phẩm / Dịch vụ</div>

                        <div class="prod-hdr" style="margin-bottom:3px;">
                            <div style="font-size:10.5px;font-weight:700;color:#94a3b8;padding-left:4px;">Mã SP</div>
                            <div style="font-size:10.5px;font-weight:700;color:#94a3b8;">Tên sản phẩm / Dịch vụ</div>
                            <div style="font-size:10.5px;font-weight:700;color:#94a3b8;text-align:center;">SL</div>
                            <div style="font-size:10.5px;font-weight:700;color:#94a3b8;text-align:right;">Đơn giá (đ)</div>
                            <div style="font-size:10.5px;font-weight:700;color:#94a3b8;text-align:right;">Thành tiền</div>
                            <div></div>
                        </div>

                        <div id="productRows">
                            <div class="prod-row product-row">
                                <input type="text" name="maSP[]" class="form-control form-control-sm" placeholder="Mã SP">
                                <input type="text" name="tenSP[]" class="form-control form-control-sm"
                                    placeholder="Tên sản phẩm / dịch vụ..." required
                                    oninput="dhAutoDetectLaptop(this)">
                                <input type="number" name="soLuong[]" class="form-control form-control-sm text-center"
                                    value="1" min="1" oninput="calcRow(this)">
                                <input type="number" name="donGia[]" class="form-control form-control-sm text-end"
                                    placeholder="0" min="0" step="1000" oninput="calcRow(this)">
                                <input type="text" class="form-control form-control-sm text-end thanh-tien"
                                    placeholder="0 đ" readonly
                                    style="background:#f8fafc;color:#059669;font-weight:700;font-size:12px;">
                                <button type="button" class="btn-ico del"
                                    onclick="this.closest('.product-row').remove();updateTotal()"
                                    style="width:28px;height:28px;font-size:11px;" title="Xóa dòng">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>

                        <button type="button" onclick="addProductRow()"
                            style="display:flex;align-items:center;gap:6px;margin-top:9px;padding:8px 14px;background:#f0fdf4;border:1.5px dashed #86efac;border-radius:9px;color:#059669;font-size:12px;font-weight:600;cursor:pointer;width:100%;justify-content:center;transition:background .15s;"
                            onmouseover="this.style.background='#dcfce7'" onmouseout="this.style.background='#f0fdf4'">
                            <i class="fas fa-plus"></i> Thêm dòng sản phẩm
                        </button>

                    </div><!-- /dh-left -->

                    <!-- CỘT PHẢI: Voucher + Tổng kết -->
                    <div class="dh-right">

                        <div class="sec-label-dh"><i class="fas fa-ticket-alt"></i> Voucher khuyến mãi</div>

                        <?php if (empty($v_dh_all)): ?>
                        <div style="text-align:center;padding:22px 14px;background:#fff;border:2px dashed #e2e8f0;border-radius:13px;">
                            <div style="font-size:32px;margin-bottom:8px;opacity:.35;">🎫</div>
                            <div style="font-size:12px;color:#94a3b8;line-height:1.6;">
                                Khách chưa có voucher nào có thể dùng cho đơn hàng.<br>
                                <span style="color:#d97706;">Voucher tự động cấp khi đủ điều kiện hạng.</span>
                            </div>
                        </div>
                        <input type="hidden" name="ap_voucher_mua_hang" value="0">

                        <?php else: ?>

                        <div style="font-size:11px;color:#374151;margin-bottom:10px;display:flex;align-items:flex-start;gap:7px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:9px;padding:9px 11px;line-height:1.5;">
                            <i class="fas fa-lightbulb" style="color:#3b82f6;flex-shrink:0;margin-top:1px;"></i>
                            <span>Nhập sản phẩm trước để xem số tiền tiết kiệm theo thời gian thực. Voucher <strong>💻 Laptop</strong> cần tích ô <strong>Đơn có Laptop</strong> bên trái.</span>
                        </div>

                        <!-- Không dùng voucher -->
                        <div id="vcOpt_none" class="vc-card no-voucher selected" onclick="selectVoucherDH(0)" role="radio" aria-checked="true">
                            <input type="radio" name="ap_voucher_mua_hang" value="0" checked style="display:none;">
                            <div style="width:34px;height:34px;background:#f1f5f9;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;">🚫</div>
                            <div style="flex:1;">
                                <div style="font-size:12.5px;font-weight:700;color:#374151;">Không dùng voucher</div>
                                <div style="font-size:11px;color:#94a3b8;margin-top:1px;">Thanh toán theo giá gốc</div>
                            </div>
                            <div class="vc-radio-dot" id="vcCheck_none">
                                <svg width="9" height="7" viewBox="0 0 9 7"><path d="M1 3.5L3 5.5L8 1" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>
                            </div>
                        </div>

                        <!-- Danh sách voucher -->
                        <?php foreach ($v_dh_all as $vdh):
                            $vcId     = $vdh['maVoucher'];
                            $vcCode   = 'VC-' . str_pad($vcId, 5, '0', STR_PAD_LEFT);
                            $loaiV    = $vdh['loaiVoucher'];
                            $loaiGiam = $vdh['loai_giam'] ?? 'vnd';
                            $giaTriSo = (double)($vdh['gia_tri_so'] ?? 0);
                            $toiDa    = (double)($vdh['so_tien_toi_da'] ?? 0);
                            $vcHan    = $vdh['ngayHetHan'] ? date('d/m/Y', strtotime($vdh['ngayHetHan'])) : 'Không giới hạn';
                            $daysLeft = $vdh['ngayHetHan'] ? (int)ceil((strtotime($vdh['ngayHetHan']) - time()) / 86400) : null;
                            $urgentHan = $daysLeft !== null && $daysLeft <= 7;
                            $isLaptopOnly = ($loaiV === 'mua_hang_1trieu' || $loaiV === 'tu_soan_mh');

                            if ($loaiV === 'mua_hang_1trieu') {
                                $vcIcon = '💻'; $vcColor = '#2563eb'; $vcBg = '#eff6ff'; $vcBorder = '#bfdbfe'; $tagText = 'Laptop';
                                $discDesc = 'Giảm thẳng <strong>1.000.000đ</strong>';
                                $condText = '⚠️ Chỉ áp cho đơn có Laptop';
                            } elseif ($loaiV === 'sinh_nhat_10pct') {
                                $vcIcon = '🎂'; $vcColor = '#be185d'; $vcBg = '#fdf2f8'; $vcBorder = '#fbcfe8'; $tagText = 'Sinh nhật';
                                $giamDesc = '10%' . ($toiDa > 0 ? ' (tối đa ' . number_format($toiDa,0,',','.') . 'đ)' : '');
                                $discDesc = 'Giảm <strong>' . $giamDesc . '</strong>';
                                $condText = '🎂 Ưu đãi sinh nhật – áp mọi đơn';
                            } elseif ($loaiV === 'tu_soan_mh') {
                                $vcIcon = '✏️'; $vcColor = '#0e7490'; $vcBg = '#ecfeff'; $vcBorder = '#a5f3fc'; $tagText = 'Tùy chỉnh';
                                $discDesc = $loaiGiam === 'pct'
                                    ? 'Giảm <strong>' . (int)$giaTriSo . '%</strong>' . ($toiDa > 0 ? ' (tối đa ' . number_format($toiDa,0,',','.') . 'đ)' : '')
                                    : 'Giảm <strong>' . number_format($giaTriSo,0,',','.') . 'đ</strong>';
                                $condText = '⚠️ Chỉ áp cho đơn có Laptop';
                            } else {
                                $vcIcon = '✏️'; $vcColor = '#0e7490'; $vcBg = '#ecfeff'; $vcBorder = '#a5f3fc'; $tagText = 'Tùy chỉnh';
                                $discDesc = $loaiGiam === 'pct'
                                    ? 'Giảm <strong>' . (int)$giaTriSo . '%</strong>' . ($toiDa > 0 ? ' (tối đa ' . number_format($toiDa,0,',','.') . 'đ)' : '')
                                    : 'Giảm <strong>' . number_format($giaTriSo,0,',','.') . 'đ</strong>';
                                $condText = 'Áp dụng mọi đơn hàng';
                            }
                        ?>
                        <div id="vcOpt_<?= $vcId ?>"
                            class="vc-card<?= $isLaptopOnly ? ' vc-need-laptop vc-disabled' : '' ?>"
                            onclick="selectVoucherDH(<?= $vcId ?>)"
                            data-laptop-only="<?= $isLaptopOnly ? '1' : '0' ?>"
                            role="radio" aria-checked="false">

                            <input type="radio" name="ap_voucher_mua_hang" value="<?= $vcId ?>" style="display:none;">
                            <span class="vc-lock-badge"><i class="fas fa-lock"></i> Cần laptop</span>

                            <div style="width:38px;height:38px;background:<?= $vcBg ?>;border:1.5px solid <?= $vcBorder ?>;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;">
                                <?= $vcIcon ?>
                            </div>

                            <div style="flex:1;min-width:0;">
                                <div style="display:flex;align-items:center;gap:5px;margin-bottom:3px;flex-wrap:wrap;">
                                    <span style="font-size:10px;background:<?= $vcBg ?>;color:<?= $vcColor ?>;border:1px solid <?= $vcBorder ?>;border-radius:4px;padding:1px 7px;font-weight:700;white-space:nowrap;"><?= $tagText ?></span>
                                    <span style="font-size:10px;color:#94a3b8;font-family:monospace;letter-spacing:.5px;"><?= $vcCode ?></span>
                                </div>
                                <div style="font-size:12.5px;color:#0f172a;line-height:1.4;"><?= $discDesc ?></div>
                                <div style="font-size:10.5px;color:#64748b;margin-top:2px;"><?= $condText ?></div>
                                <div style="font-size:10px;margin-top:2px;">
                                    <?php if ($urgentHan): ?>
                                    <span style="color:#dc2626;font-weight:700;"><i class="fas fa-exclamation-triangle"></i> HSD: <?= $vcHan ?> — còn <?= $daysLeft ?> ngày!</span>
                                    <?php else: ?>
                                    <span style="color:#94a3b8;">HSD: <?= $vcHan ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0;">
                                <span id="vcSaving_<?= $vcId ?>" class="vc-saving-badge">–0đ</span>
                                <div class="vc-radio-dot" id="vcCheck_<?= $vcId ?>">
                                    <svg width="9" height="7" viewBox="0 0 9 7"><path d="M1 3.5L3 5.5L8 1" stroke="white" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>

                        <!-- TỔNG KẾT -->
                        <div class="sec-label-dh" style="margin-top:18px;"><i class="fas fa-calculator"></i> Tổng kết thanh toán</div>
                        <div class="dh-total-box">
                            <div class="dh-total-row">
                                <span style="color:#64748b;font-size:12.5px;">Tổng tiền hàng</span>
                                <span id="grandTotalBefore" style="font-weight:600;font-size:13px;">0 đ</span>
                            </div>
                            <div class="dh-total-row" id="voucherDiscountRow" style="display:none;">
                                <span style="color:#059669;display:flex;align-items:center;gap:5px;font-size:12.5px;">
                                    <i class="fas fa-ticket-alt"></i> Giảm voucher
                                    <span id="vcDiscLabel" style="font-size:10px;background:#d1fae5;color:#065f46;border-radius:4px;padding:1px 6px;font-weight:700;"></span>
                                </span>
                                <span id="voucherDiscountAmt" style="font-weight:700;color:#dc2626;font-size:13px;">–0 đ</span>
                            </div>
                            <div class="dh-total-final">
                                <span style="font-weight:700;color:#065f46;font-size:13px;">💳 Thực thanh toán</span>
                                <span id="grandTotal" style="font-size:21px;font-weight:800;color:#059669;">0 đ</span>
                            </div>
                        </div>

                        <!-- Applied banner -->
                        <div id="vcApplyPreview" style="display:none;margin-top:10px;background:linear-gradient(135deg,#ecfdf5,#d1fae5);border:1.5px solid #6ee7b7;border-radius:10px;padding:10px 14px;">
                            <div style="font-size:11px;font-weight:700;color:#065f46;margin-bottom:3px;">
                                <i class="fas fa-check-circle me-1"></i> Áp voucher thành công!
                            </div>
                            <div style="font-size:12px;color:#047857;">
                                Tiết kiệm: <strong id="vcPreviewSave" style="font-size:14px;">0đ</strong>
                            </div>
                        </div>

                        <!-- Warning laptop -->
                        <div id="vcLaptopWarn" style="display:none;margin-top:8px;background:#fef3c7;border:1.5px solid #fbbf24;border-radius:9px;padding:9px 12px;font-size:11.5px;color:#92400e;">
                            <i class="fas fa-exclamation-circle me-1"></i>
                            Voucher Laptop đang chọn nhưng chưa tích <strong>Đơn có Laptop</strong> ở bên trái. Vui lòng tích hoặc chọn voucher khác.
                        </div>

                    </div><!-- /dh-right -->
                </div><!-- /dh-body-grid -->

                <!-- FOOTER -->
                <div class="modal-footer" style="border-top:2px solid #e2e8f0;background:#fafafa;gap:10px;">
                    <div style="flex:1;font-size:11.5px;color:#94a3b8;display:flex;align-items:center;gap:5px;">
                        <i class="fas fa-lock"></i> Thông tin đơn hàng được lưu bảo mật
                    </div>
                    <button class="btn-outline-qa" type="button" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" name="them_don_hang_chi_tiet" id="btnTaoDH" class="btn-primary-qa" style="padding:9px 24px;font-size:13.5px;">
                        <i class="fas fa-shopping-cart me-1"></i> Tạo đơn hàng
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════
     Modal thêm phiếu sửa chữa – REDESIGNED
════════════════════════════════════════════════════ -->
<style>
/* ── Add Repair Modal Styles ── */
#addPSModal .modal-content { border-radius: 18px; overflow: hidden; border: none; box-shadow: 0 20px 60px rgba(0,0,0,.18); }
#addPSModal .ps-modal-header {
    background: linear-gradient(135deg, #1c1917 0%, #292524 60%, #44403c 100%);
    padding: 22px 28px 18px;
    position: relative;
    overflow: hidden;
}
#addPSModal .ps-modal-header::before {
    content: '';
    position: absolute; inset: 0;
    background: radial-gradient(ellipse at 80% 50%, rgba(251,191,36,.15) 0%, transparent 70%);
}
#addPSModal .ps-modal-header .badge-status-row {
    display: flex; gap: 8px; flex-wrap: wrap; margin-top: 10px;
}
#addPSModal .ps-badge {
    font-size: 11px; padding: 3px 10px; border-radius: 20px;
    background: rgba(255,255,255,.1); color: rgba(255,255,255,.75);
    border: 1px solid rgba(255,255,255,.15);
}
#addPSModal .ps-section-label {
    font-size: 11px; font-weight: 700; letter-spacing: .08em;
    text-transform: uppercase; color: #94a3b8; margin-bottom: 10px;
    display: flex; align-items: center; gap: 6px;
}
#addPSModal .ps-section-label::after {
    content: ''; flex: 1; height: 1px; background: #e2e8f0;
}
#addPSModal .ps-modal-body { padding: 0; }
#addPSModal .ps-left  { padding: 24px 20px 24px 24px; border-right: 1px solid #f1f5f9; }
#addPSModal .ps-right { padding: 24px 24px 24px 20px; background: #fafafa; }

/* Status pills selector */
.status-pill-group { display: flex; flex-wrap: wrap; gap: 6px; }
.status-pill { display: none; }
.status-pill + label {
    cursor: pointer; padding: 5px 13px; border-radius: 20px; font-size: 12px; font-weight: 600;
    border: 2px solid #e2e8f0; color: #64748b; background: #fff;
    transition: all .18s; user-select: none;
}
.status-pill:checked + label { border-color: transparent; color: #fff; }
#sp_tiep_nhan:checked + label  { background:#64748b; }
#sp_kiem_tra:checked  + label  { background:#3b82f6; }
#sp_xu_ly:checked     + label  { background:#f59e0b; color:#fff; }
#sp_linh_kien:checked + label  { background:#ef4444; }
#sp_sua_xong:checked  + label  { background:#10b981; }
#sp_ban_giao:checked  + label  { background:#8b5cf6; }

/* Image drop zone */
.img-dropzone {
    border: 2px dashed #cbd5e1; border-radius: 12px; padding: 20px;
    text-align: center; cursor: pointer; transition: all .2s;
    background: #fff; position: relative;
}
.img-dropzone:hover, .img-dropzone.dragover {
    border-color: #f59e0b; background: #fffbeb;
}
.img-dropzone input[type=file] {
    position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%;
}
.img-preview-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(70px,1fr)); gap: 8px; margin-top: 10px;
}
.img-preview-item {
    position: relative; border-radius: 8px; overflow: hidden; aspect-ratio: 1;
    border: 2px solid #e2e8f0; background: #f8fafc;
}
.img-preview-item img { width: 100%; height: 100%; object-fit: cover; display: block; }
.img-preview-item .remove-img {
    position: absolute; top: 2px; right: 2px; width: 18px; height: 18px;
    background: rgba(239,68,68,.9); color: #fff; border: none; border-radius: 50%;
    font-size: 10px; cursor: pointer; display: flex; align-items: center; justify-content: center;
    line-height: 1;
}

/* ── Voucher Picker (Shopee-style) ── */
.vc-picker-item {
    transition: border-color .18s, background .18s, box-shadow .18s;
    user-select: none;
}
.vc-picker-item:hover {
    border-color: #f59e0b !important;
    background: #fffbeb !important;
    box-shadow: 0 2px 12px rgba(245,158,11,.15);
}
.vc-picker-item.active {
    border-color: #f59e0b !important;
    background: #fffbeb !important;
}

    background: linear-gradient(135deg, #f0fdf4, #dcfce7);
    border: 2px solid #86efac; border-radius: 12px; padding: 14px;
    text-align: center; transition: all .3s;
}
.cost-display.has-discount { border-color: #10b981; background: linear-gradient(135deg, #ecfdf5, #d1fae5); }
</style>

<div class="modal fade" id="addPSModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content">

            <!-- Header -->
            <div class="ps-modal-header">
                <div style="position:relative;z-index:1;">
                    <div style="display:flex;align-items:center;justify-content:space-between;">
                        <div style="display:flex;align-items:center;gap:14px;">
                            <div style="width:44px;height:44px;background:linear-gradient(135deg,#fbbf24,#f59e0b);border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:22px;box-shadow:0 4px 12px rgba(251,191,36,.4);">🔧</div>
                            <div>
                                <div style="font-size:17px;font-weight:800;color:#fff;letter-spacing:-.01em;">Tạo phiếu sửa chữa mới</div>
                                <div style="font-size:12px;color:rgba(255,255,255,.55);margin-top:1px;">Khách hàng: <strong style="color:rgba(255,255,255,.85);"><?= htmlspecialchars($khach_hang['tenKH']) ?></strong></div>
                            </div>
                        </div>
                        <button class="btn-close btn-close-white" data-bs-dismiss="modal" style="opacity:.6;"></button>
                    </div>
                    <div class="badge-status-row">
                        <span class="ps-badge"><i class="fas fa-hashtag me-1"></i>Phiếu mới</span>
                        <span class="ps-badge"><i class="fas fa-calendar me-1"></i><?= date('d/m/Y') ?></span>
                        <span class="ps-badge"><i class="fas fa-user me-1"></i><?= htmlspecialchars($admin_user) ?></span>
                    </div>
                </div>
            </div>

            <form method="POST" enctype="multipart/form-data" id="formTaoPhieu">
                <div class="ps-modal-body">
                    <div class="row g-0" style="min-height:0;">

                        <!-- CỘT TRÁI: Thông tin phiếu -->
                        <div class="col-lg-7 ps-left">

                            <!-- Thông tin thiết bị -->
                            <div class="ps-section-label"><i class="fas fa-laptop"></i> Thông tin thiết bị</div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold" style="font-size:13px;">Tên thiết bị / Model <span class="text-danger">*</span></label>
                                <div style="position:relative;">
                                    <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:15px;">💻</span>
                                    <input type="text" name="tenThietBi" class="form-control" style="padding-left:36px;"
                                        placeholder="VD: Laptop Dell XPS 13, iPhone 14 Pro, MacBook Air M2..." required>
                                </div>
                            </div>

                            <!-- Ngày nhận / trả -->
                            <div class="row g-3 mb-3">
                                <div class="col-6">
                                    <label class="form-label fw-semibold" style="font-size:13px;">Ngày nhận <span class="text-danger">*</span></label>
                                    <input type="date" name="ngayNhan" class="form-control" value="<?= date('Y-m-d') ?>" required>
                                </div>
                                <div class="col-6">
                                    <label class="form-label fw-semibold" style="font-size:13px;">Ngày trả dự kiến</label>
                                    <input type="date" name="ngayTra" class="form-control" id="ngayTraInput">
                                    <div class="form-text" style="font-size:11px;">
                                        <span id="soNgaySua" style="color:#f59e0b;font-weight:600;"></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Trạng thái -->
                            <div class="mb-3">
                                <label class="form-label fw-semibold" style="font-size:13px;">Trạng thái tiếp nhận</label>
                                <div class="status-pill-group">
                                    <input type="radio" class="status-pill" name="trangThai" id="sp_tiep_nhan"  value="Tiếp nhận"    checked>
                                    <label for="sp_tiep_nhan">⬤ Tiếp nhận</label>

                                    <input type="radio" class="status-pill" name="trangThai" id="sp_kiem_tra"  value="Đang kiểm tra">
                                    <label for="sp_kiem_tra">🔍 Đang kiểm tra</label>

                                    <input type="radio" class="status-pill" name="trangThai" id="sp_xu_ly"     value="Đang xử lý">
                                    <label for="sp_xu_ly">⚙️ Đang xử lý</label>

                                    <input type="radio" class="status-pill" name="trangThai" id="sp_linh_kien" value="Chờ linh kiện">
                                    <label for="sp_linh_kien">📦 Chờ linh kiện</label>

                                    <input type="radio" class="status-pill" name="trangThai" id="sp_sua_xong"  value="Đã sửa xong">
                                    <label for="sp_sua_xong">✅ Đã sửa xong</label>

                                    <input type="radio" class="status-pill" name="trangThai" id="sp_ban_giao"  value="Đã bàn giao">
                                    <label for="sp_ban_giao">🚀 Đã bàn giao</label>
                                </div>
                            </div>

                            <!-- Mô tả lỗi -->
                            <div class="mb-3">
                                <label class="form-label fw-semibold" style="font-size:13px;">Mô tả lỗi / Yêu cầu kỹ thuật</label>
                                <textarea name="moTaLoi" class="form-control" rows="4"
                                    placeholder="Mô tả chi tiết tình trạng: màn hình, pin, bàn phím, lỗi phần mềm... Càng chi tiết càng tốt."
                                    style="resize:vertical;font-size:13px;"></textarea>
                            </div>

                            <!-- Chi phí -->
                            <div class="ps-section-label"><i class="fas fa-dollar-sign"></i> Chi phí</div>
                            <div class="row g-3">
                                <div class="col-7">
                                    <label class="form-label fw-semibold" style="font-size:13px;">Chi phí gốc (đ) <span class="text-danger">*</span></label>
                                    <div style="position:relative;">
                                        <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:12px;font-weight:700;">₫</span>
                                        <input type="number" name="chiPhi" class="form-control" id="chiPhiInput"
                                            value="0" min="0" step="1000"
                                            style="padding-left:28px;" oninput="previewGiamSC()">
                                    </div>
                                    <div class="form-text" style="font-size:11px;">Chi phí gốc trước khi áp voucher.</div>
                                </div>
                                <div class="col-5">
                                    <label class="form-label fw-semibold" style="font-size:13px;">Sau giảm giá</label>
                                    <div class="cost-display" id="chiPhiSauGiamBox">
                                        <div style="font-size:11px;color:#166534;margin-bottom:2px;" id="chiPhiGiamRow" style="display:none;">
                                            Gốc: <span id="chiPhiGocHien">0đ</span>
                                            <span id="giamRow" style="display:none;"> → <span style="color:#dc2626;font-weight:700;" id="chiPhiGiamHien"></span></span>
                                        </div>
                                        <div style="font-size:18px;font-weight:800;color:#059669;" id="chiPhiSauGiam">0 đ</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Voucher sửa chữa -->
                            <?php if (!empty($v_sc_list)): ?>
                            <div style="background:linear-gradient(135deg,#fffbeb,#fef3c7);border:2px solid #fbbf24;border-radius:12px;padding:14px;margin-top:12px;">
                                <div style="font-weight:700;color:#92400e;margin-bottom:8px;display:flex;align-items:center;gap:8px;">
                                    <span>🎟️</span> Voucher sửa chữa khả dụng
                                </div>
                                <div style="display:flex;flex-direction:column;gap:6px;">
                                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;padding:9px 12px;background:#fff;border-radius:9px;border:1px solid #fde68a;">
                                        <input type="radio" name="ap_voucher_sua_chua" value="0" checked style="accent-color:#d97706;" onchange="previewGiamSC()">
                                        <span style="color:#64748b;font-size:13px;">Không áp dụng lần này</span>
                                    </label>
                                    <?php foreach ($v_sc_list as $vsc): ?>
                                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;padding:9px 12px;background:#fff;border-radius:9px;border:1px solid #fde68a;">
                                        <input type="radio" name="ap_voucher_sua_chua" value="<?= $vsc['maVoucher'] ?>" style="accent-color:#d97706;" onchange="previewGiamSC()">
                                        <div style="flex:1;">
                                            <span class="voucher-code">VC-<?= str_pad($vsc['maVoucher'], 5, '0', STR_PAD_LEFT) ?></span>
                                            <span style="margin-left:8px;font-size:12px;color:#b45309;font-weight:600;">Giảm 50% (tối đa 500.000đ)</span>
                                        </div>
                                        <span style="font-size:11px;color:#92400e;">HSD: <?= $vsc['ngayHetHan'] ? date('d/m/Y', strtotime($vsc['ngayHetHan'])) : '∞' ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                        </div><!-- /ps-left -->

                        <!-- CỘT PHẢI: Ảnh đính kèm -->
                        <div class="col-lg-5 ps-right">
                            <div class="ps-section-label"><i class="fas fa-camera"></i> Ảnh thiết bị</div>

                            <!-- Loại ảnh -->
                            <div class="mb-3">
                                <label class="form-label fw-semibold" style="font-size:13px;">Phân loại ảnh</label>
                                <div style="display:flex;gap:8px;">
                                    <label style="flex:1;cursor:pointer;">
                                        <input type="radio" name="loaiAnhUpload" value="truoc" checked style="display:none;" class="loai-anh-radio">
                                        <div class="loai-anh-btn" data-val="truoc" style="text-align:center;padding:10px 6px;border:2px solid #10b981;border-radius:10px;background:#f0fdf4;font-size:12px;font-weight:700;color:#059669;transition:all .15s;">
                                            📸 Trước sửa
                                        </div>
                                    </label>
                                    <label style="flex:1;cursor:pointer;">
                                        <input type="radio" name="loaiAnhUpload" value="sau" style="display:none;" class="loai-anh-radio">
                                        <div class="loai-anh-btn" data-val="sau" style="text-align:center;padding:10px 6px;border:2px solid #e2e8f0;border-radius:10px;background:#fff;font-size:12px;font-weight:700;color:#94a3b8;transition:all .15s;">
                                            ✅ Sau sửa
                                        </div>
                                    </label>
                                    <label style="flex:1;cursor:pointer;">
                                        <input type="radio" name="loaiAnhUpload" value="loi" style="display:none;" class="loai-anh-radio">
                                        <div class="loai-anh-btn" data-val="loi" style="text-align:center;padding:10px 6px;border:2px solid #e2e8f0;border-radius:10px;background:#fff;font-size:12px;font-weight:700;color:#94a3b8;transition:all .15s;">
                                            ⚠️ Lỗi / Hỏng
                                        </div>
                                    </label>
                                </div>
                            </div>

                            <!-- Drag & Drop zone -->
                            <div class="img-dropzone" id="psDropzone"
                                ondragover="event.preventDefault();this.classList.add('dragover')"
                                ondragleave="this.classList.remove('dragover')"
                                ondrop="handlePsDrop(event)">
                                <input type="file" name="anhThietBi[]" id="psFileInput"
                                    accept="image/jpeg,image/png,image/webp"
                                    multiple onchange="handlePsFiles(this.files)">
                                <div id="psDropContent">
                                    <div style="font-size:32px;margin-bottom:8px;">📂</div>
                                    <div style="font-weight:700;color:#475569;font-size:13px;">Kéo thả ảnh vào đây</div>
                                    <div style="font-size:12px;color:#94a3b8;margin-top:4px;">hoặc <span style="color:#f59e0b;font-weight:700;">nhấn để chọn file</span></div>
                                    <div style="font-size:11px;color:#cbd5e1;margin-top:8px;">JPG, PNG, WEBP · Tối đa 5 ảnh · Mỗi ảnh ≤ 5MB</div>
                                </div>
                            </div>

                            <!-- Preview grid -->
                            <div class="img-preview-grid" id="psPreviewGrid"></div>
                            <div id="psImgCount" style="font-size:11px;color:#94a3b8;margin-top:6px;text-align:right;"></div>

                            <!-- Ghi chú ảnh -->
                            <div class="mt-3">
                                <label class="form-label fw-semibold" style="font-size:13px;">Ghi chú ảnh</label>
                                <input type="text" name="moTaAnh" id="moTaAnh" class="form-control" style="font-size:13px;"
                                    placeholder="VD: Màn hình vỡ góc trên bên phải...">
                            </div>

                            <!-- Tips -->
                            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:12px;margin-top:16px;">
                                <div style="font-size:11px;font-weight:700;color:#64748b;margin-bottom:8px;text-transform:uppercase;letter-spacing:.05em;">💡 Gợi ý chụp ảnh</div>
                                <ul style="margin:0;padding-left:16px;font-size:11px;color:#94a3b8;line-height:1.9;">
                                    <li>Chụp toàn thể trước và sau sửa</li>
                                    <li>Chụp cận vùng lỗi / hỏng</li>
                                    <li>Chụp serial number / nhãn máy</li>
                                    <li>Ảnh rõ nét, đủ sáng để lưu hồ sơ</li>
                                </ul>
                            </div>
                        </div><!-- /ps-right -->
                    </div><!-- /row -->
                </div><!-- /ps-modal-body -->

                <div class="modal-footer" style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:14px 24px;">
                    <div style="flex:1;font-size:12px;color:#94a3b8;" id="psFooterInfo">
                        <i class="fas fa-info-circle me-1"></i> Ảnh sẽ được lưu vào hồ sơ phiếu sau khi tạo.
                    </div>
                    <button class="btn-outline-qa" type="button" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>Hủy
                    </button>
                    <button type="submit" name="them_phieu_sua" class="btn-primary-qa" id="btnTaoPhieu"
                        style="background:linear-gradient(135deg,#f59e0b,#d97706);border-color:transparent;min-width:140px;">
                        <i class="fas fa-plus-circle me-1"></i> Tạo phiếu
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
/* ── Phiếu sửa chữa: image upload preview ── */
let psFiles = [];

function handlePsFiles(fileList) {
    const newFiles = Array.from(fileList).filter(f => f.type.startsWith('image/'));
    const remaining = 5 - psFiles.length;
    if (remaining <= 0) { Swal.fire({icon:'warning',title:'Giới hạn ảnh',text:'Tối đa 5 ảnh mỗi phiếu.',confirmButtonColor:'#f59e0b'}); return; }
    newFiles.slice(0, remaining).forEach(f => {
        if (f.size > 5 * 1024 * 1024) { Swal.fire({icon:'error',title:'File quá lớn',text:f.name + ' vượt quá 5MB.',confirmButtonColor:'#ef4444'}); return; }
        psFiles.push(f);
    });
    renderPsPreview();
}

function handlePsDrop(e) {
    e.preventDefault();
    document.getElementById('psDropzone').classList.remove('dragover');
    handlePsFiles(e.dataTransfer.files);
}

function renderPsPreview() {
    const grid = document.getElementById('psPreviewGrid');
    const count = document.getElementById('psImgCount');
    grid.innerHTML = '';
    psFiles.forEach((f, i) => {
        const url = URL.createObjectURL(f);
        const item = document.createElement('div');
        item.className = 'img-preview-item';
        item.innerHTML = `<img src="${url}" alt="preview"><button type="button" class="remove-img" onclick="removePsImg(${i})">✕</button>`;
        grid.appendChild(item);
    });
    count.textContent = psFiles.length > 0 ? psFiles.length + '/5 ảnh đã chọn' : '';

    // Rebuild file input with DataTransfer
    const dt = new DataTransfer();
    psFiles.forEach(f => dt.items.add(f));
    document.getElementById('psFileInput').files = dt.files;

    // Footer info update
    document.getElementById('psFooterInfo').innerHTML = psFiles.length > 0
        ? `<i class="fas fa-images me-1 text-warning"></i> <strong style="color:#d97706;">${psFiles.length} ảnh</strong> sẽ được đính kèm.`
        : '<i class="fas fa-info-circle me-1"></i> Ảnh sẽ được lưu vào hồ sơ phiếu sau khi tạo.';
}

function removePsImg(idx) {
    psFiles.splice(idx, 1);
    renderPsPreview();
}

/* ── Loại ảnh toggle style ── */
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.loai-anh-radio').forEach(radio => {
        radio.addEventListener('change', function() {
            document.querySelectorAll('.loai-anh-btn').forEach(btn => {
                btn.style.borderColor = '#e2e8f0';
                btn.style.background  = '#fff';
                btn.style.color       = '#94a3b8';
            });
            const active = document.querySelector('.loai-anh-btn[data-val="' + this.value + '"]');
            if (active) {
                active.style.borderColor = '#10b981';
                active.style.background  = '#f0fdf4';
                active.style.color       = '#059669';
            }
        });
    });

    /* ── Tính số ngày sửa ── */
    const ngayNhanEl = document.querySelector('#formTaoPhieu input[name="ngayNhan"]');
    const ngayTraEl  = document.getElementById('ngayTraInput');
    function calcDays() {
        if (!ngayNhanEl.value || !ngayTraEl.value) { document.getElementById('soNgaySua').textContent = ''; return; }
        const d = Math.round((new Date(ngayTraEl.value) - new Date(ngayNhanEl.value)) / 86400000);
        document.getElementById('soNgaySua').textContent = d > 0 ? '⏱ ' + d + ' ngày sửa chữa' : '';
    }
    ngayNhanEl && ngayNhanEl.addEventListener('change', calcDays);
    ngayTraEl  && ngayTraEl.addEventListener('change', calcDays);
});

/* ── Reset khi đóng modal ── */
document.getElementById('addPSModal').addEventListener('hidden.bs.modal', function() {
    psFiles = [];
    document.getElementById('psPreviewGrid').innerHTML = '';
    document.getElementById('psImgCount').textContent = '';
    document.getElementById('psFooterInfo').innerHTML = '<i class="fas fa-info-circle me-1"></i> Ảnh sẽ được lưu vào hồ sơ phiếu sau khi tạo.';
});
</script>

<!-- Modal thêm bảo hành -->
<div class="modal fade" id="addBHModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#1e3a8a,#2563eb);color:white;">
                <h5 class="modal-title" style="color:white;"><i class="fas fa-shield-alt me-2"></i>Cấp Phiếu / Thẻ Bảo Hành</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label">Tên sản phẩm / Serial Number <span class="text-danger">*</span></label>
                            <input type="text" name="tenSP" class="form-control" placeholder="VD: Dell XPS 13 9310 - SN: 7XXXXXXX" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Ngày bắt đầu <span class="text-danger">*</span></label>
                            <input type="date" name="ngayBatDau" class="form-control" value="<?= date('Y-m-d') ?>" required onchange="calcWarrantyEndAdd()">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Thời hạn bảo hành <span class="text-danger">*</span></label>
                            <select name="thoiHan" class="form-select" id="thoiHanSelectAdd" onchange="calcWarrantyEndAdd()">
                                <option value="3 tháng">3 tháng</option>
                                <option value="6 tháng">6 tháng</option>
                                <option value="12 tháng" selected>12 tháng</option>
                                <option value="24 tháng">24 tháng</option>
                                <option value="36 tháng">36 tháng</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Ngày kết thúc <span class="text-danger">*</span></label>
                            <input type="date" name="ngayKetThuc" class="form-control" id="ngayKetThucBHAdd" value="<?= date('Y-m-d', strtotime('+12 months')) ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Điều kiện bảo hành <span class="text-danger">*</span></label>
                            <textarea name="dieuKienBaoHanh" class="form-control" rows="12" style="font-size: 12px; line-height: 1.5;" required><?php echo htmlspecialchars('CHÍNH SÁCH BẢO HÀNH - MÁY TÍNH QUANG ANH

📌 Laptop NEW: Bảo hành 12 tháng theo quy định của hãng (đổi mới trong vòng 30 ngày kể từ ngày mua hàng nếu phát sinh lỗi phần cứng).

📌 Laptop CŨ: Bảo hành 3-6 tháng 1 ĐỔI (tùy mã máy – trong vòng 15 ngày kể từ ngày mua hàng được đổi sang bất kỳ mã máy nào khác tại Quang Anh không cần lý do).

✅ Cài win, hỗ trợ cài đặt các phần mềm (không bản quyền): MIỄN PHÍ trọn đời máy.

✅ Vệ sinh chuyên sâu máy (tra keo tản nhiệt, tra dầu quạt…): MIỄN PHÍ trong vòng 24 tháng kể từ ngày mua. Hết thời gian 24 tháng: phí hỗ trợ 50.000VNĐ/lần (máy ngoài: 150.000VNĐ/lần).

⚠️ Quang Anh KHÔNG NHẬP LẠI MÁY trong thời gian 12 THÁNG kể từ mua hàng.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

📋 ĐIỀU KIỆN NHẬN BẢO HÀNH MIỄN PHÍ (phải thỏa mãn đủ 03 điều kiện):

1. TEM NIÊM PHONG của Quang Anh dán trên sản phẩm phải còn nguyên vẹn, không bị tẩy xóa, đọc rõ được nội dung. Tem không bị bong, rách hoặc bị dán tem khác.

2. Sản phẩm KHÔNG có dấu hiệu hư hỏng ngoại quan như dính chất lỏng, bị vỡ, móp, nứt vỡ, trầy tróc, bong vênh chi tiết, thiếu ốc...

3. Máy phát sinh LỖI KỸ THUẬT ảnh hưởng đến khả năng hoạt động. Lỗi kỹ thuật là lỗi do quy trình sản xuất từ nhà sản xuất, không bao gồm lỗi do sử dụng sai quy cách, lỗi phần mềm, thiên tai, cháy nổ...

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

⚠️ CÁC TRƯỜNG HỢP TỪ CHỐI BẢO HÀNH:

• Quang Anh KHÔNG BẢO HÀNH các chức năng: Đèn bàn phím, Báo mặt vân tay, Camera (chỉ hỗ trợ thay thế linh kiện cơ bản tùy điều kiện thực tế).

• Đối với màn hình và Pin laptop cũ: điểm chấm/điểm chết màn hình và độ chai pin (không vượt quá 50%) được coi là HAO MÒN, không được bảo hành.

• TỪ CHỐI nếu phát hiện: máy bị ẩm ướt/dính chất lỏng, côn trùng phá hoại, sử dụng sai nguồn sạc, bị chập cháy bảng mạch do sét đánh, hỏa hoạn, lũ lụt...

• Màn hình cong/trên máy cũ chỉ bảo hành tối đa 03 THÁNG.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

📌 MỘT SỐ LƯU Ý QUAN TRỌNG:

• Lỗi laptop quên hoặc mất password (vân tay/khuôn mặt/khóa BIOS) sẽ KHÔNG được bảo hành.

• Không nên tháo pin ra rồi cắm sạc trực tiếp, hay dùng cạn kiệt pin.

• Ảnh hưởng từ virus máy tính, sự không tương thích giữa các phần mềm không nằm trong phạm vi bảo hành.

• Bảo hành áp dụng tại cửa hàng. Trường hợp cần giữ máy kiểm tra, Quang Anh hỗ trợ thiết bị tạm thời để sử dụng.

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

📞 Mọi thắc mắc xin liên hệ: 0934322199 để được hỗ trợ.'); ?></textarea>
                            <small class="text-muted">Nội dung chính sách bảo hành đã được điền sẵn theo quy định của công ty.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Tình trạng</label>
                            <select name="trangThai" class="form-select">
                                <option value="Còn bảo hành" selected>Còn bảo hành</option>
                                <option value="Hết hạn">Hết hạn</option>
                                <option value="Đang xử lý">Đang xử lý</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline-qa" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" name="them_bao_hanh" class="btn-primary-qa"><i class="fas fa-certificate"></i> Kích Hoạt Bảo Hành</button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- Modal tải lên tài liệu -->
<div class="modal fade" id="addTLModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-file-upload text-warning me-2"></i>Tải lên tài liệu</h5>
                <button class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Loại tài liệu</label>
                        <select name="loaiTaiLieu" class="form-select">
                            <?php foreach (['Hợp đồng','Hóa đơn tài chính','Chứng nhận bảo hành','Ảnh sản phẩm','Biên bản nghiệm thu','Tài liệu kỹ thuật','Khác'] as $lt): ?>
                            <option><?= $lt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tên / Mô tả tài liệu</label>
                        <input type="text" name="tenTaiLieu" class="form-control" placeholder="VD: Hợp đồng bảo trì 2025, Hóa đơn VAT #001..." required>
                    </div>
                    <div>
                        <label class="form-label">Chọn file (JPG, PNG, PDF, DOCX)</label>
                        <input type="file" name="fileTaiLieu[]" class="form-control" multiple accept=".jpg,.jpeg,.png,.pdf,.docx,.doc">
                        <div class="form-text">Có thể chọn nhiều file cùng lúc.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-outline-qa" type="button" data-bs-dismiss="modal">Hủy</button>
                    <button type="submit" name="tai_len_tai_lieu" class="btn-primary-qa"><i class="fas fa-upload"></i> Tải lên</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal xem ảnh lớn -->
<div class="modal fade" id="bigImgModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="background:transparent;border:none;box-shadow:none;">
            <div class="modal-body p-0 text-center" style="position:relative;">
                <button class="btn-close btn-close-white" data-bs-dismiss="modal" style="position:absolute;top:10px;right:10px;z-index:10;"></button>
                <img id="bigImgSrc" src="" style="max-width:100%;max-height:85vh;border-radius:12px;object-fit:contain;">
            </div>
        </div>
    </div>
</div>

<!-- Modal cấp voucher thủ công -->
<div class="modal fade" id="capVoucherModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#7c3aed,#5b21b6);color:white;">
                <h5 class="modal-title" style="color:white;"><i class="fas fa-ticket-alt me-2"></i>Cấp voucher thủ công</h5>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div style="background:#fef3c7;border:1px solid #fbbf24;border-radius:10px;padding:12px;margin-bottom:16px;font-size:13px;color:#92400e;">
                        <i class="fas fa-info-circle me-1"></i>
                        Dùng khi khách đã đủ điều kiện hạng nhưng voucher chưa được cấp tự động (ví dụ: admin set hạng thủ công, hoặc hệ thống cũ chưa có voucher).
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Loại voucher</label>
                        <div style="display:flex;flex-direction:column;gap:10px;">
                            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;padding:12px;background:#fef3c7;border:2px solid #fbbf24;border-radius:10px;">
                                <input type="radio" name="loai_voucher_cap" value="sua_chua_50pct" checked style="accent-color:#d97706;">
                                <div>
                                    <div style="font-weight:700;font-size:13px;">🔧 Voucher sửa chữa – Giảm 50%</div>
                                    <div style="font-size:12px;color:#78350f;">Dùng cho lần sửa chữa tiếp theo (tối đa giảm 500.000đ)</div>
                                </div>
                            </label>
                            <label style="display:flex;align-items:center;gap:10px;cursor:pointer;padding:12px;background:#dbeafe;border:2px solid #93c5fd;border-radius:10px;">
                                <input type="radio" name="loai_voucher_cap" value="mua_hang_1trieu" style="accent-color:#2563eb;">
                                <div>
                                    <div style="font-weight:700;font-size:13px;">💻 Voucher mua laptop – Giảm 1.000.000đ</div>
                                    <div style="font-size:12px;color:#1e40af;">Chỉ áp dụng khi đơn hàng có laptop</div>
                                </div>
                            </label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Số lượng cấp</label>
                        <select name="so_luong_cap" class="form-select">
                            <option value="1">1 voucher</option>
                            <option value="2">2 voucher</option>
                            <option value="3">3 voucher</option>
                        </select>
                        <div class="form-text">Thân thiết thường cấp 1, VIP cấp 3.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn-outline-qa" type="button" data-bs-dismiss="modal">Hủy</button>
                    <button type="button" class="btn-primary-qa" onclick="xacNhanCapVoucher()">
                        <i class="fas fa-gift"></i> Cấp voucher
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══ MODAL SINH NHẬT ══ -->
<div class="modal fade" id="birthdayModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border:none;border-radius:16px;overflow:hidden;">
            <div class="modal-header" style="background:linear-gradient(135deg,#be185d,#ec4899,#f97316);color:white;padding:20px 24px;">
                <div>
                    <h5 class="modal-title" style="color:white;font-size:18px;font-weight:800;">
                        🎂 Gửi lời chúc sinh nhật
                    </h5>
                    <div style="font-size:13px;opacity:.85;margin-top:3px;">
                        <?php
                        if (!empty($khach_hang['ngaySinh'])):
                            $bdThisYearM = date('Y') . '-' . date('m-d', strtotime($khach_hang['ngaySinh']));
                            $daysLeftModal = (int)floor((strtotime($bdThisYearM) - strtotime(date('Y-m-d'))) / 86400);
                            if ($daysLeftModal < 0) { $bdNextYM = (date('Y')+1) . '-' . date('m-d', strtotime($khach_hang['ngaySinh'])); $daysLeftModal = (int)floor((strtotime($bdNextYM) - strtotime(date('Y-m-d'))) / 86400); }
                            $dtSinhM  = new DateTime($khach_hang['ngaySinh']);
                            $dtTodayM = new DateTime();
                            $tuoiModal = (int)$dtTodayM->diff($dtSinhM)->y;
                            if ($daysLeftModal === 0) echo "🎉 Hôm nay là sinh nhật – Tròn " . $tuoiModal . " tuổi!";
                            else echo "Sinh nhật ngày " . date('d/m/Y', strtotime($khach_hang['ngaySinh'])) . " – Còn $daysLeftModal ngày";
                        else:
                            echo "Chưa có ngày sinh – vui lòng cập nhật hồ sơ";
                        endif;
                        ?>
                    </div>
                </div>
                <button class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <form method="POST" id="formBirthday">
                <!-- mode: 'standard' = chúc mừng thường, 'tu_soan' = tự soạn voucher -->
                <input type="hidden" name="bd_mode"      id="hdBdMode"      value="standard">
                <input type="hidden" name="loaiUuDai"    id="hdLoaiUuDai"   value="">
                <input type="hidden" name="kenhGui"      id="hdKenhGui"     value="">
                <input type="hidden" name="noiDungLog"   id="hdNoiDungLog"  value="">
                <!-- Hidden inputs cho mode tự soạn -->
                <input type="hidden" name="tenVcTuSoan"   id="hdTenVc"        value="">
                <input type="hidden" name="loaiVcTuSoan"  id="hdLoaiVcTuSoan" value="tu_soan">
                <input type="hidden" name="loaiGiaTriVc"  id="hdLoaiGiaTriVc" value="pct">
                <input type="hidden" name="giaTriVcTuSoan" id="hdGiaTriVc"    value="">
                <input type="hidden" name="soTienToiDa"   id="hdSoTienToiDa"  value="0">
                <input type="hidden" name="ngayHetHanVc"  id="hdNgayHetHanVc" value="">
                <input type="hidden" name="bdMessageCustom" id="hdBdMsgCustom" value="">

                <div class="modal-body" style="padding:0;">

                    <!-- BƯỚC 1: Chọn loại ưu đãi -->
                    <div style="padding:20px 24px 0;">
                        <div style="font-size:12px;font-weight:700;color:#94a3b8;letter-spacing:.05em;margin-bottom:12px;">BƯỚC 1 — CHỌN NỘI DUNG GỬI</div>
                        <div style="display:flex;flex-direction:column;gap:10px;">

                            <!-- Chỉ chúc mừng -->
                            <label id="tplLbl_chuc" onclick="setBDTemplate('chuc')" style="display:flex;align-items:flex-start;gap:12px;padding:14px 16px;border:2px solid #ec4899;background:#fce7f3;border-radius:12px;cursor:pointer;transition:.15s;">
                                <input type="radio" name="_tplRadio" value="chuc" checked style="accent-color:#be185d;margin-top:2px;flex-shrink:0;">
                                <div style="flex:1;">
                                    <div style="font-weight:700;font-size:13px;color:#be185d;">🎂 Chỉ chúc mừng sinh nhật</div>
                                    <div style="font-size:12px;color:#9d174d;margin-top:2px;">Gửi lời chúc thân thiện, không kèm ưu đãi. <strong>Không tạo voucher.</strong></div>
                                </div>
                            </label>

                            <!-- Chúc + ưu đãi 10% -->
                            <label id="tplLbl_uudai" onclick="setBDTemplate('uudai')" style="display:flex;align-items:flex-start;gap:12px;padding:14px 16px;border:2px solid #e2e8f0;background:#fff;border-radius:12px;cursor:pointer;transition:.15s;">
                                <input type="radio" name="_tplRadio" value="uudai" style="accent-color:#d97706;margin-top:2px;flex-shrink:0;">
                                <div style="flex:1;">
                                    <div style="font-weight:700;font-size:13px;color:#92400e;">🎁 Chúc sinh nhật + Ưu đãi giảm 10%</div>
                                    <div style="font-size:12px;color:#78350f;margin-top:2px;">
                                        Gửi tin chúc mừng kèm ưu đãi.
                                        <span style="display:inline-block;background:#fef3c7;color:#92400e;border:1px solid #fbbf24;border-radius:6px;padding:1px 8px;font-weight:700;font-size:11px;margin-left:4px;">✅ Tự động tạo voucher 10% vào hệ thống</span>
                                    </div>
                                    <div style="font-size:11px;color:#b45309;margin-top:4px;">⏳ Hạn dùng: 30 ngày từ ngày sinh nhật</div>
                                </div>
                            </label>

                            <!-- Chúc + voucher sửa chữa 50% -->
                            <label id="tplLbl_voucher" onclick="setBDTemplate('voucher')" style="display:flex;align-items:flex-start;gap:12px;padding:14px 16px;border:2px solid #e2e8f0;background:#fff;border-radius:12px;cursor:pointer;transition:.15s;">
                                <input type="radio" name="_tplRadio" value="voucher" style="accent-color:#7c3aed;margin-top:2px;flex-shrink:0;">
                                <div style="flex:1;">
                                    <div style="font-weight:700;font-size:13px;color:#5b21b6;">🎟️ Chúc sinh nhật + Tặng voucher sửa chữa 50%</div>
                                    <div style="font-size:12px;color:#4c1d95;margin-top:2px;">
                                        Gửi tin kèm tặng voucher sửa chữa.
                                        <span style="display:inline-block;background:#ede9fe;color:#5b21b6;border:1px solid #a78bfa;border-radius:6px;padding:1px 8px;font-weight:700;font-size:11px;margin-left:4px;">✅ Tự động tạo voucher SC 50% vào hệ thống</span>
                                    </div>
                                    <div style="font-size:11px;color:#6d28d9;margin-top:4px;">⏳ Hạn dùng: 30 ngày từ ngày sinh nhật · Tối đa giảm 500.000đ</div>
                                </div>
                            </label>

                            <!-- Tự soạn tin + tự soạn voucher -->
                            <label id="tplLbl_tu_soan" onclick="setBDTemplate('tu_soan')" style="display:flex;align-items:flex-start;gap:12px;padding:14px 16px;border:2px solid #e2e8f0;background:#fff;border-radius:12px;cursor:pointer;transition:.15s;">
                                <input type="radio" name="_tplRadio" value="tu_soan" style="accent-color:#0891b2;margin-top:2px;flex-shrink:0;">
                                <div style="flex:1;">
                                    <div style="font-weight:700;font-size:13px;color:#0e7490;">✏️ Tự soạn tin nhắn + Tự soạn voucher</div>
                                    <div style="font-size:12px;color:#155e75;margin-top:2px;">
                                        Nhập nội dung tin nhắn và thiết kế voucher theo ý muốn.
                                        <span style="display:inline-block;background:#cffafe;color:#0e7490;border:1px solid #67e8f9;border-radius:6px;padding:1px 8px;font-weight:700;font-size:11px;margin-left:4px;">✅ Tự động lưu voucher vào hệ thống</span>
                                    </div>
                                    <div style="font-size:11px;color:#0891b2;margin-top:4px;">⚙️ Linh hoạt: giảm %, giảm tiền trực tiếp, đặt hạn dùng tùy ý</div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- PANEL TỰ SOẠN VOUCHER (chỉ hiện khi chọn tu_soan) -->
                    <div id="panelTuSoanVoucher" style="display:none;padding:0 24px 0;">
                        <div style="background:linear-gradient(135deg,#ecfeff,#cffafe);border:2px solid #67e8f9;border-radius:14px;padding:18px 20px;margin-top:4px;">
                            <div style="font-size:12px;font-weight:700;color:#0e7490;letter-spacing:.05em;margin-bottom:14px;">⚙️ THIẾT KẾ VOUCHER TÙY CHỈNH</div>
                            <div class="row g-3">
                                <div class="col-12">
                                    <label style="font-size:12px;font-weight:700;color:#155e75;margin-bottom:4px;display:block;">Tên / Tiêu đề voucher</label>
                                    <input type="text" id="tenVcInput" placeholder="VD: Voucher sinh nhật đặc biệt 2025" class="form-control"
                                        style="font-size:13px;border-radius:8px;" oninput="updateCustomVoucherNote()">
                                </div>
                                <div class="col-sm-5">
                                    <label style="font-size:12px;font-weight:700;color:#155e75;margin-bottom:4px;display:block;">Loại giảm giá</label>
                                    <select id="loaiGiaTriVcSel" class="form-select" style="font-size:13px;border-radius:8px;" onchange="toggleVoucherValueInput()">
                                        <option value="pct">📉 Giảm theo % (phần trăm)</option>
                                        <option value="vnd">💵 Giảm tiền trực tiếp (VNĐ)</option>
                                    </select>
                                </div>
                                <div class="col-sm-4">
                                    <label style="font-size:12px;font-weight:700;color:#155e75;margin-bottom:4px;display:block;" id="labelGiaTriVc">Mức giảm (%)</label>
                                    <div style="position:relative;">
                                        <input type="number" id="giaTriVcInput" placeholder="VD: 20" class="form-control"
                                            style="font-size:13px;border-radius:8px;padding-right:40px;" min="1" oninput="updateCustomVoucherNote()">
                                        <span id="giaTriVcUnit" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);font-size:12px;color:#64748b;font-weight:700;">%</span>
                                    </div>
                                </div>
                                <div class="col-sm-3">
                                    <label style="font-size:12px;font-weight:700;color:#155e75;margin-bottom:4px;display:block;">Tối đa (đ)</label>
                                    <input type="number" id="soTienToiDaInput" placeholder="0 = không giới hạn" class="form-control"
                                        style="font-size:13px;border-radius:8px;" min="0" oninput="updateCustomVoucherNote()">
                                    <div style="font-size:10px;color:#64748b;margin-top:2px;">0 = không giới hạn</div>
                                </div>
                                <div class="col-sm-6">
                                    <label style="font-size:12px;font-weight:700;color:#155e75;margin-bottom:4px;display:block;">Hạn sử dụng</label>
                                    <input type="date" id="ngayHetHanVcInput" class="form-control" style="font-size:13px;border-radius:8px;"
                                        value="<?= date('Y-m-d', strtotime('+30 days')) ?>" oninput="updateCustomVoucherNote()">
                                </div>
                                <div class="col-sm-6">
                                    <label style="font-size:12px;font-weight:700;color:#155e75;margin-bottom:4px;display:block;">Áp dụng cho</label>
                                    <select id="loaiVcTuSoanSel" class="form-select" style="font-size:13px;border-radius:8px;" onchange="updateCustomVoucherNote()">
                                        <option value="tu_soan_sc">🔧 Sửa chữa</option>
                                        <option value="tu_soan_mh">💻 Mua hàng</option>
                                        <option value="tu_soan">🎁 Tất cả dịch vụ</option>
                                    </select>
                                </div>
                                <!-- Preview voucher -->
                                <div class="col-12">
                                    <div id="previewCustomVoucher" style="background:#fff;border:2px dashed #22d3ee;border-radius:10px;padding:12px 16px;font-size:12px;color:#0e7490;">
                                        <i class="fas fa-eye me-1"></i> <span id="previewVcText">Điền thông tin để xem preview voucher...</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- BƯỚC 2: Nội dung tin nhắn -->
                    <div style="padding:16px 24px 0;">
                        <div style="font-size:12px;font-weight:700;color:#94a3b8;letter-spacing:.05em;margin-bottom:10px;">BƯỚC 2 — NỘI DUNG TIN NHẮN</div>
                        <!-- Textarea dùng cho các mẫu có sẵn (chuc / uudai / voucher) -->
                        <div id="wrapBdMsgTemplate">
                            <textarea id="bdMessageContent" class="form-control" rows="7"
                                style="font-size:13px;line-height:1.7;border-radius:10px;resize:vertical;"
                                placeholder="Chọn loại ưu đãi bên trên để xem mẫu tin nhắn..."></textarea>
                        </div>
                        <!-- Textarea dùng riêng cho tự soạn -->
                        <div id="wrapBdMsgCustom" style="display:none;">
                            <textarea id="bdMessageCustom" name="bdMessageCustomDirect" class="form-control" rows="7"
                                style="font-size:13px;line-height:1.7;border-radius:10px;resize:vertical;border-color:#22d3ee;"
                                placeholder="✏️ Nhập nội dung tin nhắn chúc sinh nhật theo ý bạn..."></textarea>
                            <div style="font-size:11px;color:#0891b2;margin-top:6px;"><i class="fas fa-info-circle me-1"></i>Nội dung này sẽ được lưu vào nhật ký giao tiếp cùng voucher tùy chỉnh.</div>
                        </div>
                    </div>

                    <!-- BƯỚC 3: Kênh gửi + Lưu -->
                    <div style="padding:16px 24px 20px;">
                        <div style="font-size:12px;font-weight:700;color:#94a3b8;letter-spacing:.05em;margin-bottom:10px;">BƯỚC 3 — GỬI & LƯU</div>
                        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                            <?php if (!empty($khach_hang['soDienThoai'])): ?>
                            <button type="button" onclick="sendBDZalo()" class="bd-send-btn"
                                style="display:inline-flex;align-items:center;gap:7px;padding:10px 18px;background:#0068ff;color:#fff;border:none;border-radius:10px;font-size:13px;font-weight:600;cursor:pointer;">
                                <i class="fas fa-comment-dots"></i> Gửi Zalo rồi Lưu
                            </button>
                            <button type="button" onclick="sendBDSMS()" class="bd-send-btn"
                                style="display:inline-flex;align-items:center;gap:7px;padding:10px 18px;background:#10b981;color:#fff;border:none;border-radius:10px;font-size:13px;font-weight:600;cursor:pointer;">
                                <i class="fas fa-sms"></i> Gửi SMS rồi Lưu
                            </button>
                            <?php endif; ?>
                            <?php if (!empty($khach_hang['email'])): ?>
                            <button type="button" onclick="sendBDEmail()" class="bd-send-btn"
                                style="display:inline-flex;align-items:center;gap:7px;padding:10px 18px;background:#3b82f6;color:#fff;border:none;border-radius:10px;font-size:13px;font-weight:600;cursor:pointer;">
                                <i class="fas fa-envelope"></i> Gửi Email rồi Lưu
                            </button>
                            <?php endif; ?>
                            <button type="button" onclick="copyBDMsg()" class="bd-send-btn"
                                style="display:inline-flex;align-items:center;gap:7px;padding:10px 18px;background:#f1f5f9;color:#374151;border:1px solid #e2e8f0;border-radius:10px;font-size:13px;font-weight:600;cursor:pointer;">
                                <i class="fas fa-copy"></i> Sao chép
                            </button>
                        </div>

                        <!-- Ghi chú: voucher được tạo ngay khi submit -->
                        <div id="bdVoucherNote" style="display:none;margin-top:12px;padding:12px 16px;background:#f0fdf4;border:1px solid #86efac;border-radius:10px;font-size:13px;color:#15803d;">
                            <i class="fas fa-info-circle me-1"></i>
                            <span id="bdVoucherNoteText"></span>
                        </div>

                        <!-- Nút lưu không gửi (chỉ tạo voucher + nhật ký) -->
                        <div style="margin-top:14px;padding-top:14px;border-top:1px solid #f1f5f9;display:flex;justify-content:flex-end;gap:8px;">
                            <button type="button" class="btn-outline-qa" data-bs-dismiss="modal">Hủy</button>
                            <button type="button" onclick="submitBirthday('Ghi nhật ký')" class="btn-primary-qa" style="gap:6px;">
                                <i class="fas fa-save"></i> Chỉ lưu nhật ký<?php if (!empty($khach_hang['ngaySinh'])): ?> &amp; voucher<?php endif; ?>
                            </button>
                        </div>
                    </div>
                </div><!-- /modal-body -->
            </form>
        </div>
    </div>
</div>

<!-- ══ JS ══ -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.7.12/sweetalert2.all.min.js"></script>
<script>
<?php if (isset($_GET['rank_up']) || $rankOnLoad): ?>
window.addEventListener('DOMContentLoaded', () => {
    const hang    = <?= json_encode($khach_hang['loaiKhachHang']) ?>;
    const isVip   = hang === 'Khách hàng VIP';
    const soVC    = <?= $so_voucher_con ?>;
    const vcText  = isVip
        ? '3 voucher SC 50% + 3 voucher mua laptop -1.000.000đ'
        : '1 voucher SC 50% + 1 voucher mua laptop -1.000.000đ';
    Swal.fire({
        icon: 'success',
        title: (isVip ? '👑 Chúc mừng lên hạng VIP!' : '🥇 Chúc mừng lên hạng Thân thiết!'),
        html: `<b><?= htmlspecialchars($khach_hang['tenKH']) ?></b> vừa được <b>tự động nâng lên hạng ${hang}</b>.<br><br>
               <div style="background:#f0fdf4;border-radius:8px;padding:10px 16px;margin:8px 0;text-align:left;font-size:13px;">
                   🎁 <b>Voucher đã cấp tự động:</b><br>
                   <span style="color:#059669;">${vcText}</span>
               </div>
               <small style="color:#64748b;">Hệ thống đã ghi nhật ký tự động. Vào tab <b>Voucher</b> để xem chi tiết.</small>`,
        confirmButtonColor: '#10b981',
        confirmButtonText: '🎟️ Xem voucher ngay',
        showCancelButton: true,
        cancelButtonText: 'Đóng',
        reverseButtons: true,
    }).then(r => {
        if (r.isConfirmed) switchTab('voucher');
    });
});
<?php endif; ?>

/* ── Tabs ── */
function switchTab(tabId) {
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    const pane = document.getElementById('tab-' + tabId);
    if (pane) pane.classList.add('active');
    document.querySelectorAll('.tab-btn').forEach(b => {
        if (b.getAttribute('onclick')?.includes("'" + tabId + "'")) b.classList.add('active');
    });
}

/* ── Confirm delete ── */
function confirmDel(url, title) {
    Swal.fire({
        title: title || 'Xóa dữ liệu này?',
        text: 'Hành động này không thể hoàn tác!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        confirmButtonText: '<i class="fas fa-trash"></i> Xóa ngay',
        cancelButtonText: 'Hủy',
        reverseButtons: true
    }).then(r => { if (r.isConfirmed) window.location.href = url; });
}

/* ── Xác nhận cấp voucher thủ công ── */
function xacNhanCapVoucher() {
    const loai  = document.querySelector('input[name="loai_voucher_cap"]:checked')?.value;
    const soLuong = document.querySelector('select[name="so_luong_cap"]')?.value || 1;
    const loaiText = loai === 'sua_chua_50pct'
        ? '🔧 <b>' + soLuong + ' voucher</b> sửa chữa giảm <b>50%</b>'
        : '💻 <b>' + soLuong + ' voucher</b> mua laptop giảm <b>1.000.000đ</b>';
    Swal.fire({
        title: '🎟️ Xác nhận cấp voucher',
        html: `Bạn sắp cấp:<br><div style="margin:12px 0;font-size:15px;">${loaiText}</div>cho khách hàng <b><?= htmlspecialchars($khach_hang['tenKH']) ?></b>.<br><small style="color:#64748b;">Voucher có hiệu lực 1 năm kể từ hôm nay.</small>`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#7c3aed',
        cancelButtonColor: '#94a3b8',
        confirmButtonText: '<i class="fas fa-gift"></i> Cấp ngay',
        cancelButtonText: 'Hủy',
        reverseButtons: true,
        customClass: { popup: 'swal-voucher-popup' }
    }).then(r => {
        if (r.isConfirmed) {
            // Tạo hidden input name và submit form
            const form = document.querySelector('#capVoucherModal form');
            const btn  = document.createElement('input');
            btn.type  = 'hidden';
            btn.name  = 'cap_voucher_thu_cong';
            btn.value = '1';
            form.appendChild(btn);
            form.submit();
        }
    });
}

/* ── Print area ── */
function printArea(areaId) {
    const el = document.getElementById(areaId);
    if (!el) return;
    const w = window.open('', '_blank', 'width=900,height=650');
    w.document.write('<html><head><meta charset="UTF-8"><title>In</title>');
    w.document.write('<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">');
    w.document.write('<style>body{font-family:\'Be Vietnam Pro\',sans-serif;padding:30px;font-size:13px;}@page{margin:15mm;}</style>');
    w.document.write('</head><body>');
    w.document.write(el.innerHTML);
    w.document.write('<script>window.onload=()=>setTimeout(()=>window.print(),400);<\/script>');
    w.document.write('</body></html>');
    w.document.close();
}

/* ── Big image viewer ── */
function showBigImg(src) {
    document.getElementById('bigImgSrc').src = src;
    new bootstrap.Modal(document.getElementById('bigImgModal')).show();
}

/* ── Preview ảnh trước upload ── */
function previewImg(input, previewId) {
    const prev = document.getElementById(previewId);
    if (!prev) return;
    if (input.files && input.files[0]) {
        const r = new FileReader();
        r.onload = e => { prev.src = e.target.result; prev.style.display = 'block'; };
        r.readAsDataURL(input.files[0]);
    }
}

/* ═══════════════════════════════════════════════════
   PRODUCT ROWS – Tạo đơn hàng
═══════════════════════════════════════════════════ */
function addProductRow() {
    const row = document.createElement('div');
    row.className = 'prod-row product-row';
    row.innerHTML = `
        <input type="text"   name="maSP[]"    class="form-control form-control-sm" placeholder="Mã SP">
        <input type="text"   name="tenSP[]"   class="form-control form-control-sm" placeholder="Tên sản phẩm / dịch vụ..." oninput="dhAutoDetectLaptop(this)">
        <input type="number" name="soLuong[]" class="form-control form-control-sm text-center" value="1" min="1" oninput="calcRow(this)">
        <input type="number" name="donGia[]"  class="form-control form-control-sm text-end" placeholder="0" min="0" step="1000" oninput="calcRow(this)">
        <input type="text"   class="form-control form-control-sm text-end thanh-tien" placeholder="0 đ" readonly
            style="background:#f8fafc;color:#059669;font-weight:700;font-size:12px;">
        <button type="button" class="btn-ico del"
            onclick="this.closest('.product-row').remove();updateTotal()"
            style="width:28px;height:28px;font-size:11px;" title="Xóa dòng">
            <i class="fas fa-times"></i>
        </button>
    `;
    document.getElementById('productRows').appendChild(row);
}

function calcRow(input) {
    const row = input.closest('.product-row');
    const sl  = parseFloat(row.querySelector('[name="soLuong[]"]').value) || 0;
    const dg  = parseFloat(row.querySelector('[name="donGia[]"]').value)  || 0;
    const tt  = sl * dg;
    row.querySelector('.thanh-tien').value = tt > 0 ? tt.toLocaleString('vi-VN') + ' đ' : '';
    updateTotal();
}

/* Tự động gợi ý tích "Đơn có Laptop" khi nhập sản phẩm có từ khóa laptop */
const _laptopKeywords = ['laptop','macbook','notebook','dell','asus','acer','lenovo','hp laptop','surface','gaming book'];
function dhAutoDetectLaptop(input) {
    const val = (input.value || '').toLowerCase();
    const isLaptop = _laptopKeywords.some(kw => val.includes(kw));
    const chk = document.getElementById('chkCoLaptop');
    if (chk && isLaptop && !chk.checked) {
        chk.checked = true;
        dhOnLaptopToggle();
    }
    updateTotal();
}

/* ═══ VOUCHER ENGINE ═══ */
const _vcDhData = <?= json_encode($v_dh_json) ?>;
let _selectedVcId = 0;

/**
 * Lấy trạng thái "đơn có laptop" từ checkbox
 */
function dhHasLaptop() {
    return !!(document.getElementById('chkCoLaptop')?.checked);
}

/**
 * Callback khi toggle laptop checkbox
 * → bật/tắt voucher laptop, deselect nếu cần
 */
function dhOnLaptopToggle() {
    const hasLaptop = dhHasLaptop();
    document.querySelectorAll('.vc-card[data-laptop-only="1"]').forEach(card => {
        if (hasLaptop) {
            card.classList.remove('vc-disabled');
        } else {
            card.classList.add('vc-disabled');
            // Bỏ chọn nếu voucher laptop đang được chọn
            const vcId = parseInt(card.id.replace('vcOpt_', ''));
            if (_selectedVcId === vcId) {
                selectVoucherDH(0);
                return;
            }
        }
    });
    updateTotal();
}

/**
 * Chọn / bỏ chọn voucher
 */
function selectVoucherDH(maVoucher) {
    // Nếu voucher này là laptop-only mà chưa tick laptop → từ chối chọn
    if (maVoucher !== 0) {
        const vc = _vcDhData.find(v => v.maVoucher === maVoucher);
        const isLaptopOnly = vc && (vc.loai === 'mua_hang_1trieu' || vc.loai === 'tu_soan_mh');
        if (isLaptopOnly && !dhHasLaptop()) {
            // Highlight cảnh báo thay vì chọn
            const warnEl = document.getElementById('vcLaptopWarn');
            if (warnEl) {
                warnEl.style.display = 'block';
                warnEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                setTimeout(() => { warnEl.style.display = 'none'; }, 4000);
            }
            return;
        }
    }

    _selectedVcId = maVoucher;

    // Sync hidden radio
    document.querySelectorAll('input[name="ap_voucher_mua_hang"]').forEach(r => {
        r.checked = (parseInt(r.value) === maVoucher);
    });

    // Reset visual trên tất cả cards
    document.querySelectorAll('.vc-card').forEach(el => el.classList.remove('selected'));
    document.querySelectorAll('[id^="vcCheck_"]').forEach(c => {
        c.style.background  = '#fff';
        c.style.borderColor = '#e2e8f0';
    });

    // Highlight card được chọn
    const key = (maVoucher === 0) ? 'none' : maVoucher;
    const activeCard  = document.getElementById('vcOpt_'   + key);
    const activeCheck = document.getElementById('vcCheck_' + key);
    if (activeCard)  activeCard.classList.add('selected');
    if (activeCheck) {
        const color = (maVoucher === 0) ? '#64748b' : '#10b981';
        activeCheck.style.background  = color;
        activeCheck.style.borderColor = color;
    }

    // Ẩn cảnh báo laptop nếu đang hiện
    const warnEl = document.getElementById('vcLaptopWarn');
    if (warnEl) warnEl.style.display = 'none';

    updateTotal();
}

/**
 * Tính số tiền giảm từ một voucher (đồng nhất với PHP backend)
 */
function calcVoucherDiscount(vcId, totalAmount) {
    if (vcId === 0 || totalAmount <= 0) return 0;
    const vc = _vcDhData.find(v => v.maVoucher === vcId);
    if (!vc) return 0;

    let disc = 0;
    const loai     = vc.loai;
    const loaiGiam = vc.loaiGiam;
    const giaTriSo = vc.giaTriSo;
    const toiDa    = vc.toiDa;

    if (loai === 'mua_hang_1trieu') {
        // Voucher cố định 1 triệu, chỉ tính khi có laptop
        if (!dhHasLaptop()) return 0;
        disc = Math.min(1000000, totalAmount);

    } else if (loai === 'sinh_nhat_10pct') {
        // Sinh nhật 10% (luôn lưu loaiGiam='pct', giaTriSo=10 trong JSON)
        disc = Math.round(totalAmount * 10 / 100);
        if (toiDa > 0) disc = Math.min(disc, toiDa);

    } else if (loai === 'tu_soan_mh') {
        // Tùy chỉnh – chỉ mua hàng laptop
        if (!dhHasLaptop()) return 0;
        if (loaiGiam === 'pct' && giaTriSo > 0) {
            disc = Math.round(totalAmount * giaTriSo / 100);
            if (toiDa > 0) disc = Math.min(disc, toiDa);
        } else if (loaiGiam === 'vnd' && giaTriSo > 0) {
            disc = giaTriSo;
            if (toiDa > 0) disc = Math.min(disc, toiDa);
        }

    } else {
        // tu_soan (áp mọi đơn): % hoặc vnd
        if (loaiGiam === 'pct' && giaTriSo > 0) {
            disc = Math.round(totalAmount * giaTriSo / 100);
            if (toiDa > 0) disc = Math.min(disc, toiDa);
        } else if (loaiGiam === 'vnd' && giaTriSo > 0) {
            disc = giaTriSo;
            if (toiDa > 0) disc = Math.min(disc, toiDa);
        }
    }

    return Math.min(Math.max(0, disc), totalAmount);
}

function fmt(n) { return Math.round(n).toLocaleString('vi-VN') + 'đ'; }

/**
 * Cập nhật toàn bộ UI tổng tiền
 */
function updateTotal() {
    // Tính tổng tiền hàng
    let total = 0;
    document.querySelectorAll('#productRows .product-row').forEach(row => {
        const sl = parseFloat(row.querySelector('[name="soLuong[]"]')?.value) || 0;
        const dg = parseFloat(row.querySelector('[name="donGia[]"]')?.value)  || 0;
        total += sl * dg;
    });

    // Tổng hàng
    const elBefore = document.getElementById('grandTotalBefore');
    if (elBefore) elBefore.textContent = total.toLocaleString('vi-VN') + ' đ';

    // Cập nhật số tiền tiết kiệm trên từng voucher card (preview nhỏ góc phải)
    _vcDhData.forEach(vc => {
        const saving = calcVoucherDiscount(vc.maVoucher, total);
        const el = document.getElementById('vcSaving_' + vc.maVoucher);
        if (!el) return;
        if (saving > 0) {
            el.textContent = '–' + fmt(saving);
            el.classList.add('active');
        } else {
            el.textContent = '–0đ';
            el.classList.remove('active');
        }
    });

    // Tính giảm theo voucher đang chọn
    const discount     = calcVoucherDiscount(_selectedVcId, total);
    const afterDiscount = Math.max(0, total - discount);

    // Tổng thực thanh toán
    const elTotal = document.getElementById('grandTotal');
    if (elTotal) elTotal.textContent = afterDiscount.toLocaleString('vi-VN') + ' đ';

    // Dòng giảm voucher
    const discRow   = document.getElementById('voucherDiscountRow');
    const discAmt   = document.getElementById('voucherDiscountAmt');
    const discLabel = document.getElementById('vcDiscLabel');
    if (discRow) {
        if (discount > 0) {
            discRow.style.display = 'flex';
            if (discAmt) discAmt.textContent = '–' + fmt(discount);
            // Thêm nhãn % hoặc vnd
            if (discLabel && _selectedVcId !== 0) {
                const vc = _vcDhData.find(v => v.maVoucher === _selectedVcId);
                if (vc) {
                    discLabel.textContent = vc.loaiGiam === 'pct'
                        ? '–' + vc.giaTriSo + '%'
                        : '–' + fmt(Math.min(vc.giaTriSo, vc.toiDa > 0 ? vc.toiDa : vc.giaTriSo));
                }
            }
        } else {
            discRow.style.display = 'none';
        }
    }

    // Banner áp voucher thành công
    const preview     = document.getElementById('vcApplyPreview');
    const previewSave = document.getElementById('vcPreviewSave');
    if (preview) {
        if (_selectedVcId !== 0 && discount > 0) {
            preview.style.display = 'block';
            if (previewSave) previewSave.textContent = fmt(discount);
        } else {
            preview.style.display = 'none';
        }
    }

    // Cảnh báo laptop nếu voucher laptop-only được chọn mà chưa tick laptop
    const warnEl = document.getElementById('vcLaptopWarn');
    if (warnEl) {
        if (_selectedVcId !== 0) {
            const vc = _vcDhData.find(v => v.maVoucher === _selectedVcId);
            const isLO = vc && (vc.loai === 'mua_hang_1trieu' || vc.loai === 'tu_soan_mh');
            warnEl.style.display = (isLO && !dhHasLaptop()) ? 'block' : 'none';
        } else {
            warnEl.style.display = 'none';
        }
    }
}

/* ── Validate trước khi submit ── */
document.getElementById('formTaoDH')?.addEventListener('submit', function(e) {
    const rows = document.querySelectorAll('#productRows .product-row');
    let hasProduct = false;
    rows.forEach(row => {
        const ten = row.querySelector('[name="tenSP[]"]')?.value.trim();
        if (ten) hasProduct = true;
    });
    if (!hasProduct) {
        e.preventDefault();
        Swal.fire({ icon: 'warning', title: 'Chưa có sản phẩm', text: 'Vui lòng nhập ít nhất 1 sản phẩm hoặc dịch vụ.', confirmButtonColor: '#10b981' });
        return;
    }
    // Cảnh báo nếu voucher laptop mà chưa tick laptop (vẫn cho submit)
    if (_selectedVcId !== 0) {
        const vc = _vcDhData.find(v => v.maVoucher === _selectedVcId);
        const isLO = vc && (vc.loai === 'mua_hang_1trieu' || vc.loai === 'tu_soan_mh');
        if (isLO && !dhHasLaptop()) {
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                title: '⚠️ Voucher Laptop chưa hợp lệ',
                html: 'Voucher bạn chọn chỉ áp dụng cho đơn có Laptop.<br>Hãy tích ô <b>Đơn có Laptop</b> hoặc chọn voucher khác.',
                confirmButtonColor: '#f59e0b',
                confirmButtonText: 'Đã hiểu, sửa lại'
            });
        }
    }
});

/* ── Reset khi đóng modal ── */
document.getElementById('addDHModal')?.addEventListener('hidden.bs.modal', function() {
    _selectedVcId = 0;
    // Reset checkbox laptop
    const chk = document.getElementById('chkCoLaptop');
    if (chk) { chk.checked = false; dhOnLaptopToggle(); }
    // Reset voucher selection
    selectVoucherDH(0);
    // Reset product rows về 1 dòng trống
    const pr = document.getElementById('productRows');
    if (pr) pr.innerHTML = `<div class="prod-row product-row">
        <input type="text" name="maSP[]" class="form-control form-control-sm" placeholder="Mã SP">
        <input type="text" name="tenSP[]" class="form-control form-control-sm" placeholder="Tên sản phẩm / dịch vụ..." required oninput="dhAutoDetectLaptop(this)">
        <input type="number" name="soLuong[]" class="form-control form-control-sm text-center" value="1" min="1" oninput="calcRow(this)">
        <input type="number" name="donGia[]" class="form-control form-control-sm text-end" placeholder="0" min="0" step="1000" oninput="calcRow(this)">
        <input type="text" class="form-control form-control-sm text-end thanh-tien" placeholder="0 đ" readonly style="background:#f8fafc;color:#059669;font-weight:700;font-size:12px;">
        <button type="button" class="btn-ico del" onclick="this.closest('.product-row').remove();updateTotal()" style="width:28px;height:28px;font-size:11px;" title="Xóa dòng"><i class="fas fa-times"></i></button>
    </div>`;
    updateTotal();
});

/* ── Khởi tạo ── */
document.addEventListener('DOMContentLoaded', function() {
    selectVoucherDH(0);
    updateTotal();
});




/* ── Preview giảm voucher sửa chữa (realtime) ── */
function previewGiamSC() {
    const chiPhi   = parseFloat(document.getElementById('chiPhiInput')?.value) || 0;
    const vRadio   = document.querySelector('input[name="ap_voucher_sua_chua"]:checked');
    const vVal     = vRadio ? parseInt(vRadio.value) : 0;

    const boxSau   = document.getElementById('chiPhiSauGiamBox');
    const elGoc    = document.getElementById('chiPhiGocHien');
    const elGiam   = document.getElementById('chiPhiGiamHien');
    const elSau    = document.getElementById('chiPhiSauGiam');

    if (vVal > 0 && chiPhi > 0) {
        const giam = Math.min(Math.round(chiPhi * 0.5), 500000);
        const sau  = Math.max(0, chiPhi - giam);
        if (elGoc)  elGoc.textContent  = chiPhi.toLocaleString('vi-VN') + 'đ';
        if (elGiam) elGiam.textContent = giam.toLocaleString('vi-VN') + 'đ';
        if (elSau)  elSau.textContent  = sau.toLocaleString('vi-VN') + ' đ';
        if (boxSau) boxSau.style.display = 'block';
    } else {
        if (boxSau) boxSau.style.display = 'none';
    }
}

/* ── In lịch sử ── */
function printLichSu() {
    const tenKH = <?= json_encode($khach_hang['tenKH']) ?>;
    function tblHtml(id) {
        const t = document.getElementById(id);
        if (!t) return '<p><i>Không có dữ liệu.</i></p>';
        return '<table border="1" cellpadding="7" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:12px;">' + t.innerHTML + '</table>';
    }
    const ngayIn = new Date().toLocaleDateString('vi-VN');
    const html = `<div style="text-align:center;margin-bottom:20px;">
        <h2 style="color:#059669;margin:0;">QA TECH</h2>
        <h3 style="margin:6px 0 2px;">HỒ SƠ LỊCH SỬ KHÁCH HÀNG</h3>
        <p style="color:#64748b;margin:0;">Ngày in: ${ngayIn}</p>
    </div>
    <table style="width:100%;margin-bottom:18px;font-size:13px;">
        <tr><td><b>Khách hàng:</b> ${tenKH}</td>
            <td><b>SĐT:</b> <?= htmlspecialchars($khach_hang['soDienThoai'] ?? '') ?></td>
            <td><b>Email:</b> <?= htmlspecialchars($khach_hang['email'] ?? '') ?></td></tr>
    </table>
    <h4 style="background:#f0fdf4;padding:8px 12px;border-left:4px solid #059669;">1. Đơn Hàng</h4>${tblHtml('tblDonHang')}
    <h4 style="background:#fff7ed;padding:8px 12px;border-left:4px solid #f97316;">2. Phiếu Sửa Chữa</h4>${tblHtml('tblSuaChua')}
    <h4 style="background:#eff6ff;padding:8px 12px;border-left:4px solid #3b82f6;">3. Bảo Hành</h4>${tblHtml('tblBaoHanh')}`;
    const w = window.open('', '_blank', 'width=1000,height=700');
    w.document.write(`<html><head><meta charset="UTF-8"><title>Ho So ${tenKH}</title>
        <style>body{font-family:Arial,sans-serif;padding:30px;color:#1e293b;}th{background:#f1f5f9;}tr:nth-child(even){background:#f8fafc;}</style>
        </head><body>${html}<script>window.onload=()=>setTimeout(()=>window.print(),400);<\/script></body></html>`);
    w.document.close();
}

/* ── Xuất Excel ── */
function exportExcelLichSu() {
    const tenKH = <?= json_encode($khach_hang['tenKH']) ?>;
    function tblToArr(id) {
        const t = document.getElementById(id);
        if (!t) return [[]];
        return Array.from(t.querySelectorAll('tr')).map(tr =>
            Array.from(tr.querySelectorAll('th,td')).map(c => c.innerText.trim())
        );
    }
    const wb = XLSX.utils.book_new();
    const infoData = [
        ['HO SO KHACH HANG - QA TECH'],[], 
        ['Ten','<?= htmlspecialchars($khach_hang['tenKH']) ?>'],
        ['SDT','<?= htmlspecialchars($khach_hang['soDienThoai'] ?? '') ?>'],
        ['Email','<?= htmlspecialchars($khach_hang['email'] ?? '') ?>'],
        ['Dia chi','<?= htmlspecialchars($khach_hang['diaChi'] ?? '') ?>'],
        ['Loai','<?= htmlspecialchars($khach_hang['loaiKhachHang'] ?? '') ?>'],
        ['Ngay xuat', new Date().toLocaleDateString('vi-VN')]
    ];
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(infoData), 'Thong Tin KH');
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(tblToArr('tblDonHang')), 'Don Hang');
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(tblToArr('tblSuaChua')), 'Sua Chua');
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(tblToArr('tblBaoHanh')), 'Bao Hanh');
    XLSX.writeFile(wb, 'LichSu_' + tenKH.replace(/\s+/g,'_') + '_' + new Date().toISOString().slice(0,10) + '.xlsx');
}

/* ── Stat bar click → switch tab ── */
window.addEventListener('DOMContentLoaded', () => {
    <?php if ($activeTab !== 'nhat-ky'): ?>
    // restore active tab from URL param handled via PHP class above
    <?php endif; ?>

    const today = new Date().toISOString().split('T')[0];

    /* ── Set max="today" cho tất cả date inputs cần kiểm tra ── */
    // ngayDangKy không có trong UI (auto từ DB), nhưng nếu có thì cũng giới hạn
    // Các trường ngày đặt hàng, ngày nhận sửa chữa
    document.querySelectorAll('input[name="ngayDat"], input[name="ngayNhan"]').forEach(el => {
        el.setAttribute('max', today);
    });

    /* ── Validation helper ── */
    function showErr(input, msg) {
        clearErr(input);
        input.classList.add('is-invalid');
        const d = document.createElement('div');
        d.className = 'invalid-feedback qa-err';
        d.textContent = msg;
        input.parentNode.appendChild(d);
    }
    function clearErr(input) {
        input.classList.remove('is-invalid');
        input.classList.remove('is-valid');
        const old = input.parentNode.querySelector('.qa-err');
        if (old) old.remove();
    }
    function showOk(input) {
        clearErr(input);
        input.classList.add('is-valid');
    }

    /* ── Validate SĐT: đúng 10 chữ số ── */
    function validatePhone(input) {
        const v = input.value.trim();
        if (!v) { clearErr(input); return true; } // không bắt buộc nếu trống
        if (!/^[0-9]{10}$/.test(v)) {
            showErr(input, 'Số điện thoại phải đúng 10 chữ số (0xxxxxxxxx)');
            return false;
        }
        showOk(input); return true;
    }

    /* ── Validate Email: phải có @ ── */
    function validateEmail(input) {
        const v = input.value.trim();
        if (!v) { clearErr(input); return true; } // không bắt buộc nếu trống
        if (!v.includes('@') || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) {
            showErr(input, 'Email không hợp lệ, phải có dạng example@domain.com');
            return false;
        }
        showOk(input); return true;
    }

    /* ── Validate Date ≤ hôm nay ── */
    function validateDateNotFuture(input, label) {
        const v = input.value;
        if (!v) return true;
        if (v > today) {
            showErr(input, (label || 'Ngày') + ' không được lớn hơn ngày hiện tại (' + today.split('-').reverse().join('/') + ')');
            return false;
        }
        showOk(input); return true;
    }

    /* ── Gắn sự kiện inline validation ── */
    document.querySelectorAll('input[name="soDienThoai"]').forEach(el => {
        el.addEventListener('input', () => validatePhone(el));
        el.addEventListener('blur',  () => validatePhone(el));
    });
    document.querySelectorAll('input[name="email"], input[type="email"]').forEach(el => {
        el.addEventListener('input', () => validateEmail(el));
        el.addEventListener('blur',  () => validateEmail(el));
    });
    document.querySelectorAll('input[name="ngayDat"], input[name="ngayNhan"]').forEach(el => {
        const lbl = el.name === 'ngayDat' ? 'Ngày đặt hàng' : 'Ngày nhận';
        el.addEventListener('change', () => validateDateNotFuture(el, lbl));
    });
    document.querySelectorAll('input[name="ngayDangKy"]').forEach(el => {
        el.addEventListener('change', () => validateDateNotFuture(el, 'Ngày đăng ký'));
    });

    /* ── Chặn submit các form có lỗi ── */
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function(e) {
            let ok = true;

            // Phone
            const phones = form.querySelectorAll('input[name="soDienThoai"]');
            phones.forEach(el => { if (!validatePhone(el)) ok = false; });

            // Email
            const emails = form.querySelectorAll('input[name="email"], input[type="email"]');
            emails.forEach(el => { if (!validateEmail(el)) ok = false; });

            // Ngày đặt / ngày nhận / ngày đăng ký ≤ hôm nay
            const datesToCheck = form.querySelectorAll('input[name="ngayDat"], input[name="ngayNhan"], input[name="ngayDangKy"]');
            datesToCheck.forEach(el => {
                const lblMap = { ngayDat: 'Ngày đặt hàng', ngayNhan: 'Ngày nhận', ngayDangKy: 'Ngày đăng ký' };
                if (!validateDateNotFuture(el, lblMap[el.name] || 'Ngày')) ok = false;
            });

            if (!ok) {
                e.preventDefault();
                // Scroll đến lỗi đầu tiên trong modal/form
                const firstErr = form.querySelector('.is-invalid');
                if (firstErr) firstErr.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
    });
});

/* ── Tính ngày kết thúc bảo hành (Modal THÊM) ── */
function calcWarrantyEndAdd() {
    const startInput = document.querySelector('#addBHModal input[name="ngayBatDau"]');
    const select     = document.getElementById('thoiHanSelectAdd');
    const endInput   = document.getElementById('ngayKetThucBHAdd');
    if (!startInput || !select || !endInput) return;
    const start = new Date(startInput.value);
    if (isNaN(start.getTime())) return;
    const val = select.value;
    const months = parseInt(val);
    if (!isNaN(months)) {
        const end = new Date(start);
        end.setMonth(end.getMonth() + months);
        endInput.value = end.toISOString().split('T')[0];
    }
}

/* ── Tính ngày kết thúc bảo hành (Modal SỬA) ── */
function calcWarrantyEndEdit(id) {
    const form     = document.querySelector('#edtBH_' + id);
    const startEl  = form ? form.querySelector('input[name="ngayBatDau"]') : null;
    const selectEl = document.getElementById('thoiHanSelectEdit_' + id);
    const endEl    = document.getElementById('ngayKetThucEdit_' + id);
    if (!startEl || !selectEl || !endEl) return;
    const start = new Date(startEl.value);
    if (isNaN(start.getTime())) return;
    const months = parseInt(selectEl.value);
    if (!isNaN(months)) {
        const end = new Date(start);
        end.setMonth(end.getMonth() + months);
        endEl.value = end.toISOString().split('T')[0];
    }
}

/* ── Lưu phiếu bảo hành (in ra cửa sổ mới để Save as PDF) ── */
function saveBHPDF(id) {
    const el = document.getElementById('printBHArea_' + id);
    if (!el) return;
    const w = window.open('', '_blank', 'width=900,height=700');
    w.document.write('<html><head><meta charset="UTF-8"><title>Phieu Bao Hanh BH-' + id + '</title>');
    w.document.write('<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css" rel="stylesheet">');
    w.document.write('<style>body{font-family:Arial,sans-serif;padding:30px;font-size:13px;}pre{font-family:Arial,sans-serif;}@page{margin:12mm;size:A4;}</style>');
    w.document.write('</head><body>');
    w.document.write(el.innerHTML);
    w.document.write('<div style="margin-top:20px;text-align:center;font-size:11px;color:#94a3b8;border-top:1px solid #e2e8f0;padding-top:10px;">Để lưu PDF: Nhấn Ctrl+P → Chọn "Save as PDF"</div>');
    w.document.write('<script>window.onload=()=>setTimeout(()=>{window.print();},500);<\/script>');
    w.document.write('</body></html>');
    w.document.close();
}
/* ========== HÀNH ĐỘNG CHO THÔNG BÁO ========== */
function scrollToForm() {
    // Cuộn đến form thêm nhật ký ở sidebar
    const quickForm = document.querySelector('.panel-card:has(.quick-form)');
    if (quickForm) {
        quickForm.scrollIntoView({ behavior: 'smooth', block: 'center' });
        quickForm.style.boxShadow = '0 0 0 3px #f59e0b';
        setTimeout(() => {
            quickForm.style.boxShadow = '';
        }, 2000);
    }
}

function openEditModal() {
    // Mở modal sửa thông tin khách hàng
    const editModal = new bootstrap.Modal(document.getElementById('editKHModal'));
    editModal.show();
}

function openBaoHanhModal() {
    // Mở modal thêm bảo hành
    const bhModal = new bootstrap.Modal(document.getElementById('addBHModal'));
    bhModal.show();
}

function switchToKyThuatTab() {
    // Chuyển sang tab Kỹ thuật
    switchTab('ky-thuat');
    // Highlight bảng sửa chữa
    setTimeout(() => {
        const repairTable = document.getElementById('tblSuaChua');
        if (repairTable) {
            repairTable.scrollIntoView({ behavior: 'smooth', block: 'start' });
            repairTable.style.boxShadow = '0 0 0 3px #f59e0b';
            setTimeout(() => {
                repairTable.style.boxShadow = '';
            }, 2000);
        }
    }, 300);
}

function switchToVoucherTab() {
    // Chuyển sang tab Voucher
    switchTab('voucher');
    setTimeout(() => {
        const voucherSection = document.querySelector('#tab-voucher');
        if (voucherSection) {
            voucherSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }, 300);
}

function openBirthdayModal() {
    // Nếu chưa có ngày sinh, mở modal sửa hồ sơ
    <?php if (empty($khach_hang['ngaySinh'])): ?>
    Swal.fire({icon:'info',title:'Chưa có ngày sinh',text:'Vui lòng cập nhật ngày sinh trong hồ sơ khách hàng trước.',confirmButtonColor:'#10b981',confirmButtonText:'Cập nhật ngay'}).then(r=>{ if(r.isConfirmed) openEditModal(); });
    return;
    <?php endif; ?>
    setBDTemplate('chuc');
    new bootstrap.Modal(document.getElementById('birthdayModal')).show();
}

/* ── Birthday data ── */
const bdTenKH  = <?= json_encode($khach_hang['tenKH']) ?>;
const bdNgaySinh = <?= json_encode(!empty($khach_hang['ngaySinh']) ? date('d/m/Y', strtotime($khach_hang['ngaySinh'])) : '') ?>;
const bdSdt    = <?= json_encode($khach_hang['soDienThoai'] ?? '') ?>;
const bdEmail  = <?= json_encode($khach_hang['email'] ?? '') ?>;

/* ── Mẫu tin nhắn ── */
const bdTemplates = {
    chuc: `🎂 Chúc mừng sinh nhật ${bdTenKH}!\n\nThay mặt toàn thể đội ngũ QA Tech, chúng tôi xin gửi đến bạn lời chúc mừng sinh nhật nồng nhiệt nhất! 🎉\n\nChúc bạn một ngày sinh nhật thật vui vẻ, hạnh phúc bên gia đình và người thân. Mong rằng năm mới này bạn sẽ luôn mạnh khỏe, thành công và mọi điều ước đều thành hiện thực! 🌟\n\n💚 QA Tech – Đồng hành cùng bạn!`,

    uudai: `🎂 Chúc mừng sinh nhật ${bdTenKH}!\n\nNhân ngày sinh nhật đặc biệt của bạn, QA Tech xin tặng bạn:\n\n🎁 ƯU ĐÃI SINH NHẬT – GIẢM 10%\nÁp dụng cho toàn bộ dịch vụ sửa chữa & mua hàng\n📅 Hiệu lực trong vòng 30 ngày kể từ ngày sinh nhật\n\nBạn đã có voucher này trong hồ sơ tại QA Tech rồi nhé, mang theo số điện thoại này là được hưởng ưu đãi! 😊\n\nChúc bạn một ngày sinh nhật thật vui và tràn đầy hạnh phúc! 🎉\n\n📞 Liên hệ: 0934322199\n💚 QA Tech – Máy tính Quang Anh`,

    voucher: `🎂 Chúc mừng sinh nhật ${bdTenKH}!\n\nNhân dịp sinh nhật, QA Tech trân trọng tặng bạn:\n\n🎟️ VOUCHER SINH NHẬT ĐẶC BIỆT\n✅ Giảm 50% chi phí sửa chữa lần tới\n✅ Tối đa giảm 500.000đ\n📅 Hiệu lực 30 ngày kể từ ngày sinh nhật\n\nVoucher đã được lưu vào hồ sơ của bạn tại QA Tech. Khi đến cửa hàng hoặc gọi điện nhắc số điện thoại này là được áp dụng ngay nhé! 🥳\n\nChúc bạn sinh nhật vui vẻ!\n💚 QA Tech – Máy tính Quang Anh\n📞 0934322199`
};

/* map template → loaiUuDai gửi lên server */
const bdLoaiMap = { chuc: '', uudai: 'uudai_10pct', voucher: 'voucher_sua_chua', tu_soan: '__tu_soan__' };

let currentBDTemplate = 'chuc';

function setBDTemplate(type) {
    currentBDTemplate = type;
    const isTuSoan = (type === 'tu_soan');

    // Hiện / ẩn panel tự soạn voucher
    document.getElementById('panelTuSoanVoucher').style.display = isTuSoan ? 'block' : 'none';

    // Hiện / ẩn textarea tương ứng
    document.getElementById('wrapBdMsgTemplate').style.display = isTuSoan ? 'none' : 'block';
    document.getElementById('wrapBdMsgCustom').style.display   = isTuSoan ? 'block' : 'none';

    // Điền mẫu tin nhắn nếu không phải tự soạn
    const area = document.getElementById('bdMessageContent');
    if (!isTuSoan && area) area.value = bdTemplates[type] || '';

    // Style labels
    ['chuc','uudai','voucher','tu_soan'].forEach(t => {
        const lbl = document.getElementById('tplLbl_' + t);
        if (!lbl) return;
        if (t === type) {
            const colors = {
                chuc:    ['#ec4899','#fce7f3'],
                uudai:   ['#fbbf24','#fffbeb'],
                voucher: ['#a78bfa','#f5f3ff'],
                tu_soan: ['#22d3ee','#ecfeff']
            };
            lbl.style.borderColor = colors[t][0];
            lbl.style.background  = colors[t][1];
        } else {
            lbl.style.borderColor = '#e2e8f0';
            lbl.style.background  = '#fff';
        }
    });

    // Hiển thị note về voucher
    const note = document.getElementById('bdVoucherNote');
    const noteText = document.getElementById('bdVoucherNoteText');
    if (note && noteText) {
        if (type === 'uudai') {
            noteText.innerHTML = '✅ Khi lưu, hệ thống sẽ <strong>tự động tạo voucher sinh nhật Giảm 10%</strong> (hạn 30 ngày) vào tab Voucher của khách hàng.';
            note.style.display = 'block';
        } else if (type === 'voucher') {
            noteText.innerHTML = '✅ Khi lưu, hệ thống sẽ <strong>tự động tạo voucher sửa chữa 50%</strong> (hạn 30 ngày, tối đa 500.000đ) vào tab Voucher của khách hàng.';
            note.style.display = 'block';
        } else if (type === 'tu_soan') {
            noteText.innerHTML = '✅ Khi lưu, hệ thống sẽ tạo <strong>voucher theo thông số bạn nhập</strong> và lưu vào tab Voucher. Voucher tự soạn áp dụng được cho đơn hàng & sửa chữa tiếp theo.';
            note.style.display = 'block';
        } else {
            note.style.display = 'none';
        }
    }
}

/* ── Preview voucher tùy chỉnh ── */
function toggleVoucherValueInput() {
    const loai = document.getElementById('loaiGiaTriVcSel').value;
    const lbl  = document.getElementById('labelGiaTriVc');
    const unit = document.getElementById('giaTriVcUnit');
    const inp  = document.getElementById('giaTriVcInput');
    if (loai === 'pct') {
        lbl.textContent  = 'Mức giảm (%)';
        unit.textContent = '%';
        inp.placeholder  = 'VD: 20';
        inp.max = 100;
    } else {
        lbl.textContent  = 'Số tiền giảm (đ)';
        unit.textContent = 'đ';
        inp.placeholder  = 'VD: 200000';
        inp.removeAttribute('max');
    }
    updateCustomVoucherNote();
}

function updateCustomVoucherNote() {
    const ten       = document.getElementById('tenVcInput').value.trim() || 'Voucher tùy chỉnh';
    const loai      = document.getElementById('loaiGiaTriVcSel').value;
    const giaTriRaw = document.getElementById('giaTriVcInput').value;
    const toiDa     = document.getElementById('soTienToiDaInput').value;
    const han       = document.getElementById('ngayHetHanVcInput').value;
    const apDung    = document.getElementById('loaiVcTuSoanSel');
    const apDungText= apDung ? apDung.options[apDung.selectedIndex].text : '';

    let giaTriText = '???';
    if (giaTriRaw) {
        if (loai === 'pct') giaTriText = giaTriRaw + '%';
        else giaTriText = Number(giaTriRaw).toLocaleString('vi-VN') + 'đ';
    }
    let toiDaText = (toiDa && Number(toiDa) > 0) ? ' (tối đa ' + Number(toiDa).toLocaleString('vi-VN') + 'đ)' : '';
    let hanText   = han ? new Date(han).toLocaleDateString('vi-VN') : '???';

    document.getElementById('previewVcText').innerHTML =
        `<strong>${ten}</strong> – Giảm <strong style="color:#0e7490;">${giaTriText}</strong>${toiDaText} · Áp dụng: ${apDungText} · HSD: ${hanText}`;
}

/* ── Validate và chuẩn bị submit tự soạn ── */
function prepareTuSoanSubmit(kenh) {
    const ten    = document.getElementById('tenVcInput').value.trim();
    const loai   = document.getElementById('loaiGiaTriVcSel').value;
    const gt     = document.getElementById('giaTriVcInput').value;
    const toiDa  = document.getElementById('soTienToiDaInput').value || '0';
    const han    = document.getElementById('ngayHetHanVcInput').value;
    const loaiVc = document.getElementById('loaiVcTuSoanSel').value;
    const msg    = document.getElementById('bdMessageCustom').value.trim();

    if (!ten) { Swal.fire({icon:'warning',title:'Thiếu tên voucher',text:'Vui lòng nhập tên / tiêu đề voucher.',confirmButtonColor:'#0891b2'}); return false; }
    if (!gt || Number(gt) <= 0) { Swal.fire({icon:'warning',title:'Thiếu giá trị giảm',text:'Vui lòng nhập mức giảm hợp lệ.',confirmButtonColor:'#0891b2'}); return false; }
    if (!han) { Swal.fire({icon:'warning',title:'Thiếu hạn sử dụng',text:'Vui lòng chọn ngày hết hạn voucher.',confirmButtonColor:'#0891b2'}); return false; }

    // Set mode tự soạn
    document.getElementById('hdBdMode').value       = 'tu_soan';
    document.getElementById('hdTenVc').value        = ten;
    document.getElementById('hdLoaiVcTuSoan').value  = loaiVc;
    document.getElementById('hdLoaiGiaTriVc').value  = loai;
    document.getElementById('hdGiaTriVc').value      = gt;
    document.getElementById('hdSoTienToiDa').value   = toiDa;
    document.getElementById('hdNgayHetHanVc').value  = han;
    document.getElementById('hdBdMsgCustom').value   = msg;
    document.getElementById('hdKenhGui').value        = kenh;
    return true;
}

/* Hàm submit chính – ghi kênh gửi rồi submit form */
function submitBirthday(kenh) {
    if (currentBDTemplate === 'tu_soan') {
        if (!prepareTuSoanSubmit(kenh)) return;
        document.getElementById('formBirthday').submit();
        return;
    }
    // Mode tiêu chuẩn
    const loai = bdLoaiMap[currentBDTemplate] || '';
    document.getElementById('hdBdMode').value      = 'standard';
    document.getElementById('hdLoaiUuDai').value   = loai;
    document.getElementById('hdKenhGui').value     = kenh;
    document.getElementById('hdNoiDungLog').value  = '';
    document.getElementById('formBirthday').submit();
}

function sendBDZalo() {
    const isTuSoan = currentBDTemplate === 'tu_soan';
    const msg = isTuSoan
        ? (document.getElementById('bdMessageCustom')?.value || '')
        : (document.getElementById('bdMessageContent')?.value || '');
    if (!msg.trim() && !isTuSoan) { Swal.fire({icon:'warning',title:'Chưa có nội dung',confirmButtonColor:'#10b981'}); return; }
    if (msg.trim()) navigator.clipboard?.writeText(msg).catch(()=>{});
    window.open('https://zalo.me/' + bdSdt.replace(/^0/,'84'), '_blank');
    Swal.fire({
        icon:'info', title:'Đã mở Zalo!',
        html: msg.trim() ? 'Nội dung đã sao chép. Dán vào chat Zalo rồi nhấn <b>Đã gửi</b> để lưu vào hệ thống.' : 'Mở Zalo xong nhấn <b>Đã gửi</b> để lưu vào hệ thống.',
        confirmButtonColor:'#0068ff', confirmButtonText:'✅ Đã gửi – Lưu vào hệ thống',
        showCancelButton:true, cancelButtonText:'Hủy', reverseButtons:true
    }).then(r => { if (r.isConfirmed) submitBirthday('Zalo'); });
}

function sendBDSMS() {
    const isTuSoan = currentBDTemplate === 'tu_soan';
    const msg = isTuSoan
        ? (document.getElementById('bdMessageCustom')?.value || '')
        : (document.getElementById('bdMessageContent')?.value || '');
    if (!msg.trim() && !isTuSoan) { Swal.fire({icon:'warning',title:'Chưa có nội dung',confirmButtonColor:'#10b981'}); return; }
    window.location.href = 'sms:' + bdSdt + '?body=' + encodeURIComponent(msg);
    setTimeout(() => {
        Swal.fire({
            icon:'question', title:'Đã gửi SMS chưa?',
            text:'Xác nhận để lưu nhật ký và voucher vào hệ thống.',
            confirmButtonColor:'#10b981', confirmButtonText:'✅ Đã gửi – Lưu vào hệ thống',
            showCancelButton:true, cancelButtonText:'Chưa gửi', reverseButtons:true
        }).then(r => { if (r.isConfirmed) submitBirthday('SMS'); });
    }, 1500);
}

function sendBDEmail() {
    const isTuSoan = currentBDTemplate === 'tu_soan';
    const msg = isTuSoan
        ? (document.getElementById('bdMessageCustom')?.value || '')
        : (document.getElementById('bdMessageContent')?.value || '');
    const subject = encodeURIComponent('🎂 Chúc mừng sinh nhật ' + bdTenKH + '! – QA Tech');
    window.location.href = 'mailto:' + bdEmail + '?subject=' + subject + '&body=' + encodeURIComponent(msg);
    setTimeout(() => {
        Swal.fire({
            icon:'question', title:'Đã gửi Email chưa?',
            text:'Xác nhận để lưu nhật ký và voucher vào hệ thống.',
            confirmButtonColor:'#3b82f6', confirmButtonText:'✅ Đã gửi – Lưu vào hệ thống',
            showCancelButton:true, cancelButtonText:'Chưa gửi', reverseButtons:true
        }).then(r => { if (r.isConfirmed) submitBirthday('Email'); });
    }, 1500);
}

function copyBDMsg() {
    const isTuSoan = currentBDTemplate === 'tu_soan';
    const msg = isTuSoan
        ? (document.getElementById('bdMessageCustom')?.value || '')
        : (document.getElementById('bdMessageContent')?.value || '');
    if (!msg.trim()) { Swal.fire({icon:'warning',title:'Chưa có nội dung',confirmButtonColor:'#10b981'}); return; }
    const doFallback = () => {
        const el = document.createElement('textarea'); el.value = msg;
        document.body.appendChild(el); el.select(); document.execCommand('copy'); document.body.removeChild(el);
    };
    (navigator.clipboard?.writeText(msg) || Promise.reject()).catch(doFallback).finally(() => {
        Swal.fire({icon:'success',title:'Đã sao chép!',text:'Dán vào kênh gửi, xong nhấn Lưu nhật ký & voucher bên dưới.',timer:2000,showConfirmButton:false});
    });
    navigator.clipboard?.writeText(msg).then(() => {
        Swal.fire({icon:'success',title:'Đã sao chép!',text:'Dán vào kênh gửi, xong nhấn Lưu nhật ký & voucher bên dưới.',timer:2000,showConfirmButton:false});
    }).catch(doFallback);
}

/* Hiển thị thông báo bd_done */
<?php if (isset($_GET['bd_done'])): ?>
window.addEventListener('DOMContentLoaded', () => {
    Swal.fire({
        icon:'success',
        title:'🎂 Đã lưu thành công!',
        html:`Nhật ký giao tiếp đã được ghi.<br><?php if (!empty($_GET['bd_done']) && isset($birthdayAlert)): ?>Voucher sinh nhật đã được tạo vào tab <b>Voucher</b>.<?php endif; ?>`,
        confirmButtonColor:'#10b981',
        confirmButtonText:'Xem Voucher',
        showCancelButton:true, cancelButtonText:'Đóng', reverseButtons:true,
        timer:6000, timerProgressBar:true
    }).then(r => { if (r.isConfirmed) switchTab('voucher'); });
});
<?php endif; ?>
</script>
<script>
/* ── Polling thông báo đơn hàng online ── */
(function() {
    const badge = document.getElementById('online-order-badge');
    if (!badge) return;
    function check() {
        fetch('notification_api.php').then(r=>r.json()).then(d=>{
            if (d.count > 0) {
                badge.textContent = d.count;
                badge.style.display = 'inline-flex';
                badge.style.opacity = '1';
                badge.style.alignItems = 'center';
                badge.style.justifyContent = 'center';
            } else {
                badge.style.display = 'none';
            }
        }).catch(()=>{});
    }
    check();
    setInterval(check, 30000);
})();
</script>
</body>
</html>