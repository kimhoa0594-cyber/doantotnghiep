<?php
session_start();

function checkRole($role_required) {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== $role_required) {
        // Nếu không đúng quyền, đẩy về trang login hoặc trang chủ
        header("Location: ../login.php?error=access_denied");
        exit();
    }
}
?>