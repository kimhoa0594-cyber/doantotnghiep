<?php
session_start();

// 1. Hủy bỏ tất cả các biến session
$_SESSION = array();

// 2. Nếu muốn xóa sạch cookie session, hãy thực hiện bước này
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. Cuối cùng, hủy phiên làm việc
session_destroy();

// 4. Chuyển hướng người dùng về trang đăng nhập
header("Location: login.php");
exit();
?>