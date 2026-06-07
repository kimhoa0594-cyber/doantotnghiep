<?php
session_start();
require_once '../db.php';

// Nạp PHPMailer
require 'phpmailer/Exception.php';
require 'phpmailer/PHPMailer.php';
require 'phpmailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php"); exit();
}

// ============================================================
// HÀM GỬI EMAIL
// ============================================================
function sendMail($to_email, $to_name, $subject, $body) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'kimhoa0594@gmail.com';
        $mail->Password   = 'pnhivqspwshxexsq';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom('kimhoa0594@gmail.com', 'Quang Anh Tech');
        $mail->addAddress($to_email, $to_name);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// ============================================================
// KHỞI TẠO BIẾN THÔNG BÁO
// ============================================================
$msg      = "";
$msg_type = "info";
$created_password = ""; // Lưu mật khẩu vừa tạo để hiển thị cho admin

// ============================================================
// 1. KHÓA / MỞ KHÓA TÀI KHOẢN
// ============================================================
if (isset($_GET['toggle_status'])) {
    $id             = (int)$_GET['toggle_status'];
    $current_status = (int)$_GET['current'];
    $new_status     = ($current_status == 1) ? 0 : 1;

    $stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
    $stmt->bind_param("ii", $new_status, $id);
    $stmt->execute();
    $stmt->close();

    $action_text = $new_status == 1 ? "mở khóa" : "khóa";
    header("Location: users.php?msg_quick=" . urlencode("Đã $action_text tài khoản #$id thành công!") . "&msg_type=success");
    exit();
}

if (isset($_GET['msg_quick'])) {
    $msg      = htmlspecialchars($_GET['msg_quick']);
    $msg_type = $_GET['msg_type'] ?? 'info';
}

