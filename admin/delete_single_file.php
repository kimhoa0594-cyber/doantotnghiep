<?php
session_start();
require_once '../db.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') exit;
$maCT = (int)$_GET['maCT'];
$maBo = (int)$_GET['maBo'];
$maKH = (int)$_GET['id'];

$file = $conn->query("SELECT duongDan FROM chi_tiet_tai_lieu WHERE maCT=$maCT")->fetch_assoc();
if ($file && file_exists('../' . $file['duongDan'])) {
    unlink('../' . $file['duongDan']);
}
$conn->query("DELETE FROM chi_tiet_tai_lieu WHERE maCT=$maCT");
header("Location: chi_tiet_khach_hang.php?id=$maKH");
?>