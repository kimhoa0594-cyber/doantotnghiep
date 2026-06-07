<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$conn = new mysqli('localhost', 'root', '', 'quanganh_db', 3307);

if ($conn->connect_error) {
    die("<div style='color:red;font-family:sans-serif;padding:20px;'>
        ❌ Kết nối thất bại: <strong>" . $conn->connect_error . "</strong>
    </div>");
}
$conn->set_charset("utf8mb4");

$users = [
    ['id' => 1,  'username' => 'admin',        'new_password' => 'Admin@123'],
    ['id' => 16, 'username' => 'hoa59',         'new_password' => 'Hoa@123'],
    ['id' => 17, 'username' => 'anhngoc',       'new_password' => 'Anhngoc@123'],
    ['id' => 18, 'username' => 'hongduyen',     'new_password' => 'Hongduyen@123'],
    ['id' => 19, 'username' => 'sen',           'new_password' => 'Sen@123'],
    ['id' => 21, 'username' => 'phamsen',       'new_password' => 'Phamsen@123'],
    ['id' => 22, 'username' => 'nhanvien1',     'new_password' => 'Nhanvien@123'],
    ['id' => 24, 'username' => 'kythuatvien1',  'new_password' => 'Kythuatvien@123'],
];

$results = [];
foreach ($users as $u) {
    $hash = password_hash($u['new_password'], PASSWORD_DEFAULT);
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $stmt->bind_param("si", $hash, $u['id']);
    $stmt->execute();
    $results[] = [
        'username' => $u['username'],
        'password' => $u['new_password'],
        'status'   => $stmt->affected_rows > 0 ? 'success' : 'skip',
    ];
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<title>Reset Mật Khẩu</title>
<style>
  body { font-family:'Segoe UI',sans-serif; background:#f3f4f6; padding:40px; }
  .card { background:white; border-radius:12px; padding:30px; max-width:620px; margin:auto; box-shadow:0 4px 20px rgba(0,0,0,.1); }
  h2 { color:#047857; text-align:center; margin-bottom:20px; }
  table { width:100%; border-collapse:collapse; font-size:14px; }
  th { background:#047857; color:white; padding:10px 12px; text-align:left; }
  td { padding:10px 12px; border-bottom:1px solid #e5e7eb; }
  .ok   { background:#d1fae5; color:#065f46; padding:2px 10px; border-radius:20px; font-size:12px; }
  .skip { background:#fef3c7; color:#92400e; padding:2px 10px; border-radius:20px; font-size:12px; }
  .warn { margin-top:20px; background:#fef3c7; border:1px solid #fcd34d; border-radius:8px; padding:12px 16px; font-size:13px; color:#92400e; }
</style>
</head>
<body>
<div class="card">
  <h2>✅ Reset Mật Khẩu Hoàn Tất</h2>
  <table>
    <tr><th>#</th><th>Username</th><th>Mật khẩu mới</th><th>Kết quả</th></tr>
    <?php foreach ($results as $i => $r): ?>
    <tr>
      <td><?= $i+1 ?></td>
      <td><strong><?= htmlspecialchars($r['username']) ?></strong></td>
      <td><code><?= htmlspecialchars($r['password']) ?></code></td>
      <td>
        <?php if ($r['status'] === 'success'): ?>
          <span class="ok">✔ Thành công</span>
        <?php else: ?>
          <span class="skip">⚠ ID không tồn tại</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <div class="warn">
    ⚠️ <strong>Xóa file <code>reset_passwords.php</code> ngay sau khi dùng xong!</strong>
  </div>
</div>
</body>
</html>