<?php
session_start();
require_once '../db.php';

// Kiểm tra quyền admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../index.php");
    exit();
}

if (!isset($_GET['id'])) {
    header("Location: quan_ly_khach_hang.php");
    exit();
}

$maPhieu = (int)$_GET['id'];

// Lấy thông tin phiếu sửa chữa
$phieu = $conn->query("SELECT * FROM phieu_sua_chua WHERE maPhieu = $maPhieu")->fetch_assoc();
if (!$phieu) {
    die("Không tìm thấy phiếu sửa chữa!");
}

// Lấy thông tin khách hàng
$khach = $conn->query("SELECT * FROM khach_hang WHERE maKH = " . $phieu['maKH'])->fetch_assoc();

// Lấy danh sách báo giá chi tiết
$bao_gia = $conn->query("SELECT * FROM chi_tiet_bao_gia WHERE maPhieu = $maPhieu");

// Load TCPDF
require_once('../tcpdf/tcpdf.php');

// Tạo class mở rộng TCPDF để thêm header/footer tùy chỉnh
class MYPDF extends TCPDF {
    public function Header() {
        // Logo (nếu có)
        // $this->Image('logo.png', 10, 5, 30);
        $this->SetFont('helvetica', 'B', 14);
        $this->Cell(0, 10, 'CÔNG TY TNHH TM & PT CN QUANG ANH', 0, 1, 'C');
        $this->SetFont('helvetica', '', 10);
        $this->Cell(0, 5, 'CS1: 57 Nguyễn Bình, Hải Phòng | CS2: 81 Quán Nam, Hải Phòng', 0, 1, 'C');
        $this->Cell(0, 5, 'CS Kỹ thuật: 59 Nguyễn Bình | ☎ 0982.459.566', 0, 1, 'C');
        $this->Ln(5);
        $this->SetLineWidth(0.5);
        $this->Line(10, 35, 200, 35);
    }
    
    public function Footer() {
        $this->SetY(-15);
        $this->SetFont('helvetica', 'I', 8);
        $this->Cell(0, 10, 'Trang ' . $this->getAliasNumPage() . '/' . $this->getAliasNbPages(), 0, false, 'C', 0, '', 0, false, 'T', 'M');
    }
}

// Tạo PDF mới
$pdf = new MYPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Thiết lập thông tin tài liệu
$pdf->SetCreator('Quang Anh Tech');
$pdf->SetAuthor('Quang Anh');
$pdf->SetTitle('Phieu Sua Chua #SC-' . $maPhieu);
$pdf->SetSubject('Phieu sua chua');
$pdf->SetKeywords('TCPDF, PDF, sua chua, bao hanh');

// Thiết lập margin
$pdf->SetMargins(15, 40, 15);
$pdf->SetHeaderMargin(10);
$pdf->SetFooterMargin(15);

// Thêm trang
$pdf->AddPage();

