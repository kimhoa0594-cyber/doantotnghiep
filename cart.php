<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$uid = intval($_SESSION['user_id']);

$uname    = htmlspecialchars($_SESSION['fullname'] ?? '');
$uphone   = htmlspecialchars($_SESSION['phone']    ?? '');
$uemail   = htmlspecialchars($_SESSION['email']    ?? '');
$uaddress = htmlspecialchars($_SESSION['address']  ?? '');

/* ── Tự tạo bảng cart nếu chưa có ── */
if ($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS `cart` (
        `id`          INT AUTO_INCREMENT PRIMARY KEY,
        `user_id`     INT NOT NULL,
        `product_key` VARCHAR(255) NOT NULL,
        `name`        VARCHAR(500) NOT NULL,
        `price`       BIGINT NOT NULL DEFAULT 0,
        `image`       TEXT DEFAULT NULL,
        `quantity`    INT NOT NULL DEFAULT 1,
        `added_at`    DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY `uk_user_product` (`user_id`,`product_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* ── Xử lý action GET (remove/update/clear) ── */
if (isset($_GET['action']) && $conn) {
    $act     = $_GET['action'];
    $cart_id = intval($_GET['id'] ?? 0);
    if ($act === 'remove' && $cart_id) {
        $s = $conn->prepare("DELETE FROM cart WHERE id=? AND user_id=?");
        $s->bind_param('ii', $cart_id, $uid); $s->execute(); $s->close();
    }
    if ($act === 'update' && $cart_id && isset($_GET['qty'])) {
        $qty = intval($_GET['qty']);
        if ($qty > 0) {
            $s = $conn->prepare("UPDATE cart SET quantity=? WHERE id=? AND user_id=?");
            $s->bind_param('iii', $qty, $cart_id, $uid);
        } else {
            $s = $conn->prepare("DELETE FROM cart WHERE id=? AND user_id=?");
            $s->bind_param('ii', $cart_id, $uid);
        }
        $s->execute(); $s->close();
    }
    if ($act === 'clear') {
        $s = $conn->prepare("DELETE FROM cart WHERE user_id=?");
        $s->bind_param('i', $uid); $s->execute(); $s->close();
    }
    header("Location: cart.php"); exit;
}

/* ── Lấy giỏ hàng từ DB ── */
$cart_items  = [];
$subtotal    = 0;
$total_items = 0;
if ($conn) {
    $s = $conn->prepare("SELECT * FROM cart WHERE user_id=? ORDER BY added_at DESC");
    $s->bind_param('i', $uid); $s->execute();
    $r = $s->get_result();
    while ($row = $r->fetch_assoc()) {
        $cart_items[] = $row;
        $subtotal    += $row['price'] * $row['quantity'];
        $total_items += $row['quantity'];
    }
    $s->close();
}

$ordered_id = isset($_GET['ordered']) ? intval($_GET['ordered']) : 0;
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Giỏ hàng – Máy Tính Quang Anh</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
    --g1:#065f46;--g2:#047857;--g3:#059669;--g4:#10b981;
    --acc:#f59e0b;--red:#ef4444;--blue:#3b82f6;
    --txt:#0f172a;--txt2:#334155;--muted:#64748b;
    --border:#e2e8f0;--border2:#d1fae5;--bg:#f0fdf4;--white:#fff;
    --sh:0 4px 20px rgba(6,95,70,.08);
}
body{background:var(--bg);font-family:'Nunito',sans-serif;color:var(--txt);font-size:14px;}
a{text-decoration:none;color:inherit;}img{max-width:100%;display:block;}
button,input,textarea,select{font-family:inherit;}

/* HEADER */
.hdr{background:linear-gradient(135deg,var(--g1),var(--g2));padding:0 24px;height:64px;
     display:flex;align-items:center;justify-content:space-between;
     box-shadow:0 4px 20px rgba(6,95,70,.3);position:sticky;top:0;z-index:100;}
.hdr-logo{font-weight:900;font-size:20px;color:#fff;letter-spacing:1px;display:flex;align-items:center;gap:10px;}
.hdr-logo span{background:rgba(255,255,255,.15);border-radius:8px;padding:4px 10px;font-size:11px;font-weight:700;letter-spacing:2px;}
.hdr-back{color:rgba(255,255,255,.85);font-weight:700;font-size:13px;display:flex;align-items:center;gap:6px;
          padding:8px 16px;border-radius:9px;border:1.5px solid rgba(255,255,255,.3);transition:.2s;}
.hdr-back:hover{background:rgba(255,255,255,.1);color:#fff;}

/* LAYOUT */
.wrap{max-width:1180px;margin:32px auto;padding:0 18px;}
.page-title{font-size:22px;font-weight:900;color:var(--g1);margin-bottom:24px;display:flex;align-items:center;gap:10px;}
.page-title i{color:var(--g3);}
.grid{display:grid;grid-template-columns:1fr 400px;gap:24px;align-items:start;}

/* PANEL */
.panel{background:var(--white);border-radius:16px;box-shadow:var(--sh);overflow:hidden;}
.panel-hdr{padding:18px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.panel-hdr-title{font-weight:800;font-size:15px;color:var(--txt);display:flex;align-items:center;gap:8px;}
.panel-hdr-title i{color:var(--g3);}
.clear-btn{color:var(--red);font-size:12px;font-weight:700;display:flex;align-items:center;gap:5px;
           background:none;border:1.5px solid #fecaca;border-radius:8px;padding:5px 12px;cursor:pointer;transition:.2s;}
.clear-btn:hover{background:#fef2f2;}

/* TABLE */
.cart-tbl{width:100%;border-collapse:collapse;}
.cart-tbl th{padding:12px 16px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;
              color:var(--muted);text-align:left;border-bottom:2px solid var(--border);}
.cart-tbl td{padding:16px;border-bottom:1px solid #f8fafc;vertical-align:middle;}
.cart-tbl tr:last-child td{border-bottom:none;}
.cart-tbl tr:hover td{background:#fafffe;}
.pd-cell{display:flex;align-items:center;gap:14px;}
.pd-img{width:68px;height:68px;border-radius:10px;border:1px solid var(--border);object-fit:contain;background:#f8fafc;padding:4px;flex-shrink:0;}
.pd-ico{width:68px;height:68px;border-radius:10px;border:1px solid var(--border);background:#f0fdf4;
        display:flex;align-items:center;justify-content:center;font-size:28px;flex-shrink:0;}
.pd-name{font-weight:700;font-size:13.5px;color:var(--txt2);line-height:1.4;}
.pd-price-sm{font-size:12px;color:var(--muted);margin-top:3px;}
.qty-ctrl{display:inline-flex;align-items:center;background:#f8fafc;border:1.5px solid var(--border);
          border-radius:30px;padding:3px;gap:2px;}
.qty-btn{width:28px;height:28px;border-radius:50%;border:none;background:transparent;
         color:var(--muted);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:11px;transition:.15s;}
.qty-btn:hover{background:var(--border);color:var(--g1);}
.qty-num{width:36px;text-align:center;font-weight:800;font-size:13px;color:var(--txt);}
.line-total{font-weight:800;font-size:15px;color:var(--g2);}
.rm-btn{color:#cbd5e1;background:none;border:none;cursor:pointer;font-size:16px;
        padding:8px;border-radius:8px;transition:.2s;}
.rm-btn:hover{color:var(--red);background:#fef2f2;}

/* EMPTY */
.empty{padding:64px 24px;text-align:center;}
.empty-ico{font-size:72px;margin-bottom:16px;opacity:.3;}
.empty-txt{font-size:17px;font-weight:700;color:var(--muted);margin-bottom:20px;}
.shop-btn{background:var(--g2);color:#fff;padding:12px 28px;border-radius:11px;
          font-weight:800;font-size:14px;display:inline-flex;align-items:center;gap:8px;transition:.2s;}
.shop-btn:hover{background:var(--g1);}

/* ORDER SUMMARY */
.sum-title{font-size:16px;font-weight:900;color:var(--txt);padding:18px 24px;border-bottom:1px solid var(--border);}
.sum-body{padding:20px 24px;}
.sum-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;font-size:13.5px;}
.sum-row .lbl{color:var(--muted);}
.sum-row .val{font-weight:700;color:var(--txt);}
.sum-row.total{margin-top:16px;padding-top:16px;border-top:2px solid var(--border);margin-bottom:0;}
.sum-row.total .lbl{font-size:14px;font-weight:800;color:var(--txt);}
.sum-row.total .val{font-size:22px;font-weight:900;color:var(--red);}
.checkout-btn{display:flex;align-items:center;justify-content:center;gap:9px;
              width:100%;margin-top:18px;padding:15px;
              background:linear-gradient(90deg,var(--g1),var(--g3));
              color:#fff;border:none;border-radius:12px;font-size:15px;font-weight:900;
              cursor:pointer;font-family:inherit;transition:.25s;
              box-shadow:0 4px 16px rgba(6,95,70,.25);}
.checkout-btn:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(6,95,70,.3);}
.checkout-btn:disabled{opacity:.5;cursor:not-allowed;transform:none;}
.promo-list{display:flex;flex-direction:column;gap:8px;margin-top:16px;}
.promo-item{display:flex;align-items:center;gap:9px;font-size:12px;color:var(--g2);font-weight:700;
            background:#f0fdf4;border:1px solid #d1fae5;border-radius:9px;padding:9px 12px;}
.promo-item i{color:var(--g3);font-size:13px;}

/* SUCCESS BOX */
.success-box{background:#f0fdf4;border:2px solid #a7f3d0;border-radius:18px;padding:40px;text-align:center;max-width:540px;margin:0 auto;}
.success-ico{font-size:64px;margin-bottom:14px;}
.success-title{font-size:22px;font-weight:900;color:var(--g1);margin-bottom:8px;}
.success-sub{color:var(--muted);font-size:14px;margin-bottom:20px;line-height:1.7;}
.success-id{background:var(--g1);color:#fff;border-radius:9px;padding:9px 22px;font-weight:800;font-size:16px;display:inline-block;margin-bottom:22px;letter-spacing:1px;}

/* CHECKOUT MODAL */
.co-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:500;
          align-items:flex-start;justify-content:center;padding:20px;overflow-y:auto;}
.co-modal.open{display:flex;}
.co-box{background:#fff;border-radius:20px;width:100%;max-width:580px;margin:auto;
        box-shadow:0 24px 60px rgba(0,0,0,.2);}
.co-hdr{background:linear-gradient(135deg,var(--g1),var(--g3));color:#fff;padding:22px 26px;
        border-radius:20px 20px 0 0;display:flex;align-items:center;justify-content:space-between;}
.co-hdr-title{font-size:18px;font-weight:900;display:flex;align-items:center;gap:10px;}
.co-close{background:rgba(255,255,255,.15);border:none;color:#fff;width:36px;height:36px;
          border-radius:50%;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;transition:.2s;}
.co-close:hover{background:rgba(255,255,255,.3);}
.co-body{padding:28px 26px;}

/* Form */
.co-section{font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.7px;
            color:var(--g2);margin:22px 0 12px;display:flex;align-items:center;gap:8px;}
.co-section::after{content:'';flex:1;height:1.5px;background:var(--border2);}
.co-section:first-child{margin-top:0;}
.form-group{margin-bottom:14px;}
.form-label{font-size:12px;font-weight:700;color:var(--txt2);margin-bottom:6px;display:block;}
.form-label .req{color:var(--red);margin-left:2px;}
.form-input{width:100%;padding:12px 14px;border:1.5px solid var(--border);border-radius:10px;
            font-size:13.5px;color:var(--txt);transition:.2s;outline:none;background:#fff;}
.form-input:focus{border-color:var(--g3);box-shadow:0 0 0 3px rgba(5,150,105,.12);}
.form-input.error{border-color:var(--red);box-shadow:0 0 0 3px rgba(239,68,68,.1);}
textarea.form-input{resize:vertical;min-height:75px;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}

/* Phương thức thanh toán */
.pay-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;}
.pay-opt{cursor:pointer;}
.pay-opt input{display:none;}
.pay-card{border:2px solid var(--border);border-radius:12px;padding:13px 10px;text-align:center;transition:.2s;background:#fff;}
.pay-opt input:checked + .pay-card{border-color:var(--g3);background:#f0fdf4;box-shadow:0 0 0 3px rgba(5,150,105,.1);}
.pay-ico{font-size:24px;margin-bottom:5px;}
.pay-lbl{font-size:12px;font-weight:800;color:var(--txt2);}
.pay-sub{font-size:10px;color:var(--muted);margin-top:2px;}

/* Bank / Ewallet info */
.pay-info-box{margin-top:12px;border-radius:12px;padding:14px 16px;font-size:13px;line-height:1.9;display:none;}
.pay-info-box.bank{background:#eff6ff;border:1.5px solid #bfdbfe;}
.pay-info-box.ewallet{background:#fdf4ff;border:1.5px solid #e9d5ff;}
.pay-info-title{font-weight:900;margin-bottom:6px;display:flex;align-items:center;gap:7px;}
.bank .pay-info-title{color:#1d4ed8;}
.ewallet .pay-info-title{color:#7c3aed;}

/* Tóm tắt đơn */
.co-summary{background:#f8fafc;border-radius:12px;padding:16px 18px;margin-bottom:6px;border:1px solid var(--border);}
.co-sum-row{display:flex;justify-content:space-between;font-size:13px;margin-bottom:7px;color:var(--txt2);}
.co-sum-row:last-child{font-weight:900;font-size:15px;color:var(--g1);margin-top:10px;padding-top:10px;
                        border-top:1.5px solid var(--border2);margin-bottom:0;}

/* Submit */
.submit-btn{width:100%;margin-top:22px;padding:15px;background:linear-gradient(90deg,var(--g1),var(--g3));
            color:#fff;border:none;border-radius:12px;font-size:15px;font-weight:900;
            cursor:pointer;display:flex;align-items:center;justify-content:center;gap:9px;
            box-shadow:0 4px 16px rgba(6,95,70,.25);transition:.2s;}
.submit-btn:hover{opacity:.92;transform:translateY(-1px);}
.submit-btn:disabled{opacity:.6;cursor:not-allowed;transform:none;}
.error-msg{background:#fef2f2;border:1.5px solid #fecaca;color:#b91c1c;border-radius:10px;
           padding:12px 15px;font-size:13px;font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:8px;}

/* Toast */
.toast{position:fixed;bottom:24px;right:24px;padding:13px 20px;border-radius:12px;font-size:13px;font-weight:700;
       z-index:999;display:flex;align-items:center;gap:9px;
       transform:translateY(80px);opacity:0;transition:.3s;box-shadow:0 8px 24px rgba(0,0,0,.2);}
.toast.show{transform:translateY(0);opacity:1;}

/* SUCCESS MODAL */
.suc-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:600;
           align-items:center;justify-content:center;padding:20px;}
.suc-modal.open{display:flex;}
.suc-card{background:#fff;border-radius:20px;padding:44px 36px;text-align:center;
          max-width:460px;width:100%;box-shadow:0 24px 60px rgba(0,0,0,.2);}
.suc-ico{font-size:68px;margin-bottom:14px;animation:pop .4s ease;}
@keyframes pop{0%{transform:scale(.5);}70%{transform:scale(1.15);}100%{transform:scale(1);}}
.suc-title{font-size:22px;font-weight:900;color:var(--g1);margin-bottom:8px;}
.suc-sub{color:var(--muted);font-size:13.5px;margin-bottom:18px;line-height:1.7;}
.suc-id{background:var(--g1);color:#fff;border-radius:9px;padding:9px 22px;font-weight:800;
        font-size:15px;display:inline-block;margin-bottom:22px;letter-spacing:1.5px;}
.suc-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;}
.suc-btn{padding:11px 24px;border-radius:10px;font-weight:800;font-size:13px;cursor:pointer;border:none;font-family:inherit;transition:.2s;}
.suc-btn.primary{background:linear-gradient(90deg,var(--g2),var(--g3));color:#fff;}
.suc-btn.secondary{background:#f0fdf4;color:var(--g2);border:1.5px solid var(--border2);}
.suc-btn:hover{opacity:.88;}

@media(max-width:768px){
    .grid{grid-template-columns:1fr;}
    .pay-grid{grid-template-columns:1fr 1fr;}
    .form-row{grid-template-columns:1fr;}
    .hdr-logo span{display:none;}
}
</style>
</head>
<body>

<!-- HEADER -->
<div class="hdr">
    <div class="hdr-logo">MÁY TÍNH QUANG ANH <span>GIỎ HÀNG</span></div>
    <a href="index.php" class="hdr-back"><i class="fas fa-arrow-left"></i> Tiếp tục mua sắm</a>
</div>

<div class="wrap">

<?php if ($ordered_id): ?>
<!-- ── ĐẶT HÀNG THÀNH CÔNG (URL redirect) ── -->
<div class="success-box" style="margin:0 auto;">
    <div class="success-ico">🎉</div>
    <div class="success-title">Đặt hàng thành công!</div>
    <div class="success-sub">Cảm ơn bạn đã tin tưởng Máy Tính Quang Anh.<br>Chúng tôi sẽ liên hệ xác nhận đơn trong vòng 30 phút.</div>
    <div class="success-id">#QA-<?= str_pad($ordered_id, 5, '0', STR_PAD_LEFT) ?></div><br>
    <a href="index.php" class="shop-btn"><i class="fas fa-shopping-bag"></i> Tiếp tục mua sắm</a>
</div>

<?php else: ?>

<div class="page-title">
    <i class="fas fa-shopping-bag"></i> Giỏ hàng của bạn
    <?php if($total_items > 0): ?>
    <span style="background:var(--g3);color:#fff;font-size:12px;border-radius:20px;padding:3px 12px;"><?= $total_items ?> sản phẩm</span>
    <?php endif; ?>
</div>

<div class="grid">

    <!-- CỘT TRÁI: Danh sách sản phẩm -->
    <div class="panel">
        <div class="panel-hdr">
            <div class="panel-hdr-title"><i class="fas fa-list"></i> Sản phẩm trong giỏ</div>
            <?php if(!empty($cart_items)): ?>
            <button class="clear-btn" onclick="clearCart()"><i class="fas fa-trash"></i> Xóa tất cả</button>
            <?php endif; ?>
        </div>

        <?php if(!empty($cart_items)): ?>
        <table class="cart-tbl">
            <thead>
                <tr>
                    <th style="width:45%">Sản phẩm</th>
                    <th style="width:16%">Đơn giá</th>
                    <th style="width:20%;text-align:center">Số lượng</th>
                    <th style="width:15%;text-align:right">Thành tiền</th>
                    <th style="width:4%"></th>
                </tr>
            </thead>
            <tbody id="cart-tbody">
            <?php foreach($cart_items as $item):
                $line = $item['price'] * $item['quantity'];
            ?>
            <tr id="row-<?= $item['id'] ?>">
                <td>
                    <div class="pd-cell">
                        <?php if(!empty($item['image'])): ?>
                        <img src="<?= htmlspecialchars($item['image']) ?>" class="pd-img"
                             onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                        <div class="pd-ico" style="display:none">💻</div>
                        <?php else: ?>
                        <div class="pd-ico">💻</div>
                        <?php endif; ?>
                        <div>
                            <div class="pd-name"><?= htmlspecialchars($item['name']) ?></div>
                            <div class="pd-price-sm"><?= number_format($item['price']) ?>đ / cái</div>
                        </div>
                    </div>
                </td>
                <td style="color:var(--muted);font-weight:700;"><?= number_format($item['price']) ?>đ</td>
                <td style="text-align:center">
                    <div class="qty-ctrl">
                        <button class="qty-btn" onclick="updateQty(<?= $item['id'] ?>, <?= $item['quantity']-1 ?>)"><i class="fas fa-minus"></i></button>
                        <span class="qty-num" id="qty-<?= $item['id'] ?>"><?= $item['quantity'] ?></span>
                        <button class="qty-btn" onclick="updateQty(<?= $item['id'] ?>, <?= $item['quantity']+1 ?>)"><i class="fas fa-plus"></i></button>
                    </div>
                </td>
                <td style="text-align:right">
                    <span class="line-total" id="line-<?= $item['id'] ?>"><?= number_format($line) ?>đ</span>
                </td>
                <td>
                    <button class="rm-btn" title="Xóa" onclick="removeItem(<?= $item['id'] ?>)"><i class="fas fa-trash-alt"></i></button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="empty">
            <div class="empty-ico">🛒</div>
            <div class="empty-txt">Giỏ hàng của bạn đang trống</div>
            <a href="index.php" class="shop-btn"><i class="fas fa-shopping-bag"></i> Mua sắm ngay</a>
        </div>
        <?php endif; ?>
    </div>

    <!-- CỘT PHẢI: Tổng kết đơn -->
    <div>
        <div class="panel">
            <div class="sum-title">🧾 Tổng đơn hàng</div>
            <div class="sum-body">
                <div class="sum-row"><span class="lbl">Số lượng</span><span class="val" id="sum-count"><?= $total_items ?> sản phẩm</span></div>
                <div class="sum-row"><span class="lbl">Tạm tính</span><span class="val" id="sum-sub"><?= number_format($subtotal) ?>đ</span></div>
                <div class="sum-row"><span class="lbl">Phí giao hàng</span><span class="val" style="color:var(--g3)">Miễn phí nội thành</span></div>
                <div class="sum-row total">
                    <span class="lbl">Tổng cộng</span>
                    <span class="val" id="sum-total"><?= number_format($subtotal) ?>đ</span>
                </div>
                <button class="checkout-btn" onclick="openCheckout()" <?= empty($cart_items)?'disabled':'' ?>>
                    <i class="fas fa-bolt"></i> Tiến hành thanh toán
                </button>
                <div class="promo-list">
                    <div class="promo-item"><i class="fas fa-shield-alt"></i>Bảo hành 12 tháng chính hãng</div>
                    <div class="promo-item"><i class="fas fa-truck"></i>Giao hàng miễn phí nội thành HP</div>
                    <div class="promo-item"><i class="fas fa-undo"></i>Đổi trả trong 30 ngày</div>
                </div>
            </div>
        </div>
    </div>

</div><!-- /grid -->
<?php endif; ?>
</div><!-- /wrap -->

<!-- ════════════════════════════════
     MODAL CHECKOUT
════════════════════════════════ -->
<div class="co-modal" id="coModal" onclick="if(event.target===this)closeCheckout()">
    <div class="co-box">
        <div class="co-hdr">
            <div class="co-hdr-title"><i class="fas fa-shopping-bag"></i> Xác nhận đặt hàng</div>
            <button class="co-close" onclick="closeCheckout()"><i class="fas fa-times"></i></button>
        </div>
        <div class="co-body">

            <!-- Error message -->
            <div class="error-msg" id="co-error" style="display:none">
                <i class="fas fa-exclamation-circle"></i><span id="co-error-text"></span>
            </div>

            <!-- Tóm tắt giỏ hàng -->
            <div class="co-summary" id="co-summary-box">
                <div class="co-sum-row"><span><?= $total_items ?> sản phẩm</span><span></span></div>
                <?php foreach(array_slice($cart_items, 0, 3) as $ci): ?>
                <div class="co-sum-row" style="font-size:12px;">
                    <span><?= htmlspecialchars(mb_strimwidth($ci['name'],0,42,'…','UTF-8')) ?> ×<?= $ci['quantity'] ?></span>
                    <span><?= number_format($ci['price']*$ci['quantity']) ?>đ</span>
                </div>
                <?php endforeach; ?>
                <?php if(count($cart_items) > 3): ?>
                <div class="co-sum-row" style="font-size:12px;color:var(--muted);">
                    <span>... và <?= count($cart_items)-3 ?> sản phẩm khác</span><span></span>
                </div>
                <?php endif; ?>
                <div class="co-sum-row">
                    <span>Tổng thanh toán</span>
                    <span><?= number_format($subtotal) ?>đ</span>
                </div>
            </div>

            <!-- ── THÔNG TIN NGƯỜI NHẬN ── -->
            <div class="co-section"><i class="fas fa-user"></i> Thông tin người nhận</div>

            <div class="form-group">
                <label class="form-label">Họ và tên <span class="req">*</span></label>
                <input type="text" id="co-name" class="form-input"
                       value="<?= $uname ?>" placeholder="Nguyễn Văn A" autocomplete="name">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Số điện thoại <span class="req">*</span></label>
                    <input type="tel" id="co-phone" class="form-input"
                           value="<?= $uphone ?>" placeholder="0987 654 321" autocomplete="tel">
                </div>
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" id="co-email" class="form-input"
                           value="<?= $uemail ?>" placeholder="email@gmail.com" autocomplete="email">
                </div>
            </div>

            <!-- ── ĐỊA CHỈ GIAO HÀNG ── -->
            <div class="co-section"><i class="fas fa-map-marker-alt"></i> Địa chỉ giao hàng</div>

            <div class="form-group">
                <label class="form-label">Địa chỉ nhận hàng <span class="req">*</span></label>
                <input type="text" id="co-addr" class="form-input"
                       value="<?= $uaddress ?>"
                       placeholder="Số nhà, tên đường, phường/xã, quận/huyện, tỉnh/thành phố"
                       autocomplete="street-address">
            </div>
            <div class="form-group">
                <label class="form-label">Ghi chú đơn hàng</label>
                <textarea id="co-note" class="form-input"
                          placeholder="Ghi chú cho người giao hàng, yêu cầu đặc biệt, thời gian giao hàng..."></textarea>
            </div>

            <!-- ── PHƯƠNG THỨC THANH TOÁN ── -->
            <div class="co-section"><i class="fas fa-credit-card"></i> Phương thức thanh toán</div>

            <div class="pay-grid">
                <label class="pay-opt">
                    <input type="radio" name="payment" value="cod" checked>
                    <div class="pay-card">
                        <div class="pay-ico">💵</div>
                        <div class="pay-lbl">COD</div>
                        <div class="pay-sub">Trả khi nhận hàng</div>
                    </div>
                </label>
                <label class="pay-opt">
                    <input type="radio" name="payment" value="bank">
                    <div class="pay-card">
                        <div class="pay-ico">🏦</div>
                        <div class="pay-lbl">Chuyển khoản</div>
                        <div class="pay-sub">MB / VCB / TCB</div>
                    </div>
                </label>
                <label class="pay-opt">
                    <input type="radio" name="payment" value="ewallet">
                    <div class="pay-card">
                        <div class="pay-ico">📱</div>
                        <div class="pay-lbl">Ví điện tử</div>
                        <div class="pay-sub">MoMo / ZaloPay</div>
                    </div>
                </label>
            </div>

            <!-- Thông tin chuyển khoản -->
            <div class="pay-info-box bank" id="bankInfo">
                <div class="pay-info-title"><i class="fas fa-university"></i> Thông tin chuyển khoản</div>
                <div>🏦 <b>MB Bank</b> – STK: <b>0979123456</b></div>
                <div>👤 Chủ TK: <b>NGUYEN VAN QUANG ANH</b></div>
                <div>📝 Nội dung: <b>[Họ tên] + [SĐT]</b></div>
                <div style="font-size:12px;color:#1d4ed8;margin-top:4px;">* Đơn hàng sẽ được xử lý sau khi nhận được chuyển khoản</div>
            </div>
            <!-- Thông tin ví điện tử -->
            <div class="pay-info-box ewallet" id="ewalletInfo">
                <div class="pay-info-title"><i class="fas fa-mobile-alt"></i> Thanh toán ví điện tử</div>
                <div>📱 <b>MoMo / ZaloPay</b>: <b>0787 911 555</b></div>
                <div>👤 Tên: <b>QUANG ANH TECH</b></div>
                <div style="font-size:12px;color:#7c3aed;margin-top:4px;">* Chụp màn hình xác nhận gửi qua Zalo sau khi chuyển</div>
            </div>

            <button class="submit-btn" id="submitBtn" onclick="submitOrder()">
                <i class="fas fa-check-circle"></i>
                Xác nhận đặt hàng – <?= number_format($subtotal) ?>đ
            </button>

        </div>
    </div>
</div>

<!-- ════════════════════════════════
     MODAL THÀNH CÔNG
════════════════════════════════ -->
<div class="suc-modal" id="sucModal">
    <div class="suc-card">
        <div class="suc-ico">🎉</div>
        <div class="suc-title">Đặt hàng thành công!</div>
        <div class="suc-sub">
            Cảm ơn bạn đã tin tưởng Máy Tính Quang Anh.<br>
            Chúng tôi sẽ liên hệ xác nhận trong vòng <b>30 phút</b>.
        </div>
        <div class="suc-id" id="suc-order-id">#QA-00000</div>
        <div class="suc-actions">
            <button class="suc-btn secondary" onclick="window.location.href='index.php'">
                <i class="fas fa-shopping-bag"></i> Mua thêm
            </button>
            <button class="suc-btn primary" onclick="window.location.href='index.php'">
                <i class="fas fa-home"></i> Về trang chủ
            </button>
        </div>
    </div>
</div>

<!-- Toast -->
<div class="toast" id="toast"></div>

<script>
/* ── Dữ liệu giỏ hàng từ PHP ── */
let cartSubtotal = <?= $subtotal ?>;
const itemPrices = {
<?php foreach($cart_items as $item): ?>
    <?= $item['id'] ?>: <?= $item['price'] ?>,
<?php endforeach; ?>
};

/* ════════════════════════════════════
   AJAX HELPER
════════════════════════════════════ */
function cartAjax(params, onSuccess) {
    fetch('add_to_cart.php', { method:'POST', body: new URLSearchParams(params) })
        .then(r => r.json())
        .then(d => {
            if (d.ok && onSuccess) onSuccess(d);
            else if (!d.ok) showToast((d.msg || 'Có lỗi xảy ra!'), '#ef4444');
        })
        .catch(() => showToast('❌ Lỗi kết nối server!', '#ef4444'));
}

/* ════════════════════════════════════
   CẬP NHẬT SỐ LƯỢNG
════════════════════════════════════ */
function updateQty(cartId, qty) {
    if (qty < 0) return;
    if (qty === 0) { removeItem(cartId); return; }

    cartAjax({ action:'update', cart_id: cartId, qty: qty }, (d) => {
        const qEl = document.getElementById('qty-' + cartId);
        const lEl = document.getElementById('line-' + cartId);
        if (qEl) qEl.textContent = qty;
        if (lEl && itemPrices[cartId]) {
            const lineTotal = itemPrices[cartId] * qty;
            lEl.textContent = lineTotal.toLocaleString('vi-VN') + 'đ';
        }
        /* Cập nhật onclick cho nút +/- */
        const ctrl = qEl?.closest('.qty-ctrl');
        if (ctrl) {
            const btns = ctrl.querySelectorAll('.qty-btn');
            if (btns[0]) btns[0].setAttribute('onclick', `updateQty(${cartId}, ${qty-1})`);
            if (btns[1]) btns[1].setAttribute('onclick', `updateQty(${cartId}, ${qty+1})`);
        }
        updateSummaryUI(d.subtotal);
    });
}

/* ════════════════════════════════════
   XÓA 1 SẢN PHẨM
════════════════════════════════════ */
function removeItem(cartId) {
    if (!confirm('Bạn muốn xóa sản phẩm này khỏi giỏ hàng?')) return;
    cartAjax({ action:'remove', cart_id: cartId }, (d) => {
        const row = document.getElementById('row-' + cartId);
        if (row) {
            row.style.transition = 'opacity .3s';
            row.style.opacity = '0';
            setTimeout(() => {
                row.remove();
                if (d.count === 0) location.reload();
            }, 300);
        }
        updateSummaryUI(d.subtotal);
    });
}

/* ════════════════════════════════════
   XÓA TOÀN BỘ GIỎ
════════════════════════════════════ */
function clearCart() {
    if (!confirm('Bạn muốn xóa toàn bộ giỏ hàng?')) return;
    cartAjax({ action:'clear' }, () => location.reload());
}

/* ════════════════════════════════════
   CẬP NHẬT UI TỔNG TIỀN
════════════════════════════════════ */
function updateSummaryUI(newSubtotal) {
    cartSubtotal = parseInt(newSubtotal) || 0;
    const fmt = cartSubtotal.toLocaleString('vi-VN') + 'đ';
    const subEl   = document.getElementById('sum-sub');
    const totalEl = document.getElementById('sum-total');
    if (subEl)   subEl.textContent   = fmt;
    if (totalEl) totalEl.textContent = fmt;
    /* Cập nhật nút submit trong modal checkout */
    const submitBtn = document.getElementById('submitBtn');
    if (submitBtn) submitBtn.innerHTML = `<i class="fas fa-check-circle"></i> Xác nhận đặt hàng – ${fmt}`;
}

/* ════════════════════════════════════
   MỞ / ĐÓNG CHECKOUT MODAL
════════════════════════════════════ */
function openCheckout() {
    hideError();
    document.getElementById('coModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeCheckout() {
    document.getElementById('coModal').classList.remove('open');
    document.body.style.overflow = '';
}

/* ════════════════════════════════════
   PHƯƠNG THỨC THANH TOÁN – hiện/ẩn info
════════════════════════════════════ */
document.querySelectorAll('input[name="payment"]').forEach(r => {
    r.addEventListener('change', function() {
        document.getElementById('bankInfo').style.display    = this.value === 'bank'    ? 'block' : 'none';
        document.getElementById('ewalletInfo').style.display = this.value === 'ewallet' ? 'block' : 'none';
    });
});

/* ════════════════════════════════════
   VALIDATE & GỬI ĐƠN HÀNG
════════════════════════════════════ */
function submitOrder() {
    hideError();

    const name    = document.getElementById('co-name').value.trim();
    const phone   = document.getElementById('co-phone').value.trim();
    const address = document.getElementById('co-addr').value.trim();
    const note    = document.getElementById('co-note').value.trim();
    const payment = document.querySelector('input[name="payment"]:checked')?.value || 'cod';

    /* Validate */
    let errors = [];
    const fields = [
        ['co-name',  name,    'Vui lòng nhập họ và tên'],
        ['co-phone', phone,   'Vui lòng nhập số điện thoại'],
        ['co-addr',  address, 'Vui lòng nhập địa chỉ giao hàng'],
    ];
    fields.forEach(([id, val, msg]) => {
        const el = document.getElementById(id);
        if (!val) {
            el.classList.add('error');
            errors.push(msg);
        } else {
            el.classList.remove('error');
        }
    });
    /* Validate số điện thoại */
    if (phone && !/^(0|\+84)[0-9]{8,10}$/.test(phone.replace(/\s/g,''))) {
        document.getElementById('co-phone').classList.add('error');
        errors.push('Số điện thoại không hợp lệ');
    }

    if (errors.length) {
        showError(errors[0]);
        document.getElementById(fields.find(f => !f[1].trim())?.[0] || 'co-name')?.focus();
        return;
    }

    /* Gửi đơn hàng */
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Đang xử lý...';

    cartAjax({
        action:   'checkout',
        fullname: name,
        phone:    phone,
        address:  address,
        payment:  payment,
        note:     note
    }, (d) => {
        closeCheckout();
        /* Hiện modal thành công */
        const padId = '#QA-' + String(d.order_id).padStart(5, '0');
        document.getElementById('suc-order-id').textContent = padId;
        document.getElementById('sucModal').classList.add('open');
        /* Xóa UI giỏ hàng */
        const tbody = document.getElementById('cart-tbody');
        if (tbody) tbody.innerHTML = '';
        updateSummaryUI(0);
    });

    /* Reset nút nếu có lỗi */
    setTimeout(() => {
        if (btn.disabled) {
            btn.disabled = false;
            btn.innerHTML = `<i class="fas fa-check-circle"></i> Xác nhận đặt hàng – ${cartSubtotal.toLocaleString('vi-VN')}đ`;
        }
    }, 8000);
}

/* ════════════════════════════════════
   HELPERS
════════════════════════════════════ */
function showError(msg) {
    const box  = document.getElementById('co-error');
    const text = document.getElementById('co-error-text');
    text.textContent = msg;
    box.style.display = 'flex';
    box.scrollIntoView({ behavior:'smooth', block:'nearest' });
}
function hideError() {
    document.getElementById('co-error').style.display = 'none';
    document.querySelectorAll('.form-input.error').forEach(el => el.classList.remove('error'));
}

function showToast(msg, bg = '#047857') {
    const t = document.getElementById('toast');
    t.style.background = bg;
    t.innerHTML = (bg === '#047857' ? '<i class="fas fa-check-circle"></i>' : '<i class="fas fa-exclamation-circle"></i>') + msg;
    t.classList.add('show');
    clearTimeout(t._timer);
    t._timer = setTimeout(() => t.classList.remove('show'), 3000);
}

/* Xóa class error khi người dùng gõ lại */
document.querySelectorAll('.form-input').forEach(el => {
    el.addEventListener('input', () => { el.classList.remove('error'); hideError(); });
});
</script>
</body>
</html>