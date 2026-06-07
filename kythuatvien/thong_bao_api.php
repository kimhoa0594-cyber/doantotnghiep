<?php
/**
 * thong_bao_api.php
 * API thông báo phân công kỹ thuật viên
 * Đặt trong cùng thư mục kythuatvien/
 */
session_start();
require_once '../db.php';
date_default_timezone_set('Asia/Ho_Chi_Minh');

header('Content-Type: application/json; charset=utf-8');

// ── Hàm lấy tên KTV chính xác (ưu tiên tenKTV trong bảng ky_thuat_vien) ──
function getKtvName(): string {
    global $conn;
    // Ưu tiên 1: lấy tenKTV từ bảng ky_thuat_vien theo email session
    $email = $_SESSION['email'] ?? '';
    if ($email) {
        $chk = $conn->query("SHOW TABLES LIKE 'ky_thuat_vien'");
        if ($chk && $chk->num_rows > 0) {
            $s = $conn->prepare("SELECT tenKTV FROM ky_thuat_vien WHERE email=? AND trangThai=1 LIMIT 1");
            $s->bind_param("s", $email);
            $s->execute();
            $row = $s->get_result()->fetch_assoc();
            $s->close();
            if (!empty($row['tenKTV'])) return $row['tenKTV'];
        }
    }
    // Ưu tiên 2: fallback về session
    return $_SESSION['fullname'] ?? $_SESSION['username'] ?? '';
}