// Nội dung PDF
$html = '
<style>
    .header-title {
        text-align: center;
        font-size: 18px;
        font-weight: bold;
        color: #d9534f;
        margin: 20px 0;
    }
    .info-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 15px;
    }
    .info-table td {
        padding: 8px;
        border: 1px solid #ddd;
    }
    .info-table td.label {
        width: 30%;
        font-weight: bold;
        background-color: #f5f5f5;
    }
    .product-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 15px;
    }
    .product-table th {
        background-color: #f5f5f5;
        border: 1px solid #ddd;
        padding: 8px;
        text-align: center;
        font-weight: bold;
    }
    .product-table td {
        border: 1px solid #ddd;
        padding: 8px;
    }
    .total {
        text-align: right;
        font-weight: bold;
        font-size: 14px;
        margin-top: 10px;
    }
    .signature {
        margin-top: 40px;
        display: flex;
        justify-content: space-between;
    }
    .signature div {
        text-align: center;
        width: 45%;
    }
    .warranty-policy {
        margin-top: 20px;
        padding: 10px;
        background-color: #fffef7;
        border: 1px solid #ffc107;
        border-radius: 5px;
        font-size: 10px;
    }
    .warranty-policy h4 {
        text-align: center;
        color: #d9534f;
        margin-bottom: 10px;
    }
    .status-badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 15px;
        font-size: 11px;
        font-weight: bold;
    }
    .status-processing { background-color: #17a2b8; color: white; }
    .status-done { background-color: #28a745; color: white; }
    .status-waiting { background-color: #ffc107; color: #333; }
    .status-pending { background-color: #6c757d; color: white; }
</style>

<h2 class="header-title">PHIẾU SỬA CHỮA #SC-' . $maPhieu . '</h2>

<!-- Thông tin khách hàng và thiết bị -->
<table class="info-table">
    <tr>
        <td class="label">Khách hàng:</td>
        <td>' . htmlspecialchars($khach['tenKH'] ?? '—') . '</td>
        <td class="label">SĐT:</td>
        <td>' . htmlspecialchars($khach['soDienThoai'] ?? '—') . '</td>
    </tr>
    <tr>
        <td class="label">Email:</td>
        <td>' . htmlspecialchars($khach['email'] ?? '—') . '</td>
        <td class="label">Địa chỉ:</td>
        <td>' . htmlspecialchars($khach['diaChi'] ?? '—') . '</td>
    </tr>
    <tr>
        <td class="label">Thiết bị:</td>
        <td colspan="3">' . htmlspecialchars($phieu['tenThietBi'] ?? '—') . '</td>
    </tr>
    <tr>
        <td class="label">Ngày nhận:</td>
        <td>' . date('d/m/Y', strtotime($phieu['ngayNhan'])) . '</td>
        <td class="label">Ngày trả (dự kiến):</td>
        <td>' . (!empty($phieu['ngayTra']) ? date('d/m/Y', strtotime($phieu['ngayTra'])) : '—') . '</td>
    </tr>
    <tr>
        <td class="label">Trạng thái:</td>
        <td colspan="3">';

// Xác định class cho trạng thái
$status_class = 'status-pending';
if ($phieu['trangThai'] == 'Đang xử lý' || $phieu['trangThai'] == 'Đang kiểm tra') $status_class = 'status-processing';
if ($phieu['trangThai'] == 'Đã bàn giao' || $phieu['trangThai'] == 'Đã sửa xong') $status_class = 'status-done';
if ($phieu['trangThai'] == 'Chờ linh kiện') $status_class = 'status-waiting';

$html .= '<span class="status-badge ' . $status_class . '">' . htmlspecialchars($phieu['trangThai']) . '</span>';
$html .= '</td></tr>
    <tr>
        <td class="label">Mô tả lỗi:</td>
        <td colspan="3">' . nl2br(htmlspecialchars($phieu['moTaLoi'] ?? '—')) . '</td>
    </tr>
</table>

<!-- Báo giá chi tiết -->
<h3>📋 BÁO GIÁ CHI TIẾT</h3>
<table class="product-table">
    <thead>
        <tr>
            <th style="width:15%">Loại</th>
            <th style="width:45%">Tên linh kiện / Dịch vụ</th>
            <th style="width:10%">SL</th>
            <th style="width:15%">Đơn giá</th>
            <th style="width:15%">Thành tiền</th>
        </tr>
    </thead>
    <tbody>';

$tong = 0;
if ($bao_gia && $bao_gia->num_rows > 0) {
    while ($bg = $bao_gia->fetch_assoc()) {
        $thanh_tien = $bg['soLuong'] * $bg['donGia'];
        $tong += $thanh_tien;
        $html .= '<tr>
            <td>' . htmlspecialchars($bg['loaiHang']) . '</td>
            <td>' . htmlspecialchars($bg['tenHang']) . '</td>
            <td style="text-align:center">' . $bg['soLuong'] . '</td>
            <td style="text-align:right">' . number_format($bg['donGia'], 0, ',', '.') . ' đ</td>
            <td style="text-align:right">' . number_format($thanh_tien, 0, ',', '.') . ' đ</td>
        </tr>';
    }
} else {
    $html .= '<tr><td colspan="5" style="text-align:center">Không có báo giá chi tiết</td></tr>';
}

$html .= '</tbody>
    <tfoot>
        <tr style="background-color:#f5f5f5">
            <td colspan="4" style="text-align:right"><strong>TỔNG CỘNG:</strong></td>
            <td style="text-align:right;color:#d9534f"><strong>' . number_format($phieu['chiPhi'], 0, ',', '.') . ' đ</strong></td>
        </tr>
    </tfoot>
</table>

<!-- Chính sách bảo hành -->
<div class="warranty-policy">
    <h4>CHÍNH SÁCH BẢO HÀNH - MÁY TÍNH QUANG ANH</h4>
    <p><strong>• Laptop NEW:</strong> Bảo hành 12 tháng theo quy định của hãng (đổi mới trong vòng 30 ngày nếu lỗi phần cứng).</p>
    <p><strong>• Laptop CŨ:</strong> Bảo hành 3-6 tháng 1 ĐỔI (tùy mã máy, trong 15 ngày được đổi máy khác không cần lý do).</p>
    <p>✅ Cài win, hỗ trợ phần mềm (không bản quyền): <strong>MIỄN PHÍ trọn đời máy</strong>.</p>
    <p>✅ Vệ sinh chuyên sâu: <strong>MIỄN PHÍ 24 tháng</strong>, sau đó 50.000đ/lần (máy ngoài 150.000đ/lần).</p>
    <hr>
    <p><strong>📋 ĐIỀU KIỆN NHẬN BẢO HÀNH MIỄN PHÍ (phải đủ 3 điều kiện):</strong></p>
    <ol>
        <li>Tem niêm phong Quang Anh còn nguyên vẹn, không bị bong rách.</li>
        <li>Sản phẩm KHÔNG có dấu hiệu hư hỏng ngoại quan (dính nước, vỡ, móp, trầy tróc...).</li>
        <li>Máy phát sinh LỖI KỸ THUẬT từ nhà sản xuất (không do người dùng).</li>
    </ol>
    <hr>
    <p class="text-center">📞 Mọi thắc mắc xin liên hệ: <strong>0934322199</strong></p>
</div>

<!-- Chữ ký -->
<div class="signature">
    <div>
        <p><strong>Khách hàng ký tên</strong></p>
        <br><br><br>
        <small>(Ký, ghi rõ họ tên)</small>
    </div>
    <div>
        <p><strong>Đại diện Quang Anh Tech</strong></p>
        <br><br><br>
        <small>(Ký, đóng dấu)</small>
    </div>
</div>';

// Xuất PDF
$pdf->writeHTML($html, true, false, true, false, '');

// Đóng và xuất file
$pdf->Output('Phieu_Sua_Chua_SC_' . $maPhieu . '.pdf', 'I');
?>