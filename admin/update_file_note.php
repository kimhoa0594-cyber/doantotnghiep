<?php
session_start();
require_once '../db.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') exit;
$data = json_decode(file_get_contents('php://input'), true);
$maCT = (int)$data['maCT'];
$ghiChu = $conn->real_escape_string($data['ghiChu']);
$conn->query("UPDATE chi_tiet_tai_lieu SET ghiChu='$ghiChu' WHERE maCT=$maCT");
echo json_encode(['success' => true]);
?>