// ── Khởi tạo bảng thông báo nếu chưa có ──
$conn->query("CREATE TABLE IF NOT EXISTS `thong_bao_ktv` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `maPhieu`     INT NOT NULL,
    `kyThuatVien` VARCHAR(100) NOT NULL COMMENT 'Lưu theo fullname (hoặc username nếu không có fullname)',
    `loai`        VARCHAR(30)  NOT NULL DEFAULT 'phan_cong',
    `tieuDe`      VARCHAR(200) NOT NULL,
    `noiDung`     TEXT         DEFAULT NULL,
    `trangThai`   VARCHAR(20)  NOT NULL DEFAULT 'chua_doc',
    `nguoiGui`    VARCHAR(100) DEFAULT NULL,
    `thoiGian`    DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ktv_tt (`kyThuatVien`, `trangThai`),
    INDEX idx_phieu (`maPhieu`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Tạo bảng thông báo admin nếu chưa có
$conn->query("CREATE TABLE IF NOT EXISTS `thong_bao_admin` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `maPhieu`     INT NOT NULL,
    `loai`        VARCHAR(30)  NOT NULL DEFAULT 'phan_cong',
    `tieuDe`      VARCHAR(200) NOT NULL,
    `noiDung`     TEXT         DEFAULT NULL,
    `trangThai`   VARCHAR(20)  NOT NULL DEFAULT 'chua_doc',
    `nguoiGui`    VARCHAR(100) DEFAULT NULL,
    `thoiGian`    DATETIME     DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_phieu (`maPhieu`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Thêm cột kyThuatVien vào phieu_sua_chua nếu chưa có
$colCheck = $conn->query("SHOW COLUMNS FROM phieu_sua_chua LIKE 'kyThuatVien'");
if ($colCheck && $colCheck->num_rows == 0) {
    $conn->query("ALTER TABLE phieu_sua_chua ADD COLUMN `kyThuatVien` VARCHAR(100) DEFAULT NULL COMMENT 'KTV được phân công (lưu theo fullname)'");
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ════════════════════════════════════
// GET: đếm thông báo chưa đọc (KTV)
// ════════════════════════════════════
if ($action === 'count') {
    if (!isset($_SESSION['role'])) { echo json_encode(['count' => 0]); exit; }
    $ktv  = getKtvName();
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM thong_bao_ktv WHERE kyThuatVien=? AND trangThai='chua_doc'");
    $stmt->bind_param("s", $ktv);
    $stmt->execute();
    $c = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    echo json_encode(['count' => $c]);
    exit;
}

// ════════════════════════════════════
// GET: lấy danh sách thông báo (KTV)
// ════════════════════════════════════
if ($action === 'list') {
    if (!isset($_SESSION['role'])) { echo json_encode(['items' => []]); exit; }
    $ktv   = getKtvName();
    $limit = min((int)($_GET['limit'] ?? 20), 50);
    $stmt  = $conn->prepare("
        SELECT tb.*, ps.tenThietBi, ps.tenKH, ps.trangThai AS ttPhieu, ps.mucDoUuTien
        FROM thong_bao_ktv tb
        LEFT JOIN phieu_sua_chua ps ON ps.maPhieu = tb.maPhieu
        WHERE tb.kyThuatVien = ?
        ORDER BY tb.thoiGian DESC
        LIMIT ?
    ");
    $stmt->bind_param("si", $ktv, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($rows as &$row) {
        $row['thoiGianFormat'] = date('d/m/Y H:i', strtotime($row['thoiGian']));
    }
    echo json_encode(['items' => $rows]);
    exit;
}

// ════════════════════════════════════
// GET: [admin] lấy danh sách KTV để hiện dropdown
// ════════════════════════════════════
if ($action === 'danh_sach_ktv') {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        echo json_encode(['ok' => false, 'msg' => 'Chỉ admin mới có quyền', 'items' => []]);
        exit;
    }

    $items = [];

    $checkUsers = $conn->query("SHOW TABLES LIKE 'users'");
    if ($checkUsers && $checkUsers->num_rows > 0) {
        $colsRes = $conn->query("SHOW COLUMNS FROM users");
        $cols = [];
        while ($col = $colsRes->fetch_assoc()) $cols[] = $col['Field'];

        $selectFullname = in_array('fullname', $cols) ? 'fullname' : 'NULL';
        $selectEmail    = in_array('email',    $cols) ? 'email'    : "''";

        $res = $conn->query("SELECT username, {$selectFullname} AS fullname, {$selectEmail} AS email
                             FROM users WHERE role='technician' ORDER BY fullname, username");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $displayName = trim($row['fullname'] ?? '') ?: $row['username'];
                $items[] = [
                    'label'    => $displayName,
                    'value'    => $displayName,
                    'username' => $row['username'],
                    'fullname' => $row['fullname'] ?? '',
                    'email'    => $row['email']    ?? '',
                ];
            }
        }
    }

    if (empty($items)) {
        $checkKtv = $conn->query("SHOW TABLES LIKE 'ky_thuat_vien'");
        if ($checkKtv && $checkKtv->num_rows > 0) {
            $res2 = $conn->query("SELECT tenKTV, email FROM ky_thuat_vien WHERE trangThai=1 ORDER BY tenKTV");
            if ($res2) {
                while ($row = $res2->fetch_assoc()) {
                    $items[] = [
                        'label'    => $row['tenKTV'],
                        'value'    => $row['tenKTV'],
                        'username' => $row['tenKTV'],
                        'fullname' => $row['tenKTV'],
                        'email'    => $row['email'] ?? '',
                    ];
                }
            }
        }
    }

    echo json_encode(['ok' => true, 'items' => $items]);
    exit;
}

// ════════════════════════════════════
// POST: chấp nhận phân công (KTV)
// ════════════════════════════════════
if ($action === 'chap_nhan' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'technician') {
        echo json_encode(['ok' => false, 'msg' => 'Không có quyền']); exit;
    }
    $tbId    = (int)($_POST['id']      ?? 0);
    $maPhieu = (int)($_POST['maPhieu'] ?? 0);
    $ktv     = getKtvName();
    
    // Lấy thông tin phiếu trước khi cập nhật
    $stmtGet = $conn->prepare("SELECT ps.*, kh.tenKH as khachHang, kh.soDienThoai 
                               FROM phieu_sua_chua ps 
                               LEFT JOIN khach_hang kh ON ps.maKH = kh.maKH 
                               WHERE ps.maPhieu = ?");
    $stmtGet->bind_param("i", $maPhieu);
    $stmtGet->execute();
    $phieuInfo = $stmtGet->get_result()->fetch_assoc();
    $stmtGet->close();

    // Cập nhật trạng thái thông báo
    $stmt = $conn->prepare("UPDATE thong_bao_ktv SET trangThai='da_chap_nhan' WHERE id=? AND kyThuatVien=?");
    $stmt->bind_param("is", $tbId, $ktv);
    $stmt->execute();
    $stmt->close();

    // Cập nhật phiếu → Đang sửa (không giới hạn trạng thái để luôn cập nhật được)
    $stmt2 = $conn->prepare("UPDATE phieu_sua_chua SET trangThai='Đang sửa', kyThuatVien=? WHERE maPhieu=?");
    $stmt2->bind_param("si", $ktv, $maPhieu);
    $stmt2->execute();
    $stmt2->close();

    // GỬI THÔNG BÁO CHO ADMIN
    $tieuDeAdmin = "✅ KTV đã chấp nhận phân công";
    $noiDungAdmin = "Kỹ thuật viên {$ktv} đã CHẤP NHẬN xử lý phiếu sửa chữa:
━━━━━━━━━━━━━━━━━━━━━━━━━━
📋 Mã phiếu: #SC-{$maPhieu}
💻 Thiết bị: " . ($phieuInfo['tenThietBi'] ?? '—') . "
👤 Khách hàng: " . ($phieuInfo['khachHang'] ?? '—') . "
📞 SĐT: " . ($phieuInfo['soDienThoai'] ?? '—') . "
━━━━━━━━━━━━━━━━━━━━━━━━━━
👉 Trạng thái hiện tại: Đang sửa
⏰ Thời gian xác nhận: " . date('d/m/Y H:i');

    $stmtAdmin = $conn->prepare("INSERT INTO thong_bao_admin (maPhieu, loai, tieuDe, noiDung, nguoiGui, trangThai) 
                                  VALUES (?, 'phan_cong', ?, ?, ?, 'chua_doc')");
    $stmtAdmin->bind_param("isss", $maPhieu, $tieuDeAdmin, $noiDungAdmin, $ktv);
    $stmtAdmin->execute();
    $stmtAdmin->close();

    echo json_encode(['ok' => true, 'msg' => 'Đã chấp nhận phân công. Phiếu chuyển sang Đang sửa.']);
    exit;
}

// ════════════════════════════════════
// POST: từ chối phân công (KTV)
// ════════════════════════════════════
if ($action === 'tu_choi' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'technician') {
        echo json_encode(['ok' => false, 'msg' => 'Không có quyền']); exit;
    }
    $tbId    = (int)($_POST['id']      ?? 0);
    $maPhieu = (int)($_POST['maPhieu'] ?? 0);
    $ktv     = getKtvName();
    $lyDo    = trim($_POST['ly_do']    ?? '');
    
    // Lấy thông tin phiếu trước khi cập nhật
    $stmtGet = $conn->prepare("SELECT ps.*, kh.tenKH as khachHang, kh.soDienThoai 
                               FROM phieu_sua_chua ps 
                               LEFT JOIN khach_hang kh ON ps.maKH = kh.maKH 
                               WHERE ps.maPhieu = ?");
    $stmtGet->bind_param("i", $maPhieu);
    $stmtGet->execute();
    $phieuInfo = $stmtGet->get_result()->fetch_assoc();
    $stmtGet->close();

    $stmt = $conn->prepare("UPDATE thong_bao_ktv
                            SET trangThai='tu_choi',
                                noiDung=CONCAT(IFNULL(noiDung,''), '\n[Từ chối: ', ?, ']')
                            WHERE id=? AND kyThuatVien=?");
    $stmt->bind_param("sis", $lyDo, $tbId, $ktv);
    $stmt->execute();
    $stmt->close();

    // Reset kyThuatVien trên phiếu để admin có thể phân công lại
    $stmt2 = $conn->prepare("UPDATE phieu_sua_chua SET kyThuatVien=NULL WHERE maPhieu=? AND kyThuatVien=?");
    $stmt2->bind_param("is", $maPhieu, $ktv);
    $stmt2->execute();
    $stmt2->close();

    // GỬI THÔNG BÁO CHO ADMIN
    $tieuDeAdmin = "❌ KTV từ chối phân công";
    $noiDungAdmin = "Kỹ thuật viên {$ktv} đã TỪ CHỐI xử lý phiếu sửa chữa:
━━━━━━━━━━━━━━━━━━━━━━━━━━
📋 Mã phiếu: #SC-{$maPhieu}
💻 Thiết bị: " . ($phieuInfo['tenThietBi'] ?? '—') . "
👤 Khách hàng: " . ($phieuInfo['khachHang'] ?? '—') . "
📞 SĐT: " . ($phieuInfo['soDienThoai'] ?? '—') . "
━━━━━━━━━━━━━━━━━━━━━━━━━━
💬 Lý do từ chối: " . ($lyDo ?: 'Không cung cấp lý do') . "
⏰ Thời gian từ chối: " . date('d/m/Y H:i') . "
━━━━━━━━━━━━━━━━━━━━━━━━━━
👉 Vui lòng phân công lại cho KTV khác.";

    $stmtAdmin = $conn->prepare("INSERT INTO thong_bao_admin (maPhieu, loai, tieuDe, noiDung, nguoiGui, trangThai) 
                                  VALUES (?, 'tu_choi', ?, ?, ?, 'chua_doc')");
    $stmtAdmin->bind_param("isss", $maPhieu, $tieuDeAdmin, $noiDungAdmin, $ktv);
    $stmtAdmin->execute();
    $stmtAdmin->close();

    echo json_encode(['ok' => true, 'msg' => 'Đã từ chối. Admin sẽ được thông báo để phân công lại.']);
    exit;
}

// ════════════════════════════════════
// POST: đánh dấu đã đọc (KTV - id=0 là tất cả)
// ════════════════════════════════════
if ($action === 'doc' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['role'])) { echo json_encode(['ok' => false]); exit; }
    $ktv = getKtvName();
    $id  = (int)($_POST['id'] ?? 0);

    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE thong_bao_ktv SET trangThai='da_doc'
                                WHERE id=? AND kyThuatVien=? AND trangThai='chua_doc'");
        $stmt->bind_param("is", $id, $ktv);
    } else {
        $stmt = $conn->prepare("UPDATE thong_bao_ktv SET trangThai='da_doc'
                                WHERE kyThuatVien=? AND trangThai='chua_doc'");
        $stmt->bind_param("s", $ktv);
    }
    $stmt->execute();
    $stmt->close();
    echo json_encode(['ok' => true]);
    exit;
}

// ════════════════════════════════════
// GET: lấy thông báo cho admin
// ════════════════════════════════════
if ($action === 'admin_notifications') {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        echo json_encode(['items' => []]); exit;
    }
    
    $limit = min((int)($_GET['limit'] ?? 20), 50);
    $stmt = $conn->prepare("
        SELECT tb.*, ps.tenThietBi, ps.tenKH, ps.trangThai as ttPhieu
        FROM thong_bao_admin tb
        LEFT JOIN phieu_sua_chua ps ON ps.maPhieu = tb.maPhieu
        ORDER BY tb.thoiGian DESC
        LIMIT ?
    ");
    $stmt->bind_param("i", $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    foreach ($rows as &$row) {
        $row['thoiGianFormat'] = date('d/m/Y H:i', strtotime($row['thoiGian']));
    }
    
    echo json_encode(['items' => $rows]);
    exit;
}

// ════════════════════════════════════
// GET: đếm thông báo chưa đọc cho admin
// ════════════════════════════════════
if ($action === 'admin_count') {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        echo json_encode(['count' => 0]); exit;
    }
    $stmt = $conn->prepare("SELECT COUNT(*) as c FROM thong_bao_admin WHERE trangThai='chua_doc'");
    $stmt->execute();
    $c = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    echo json_encode(['count' => $c]);
    exit;
}

// ════════════════════════════════════
// POST: đánh dấu đã đọc thông báo admin
// ════════════════════════════════════
if ($action === 'admin_doc' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        echo json_encode(['ok' => false]); exit;
    }
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE thong_bao_admin SET trangThai='da_doc' WHERE id=?");
        $stmt->bind_param("i", $id);
    } else {
        $stmt = $conn->prepare("UPDATE thong_bao_admin SET trangThai='da_doc' WHERE trangThai='chua_doc'");
    }
    $stmt->execute();
    $stmt->close();
    echo json_encode(['ok' => true]);
    exit;
}

// ════════════════════════════════════
// POST: [admin] gửi thông báo phân công
// ════════════════════════════════════
if ($action === 'gui_phan_cong' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        echo json_encode(['ok' => false, 'msg' => 'Chỉ admin mới có quyền']); exit;
    }
    $maPhieu = (int)trim($_POST['maPhieu']      ?? '');
    $ktv     =     trim($_POST['kyThuatVien']   ?? '');
    $admin   = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Admin';

    if (!$maPhieu || !$ktv) {
        echo json_encode(['ok' => false, 'msg' => 'Thiếu thông tin (maPhieu hoặc kyThuatVien)']); exit;
    }

    $stmtPS = $conn->prepare("
        SELECT ps.*, kh.email, kh.diaChi
        FROM phieu_sua_chua ps
        LEFT JOIN khach_hang kh ON kh.maKH = ps.maKH
        WHERE ps.maPhieu=?
    ");
    $stmtPS->bind_param("i", $maPhieu);
    $stmtPS->execute();
    $ps = $stmtPS->get_result()->fetch_assoc();
    $stmtPS->close();

    if (!$ps) {
        echo json_encode(['ok' => false, 'msg' => "Không tìm thấy phiếu #$maPhieu"]); exit;
    }

    $fmtDate = fn($d) => $d ? date('d/m/Y', strtotime($d)) : '—';
    $fmtMoney = fn($v) => $v ? number_format((float)$v, 0, ',', '.') . 'đ' : '—';

    $tieuDe  = "🔧 Phân công phiếu #SC-{$maPhieu}: {$ps['tenThietBi']}";
    $noiDung = "Bạn được phân công xử lý phiếu sửa chữa:
━━━━━━━━━━━━━━━━━━━━━━━━━━
📋 Mã phiếu    : #SC-{$maPhieu}
💻 Thiết bị    : {$ps['tenThietBi']}
🔴 Ưu tiên     : " . ($ps['mucDoUuTien'] ?? 'Bình thường') . "

👤 THÔNG TIN KHÁCH HÀNG
──────────────────────
   Tên KH     : {$ps['tenKH']}
   Điện thoại : " . ($ps['soDienThoai'] ?? '—') . "
   Email       : " . ($ps['email'] ?? '—') . "
   Địa chỉ    : " . ($ps['diaChi'] ?? '—') . "

🗓️ THỜI GIAN
──────────────────────
   Ngày nhận  : " . $fmtDate($ps['ngayNhan']) . "
   Ngày trả   : " . $fmtDate($ps['ngayTraDuKien']) . "

💰 TÀI CHÍNH
──────────────────────
   Báo giá    : " . $fmtMoney($ps['baoGia'] ?? 0) . "

🔧 MÔ TẢ LỖI
──────────────────────
" . ($ps['moTaLoi'] ?? '—') . "
━━━━━━━━━━━━━━━━━━━━━━━━━━
📌 Phân công bởi: $admin";

    // Xóa thông báo cũ nếu có
    $stmtDel = $conn->prepare("DELETE FROM thong_bao_ktv WHERE maPhieu=? AND trangThai IN ('chua_doc','da_doc','tu_choi')");
    $stmtDel->bind_param("i", $maPhieu);
    $stmtDel->execute();
    $stmtDel->close();

    $stmtIns = $conn->prepare("INSERT INTO thong_bao_ktv (maPhieu,kyThuatVien,loai,tieuDe,noiDung,nguoiGui)
                               VALUES (?,?, 'phan_cong',?,?,?)");
    $stmtIns->bind_param("issss", $maPhieu, $ktv, $tieuDe, $noiDung, $admin);
    $stmtIns->execute();
    $stmtIns->close();

    // Cập nhật kyThuatVien trên phiếu
    $stmtUpd = $conn->prepare("UPDATE phieu_sua_chua SET kyThuatVien=? WHERE maPhieu=?");
    $stmtUpd->bind_param("si", $ktv, $maPhieu);
    $stmtUpd->execute();
    $stmtUpd->close();

    echo json_encode(['ok' => true, 'msg' => "Đã gửi thông báo phân công đến: $ktv"]);
    exit;
}

// ════════════════════════════════════
// POST: gửi thông báo nhắc nhở KTV
// ════════════════════════════════════
if ($action === 'gui_nhac_nho' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'technician')) {
        echo json_encode(['ok' => false, 'msg' => 'Không có quyền']); exit;
    }
    
    $maPhieu = (int)($_POST['maPhieu'] ?? 0);
    $kyThuatVien = trim($_POST['kyThuatVien'] ?? '');
    $noiDungThem = trim($_POST['noiDung'] ?? '');
    $nguoiGui = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Hệ thống';
    
    if (!$maPhieu || !$kyThuatVien) {
        echo json_encode(['ok' => false, 'msg' => 'Thiếu thông tin phiếu hoặc KTV']); exit;
    }
    
    $stmtPS = $conn->prepare("SELECT * FROM phieu_sua_chua WHERE maPhieu=?");
    $stmtPS->bind_param("i", $maPhieu);
    $stmtPS->execute();
    $ps = $stmtPS->get_result()->fetch_assoc();
    $stmtPS->close();
    
    if (!$ps) {
        echo json_encode(['ok' => false, 'msg' => "Không tìm thấy phiếu #$maPhieu"]); exit;
    }
    
    $tieuDe = "🔔 Nhắc nhở xử lý phiếu #SC-{$maPhieu}";
    $noiDung = "Bạn có phiếu sửa chữa cần xử lý:
━━━━━━━━━━━━━━━━━━━━
📋 Mã phiếu: #SC-$maPhieu
💻 Thiết bị: {$ps['tenThietBi']}
👤 Khách hàng: {$ps['tenKH']}
📝 Trạng thái hiện tại: {$ps['trangThai']}
⏰ Ngày nhận: " . date('d/m/Y', strtotime($ps['ngayNhan'])) . "
━━━━━━━━━━━━━━━━━━━━
📌 Yêu cầu: Vui lòng cập nhật tiến độ sửa chữa.
" . ($noiDungThem ? "\n💬 Ghi chú từ admin: $noiDungThem" : "");
    
    $stmtIns = $conn->prepare("INSERT INTO thong_bao_ktv (maPhieu, kyThuatVien, loai, tieuDe, noiDung, nguoiGui, trangThai)
                               VALUES (?, ?, 'nhac_nho', ?, ?, ?, 'chua_doc')");
    $stmtIns->bind_param("isssss", $maPhieu, $kyThuatVien, $tieuDe, $noiDung, $nguoiGui);
    $stmtIns->execute();
    $stmtIns->close();
    
    echo json_encode(['ok' => true, 'msg' => "Đã gửi nhắc nhở đến $kyThuatVien"]);
    exit;
}

echo json_encode(['ok' => false, 'msg' => "Action '$action' không hợp lệ"]);
?>