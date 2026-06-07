<?php
session_start();
require_once '../db.php';
date_default_timezone_set('Asia/Ho_Chi_Minh');

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['items' => [], 'count' => 0]);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Lấy danh sách thông báo
if ($action === 'list') {
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

// Đếm thông báo chưa đọc
if ($action === 'count') {
    $stmt = $conn->prepare("SELECT COUNT(*) as c FROM thong_bao_admin WHERE trangThai='chua_doc'");
    $stmt->execute();
    $c = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    echo json_encode(['count' => $c]);
    exit;
}

// Đánh dấu đã đọc
if ($action === 'doc' && $_SERVER['REQUEST_METHOD'] === 'POST') {
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

echo json_encode(['items' => []]);
?>