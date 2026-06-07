<?php
session_start();
require_once 'db.php';

// Nếu chưa đăng nhập thì đuổi về trang login
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Xử lý khi khách bấm nút "Lưu thông tin"
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $dob = $_POST['dob'];
    $occupation = $_POST['occupation'];
    
    // Gộp mảng sở thích thành chuỗi cách nhau bởi dấu phẩy (VD: Gaming, Đồ họa)
    $hobbies = isset($_POST['hobbies']) ? implode(', ', $_POST['hobbies']) : '';

    // Cập nhật vào Database và đổi trạng thái is_profile_completed thành 1
    $stmt = $conn->prepare("UPDATE users SET dob = ?, occupation = ?, hobbies = ?, is_profile_completed = 1 WHERE id = ?");
    $stmt->bind_param("sssi", $dob, $occupation, $hobbies, $user_id);
    
    if ($stmt->execute()) {
        // Điền xong thì đẩy về trang chủ để mua sắm
        header("Location: index.php");
        exit();
    } else {
        $error = "Có lỗi xảy ra, vui lòng thử lại!";
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Hoàn thiện hồ sơ - Quang Anh Tech</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body { background-color: #f3f4f6; display: flex; justify-content: center; align-items: center; min-height: 100vh; font-family: 'Segoe UI', sans-serif; margin: 0; }
        .profile-container { background: white; width: 100%; max-width: 550px; padding: 40px; border-radius: 15px; box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        .profile-title { text-align: center; color: #0b8a2e; font-size: 26px; font-weight: 900; margin-bottom: 10px; }
        .profile-subtitle { text-align: center; color: #666; font-size: 15px; margin-bottom: 30px; line-height: 1.5; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-weight: bold; margin-bottom: 8px; color: #333; font-size: 14px; }
        .form-control { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px; box-sizing: border-box; outline: none; }
        .form-control:focus { border-color: #0b8a2e; }
        .checkbox-group { display: flex; gap: 15px; flex-wrap: wrap; margin-top: 10px; }
        .checkbox-label { display: flex; align-items: center; gap: 8px; font-weight: normal !important; cursor: pointer; background: #f9fafb; padding: 10px 15px; border-radius: 8px; border: 1px solid #e5e7eb; transition: 0.2s;}
        .checkbox-label:hover { background: #f0fff4; border-color: #0b8a2e; }
        .btn-submit { width: 100%; background: #0b8a2e; color: white; border: none; padding: 15px; border-radius: 8px; font-size: 16px; font-weight: bold; cursor: pointer; transition: 0.2s; margin-top: 15px; text-transform: uppercase;}
        .btn-submit:hover { background: #097526; transform: translateY(-2px); }
        .alert-error { background: #fee2e2; color: #991b1b; padding: 10px; border-radius: 5px; margin-bottom: 15px; text-align: center; font-size: 14px; }
    </style>
</head>
<body>

<div class="profile-container">
    <div class="profile-title">Chào mừng thành viên mới! 🎉</div>
    <div class="profile-subtitle">Hãy cho Quang Anh Tech biết thêm về bạn nhé.</div>

    <?php if(isset($error)) echo "<div class='alert-error'>$error</div>"; ?>

    <form method="POST">
        <div class="form-group">
            <label>Ngày sinh của bạn 🎂</label>
            <input type="date" name="dob" class="form-control" required>
        </div>

        <div class="form-group">
            <label>Bạn đang là? 🎓💼</label>
            <select name="occupation" class="form-control" required>
                <option value="">-- Chọn nghề nghiệp --</option>
                <option value="Học sinh / Sinh viên">Học sinh / Sinh viên</option>
                <option value="Người đi làm">Người đi làm</option>
                <option value="Doanh nghiệp">Khách hàng Doanh nghiệp</option>
            </select>
        </div>

        <div class="form-group">
            <label>Nhu cầu / Sở thích chính của bạn là gì? 🎮🎨 (Có thể chọn nhiều)</label>
            <div class="checkbox-group">
                <label class="checkbox-label"><input type="checkbox" name="hobbies[]" value="Gaming"> Chơi Game</label>
                <label class="checkbox-label"><input type="checkbox" name="hobbies[]" value="Văn phòng"> Văn phòng / Học tập</label>
                <label class="checkbox-label"><input type="checkbox" name="hobbies[]" value="Đồ họa"> Thiết kế đồ họa / Video</label>
                <label class="checkbox-label"><input type="checkbox" name="hobbies[]" value="Lập trình"> Lập trình / IT</label>
            </div>
        </div>

        <button type="submit" class="btn-submit">Lưu thông tin & Khám phá ngay</button>
    </form>
</div>

</body>
</html>