// ============================================================
// 2. THÊM TÀI KHOẢN MỚI
// ============================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_user'])) {
    $u_name  = trim($_POST['u_name']);
    $u_email = trim($_POST['u_email']);
    $u_role  = $_POST['u_role'];
    $u_pass_input = trim($_POST['u_pass']);

    // Tạo mật khẩu ngẫu nhiên nếu để trống
    if (empty($u_pass_input)) {
        $u_pass_input = substr(str_shuffle("abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"), 0, 10);
    }
    $u_pass_hashed = password_hash($u_pass_input, PASSWORD_DEFAULT);

    // Kiểm tra email trùng
    $check = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $check->bind_param("s", $u_email);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $msg      = "⚠️ Email <strong>$u_email</strong> đã tồn tại trong hệ thống!";
        $msg_type = "warning";
    } else {
        // INSERT tài khoản mới
        $stmt = $conn->prepare("INSERT INTO users (username, email, password, role, status, created_at) VALUES (?, ?, ?, ?, 1, NOW())");
        $stmt->bind_param("ssss", $u_name, $u_email, $u_pass_hashed, $u_role);

        if ($stmt->execute()) {
            $created_password = $u_pass_input; // Lưu để hiển thị cho admin

            // ── Nếu là khách hàng → tự động thêm vào bảng khach_hang ──
            if ($u_role === 'customer') {
                // Tạo bảng nếu chưa có (phòng trường hợp users.php chạy độc lập)
                $conn->query("CREATE TABLE IF NOT EXISTS `khach_hang` (
                    `maKH` int(11) NOT NULL AUTO_INCREMENT,
                    `tenKH` varchar(100) NOT NULL,
                    `diaChi` varchar(255) DEFAULT NULL,
                    `soDienThoai` varchar(15) DEFAULT NULL,
                    `email` varchar(100) DEFAULT NULL,
                    `ngaySinh` date DEFAULT NULL,
                    `loaiKhachHang` varchar(50) DEFAULT 'Khách truy cập',
                    `ngayDangKy` date DEFAULT current_timestamp(),
                    `trangThai` tinyint(1) DEFAULT 1,
                    PRIMARY KEY (`maKH`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

                // Kiểm tra xem email đã có trong khach_hang chưa
                $chkKH = $conn->prepare("SELECT maKH FROM khach_hang WHERE email = ? LIMIT 1");
                $chkKH->bind_param("s", $u_email);
                $chkKH->execute();
                $chkKH->store_result();
                if ($chkKH->num_rows === 0) {
                    $insKH = $conn->prepare(
                        "INSERT INTO khach_hang (tenKH, email, loaiKhachHang, ngayDangKy, trangThai)
                         VALUES (?, ?, 'Khách truy cập', CURDATE(), 1)"
                    );
                    $insKH->bind_param("ss", $u_name, $u_email);
                    $insKH->execute();
                    $insKH->close();
                }
                $chkKH->close();
            }

            // Gửi email thông báo cho người dùng
            $role_label = ['admin' => 'Quản trị viên', 'staff' => 'Nhân viên', 'technician' => 'Kỹ thuật viên', 'customer' => 'Khách hàng'];
            $role_name  = $role_label[$u_role] ?? $u_role;

            $email_body = "
            <div style='font-family: Arial, sans-serif; max-width: 520px; margin: auto; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden;'>
                <div style='background: #0b8a2e; padding: 28px; text-align: center;'>
                    <h2 style='color: white; margin: 0;'>🖥️ Quang Anh Tech</h2>
                    <p style='color: #d1fae5; margin: 6px 0 0;'>Thông báo cấp tài khoản hệ thống</p>
                </div>
                <div style='padding: 30px;'>
                    <p style='font-size: 16px;'>Xin chào <strong>$u_name</strong>,</p>
                    <p>Tài khoản của bạn đã được tạo thành công trên hệ thống <strong>Quang Anh Tech</strong>.</p>
                    <div style='background: #f1f5f9; border-radius: 10px; padding: 20px; margin: 20px 0;'>
                        <p style='margin: 6px 0;'><strong>📧 Email đăng nhập:</strong> $u_email</p>
                        <p style='margin: 6px 0;'><strong>🔑 Mật khẩu:</strong> <span style='font-size: 18px; font-weight: bold; color: #0b8a2e; letter-spacing: 2px;'>$u_pass_input</span></p>
                        <p style='margin: 6px 0;'><strong>👤 Vai trò:</strong> $role_name</p>
                    </div>
                    <p style='color: #ef4444; font-size: 13px;'>⚠️ Vui lòng đổi mật khẩu sau khi đăng nhập lần đầu để bảo mật tài khoản.</p>
                    <p style='font-size: 13px; color: #64748b;'>Nếu bạn không yêu cầu tài khoản này, vui lòng liên hệ với chúng tôi.</p>
                </div>
                <div style='background: #f8fafc; padding: 15px; text-align: center; font-size: 12px; color: #94a3b8;'>
                    © 2025 Quang Anh Tech — Hỗ trợ máy tính chuyên nghiệp
                </div>
            </div>";

            $mail_sent = sendMail($u_email, $u_name, "Thông báo cấp tài khoản - Quang Anh Tech", $email_body);

            if ($mail_sent) {
                $msg = "✅ Tạo tài khoản thành công! Email thông báo đã được gửi tới <strong>$u_email</strong>.";
            } else {
                $msg = "✅ Tạo tài khoản thành công! <span class='text-warning'>(Không thể gửi email tự động)</span>";
            }
            $msg_type = "success";

            // Ghi nhớ tên khách hàng vừa tạo để hiển thị banner liên kết
            if ($u_role === 'customer') {
                $_SESSION['new_customer_alert'] = [
                    'name'  => $u_name,
                    'email' => $u_email,
                    'date'  => date('d/m/Y'),
                ];
            }

            // ── Nếu là nhân viên / kỹ thuật viên → tự động thêm vào bảng nhan_vien ──
            if ($u_role === 'staff' || $u_role === 'technician') {
                $conn->query("CREATE TABLE IF NOT EXISTS `nhan_vien` (
                    `maNV`        int(11)      NOT NULL AUTO_INCREMENT,
                    `tenNV`       varchar(100) NOT NULL,
                    `email`       varchar(100) DEFAULT NULL,
                    `soDienThoai` varchar(15)  DEFAULT NULL,
                    `diaChi`      varchar(255) DEFAULT NULL,
                    `ngaySinh`    date         DEFAULT NULL,
                    `chucVu`      varchar(50)  DEFAULT 'Nhân viên',
                    `phongBan`    varchar(100) DEFAULT NULL,
                    `ngayVaoLam`  date         DEFAULT (CURRENT_DATE),
                    `trangThai`   tinyint(1)   DEFAULT 1,
                    PRIMARY KEY (`maNV`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

                $chkNV = $conn->prepare("SELECT maNV FROM nhan_vien WHERE email = ? LIMIT 1");
                $chkNV->bind_param("s", $u_email);
                $chkNV->execute();
                $chkNV->store_result();
                if ($chkNV->num_rows === 0) {
                    $chucVu = ($u_role === 'technician') ? 'Kỹ thuật viên' : 'Nhân viên';
                    $insNV  = $conn->prepare(
                        "INSERT INTO nhan_vien (tenNV, email, chucVu, ngayVaoLam, trangThai)
                         VALUES (?, ?, ?, CURDATE(), 1)"
                    );
                    $insNV->bind_param("sss", $u_name, $u_email, $chucVu);
                    $insNV->execute();
                    $insNV->close();
                }
                $chkNV->close();

                // ── Nếu là kỹ thuật viên → thêm vào bảng ky_thuat_vien ──
                if ($u_role === 'technician') {
                    $conn->query("CREATE TABLE IF NOT EXISTS `ky_thuat_vien` (
                        `maKTV`       int(11)      NOT NULL AUTO_INCREMENT,
                        `tenKTV`      varchar(100) NOT NULL,
                        `email`       varchar(100) DEFAULT NULL,
                        `soDienThoai` varchar(15)  DEFAULT NULL,
                        `diaChi`      varchar(255) DEFAULT NULL,
                        `ngaySinh`    date         DEFAULT NULL,
                        `chuyenMon`   varchar(150) DEFAULT NULL,
                        `ghiChu`      text         DEFAULT NULL,
                        `ngayTaoTK`   date         DEFAULT NULL,
                        `ngayCapNhat` datetime     DEFAULT NULL,
                        `trangThai`   tinyint(1)   DEFAULT 1,
                        PRIMARY KEY (`maKTV`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

                    $chkKTV = $conn->prepare("SELECT maKTV FROM ky_thuat_vien WHERE email = ? LIMIT 1");
                    $chkKTV->bind_param("s", $u_email);
                    $chkKTV->execute();
                    $chkKTV->store_result();
                    if ($chkKTV->num_rows === 0) {
                        $insKTV = $conn->prepare(
                            "INSERT INTO ky_thuat_vien (tenKTV, email, ngayTaoTK, trangThai)
                             VALUES (?, ?, CURDATE(), 1)"
                        );
                        $insKTV->bind_param("ss", $u_name, $u_email);
                        $insKTV->execute();
                        $insKTV->close();
                    }
                    $chkKTV->close();

                    // Alert riêng cho kỹ thuật viên
                    $_SESSION['new_technician_alert'] = [
                        'name'  => $u_name,
                        'email' => $u_email,
                        'date'  => date('d/m/Y'),
                    ];
                }

                // Alert cho nhân viên thường (staff)
                if ($u_role === 'staff') {
                    $_SESSION['new_staff_alert'] = [
                        'name'  => $u_name,
                        'email' => $u_email,
                        'role'  => $role_name,
                        'date'  => date('d/m/Y'),
                    ];
                }
            }
        } else {
            $msg      = "❌ Có lỗi xảy ra khi tạo tài khoản. Vui lòng thử lại!";
            $msg_type = "danger";
        }
        $stmt->close();
    }
    $check->close();
}

// ============================================================
// 3. CẬP NHẬT TÀI KHOẢN
// ============================================================
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_user'])) {
    $u_id    = (int)$_POST['u_id'];
    $u_name  = trim($_POST['u_name']);
    $u_email = trim($_POST['u_email']);
    $u_role  = $_POST['u_role'];
    $u_pass  = trim($_POST['u_pass']);

    // Kiểm tra email trùng (trừ chính user đó)
    $check = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $check->bind_param("si", $u_email, $u_id);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $msg      = "⚠️ Email <strong>$u_email</strong> đã được sử dụng bởi tài khoản khác!";
        $msg_type = "warning";
    } else {
        if (!empty($u_pass)) {
            $hashed_pass = password_hash($u_pass, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET username=?, email=?, role=?, password=? WHERE id=?");
            $stmt->bind_param("ssssi", $u_name, $u_email, $u_role, $hashed_pass, $u_id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET username=?, email=?, role=? WHERE id=?");
            $stmt->bind_param("sssi", $u_name, $u_email, $u_role, $u_id);
        }

        if ($stmt->execute()) {
            $msg      = "✅ Cập nhật tài khoản <strong>#$u_id</strong> thành công!";
            $msg_type = "success";
        } else {
            $msg      = "❌ Có lỗi xảy ra khi cập nhật!";
            $msg_type = "danger";
        }
        $stmt->close();
    }
    $check->close();
}

// ============================================================
// 4. XÓA TÀI KHOẢN
// ============================================================
if (isset($_GET['del'])) {
    $del_id = (int)$_GET['del'];
    // Không cho xóa chính mình
    if ($del_id == $_SESSION['user_id']) {
        header("Location: users.php?msg_quick=" . urlencode("Không thể xóa tài khoản của chính bạn!") . "&msg_type=danger");
        exit();
    }

    // Lấy email + role trước khi xóa để xóa các bảng liên quan
    $info = $conn->prepare("SELECT email, role FROM users WHERE id = ?");
    $info->bind_param("i", $del_id);
    $info->execute();
    $info_result = $info->get_result()->fetch_assoc();
    $info->close();

    if ($info_result) {
        $del_email = $info_result['email'];
        $del_role  = $info_result['role'];

        // Xóa trong khach_hang nếu là customer
        if ($del_role === 'customer') {
            $d = $conn->prepare("DELETE FROM khach_hang WHERE email = ?");
            $d->bind_param("s", $del_email);
            $d->execute();
            $d->close();
        }

        // Xóa trong nhan_vien nếu là staff hoặc technician
        if ($del_role === 'staff' || $del_role === 'technician') {
            $d = $conn->prepare("DELETE FROM nhan_vien WHERE email = ?");
            $d->bind_param("s", $del_email);
            $d->execute();
            $d->close();
        }

        // Xóa trong ky_thuat_vien nếu là technician
        if ($del_role === 'technician') {
            $d = $conn->prepare("DELETE FROM ky_thuat_vien WHERE email = ?");
            $d->bind_param("s", $del_email);
            $d->execute();
            $d->close();
        }
    }

    // Xóa khỏi bảng users
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param("i", $del_id);
    if ($stmt->execute()) {
        header("Location: users.php?msg_quick=" . urlencode("Đã xóa tài khoản thành công!") . "&msg_type=success");
    } else {
        header("Location: users.php?msg_quick=" . urlencode("Có lỗi khi xóa tài khoản!") . "&msg_type=danger");
    }
    $stmt->close();
    exit();
}

// ============================================================
// 5. THỐNG KÊ
// ============================================================
$stats      = $conn->query("SELECT role, COUNT(*) as count FROM users GROUP BY role");
$count_data = ['admin' => 0, 'staff' => 0, 'technician' => 0, 'customer' => 0];
while ($s = $stats->fetch_assoc()) {
    if (isset($count_data[$s['role']])) $count_data[$s['role']] = $s['count'];
}
$total_users  = array_sum($count_data);
$locked_count = $conn->query("SELECT COUNT(*) as c FROM users WHERE status = 0")->fetch_assoc()['c'];

// ============================================================
// 6. TRUY VẤN DANH SÁCH
// ============================================================
$tab    = $_GET['tab'] ?? 'all';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$date_f = isset($_GET['date_f']) ? trim($_GET['date_f']) : '';
$status_f = isset($_GET['status_f']) ? $_GET['status_f'] : '';

$where    = " WHERE 1=1 ";
$params   = [];
$types    = "";

if ($tab != 'all') {
    $where   .= " AND role = ? ";
    $params[] = $tab;
    $types   .= "s";
}
if (!empty($search)) {
    $like     = "%$search%";
    $where   .= " AND (username LIKE ? OR email LIKE ?) ";
    $params[] = $like;
    $params[] = $like;
    $types   .= "ss";
}
if (!empty($date_f)) {
    $where   .= " AND DATE(created_at) = ? ";
    $params[] = $date_f;
    $types   .= "s";
}
if ($status_f !== '') {
    $where   .= " AND status = ? ";
    $params[] = (int)$status_f;
    $types   .= "i";
}

$sql_users = "SELECT *, (created_at >= NOW() - INTERVAL 1 DAY) as is_new FROM users $where ORDER BY is_new DESC, id DESC";
$stmt_u    = $conn->prepare($sql_users);
if (!empty($params)) {
    $stmt_u->bind_param($types, ...$params);
}
$stmt_u->execute();
$users = $stmt_u->get_result();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Phân quyền người dùng - QA Tech Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --primary: #0b8a2e;
            --primary-light: #e8f5ed;
            --secondary: #64748b;
            --dark: #0f172a;
            --light-bg: #f1f5f9;
            --card-radius: 16px;
        }
        body { background-color: var(--light-bg); font-family: 'Inter', 'Segoe UI', sans-serif; color: var(--dark); }

        /* ---- SIDEBAR ---- */
        .sidebar {
            width: 260px; height: 100vh; position: fixed;
            background: var(--dark); color: white;
            padding-top: 20px; z-index: 100;
            display: flex; flex-direction: column;
        }
        .sidebar-logo { padding: 0 24px 20px; border-bottom: 1px solid rgba(255,255,255,0.08); }
        .sidebar-logo h4 { font-size: 18px; font-weight: 700; margin: 0; }
        .sidebar .nav-link {
            color: #94a3b8; padding: 11px 24px; margin: 2px 12px;
            border-radius: 10px; font-weight: 500; font-size: 14px;
            text-decoration: none; display: flex; align-items: center; gap: 10px;
            transition: all 0.2s;
        }
        .sidebar .nav-link:hover { background: rgba(255,255,255,0.08); color: white; }
        .sidebar .nav-link.active { background: var(--primary); color: white; }
        .sidebar .nav-link i { width: 18px; text-align: center; }
        .sidebar-bottom { margin-top: auto; padding-bottom: 20px; }

        /* ---- MAIN ---- */
        .main-content { margin-left: 260px; padding: 32px 36px; min-height: 100vh; }

        /* ---- STAT CARDS ---- */
        .stat-card {
            background: white; border: none; border-radius: var(--card-radius);
            padding: 20px 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.07);
            display: flex; align-items: center; gap: 16px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,0.1); }
        .stat-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }

        /* ---- TABLE CARD ---- */
        .table-card {
            background: white; border-radius: 20px;
            padding: 28px; box-shadow: 0 4px 20px rgba(0,0,0,0.05);
        }
        .search-box {
            background: #f8fafc; border: 1.5px solid #e2e8f0;
            border-radius: 10px; padding: 4px 14px;
            display: flex; align-items: center; gap: 8px;
        }
        .search-box input { border: none; background: transparent; padding: 7px 0; font-size: 14px; outline: none; width: 100%; }
        .search-box input::placeholder { color: #a0aec0; }

        /* ---- BADGES ---- */
        .role-badge { padding: 5px 12px; border-radius: 8px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .badge-admin      { background: #fee2e2; color: #ef4444; }
        .badge-staff      { background: #dbeafe; color: #2563eb; }
        .badge-technician { background: #fef9c3; color: #b45309; }
        .badge-customer   { background: #f1f5f9; color: #64748b; }

        .status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
        .dot-active { background: #22c55e; }
        .dot-locked { background: #ef4444; }

        /* ---- TABLE ---- */
        .table > thead > tr > th { font-size: 11px; font-weight: 700; color: var(--secondary); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1.5px solid #f1f5f9; padding-bottom: 14px; }
        .table > tbody > tr { border-color: #f8fafc; transition: background 0.15s; }
        .table > tbody > tr:hover { background: #f8fafc; }
        .table > tbody > tr > td { padding: 14px 12px; vertical-align: middle; }

        /* ---- USER AVATAR ---- */
        .user-avatar { width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 15px; flex-shrink: 0; }

        /* ---- ACTION BUTTONS (labeled) ---- */
        .btn-lbl {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 6px 12px; border-radius: 8px; border: 1.5px solid;
            font-size: 12px; font-weight: 600; cursor: pointer;
            transition: all 0.18s; white-space: nowrap; background: white;
        }
        .btn-lbl i { font-size: 11px; }
        .btn-lbl-view   { color: #2563eb; border-color: #bfdbfe; background: #eff6ff; }
        .btn-lbl-view:hover   { background: #2563eb; color: white; border-color: #2563eb; transform: translateY(-1px); box-shadow: 0 3px 8px rgba(37,99,235,0.3); }
        .btn-lbl-edit   { color: #16a34a; border-color: #bbf7d0; background: #f0fdf4; }
        .btn-lbl-edit:hover   { background: #16a34a; color: white; border-color: #16a34a; transform: translateY(-1px); box-shadow: 0 3px 8px rgba(22,163,74,0.3); }
        .btn-lbl-lock   { color: #ea580c; border-color: #fed7aa; background: #fff7ed; }
        .btn-lbl-lock:hover   { background: #ea580c; color: white; border-color: #ea580c; transform: translateY(-1px); box-shadow: 0 3px 8px rgba(234,88,12,0.3); }
        .btn-lbl-unlock { color: #16a34a; border-color: #bbf7d0; background: #f0fdf4; }
        .btn-lbl-unlock:hover { background: #16a34a; color: white; border-color: #16a34a; transform: translateY(-1px); box-shadow: 0 3px 8px rgba(22,163,74,0.3); }
        .btn-lbl-del    { color: #ef4444; border-color: #fecaca; background: #fef2f2; }
        .btn-lbl-del:hover    { background: #ef4444; color: white; border-color: #ef4444; transform: translateY(-1px); box-shadow: 0 3px 8px rgba(239,68,68,0.3); }

        /* ---- VIEW MODAL ---- */
        .view-modal-header { background: linear-gradient(135deg, #1e3a5f, #0b8a2e); border-radius: 20px 20px 0 0; }
        .view-avatar {
            width: 80px; height: 80px; border-radius: 20px;
            display: flex; align-items: center; justify-content: center;
            font-size: 32px; font-weight: 800;
            border: 3px solid rgba(255,255,255,0.3);
        }
        .view-quick-actions { background: #f8fafc; }
        .view-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .view-info-item { display: flex; align-items: flex-start; gap: 12px; padding: 12px; background: #f8fafc; border-radius: 12px; }
        .view-info-icon { width: 38px; height: 38px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; }
        .view-info-label { font-size: 11px; font-weight: 700; color: var(--secondary); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 3px; }
        .view-info-value { font-size: 14px; font-weight: 600; color: var(--dark); word-break: break-all; }

        /* ---- EDIT MODAL ---- */
        .edit-current-info {}
        .input-icon-wrap { position: relative; }
        .input-icon { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 13px; pointer-events: none; z-index: 1; }
        .ps-icon { padding-left: 36px !important; }
        .input-suffix-btn {
            position: absolute; right: 6px; top: 50%; transform: translateY(-50%);
            background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 6px;
            padding: 4px 8px; font-size: 13px; color: #64748b; cursor: pointer;
            transition: all 0.15s;
        }
        .input-suffix-btn:hover { background: var(--primary); color: white; border-color: var(--primary); }
        .normal-case { text-transform: none; font-size: 11px; }
        .text-purple { color: #9333ea; }

        /* ---- MODAL ---- */
        .modal-card { border: none; border-radius: 20px; box-shadow: 0 20px 60px rgba(0,0,0,0.15); }
        .modal-header-custom { background: linear-gradient(135deg, var(--primary), #0d6e24); color: white; border-radius: 20px 20px 0 0; padding: 24px 28px; }
        .form-control-custom, .form-select-custom {
            background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 10px;
            padding: 10px 14px; font-size: 14px; transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-control-custom:focus, .form-select-custom:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(11,138,46,0.15); outline: none; background: white; }
        .label-sm { font-size: 11px; font-weight: 700; color: var(--secondary); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; }

        /* ---- PASSWORD REVEAL BOX ---- */
        .pass-reveal-box { background: #f0fdf4; border: 2px solid #86efac; border-radius: 12px; padding: 16px 20px; }
        .pass-reveal-box .pass-text { font-size: 22px; font-weight: 800; color: #15803d; letter-spacing: 3px; font-family: monospace; }

        /* ---- NEW INDICATOR ---- */
        .new-tag { background: #fee2e2; color: #ef4444; font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 20px; margin-left: 6px; }

        /* ---- TABS ---- */
        .tab-pills .nav-link { border-radius: 8px; font-size: 13px; font-weight: 500; color: var(--secondary); padding: 7px 16px; }
        .tab-pills .nav-link.active { background: var(--primary); color: white; }

        /* ---- LOCKED ROW ---- */
        .row-locked td { opacity: 0.55; }

        /* ---- RESPONSIVE ---- */
        @media (max-width: 768px) { .main-content { margin-left: 0; padding: 16px; } .sidebar { display: none; } }
    </style>
</head>
<body>

<!-- ============================================================ SIDEBAR -->
<div class="sidebar">
    <div class="sidebar-logo">
        <h4 class="text-white">QA <span class="text-success">TECH</span> <small class="text-secondary fw-normal fs-6">Admin</small></h4>
    </div>
    <nav class="nav flex-column mt-3 px-0">
        <a class="nav-link" href="index.php"><i class="fas fa-th-large"></i> Tổng quan</a>
        <a class="nav-link active" href="users.php"><i class="fas fa-user-shield"></i> Phân quyền</a>
        
        <a class="nav-link" href="quan_ly_nhan_vien.php"><i class="fas fa-id-badge"></i> Nhân viên</a>
        <a class="nav-link" href="quan_ly_ky_thuat_vien.php"><i class="fas fa-tools"></i> Kỹ thuật viên</a>
        <a class="nav-link" href="quan_ly_khach_hang.php"><i class="fas fa-users"></i> Khách hàng</a>
        
    </nav>
    <div class="sidebar-bottom">
        <a class="nav-link text-danger" href="../logout.php"><i class="fas fa-sign-out-alt"></i> Đăng xuất</a>
    </div>
</div>

<!-- ============================================================ MAIN CONTENT -->
<div class="main-content">

    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold mb-1" style="font-size: 24px;">Phân quyền người dùng</h2>
            <p class="text-secondary mb-0 small">Quản lý tài khoản và phân quyền toàn bộ người dùng hệ thống.</p>
        </div>
        <button class="btn btn-sm px-4 py-2 rounded-3 shadow-sm text-white fw-semibold" style="background: var(--primary);" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="fas fa-user-plus me-2"></i>Tạo tài khoản
        </button>
    </div>

    <!-- Stat Cards -->
    <div class="row g-3 mb-4">
        <div class="col-xl col-md-4 col-6">
            <div class="stat-card">
                <div class="stat-icon" style="background:#eff6ff; color:#2563eb;"><i class="fas fa-users"></i></div>
                <div><div class="small text-secondary">Tổng người dùng</div><div class="fw-bold fs-5"><?= $total_users ?></div></div>
            </div>
        </div>
        <div class="col-xl col-md-4 col-6">
            <div class="stat-card">
                <div class="stat-icon" style="background:#fee2e2; color:#ef4444;"><i class="fas fa-user-tie"></i></div>
                <div><div class="small text-secondary">Quản trị viên</div><div class="fw-bold fs-5"><?= $count_data['admin'] ?></div></div>
            </div>
        </div>
        <div class="col-xl col-md-4 col-6">
            <div class="stat-card">
                <div class="stat-icon" style="background:#dbeafe; color:#2563eb;"><i class="fas fa-user-check"></i></div>
                <div><div class="small text-secondary">Nhân viên</div><div class="fw-bold fs-5"><?= $count_data['staff'] ?></div></div>
            </div>
        </div>
        <div class="col-xl col-md-4 col-6">
            <div class="stat-card">
                <div class="stat-icon" style="background:#fef9c3; color:#b45309;"><i class="fas fa-tools"></i></div>
                <div><div class="small text-secondary">Kỹ thuật viên</div><div class="fw-bold fs-5"><?= $count_data['technician'] ?></div></div>
            </div>
        </div>
        <div class="col-xl col-md-4 col-6">
            <div class="stat-card">
                <div class="stat-icon" style="background:#f1f5f9; color:#64748b;"><i class="fas fa-user-tag"></i></div>
                <div><div class="small text-secondary">Khách hàng</div><div class="fw-bold fs-5"><?= $count_data['customer'] ?></div></div>
            </div>
        </div>
        <div class="col-xl col-md-4 col-6">
            <div class="stat-card">
                <div class="stat-icon" style="background:#fef2f2; color:#ef4444;"><i class="fas fa-lock"></i></div>
                <div><div class="small text-secondary">Đang bị khóa</div><div class="fw-bold fs-5"><?= $locked_count ?></div></div>
            </div>
        </div>
    </div>

    <!-- Table Card -->
    <div class="table-card">

        <!-- Toolbar -->
        <div class="row g-3 mb-4 align-items-center">
            <!-- Tabs -->
            <div class="col-lg-7">
                <ul class="nav tab-pills bg-light p-1 rounded-3">
                    <li class="nav-item"><a class="nav-link <?= $tab=='all' ? 'active' : '' ?>" href="?tab=all">Tất cả <span class="badge bg-secondary ms-1"><?= $total_users ?></span></a></li>
                    <li class="nav-item"><a class="nav-link <?= $tab=='admin' ? 'active' : '' ?>" href="?tab=admin">Admin</a></li>
                    <li class="nav-item"><a class="nav-link <?= $tab=='staff' ? 'active' : '' ?>" href="?tab=staff">Nhân viên</a></li>
                    <li class="nav-item"><a class="nav-link <?= $tab=='technician' ? 'active' : '' ?>" href="?tab=technician">Kỹ thuật</a></li>
                    <li class="nav-item"><a class="nav-link <?= $tab=='customer' ? 'active' : '' ?>" href="?tab=customer">Khách hàng</a></li>
                </ul>
            </div>
            <!-- Search & Filter -->
            <div class="col-lg-5">
                <form method="GET" class="d-flex gap-2 align-items-center">
                    <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
                    <div class="search-box flex-grow-1">
                        <i class="fas fa-search text-secondary" style="font-size:13px;"></i>
                        <input type="text" name="search" placeholder="Tìm tên, email..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <input type="date" name="date_f" class="form-control-custom" style="width:150px;" value="<?= htmlspecialchars($date_f) ?>">
                    <select name="status_f" class="form-select-custom" style="width:120px;">
                        <option value="">Trạng thái</option>
                        <option value="1" <?= $status_f==='1'?'selected':'' ?>>Hoạt động</option>
                        <option value="0" <?= $status_f==='0'?'selected':'' ?>>Bị khóa</option>
                    </select>
                    <button type="submit" class="btn btn-sm px-3 py-2 rounded-3 text-white fw-semibold" style="background:var(--primary); white-space:nowrap;">
                        <i class="fas fa-filter me-1"></i>Lọc
                    </button>
                    <?php if (!empty($search) || !empty($date_f) || $status_f !== ''): ?>
                    <a href="?tab=<?= $tab ?>" class="btn btn-sm btn-light px-3 py-2 rounded-3">Xóa lọc</a>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- ── BANNER: Khách hàng mới đã được lưu vào danh sách ── -->
        <?php if (!empty($_SESSION['new_customer_alert'])): $nca = $_SESSION['new_customer_alert']; unset($_SESSION['new_customer_alert']); ?>
        <div class="alert border-0 rounded-4 shadow-sm mb-4 p-0 overflow-hidden" id="newCustomerBanner" style="border-left: 5px solid #0b8a2e !important;">
            <div class="d-flex align-items-stretch">
                <div class="d-flex align-items-center justify-content-center px-4" style="background:linear-gradient(135deg,#0b8a2e,#16a34a); min-width:64px;">
                    <span style="font-size:28px;">🎉</span>
                </div>
                <div class="flex-grow-1 p-3 ps-4" style="background:#f0fdf4;">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="fw-bold mb-1" style="color:#065f46; font-size:15px;">
                                Tài khoản của <span style="color:#0b8a2e;"><?= htmlspecialchars($nca['name']) ?></span>
                                đã được tạo và lưu vào danh sách khách hàng!
                            </div>
                            <div class="small mb-2" style="color:#166534;">
                                <i class="fas fa-info-circle me-1"></i>
                                Hãy mở trang <strong>Quản lý khách hàng</strong> để cập nhật đầy đủ thông tin
                                (số điện thoại, ngày sinh, địa chỉ...) nhằm chăm sóc khách hàng tốt hơn mỗi ngày.
                            </div>
                            <a href="quan_ly_khach_hang.php" class="btn btn-sm fw-semibold rounded-3 px-4 text-white"
                               style="background:#0b8a2e; border:none; font-size:13px;">
                                <i class="fas fa-users me-1"></i> Mở trang Quản lý Khách Hàng
                            </a>
                        </div>
                        <button type="button" class="btn-close ms-3 mt-1" onclick="document.getElementById('newCustomerBanner').remove()"></button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── BANNER: Nhân viên (staff) mới đã được lưu vào danh sách ── -->
        <?php if (!empty($_SESSION['new_staff_alert'])): $nsa = $_SESSION['new_staff_alert']; unset($_SESSION['new_staff_alert']); ?>
        <div class="alert border-0 rounded-4 shadow-sm mb-4 p-0 overflow-hidden" id="newStaffBanner" style="border-left: 5px solid #2563eb !important;">
            <div class="d-flex align-items-stretch">
                <div class="d-flex align-items-center justify-content-center px-4" style="background:linear-gradient(135deg,#1d4ed8,#2563eb); min-width:64px;">
                    <span style="font-size:28px;">👔</span>
                </div>
                <div class="flex-grow-1 p-3 ps-4" style="background:#eff6ff;">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="fw-bold mb-1" style="color:#1e3a8a; font-size:15px;">
                                Tài khoản của <span style="color:#2563eb;"><?= htmlspecialchars($nsa['name']) ?></span>
                                (<?= htmlspecialchars($nsa['role']) ?>) đã được tạo và lưu vào danh sách nhân viên!
                            </div>
                            <div class="small mb-2" style="color:#1e40af;">
                                <i class="fas fa-info-circle me-1"></i>
                                Hãy truy cập trang <strong>Quản lý nhân viên</strong> để cập nhật đầy đủ thông tin
                                (số điện thoại, ngày sinh, địa chỉ, phòng ban...) nhằm quản lý nhân viên tốt hơn.
                            </div>
                            <a href="quan_ly_nhan_vien.php" class="btn btn-sm fw-semibold rounded-3 px-4 text-white"
                               style="background:#2563eb; border:none; font-size:13px;">
                                <i class="fas fa-id-badge me-1"></i> Mở trang Quản lý Nhân Viên
                            </a>
                        </div>
                        <button type="button" class="btn-close ms-3 mt-1" onclick="document.getElementById('newStaffBanner').remove()"></button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- ── BANNER: Kỹ thuật viên mới đã được tạo tài khoản ── -->
        <?php if (!empty($_SESSION['new_technician_alert'])): $nta = $_SESSION['new_technician_alert']; unset($_SESSION['new_technician_alert']); ?>
        <div class="alert border-0 rounded-4 shadow-sm mb-4 p-0 overflow-hidden" id="newTechnicianBanner" style="border-left: 5px solid #b45309 !important;">
            <div class="d-flex align-items-stretch">
                <div class="d-flex align-items-center justify-content-center px-4" style="background:linear-gradient(135deg,#92400e,#b45309); min-width:64px;">
                    <span style="font-size:28px;">🔧</span>
                </div>
                <div class="flex-grow-1 p-3 ps-4" style="background:#fffbeb;">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="fw-bold mb-1" style="color:#92400e; font-size:15px;">
                                Tài khoản của kỹ thuật viên <span style="color:#b45309;"><?= htmlspecialchars($nta['name']) ?></span>
                                đã được tạo và lưu vào danh sách kỹ thuật viên!
                            </div>
                            <div class="small mb-2" style="color:#78350f;">
                                <i class="fas fa-info-circle me-1"></i>
                                Hãy truy cập trang <strong>Quản lý kỹ thuật viên</strong> để cập nhật đầy đủ thông tin
                                (họ tên, ngày sinh, số điện thoại, địa chỉ, gmail) nhằm quản lý nhân viên tốt hơn.
                            </div>
                            <a href="quan_ly_ky_thuat_vien.php" class="btn btn-sm fw-semibold rounded-3 px-4 text-white"
                               style="background:#b45309; border:none; font-size:13px;">
                                <i class="fas fa-tools me-1"></i> Mở trang Quản lý Kỹ Thuật Viên
                            </a>
                        </div>
                        <button type="button" class="btn-close ms-3 mt-1" onclick="document.getElementById('newTechnicianBanner').remove()"></button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Alert -->
        <?php if (!empty($msg)): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show border-0 rounded-3 shadow-sm mb-4 d-flex align-items-start gap-2">
            <div class="flex-grow-1"><?= $msg ?></div>
            <?php if (!empty($created_password)): ?>
            <div class="pass-reveal-box ms-3" style="min-width: 220px;">
                <div class="small fw-bold text-success mb-1"><i class="fas fa-key me-1"></i>Mật khẩu đã tạo:</div>
                <div class="pass-text" id="gen_pass"><?= htmlspecialchars($created_password) ?></div>
                <button onclick="copyPass()" class="btn btn-sm btn-success mt-2 w-100 rounded-3">
                    <i class="fas fa-copy me-1"></i>Sao chép
                </button>
            </div>
            <?php endif; ?>
            <button type="button" class="btn-close ms-2" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Table -->
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width:50px;">#</th>
                        <th>NGƯỜI DÙNG</th>
                        <th>VAI TRÒ</th>
                        <th>TRẠNG THÁI</th>
                        <th>NGÀY TẠO</th>
                        <th class="text-end">THAO TÁC</th>
                    </tr>
                </thead>
                <tbody>
                <?php $stt = 1; while ($row = $users->fetch_assoc()): ?>
                <tr class="<?= $row['status'] == 0 ? 'row-locked' : '' ?>">
                    <td class="text-secondary small"><?= $stt++ ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-3">
                            <?php
                            $colors = ['admin'=>'#ef4444','staff'=>'#2563eb','technician'=>'#d97706','customer'=>'#64748b'];
                            $bg     = $colors[$row['role']] ?? '#64748b';
                            $letter = strtoupper(substr($row['username'] ?? 'U', 0, 1));
                            ?>
                            <div class="user-avatar text-white" style="background: <?= $bg ?>20; color: <?= $bg ?>;"><?= $letter ?></div>
                            <div>
                                <div class="fw-semibold small">
                                    <?= htmlspecialchars($row['username']) ?>
                                    <?php if ($row['is_new']): ?><span class="new-tag">MỚI</span><?php endif; ?>
                                </div>
                                <div class="text-secondary" style="font-size:12px;"><?= htmlspecialchars($row['email']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php
                        $role_labels = ['admin'=>'Quản trị viên','staff'=>'Nhân viên','technician'=>'Kỹ thuật viên','customer'=>'Khách hàng'];
                        $rl = $role_labels[$row['role']] ?? $row['role'];
                        ?>
                        <span class="role-badge badge-<?= $row['role'] ?>"><?= $rl ?></span>
                    </td>
                    <td>
                        <?php if ($row['status'] == 1): ?>
                            <div class="d-flex align-items-center gap-2">
                                <span class="status-dot dot-active"></span>
                                <span class="small fw-semibold text-success">Hoạt động</span>
                            </div>
                        <?php else: ?>
                            <div class="d-flex align-items-center gap-2">
                                <span class="status-dot dot-locked"></span>
                                <span class="small fw-semibold text-danger">Bị khóa</span>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="text-secondary small"><?= date('d/m/Y', strtotime($row['created_at'])) ?></td>
                    <td class="text-end">
                        <div class="d-flex gap-2 justify-content-end flex-wrap">
                            <button onclick='viewUser(<?= json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                class="btn-lbl btn-lbl-view" title="Xem chi tiết tài khoản">
                                <i class="fas fa-eye"></i><span>Xem</span>
                            </button>
                            <button onclick='editUser(<?= json_encode($row, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'
                                class="btn-lbl btn-lbl-edit" title="Chỉnh sửa thông tin">
                                <i class="fas fa-pen-to-square"></i><span>Sửa</span>
                            </button>
                            <?php if ($row['status'] == 1): ?>
                                <button onclick="confirmToggle(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['username'])) ?>', 1, '<?= $row['role'] ?>')"
                                    class="btn-lbl btn-lbl-lock" title="Khóa tài khoản này">
                                    <i class="fas fa-lock"></i><span>Khóa</span>
                                </button>
                            <?php else: ?>
                                <button onclick="confirmToggle(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['username'])) ?>', 0, '<?= $row['role'] ?>')"
                                    class="btn-lbl btn-lbl-unlock" title="Mở khóa tài khoản này">
                                    <i class="fas fa-lock-open"></i><span>Mở khóa</span>
                                </button>
                            <?php endif; ?>
                            <button onclick="confirmDelete(<?= $row['id'] ?>, '<?= htmlspecialchars(addslashes($row['username'])) ?>', '<?= htmlspecialchars(addslashes($row['email'])) ?>', '<?= $row['role'] ?>')"
                                class="btn-lbl btn-lbl-del" title="Xóa vĩnh viễn tài khoản">
                                <i class="fas fa-trash-can"></i><span>Xóa</span>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php if ($stt == 1): ?>
                <tr><td colspan="6" class="text-center text-secondary py-5"><i class="fas fa-inbox fa-2x mb-2 d-block"></i>Không tìm thấy tài khoản nào.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div><!-- /.table-responsive -->
    </div><!-- /.table-card -->
</div><!-- /.main-content -->


<!-- ============================================================ MODAL: XEM CHI TIẾT -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content modal-card">
            <!-- Header với avatar lớn -->
            <div id="v_modal_header" class="view-modal-header position-relative">
                <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-3" data-bs-dismiss="modal"></button>
                <div class="text-center pt-4 pb-3 px-4">
                    <div id="v_avatar" class="view-avatar mx-auto mb-3"></div>
                    <h4 class="mb-1 fw-bold text-white" id="v_username_title"></h4>
                    <div class="opacity-75 small text-white" id="v_email_header"></div>
                    <div class="mt-2" id="v_status_header"></div>
                </div>
            </div>
            <!-- Body thông tin chi tiết -->
            <div class="modal-body p-0">
                <!-- Thanh action nhanh -->
                <div class="view-quick-actions px-4 py-3 d-flex gap-2 border-bottom">
                    <button id="v_btn_edit" class="btn btn-sm flex-fill rounded-3 fw-semibold py-2" onclick="switchToEdit()">
                        <i class="fas fa-pen-to-square me-1"></i>Chỉnh sửa
                    </button>
                    <button id="v_btn_toggle" class="btn btn-sm flex-fill rounded-3 fw-semibold py-2" onclick="quickToggle()">
                        <i class="fas fa-lock me-1" id="v_toggle_icon"></i><span id="v_toggle_text"></span>
                    </button>
                    <button id="v_btn_del" class="btn btn-sm btn-outline-danger flex-fill rounded-3 fw-semibold py-2" onclick="quickDelete()">
                        <i class="fas fa-trash-can me-1"></i>Xóa TK
                    </button>
                </div>
                <!-- Grid thông tin -->
                <div class="p-4">
                    <div class="view-info-grid">
                        <div class="view-info-item">
                            <div class="view-info-icon" style="background:#eff6ff; color:#2563eb;"><i class="fas fa-hashtag"></i></div>
                            <div>
                                <div class="view-info-label">Mã tài khoản</div>
                                <div class="view-info-value fw-bold" id="v_id"></div>
                            </div>
                        </div>
                        <div class="view-info-item">
                            <div class="view-info-icon" style="background:#f0fdf4; color:#16a34a;"><i class="fas fa-user"></i></div>
                            <div>
                                <div class="view-info-label">Tên đăng nhập</div>
                                <div class="view-info-value fw-bold" id="v_username"></div>
                            </div>
                        </div>
                        <div class="view-info-item">
                            <div class="view-info-icon" style="background:#fef3c7; color:#d97706;"><i class="fas fa-envelope"></i></div>
                            <div>
                                <div class="view-info-label">Địa chỉ Email</div>
                                <div class="view-info-value" id="v_email"></div>
                            </div>
                        </div>
                        <div class="view-info-item">
                            <div class="view-info-icon" style="background:#fdf4ff; color:#9333ea;"><i class="fas fa-shield-halved"></i></div>
                            <div>
                                <div class="view-info-label">Vai trò & Quyền hạn</div>
                                <div id="v_role"></div>
                            </div>
                        </div>
                        <div class="view-info-item">
                            <div class="view-info-icon" style="background:#f0f9ff; color:#0ea5e9;"><i class="fas fa-calendar-plus"></i></div>
                            <div>
                                <div class="view-info-label">Ngày tạo tài khoản</div>
                                <div class="view-info-value" id="v_date"></div>
                            </div>
                        </div>
                        <div class="view-info-item">
                            <div class="view-info-icon" style="background:#f1f5f9; color:#64748b;"><i class="fas fa-circle-dot"></i></div>
                            <div>
                                <div class="view-info-label">Trạng thái tài khoản</div>
                                <div id="v_status"></div>
                            </div>
                        </div>
                    </div>
                    <!-- Ghi chú hoạt động gần đây -->
                    <div id="v_new_notice" class="mt-3 p-3 rounded-3 d-none" style="background:#fef9c3; border:1px solid #fde047;">
                        <i class="fas fa-star text-warning me-2"></i>
                        <span class="small fw-semibold" style="color:#854d0e;">Tài khoản mới — tạo trong vòng 24 giờ qua</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0 px-4 pb-4">
                <button type="button" class="btn btn-light w-100 rounded-3 py-2 fw-semibold" data-bs-dismiss="modal">
                    <i class="fas fa-xmark me-1"></i>Đóng cửa sổ
                </button>
            </div>
        </div>
    </div>
</div>


<!-- ============================================================ MODAL: THÊM TÀI KHOẢN -->
<div class="modal fade" id="addModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content modal-card" method="POST">
            <div class="modal-header-custom">
                <h5 class="mb-0 fw-bold"><i class="fas fa-user-plus me-2"></i>Tạo tài khoản mới</h5>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <div class="label-sm">Tên đăng nhập</div>
                    <input type="text" name="u_name" class="form-control-custom w-100" placeholder="Nhập tên người dùng" required>
                </div>
                <div class="mb-3">
                    <div class="label-sm">Địa chỉ Email <span class="text-danger">*</span></div>
                    <input type="email" name="u_email" class="form-control-custom w-100" placeholder="example@email.com" required>
                    <small class="text-secondary">Email này dùng để đăng nhập và nhận thông báo.</small>
                </div>
                <div class="row g-3">
                    <div class="col-6">
                        <div class="label-sm">Vai trò</div>
                        <select name="u_role" class="form-select-custom w-100">
                            <option value="customer">Khách hàng</option>
                            <option value="staff">Nhân viên</option>
                            <option value="technician">Kỹ thuật viên</option>
                            <option value="admin">Quản trị viên</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <div class="label-sm">Mật khẩu</div>
                        <div class="d-flex gap-1">
                            <input type="text" name="u_pass" id="gen_pass_field" class="form-control-custom flex-grow-1" placeholder="Tự động nếu trống" value="">
                            <button type="button" onclick="generatePass()" class="btn btn-light border rounded-3 px-2" title="Tạo ngẫu nhiên">
                                <i class="fas fa-dice" style="font-size:13px;"></i>
                            </button>
                        </div>
                        <small class="text-secondary">Để trống để tạo tự động.</small>
                    </div>
                </div>
                <div class="mt-3 p-3 rounded-3" style="background:#fffbeb; border: 1px solid #fde68a;">
                    <small class="text-warning-emphasis"><i class="fas fa-info-circle me-1"></i>Mật khẩu sẽ được gửi tới email của người dùng. Khách hàng có thể đăng nhập ngay sau khi tạo.</small>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0 px-4 pb-4 gap-2">
                <button type="button" class="btn btn-light flex-fill py-2 rounded-3" data-bs-dismiss="modal">Hủy bỏ</button>
                <button type="submit" name="add_user" class="btn text-white flex-fill py-2 rounded-3 fw-semibold" style="background:var(--primary);">
                    <i class="fas fa-check me-1"></i>Tạo tài khoản
                </button>
            </div>
        </form>
    </div>
</div>


<!-- ============================================================ MODAL: SỬA TÀI KHOẢN -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <form class="modal-content modal-card" method="POST" id="editForm">
            <div class="modal-header-custom">
                <div class="d-flex align-items-center gap-3">
                    <div id="m_avatar" class="text-white rounded-circle d-flex align-items-center justify-content-center fw-bold flex-shrink-0"
                         style="width:48px;height:48px;font-size:20px;background:rgba(255,255,255,0.2);"></div>
                    <div>
                        <h5 class="mb-0 fw-bold">Chỉnh sửa tài khoản</h5>
                        <div class="small opacity-75" id="m_subtitle">Cập nhật thông tin người dùng</div>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" name="u_id" id="m_id">

                <!-- Thông tin hiện tại (readonly preview) -->
                <div class="edit-current-info mb-4 p-3 rounded-3" style="background:#f8fafc; border:1.5px dashed #cbd5e1;">
                    <div class="label-sm mb-2"><i class="fas fa-info-circle me-1 text-primary"></i>Thông tin hiện tại</div>
                    <div class="d-flex gap-4 flex-wrap">
                        <div><span class="text-secondary small">Mã TK:</span> <strong id="m_current_id" class="small"></strong></div>
                        <div><span class="text-secondary small">Email cũ:</span> <strong id="m_current_email" class="small"></strong></div>
                        <div><span class="text-secondary small">Vai trò cũ:</span> <span id="m_current_role" class="small"></span></div>
                        <div><span class="text-secondary small">Ngày tạo:</span> <strong id="m_current_date" class="small"></strong></div>
                    </div>
                </div>

                <!-- Form chỉnh sửa -->
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="label-sm">
                            <i class="fas fa-user me-1 text-primary"></i>Tên đăng nhập <span class="text-danger">*</span>
                        </div>
                        <div class="input-icon-wrap">
                            <i class="fas fa-user input-icon"></i>
                            <input type="text" name="u_name" id="m_name" class="form-control-custom w-100 ps-icon" required placeholder="Nhập tên đăng nhập">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="label-sm">
                            <i class="fas fa-envelope me-1 text-warning"></i>Địa chỉ Email <span class="text-danger">*</span>
                        </div>
                        <div class="input-icon-wrap">
                            <i class="fas fa-envelope input-icon"></i>
                            <input type="email" name="u_email" id="m_email" class="form-control-custom w-100 ps-icon" required placeholder="email@example.com">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="label-sm">
                            <i class="fas fa-shield-halved me-1 text-purple"></i>Vai trò & Quyền hạn <span class="text-danger">*</span>
                        </div>
                        <div class="input-icon-wrap">
                            <i class="fas fa-shield-halved input-icon"></i>
                            <select name="u_role" id="m_role" class="form-select-custom w-100 ps-icon" onchange="updateRolePreview()">
                                <option value="customer">👤 Khách hàng</option>
                                <option value="staff">👔 Nhân viên</option>
                                <option value="technician">🔧 Kỹ thuật viên</option>
                                <option value="admin">🛡️ Quản trị viên</option>
                            </select>
                        </div>
                        <div id="m_role_desc" class="mt-1 small text-secondary"></div>
                    </div>
                    <div class="col-md-6">
                        <div class="label-sm">
                            <i class="fas fa-key me-1 text-danger"></i>Mật khẩu mới
                            <span class="text-secondary fw-normal normal-case ms-1">(để trống = giữ nguyên)</span>
                        </div>
                        <div class="input-icon-wrap">
                            <i class="fas fa-lock input-icon"></i>
                            <input type="text" name="u_pass" id="m_pass" class="form-control-custom w-100 ps-icon" placeholder="Nhập mật khẩu mới nếu muốn đổi">
                            <button type="button" class="input-suffix-btn" onclick="genEditPass()" title="Tạo ngẫu nhiên">
                                <i class="fas fa-dice"></i>
                            </button>
                        </div>
                        <small class="text-secondary">Tối thiểu 6 ký tự. Kết hợp chữ + số để bảo mật hơn.</small>
                    </div>
                </div>

                <!-- Cảnh báo đổi role admin -->
                <div id="m_admin_warning" class="mt-3 p-3 rounded-3 d-none" style="background:#fef2f2; border:1px solid #fecaca;">
                    <i class="fas fa-triangle-exclamation text-danger me-2"></i>
                    <span class="small fw-semibold text-danger">Cảnh báo: Vai trò Quản trị viên có toàn quyền truy cập hệ thống!</span>
                </div>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 gap-2">
                <button type="button" class="btn btn-light flex-fill py-2 rounded-3 fw-semibold" data-bs-dismiss="modal">
                    <i class="fas fa-xmark me-1"></i>Hủy bỏ
                </button>
                <button type="submit" name="edit_user" class="btn text-white flex-fill py-2 rounded-3 fw-semibold" style="background:var(--primary);">
                    <i class="fas fa-floppy-disk me-1"></i>Lưu thay đổi
                </button>
            </div>
        </form>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ============================================================
// DATA & CONSTANTS
// ============================================================
const roleLabels    = { admin: 'Quản trị viên', staff: 'Nhân viên', technician: 'Kỹ thuật viên', customer: 'Khách hàng' };
const roleBadgeClass= { admin: 'badge-admin', staff: 'badge-staff', technician: 'badge-technician', customer: 'badge-customer' };
const roleColors    = { admin: '#ef4444', staff: '#2563eb', technician: '#d97706', customer: '#64748b' };
const roleDesc      = {
    admin:      'Toàn quyền quản trị hệ thống, quản lý người dùng và cấu hình.',
    staff:      'Xử lý đơn hàng, hỗ trợ khách hàng, xem báo cáo cơ bản.',
    technician: 'Tiếp nhận và xử lý phiếu kỹ thuật, cập nhật trạng thái sửa chữa.',
    customer:   'Đặt dịch vụ, theo dõi đơn hàng và lịch sử giao dịch cá nhân.'
};

// Lưu dữ liệu user đang xem để dùng cho các action nhanh
let _currentUser = null;

// ============================================================
// FORMAT HELPERS
// ============================================================
function formatDate(str) {
    if (!str) return '—';
    const d = new Date(str);
    if (isNaN(d)) return str.substring(0,10);
    return d.toLocaleDateString('vi-VN', { day:'2-digit', month:'2-digit', year:'numeric' })
         + ' ' + d.toLocaleTimeString('vi-VN', { hour:'2-digit', minute:'2-digit' });
}
function isNew(dateStr) {
    if (!dateStr) return false;
    return (Date.now() - new Date(dateStr).getTime()) < 86400000;
}

// ============================================================
// XEM CHI TIẾT
// ============================================================
function viewUser(u) {
    _currentUser = u;
    const role   = u.role || 'customer';
    const color  = roleColors[role] || '#64748b';
    const letter = (u.username || 'U').charAt(0).toUpperCase();
    const active = u.status == 1;

    // Avatar
    const av = document.getElementById('v_avatar');
    av.innerText = letter;
    av.style.background = color + '30';
    av.style.color = color;
    av.style.border = '3px solid ' + color + '60';

    // Header
    document.getElementById('v_username_title').innerText = u.username || '';
    document.getElementById('v_email_header').innerText   = u.email    || '';
    document.getElementById('v_status_header').innerHTML  = active
        ? '<span class="badge rounded-pill px-3 py-1 fw-semibold" style="background:rgba(34,197,94,0.2);color:#dcfce7;border:1px solid rgba(255,255,255,0.3);">● Đang hoạt động</span>'
        : '<span class="badge rounded-pill px-3 py-1 fw-semibold" style="background:rgba(239,68,68,0.2);color:#fecaca;border:1px solid rgba(255,255,255,0.2);">✕ Tài khoản bị khóa</span>';

    // Quick action buttons
    document.getElementById('v_btn_edit').className =
        'btn btn-sm flex-fill rounded-3 fw-semibold py-2 btn-outline-light';
    const toggleBtn  = document.getElementById('v_btn_toggle');
    const toggleIcon = document.getElementById('v_toggle_icon');
    const toggleText = document.getElementById('v_toggle_text');
    if (active) {
        toggleBtn.className  = 'btn btn-sm flex-fill rounded-3 fw-semibold py-2';
        toggleBtn.style.cssText = 'background:rgba(234,88,12,0.15);color:#fed7aa;border:1px solid rgba(234,88,12,0.3);';
        toggleIcon.className = 'fas fa-lock me-1';
        toggleText.innerText = 'Khóa tài khoản';
    } else {
        toggleBtn.className  = 'btn btn-sm flex-fill rounded-3 fw-semibold py-2';
        toggleBtn.style.cssText = 'background:rgba(34,197,94,0.15);color:#bbf7d0;border:1px solid rgba(34,197,94,0.3);';
        toggleIcon.className = 'fas fa-lock-open me-1';
        toggleText.innerText = 'Mở khóa';
    }

    // Info grid
    document.getElementById('v_id').innerText       = '#' + u.id;
    document.getElementById('v_username').innerText  = u.username || '—';
    document.getElementById('v_email').innerText     = u.email    || '—';
    document.getElementById('v_date').innerText      = formatDate(u.created_at);
    document.getElementById('v_role').innerHTML      =
        `<span class="role-badge ${roleBadgeClass[role]}">${roleLabels[role] || role}</span>`;
    document.getElementById('v_status').innerHTML    = active
        ? '<div class="d-flex align-items-center gap-2"><span style="width:8px;height:8px;border-radius:50%;background:#22c55e;display:inline-block;"></span><span class="small fw-semibold text-success">Hoạt động</span></div>'
        : '<div class="d-flex align-items-center gap-2"><span style="width:8px;height:8px;border-radius:50%;background:#ef4444;display:inline-block;"></span><span class="small fw-semibold text-danger">Bị khóa</span></div>';

    // New notice
    const notice = document.getElementById('v_new_notice');
    if (isNew(u.created_at)) { notice.classList.remove('d-none'); }
    else { notice.classList.add('d-none'); }

    new bootstrap.Modal(document.getElementById('viewModal')).show();
}

// Action nhanh từ modal xem
function switchToEdit() {
    bootstrap.Modal.getInstance(document.getElementById('viewModal')).hide();
    setTimeout(() => { if (_currentUser) editUser(_currentUser); }, 300);
}
function quickToggle() {
    if (_currentUser) {
        bootstrap.Modal.getInstance(document.getElementById('viewModal')).hide();
        setTimeout(() => confirmToggle(_currentUser.id, _currentUser.username, _currentUser.status == 1 ? 1 : 0, _currentUser.role), 300);
    }
}
function quickDelete() {
    if (_currentUser) {
        bootstrap.Modal.getInstance(document.getElementById('viewModal')).hide();
        setTimeout(() => confirmDelete(_currentUser.id, _currentUser.username, _currentUser.email, _currentUser.role), 300);
    }
}

// ============================================================
// SỬA TÀI KHOẢN
// ============================================================
function editUser(u) {
    const role  = u.role || 'customer';
    const color = roleColors[role] || '#64748b';

    document.getElementById('m_id').value       = u.id;
    document.getElementById('m_name').value      = u.username || '';
    document.getElementById('m_email').value     = u.email    || '';
    document.getElementById('m_role').value      = role;
    document.getElementById('m_pass').value      = '';

    // Avatar
    const av = document.getElementById('m_avatar');
    av.innerText = (u.username || 'U').charAt(0).toUpperCase();
    av.style.background = color + '40';
    av.style.color = 'white';

    // Subtitle
    document.getElementById('m_subtitle').innerText = 'Đang sửa: #' + u.id + ' — ' + (u.email || '');

    // Preview hiện tại
    document.getElementById('m_current_id').innerText    = '#' + u.id;
    document.getElementById('m_current_email').innerText = u.email || '—';
    document.getElementById('m_current_date').innerText  = u.created_at ? u.created_at.substring(0,10) : '—';
    document.getElementById('m_current_role').innerHTML  =
        `<span class="role-badge ${roleBadgeClass[role]}">${roleLabels[role]}</span>`;

    updateRolePreview();
    new bootstrap.Modal(document.getElementById('editModal')).show();
}

function updateRolePreview() {
    const role = document.getElementById('m_role').value;
    document.getElementById('m_role_desc').innerText = roleDesc[role] || '';
    const warn = document.getElementById('m_admin_warning');
    if (role === 'admin') warn.classList.remove('d-none');
    else warn.classList.add('d-none');
}

function genEditPass() {
    const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789@#!';
    let pass = '';
    for (let i = 0; i < 10; i++) pass += chars[Math.floor(Math.random() * chars.length)];
    document.getElementById('m_pass').value = pass;
}

// ============================================================
// KHÓA / MỞ KHÓA VỚI CONFIRM
// ============================================================
function confirmToggle(userId, userName, currentStatus, role) {
    const isLocking = currentStatus == 1;
    Swal.fire({
        title: isLocking ? 'Khóa tài khoản?' : 'Mở khóa tài khoản?',
        html: `
            <div style="text-align:left; margin-top:8px;">
                <div style="background:#f8fafc;border-radius:10px;padding:14px 16px;margin-bottom:12px;">
                    <div style="font-size:12px;color:#64748b;margin-bottom:4px;">TÀI KHOẢN</div>
                    <div style="font-weight:700;font-size:16px;">${userName}</div>
                    <div style="font-size:13px;color:#64748b;">Vai trò: ${roleLabels[role] || role}</div>
                </div>
                <p style="font-size:14px;color:#475569;margin:0;">
                    ${isLocking
                        ? 'Người dùng <strong>sẽ không thể đăng nhập</strong> vào hệ thống cho đến khi được mở khóa.'
                        : 'Người dùng <strong>sẽ đăng nhập được</strong> trở lại ngay sau khi mở khóa.'}
                </p>
            </div>`,
        icon: isLocking ? 'warning' : 'question',
        showCancelButton: true,
        confirmButtonColor: isLocking ? '#ea580c' : '#16a34a',
        cancelButtonColor: '#94a3b8',
        confirmButtonText: isLocking
            ? '<i class="fas fa-lock me-1"></i>Xác nhận khóa'
            : '<i class="fas fa-lock-open me-1"></i>Xác nhận mở khóa',
        cancelButtonText: 'Hủy bỏ',
        customClass: { popup: 'rounded-4' }
    }).then((result) => {
        if (result.isConfirmed) {
            const current = isLocking ? 1 : 0;
            window.location.href = `users.php?toggle_status=${userId}&current=${current}&tab=<?= $tab ?>`;
        }
    });
}

// ============================================================
// XÓA TÀI KHOẢN
// ============================================================
function confirmDelete(userId, userName, userEmail, userRole) {
    Swal.fire({
        title: 'Xóa tài khoản vĩnh viễn?',
        html: `
            <div style="text-align:left; margin-top:8px;">
                <div style="background:#fef2f2;border:1.5px solid #fecaca;border-radius:10px;padding:14px 16px;margin-bottom:12px;">
                    <div style="font-size:12px;color:#ef4444;font-weight:700;margin-bottom:6px;">
                        <i class="fas fa-user me-1"></i> THÔNG TIN TÀI KHOẢN SẼ XÓA
                    </div>
                    <div style="font-weight:700;font-size:15px;margin-bottom:2px;">${userName}</div>
                    <div style="font-size:13px;color:#64748b;margin-bottom:2px;">${userEmail || ''}</div>
                    <div style="font-size:12px;margin-top:4px;">
                        <span style="background:#fee2e2;color:#ef4444;padding:2px 8px;border-radius:6px;font-weight:700;font-size:11px;">
                            ${roleLabels[userRole] || userRole}
                        </span>
                    </div>
                </div>
                <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;font-size:13px;color:#78350f;">
                    <i class="fas fa-triangle-exclamation me-1"></i>
                    Hành động này <strong>không thể hoàn tác</strong>. Toàn bộ dữ liệu liên quan sẽ bị mất.
                </div>
            </div>`,
        icon: 'error',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#64748b',
        confirmButtonText: '<i class="fas fa-trash-can me-1"></i>Xóa vĩnh viễn',
        cancelButtonText: '<i class="fas fa-xmark me-1"></i>Hủy bỏ',
        customClass: { popup: 'rounded-4' },
        focusCancel: true
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = `users.php?del=${userId}&tab=<?= $tab ?>`;
        }
    });
}

// ============================================================
// TẠO MẬT KHẨU NGẪU NHIÊN (FORM TẠO MỚI)
// ============================================================
function generatePass() {
    const chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789@#!';
    let pass = '';
    for (let i = 0; i < 10; i++) pass += chars[Math.floor(Math.random() * chars.length)];
    document.getElementById('gen_pass_field').value = pass;
}

function copyPass() {
    const t = document.getElementById('gen_pass').innerText;
    navigator.clipboard.writeText(t).then(() => {
        Swal.fire({ icon: 'success', title: 'Đã sao chép!', text: 'Mật khẩu đã được sao chép vào clipboard.', timer: 1500, showConfirmButton: false });
    });
}
</script>
</body>
</html>