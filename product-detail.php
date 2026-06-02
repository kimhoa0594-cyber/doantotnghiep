<?php
require_once 'db.php';
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$result = $conn->query("SELECT * FROM ads WHERE id = $id");
$product = $result->fetch_assoc();

if (!$product) { die("Sản phẩm không tồn tại!"); }
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title><?= $product['title'] ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .product-title { font-weight: 700; font-size: 28px; color: #1e1e2d; }
        .price-new { color: #d70018; font-size: 24px; font-weight: 700; }
        .price-old { text-decoration: line-through; color: #707070; margin-left: 10px; }
        .content-box { background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #eee; margin-top: 20px; }
        img { max-width: 100%; height: auto; }
    </style>
</head>
<body class="bg-light">
    <div class="container py-5">
        <div class="row">
            <div class="col-md-6">
                <img src="<?= $product['image'] ?>" class="rounded shadow-sm w-100">
            </div>
            <div class="col-md-6">
                <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item">Sản phẩm</li><li class="breadcrumb-item active"><?= $product['categories'] ?></li></ol></nav>
                <h1 class="product-title"><?= $product['title'] ?></h1>
                <div class="my-3">
                    <span class="price-new"><?= number_format($product['new_p']) ?>đ</span>
                    <span class="price-old"><?= number_format($product['old_p']) ?>đ</span>
                </div>
                <div class="alert alert-info">Bảo hành 12 tháng - Trả góp 0%</div>
                <button class="btn btn-danger btn-lg w-100 fw-bold">MUA NGAY</button>
            </div>
        </div>
        
        <div class="content-box">
            <h4 class="fw-bold border-bottom pb-2">ĐẶC ĐIỂM NỔI BẬT</h4>
            <div class="mt-3">
                <?= $product['content'] // Đây là nội dung từ TinyMCE ?>
            </div>
        </div>
    </div>
</body>
</html>