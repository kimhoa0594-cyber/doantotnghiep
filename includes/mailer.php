<?php
// Tự động tìm đường dẫn chính xác đến thư mục vendor
$vendorPath = dirname(__FILE__) . '/../vendor/PHPMailer/';

require $vendorPath . 'Exception.php';
require $vendorPath . 'PHPMailer.php';
require $vendorPath . 'SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendQuangAnhMail($toEmail, $subject, $message) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'kimhoa0594@gmail.com'; // Thay bằng Gmail của bạn
        $mail->Password   = 'vklbveowahggxnsq';    // Thay bằng mật khẩu ứng dụng 16 số
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom('no-reply@quanganh.com', 'Quang Anh Tech');
        $mail->addAddress($toEmail);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $message;

        $mail->send();
        return true;
    } catch (Exception $e) {
        // Ghi log lỗi nếu cần: error_log($mail->ErrorInfo);
        return false;
    }
}