<?php
// Kết nối CSDL (Bạn nhớ thay đổi thông tin kết nối cho phù hợp với file db của bạn)
$conn = new mysqli("localhost", "root", "", "quanganh_db", 3307);
if ($conn->connect_error) {
    die("Kết nối thất bại: " . $conn->connect_error);
}

// Truy vấn danh sách khách hàng
$sql = "SELECT * FROM khach_hang ORDER BY ngayDangKy DESC";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Quản lý Khách hàng - Công ty Quang Anh</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Quản lý Khách hàng</h2>
        <a href="them_khach_hang.php" class="btn btn-success">+ Thêm Khách Hàng Mới</a>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <table class="table table-bordered table-hover">
                <thead class="table-dark">
                    <tr>
                        <th>Mã KH</th>
                        <th>Tên Khách Hàng</th>
                        <th>Số Điện Thoại</th>
                        <th>Email</th>
                        <th>Loại/Trạng thái vòng đời</th>
                        <th>Ngày Đăng Ký</th>
                        <th>Trạng Thái</th>
                        <th>Hành Động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($result->num_rows > 0): ?>
                        <?php while($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo $row['maKH']; ?></td>
                            <td><strong><?php echo htmlspecialchars($row['tenKH']); ?></strong></td>
                            <td><?php echo htmlspecialchars($row['soDienThoai']); ?></td>
                            <td><?php echo htmlspecialchars($row['email']); ?></td>
                            <td>
                                <?php 
                                    $badge = 'bg-secondary';
                                    if($row['loaiKhachHang'] == 'Quan tâm') $badge = 'bg-info';
                                    if($row['loaiKhachHang'] == 'Đã sử dụng') $badge = 'bg-primary';
                                    if($row['loaiKhachHang'] == 'Quay lại') $badge = 'bg-success';
                                    if($row['loaiKhachHang'] == 'Thân thiết') $badge = 'bg-warning text-dark';
                                    if($row['loaiKhachHang'] == 'Trung thành') $badge = 'bg-danger';
                                    if($row['loaiKhachHang'] == 'Ngừng') $badge = 'bg-dark';
                                ?>
                                <span class="badge <?php echo $badge; ?>">
                                    <?php echo htmlspecialchars($row['loaiKhachHang']); ?>
                                </span>
                            </td>
                            <td><?php echo date('d/m/Y', strtotime($row['ngayDangKy'])); ?></td>
                            <td>
                                <?php echo ($row['trangThai'] == 1) ? '<span class="text-success">Hoạt động</span>' : '<span class="text-danger">Khóa</span>'; ?>
                            </td>
                            <td>
                                <a href="chi_tiet_khach_hang.php?id=<?php echo $row['maKH']; ?>" class="btn btn-sm btn-primary">Chi tiết / Chăm sóc</a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center">Chưa có khách hàng nào trong hệ thống.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>