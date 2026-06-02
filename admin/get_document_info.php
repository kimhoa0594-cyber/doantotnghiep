<?php
session_start();
require_once '../db.php';
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') exit;
$id = (int)$_GET['id'];
$result = $conn->query("SELECT maBo, loaiTaiLieu, tenTaiLieu FROM bo_tai_lieu WHERE maBo=$id")->fetch_assoc();
header('Content-Type: application/json');
echo json_encode($result);
?>