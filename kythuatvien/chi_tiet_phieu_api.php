<?php
/**
 * chi_tiet_phieu_api.php
 * Trả về đầy đủ thông tin phiếu sửa chữa (JSON) cho modal chi tiết của KTV
 * Đặt trong cùng thư mục kythuatvien/
 */
session_start();
require_once '../db.php';
date_default_timezone_set('Asia/Ho_Chi_Minh');
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'technician') {
    echo json_encode(['ok' => false, 'msg' => 'Không có quyền']); exit;
}

$maPhieu = (int)($_GET['maPhieu'] ?? 0);
if (!$maPhieu) {
    echo json_encode(['ok' => false, 'msg' => 'Thiếu mã phiếu']); exit;
}

// Lấy tên KTV hiện tại
$tenKTV = $_SESSION['fullname'] ?? $_SESSION['username'] ?? '';
$ktv_email = $_SESSION['email'] ?? '';
if ($ktv_email) {
    $chkKtv = $conn->query("SHOW TABLES LIKE 'ky_thuat_vien'");
    if ($chkKtv && $chkKtv->num_rows > 0) {
        $sKtv = $conn->prepare("SELECT tenKTV FROM ky_thuat_vien WHERE email=? AND trangThai=1 LIMIT 1");
        $sKtv->bind_param("s", $ktv_email);
        $sKtv->execute();
        $rowKtv = $sKtv->get_result()->fetch_assoc();
        $sKtv->close();
        if (!empty($rowKtv['tenKTV'])) $tenKTV = $rowKtv['tenKTV'];
    }
}

// Chỉ cho phép xem phiếu được phân công cho mình
$stmt = $conn->prepare("
    SELECT
        ps.*,
        kh.email,
        kh.diaChi,
        kh.tenKH  AS tenKhachHang
    FROM phieu_sua_chua ps
    LEFT JOIN khach_hang kh ON kh.maKH = ps.maKH
    WHERE ps.maPhieu = ? AND ps.kyThuatVien = ?
    LIMIT 1
");
$stmt->bind_param("is", $maPhieu, $tenKTV);
$stmt->execute();
$phieu = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$phieu) {
    echo json_encode(['ok' => false, 'msg' => 'Không tìm thấy phiếu hoặc không có quyền truy cập']); exit;
}

// Dùng tenKhachHang từ bảng khach_hang nếu có, fallback về cột tenKH trong phiếu
$phieu['tenKH']    = $phieu['tenKhachHang'] ?: ($phieu['tenKH'] ?? '');
$phieu['email']    = $phieu['email']   ?? '';
$phieu['diaChi']   = $phieu['diaChi']  ?? '';

echo json_encode(['ok' => true, 'phieu' => $phieu]);
?>