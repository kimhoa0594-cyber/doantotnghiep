<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$uid      = intval($_SESSION['user_id']);
$uname    = htmlspecialchars($_SESSION['fullname'] ?? 'Khách');
$uemail   = htmlspecialchars($_SESSION['email']    ?? '');
$uphone   = htmlspecialchars($_SESSION['phone']    ?? '');
$uaddress = htmlspecialchars($_SESSION['address']  ?? '');
$join_date = isset($_SESSION['created_at']) ? date('d/m/Y', strtotime($_SESSION['created_at'])) : date('d/m/Y');
$avatar_char = mb_strtoupper(mb_substr($uname, 0, 1, 'UTF-8'), 'UTF-8');

// Thống kê đơn hàng
$order_count=0; $total_spent=0; $pending_count=0; $orders=[];
if($conn){
    $tbl=$conn->query("SHOW TABLES LIKE 'orders'");
    if($tbl&&$tbl->num_rows>0){
        $r=$conn->query("SELECT COUNT(*) as c FROM orders WHERE user_id=$uid"); if($r){$row=$r->fetch_assoc();$order_count=$row['c'];}
        $r=$conn->query("SELECT SUM(total_amount) as t FROM orders WHERE user_id=$uid AND status!='cancelled'"); if($r){$row=$r->fetch_assoc();$total_spent=$row['t']??0;}
        $r=$conn->query("SELECT COUNT(*) as c FROM orders WHERE user_id=$uid AND status='pending'"); if($r){$row=$r->fetch_assoc();$pending_count=$row['c'];}
        $r=$conn->query("SELECT * FROM orders WHERE user_id=$uid ORDER BY id DESC LIMIT 10"); if($r){while($row=$r->fetch_assoc())$orders[]=$row;}
    }
}
$loyalty_points=floor($total_spent/1000);
$vip_threshold=5000;
$vip_percent=min(100,round(($loyalty_points/$vip_threshold)*100));
$member_level=$loyalty_points>=5000?'DIAMOND':($loyalty_points>=2000?'GOLD':($loyalty_points>=500?'SILVER':'MEMBER'));
$level_icon=$loyalty_points>=5000?'💎':($loyalty_points>=2000?'🏆':($loyalty_points>=500?'⭐':'🎖️'));
$level_color=$loyalty_points>=5000?'#3b82f6':($loyalty_points>=2000?'#f59e0b':($loyalty_points>=500?'#94a3b8':'#16a34a'));

// Xử lý cập nhật thông tin
$update_msg='';
if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['action'])&&$_POST['action']==='update_profile'){
    $new_name=htmlspecialchars(trim($_POST['fullname']??''));
    $new_phone=htmlspecialchars(trim($_POST['phone']??''));
    $new_address=htmlspecialchars(trim($_POST['address']??''));
    if($new_name){
        $_SESSION['fullname']=$new_name;$_SESSION['phone']=$new_phone;$_SESSION['address']=$new_address;
        if($conn){$u=$conn->query("SHOW TABLES LIKE 'users'");if($u&&$u->num_rows>0){$cols_r=$conn->query("SHOW COLUMNS FROM users");$cols=[];if($cols_r)while($c=$cols_r->fetch_assoc())$cols[]=$c["Field"];if(!in_array("phone",$cols))$conn->query("ALTER TABLE users ADD COLUMN phone varchar(20) DEFAULT NULL");if(!in_array("address",$cols))$conn->query("ALTER TABLE users ADD COLUMN address varchar(255) DEFAULT NULL");$stmt=$conn->prepare("UPDATE users SET fullname=?,phone=?,address=? WHERE id=?");if($stmt){$stmt->bind_param("sssi",$new_name,$new_phone,$new_address,$uid);$stmt->execute();}}}
        $uname=$new_name;$uphone=$new_phone;$uaddress=$new_address;$update_msg='success';
    }
}

// Lấy sản phẩm từ DB
$db_products=[];
if($conn){
    $pt=$conn->query("SHOW TABLES LIKE 'products'");
    if($pt&&$pt->num_rows>0){
        $cr=$conn->query("SHOW COLUMNS FROM products");$cn=[];
        if($cr)while($c=$cr->fetch_assoc())$cn[]=$c['Field'];
        $ord=in_array('created_at',$cn)?'ORDER BY created_at DESC':(in_array('id',$cn)?'ORDER BY id DESC':'');
        $r=$conn->query("SELECT * FROM products $ord LIMIT 20");
        if($r)while($row=$r->fetch_assoc())$db_products[]=$row;
    }
}

// ============================================================
// Lấy bài viết quảng bá từ admin/advertising.php (bảng ads)
// ============================================================

/**
 * Chuẩn hoá đường dẫn ảnh từ admin sang gốc dự án.
 * advertising.php lưu ảnh vào uploads/ bên trong thư mục admin/,
 * nên khi hiển thị từ index.php (ở gốc) cần thêm tiền tố "admin/".
 *
 * Các trường hợp:
 *  - URL tuyệt đối (http/https)  → giữ nguyên
 *  - Đã bắt đầu bằng "admin/"   → giữ nguyên
 *  - Bắt đầu bằng "uploads/"    → thêm "admin/" phía trước
 *  - Bắt đầu bằng "/"           → giữ nguyên (path tuyệt đối server)
 *  - Còn lại                     → thêm "admin/" phía trước
 */
/**
 * Chuẩn hoá đường dẫn ảnh từ admin sang gốc dự án.
 *
 * advertising.php lưu ảnh vào ../images/ (root) và lưu DB dạng "images/tên_file".
 * Từ index.php (ở root), path "images/tên_file" là ĐÚNG — không cần thêm gì.
 *
 * Các trường hợp:
 *  - URL tuyệt đối (http/https)        → giữ nguyên
 *  - Bắt đầu bằng "images/"            → giữ nguyên (đã đúng từ root)
 *  - Bắt đầu bằng "admin/images/"      → bỏ "admin/" → "images/..."
 *  - Bắt đầu bằng "../images/"         → bỏ "../"    → "images/..."
 *  - Bắt đầu bằng "/"                  → giữ nguyên
 *  - Còn lại                            → giữ nguyên (không thêm prefix bừa)
 */
function fixAdminImgPath(string $src): string {
    $src = trim($src);
    if ($src === '') return '';
    // URL tuyệt đối
    if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) return $src;
    // Đã đúng: images/ ở root
    if (str_starts_with($src, 'images/')) return $src;
    // admin/images/ → images/
    if (str_starts_with($src, 'admin/images/')) return substr($src, strlen('admin/'));
    // ../images/ → images/
    if (str_starts_with($src, '../images/')) return substr($src, strlen('../'));
    // Path tuyệt đối server
    if (str_starts_with($src, '/')) return $src;
    // Còn lại: giữ nguyên
    return $src;
}

/**
 * Áp fixAdminImgPath cho toàn bộ mảng ảnh.
 */
function fixAdminImgPaths(array $imgs): array {
    return array_map('fixAdminImgPath', $imgs);
}

/**
 * Dọn sạch nội dung TinyMCE:
 * - Xóa \r\n và \\r\\n literal (không phải newline thật) xuất hiện do escape không đúng
 * - Fix ảnh
 */
function cleanTinyMCEContent(string $html): string {
    if ($html === '') return '';
    // Xóa các chuỗi literal \r\n và \\r\\n (dạng text thô trong HTML)
    $html = str_replace(['\\r\\n', '\r\n', '\\\\r\\\\n'], '', $html);
    // Xóa tag <p> rỗng / chỉ chứa &nbsp; xuất hiện do double newline
    $html = preg_replace('/<p[^>]*>(\s|&nbsp;)*<\/p>/i', '', $html ?? $html);
    return $html;
}

/**
 * Sửa src của tất cả <img> bên trong nội dung HTML từ admin.
 */
function fixAdminContentImgs(string $html): string {
    if ($html === '') return '';
    $result = preg_replace_callback(
        '/<img([^>]+)src=["\']([^"\']+)["\']([^>]*)>/i',
        function($m) {
            $src = $m[2];
            // data:image → nhúng thẳng, giữ nguyên không cần fix path
            if (str_starts_with($src, 'data:image')) {
                return $m[0];
            }
            $fixed = fixAdminImgPath($src);
            return '<img' . $m[1] . 'src="' . htmlspecialchars($fixed, ENT_QUOTES) . '"' . $m[3] . '>';
        },
        $html
    );
    return $result ?? $html;
}

$ads_published = [];
$ads_products  = [];
if($conn){
    $at = $conn->query("SHOW TABLES LIKE 'ads'");
    if($at && $at->num_rows > 0){
        $conn->query("SET NAMES 'utf8mb4'");
        $ra = $conn->query("SELECT * FROM ads WHERE status='published' ORDER BY id DESC LIMIT 20");
        if($ra) while($row = $ra->fetch_assoc()){
            // Fix đường dẫn ảnh đơn
            if (!empty($row['image'])) {
                $row['image'] = fixAdminImgPath($row['image']);
            }
            // Fix đường dẫn ảnh nhiều (images_json)
            if (!empty($row['images_json'])) {
                $decoded = json_decode($row['images_json'], true);
                if (is_array($decoded)) {
                    $row['images_json'] = json_encode(fixAdminImgPaths($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
            // Fix ảnh bên trong nội dung HTML (TinyMCE) + dọn \r\n literal
            if (!empty($row['content'])) {
                $row['content'] = cleanTinyMCEContent($row['content']);
                $row['content'] = fixAdminContentImgs($row['content']);
            }

            // Bài có giá bán → hiển thị như sản phẩm
            if(!empty($row['new_p']) && floatval($row['new_p']) > 0){
                $ads_products[] = $row;
            } else {
                $ads_published[] = $row;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Máy Tính Quang Anh – Hải Phòng</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800;900&family=Oswald:wght@600;700&display=swap" rel="stylesheet">
<style>
/* ============================================================
   ROOT & RESET
============================================================ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
    --g1:#065f46; --g2:#047857; --g3:#059669; --g4:#10b981; --g5:#34d399;
    --gl:#d1fae5; --gll:#ecfdf5;
    --acc:#f59e0b; --acc2:#fcd34d;
    --red:#ef4444; --blue:#3b82f6;
    --txt:#0f172a; --txt2:#334155; --muted:#64748b;
    --border:#d1fae5; --border2:#e2e8f0;
    --bg:#f0fdf4; --bg2:#f8fafc; --white:#fff;
    --sh:0 2px 16px rgba(6,95,70,.10);
    --sh2:0 8px 32px rgba(6,95,70,.16);
}
body{background:var(--bg);font-family:'Nunito',sans-serif;color:var(--txt);font-size:14px;line-height:1.5;}
a{text-decoration:none;color:inherit;}img{max-width:100%;display:block;}ul{list-style:none;}
button,input{font-family:inherit;}
::-webkit-scrollbar{width:6px;height:6px;}
::-webkit-scrollbar-track{background:#f0fdf4;}
::-webkit-scrollbar-thumb{background:var(--g3);border-radius:3px;}

/* ============================================================
   TOP BAR
============================================================ */
.topbar{background:var(--g1);color:rgba(255,255,255,.8);font-size:12px;padding:5px 0;}
.topbar .wrap{max-width:1280px;margin:0 auto;padding:0 18px;display:flex;justify-content:space-between;align-items:center;gap:12px;}
.topbar a{color:rgba(255,255,255,.75);transition:.15s;}
.topbar a:hover{color:#fff;}
.topbar .tb-links{display:flex;gap:18px;align-items:center;}
.topbar .tb-links i{margin-right:4px;font-size:11px;}
.tb-hotline{display:flex;align-items:center;gap:6px;color:#fff;font-weight:700;}
.tb-hotline i{color:var(--acc);}

/* ============================================================
   HEADER
============================================================ */
.site-header{
    background:linear-gradient(135deg,var(--g1) 0%,var(--g2) 60%,var(--g3) 100%);
    position:sticky;top:0;z-index:200;
    box-shadow:0 4px 20px rgba(6,95,70,.35);
}
.hdr-inner{max-width:1280px;margin:0 auto;padding:0 18px;display:flex;align-items:center;gap:14px;height:70px;}

/* Logo */
.logo{display:flex;align-items:center;gap:11px;flex-shrink:0;}
.logo-mark{
    width:50px;height:50px;border-radius:12px;
    background:linear-gradient(135deg,#fff 0%,#e0fce4 100%);
    display:flex;align-items:center;justify-content:center;
    box-shadow:0 2px 8px rgba(0,0,0,.15);
}
.logo-mark span{font-family:'Oswald',sans-serif;font-size:20px;font-weight:700;color:var(--g1);letter-spacing:-1px;}
.logo-text .brand{font-family:'Oswald',sans-serif;font-size:18px;letter-spacing:1px;color:#fff;line-height:1;}
.logo-text .tagline{font-size:10px;color:rgba(255,255,255,.7);letter-spacing:.8px;text-transform:uppercase;margin-top:1px;}

/* Search */
.hdr-search{
    flex:1;max-width:520px;
    display:flex;background:rgba(255,255,255,.12);
    border:1.5px solid rgba(255,255,255,.25);
    border-radius:10px;overflow:hidden;transition:.2s;
}
.hdr-search:focus-within{background:rgba(255,255,255,.22);border-color:rgba(255,255,255,.5);}
.hdr-search input{flex:1;background:none;border:none;outline:none;padding:11px 16px;font-size:14px;color:#fff;font-family:inherit;}
.hdr-search input::placeholder{color:rgba(255,255,255,.6);}
.hdr-search button{
    background:var(--acc);border:none;width:50px;
    color:var(--txt);font-size:16px;cursor:pointer;
    display:flex;align-items:center;justify-content:center;
    transition:.2s;flex-shrink:0;
}
.hdr-search button:hover{background:var(--acc2);}

/* Contacts */
.hdr-contacts{display:flex;gap:20px;margin-left:auto;}
.hdr-contact{display:flex;align-items:center;gap:9px;color:#fff;}
.hc-icon{width:38px;height:38px;border-radius:9px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:15px;}
.hc-label{font-size:10px;opacity:.75;display:block;}
.hc-num{font-size:15px;font-weight:800;line-height:1.2;font-family:'Oswald',sans-serif;letter-spacing:.3px;}

/* Header buttons */
.hdr-btns{display:flex;gap:6px;}
.hbtn{
    width:42px;height:42px;border-radius:10px;
    background:rgba(255,255,255,.15);
    border:none;color:#fff;font-size:17px;cursor:pointer;
    display:flex;align-items:center;justify-content:center;
    position:relative;transition:.2s;
}
.hbtn:hover{background:rgba(255,255,255,.28);}
.hbtn .bdg{
    position:absolute;top:-5px;right:-5px;
    background:var(--acc);color:var(--txt);
    font-size:10px;font-weight:900;
    width:19px;height:19px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    border:2px solid var(--g2);
}
.hbtn.acc-btn{background:rgba(255,255,255,.2);border:1.5px solid rgba(255,255,255,.35);}

/* Nav strip */
.nav-strip{background:var(--g1);border-top:1px solid rgba(255,255,255,.12);}
.nav-strip .wrap{max-width:1280px;margin:0 auto;padding:0 18px;display:flex;align-items:center;gap:4px;}
.nav-strip .ns-addr{
    margin-left:auto;color:rgba(255,255,255,.85);font-size:12px;font-weight:700;
    padding:7px 0;display:flex;align-items:center;gap:6px;
}
.nav-strip .ns-addr i{color:var(--acc2);}
.nav-item{
    padding:9px 14px;color:rgba(255,255,255,.8);font-size:13px;font-weight:700;
    cursor:pointer;transition:.18s;border-radius:6px;display:flex;align-items:center;gap:6px;
    white-space:nowrap;
}
.nav-item:hover,.nav-item.hot{color:#fff;background:rgba(255,255,255,.12);}
.nav-item.hot{color:var(--acc);}
.nav-item i{font-size:12px;}

/* ============================================================
   PAGE BODY
============================================================ */
.page-body{max-width:1280px;margin:18px auto 40px;padding:0 18px;display:grid;grid-template-columns:240px 1fr;gap:18px;align-items:start;}

/* ============================================================
   LEFT SIDEBAR
============================================================ */
.left-col{display:flex;flex-direction:column;gap:14px;position:sticky;top:88px;}

/* Category menu */
.cat-box{background:var(--white);border-radius:14px;overflow:hidden;box-shadow:var(--sh);}
.cat-head{
    background:linear-gradient(135deg,var(--g2),var(--g3));
    color:#fff;padding:13px 16px;font-size:14px;font-weight:800;
    display:flex;align-items:center;gap:9px;
    font-family:'Oswald',sans-serif;letter-spacing:.5px;text-transform:uppercase;
}
.cat-head i{background:rgba(255,255,255,.2);width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:13px;}
.cat-list{padding:5px 0;}
.cat-item{
    display:flex;align-items:center;gap:10px;
    padding:10px 14px;color:var(--txt2);font-size:13.5px;font-weight:600;
    cursor:pointer;transition:.18s;border-left:3px solid transparent;
}
.cat-item:hover,.cat-item.active{background:var(--gll);color:var(--g2);border-left-color:var(--g3);}
.cat-icon{width:30px;height:30px;border-radius:7px;background:var(--bg);display:flex;align-items:center;justify-content:center;font-size:13px;color:var(--muted);flex-shrink:0;transition:.18s;}
.cat-item:hover .cat-icon,.cat-item.active .cat-icon{background:var(--g3);color:#fff;}
.cat-arrow{margin-left:auto;font-size:10px;color:var(--muted);}
.cat-count{margin-left:auto;background:var(--gl);color:var(--g2);font-size:10px;font-weight:800;padding:2px 7px;border-radius:10px;}
.cat-sep{border:none;border-top:1px solid var(--border);margin:4px 10px;}

/* Service box */
.service-box{background:var(--white);border-radius:14px;overflow:hidden;box-shadow:var(--sh);}
.srv-head{background:linear-gradient(135deg,var(--g3),var(--g4));color:#fff;padding:11px 16px;font-size:13px;font-weight:800;display:flex;align-items:center;gap:8px;font-family:'Oswald',sans-serif;letter-spacing:.5px;}
.srv-list{padding:6px 0;}
.srv-item{display:flex;align-items:center;gap:9px;padding:9px 14px;font-size:13px;font-weight:600;color:var(--txt2);cursor:pointer;transition:.18s;}
.srv-item:hover{background:var(--gll);color:var(--g2);}
.srv-dot{width:8px;height:8px;border-radius:50%;background:var(--g4);flex-shrink:0;}
.srv-item:hover .srv-dot{background:var(--g2);}

/* Flash deal sidebar */
.flash-box{background:linear-gradient(135deg,var(--g1),var(--g2));border-radius:14px;padding:16px;box-shadow:var(--sh2);}
.flash-title{color:#fff;font-size:13px;font-weight:800;display:flex;align-items:center;gap:7px;margin-bottom:12px;font-family:'Oswald',sans-serif;letter-spacing:.5px;}
.flash-title i{color:var(--acc);}
.flash-countdown{display:flex;gap:6px;justify-content:center;margin-bottom:12px;}
.countdown-block{background:rgba(255,255,255,.15);border-radius:8px;padding:6px 8px;text-align:center;min-width:42px;}
.countdown-block .num{font-size:20px;font-weight:900;color:#fff;font-family:'Oswald',sans-serif;display:block;}
.countdown-block .lbl{font-size:9px;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:.5px;}
.countdown-sep{color:rgba(255,255,255,.5);font-size:20px;font-weight:900;align-self:center;margin-top:-4px;}
.flash-item{background:rgba(255,255,255,.1);border-radius:10px;padding:10px;display:flex;gap:10px;align-items:center;margin-bottom:8px;cursor:pointer;transition:.2s;}
.flash-item:hover{background:rgba(255,255,255,.2);}
.flash-item:last-child{margin-bottom:0;}
.fi-ico{font-size:30px;flex-shrink:0;width:48px;height:48px;background:rgba(255,255,255,.1);border-radius:8px;display:flex;align-items:center;justify-content:center;}
.fi-name{font-size:12px;color:rgba(255,255,255,.9);font-weight:600;line-height:1.3;margin-bottom:4px;}
.fi-price{font-size:14px;color:var(--acc);font-weight:900;}
.fi-old{font-size:11px;color:rgba(255,255,255,.5);text-decoration:line-through;margin-left:5px;}

/* ============================================================
   MAIN CONTENT
============================================================ */
.main-col{display:flex;flex-direction:column;gap:18px;}

/* ============================================================
   HERO
============================================================ */
.hero-grid{display:grid;grid-template-columns:1fr 230px;gap:14px;}

/* Slider */
.hero-slider{border-radius:14px;overflow:hidden;position:relative;min-height:290px;box-shadow:var(--sh2);cursor:pointer;}
.hero-slide{position:absolute;inset:0;opacity:0;transition:opacity .7s;display:flex;align-items:center;padding:32px 36px;gap:20px;}
.hero-slide.active{opacity:1;}
.hero-slide.s1{background:linear-gradient(135deg,#e8f5e9 0%,#c8e6c9 40%,#a5d6a7 100%);}
.hero-slide.s2{background:linear-gradient(135deg,#fff8e1 0%,#ffecb3 50%,#ffe082 100%);}
.hero-slide.s3{background:linear-gradient(135deg,#e3f2fd 0%,#bbdefb 50%,#90caf9 100%);}
.slide-badge{display:inline-block;background:var(--g2);color:#fff;font-size:11px;font-weight:800;padding:4px 12px;border-radius:20px;margin-bottom:10px;text-transform:uppercase;letter-spacing:.5px;}
.slide-badge.yellow{background:var(--acc);}
.slide-badge.blue{background:var(--blue);}
.slide-title{font-family:'Oswald',sans-serif;font-size:32px;font-weight:700;color:var(--g1);line-height:1.1;margin-bottom:8px;}
.slide-desc{font-size:13px;color:var(--muted);margin-bottom:18px;line-height:1.6;}
.slide-checklist{display:flex;flex-direction:column;gap:5px;margin-bottom:18px;}
.slide-checklist li{font-size:12px;color:var(--txt2);display:flex;align-items:center;gap:7px;}
.slide-checklist li i{color:var(--g3);font-size:11px;}
.slide-cta{display:inline-flex;align-items:center;gap:8px;background:var(--g2);color:#fff;padding:11px 24px;border-radius:9px;font-size:13px;font-weight:800;transition:.2s;}
.slide-cta:hover{background:var(--g1);transform:translateY(-2px);}
.slide-cta.yellow{background:var(--acc);color:var(--txt);}
.slide-cta.blue{background:var(--blue);color:#fff;}
.slide-visual{flex-shrink:0;font-size:90px;display:flex;align-items:center;justify-content:center;width:160px;filter:drop-shadow(0 8px 16px rgba(0,0,0,.12));}
.slide-dots{position:absolute;bottom:14px;left:50%;transform:translateX(-50%);display:flex;gap:7px;}
.sdot{width:8px;height:8px;border-radius:50%;background:rgba(0,0,0,.18);cursor:pointer;transition:.3s;}
.sdot.active{background:var(--g2);width:22px;border-radius:4px;}

/* Right banner stack */
.hero-right{display:flex;flex-direction:column;gap:10px;}
.promo-card{
    border-radius:12px;overflow:hidden;min-height:88px;
    display:flex;align-items:center;padding:14px;
    cursor:pointer;transition:.22s;box-shadow:var(--sh);
    position:relative;
}
.promo-card:hover{transform:translateY(-3px);box-shadow:var(--sh2);}
.pc1{background:linear-gradient(135deg,#1b5e20,#2e7d32);}
.pc2{background:linear-gradient(135deg,#0d47a1,#1565c0);}
.pc3{background:linear-gradient(135deg,#4a148c,#6a1b9a);}
.promo-card .illo{position:absolute;left:8px;bottom:0;font-size:48px;opacity:.22;}
.promo-info{margin-left:auto;text-align:right;}
.promo-off{font-size:30px;font-weight:900;color:var(--acc);font-family:'Oswald',sans-serif;line-height:1;text-shadow:0 2px 4px rgba(0,0,0,.2);}
.promo-off-lbl{font-size:10px;color:rgba(255,255,255,.7);font-weight:700;letter-spacing:.5px;}
.promo-prod{font-size:12px;color:#fff;font-weight:800;margin-top:3px;}
.promo-price{font-size:11px;color:rgba(255,255,255,.65);}

/* ============================================================
   FEATURE STRIP
============================================================ */
.feature-strip{
    display:grid;grid-template-columns:repeat(4,1fr);
    gap:0;background:var(--white);border-radius:14px;
    overflow:hidden;box-shadow:var(--sh);
}
.feat-item{
    display:flex;align-items:center;gap:12px;
    padding:16px 18px;border-right:1px solid var(--border);
    transition:.2s;cursor:default;
}
.feat-item:last-child{border-right:none;}
.feat-item:hover{background:var(--gll);}
.feat-icon{
    width:44px;height:44px;border-radius:11px;
    background:var(--gl);color:var(--g2);
    display:flex;align-items:center;justify-content:center;
    font-size:18px;flex-shrink:0;
}
.feat-title{font-size:13px;font-weight:800;color:var(--txt);}
.feat-sub{font-size:11px;color:var(--muted);margin-top:1px;}

/* ============================================================
   SECTION HEADER
============================================================ */
.sec-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:10px;}
.sec-title{font-family:'Oswald',sans-serif;font-size:20px;font-weight:700;color:var(--g1);display:flex;align-items:center;gap:10px;text-transform:uppercase;letter-spacing:.5px;}
.sec-title::before{content:'';width:5px;height:24px;background:linear-gradient(to bottom,var(--g3),var(--g4));border-radius:3px;flex-shrink:0;}
.sec-filters{display:flex;gap:7px;flex-wrap:wrap;}
.sf{padding:6px 14px;border-radius:20px;font-size:12px;font-weight:700;border:1.5px solid var(--border2);background:#fff;color:var(--muted);cursor:pointer;transition:.18s;font-family:inherit;}
.sf.on,.sf:hover{border-color:var(--g3);color:var(--g2);background:var(--gll);}
.see-all{font-size:13px;color:var(--g3);font-weight:800;display:flex;align-items:center;gap:5px;transition:.2s;}
.see-all:hover{color:var(--g1);}

/* ============================================================
   PRODUCT GRID
============================================================ */
.prod-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:12px;}
.prod-grid.four{grid-template-columns:repeat(4,1fr);}

.pcard{
    background:var(--white);border-radius:12px;overflow:hidden;
    box-shadow:0 1px 8px rgba(6,95,70,.08);
    transition:all .22s;border:1.5px solid transparent;
    position:relative;cursor:pointer;
}
.pcard:hover{transform:translateY(-5px);box-shadow:var(--sh2);border-color:var(--g4);}
.pcard-badge{
    position:absolute;top:9px;left:9px;z-index:2;
    background:var(--red);color:#fff;font-size:11px;font-weight:800;
    padding:3px 9px;border-radius:6px;
}
.pcard-badge.new{background:var(--g3);}
.pcard-badge.hot{background:var(--acc);color:var(--txt);}
.pcard-wish{
    position:absolute;top:8px;right:8px;z-index:2;
    width:28px;height:28px;border-radius:50%;
    background:rgba(255,255,255,.85);border:none;cursor:pointer;
    display:flex;align-items:center;justify-content:center;
    font-size:13px;color:var(--muted);transition:.2s;opacity:0;
}
.pcard:hover .pcard-wish{opacity:1;}
.pcard-wish:hover{color:var(--red);background:#fff;}
.pcard-img{
    aspect-ratio:1;background:var(--bg);
    display:flex;align-items:center;justify-content:center;
    font-size:60px;overflow:hidden;padding:10px;
    position:relative;
}
.pcard-img img{width:100%;height:100%;object-fit:contain;}
.pcard-img .pi-overlay{
    position:absolute;inset:0;background:rgba(6,95,70,.08);
    display:flex;align-items:center;justify-content:center;gap:8px;
    opacity:0;transition:.22s;
}
.pcard:hover .pi-overlay{opacity:1;}
.pi-btn{
    background:var(--g2);color:#fff;border:none;
    width:36px;height:36px;border-radius:9px;cursor:pointer;font-size:14px;
    display:flex;align-items:center;justify-content:center;transition:.2s;
}
.pi-btn:hover{background:var(--g1);}
.pcard-body{padding:10px 12px 14px;}
.pcard-brand{font-size:10px;color:var(--g3);font-weight:800;text-transform:uppercase;letter-spacing:.5px;margin-bottom:3px;}
.pcard-name{font-size:13px;font-weight:700;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:36px;margin-bottom:7px;}
.pcard-spec{font-size:11px;color:var(--muted);margin-bottom:7px;display:flex;gap:6px;flex-wrap:wrap;}
.pcard-spec span{background:var(--bg);padding:2px 6px;border-radius:5px;border:1px solid var(--border);}
.pcard-price{font-size:17px;font-weight:900;color:var(--g1);}
.pcard-old{font-size:11px;color:var(--muted);text-decoration:line-through;margin-left:5px;}
.pcard-footer{display:flex;align-items:center;justify-content:space-between;margin-top:8px;}
.pcard-rating{font-size:11px;color:var(--acc);display:flex;align-items:center;gap:3px;}
.pcard-sold{font-size:10px;color:var(--muted);}
.pcard-add{
    background:var(--gl);color:var(--g2);border:none;
    border-radius:7px;width:32px;height:32px;
    display:flex;align-items:center;justify-content:center;
    font-size:15px;cursor:pointer;transition:.2s;
}
.pcard-add:hover{background:var(--g3);color:#fff;}

/* ============================================================
   CATEGORY BANNER GRID
============================================================ */
.cat-banners{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;}
.cat-banner{
    border-radius:12px;padding:20px 16px;cursor:pointer;
    transition:.22s;box-shadow:var(--sh);
    display:flex;flex-direction:column;align-items:center;text-align:center;gap:8px;
    position:relative;overflow:hidden;
}
.cat-banner:hover{transform:translateY(-4px);box-shadow:var(--sh2);}
.cat-banner::before{content:'';position:absolute;inset:0;opacity:.07;background:repeating-linear-gradient(45deg,#fff 0,#fff 1px,transparent 1px,transparent 8px);}
.cat-banner.cb1{background:linear-gradient(135deg,#065f46,#059669);}
.cat-banner.cb2{background:linear-gradient(135deg,#1e3a8a,#3b82f6);}
.cat-banner.cb3{background:linear-gradient(135deg,#7c2d12,#ea580c);}
.cat-banner.cb4{background:linear-gradient(135deg,#4a044e,#a855f7);}
.cb-icon{font-size:36px;filter:drop-shadow(0 3px 6px rgba(0,0,0,.2));}
.cb-title{font-size:14px;font-weight:900;color:#fff;font-family:'Oswald',sans-serif;letter-spacing:.5px;}
.cb-sub{font-size:11px;color:rgba(255,255,255,.75);}
.cb-arrow{
    background:rgba(255,255,255,.2);color:#fff;
    width:28px;height:28px;border-radius:50%;
    display:flex;align-items:center;justify-content:center;
    font-size:12px;margin-top:4px;transition:.2s;
}
.cat-banner:hover .cb-arrow{background:rgba(255,255,255,.35);}

/* ============================================================
   WARRANTY / PROMO BANNER
============================================================ */
.promo-strip{
    background:linear-gradient(90deg,var(--g1) 0%,var(--g2) 40%,var(--g3) 100%);
    border-radius:14px;padding:18px 24px;
    display:flex;align-items:center;gap:0;
    box-shadow:var(--sh2);overflow:hidden;position:relative;
}
.promo-strip::after{content:'';position:absolute;right:-40px;top:-40px;width:200px;height:200px;background:rgba(255,255,255,.05);border-radius:50%;}
.ps-item{display:flex;align-items:center;gap:10px;flex:1;color:#fff;padding:0 20px;border-right:1px solid rgba(255,255,255,.2);}
.ps-item:first-child{padding-left:0;}
.ps-item:last-child{border-right:none;}
.ps-icon{width:44px;height:44px;border-radius:11px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;}
.ps-title{font-size:14px;font-weight:800;}
.ps-sub{font-size:11px;color:rgba(255,255,255,.7);margin-top:1px;}
.ps-cta{margin-left:auto;background:var(--acc);color:var(--txt);padding:11px 26px;border-radius:9px;font-size:13px;font-weight:800;border:none;cursor:pointer;display:flex;align-items:center;gap:7px;transition:.2s;font-family:inherit;flex-shrink:0;z-index:1;}
.ps-cta:hover{background:var(--acc2);transform:translateY(-1px);}

/* ============================================================
   BRAND ROW
============================================================ */
.brand-row{background:var(--white);border-radius:14px;padding:18px 24px;box-shadow:var(--sh);}
.brand-list{display:flex;gap:12px;flex-wrap:wrap;margin-top:12px;}
.brand-chip{
    display:flex;align-items:center;gap:7px;
    padding:8px 16px;border-radius:10px;
    border:1.5px solid var(--border2);background:#fff;
    font-size:13px;font-weight:800;color:var(--txt2);
    cursor:pointer;transition:.2s;
}
.brand-chip:hover,.brand-chip.on{border-color:var(--g3);background:var(--gll);color:var(--g2);}
.brand-chip .brand-ico{font-size:18px;}

/* ============================================================
   BLOG / TIP ROW
============================================================ */
.blog-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;}
.blog-card{background:var(--white);border-radius:12px;overflow:hidden;box-shadow:var(--sh);transition:.22s;cursor:pointer;}
.blog-card:hover{transform:translateY(-4px);box-shadow:var(--sh2);}
.blog-thumb{height:120px;display:flex;align-items:center;justify-content:center;font-size:52px;position:relative;}
.blog-thumb.bt1{background:linear-gradient(135deg,#d1fae5,#a7f3d0);}
.blog-thumb.bt2{background:linear-gradient(135deg,#dbeafe,#bfdbfe);}
.blog-thumb.bt3{background:linear-gradient(135deg,#fef3c7,#fde68a);}
.blog-tag{position:absolute;bottom:8px;left:10px;background:var(--g2);color:#fff;font-size:10px;font-weight:800;padding:3px 9px;border-radius:10px;}
.blog-body{padding:12px 14px;}
.blog-title{font-size:13px;font-weight:800;line-height:1.4;margin-bottom:6px;color:var(--txt);}
.blog-meta{font-size:11px;color:var(--muted);display:flex;align-items:center;gap:8px;}
.blog-meta i{font-size:10px;}

/* ============================================================
   FLOATING ACCOUNT PANEL
============================================================ */
.acc-overlay{
    position:fixed;inset:0;z-index:999;
    background:rgba(0,0,0,.45);
    display:none;align-items:flex-start;justify-content:flex-end;
    padding:78px 20px 0 0;
    backdrop-filter:blur(2px);
}
.acc-overlay.open{display:flex;}
.acc-panel{
    width:440px;max-height:calc(100vh - 96px);
    background:var(--white);border-radius:16px;
    box-shadow:0 24px 64px rgba(6,95,70,.22);
    overflow:hidden;display:flex;flex-direction:column;
    animation:panelIn .28s cubic-bezier(.34,1.56,.64,1);
}
@keyframes panelIn{from{opacity:0;transform:translateY(-16px) scale(.97);}to{opacity:1;transform:none;}}

/* Panel header */
.apnl-hdr{
    background:linear-gradient(135deg,var(--g1) 0%,var(--g2) 60%,var(--g3) 100%);
    padding:20px 20px 0;color:#fff;flex-shrink:0;
    position:relative;
}
.apnl-close{
    position:absolute;top:14px;right:14px;width:30px;height:30px;
    border-radius:50%;background:rgba(255,255,255,.2);border:none;
    color:#fff;font-size:14px;cursor:pointer;
    display:flex;align-items:center;justify-content:center;transition:.2s;
}
.apnl-close:hover{background:rgba(255,255,255,.35);}
.apnl-user{display:flex;align-items:center;gap:13px;margin-bottom:16px;}
.apnl-ava{
    width:56px;height:56px;border-radius:50%;
    background:rgba(255,255,255,.2);border:2.5px solid rgba(255,255,255,.4);
    display:flex;align-items:center;justify-content:center;
    font-size:24px;font-weight:900;flex-shrink:0;
    font-family:'Oswald',sans-serif;
}
.apnl-name{font-size:17px;font-weight:800;font-family:'Oswald',sans-serif;letter-spacing:.3px;}
.apnl-lv{font-size:12px;opacity:.85;margin-top:3px;display:flex;align-items:center;gap:5px;}
.apnl-stats{display:flex;gap:0;background:rgba(255,255,255,.12);border-radius:10px 10px 0 0;overflow:hidden;margin:0 -20px;}
.apnl-stat{flex:1;padding:10px 8px;text-align:center;border-right:1px solid rgba(255,255,255,.15);}
.apnl-stat:last-child{border-right:none;}
.apnl-stat .v{font-size:18px;font-weight:900;color:var(--acc);font-family:'Oswald',sans-serif;display:block;}
.apnl-stat .l{font-size:10px;color:rgba(255,255,255,.7);display:block;margin-top:1px;}

/* Tab bar */
.apnl-tabs{
    display:flex;background:var(--g1);flex-shrink:0;
    border-bottom:1px solid var(--border);
}
.apnl-tab{
    flex:1;padding:10px 4px;color:rgba(255,255,255,.65);
    background:none;border:none;cursor:pointer;transition:.2s;
    font-size:11px;font-weight:800;font-family:inherit;
    display:flex;flex-direction:column;align-items:center;gap:3px;
    border-bottom:2.5px solid transparent;
}
.apnl-tab i{font-size:15px;}
.apnl-tab.on,.apnl-tab:hover{color:#fff;border-bottom-color:var(--acc);}

/* Panel body */
.apnl-body{overflow-y:auto;flex:1;padding:16px;}

/* --- Dashboard --- */
.mini-vip{background:linear-gradient(135deg,var(--gll),var(--gl));border-radius:12px;padding:14px 16px;margin-bottom:14px;border:1.5px solid var(--border);}
.mvip-row{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;}
.mvip-title{font-size:13px;font-weight:800;display:flex;align-items:center;gap:6px;color:var(--g1);}
.mvip-badge{font-size:11px;font-weight:800;padding:3px 12px;border-radius:20px;border:1.5px solid;}
.vip-bar-track{background:#d1fae5;border-radius:20px;height:11px;overflow:hidden;}
.vip-bar-fill{height:100%;border-radius:20px;background:linear-gradient(90deg,var(--g2),var(--g4));transition:width 1.2s cubic-bezier(.34,1.56,.64,1);}
.vip-bar-note{font-size:11px;color:var(--muted);text-align:center;margin-top:6px;}

.quick-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:9px;margin-bottom:14px;}
.qbtn{
    display:flex;flex-direction:column;align-items:center;gap:6px;
    padding:12px 8px;border-radius:11px;
    border:1.5px solid var(--border);background:#fff;
    color:var(--txt2);font-size:11px;font-weight:700;text-align:center;
    cursor:pointer;transition:.2s;
}
.qbtn:hover{border-color:var(--g3);background:var(--gll);color:var(--g2);}
.qbtn .qi{width:36px;height:36px;border-radius:9px;background:var(--gl);color:var(--g2);display:flex;align-items:center;justify-content:center;font-size:16px;transition:.2s;}
.qbtn:hover .qi{background:var(--g3);color:#fff;}

.logout-row{
    display:flex;align-items:center;gap:9px;padding:12px 14px;
    border-radius:11px;background:#fff5f5;border:1.5px solid #fecaca;
    color:#ef4444;font-weight:800;font-size:13px;cursor:pointer;transition:.2s;
    margin-top:6px;
}
.logout-row:hover{background:#fee2e2;}

/* --- Profile view --- */
.pv-list{display:flex;flex-direction:column;gap:9px;}
.pv-row{display:flex;align-items:center;gap:11px;padding:11px 13px;background:var(--bg);border-radius:10px;border:1px solid var(--border);}
.pv-ico{width:36px;height:36px;border-radius:8px;background:var(--gl);color:var(--g2);display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;}
.pv-lbl{font-size:10px;color:var(--muted);display:block;}
.pv-val{font-size:13px;font-weight:700;display:block;margin-top:1px;}
.edit-btn{
    width:100%;margin-top:10px;padding:12px;
    background:linear-gradient(90deg,var(--g2),var(--g3));color:#fff;border:none;
    border-radius:10px;font-size:13px;font-weight:800;cursor:pointer;font-family:inherit;
    display:flex;align-items:center;justify-content:center;gap:8px;transition:.2s;
}
.edit-btn:hover{background:linear-gradient(90deg,var(--g1),var(--g2));}

/* Edit form */
.pf-form{display:flex;flex-direction:column;gap:11px;}
.pf-lbl{font-size:11px;font-weight:700;display:block;margin-bottom:4px;color:var(--txt2);}
.pf-input{
    width:100%;padding:10px 13px;
    border:1.5px solid var(--border2);border-radius:9px;
    font-size:13px;font-family:inherit;background:#fff;
    transition:.2s;color:var(--txt);
}
.pf-input:focus{outline:none;border-color:var(--g3);box-shadow:0 0 0 3px rgba(16,185,129,.12);}
.pf-input[readonly]{background:var(--bg);color:var(--muted);}
.pf-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.pf-actions{display:flex;gap:8px;}
.pf-save{flex:1;padding:11px;background:linear-gradient(90deg,var(--g2),var(--g3));color:#fff;border:none;border-radius:9px;font-size:13px;font-weight:800;cursor:pointer;font-family:inherit;transition:.2s;}
.pf-save:hover{background:linear-gradient(90deg,var(--g1),var(--g2));}
.pf-cancel{padding:11px 16px;background:#fff;color:var(--muted);border:1.5px solid var(--border2);border-radius:9px;font-size:13px;cursor:pointer;font-family:inherit;transition:.2s;}
.pf-cancel:hover{border-color:var(--muted);}
.alert-ok{background:#d1fae5;color:#065f46;border:1px solid #6ee7b7;padding:10px 14px;border-radius:9px;font-size:13px;display:flex;align-items:center;gap:8px;margin-bottom:12px;font-weight:700;}

/* --- Orders --- */
.ord-list{display:flex;flex-direction:column;gap:10px;}
.ord-item{background:var(--bg);border-radius:11px;padding:13px;border:1px solid var(--border);}
.ord-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:7px;}
.ord-id{font-weight:900;color:var(--g2);font-size:13px;}
.ord-badge{font-size:11px;font-weight:800;padding:3px 11px;border-radius:20px;}
.status-pending{background:#fef3c7;color:#92400e;}
.status-processing{background:#dbeafe;color:#1d4ed8;}
.status-completed{background:#d1fae5;color:#065f46;}
.status-cancelled{background:#fee2e2;color:#991b1b;}
.ord-info{font-size:12px;color:var(--muted);}
.ord-bot{display:flex;align-items:center;justify-content:space-between;margin-top:8px;}
.ord-total{font-weight:900;color:var(--g1);font-size:14px;}
.ord-detail{font-size:12px;font-weight:800;color:var(--g2);background:var(--gl);padding:5px 13px;border-radius:7px;}
.empty-panel{text-align:center;padding:40px 20px;color:var(--muted);}
.empty-panel .ep-ico{font-size:44px;opacity:.25;margin-bottom:12px;}
.empty-panel h3{font-size:16px;color:var(--txt2);margin-bottom:6px;}

/* --- Points --- */
.pts-hero{background:linear-gradient(135deg,#fbbf24,#f59e0b);border-radius:12px;padding:22px;text-align:center;margin-bottom:14px;}
.pts-num{font-size:52px;font-weight:900;color:#78350f;font-family:'Oswald',sans-serif;line-height:1;}
.pts-lbl{font-size:13px;color:#92400e;font-weight:700;margin-top:3px;}
.lv-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;}
.lv-card{border-radius:10px;padding:13px;text-align:center;border:2px solid var(--border2);position:relative;}
.lv-card.cur{border-color:var(--g3);background:var(--gll);}
.lv-cur-tag{position:absolute;top:6px;right:6px;background:var(--g2);color:#fff;font-size:9px;font-weight:800;padding:2px 7px;border-radius:8px;}
.lv-ico{font-size:26px;display:block;margin-bottom:5px;}
.lv-name{font-size:12px;font-weight:900;margin-bottom:2px;font-family:'Oswald',sans-serif;}
.lv-req{font-size:10px;color:var(--muted);}
.pts-how{background:var(--bg);border-radius:10px;padding:13px;margin-top:12px;border:1px solid var(--border);}
.pts-how-title{font-size:12px;font-weight:800;margin-bottom:8px;color:var(--g1);display:flex;align-items:center;gap:6px;}
.pts-how-list{display:flex;flex-direction:column;gap:5px;}
.pts-how-list li{font-size:11px;color:var(--muted);display:flex;align-items:center;gap:7px;}
.pts-how-list li i{color:var(--g3);font-size:10px;}

/* --- Address --- */
.addr-card{border:2px solid var(--g3);border-radius:11px;padding:16px;background:var(--gll);position:relative;}
.addr-def{position:absolute;top:10px;right:10px;background:var(--g2);color:#fff;font-size:10px;font-weight:800;padding:2px 9px;border-radius:8px;}
.addr-name{font-weight:800;font-size:14px;margin-bottom:3px;}
.addr-phone{font-size:12px;color:var(--muted);margin-bottom:6px;}
.addr-txt{font-size:13px;color:var(--txt2);}
.addr-edit-btn{width:100%;margin-top:12px;padding:9px;background:#fff;border:1.5px solid var(--g3);color:var(--g2);border-radius:9px;font-size:12px;font-weight:800;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:7px;transition:.2s;}
.addr-edit-btn:hover{background:var(--gl);}

/* ============================================================
   FOOTER MINI
============================================================ */
.site-footer{background:var(--g1);color:rgba(255,255,255,.8);padding:28px 0 18px;margin-top:30px;}
.footer-inner{max-width:1280px;margin:0 auto;padding:0 18px;display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:28px;}
.ft-brand .brand-name{font-family:'Oswald',sans-serif;font-size:22px;color:#fff;letter-spacing:1px;margin-bottom:6px;}
.ft-brand p{font-size:12px;line-height:1.7;opacity:.7;max-width:240px;}
.ft-col h4{font-size:13px;font-weight:800;color:#fff;margin-bottom:12px;text-transform:uppercase;letter-spacing:.5px;}
.ft-col ul{display:flex;flex-direction:column;gap:7px;}
.ft-col ul li a{font-size:12px;color:rgba(255,255,255,.65);transition:.15s;}
.ft-col ul li a:hover{color:#fff;}
.footer-bottom{max-width:1280px;margin:18px auto 0;padding:14px 18px 0;border-top:1px solid rgba(255,255,255,.12);display:flex;justify-content:space-between;align-items:center;font-size:11px;color:rgba(255,255,255,.5);}

/* ============================================================
   MODAL OVERLAY (dùng chung cho 3 modal)
============================================================ */
.modal-overlay{
    position:fixed;inset:0;background:rgba(0,0,0,.55);
    z-index:500;display:flex;align-items:center;justify-content:center;
    opacity:0;pointer-events:none;transition:opacity .25s;padding:16px;
}
.modal-overlay.open{opacity:1;pointer-events:all;}
.modal-box{
    background:#fff;border-radius:18px;width:100%;max-width:780px;
    max-height:90vh;overflow:hidden;display:flex;flex-direction:column;
    transform:translateY(30px) scale(.97);transition:transform .25s;
    box-shadow:0 24px 60px rgba(0,0,0,.22);
}
.modal-overlay.open .modal-box{transform:translateY(0) scale(1);}
.modal-hdr{
    display:flex;align-items:center;gap:12px;
    padding:18px 22px;border-bottom:1px solid var(--border2);
    background:linear-gradient(135deg,var(--g2),var(--g3));
    flex-shrink:0;
}
.modal-hdr-icon{
    width:44px;height:44px;background:rgba(255,255,255,.2);
    border-radius:12px;display:flex;align-items:center;justify-content:center;
    font-size:20px;flex-shrink:0;
}
.modal-hdr-title{font-family:'Oswald',sans-serif;font-size:18px;font-weight:700;color:#fff;letter-spacing:.5px;}
.modal-hdr-sub{font-size:11px;color:rgba(255,255,255,.75);margin-top:1px;}
.modal-close{
    margin-left:auto;width:36px;height:36px;border-radius:9px;
    background:rgba(255,255,255,.18);border:none;color:#fff;
    font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;
    transition:.18s;flex-shrink:0;
}
.modal-close:hover{background:rgba(255,255,255,.32);}
.modal-body{overflow-y:auto;padding:24px 22px;flex:1;}

/* ---- PRODUCT DETAIL MODAL ---- */
.pd-top{display:grid;grid-template-columns:200px 1fr;gap:24px;margin-bottom:24px;}
.pd-img-box{
    background:var(--gll);border-radius:14px;
    display:flex;align-items:center;justify-content:center;
    font-size:80px;min-height:200px;
}
.pd-price-block{display:flex;align-items:baseline;gap:10px;margin:10px 0 6px;}
.pd-price{font-size:26px;font-weight:900;color:var(--g2);font-family:'Oswald',sans-serif;}
.pd-old{font-size:15px;color:var(--muted);text-decoration:line-through;}
.pd-disc{background:var(--red);color:#fff;font-size:11px;font-weight:800;padding:2px 8px;border-radius:8px;}
.pd-specs{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:14px 0;}
.pd-spec-row{
    display:flex;gap:8px;align-items:flex-start;
    background:var(--gll);border-radius:9px;padding:8px 11px;
}
.pd-spec-lbl{font-size:11px;color:var(--muted);font-weight:600;width:90px;flex-shrink:0;padding-top:1px;}
.pd-spec-val{font-size:12px;color:var(--txt);font-weight:700;}
.pd-desc{font-size:13px;color:var(--txt2);line-height:1.7;border-top:1px solid var(--border2);padding-top:14px;margin-top:4px;}
.pd-actions{display:flex;gap:10px;margin-top:16px;}
.pd-btn-buy{
    flex:1;background:linear-gradient(90deg,var(--g2),var(--g3));
    color:#fff;border:none;border-radius:10px;padding:13px;
    font-size:14px;font-weight:800;cursor:pointer;font-family:inherit;
    display:flex;align-items:center;justify-content:center;gap:8px;
    transition:.2s;
}
.pd-btn-buy:hover{background:linear-gradient(90deg,var(--g1),var(--g2));transform:translateY(-2px);}
.pd-btn-cart{
    background:var(--gll);color:var(--g2);border:2px solid var(--g3);
    border-radius:10px;padding:13px 18px;font-size:14px;font-weight:800;
    cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:7px;
    transition:.2s;
}
.pd-btn-cart:hover{background:var(--gl);}
.pd-highlight{
    display:flex;align-items:flex-start;gap:10px;
    font-size:13px;font-weight:600;color:var(--txt2);
    background:var(--gll);border-radius:9px;padding:8px 12px;
    border-left:3px solid var(--g3);
}
.pd-warranty-note{
    display:flex;align-items:center;gap:10px;
    background:var(--gll);border-radius:10px;padding:10px 14px;margin-top:14px;
    font-size:12px;color:var(--g2);font-weight:700;
}
.pd-warranty-note i{color:var(--g3);font-size:16px;}
.pd-tabs{display:flex;gap:4px;border-bottom:2px solid var(--border2);margin-bottom:16px;}
.pd-tab{
    padding:8px 16px;font-size:13px;font-weight:700;color:var(--muted);
    border:none;background:none;cursor:pointer;border-bottom:2px solid transparent;
    margin-bottom:-2px;font-family:inherit;transition:.18s;
}
.pd-tab.on{color:var(--g2);border-bottom-color:var(--g3);}
.pd-tabpanel{display:none;}
.pd-tabpanel.on{display:block;}
.pd-review-stars{display:flex;gap:3px;color:var(--acc);font-size:15px;}
.pd-reviews{display:flex;flex-direction:column;gap:12px;margin-top:12px;}
.pd-review-item{background:var(--bg2);border-radius:10px;padding:12px 14px;}
.pd-review-name{font-size:13px;font-weight:700;color:var(--txt);}
.pd-review-date{font-size:11px;color:var(--muted);margin-left:8px;}
.pd-review-text{font-size:12px;color:var(--txt2);margin-top:5px;line-height:1.6;}

/* ---- WARRANTY MODAL ---- */
.wty-hero{
    background:linear-gradient(135deg,var(--g2),var(--g3));
    border-radius:14px;padding:20px;margin-bottom:20px;
    display:flex;align-items:center;gap:16px;
}
.wty-hero-icon{font-size:48px;}
.wty-hero-title{font-family:'Oswald',sans-serif;font-size:22px;color:#fff;font-weight:700;}
.wty-hero-sub{font-size:12px;color:rgba(255,255,255,.8);margin-top:3px;line-height:1.6;}
.wty-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:20px;}
.wty-card{
    background:var(--gll);border-radius:12px;padding:16px;text-align:center;
    border:1.5px solid var(--border);
}
.wty-card-icon{font-size:30px;margin-bottom:8px;}
.wty-card-title{font-size:13px;font-weight:800;color:var(--g2);margin-bottom:4px;}
.wty-card-desc{font-size:11px;color:var(--muted);line-height:1.5;}
.wty-section{margin-bottom:18px;}
.wty-section-title{
    font-size:14px;font-weight:800;color:var(--g1);margin-bottom:10px;
    display:flex;align-items:center;gap:8px;padding-bottom:6px;
    border-bottom:2px solid var(--gl);
}
.wty-section-title i{color:var(--g3);}
.wty-list{display:flex;flex-direction:column;gap:7px;}
.wty-list-item{
    display:flex;align-items:flex-start;gap:9px;
    font-size:12.5px;color:var(--txt2);line-height:1.55;
}
.wty-list-item i{color:var(--g3);font-size:13px;margin-top:2px;flex-shrink:0;}
.wty-list-item.no i{color:var(--red);}
.wty-process{display:flex;gap:0;margin:14px 0;overflow-x:auto;}
.wty-step{
    flex:1;text-align:center;position:relative;min-width:90px;
}
.wty-step::after{
    content:'';position:absolute;top:22px;left:50%;width:100%;height:2px;
    background:var(--gl);z-index:0;
}
.wty-step:last-child::after{display:none;}
.wty-step-num{
    width:44px;height:44px;border-radius:50%;
    background:var(--g3);color:#fff;font-weight:900;font-size:16px;
    display:flex;align-items:center;justify-content:center;
    margin:0 auto 7px;position:relative;z-index:1;
    font-family:'Oswald',sans-serif;
}
.wty-step-lbl{font-size:11px;color:var(--txt2);font-weight:600;line-height:1.4;}
.wty-contact-bar{
    display:flex;gap:10px;background:var(--g1);border-radius:12px;
    padding:14px 18px;align-items:center;flex-wrap:wrap;
}
.wty-contact-bar span{color:rgba(255,255,255,.85);font-size:13px;font-weight:700;}
.wty-contact-bar a{
    margin-left:auto;background:var(--acc);color:var(--txt);
    padding:9px 18px;border-radius:9px;font-weight:800;font-size:13px;
    display:flex;align-items:center;gap:6px;
}

/* ---- REPAIR MODAL ---- */
.rep-banner{
    background:linear-gradient(135deg,#1b5e20,#2e7d32);
    border-radius:14px;padding:18px 20px;margin-bottom:20px;
    display:flex;align-items:center;gap:14px;
}
.rep-banner-icon{font-size:44px;}
.rep-banner-title{font-family:'Oswald',sans-serif;font-size:20px;color:#fff;font-weight:700;}
.rep-banner-sub{font-size:12px;color:rgba(255,255,255,.8);margin-top:3px;}
.rep-services{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:20px;}
.rep-svc{
    border:1.5px solid var(--border);border-radius:12px;
    padding:14px;transition:.2s;cursor:default;
}
.rep-svc:hover{border-color:var(--g3);background:var(--gll);}
.rep-svc-head{display:flex;align-items:center;gap:9px;margin-bottom:8px;}
.rep-svc-icon{
    width:38px;height:38px;border-radius:9px;
    background:linear-gradient(135deg,var(--g2),var(--g3));
    display:flex;align-items:center;justify-content:center;
    color:#fff;font-size:16px;flex-shrink:0;
}
.rep-svc-name{font-size:13px;font-weight:800;color:var(--txt);}
.rep-svc-price{font-size:12px;color:var(--g2);font-weight:700;margin-bottom:6px;}
.rep-svc-items{display:flex;flex-direction:column;gap:3px;}
.rep-svc-items li{font-size:11.5px;color:var(--muted);display:flex;align-items:center;gap:5px;}
.rep-svc-items li::before{content:'•';color:var(--g4);}
.rep-promise{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:20px;}
.rep-prom-item{text-align:center;background:var(--gll);border-radius:10px;padding:12px 8px;}
.rep-prom-icon{font-size:24px;margin-bottom:5px;}
.rep-prom-txt{font-size:11px;font-weight:700;color:var(--g2);line-height:1.4;}
.rep-section-title{font-size:14px;font-weight:800;color:var(--g1);margin-bottom:10px;display:flex;align-items:center;gap:8px;}
.rep-section-title i{color:var(--g3);}
.rep-form{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.rep-input{
    width:100%;border:1.5px solid var(--border2);border-radius:9px;
    padding:10px 13px;font-size:13px;font-family:inherit;color:var(--txt);
    transition:.18s;outline:none;background:#fff;
}
.rep-input:focus{border-color:var(--g3);box-shadow:0 0 0 3px rgba(5,150,105,.12);}
.rep-input.full{grid-column:1/-1;}
.rep-submit{
    grid-column:1/-1;
    background:linear-gradient(90deg,var(--g2),var(--g3));
    color:#fff;border:none;border-radius:10px;padding:13px;
    font-size:14px;font-weight:800;cursor:pointer;font-family:inherit;
    display:flex;align-items:center;justify-content:center;gap:8px;
    transition:.2s;
}
.rep-submit:hover{background:linear-gradient(90deg,var(--g1),var(--g2));transform:translateY(-2px);}
.rep-note{
    grid-column:1/-1;
    display:flex;align-items:center;gap:8px;
    background:var(--gll);border-radius:9px;padding:10px 13px;
    font-size:12px;color:var(--g2);font-weight:600;
}
.rep-note i{color:var(--g3);}

/* ---- TOPBAR LINKS (bảo hành, sửa chữa) ---- */
.topbar a.tb-modal-link{cursor:pointer;}

/* ============================================================
   BACK TO TOP
============================================================ */
.btt{
    position:fixed;bottom:24px;right:24px;
    width:42px;height:42px;border-radius:50%;
    background:linear-gradient(135deg,var(--g2),var(--g3));
    color:#fff;border:none;cursor:pointer;font-size:17px;
    display:flex;align-items:center;justify-content:center;
    box-shadow:0 4px 16px rgba(6,95,70,.35);
    transition:.2s;z-index:100;opacity:0;pointer-events:none;
}
.btt.show{opacity:1;pointer-events:all;}
.btt:hover{transform:translateY(-3px);}

/* ============================================================
   RESPONSIVE
============================================================ */
@media(max-width:1100px){
    .prod-grid{grid-template-columns:repeat(4,1fr);}
    .hdr-contacts{display:none;}
    .cat-banners{grid-template-columns:repeat(2,1fr);}
}
@media(max-width:900px){
    .page-body{grid-template-columns:1fr;}
    .left-col{display:none;}
    .prod-grid{grid-template-columns:repeat(3,1fr);}
    .feature-strip{grid-template-columns:repeat(2,1fr);}
    .blog-grid{grid-template-columns:1fr 1fr;}
}
@media(max-width:600px){
    .prod-grid{grid-template-columns:repeat(2,1fr);}
    .hero-grid{grid-template-columns:1fr;}
    .hero-right{display:none;}
    .promo-strip{flex-direction:column;gap:12px;}
    .ps-item{border-right:none;border-bottom:1px solid rgba(255,255,255,.2);padding:0 0 12px;}
    .ps-item:last-child{border-bottom:none;}
    .acc-panel{width:100%;border-radius:12px 12px 0 0;}
    .acc-overlay{padding:0;align-items:flex-end;}
    .blog-grid{grid-template-columns:1fr;}
}
</style>
</head>
<body>

<!-- TOP BAR -->
<div class="topbar">
    <div class="wrap">
        <div class="tb-hotline"><i class="fas fa-phone-volume"></i> Hotline: 0787.911.555 – 0866.589.959</div>
        <div class="tb-links">
            <a href="#"><i class="fas fa-map-marker-alt"></i>57 Nguyễn Bình, Hải Phòng</a>
            <a href="#"><i class="fas fa-truck"></i>Theo dõi đơn hàng</a>
            <a href="#" onclick="openModal('warrantyModal');return false;"><i class="fas fa-shield-alt"></i>Bảo hành</a>
            <a href="#" onclick="openModal('repairModal');return false;"><i class="fas fa-headset"></i>Hỗ trợ</a>
        </div>
    </div>
</div>

<!-- HEADER -->
<header class="site-header">
    <div class="hdr-inner">
        <a href="index.php" class="logo">
            <div class="logo-mark"><span>QA</span></div>
            <div class="logo-text">
                <div class="brand">MÁY TÍNH QUANG ANH</div>
                <div class="tagline">computer store – hải phòng</div>
            </div>
        </a>

        <div class="hdr-search">
            <input type="text" placeholder="Tìm laptop, PC, linh kiện, phụ kiện...">
            <button><i class="fas fa-search"></i></button>
        </div>

        <div class="hdr-contacts">
            <div class="hdr-contact">
                <div class="hc-icon"><i class="fas fa-phone-alt"></i></div>
                <div><span class="hc-label">Hotline 24/7</span><span class="hc-num">0787.911.555</span></div>
            </div>
            <div class="hdr-contact">
                <div class="hc-icon"><i class="fas fa-tools"></i></div>
                <div><span class="hc-label">Kỹ thuật</span><span class="hc-num">0866.589.959</span></div>
            </div>
        </div>

        <div class="hdr-btns">
            <a href="wishlist.php" class="hbtn" title="Yêu thích"><i class="fas fa-heart"></i></a>
            <a href="compare.php" class="hbtn" title="So sánh"><i class="fas fa-balance-scale"></i></a>
            <a href="cart.php" class="hbtn" title="Giỏ hàng"><i class="fas fa-shopping-cart"></i><span class="bdg">0</span></a>
            <button class="hbtn acc-btn" id="accBtn" onclick="openPanel()" title="Tài khoản">
                <i class="fas fa-user"></i>
            </button>
        </div>
    </div>

    <div class="nav-strip">
        <div class="wrap">
            <a href="?category=laptop" class="nav-item"><i class="fas fa-laptop"></i>Laptop</a>
            <a href="?category=pc" class="nav-item"><i class="fas fa-desktop"></i>PC Bàn</a>
            <a href="?category=linh_kien" class="nav-item"><i class="fas fa-microchip"></i>Linh kiện</a>
            <a href="?category=gaming" class="nav-item hot"><i class="fas fa-gamepad"></i>Gaming</a>
            <a href="?category=man_hinh" class="nav-item"><i class="fas fa-tv"></i>Màn hình</a>
            <a href="?category=phim_chuot" class="nav-item"><i class="fas fa-keyboard"></i>Phím & Chuột</a>
            <a href="?category=mang" class="nav-item"><i class="fas fa-wifi"></i>Thiết bị mạng</a>
            <a href="#" onclick="openModal('repairModal');return false;" class="nav-item hot"><i class="fas fa-wrench"></i>Sửa chữa</a>
            <div class="ns-addr"><i class="fas fa-store"></i>57 NGUYỄN BÌNH – LÊ CHÂN – HẢI PHÒNG</div>
        </div>
    </div>
</header>

<!-- PAGE BODY -->
<div class="page-body">

    <!-- LEFT SIDEBAR -->
    <aside class="left-col">
        <!-- Category menu -->
        <div class="cat-box">
            <div class="cat-head"><i class="fas fa-th-large"></i>Danh mục sản phẩm</div>
            <ul class="cat-list">
                <li><a href="?category=laptop" class="cat-item active">
                    <div class="cat-icon"><i class="fas fa-laptop"></i></div>Laptop
                    <span class="cat-count">120+</span>
                </a></li>
                <li><a href="?category=laptop_gaming" class="cat-item">
                    <div class="cat-icon"><i class="fas fa-gamepad"></i></div>Laptop Gaming
                    <i class="fas fa-chevron-right cat-arrow"></i>
                </a></li>
                <li><a href="?category=macbook" class="cat-item">
                    <div class="cat-icon"><i class="fab fa-apple"></i></div>MacBook
                    <i class="fas fa-chevron-right cat-arrow"></i>
                </a></li>
                <li><a href="?category=pc_ban" class="cat-item">
                    <div class="cat-icon"><i class="fas fa-desktop"></i></div>Máy tính để bàn
                    <span class="cat-count">60+</span>
                </a></li>
                <li><a href="?category=pc_gaming" class="cat-item">
                    <div class="cat-icon"><i class="fas fa-bolt"></i></div>PC Gaming
                    <i class="fas fa-chevron-right cat-arrow"></i>
                </a></li>
                <li><a href="?category=man_hinh" class="cat-item">
                    <div class="cat-icon"><i class="fas fa-tv"></i></div>Màn hình
                    <i class="fas fa-chevron-right cat-arrow"></i>
                </a></li>
                <li><a href="?category=ram_ssd" class="cat-item">
                    <div class="cat-icon"><i class="fas fa-memory"></i></div>RAM & SSD
                    <i class="fas fa-chevron-right cat-arrow"></i>
                </a></li>
                <li><a href="?category=phim_chuot" class="cat-item">
                    <div class="cat-icon"><i class="fas fa-keyboard"></i></div>Phím & Chuột
                    <i class="fas fa-chevron-right cat-arrow"></i>
                </a></li>
                <li><a href="?category=mang" class="cat-item">
                    <div class="cat-icon"><i class="fas fa-wifi"></i></div>Thiết bị mạng
                    <i class="fas fa-chevron-right cat-arrow"></i>
                </a></li>
                <hr class="cat-sep">
                <li><a href="?category=van_phong" class="cat-item">
                    <div class="cat-icon"><i class="fas fa-print"></i></div>Thiết bị văn phòng
                    <i class="fas fa-chevron-right cat-arrow"></i>
                </a></li>
                <li><a href="?category=cho_thue" class="cat-item">
                    <div class="cat-icon"><i class="fas fa-handshake"></i></div>Cho thuê laptop
                    <i class="fas fa-chevron-right cat-arrow"></i>
                </a></li>
            </ul>
        </div>

        <!-- Service -->
        <div class="service-box">
            <div class="srv-head"><i class="fas fa-tools"></i>Dịch vụ sửa chữa</div>
            <ul class="srv-list">
                <li class="srv-item" onclick="openModal('repairModal')"><div class="srv-dot"></div>Cài Win, vệ sinh từ 50k</li>
                <li class="srv-item" onclick="openModal('repairModal')"><div class="srv-dot"></div>Sửa không lên nguồn</li>
                <li class="srv-item" onclick="openModal('repairModal')"><div class="srv-dot"></div>Thay màn hình, bàn phím</li>
                <li class="srv-item" onclick="openModal('repairModal')"><div class="srv-dot"></div>Nâng cấp RAM, SSD</li>
                <li class="srv-item" onclick="openModal('repairModal')"><div class="srv-dot"></div>Sửa bản lề, vỏ máy</li>
                <li class="srv-item" onclick="openModal('repairModal')"><div class="srv-dot"></div>Lấy ngay trong ngày</li>
            </ul>
        </div>

        <!-- Flash deal -->
        <div class="flash-box">
            <div class="flash-title"><i class="fas fa-bolt"></i>FLASH DEAL HÔM NAY</div>
            <div class="flash-countdown">
                <div class="countdown-block"><span class="num" id="cdH">05</span><span class="lbl">Giờ</span></div>
                <span class="countdown-sep">:</span>
                <div class="countdown-block"><span class="num" id="cdM">32</span><span class="lbl">Phút</span></div>
                <span class="countdown-sep">:</span>
                <div class="countdown-block"><span class="num" id="cdS">00</span><span class="lbl">Giây</span></div>
            </div>
            <div class="flash-item">
                <div class="fi-ico">💻</div>
                <div>
                    <div class="fi-name">Dell Latitude E7490 i7</div>
                    <div><span class="fi-price">8.990.000đ</span><span class="fi-old">12.500.000đ</span></div>
                </div>
            </div>
            <div class="flash-item">
                <div class="fi-ico">🖥️</div>
                <div>
                    <div class="fi-name">PC Gaming i5 RTX 3060</div>
                    <div><span class="fi-price">14.990.000đ</span><span class="fi-old">18.000.000đ</span></div>
                </div>
            </div>
            <div class="flash-item">
                <div class="fi-ico">🖱️</div>
                <div>
                    <div class="fi-name">RAM DDR4 16GB 3200MHz</div>
                    <div><span class="fi-price">890.000đ</span><span class="fi-old">1.200.000đ</span></div>
                </div>
            </div>
        </div>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="main-col">

        <!-- HERO -->
        <div class="hero-grid">
            <div class="hero-slider" id="heroSlider">
                <!-- Slide 1 -->
                <div class="hero-slide s1 active">
                    <div style="flex:1">
                        <span class="slide-badge">🔧 Dịch vụ sửa chữa</span>
                        <div class="slide-title">SỬA CHỮA<br>LAPTOP – PC</div>
                        <ul class="slide-checklist">
                            <li><i class="fas fa-check-circle"></i>Cài win, vệ sinh chỉ từ 50k</li>
                            <li><i class="fas fa-check-circle"></i>Sửa không lên nguồn từ 300k</li>
                            <li><i class="fas fa-check-circle"></i>Thay màn hình, bàn phím từ 299k</li>
                            <li><i class="fas fa-check-circle"></i>Nâng cấp SSD, RAM từ 499k</li>
                        </ul>
                        <a href="?category=sua_chua" class="slide-cta"><i class="fas fa-tools"></i>Xem dịch vụ</a>
                    </div>
                    <div class="slide-visual">💻</div>
                </div>
                <!-- Slide 2 -->
                <div class="hero-slide s2">
                    <div style="flex:1">
                        <span class="slide-badge yellow">🔥 Hot deal</span>
                        <div class="slide-title" style="color:#92400e">LAPTOP<br>GIÁ SỐC</div>
                        <p class="slide-desc">Laptop văn phòng, gaming chính hãng<br>Giá tốt nhất Hải Phòng – BH 12 tháng</p>
                        <a href="?category=laptop" class="slide-cta yellow"><i class="fas fa-laptop"></i>Mua ngay</a>
                    </div>
                    <div class="slide-visual">🔥</div>
                </div>
                <!-- Slide 3 -->
                <div class="hero-slide s3">
                    <div style="flex:1">
                        <span class="slide-badge blue">💎 Build PC</span>
                        <div class="slide-title" style="color:#1e3a8a">PC GAMING<br>THEO YÊU CẦU</div>
                        <p class="slide-desc">Tư vấn build PC theo ngân sách<br>Lắp đặt tận nơi – Bảo hành toàn diện</p>
                        <a href="?category=pc_gaming" class="slide-cta blue"><i class="fas fa-gamepad"></i>Tìm hiểu</a>
                    </div>
                    <div class="slide-visual">🖥️</div>
                </div>
                <div class="slide-dots">
                    <div class="sdot active" onclick="goSlide(0)"></div>
                    <div class="sdot" onclick="goSlide(1)"></div>
                    <div class="sdot" onclick="goSlide(2)"></div>
                </div>
            </div>

            <div class="hero-right">
                <div class="promo-card pc1">
                    <div class="illo">💻</div>
                    <div class="promo-info">
                        <div class="promo-off">25%</div>
                        <div class="promo-off-lbl">GIẢM ĐẾN</div>
                        <div class="promo-prod">Dell Inspiron 7560</div>
                        <div class="promo-price">Từ 12 triệu</div>
                    </div>
                </div>
                <div class="promo-card pc2">
                    <div class="illo">🖥️</div>
                    <div class="promo-info">
                        <div class="promo-off">30%</div>
                        <div class="promo-off-lbl">UP TO</div>
                        <div class="promo-prod">Dell Inspiron 7472</div>
                        <div class="promo-price">Từ 12 triệu</div>
                    </div>
                </div>
                <div class="promo-card pc3">
                    <div class="illo">🖱️</div>
                    <div class="promo-info">
                        <div class="promo-off" style="font-size:22px">10 Triệu</div>
                        <div class="promo-off-lbl">GIÁ CHỈ TỪ</div>
                        <div class="promo-prod">Dell Latitude E7490</div>
                        <div class="promo-price">Hàng chính hãng</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- FEATURE STRIP -->
        <div class="feature-strip">
            <div class="feat-item">
                <div class="feat-icon"><i class="fas fa-shield-alt"></i></div>
                <div><div class="feat-title">Bảo hành 1 đổi 1</div><div class="feat-sub">Lỗi phần cứng trong 12 tháng</div></div>
            </div>
            <div class="feat-item">
                <div class="feat-icon"><i class="fas fa-truck"></i></div>
                <div><div class="feat-title">Giao hàng tận nơi</div><div class="feat-sub">Nội thành Hải Phòng miễn phí</div></div>
            </div>
            <div class="feat-icon" style="display:none"></div>
            <div class="feat-item">
                <div class="feat-icon"><i class="fas fa-credit-card"></i></div>
                <div><div class="feat-title">Hỗ trợ trả góp</div><div class="feat-sub">0% lãi suất 12 tháng</div></div>
            </div>
            <div class="feat-item">
                <div class="feat-icon"><i class="fas fa-headset"></i></div>
                <div><div class="feat-title">Hỗ trợ 24/7</div><div class="feat-sub">Kỹ thuật viên online</div></div>
            </div>
        </div>

        <!-- CATEGORY BANNERS -->
        <div>
            <div class="sec-hdr">
                <div class="sec-title">Danh mục nổi bật</div>
            </div>
            <div class="cat-banners">
                <a href="?category=laptop" class="cat-banner cb1">
                    <div class="cb-icon">💻</div>
                    <div class="cb-title">LAPTOP</div>
                    <div class="cb-sub">Hơn 120 mẫu</div>
                    <div class="cb-arrow"><i class="fas fa-arrow-right"></i></div>
                </a>
                <a href="?category=pc_gaming" class="cat-banner cb2">
                    <div class="cb-icon">🖥️</div>
                    <div class="cb-title">PC GAMING</div>
                    <div class="cb-sub">Build theo yêu cầu</div>
                    <div class="cb-arrow"><i class="fas fa-arrow-right"></i></div>
                </a>
                <a href="?category=linh_kien" class="cat-banner cb3">
                    <div class="cb-icon">⚡</div>
                    <div class="cb-title">LINH KIỆN</div>
                    <div class="cb-sub">RAM, SSD, VGA...</div>
                    <div class="cb-arrow"><i class="fas fa-arrow-right"></i></div>
                </a>
                <a href="?category=sua_chua" class="cat-banner cb4">
                    <div class="cb-icon">🔧</div>
                    <div class="cb-title">SỬA CHỮA</div>
                    <div class="cb-sub">Lấy ngay trong ngày</div>
                    <div class="cb-arrow"><i class="fas fa-arrow-right"></i></div>
                </a>
            </div>
        </div>

        <!-- LAPTOP MỚI VỀ -->
        <div>
            <div class="sec-hdr">
                <div class="sec-title">Laptop mới về</div>
                <div class="sec-filters">
                    <button class="sf on" onclick="filterSec(this,'laptop-grid','all')">Tất cả</button>
                    <button class="sf" onclick="filterSec(this,'laptop-grid','under5')">Dưới 5 triệu</button>
                    <button class="sf" onclick="filterSec(this,'laptop-grid','under8')">Dưới 8 triệu</button>
                    <button class="sf" onclick="filterSec(this,'laptop-grid','under10')">Dưới 10 triệu</button>
                    <button class="sf" onclick="filterSec(this,'laptop-grid','over10')">Trên 10 triệu</button>
                </div>
                <a href="?category=laptop" class="see-all">Xem tất cả <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="prod-grid" id="laptop-grid">
                <?php
                // Sản phẩm từ DB hoặc placeholder
                $laptops_db = array_filter($db_products, fn($p) => stripos($p['name']??'','laptop')!==false || ($p['category']??'')==='laptop');
                if(!empty($laptops_db)){ $show=$laptops_db; } else {
                $show=[
                    ['name'=>'Dell Inspiron 15 3511 i5 Gen 11','price'=>8990000,'old'=>11990000,'brand'=>'Dell',
                     'spec'=>['Intel Core i5-1135G7','8GB DDR4 RAM','512GB NVMe SSD','15.6" FHD IPS','Windows 11'],
                     'desc'=>'Laptop văn phòng bền bỉ, hiệu năng mạnh mẽ với chip Intel Gen 11 mới nhất. Màn hình FHD sắc nét, pin 3 cell dùng cả ngày không lo hết điện. Thiết kế mỏng nhẹ, dễ mang theo.',
                     'highlights'=>['Chip Intel i5 Gen 11 – nhanh hơn 20% so với Gen 10','Màn hình FHD chống chói, góc nhìn rộng 178°','SSD NVMe khởi động chỉ 10 giây','Bảo hành Dell chính hãng 12 tháng tại QA'],
                     'disc'=>25,'img'=>'https://images.unsplash.com/photo-1593642632559-0c6d3fc62b89?w=400&q=80','sold'=>142,'tag'=>'sale','prange'=>'under10','reviews'=>[['name'=>'Nguyễn Văn A','star'=>5,'text'=>'Máy chạy mượt, giá rất tốt. Shop tư vấn nhiệt tình!'],['name'=>'Trần Thị B','star'=>4,'text'=>'Mua về dùng văn phòng rất ổn, pin trâu.']]],

                    ['name'=>'HP Pavilion 14 Core i5 Gen 12','price'=>9490000,'old'=>12990000,'brand'=>'HP',
                     'spec'=>['Intel Core i5-1235U','8GB DDR4 RAM','256GB NVMe SSD','14" FHD IPS 250nits','Intel Iris Xe'],
                     'desc'=>'HP Pavilion 14 – thiết kế premium mỏng nhẹ chỉ 1.41kg, màn hình tràn viền đẹp mắt. Bộ xử lý Intel Gen 12 cho tốc độ vượt trội, lý tưởng cho sinh viên và nhân viên văn phòng.',
                     'highlights'=>['Thiết kế mỏng 17.9mm, trọng lượng chỉ 1.41kg','CPU Intel Core i5-1235U 12 nhân mạnh mẽ','Màn hình tràn viền tỷ lệ 80% screen-to-body','HP Fast Charge: sạc 50% chỉ trong 30 phút'],
                     'disc'=>27,'img'=>'https://images.unsplash.com/photo-1496181133206-80ce9b88a853?w=400&q=80','sold'=>98,'tag'=>'sale','prange'=>'under10','reviews'=>[['name'=>'Lê Văn C','star'=>5,'text'=>'Mỏng đẹp, đi học mang rất nhẹ nhàng. Rất hài lòng!'],['name'=>'Phạm Thị D','star'=>5,'text'=>'HP chính hãng, bảo hành tốt, shop giao hàng nhanh.']]],

                    ['name'=>'Lenovo IdeaPad 5 AMD Ryzen 5','price'=>7290000,'old'=>9590000,'brand'=>'Lenovo',
                     'spec'=>['AMD Ryzen 5 5500U','8GB DDR4 RAM','512GB NVMe SSD','15.6" FHD IPS 300nits','AMD Radeon Graphics'],
                     'desc'=>'Lenovo IdeaPad 5 trang bị AMD Ryzen 5 5500U cho hiệu suất đa nhiệm xuất sắc, giá hợp lý nhất phân khúc. Card đồ họa tích hợp đủ mạnh để xem phim, chỉnh ảnh nhẹ.',
                     'highlights'=>['Ryzen 5 5500U – điểm benchmark cao hơn i5 cùng phân khúc','RAM DDR4 dual-channel, có thể nâng lên 16GB','SSD 512GB – lưu trữ rộng rãi cho mọi nhu cầu','Pin 45Wh – sử dụng liên tục 7-8 tiếng'],
                     'disc'=>24,'img'=>'https://images.unsplash.com/photo-1525547719571-a2d4ac8945e2?w=400&q=80','sold'=>201,'tag'=>'hot','prange'=>'under8','reviews'=>[['name'=>'Hoàng Minh E','star'=>5,'text'=>'Máy chạy cực mượt, giá rẻ mà cấu hình khủng. Highly recommend!'],['name'=>'Vũ Thị F','star'=>4,'text'=>'Màn hình sắc nét, loa to vừa đủ. Bàn phím gõ sướng.']]],

                    ['name'=>'Asus VivoBook 14 i3 Gen 11','price'=>5990000,'old'=>7490000,'brand'=>'Asus',
                     'spec'=>['Intel Core i3-1115G4','4GB DDR4 RAM','256GB NVMe SSD','14" FHD IPS','Intel UHD Graphics'],
                     'desc'=>'Asus VivoBook 14 – lựa chọn hoàn hảo cho học sinh, sinh viên với mức giá dưới 6 triệu. Thiết kế colorful trẻ trung, hiệu năng đủ dùng cho học tập và giải trí nhẹ nhàng.',
                     'highlights'=>['Giá tốt nhất phân khúc dưới 6 triệu','4 màu cá tính: Dreamy White, Indie Black, Transparent Silver, Cobalt Blue','Bàn phím backlit – gõ tối thoải mái','ASUS NumberPad 2.0 tích hợp trên touchpad'],
                     'disc'=>20,'img'=>'https://images.unsplash.com/photo-1611532736597-de2d4265fba3?w=400&q=80','sold'=>317,'tag'=>'sale','prange'=>'under8','reviews'=>[['name'=>'Đặng Văn G','star'=>4,'text'=>'Cho con mua đi học, giá hợp lý lắm. Máy dùng ổn định.'],['name'=>'Ngô Thị H','star'=>5,'text'=>'Mỏng nhẹ, đẹp, pin dùng được cả buổi học.']]],

                    ['name'=>'Acer Aspire 5 i5 Gen 12 FHD','price'=>9990000,'old'=>13990000,'brand'=>'Acer',
                     'spec'=>['Intel Core i5-1235U','8GB DDR4 RAM','512GB NVMe SSD','15.6" FHD IPS 144Hz','Intel Iris Xe Graphics'],
                     'desc'=>'Acer Aspire 5 Gen 12 – hiệu năng vượt trội với màn hình 144Hz mượt mà. Không chỉ mạnh mẽ với công việc văn phòng mà còn đủ để chơi game casual nhờ màn hình tần số cao.',
                     'highlights'=>['Màn hình 144Hz – trải nghiệm cực mượt khi xem phim, chơi game','CPU Intel i5 Gen 12 12 nhân 16 luồng','Hệ thống tản nhiệt DualFan – ổn định nhiệt độ tuyệt vời','Cổng kết nối đầy đủ: USB-C, HDMI 2.0, SD Card'],
                     'disc'=>29,'img'=>'https://images.unsplash.com/photo-1588872657578-7efd1f1555ed?w=400&q=80','sold'=>75,'tag'=>'new','prange'=>'under10','reviews'=>[['name'=>'Trịnh Văn I','star'=>5,'text'=>'Màn hình 144Hz chơi game cực đã, giá sale này quá hời!'],['name'=>'Lý Thị J','star'=>5,'text'=>'Mua về làm đồ họa nhẹ rất ổn. Shop tư vấn rất kỹ.']]],

                    ['name'=>'Asus TUF Gaming F15 RTX 3050','price'=>16490000,'old'=>20000000,'brand'=>'Asus',
                     'spec'=>['Intel Core i5-11400H','8GB DDR4 RAM','512GB NVMe SSD','15.6" FHD 144Hz','NVIDIA RTX 3050 4GB'],
                     'desc'=>'Asus TUF Gaming F15 – laptop gaming phổ thông mạnh nhất tầm 16 triệu! RTX 3050 chơi được hầu hết game AAA ở setting trung cao. Thiết kế quân đội bền bỉ, tản nhiệt xuất sắc.',
                     'highlights'=>['RTX 3050 – chiến mọi game LoL, PUBG, FIFA mượt mà','Màn hình 144Hz – không lag, không xé hình','Bàn phím RGB 4 vùng cực đẹp','Khung máy chuẩn MIL-STD-810H – chịu va đập tốt'],
                     'disc'=>18,'img'=>'https://images.unsplash.com/photo-1603302576837-37561b2e2302?w=400&q=80','sold'=>55,'tag'=>'hot','prange'=>'over10','reviews'=>[['name'=>'Phùng Văn K','star'=>5,'text'=>'Chiến game cực ổn, nhiệt độ mát. Đáng tiền lắm!'],['name'=>'Đinh Thị L','star'=>5,'text'=>'Gaming laptop ngon nhất tầm giá này. Recommend 100%.']]],

                    ['name'=>'Dell Latitude E7490 i7 Gen 8','price'=>6490000,'old'=>12500000,'brand'=>'Dell',
                     'spec'=>['Intel Core i7-8650U','16GB DDR4 RAM','256GB NVMe SSD','14" FHD IPS','Intel UHD 620'],
                     'desc'=>'Dell Latitude E7490 – laptop doanh nhân cao cấp refurbished chính hãng, giảm tới 48%! Cấu hình i7 + 16GB RAM cho đa nhiệm không giới hạn. Thiết kế carbon fiber siêu nhẹ chỉ 1.36kg.',
                     'highlights'=>['i7-8650U + 16GB RAM – đa nhiệm như máy trạm','Vỏ carbon fiber – nhẹ nhất trong phân khúc','Màn hình IPS chống chói FHD siêu sắc nét','Kiểm định kỹ thuật 100% trước khi giao – Bảo hành 6 tháng'],
                     'disc'=>48,'img'=>'https://images.unsplash.com/photo-1541807084-5c52b6b3adef?w=400&q=80','sold'=>428,'tag'=>'sale','prange'=>'under8','reviews'=>[['name'=>'Bùi Văn M','star'=>5,'text'=>'Máy cũ nhưng chất lượng như mới. Ram 16GB mà giá rẻ vậy!'],['name'=>'Nguyễn Thị N','star'=>4,'text'=>'Dùng công việc rất tốt, shop kiểm hàng kỹ, yên tâm.']]],

                    ['name'=>'HP EliteBook 840 G6 i5 Gen 8','price'=>5990000,'old'=>10000000,'brand'=>'HP',
                     'spec'=>['Intel Core i5-8365U','8GB DDR4 RAM','256GB NVMe SSD','14" FHD IPS','HP Sure View màn hình bảo mật'],
                     'desc'=>'HP EliteBook 840 G6 – laptop doanh nhân được tin dùng nhất thế giới với màn hình HP Sure View chống nhìn trộm. Thiết kế aluminum cao cấp, keyboard travel sâu thoải mái.',
                     'highlights'=>['Tính năng HP Sure View – bảo mật thông tin khi làm việc nơi công cộng','Vỏ nhôm CNC cao cấp, sang trọng','RAM 8GB có thể nâng lên 32GB','Pin 50Wh – HP Fast Charge sạc nhanh siêu tốc'],
                     'disc'=>40,'img'=>'https://images.unsplash.com/photo-1484788984921-03950022c9ef?w=400&q=80','sold'=>236,'tag'=>'sale','prange'=>'under8','reviews'=>[['name'=>'Cao Văn O','star'=>5,'text'=>'EliteBook xịn thật, dùng văn phòng cực tốt. Giá sale quá hời.'],['name'=>'Dương Thị P','star'=>4,'text'=>'Máy mỏng, nhẹ, chạy mát, bàn phím gõ sướng tay.']]],

                    ['name'=>'Lenovo ThinkPad X280 i7 Gen 8','price'=>7490000,'old'=>14000000,'brand'=>'Lenovo',
                     'spec'=>['Intel Core i7-8550U','16GB DDR4 RAM','256GB NVMe SSD','12.5" FHD IPS','Intel UHD 620'],
                     'desc'=>'ThinkPad X280 – biểu tượng của laptop doanh nhân Lenovo! Siêu nhẹ chỉ 1.13kg, 16GB RAM mạnh mẽ, bàn phím TrackPoint huyền thoại. Giảm tới 47% – cơ hội hiếm có!',
                     'highlights'=>['Siêu nhẹ 1.13kg – nhẹ nhất trong dòng ThinkPad','16GB RAM – đa nhiệm không giới hạn','Bàn phím TrackPoint huyền thoại – thao tác nhanh chóng','Chuẩn quân sự MIL-SPEC 12 tiêu chí bền bỉ'],
                     'disc'=>47,'img'=>'https://images.unsplash.com/photo-1517336714731-489689fd1ca8?w=400&q=80','sold'=>183,'tag'=>'hot','prange'=>'under8','reviews'=>[['name'=>'Phan Văn Q','star'=>5,'text'=>'ThinkPad đúng nghĩa siêu bền, nhẹ không tưởng. Rất xứng đáng!'],['name'=>'Trần Thị R','star'=>5,'text'=>'Bàn phím gõ sướng nhất tôi từng dùng. Cực kỳ hài lòng.']]],

                    ['name'=>'MacBook Air M1 8GB 256GB','price'=>18990000,'old'=>25000000,'brand'=>'Apple',
                     'spec'=>['Apple M1 8 nhân','8GB RAM Unified','256GB SSD tốc độ cao','13.3" Retina 2560x1600','Apple GPU 7 nhân'],
                     'desc'=>'MacBook Air M1 – cách mạng máy tính cá nhân! Chip Apple M1 mạnh hơn 3.5x so với Intel, pin siêu bền 18 tiếng, không quạt không nóng. Màn hình Retina đẹp nhất phân khúc.',
                     'highlights'=>['Apple M1 – mạnh hơn 3.5x mà tiêu thụ điện ít hơn 5x','Pin 18 tiếng thực tế – cả ngày không cần cắm sạc','Không quạt tản nhiệt – không bao giờ nóng, không tiếng ồn','Màn hình Retina P3 wide color – màu sắc cực chuẩn'],
                     'disc'=>24,'img'=>'https://images.unsplash.com/photo-1611186871348-b1ce696e52c9?w=400&q=80','sold'=>89,'tag'=>'hot','prange'=>'over10','reviews'=>[['name'=>'Lưu Văn S','star'=>5,'text'=>'MacBook M1 xứng đáng 5 sao! Pin dùng được cả ngày, máy mượt không tưởng.'],['name'=>'Mai Thị T','star'=>5,'text'=>'Đầu tư MBM1 không hối hận. Làm đồ họa, lập trình đều siêu tốt.']]],
                ];}
                foreach($show as $p):
                    $price=isset($p['price'])?$p['price']:0;
                    $old=isset($p['old'])&&$p['old']>$price?$p['old']:0;
                    $disc=isset($p['disc'])?$p['disc']:($old>0?round((1-$price/$old)*100):0);
                    $tag=isset($p['tag'])?$p['tag']:'sale';
                    $tag_labels=['sale'=>'Giảm '.$disc.'%','new'=>'Mới về','hot'=>'🔥 Hot'];
                    $tag_cls=['sale'=>'','new'=>'new','hot'=>'hot'];
                    $name=htmlspecialchars($p['name']??'Sản phẩm');
                    $brand=htmlspecialchars($p['brand']??'');
                    $ico=htmlspecialchars($p['ico']??'💻');
                    $sold=isset($p['sold'])?$p['sold']:0;
                    $prange=isset($p['prange'])?$p['prange']:'all';
                    $specs=isset($p['spec'])?$p['spec']:[];
                    $img=isset($p['img'])?htmlspecialchars($p['img']):(isset($p['image'])?htmlspecialchars($p['image']):'');
                    $desc=addslashes(htmlspecialchars($p['desc']??''));
                    $highlights=isset($p['highlights'])?$p['highlights']:[];
                    $reviews=isset($p['reviews'])?$p['reviews']:[];
                ?>
                <div class="pcard" data-range="<?php echo $prange; ?>"
                    data-name="<?php echo addslashes($name); ?>"
                    data-brand="<?php echo addslashes($brand); ?>"
                    data-price="<?php echo number_format($price); ?>"
                    data-old="<?php echo $old?number_format($old):''; ?>"
                    data-disc="<?php echo $disc; ?>"
                    data-ico="<?php echo $ico; ?>"
                    data-img="<?php echo htmlspecialchars($img); ?>"
                    data-desc="<?php echo $desc; ?>"
                    data-highlights='<?php echo json_encode($highlights,JSON_UNESCAPED_UNICODE); ?>'
                    data-reviews='<?php echo json_encode($reviews,JSON_UNESCAPED_UNICODE); ?>'
                    data-specs='<?php echo json_encode($specs,JSON_UNESCAPED_UNICODE); ?>'>
                    <?php if($disc>0): ?><div class="pcard-badge <?php echo $tag_cls[$tag]??''; ?>"><?php echo $tag_labels[$tag]??('Giảm '.$disc.'%'); ?></div><?php endif; ?>
                    <button class="pcard-wish"><i class="far fa-heart"></i></button>
                    <div class="pcard-img">
                        <?php if($img): ?><img src="<?php echo $img;?>" alt="<?php echo $name;?>" style="object-fit:cover;"><?php else: ?><span><?php echo $ico; ?></span><?php endif; ?>
                        <div class="pi-overlay">
                            <button class="pi-btn" title="Xem chi tiết" onclick="openProductModal(this.closest('.pcard'))"><i class="fas fa-eye"></i></button>
                            <button class="pi-btn" title="So sánh"><i class="fas fa-balance-scale"></i></button>
                        </div>
                    </div>
                    <div class="pcard-body">
                        <?php if($brand): ?><div class="pcard-brand"><?php echo $brand; ?></div><?php endif; ?>
                        <div class="pcard-name" style="cursor:pointer" onclick="openProductModal(this.closest('.pcard'))"><?php echo $name; ?></div>
                        <?php if(!empty($specs)): ?>
                        <div class="pcard-spec"><?php foreach(array_slice($specs,0,3) as $s): ?><span><?php echo htmlspecialchars($s); ?></span><?php endforeach; ?></div>
                        <?php endif; ?>
                        <div>
                            <span class="pcard-price"><?php echo number_format($price); ?>đ</span>
                            <?php if($old): ?><span class="pcard-old"><?php echo number_format($old); ?>đ</span><?php endif; ?>
                        </div>
                        <div class="pcard-footer">
                            <div>
                                <div class="pcard-rating"><i class="fas fa-star"></i><?php echo number_format(4.5+rand(0,5)/10,1); ?></div>
                                <?php if($sold): ?><div class="pcard-sold">Đã bán <?php echo $sold; ?></div><?php endif; ?>
                            </div>
                            <button class="pcard-add"><i class="fas fa-cart-plus"></i></button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- PROMO STRIP -->
        <div class="promo-strip">
            <div class="ps-item">
                <div class="ps-icon"><i class="fas fa-shield-alt"></i></div>
                <div><div class="ps-title">Bảo hành lỗi 1 đổi 1</div><div class="ps-sub">12 tháng toàn diện</div></div>
            </div>
            <div class="ps-item">
                <div class="ps-icon"><i class="fas fa-credit-card"></i></div>
                <div><div class="ps-title">Hỗ trợ trả góp 0%</div><div class="ps-sub">Duyệt nhanh trong 15 phút</div></div>
            </div>
            <div class="ps-item">
                <div class="ps-icon"><i class="fas fa-exchange-alt"></i></div>
                <div><div class="ps-title">Thu cũ đổi mới</div><div class="ps-sub">Định giá cao – ưu đãi thêm</div></div>
            </div>
            <div class="ps-item" style="border-right:none;">
                <div class="ps-icon"><i class="fas fa-headset"></i></div>
                <div><div class="ps-title">Hỗ trợ kỹ thuật 24/7</div><div class="ps-sub">Online & tại cửa hàng</div></div>
            </div>
            <button class="ps-cta"><i class="fas fa-phone-alt"></i>Liên hệ ngay</button>
        </div>

        <!-- PC GAMING & VĂN PHÒNG -->
        <div>
            <div class="sec-hdr">
                <div class="sec-title">PC Gaming & Văn phòng</div>
                <a href="?category=pc" class="see-all">Xem tất cả <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="prod-grid">
                <?php
                $pcs=[
                    ['name'=>'PC Gaming Intel Core i5-12400F RTX 3060 Ti','price'=>18990000,'old'=>23000000,'brand'=>'QA Build',
                     'spec'=>['Intel Core i5-12400F 6 nhân','16GB DDR4 3200MHz','512GB NVMe SSD','NVIDIA RTX 3060 Ti 8GB','Case NZXT H510 RGB'],
                     'desc'=>'Bộ PC Gaming do chuyên gia Quang Anh lắp ráp – RTX 3060 Ti chiến Ultra mọi game AAA 2025! Cấu hình i5-12400F + 16GB RAM cho đa nhiệm stream + game cùng lúc không đơ.',
                     'highlights'=>['RTX 3060 Ti chiến 1080p Ultra 100fps+ mọi tựa game','i5-12400F – CPU gaming tầm trung mạnh nhất phân khúc','Đã lắp ráp hoàn chỉnh, test chạy 24h trước khi giao','Bảo hành linh kiện chính hãng 12-36 tháng'],
                     'disc'=>17,'img'=>'https://images.unsplash.com/photo-1587202372775-e229f172b9d7?w=400&q=80','sold'=>43,'tag'=>'hot'],

                    ['name'=>'PC Văn phòng Core i3-12100 8GB SSD 256GB','price'=>5990000,'old'=>7490000,'brand'=>'QA Build',
                     'spec'=>['Intel Core i3-12100 4 nhân 8 luồng','8GB DDR4 3200MHz','256GB NVMe SSD','Intel UHD 730','Case Xigmatek mini'],
                     'desc'=>'PC Văn phòng giá siêu hợp lý cho gia đình, doanh nghiệp! Intel i3-12100 thế hệ 12 mạnh hơn i5 Gen 10 cũ. Phù hợp văn phòng, học online, xem phim, không lag không giật.',
                     'highlights'=>['i3-12100 Gen 12 – mạnh hơn 40% so với i3 Gen 10','8GB RAM – đủ cho đa nhiệm văn phòng, học online','SSD giúp khởi động Windows chỉ 10 giây','Tiêu thụ điện thấp – tiết kiệm điện cho văn phòng'],
                     'disc'=>20,'img'=>'https://images.unsplash.com/photo-1593640495253-23196b27a87f?w=400&q=80','sold'=>188,'tag'=>'sale'],

                    ['name'=>'PC Gaming AMD Ryzen 5 5600X RX 6600','price'=>17490000,'old'=>21000000,'brand'=>'QA Build',
                     'spec'=>['AMD Ryzen 5 5600X 6 nhân 12 luồng','16GB DDR4 3600MHz','512GB NVMe SSD','AMD RX 6600 8GB GDDR6','Case Deepcool Macube RGB'],
                     'desc'=>'PC Gaming AMD đỉnh cao – Ryzen 5 5600X kết hợp RX 6600 tạo nên cỗ máy chiến game tuyệt vời! Hiệu năng cao trong ngân sách hợp lý, phù hợp anh em yêu Team Red.',
                     'highlights'=>['Ryzen 5 5600X – CPU gaming AMD tốt nhất tầm 5 triệu','RX 6600 – chiến 1080p High/Ultra mọi game','RAM 3600MHz được tối ưu cho Ryzen – hiệu năng tối đa','LED RGB hệ thống, case kính trong suốt nhìn cực đẹp'],
                     'disc'=>17,'img'=>'https://images.unsplash.com/photo-1555680202-c86f0e12f086?w=400&q=80','sold'=>67,'tag'=>'hot'],

                    ['name'=>'PC Mini Intel NUC i5 Gen 11 SSD 512GB','price'=>7990000,'old'=>9990000,'brand'=>'Intel NUC',
                     'spec'=>['Intel Core i5-1135G7 4 nhân','8GB LPDDR4X RAM','512GB NVMe SSD','Intel Iris Xe Graphics','11.6x11.2x3.6cm siêu nhỏ'],
                     'desc'=>'Intel NUC – máy tính mini nhỏ bằng quyển sách nhưng mạnh như laptop cao cấp! Đặt được sau màn hình, tiết kiệm hoàn toàn không gian làm việc. Lý tưởng cho quán net, văn phòng.',
                     'highlights'=>['Kích thước siêu nhỏ 11.6x11.2x3.6cm – nhỏ nhất thị trường','Gắn sau màn hình bằng giá VESA – bàn làm việc gọn gàng','Tiêu thụ điện chỉ 15-28W – siêu tiết kiệm','Iris Xe Graphics – đủ mạnh cho đồ họa cơ bản, xem 4K'],
                     'disc'=>20,'img'=>'https://images.unsplash.com/photo-1562976540-1502c2145bab?w=400&q=80','sold'=>91,'tag'=>'new'],

                    ['name'=>'PC Dell OptiPlex 7060 Core i7-8700','price'=>8490000,'old'=>11000000,'brand'=>'Dell',
                     'spec'=>['Intel Core i7-8700 6 nhân 12 luồng','16GB DDR4 RAM','256GB NVMe SSD','Intel UHD 630','Thiết kế Small Form Factor'],
                     'desc'=>'Dell OptiPlex 7060 – máy tính đồng bộ cao cấp từ Dell, chuẩn doanh nghiệp toàn cầu! i7-8700 6 nhân 12 luồng cho hiệu năng đỉnh cao. Nhỏ gọn, yên tĩnh, bền bỉ vô địch.',
                     'highlights'=>['i7-8700 6 nhân 12 luồng – mạnh hơn nhiều i5 thế hệ mới','16GB RAM DDR4 – đa nhiệm thoải mái, render video nhanh','Thiết kế nhỏ gọn SFF – để bàn hoặc gắn sau màn hình','Kiểm định bởi kỹ thuật viên QA – bảo hành 6 tháng'],
                     'disc'=>23,'img'=>'https://images.unsplash.com/photo-1547082299-de196ea013d6?w=400&q=80','sold'=>156,'tag'=>'sale'],

                    ['name'=>'PC Gaming i9-13900K RTX 4090 Siêu Mạnh','price'=>58990000,'old'=>72000000,'brand'=>'QA Ultra Build',
                     'spec'=>['Intel Core i9-13900K 24 nhân','64GB DDR5 6000MHz','2TB NVMe Gen4 SSD','NVIDIA RTX 4090 24GB','360mm AIO Water Cooling'],
                     'desc'=>'PC Gaming đỉnh cao nhất năm 2025! i9-13900K + RTX 4090 – không gì cản nổi! Chiến 4K Ultra mọi game, render video 8K, stream 4K cùng lúc mà không hề lag. Đây là đẳng cấp số 1.',
                     'highlights'=>['RTX 4090 24GB – GPU số 1 thế giới hiện tại','i9-13900K 24 nhân – hiệu suất đa nhân vô song','64GB DDR5 – không bao giờ đủ... hay thừa RAM!','Tản nhiệt nước 360mm – nhiệt độ luôn dưới 70°C full load'],
                     'disc'=>18,'img'=>'https://images.unsplash.com/photo-1593640495253-23196b27a87f?w=400&q=80','sold'=>12,'tag'=>'hot'],
                ];
                foreach($pcs as $p):
                    $disc=$p['disc'];
                    $tag=$p['tag'];
                    $tag_labels=['sale'=>'Giảm '.$disc.'%','new'=>'Mới về','hot'=>'🔥 Hot'];
                    $tag_cls=['sale'=>'','new'=>'new','hot'=>'hot'];
                    $img=isset($p['img'])?htmlspecialchars($p['img']):'';
                    $desc=addslashes(htmlspecialchars($p['desc']??''));
                    $highlights=isset($p['highlights'])?$p['highlights']:[];
                    $reviews=isset($p['reviews'])?$p['reviews']:[];
                ?>
                <div class="pcard"
                    data-name="<?php echo addslashes(htmlspecialchars($p['name'])); ?>"
                    data-brand="<?php echo addslashes(htmlspecialchars($p['brand'])); ?>"
                    data-price="<?php echo number_format($p['price']); ?>"
                    data-old="<?php echo number_format($p['old']); ?>"
                    data-disc="<?php echo $disc; ?>"
                    data-ico="🖥️"
                    data-img="<?php echo $img; ?>"
                    data-desc="<?php echo $desc; ?>"
                    data-highlights='<?php echo json_encode($highlights,JSON_UNESCAPED_UNICODE); ?>'
                    data-reviews='<?php echo json_encode($reviews,JSON_UNESCAPED_UNICODE); ?>'
                    data-specs='<?php echo json_encode($p['spec'],JSON_UNESCAPED_UNICODE); ?>'>
                    <div class="pcard-badge <?php echo $tag_cls[$tag]; ?>"><?php echo $tag_labels[$tag]; ?></div>
                    <button class="pcard-wish"><i class="far fa-heart"></i></button>
                    <div class="pcard-img">
                        <?php if($img): ?><img src="<?php echo $img;?>" alt="<?php echo htmlspecialchars($p['name']);?>" style="object-fit:cover;"><?php else: ?><span>🖥️</span><?php endif; ?>
                        <div class="pi-overlay">
                            <button class="pi-btn" onclick="openProductModal(this.closest('.pcard'))"><i class="fas fa-eye"></i></button>
                            <button class="pi-btn"><i class="fas fa-balance-scale"></i></button>
                        </div>
                    </div>
                    <div class="pcard-body">
                        <div class="pcard-brand"><?php echo htmlspecialchars($p['brand']); ?></div>
                        <div class="pcard-name" style="cursor:pointer" onclick="openProductModal(this.closest('.pcard'))"><?php echo htmlspecialchars($p['name']); ?></div>
                        <div class="pcard-spec"><?php foreach(array_slice($p['spec'],0,3) as $s): ?><span><?php echo htmlspecialchars($s); ?></span><?php endforeach; ?></div>
                        <div>
                            <span class="pcard-price"><?php echo number_format($p['price']); ?>đ</span>
                            <span class="pcard-old"><?php echo number_format($p['old']); ?>đ</span>
                        </div>
                        <div class="pcard-footer">
                            <div><div class="pcard-rating"><i class="fas fa-star"></i><?php echo number_format(4.5+rand(0,5)/10,1); ?></div><div class="pcard-sold">Đã bán <?php echo $p['sold']; ?></div></div>
                            <button class="pcard-add"><i class="fas fa-cart-plus"></i></button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- LINH KIỆN & PHỤ KIỆN -->
        <div>
            <div class="sec-hdr">
                <div class="sec-title">Linh kiện & Phụ kiện</div>
                <a href="?category=linh_kien" class="see-all">Xem tất cả <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="prod-grid">
                <?php
                $accs=[
                    ['name'=>'RAM DDR4 16GB 3200MHz Kingston Fury','price'=>890000,'old'=>1200000,'brand'=>'Kingston',
                     'spec'=>['DDR4-3200','16GB (1x16GB)','CL16','Heatsink đỏ cao cấp','Tương thích Intel & AMD'],
                     'desc'=>'Kingston FURY Beast – RAM gaming phổ thông được ưa chuộng nhất! Tốc độ 3200MHz, tản nhiệt đỏ bắt mắt. Giải pháp nâng cấp tốt nhất để máy tính hết lag giật.',
                     'highlights'=>['3200MHz – tốc độ lý tưởng cho CPU Intel & AMD','Tản nhiệt nhôm CNC giúp nhiệt độ luôn ổn định','Tương thích hầu hết mainboard B450, B550, Z490, Z590','Kiểm định XMP 2.0 – cắm vào chạy ngay không cần chỉnh'],
                     'disc'=>26,'img'=>'https://images.unsplash.com/photo-1591488320449-011701bb6704?w=400&q=80','sold'=>512,'tag'=>'hot'],

                    ['name'=>'SSD Samsung 870 EVO 500GB SATA','price'=>1290000,'old'=>1690000,'brand'=>'Samsung',
                     'spec'=>['SATA III 6Gb/s','500GB dung lượng','Đọc 560MB/s','Ghi 530MB/s','Samsung MKX Controller'],
                     'desc'=>'Samsung 870 EVO – SSD SATA bán chạy số 1 thế giới nhiều năm liền! Nâng cấp từ HDD lên SSD Samsung là cải thiện tốc độ máy tính rõ rệt nhất bạn từng trải nghiệm.',
                     'highlights'=>['Tốc độ đọc 560MB/s – nhanh gấp 5x ổ HDD thông thường','Samsung V-NAND công nghệ điện tử tiên tiến nhất','Bảo hành Samsung chính hãng 5 năm','Phù hợp nâng cấp cho laptop, PC cũ còn khe SATA'],
                     'disc'=>24,'img'=>'https://images.unsplash.com/photo-1597872200969-2b65d56bd16b?w=400&q=80','sold'=>389,'tag'=>'hot'],

                    ['name'=>'SSD NVMe WD Black SN770 1TB Gen4','price'=>1890000,'old'=>2490000,'brand'=>'WD',
                     'spec'=>['NVMe PCIe Gen4 x4','1TB dung lượng','Đọc 5150MB/s','Ghi 4900MB/s','WD Dashboard quản lý'],
                     'desc'=>'WD Black SN770 – SSD NVMe Gen4 siêu nhanh cho gaming và content creation! Tốc độ 5150MB/s load game siêu tốc, không còn cảnh màn hình loading làm gián đoạn trải nghiệm.',
                     'highlights'=>['Gen4 NVMe – nhanh gấp 10x so với SSD SATA','1TB – không gian lưu trữ rộng rãi cho game và file','Công nghệ nCache 4.0 – hiệu suất ghi ổn định','Tương thích PS5, laptop Gen4, PC gaming mới nhất'],
                     'disc'=>24,'img'=>'https://images.unsplash.com/photo-1601737487795-dab272f52420?w=400&q=80','sold'=>241,'tag'=>'sale'],

                    ['name'=>'VGA MSI GeForce RTX 4060 8GB GDDR6','price'=>8990000,'old'=>11000000,'brand'=>'MSI',
                     'spec'=>['NVIDIA RTX 4060','8GB GDDR6 128-bit','DLSS 3.0 + Frame Gen','Ada Lovelace Architecture','Dual Fan TORX 5.0'],
                     'desc'=>'MSI RTX 4060 GAMING X – card đồ họa gaming 1080p tốt nhất năm 2025! DLSS 3 với Frame Generation cho fps tăng gấp đôi. Chiến mọi game AAA với setting cao.',
                     'highlights'=>['RTX 4060 – hiệu năng 1080p tốt nhất tầm 9 triệu','DLSS 3 Frame Generation – FPS tăng 2-4 lần','Ray Tracing thực tế – ánh sáng, bóng đổ siêu đẹp','MSI GAMING X Dual Fan – mát, yên tĩnh tuyệt đối'],
                     'disc'=>18,'img'=>'https://images.unsplash.com/photo-1591488320449-011701bb6704?w=400&q=80','sold'=>78,'tag'=>'new'],

                    ['name'=>'Màn hình LG 27" IPS QHD 165Hz','price'=>5990000,'old'=>7990000,'brand'=>'LG',
                     'spec'=>['27" IPS QHD 2560x1440','165Hz refresh rate','1ms GTG response','HDR400 sRGB 99%','AMD FreeSync Premium'],
                     'desc'=>'LG 27QN600 – màn hình gaming QHD 165Hz cân bằng hoàn hảo giữa hình ảnh đẹp và tốc độ! Độ phân giải 2K sắc nét tuyệt vời, 165Hz mượt mà không xé hình, màu IPS trung thực.',
                     'highlights'=>['QHD 2560x1440 – sắc nét gấp đôi Full HD','165Hz với 1ms response – không lag, không ghosting','sRGB 99% – màu sắc chuẩn cho thiết kế đồ họa','FreeSync Premium – chơi game AMD GPU mượt hơn nữa'],
                     'disc'=>25,'img'=>'https://images.unsplash.com/photo-1593640408182-31c228b748a9?w=400&q=80','sold'=>167,'tag'=>'sale'],

                    ['name'=>'Bàn phím cơ Keychron K2 Pro RGB','price'=>1990000,'old'=>2590000,'brand'=>'Keychron',
                     'spec'=>['75% layout TKL compact','Hot-swap socket thay switch dễ dàng','RGB per-key backlight','Bluetooth 5.1 + USB-C','Gateron G Pro Red switches'],
                     'desc'=>'Keychron K2 Pro – bàn phím cơ cao cấp được lập trình viên và designer yêu thích nhất! Kết nối 3 thiết bị đồng thời qua Bluetooth, hot-swap switch không cần hàn.',
                     'highlights'=>['Hot-swap socket – thay đổi switch theo sở thích dễ dàng','3 chế độ kết nối: USB, Bluetooth thiết bị 1-2-3','RGB per-key 16.8 triệu màu, nhiều hiệu ứng đẹp','QMK/VIA support – lập trình phím tùy ý'],
                     'disc'=>23,'img'=>'https://images.unsplash.com/photo-1587829741301-dc798b83add3?w=400&q=80','sold'=>203,'tag'=>'hot'],
                ];
                foreach($accs as $p):
                    $disc=$p['disc']; $tag=$p['tag'];
                    $tag_labels=['sale'=>'Giảm '.$disc.'%','new'=>'Mới về','hot'=>'🔥 Hot'];
                    $tag_cls=['sale'=>'','new'=>'new','hot'=>'hot'];
                    $img=isset($p['img'])?htmlspecialchars($p['img']):'';
                    $desc=addslashes(htmlspecialchars($p['desc']??''));
                    $highlights=isset($p['highlights'])?$p['highlights']:[];
                    $reviews=isset($p['reviews'])?$p['reviews']:[];
                ?>
                <div class="pcard"
                    data-name="<?php echo addslashes(htmlspecialchars($p['name'])); ?>"
                    data-brand="<?php echo addslashes(htmlspecialchars($p['brand'])); ?>"
                    data-price="<?php echo number_format($p['price']); ?>"
                    data-old="<?php echo number_format($p['old']); ?>"
                    data-disc="<?php echo $disc; ?>"
                    data-ico="⚙️"
                    data-img="<?php echo $img; ?>"
                    data-desc="<?php echo $desc; ?>"
                    data-highlights='<?php echo json_encode($highlights,JSON_UNESCAPED_UNICODE); ?>'
                    data-reviews='<?php echo json_encode($reviews,JSON_UNESCAPED_UNICODE); ?>'
                    data-specs='<?php echo json_encode($p['spec'],JSON_UNESCAPED_UNICODE); ?>'>
                    <div class="pcard-badge <?php echo $tag_cls[$tag]; ?>"><?php echo $tag_labels[$tag]; ?></div>
                    <button class="pcard-wish"><i class="far fa-heart"></i></button>
                    <div class="pcard-img">
                        <?php if($img): ?><img src="<?php echo $img;?>" alt="<?php echo htmlspecialchars($p['name']);?>" style="object-fit:cover;"><?php else: ?><span>⚙️</span><?php endif; ?>
                        <div class="pi-overlay">
                            <button class="pi-btn" onclick="openProductModal(this.closest('.pcard'))"><i class="fas fa-eye"></i></button>
                            <button class="pi-btn"><i class="fas fa-balance-scale"></i></button>
                        </div>
                    </div>
                    <div class="pcard-body">
                        <div class="pcard-brand"><?php echo htmlspecialchars($p['brand']); ?></div>
                        <div class="pcard-name" style="cursor:pointer" onclick="openProductModal(this.closest('.pcard'))"><?php echo htmlspecialchars($p['name']); ?></div>
                        <div class="pcard-spec"><?php foreach(array_slice($p['spec'],0,3) as $s): ?><span><?php echo htmlspecialchars($s); ?></span><?php endforeach; ?></div>
                        <div>
                            <span class="pcard-price"><?php echo number_format($p['price']); ?>đ</span>
                            <span class="pcard-old"><?php echo number_format($p['old']); ?>đ</span>
                        </div>
                        <div class="pcard-footer">
                            <div><div class="pcard-rating"><i class="fas fa-star"></i><?php echo number_format(4.5+rand(0,5)/10,1); ?></div><div class="pcard-sold">Đã bán <?php echo $p['sold']; ?></div></div>
                            <button class="pcard-add"><i class="fas fa-cart-plus"></i></button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- BRAND ROW -->
        <div class="brand-row">
            <div class="sec-title" style="font-size:16px;">Thương hiệu phân phối</div>
            <div class="brand-list">
                <div class="brand-chip on"><span class="brand-ico">💻</span>Dell</div>
                <div class="brand-chip"><span class="brand-ico">💻</span>HP</div>
                <div class="brand-chip"><span class="brand-ico">💻</span>Lenovo</div>
                <div class="brand-chip"><span class="brand-ico">💻</span>Asus</div>
                <div class="brand-chip"><span class="brand-ico">💻</span>Acer</div>
                <div class="brand-chip"><span class="brand-ico">🍎</span>Apple</div>
                <div class="brand-chip"><span class="brand-ico">🎮</span>MSI</div>
                <div class="brand-chip"><span class="brand-ico">⚡</span>Gigabyte</div>
                <div class="brand-chip"><span class="brand-ico">💾</span>Samsung</div>
                <div class="brand-chip"><span class="brand-ico">💾</span>WD</div>
                <div class="brand-chip"><span class="brand-ico">🔩</span>Kingston</div>
                <div class="brand-chip"><span class="brand-ico">🖥️</span>LG</div>
            </div>
        </div>

        <!-- ============================================================
             SẢN PHẨM QUẢNG BÁ TỪ ADMIN (advertising.php)
        ============================================================ -->
        <?php if(!empty($ads_products)): ?>
        <div>
            <div class="sec-hdr">
                <div class="sec-title">🔥 Sản phẩm mới đăng</div>
                <span style="font-size:12px;color:var(--g3);font-weight:700;">Cập nhật mới nhất</span>
            </div>
            <div class="prod-grid">
                <?php foreach($ads_products as $ap):
                    $ap_price = floatval($ap['new_p']);
                    $ap_old   = floatval($ap['old_p']);
                    $ap_disc  = ($ap_old > $ap_price && $ap_old > 0) ? round((1 - $ap_price/$ap_old)*100) : 0;
                    $ap_name  = htmlspecialchars($ap['title'] ?? 'Sản phẩm');
                    $ap_cats  = htmlspecialchars($ap['categories'] ?? '');
                    $ap_date  = !empty($ap['created_at']) ? date('d/m/Y', strtotime($ap['created_at'])) : date('d/m/Y');

                    // --- Lấy ảnh đại diện ---
                    // Ưu tiên 1: cột images_json (nhiều ảnh) — đã được fix path ở trên
                    // Ưu tiên 2: cột image (ảnh đơn) — đã được fix path ở trên
                    // Ưu tiên 3: ảnh đầu tiên trong content TinyMCE — đã được fix path ở trên
                    $ap_all_imgs = [];
                    if (!empty($ap['images_json'])) {
                        $decoded = json_decode($ap['images_json'], true);
                        if (is_array($decoded)) $ap_all_imgs = $decoded;
                    }
                    if (empty($ap_all_imgs) && !empty($ap['image'])) {
                        $ap_all_imgs = [$ap['image']];
                    }
                    if (empty($ap_all_imgs) && !empty($ap['content'])) {
                        preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/', $ap['content'], $img_matches);
                        if (!empty($img_matches[1])) $ap_all_imgs = $img_matches[1];
                        // Đường dẫn từ content đã được fixAdminContentImgs() xử lý sẵn
                    }
                    $ap_thumb = !empty($ap_all_imgs) ? $ap_all_imgs[0] : '';

                    // Nội dung HTML đầy đủ để truyền vào modal (base64 safe)
                    // $ap['content'] đã được fixAdminContentImgs() xử lý ở vòng lặp query
                    // Đảm bảo encoding UTF-8 đúng trước khi base64
                    $ap_content_fixed = $ap['content'] ?? '';
                    // Nếu DB trả về latin1 thay vì utf8, convert lại
                    if (!mb_check_encoding($ap_content_fixed, 'UTF-8')) {
                        $ap_content_fixed = mb_convert_encoding($ap_content_fixed, 'UTF-8', 'Windows-1252');
                    }
                    $ap_content_b64 = base64_encode($ap_content_fixed);
                    // Danh sách ảnh dạng JSON để slider trong modal — dùng double-quote attribute
                    $ap_imgs_json = json_encode($ap_all_imgs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                ?>
                <div class="pcard"
                    data-name="<?php echo addslashes($ap_name); ?>"
                    data-brand="Mới đăng"
                    data-price="<?php echo number_format($ap_price); ?>"
                    data-old="<?php echo $ap_old > 0 ? number_format($ap_old) : ''; ?>"
                    data-disc="<?php echo $ap_disc; ?>"
                    data-ico="💻"
                    data-img="<?php echo htmlspecialchars($ap_thumb); ?>"
                    data-imgs="<?php echo htmlspecialchars($ap_imgs_json, ENT_QUOTES); ?>"
                    data-content-b64="<?php echo $ap_content_b64; ?>"
                    data-desc=""
                    data-highlights='[]'
                    data-reviews='[]'
                    data-specs='<?php echo json_encode(array_values(array_filter([$ap_cats])), JSON_UNESCAPED_UNICODE); ?>'>

                    <?php if($ap_disc > 0): ?>
                        <div class="pcard-badge">Giảm <?php echo $ap_disc; ?>%</div>
                    <?php else: ?>
                        <div class="pcard-badge new">Mới đăng</div>
                    <?php endif; ?>

                    <button class="pcard-wish"><i class="far fa-heart"></i></button>

                    <div class="pcard-img">
                        <?php if($ap_thumb): ?>
                            <img src="<?php echo htmlspecialchars($ap_thumb); ?>"
                                 alt="<?php echo $ap_name; ?>"
                                 style="width:100%;height:100%;object-fit:cover;"
                                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                            <span style="display:none;font-size:60px;width:100%;height:100%;align-items:center;justify-content:center;">💻</span>
                        <?php else: ?>
                            <span style="font-size:60px;">💻</span>
                        <?php endif; ?>
                        <div class="pi-overlay">
                            <button class="pi-btn" onclick="openAdModal(this.closest('.pcard'))">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="pcard-body">
                        <div class="pcard-brand" style="color:var(--acc);">🆕 <?php echo $ap_date; ?></div>
                        <div class="pcard-name" style="cursor:pointer" onclick="openAdModal(this.closest('.pcard'))">
                            <?php echo $ap_name; ?>
                        </div>
                        <?php if($ap_cats): ?>
                        <div class="pcard-spec"><span><?php echo $ap_cats; ?></span></div>
                        <?php endif; ?>
                        <div>
                            <span class="pcard-price"><?php echo number_format($ap_price); ?>đ</span>
                            <?php if($ap_old > 0): ?>
                            <span class="pcard-old"><?php echo number_format($ap_old); ?>đ</span>
                            <?php endif; ?>
                        </div>
                        <div class="pcard-footer">
                            <div class="pcard-rating"><i class="fas fa-star"></i> Nổi bật</div>
                            <button class="pcard-add"><i class="fas fa-cart-plus"></i></button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- TIN TỨC / TIPS -->
        <div>
            <div class="sec-hdr">
                <div class="sec-title">Tin tức & Tư vấn</div>
                <a href="blog.php" class="see-all">Xem thêm <i class="fas fa-arrow-right"></i></a>
            </div>
            <div class="blog-grid">
                <?php
                // Bài viết dạng nội dung (không có giá) từ admin
                $bt_classes = ['bt1','bt2','bt3'];
                $bt_icons   = ['💻','🖥️','🔧'];
                $blog_items = [];

                if(!empty($ads_published)){
                    foreach(array_slice($ads_published, 0, 3) as $i => $ab){
                        $blog_items[] = [
                            'icon'  => $bt_icons[$i % 3],
                            'class' => $bt_classes[$i % 3],
                            'tag'   => !empty($ab['categories']) ? explode(',', $ab['categories'])[0] : 'Tin tức',
                            'title' => htmlspecialchars($ab['title'] ?? ''),
                            'date'  => !empty($ab['created_at']) ? date('d/m/Y', strtotime($ab['created_at'])) : date('d/m/Y'),
                            'content' => $ab['content'] ?? '',
                        ];
                    }
                }

                // Bài tĩnh mặc định nếu chưa có bài admin
                $static_blogs = [
                    ['icon'=>'💻','class'=>'bt1','tag'=>'Tư vấn','title'=>'5 Laptop văn phòng tốt nhất dưới 10 triệu năm 2025','date'=>'12/05/2025','content'=>''],
                    ['icon'=>'🖥️','class'=>'bt2','tag'=>'Build PC','title'=>'Hướng dẫn build PC gaming 20 triệu cấu hình mạnh nhất','date'=>'08/05/2025','content'=>''],
                    ['icon'=>'🔧','class'=>'bt3','tag'=>'Sửa chữa','title'=>'Laptop bị đen màn hình – Nguyên nhân và cách xử lý','date'=>'01/05/2025','content'=>''],
                ];

                // Điền thêm bài tĩnh nếu chưa đủ 3
                while(count($blog_items) < 3){
                    $blog_items[] = array_shift($static_blogs);
                }
                $blog_items = array_slice($blog_items, 0, 3);

                foreach($blog_items as $bi):
                ?>
                <div class="blog-card" <?php if(!empty($bi['content'])): ?>onclick="openBlogModal(this)"
                    data-title="<?php echo htmlspecialchars($bi['title']); ?>"
                    data-content="<?php echo htmlspecialchars($bi['content']); ?>"<?php endif; ?>>
                    <div class="blog-thumb <?php echo $bi['class']; ?>"><?php echo $bi['icon']; ?><span class="blog-tag"><?php echo htmlspecialchars($bi['tag']); ?></span></div>
                    <div class="blog-body">
                        <div class="blog-title"><?php echo $bi['title']; ?></div>
                        <div class="blog-meta"><i class="fas fa-calendar-alt"></i><?php echo $bi['date']; ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

    </main>
</div><!-- /page-body -->

<!-- FOOTER -->
<footer class="site-footer">
    <div class="footer-inner">
        <div class="ft-brand">
            <div class="brand-name">MÁY TÍNH QUANG ANH</div>
            <p>Chuyên mua bán, sửa chữa laptop, máy tính bàn, linh kiện và phụ kiện máy tính tại Hải Phòng. Uy tín – Chất lượng – Giá tốt.</p>
            <div style="margin-top:14px;display:flex;gap:10px;">
                <a href="#" style="width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.8);font-size:15px;"><i class="fab fa-facebook-f"></i></a>
                <a href="#" style="width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.8);font-size:15px;"><i class="fab fa-youtube"></i></a>
                <a href="#" style="width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.8);font-size:15px;"><i class="fab fa-tiktok"></i></a>
            </div>
        </div>
        <div class="ft-col">
            <h4>Sản phẩm</h4>
            <ul>
                <li><a href="#">Laptop văn phòng</a></li>
                <li><a href="#">Laptop gaming</a></li>
                <li><a href="#">Máy tính để bàn</a></li>
                <li><a href="#">PC Gaming</a></li>
                <li><a href="#">Linh kiện máy tính</a></li>
                <li><a href="#">Màn hình</a></li>
            </ul>
        </div>
        <div class="ft-col">
            <h4>Dịch vụ</h4>
            <ul>
                <li><a href="#">Sửa chữa laptop</a></li>
                <li><a href="#">Bảo dưỡng máy tính</a></li>
                <li><a href="#">Nâng cấp RAM/SSD</a></li>
                <li><a href="#">Cài đặt phần mềm</a></li>
                <li><a href="#">Cho thuê laptop</a></li>
                <li><a href="#">Thu cũ đổi mới</a></li>
            </ul>
        </div>
        <div class="ft-col">
            <h4>Liên hệ</h4>
            <ul>
                <li><a href="#"><i class="fas fa-map-marker-alt" style="width:16px"></i> 57 Nguyễn Bình, Hải Phòng</a></li>
                <li><a href="#"><i class="fas fa-phone-alt" style="width:16px"></i> 0787.911.555</a></li>
                <li><a href="#"><i class="fas fa-tools" style="width:16px"></i> 0866.589.959</a></li>
                <li><a href="#"><i class="fas fa-clock" style="width:16px"></i> 8:00 – 21:00 mỗi ngày</a></li>
                <li><a href="#"><i class="fab fa-facebook" style="width:16px"></i> fb.com/maytinhquanganh</a></li>
            </ul>
        </div>
    </div>
    <div class="footer-bottom">
        <span>© 2025 Máy Tính Quang Anh. Bảo lưu mọi quyền.</span>
        <span>Thiết kế bởi QA Tech Team</span>
    </div>
</footer>

<!-- BACK TO TOP -->
<button class="btt" id="bttBtn" onclick="window.scrollTo({top:0,behavior:'smooth'})">
    <i class="fas fa-arrow-up"></i>
</button>

<!-- =====================================================
     FLOATING ACCOUNT PANEL
====================================================== -->
<div class="acc-overlay" id="accOverlay" onclick="overlayClick(event)">
    <div class="acc-panel" id="accPanel">

        <!-- Header -->
        <div class="apnl-hdr">
            <button class="apnl-close" onclick="closePanel()"><i class="fas fa-times"></i></button>
            <div class="apnl-user">
                <div class="apnl-ava"><?php echo $avatar_char; ?></div>
                <div>
                    <div class="apnl-name"><?php echo $uname; ?></div>
                    <div class="apnl-lv"><?php echo $level_icon; ?> Thành viên <?php echo $member_level; ?> &nbsp;•&nbsp; <i class="fas fa-star" style="color:var(--acc2)"></i>&nbsp;<?php echo number_format($loyalty_points); ?> điểm</div>
                </div>
            </div>
            <div class="apnl-stats">
                <div class="apnl-stat"><span class="v"><?php echo $order_count; ?></span><span class="l">Đơn hàng</span></div>
                <div class="apnl-stat"><span class="v"><?php echo $pending_count; ?></span><span class="l">Đang xử lý</span></div>
                <div class="apnl-stat"><span class="v"><?php echo number_format($loyalty_points); ?></span><span class="l">Điểm VIP</span></div>
                <div class="apnl-stat"><span class="v"><?php echo $total_spent>=1000000?number_format($total_spent/1000000,1).'M':number_format($total_spent/1000).'K'; ?>đ</span><span class="l">Chi tiêu</span></div>
            </div>
        </div>

        <!-- Tab bar -->
        <div class="apnl-tabs">
            <button class="apnl-tab on" onclick="swTab('dash',this)"><i class="fas fa-th-large"></i>Tổng quan</button>
            <button class="apnl-tab" onclick="swTab('prof',this)"><i class="fas fa-user"></i>Hồ sơ</button>
            <button class="apnl-tab" onclick="swTab('ords',this)"><i class="fas fa-box"></i>Đơn hàng<?php if($pending_count>0): ?> <span style="background:var(--acc);color:#333;font-size:9px;padding:1px 5px;border-radius:7px;"><?php echo $pending_count;?></span><?php endif;?></button>
            <button class="apnl-tab" onclick="swTab('pts',this)"><i class="fas fa-star"></i>Điểm VIP</button>
            <button class="apnl-tab" onclick="swTab('addr',this)"><i class="fas fa-map-marker-alt"></i>Địa chỉ</button>
        </div>

        <!-- Body -->
        <div class="apnl-body">

            <!-- DASHBOARD -->
            <div id="t-dash">
                <div class="mini-vip">
                    <div class="mvip-row">
                        <span class="mvip-title"><i class="fas fa-trophy" style="color:var(--acc)"></i> Tiến trình VIP</span>
                        <span class="mvip-badge" style="border-color:<?php echo $level_color;?>;color:<?php echo $level_color;?>;background:<?php echo $level_color;?>18;"><?php echo $level_icon;?> <?php echo $member_level;?></span>
                    </div>
                    <div class="vip-bar-track">
                        <div class="vip-bar-fill" id="vipF" style="width:0%" data-t="<?php echo $vip_percent;?>%"></div>
                    </div>
                    <div class="vip-bar-note">Còn <strong><?php echo number_format(max(0,$vip_threshold-$loyalty_points));?> điểm</strong> để lên hạng tiếp theo</div>
                </div>

                <div class="quick-grid">
                    <a href="cart.php" class="qbtn"><div class="qi"><i class="fas fa-shopping-cart"></i></div>Giỏ hàng</a>
                    <button class="qbtn" onclick="swTab('ords',null)"><div class="qi"><i class="fas fa-box-open"></i></div>Đơn hàng</button>
                    <button class="qbtn" onclick="swTab('prof',null)"><div class="qi"><i class="fas fa-user-edit"></i></div>Hồ sơ</button>
                    <button class="qbtn" onclick="swTab('pts',null)"><div class="qi"><i class="fas fa-star"></i></div>Điểm VIP</button>
                    <button class="qbtn" onclick="swTab('addr',null)"><div class="qi"><i class="fas fa-map-marker-alt"></i></div>Địa chỉ</button>
                    <a href="wishlist.php" class="qbtn"><div class="qi"><i class="fas fa-heart"></i></div>Yêu thích</a>
                </div>
                <a href="logout.php" class="logout-row"><i class="fas fa-sign-out-alt"></i> Đăng xuất tài khoản</a>
            </div>

            <!-- PROFILE -->
            <div id="t-prof" style="display:none">
                <?php if($update_msg==='success'): ?><div class="alert-ok"><i class="fas fa-check-circle"></i> Cập nhật thành công!</div><?php endif; ?>
                <div id="pvView">
                    <div class="pv-list">
                        <div class="pv-row"><div class="pv-ico"><i class="fas fa-user"></i></div><div><span class="pv-lbl">Họ và tên</span><span class="pv-val"><?php echo $uname?:'—';?></span></div></div>
                        <div class="pv-row"><div class="pv-ico"><i class="fas fa-envelope"></i></div><div><span class="pv-lbl">Email</span><span class="pv-val"><?php echo $uemail?:'—';?></span></div></div>
                        <div class="pv-row"><div class="pv-ico"><i class="fas fa-phone-alt"></i></div><div><span class="pv-lbl">Số điện thoại</span><span class="pv-val"><?php echo $uphone?:'Chưa cập nhật';?></span></div></div>
                        <div class="pv-row"><div class="pv-ico"><i class="fas fa-calendar-alt"></i></div><div><span class="pv-lbl">Ngày tham gia</span><span class="pv-val"><?php echo $join_date;?></span></div></div>
                        <div class="pv-row"><div class="pv-ico"><i class="fas fa-map-marker-alt"></i></div><div><span class="pv-lbl">Địa chỉ</span><span class="pv-val"><?php echo $uaddress?:'Chưa cập nhật';?></span></div></div>
                        <div class="pv-row"><div class="pv-ico"><i class="fas fa-id-badge"></i></div><div><span class="pv-lbl">ID tài khoản</span><span class="pv-val">#<?php echo str_pad($uid,5,'0',STR_PAD_LEFT);?></span></div></div>
                    </div>
                    <button class="edit-btn" onclick="document.getElementById('pvView').style.display='none';document.getElementById('pvEdit').style.display='block'"><i class="fas fa-pen"></i> Chỉnh sửa thông tin</button>
                </div>
                <div id="pvEdit" style="display:none">
                    <form method="POST" action="?">
                        <input type="hidden" name="action" value="update_profile">
                        <div class="pf-form">
                            <div><label class="pf-lbl">Họ và tên <span style="color:red">*</span></label><input type="text" name="fullname" class="pf-input" value="<?php echo $uname;?>" required></div>
                            <div><label class="pf-lbl">Email</label><input type="email" class="pf-input" value="<?php echo $uemail;?>" readonly></div>
                            <div><label class="pf-lbl">Số điện thoại</label><input type="tel" name="phone" class="pf-input" value="<?php echo $uphone;?>" placeholder="Nhập số điện thoại"></div>
                            <div><label class="pf-lbl">Địa chỉ nhận hàng</label><input type="text" name="address" class="pf-input" value="<?php echo $uaddress;?>" placeholder="Số nhà, đường, phường, quận..."></div>
                            <div class="pf-actions">
                                <button type="submit" class="pf-save"><i class="fas fa-save"></i> Lưu</button>
                                <button type="button" class="pf-cancel" onclick="document.getElementById('pvEdit').style.display='none';document.getElementById('pvView').style.display='block'">Hủy</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ORDERS -->
            <div id="t-ords" style="display:none">
                <?php if(empty($orders)): ?>
                <div class="empty-panel"><div class="ep-ico">📦</div><h3>Chưa có đơn hàng nào</h3><p>Hãy bắt đầu mua sắm!</p><a href="index.php?category=laptop" style="display:inline-flex;align-items:center;gap:6px;margin-top:14px;background:linear-gradient(90deg,var(--g2),var(--g3));color:#fff;padding:10px 22px;border-radius:9px;font-weight:800;font-size:13px;"><i class="fas fa-store"></i>Khám phá</a></div>
                <?php else: ?>
                <div class="ord-list">
                    <?php foreach($orders as $ord):
                        $st=$ord['status']??'pending';
                        $labels=['pending'=>'Chờ xử lý','processing'=>'Đang giao','completed'=>'Hoàn thành','cancelled'=>'Đã hủy'];
                    ?>
                    <div class="ord-item">
                        <div class="ord-top"><span class="ord-id">#<?php echo str_pad($ord['id'],5,'0',STR_PAD_LEFT);?></span><span class="ord-badge status-<?php echo $st;?>"><?php echo $labels[$st]??ucfirst($st);?></span></div>
                        <div class="ord-info"><?php echo isset($ord['created_at'])?date('d/m/Y',strtotime($ord['created_at'])):'N/A';?> • <?php echo htmlspecialchars($ord['product_name']??'Xem chi tiết');?></div>
                        <div class="ord-bot"><span class="ord-total"><?php echo number_format($ord['total_amount']??0);?>đ</span><a href="order_detail.php?id=<?php echo $ord['id'];?>" class="ord-detail">Chi tiết</a></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- POINTS -->
            <div id="t-pts" style="display:none">
                <div class="pts-hero"><div class="pts-num"><?php echo number_format($loyalty_points);?></div><div class="pts-lbl">🎯 Điểm tích lũy của bạn</div></div>
                <div class="lv-grid">
                    <?php
                    $lvls=[['ico'=>'🎖️','name'=>'MEMBER','req'=>'0 điểm','col'=>'#065f46'],['ico'=>'⭐','name'=>'SILVER','req'=>'500 điểm','col'=>'#94a3b8'],['ico'=>'🏆','name'=>'GOLD','req'=>'2.000 điểm','col'=>'#f59e0b'],['ico'=>'💎','name'=>'DIAMOND','req'=>'5.000 điểm','col'=>'#3b82f6']];
                    foreach($lvls as $lv):$cur=($member_level==$lv['name']);?>
                    <div class="lv-card <?php echo $cur?'cur':'';?>">
                        <?php if($cur):?><div class="lv-cur-tag">Hạng bạn</div><?php endif;?>
                        <span class="lv-ico"><?php echo $lv['ico'];?></span>
                        <div class="lv-name" style="color:<?php echo $lv['col'];?>"><?php echo $lv['name'];?></div>
                        <div class="lv-req">Từ <?php echo $lv['req'];?></div>
                    </div>
                    <?php endforeach;?>
                </div>
                <div class="pts-how">
                    <div class="pts-how-title"><i class="fas fa-info-circle" style="color:var(--g3)"></i> Cách tích điểm</div>
                    <ul class="pts-how-list">
                        <li><i class="fas fa-check-circle"></i>Mua hàng: mỗi 1.000đ = 1 điểm</li>
                        <li><i class="fas fa-check-circle"></i>Đánh giá sản phẩm: +10 điểm</li>
                        <li><i class="fas fa-check-circle"></i>Giới thiệu bạn bè: +50 điểm</li>
                        <li><i class="fas fa-check-circle"></i>Sinh nhật: +100 điểm/năm</li>
                    </ul>
                </div>
            </div>

            <!-- ADDRESS -->
            <div id="t-addr" style="display:none">
                <?php if($uaddress): ?>
                <div class="addr-card">
                    <div class="addr-def">Mặc định</div>
                    <div style="display:flex;gap:10px;">
                        <div style="width:38px;height:38px;background:var(--g2);border-radius:9px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px;flex-shrink:0;"><i class="fas fa-home"></i></div>
                        <div><div class="addr-name"><?php echo $uname;?></div><div class="addr-phone"><?php echo $uphone?:'Chưa có SĐT';?></div><div class="addr-txt"><?php echo $uaddress;?></div></div>
                    </div>
                    <button class="addr-edit-btn" onclick="swTab('prof',null);setTimeout(()=>{document.getElementById('pvView').style.display='none';document.getElementById('pvEdit').style.display='block'},100);"><i class="fas fa-pen"></i> Chỉnh sửa địa chỉ</button>
                </div>
                <?php else: ?>
                <div class="empty-panel"><div class="ep-ico">🏠</div><h3>Chưa có địa chỉ</h3><button onclick="swTab('prof',null);setTimeout(()=>{document.getElementById('pvView').style.display='none';document.getElementById('pvEdit').style.display='block'},100);" style="margin-top:14px;background:linear-gradient(90deg,var(--g2),var(--g3));color:#fff;padding:10px 22px;border-radius:9px;font-weight:800;font-size:13px;border:none;cursor:pointer;font-family:inherit;display:flex;align-items:center;gap:7px;margin:14px auto 0;"><i class="fas fa-plus"></i>Thêm địa chỉ</button></div>
                <?php endif; ?>
            </div>

        </div><!-- /apnl-body -->
    </div><!-- /acc-panel -->
</div><!-- /acc-overlay -->

<!-- =====================================================
     MODAL: CHI TIẾT SẢN PHẨM
====================================================== -->
<div class="modal-overlay" id="productModal" onclick="modalOverlayClick(event,'productModal')">
    <div class="modal-box" style="max-width:900px;">
        <div class="modal-hdr">
            <div class="modal-hdr-icon">🛍️</div>
            <div><div class="modal-hdr-title" id="pm-title">Chi tiết sản phẩm</div><div class="modal-hdr-sub" id="pm-brand">Máy Tính Quang Anh – Hải Phòng</div></div>
            <button class="modal-close" onclick="closeModal('productModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" style="padding:0;">

            <!-- TOP: Image + Info -->
            <div style="display:grid;grid-template-columns:280px 1fr;gap:0;">
                <!-- Product image -->
                <div id="pm-img-box" style="background:var(--gll);display:flex;align-items:center;justify-content:center;min-height:280px;border-right:1px solid var(--border2);position:relative;">
                    <span style="font-size:80px;">💻</span>
                    <div id="pm-ribbon" style="position:absolute;top:14px;left:-2px;background:var(--red);color:#fff;font-size:13px;font-weight:900;padding:5px 14px 5px 10px;border-radius:0 6px 6px 0;display:none;"></div>
                </div>
                <!-- Right info -->
                <div style="padding:22px 24px;">
                    <div style="font-size:11px;color:var(--g3);font-weight:800;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">✅ Hàng chính hãng • Còn hàng</div>
                    <div style="font-size:20px;font-weight:900;color:var(--txt);line-height:1.3;margin-bottom:10px;" id="pm-name">—</div>

                    <!-- Price block -->
                    <div style="background:linear-gradient(135deg,var(--gll),#fff);border-radius:12px;padding:14px 16px;margin-bottom:14px;border:1.5px solid var(--border);">
                        <div style="display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;">
                            <span class="pd-price" id="pm-price" style="font-size:30px;">—</span>
                            <span class="pd-old" id="pm-old"></span>
                            <span class="pd-disc" id="pm-disc" style="display:none;font-size:14px;"></span>
                        </div>
                        <div style="font-size:12px;color:var(--muted);margin-top:4px;display:flex;align-items:center;gap:8px;">
                            <i class="fas fa-star" style="color:var(--acc)"></i><strong style="color:var(--txt)">4.8/5</strong>
                            <span>•</span>
                            <i class="fas fa-users" style="color:var(--g3)"></i> <strong>200+</strong> khách hàng đã mua
                        </div>
                    </div>

                    <!-- Highlights – điểm nổi bật như quảng cáo -->
                    <div id="pm-highlights" style="display:flex;flex-direction:column;gap:7px;margin-bottom:14px;"></div>

                    <!-- Warranty badge -->
                    <div class="pd-warranty-note" style="margin-bottom:14px;">
                        <i class="fas fa-shield-alt"></i>
                        Bảo hành 12 tháng • Đổi trả 30 ngày • Giao hàng 2–4 giờ
                        <span onclick="closeModal('productModal');openModal('warrantyModal')" style="margin-left:auto;cursor:pointer;text-decoration:underline;font-weight:800;white-space:nowrap;">Xem chi tiết</span>
                    </div>

                    <!-- CTA buttons -->
                    <div class="pd-actions">
                        <button class="pd-btn-buy" id="pm-btn-buy" onclick="addCurrentProductToCart(true)"><i class="fas fa-bolt"></i> Mua ngay</button>
                        <button class="pd-btn-cart" id="pm-btn-cart" onclick="addCurrentProductToCart(false)"><i class="fas fa-cart-plus"></i> Giỏ hàng</button>
                        <a href="tel:0787911555" class="pd-btn-cart" style="background:var(--g1);color:#fff;border-color:var(--g1);text-decoration:none;">
                            <i class="fas fa-phone-alt"></i>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div style="padding:0 0 0 0;border-top:1px solid var(--border2);">
                <div class="pd-tabs" style="padding:0 24px;margin-bottom:0;">
                    <button class="pd-tab on" onclick="swPdTab('pd-info',this)"><i class="fas fa-list-ul" style="margin-right:5px;"></i>Mô tả & Thông số</button>
                    <button class="pd-tab" onclick="swPdTab('pd-review',this)"><i class="fas fa-star" style="margin-right:5px;color:var(--acc)"></i>Đánh giá khách hàng</button>
                    <button class="pd-tab" onclick="swPdTab('pd-policy',this)"><i class="fas fa-shield-alt" style="margin-right:5px;"></i>Chính sách mua hàng</button>
                </div>

                <!-- Tab: Info -->
                <div class="pd-tabpanel on" id="pd-info" style="padding:20px 24px 24px;">
                    <!-- Description -->
                    <div style="background:linear-gradient(135deg,var(--gll),#fff);border-radius:12px;padding:16px 18px;margin-bottom:18px;border-left:4px solid var(--g3);">
                        <div style="font-size:13px;font-weight:800;color:var(--g1);margin-bottom:6px;display:flex;align-items:center;gap:7px;">
                            <i class="fas fa-quote-left" style="color:var(--g3)"></i> Mô tả sản phẩm
                        </div>
                        <p id="pm-desc-text" style="font-size:13px;color:var(--txt2);line-height:1.8;"></p>
                    </div>
                    <!-- Specs table -->
                    <div style="font-size:13px;font-weight:800;color:var(--g1);margin-bottom:10px;display:flex;align-items:center;gap:7px;">
                        <i class="fas fa-microchip" style="color:var(--g3)"></i> Cấu hình chi tiết
                    </div>
                    <div id="pm-detail-specs"></div>
                    <!-- Specs grid summary -->
                    <div style="margin-top:14px;">
                        <div style="font-size:12px;font-weight:800;color:var(--g1);margin-bottom:8px;"><i class="fas fa-th" style="color:var(--g3);margin-right:5px;"></i>Tóm tắt cấu hình chính</div>
                        <div class="pd-specs" id="pm-specs"></div>
                    </div>
                </div>

                <!-- Tab: Reviews -->
                <div class="pd-tabpanel" id="pd-review" style="padding:20px 24px 24px;">
                    <div style="display:flex;align-items:center;gap:14px;margin-bottom:18px;background:var(--gll);border-radius:12px;padding:16px;">
                        <div style="text-align:center;flex-shrink:0;">
                            <div style="font-size:48px;font-weight:900;color:var(--g2);font-family:'Oswald',sans-serif;line-height:1;">4.8</div>
                            <div class="pd-review-stars"><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star"></i><i class="fas fa-star-half-alt"></i></div>
                            <div style="font-size:11px;color:var(--muted);margin-top:4px;">Đánh giá trung bình</div>
                        </div>
                        <div style="flex:1;display:flex;flex-direction:column;gap:5px;">
                            <?php foreach([5=>75,4=>17,3=>8,2=>0,1=>0] as $s=>$pct): ?>
                            <div style="display:flex;align-items:center;gap:8px;font-size:11px;">
                                <span style="width:14px;color:var(--muted);text-align:right;"><?php echo $s;?></span>
                                <i class="fas fa-star" style="color:var(--acc);font-size:10px;"></i>
                                <div style="flex:1;background:var(--border2);border-radius:4px;height:8px;">
                                    <div style="width:<?php echo $pct;?>%;background:var(--acc);border-radius:4px;height:8px;transition:width .6s;"></div>
                                </div>
                                <span style="width:28px;color:var(--muted);"><?php echo $pct;?>%</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="pd-reviews" id="pm-reviews-list"></div>
                </div>

                <!-- Tab: Policy -->
                <div class="pd-tabpanel" id="pd-policy" style="padding:20px 24px 24px;">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div style="background:var(--gll);border-radius:12px;padding:16px;border:1.5px solid var(--border);">
                            <div style="font-size:28px;margin-bottom:8px;">🛡️</div>
                            <div style="font-size:14px;font-weight:800;color:var(--g2);margin-bottom:5px;">Bảo hành 12 tháng</div>
                            <div style="font-size:12px;color:var(--muted);line-height:1.6;">Lỗi phần cứng được đổi mới trong 30 ngày đầu. Bảo hành 1 đổi 1 nếu lỗi do nhà sản xuất.</div>
                        </div>
                        <div style="background:var(--gll);border-radius:12px;padding:16px;border:1.5px solid var(--border);">
                            <div style="font-size:28px;margin-bottom:8px;">🔄</div>
                            <div style="font-size:14px;font-weight:800;color:var(--g2);margin-bottom:5px;">Đổi trả 30 ngày</div>
                            <div style="font-size:12px;color:var(--muted);line-height:1.6;">Đổi trả trong 30 ngày nếu sản phẩm còn nguyên seal, đủ phụ kiện và hóa đơn mua hàng.</div>
                        </div>
                        <div style="background:var(--gll);border-radius:12px;padding:16px;border:1.5px solid var(--border);">
                            <div style="font-size:28px;margin-bottom:8px;">🚚</div>
                            <div style="font-size:14px;font-weight:800;color:var(--g2);margin-bottom:5px;">Giao hàng nhanh</div>
                            <div style="font-size:12px;color:var(--muted);line-height:1.6;">Nội thành Hải Phòng miễn phí, giao trong 2–4 giờ. Tỉnh thành khác 1–2 ngày làm việc.</div>
                        </div>
                        <div style="background:var(--gll);border-radius:12px;padding:16px;border:1.5px solid var(--border);">
                            <div style="font-size:28px;margin-bottom:8px;">💳</div>
                            <div style="font-size:14px;font-weight:800;color:var(--g2);margin-bottom:5px;">Thanh toán linh hoạt</div>
                            <div style="font-size:12px;color:var(--muted);line-height:1.6;">Tiền mặt, chuyển khoản, trả góp 0% 12 tháng qua thẻ tín dụng. Xuất hóa đơn VAT đầy đủ.</div>
                        </div>
                    </div>
                    <div style="background:linear-gradient(90deg,var(--g1),var(--g2));border-radius:12px;padding:14px 18px;margin-top:14px;display:flex;align-items:center;gap:12px;">
                        <i class="fas fa-headset" style="font-size:24px;color:var(--acc2);"></i>
                        <div style="color:#fff;">
                            <div style="font-size:14px;font-weight:800;">Hỗ trợ kỹ thuật 24/7</div>
                            <div style="font-size:12px;opacity:.8;">Hotline: 0787.911.555 – Kỹ thuật: 0866.589.959</div>
                        </div>
                        <a href="tel:0787911555" style="margin-left:auto;background:var(--acc);color:var(--txt);padding:9px 18px;border-radius:9px;font-weight:800;font-size:13px;display:flex;align-items:center;gap:6px;white-space:nowrap;">
                            <i class="fas fa-phone-alt"></i>Gọi ngay
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- =====================================================
     MODAL: CHÍNH SÁCH BẢO HÀNH
====================================================== -->
<div class="modal-overlay" id="warrantyModal" onclick="modalOverlayClick(event,'warrantyModal')">
    <div class="modal-box">
        <div class="modal-hdr">
            <div class="modal-hdr-icon">🛡️</div>
            <div><div class="modal-hdr-title">Chính sách bảo hành</div><div class="modal-hdr-sub">Cam kết uy tín – Minh bạch – Tận tâm</div></div>
            <button class="modal-close" onclick="closeModal('warrantyModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div class="wty-hero">
                <div class="wty-hero-icon">🏆</div>
                <div>
                    <div class="wty-hero-title">Bảo hành 12 tháng – Đổi mới 30 ngày</div>
                    <div class="wty-hero-sub">Máy Tính Quang Anh cam kết 100% hàng chính hãng, bảo hành đúng cam kết.<br>Khách hàng được hỗ trợ kỹ thuật miễn phí trong suốt thời gian bảo hành.</div>
                </div>
            </div>

            <div class="wty-cards">
                <div class="wty-card">
                    <div class="wty-card-icon">📅</div>
                    <div class="wty-card-title">12 Tháng</div>
                    <div class="wty-card-desc">Bảo hành chính hãng toàn diện cho tất cả sản phẩm</div>
                </div>
                <div class="wty-card">
                    <div class="wty-card-icon">🔄</div>
                    <div class="wty-card-title">Đổi mới 30 ngày</div>
                    <div class="wty-card-desc">Lỗi phần cứng nhà sản xuất được đổi máy mới ngay</div>
                </div>
                <div class="wty-card">
                    <div class="wty-card-icon">⚡</div>
                    <div class="wty-card-title">Xử lý nhanh</div>
                    <div class="wty-card-desc">Tiếp nhận và trả máy trong 24-48 giờ cho các lỗi phổ biến</div>
                </div>
            </div>

            <div class="wty-section">
                <div class="wty-section-title"><i class="fas fa-check-circle"></i>Điều kiện được bảo hành</div>
                <div class="wty-list">
                    <div class="wty-list-item"><i class="fas fa-check-circle"></i>Sản phẩm còn trong thời hạn bảo hành (12 tháng từ ngày mua)</div>
                    <div class="wty-list-item"><i class="fas fa-check-circle"></i>Có tem bảo hành hoặc hóa đơn mua hàng tại Máy Tính Quang Anh</div>
                    <div class="wty-list-item"><i class="fas fa-check-circle"></i>Lỗi do nhà sản xuất: hỏng ổ cứng, lỗi màn hình, bo mạch chủ...</div>
                    <div class="wty-list-item"><i class="fas fa-check-circle"></i>Sản phẩm không bị can thiệp phần cứng bởi bên ngoài</div>
                    <div class="wty-list-item"><i class="fas fa-check-circle"></i>Tem niêm phong còn nguyên vẹn (đối với sản phẩm phụ kiện)</div>
                </div>
            </div>

            <div class="wty-section">
                <div class="wty-section-title"><i class="fas fa-times-circle" style="color:var(--red)"></i>Trường hợp không được bảo hành</div>
                <div class="wty-list">
                    <div class="wty-list-item no"><i class="fas fa-times-circle"></i>Sản phẩm bị vỡ, bể do tác động vật lý (rơi, va đập mạnh)</div>
                    <div class="wty-list-item no"><i class="fas fa-times-circle"></i>Hư hỏng do nước, ẩm ướt, tiếp xúc chất lỏng</div>
                    <div class="wty-list-item no"><i class="fas fa-times-circle"></i>Tự ý tháo máy, thay linh kiện không qua kỹ thuật viên</div>
                    <div class="wty-list-item no"><i class="fas fa-times-circle"></i>Hỏng do virus, phần mềm độc hại hoặc cài đặt sai</div>
                    <div class="wty-list-item no"><i class="fas fa-times-circle"></i>Sản phẩm bị cháy nổ do điện áp không ổn định</div>
                </div>
            </div>

            <div class="wty-section">
                <div class="wty-section-title"><i class="fas fa-list-ol"></i>Quy trình bảo hành</div>
                <div class="wty-process">
                    <div class="wty-step"><div class="wty-step-num">1</div><div class="wty-step-lbl">Liên hệ<br>hotline</div></div>
                    <div class="wty-step"><div class="wty-step-num">2</div><div class="wty-step-lbl">Mang máy<br>đến cửa hàng</div></div>
                    <div class="wty-step"><div class="wty-step-num">3</div><div class="wty-step-lbl">Kiểm tra<br>& xác nhận</div></div>
                    <div class="wty-step"><div class="wty-step-num">4</div><div class="wty-step-lbl">Sửa chữa<br>/ Đổi máy</div></div>
                    <div class="wty-step"><div class="wty-step-num">5</div><div class="wty-step-lbl">Nhận máy<br>đã xử lý</div></div>
                </div>
            </div>

            <div class="wty-section">
                <div class="wty-section-title"><i class="fas fa-gift"></i>Quyền lợi thêm cho khách hàng thân thiết</div>
                <div class="wty-list">
                    <div class="wty-list-item"><i class="fas fa-check-circle"></i>Khách hàng hạng SILVER trở lên: giảm 10% phí sửa chữa ngoài bảo hành</div>
                    <div class="wty-list-item"><i class="fas fa-check-circle"></i>Khách hàng hạng GOLD: kiểm tra máy định kỳ miễn phí 6 tháng/lần</div>
                    <div class="wty-list-item"><i class="fas fa-check-circle"></i>Khách hàng hạng DIAMOND: bảo hành mở rộng thêm 6 tháng, ưu tiên xử lý</div>
                    <div class="wty-list-item"><i class="fas fa-check-circle"></i>Miễn phí cài đặt phần mềm cơ bản trong suốt thời gian bảo hành</div>
                </div>
            </div>

            <div class="wty-contact-bar">
                <span><i class="fas fa-phone-alt" style="color:var(--acc2);margin-right:6px;"></i>Hotline bảo hành: <strong>0787.911.555</strong></span>
                <a href="tel:0787911555"><i class="fas fa-phone-volume"></i>Gọi ngay</a>
            </div>
        </div>
    </div>
</div>

<!-- =====================================================
     MODAL: DỊCH VỤ SỬA CHỮA
====================================================== -->
<div class="modal-overlay" id="repairModal" onclick="modalOverlayClick(event,'repairModal')">
    <div class="modal-box" style="max-width:860px;">
        <div class="modal-hdr">
            <div class="modal-hdr-icon">🔧</div>
            <div><div class="modal-hdr-title">Dịch vụ sửa chữa</div><div class="modal-hdr-sub">Nhanh – Uy tín – Giá tốt nhất Hải Phòng</div></div>
            <button class="modal-close" onclick="closeModal('repairModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body">
            <div class="rep-banner">
                <div class="rep-banner-icon">🛠️</div>
                <div>
                    <div class="rep-banner-title">Sửa chữa laptop – PC – Linh kiện</div>
                    <div class="rep-banner-sub">Lấy ngay trong ngày · Kỹ thuật viên có kinh nghiệm 10 năm · Cam kết giá rẻ nhất</div>
                </div>
            </div>

            <div class="rep-promise">
                <div class="rep-prom-item"><div class="rep-prom-icon">⚡</div><div class="rep-prom-txt">Lấy ngay<br>trong ngày</div></div>
                <div class="rep-prom-item"><div class="rep-prom-icon">💰</div><div class="rep-prom-txt">Báo giá<br>miễn phí</div></div>
                <div class="rep-prom-item"><div class="rep-prom-icon">🔒</div><div class="rep-prom-txt">Bảo hành<br>sau sửa 3-6 tháng</div></div>
                <div class="rep-prom-item"><div class="rep-prom-icon">📞</div><div class="rep-prom-txt">Hỗ trợ<br>24/7</div></div>
            </div>

            <div class="rep-section-title"><i class="fas fa-tools"></i>Bảng giá dịch vụ</div>
            <div class="rep-services">
                <div class="rep-svc">
                    <div class="rep-svc-head"><div class="rep-svc-icon"><i class="fas fa-desktop"></i></div><div class="rep-svc-name">Cài đặt hệ thống</div></div>
                    <div class="rep-svc-price">Từ 50.000đ</div>
                    <ul class="rep-svc-items">
                        <li>Cài Windows 10/11 từ 50k</li>
                        <li>Cài Office, phần mềm từ 30k</li>
                        <li>Vệ sinh, dọn dẹp máy từ 50k</li>
                        <li>Diệt virus, malware từ 80k</li>
                    </ul>
                </div>
                <div class="rep-svc">
                    <div class="rep-svc-head"><div class="rep-svc-icon"><i class="fas fa-power-off"></i></div><div class="rep-svc-name">Sửa không lên nguồn</div></div>
                    <div class="rep-svc-price">Từ 300.000đ</div>
                    <ul class="rep-svc-items">
                        <li>Sửa IC nguồn, cầu chì từ 300k</li>
                        <li>Sửa bo mạch chủ từ 500k</li>
                        <li>Thay pin laptop từ 400k</li>
                        <li>Thay adapter chính hãng từ 250k</li>
                    </ul>
                </div>
                <div class="rep-svc">
                    <div class="rep-svc-head"><div class="rep-svc-icon"><i class="fas fa-tv"></i></div><div class="rep-svc-name">Màn hình & Bàn phím</div></div>
                    <div class="rep-svc-price">Từ 299.000đ</div>
                    <ul class="rep-svc-items">
                        <li>Thay màn hình laptop từ 800k</li>
                        <li>Thay bàn phím từ 299k</li>
                        <li>Sửa bản lề màn hình từ 200k</li>
                        <li>Thay vỏ máy từ 350k</li>
                    </ul>
                </div>
                <div class="rep-svc">
                    <div class="rep-svc-head"><div class="rep-svc-icon"><i class="fas fa-memory"></i></div><div class="rep-svc-name">Nâng cấp phần cứng</div></div>
                    <div class="rep-svc-price">Từ 100.000đ</div>
                    <ul class="rep-svc-items">
                        <li>Nâng cấp RAM (phí lắp từ 100k)</li>
                        <li>Thay SSD / HDD (phí lắp từ 150k)</li>
                        <li>Vệ sinh tản nhiệt từ 150k</li>
                        <li>Thay keo tản nhiệt từ 80k</li>
                    </ul>
                </div>
            </div>

            <div class="rep-section-title" style="margin-top:20px;"><i class="fas fa-calendar-check"></i>Đặt lịch sửa chữa</div>
            <div class="rep-form">
                <input type="text" class="rep-input" placeholder="Họ và tên *" value="<?php echo $uname;?>">
                <input type="tel" class="rep-input" placeholder="Số điện thoại *" value="<?php echo $uphone;?>">
                <select class="rep-input">
                    <option value="">-- Chọn loại dịch vụ --</option>
                    <option>Cài đặt hệ thống / phần mềm</option>
                    <option>Sửa không lên nguồn</option>
                    <option>Thay màn hình / bàn phím</option>
                    <option>Nâng cấp RAM / SSD</option>
                    <option>Sửa bản lề / vỏ máy</option>
                    <option>Vệ sinh máy tính</option>
                    <option>Sửa chữa khác</option>
                </select>
                <input type="datetime-local" class="rep-input">
                <textarea class="rep-input full" rows="3" placeholder="Mô tả sự cố (tuỳ chọn)..."></textarea>
                <div class="rep-note"><i class="fas fa-info-circle"></i>Kỹ thuật viên sẽ gọi xác nhận lịch trong 30 phút. Hoặc đến trực tiếp tại 57 Nguyễn Bình, Lê Chân, Hải Phòng.</div>
                <button class="rep-submit" onclick="alert('Đặt lịch thành công! Chúng tôi sẽ liên hệ lại trong 30 phút.')"><i class="fas fa-calendar-plus"></i>Đặt lịch ngay</button>
            </div>
        </div>
    </div>
</div>

<script>
// ===== Panel =====
function openPanel(){document.getElementById('accOverlay').classList.add('open');document.body.style.overflow='hidden';setTimeout(()=>{const f=document.getElementById('vipF');if(f)f.style.width=f.dataset.t;},400);}
function closePanel(){document.getElementById('accOverlay').classList.remove('open');document.body.style.overflow='';}
function overlayClick(e){if(e.target===document.getElementById('accOverlay'))closePanel();}

// ===== Tabs in panel =====
function swTab(id,btn){
    ['dash','prof','ords','pts','addr'].forEach(t=>document.getElementById('t-'+t).style.display='none');
    document.getElementById('t-'+id).style.display='block';
    document.querySelectorAll('.apnl-tab').forEach(b=>b.classList.remove('on'));
    if(btn)btn.classList.add('on');
    else document.querySelectorAll('.apnl-tab').forEach(b=>{if(b.getAttribute('onclick')&&b.getAttribute('onclick').includes("'"+id+"'"))b.classList.add('on');});
}

// ===== Hero slider =====
let curSlide=0,slideTimer;
const slides=document.querySelectorAll('.hero-slide');
const dots=document.querySelectorAll('.sdot');
function goSlide(n){
    slides[curSlide].classList.remove('active');
    dots[curSlide].classList.remove('active');
    curSlide=n;
    slides[curSlide].classList.add('active');
    dots[curSlide].classList.add('active');
    clearInterval(slideTimer);
    slideTimer=setInterval(()=>goSlide((curSlide+1)%slides.length),5000);
}
slideTimer=setInterval(()=>goSlide((curSlide+1)%slides.length),5000);

// ===== Countdown =====
function startCountdown(h,m,s){
    setInterval(()=>{
        s--;if(s<0){s=59;m--;}if(m<0){m=59;h--;}if(h<0){h=0;m=0;s=0;}
        document.getElementById('cdH').textContent=String(h).padStart(2,'0');
        document.getElementById('cdM').textContent=String(m).padStart(2,'0');
        document.getElementById('cdS').textContent=String(s).padStart(2,'0');
    },1000);
}
startCountdown(5,32,0);

// ===== Filter laptops =====
function filterSec(btn,gridId,range){
    btn.closest('.sec-filters').querySelectorAll('.sf').forEach(b=>b.classList.remove('on'));
    btn.classList.add('on');
    document.querySelectorAll('#'+gridId+' .pcard').forEach(c=>{
        c.style.display=(range==='all'||c.dataset.range===range)?'':'none';
    });
}

// ===== Brand chips =====
document.querySelectorAll('.brand-chip').forEach(c=>{
    c.addEventListener('click',function(){
        document.querySelectorAll('.brand-chip').forEach(x=>x.classList.remove('on'));
        this.classList.add('on');
    });
});

// ===== Wishlist toggle =====
document.querySelectorAll('.pcard-wish').forEach(b=>{
    b.addEventListener('click',function(e){
        e.stopPropagation();
        const ico=this.querySelector('i');
        ico.classList.toggle('far');ico.classList.toggle('fas');
        this.style.color=ico.classList.contains('fas')?'#ef4444':'';
    });
});

// ===== Back to top =====
window.addEventListener('scroll',()=>{
    document.getElementById('bttBtn').classList.toggle('show',window.scrollY>400);
});

// ===== Auto open panel after profile update =====
<?php if($update_msg==='success'): ?>
window.addEventListener('load',()=>{openPanel();swTab('prof',null);});
<?php endif; ?>

// ===== MODAL FUNCTIONS =====
function openModal(id){
    document.getElementById(id).classList.add('open');
    document.body.style.overflow='hidden';
}
function closeModal(id){
    document.getElementById(id).classList.remove('open');
    document.body.style.overflow='';
}
function modalOverlayClick(e,id){
    if(e.target===document.getElementById(id)) closeModal(id);
}

// ===== CART: Biến lưu sản phẩm đang xem trong modal =====
let _currentProduct = { name:'', price:0, image:'' };

/* Gọi add_to_cart.php qua AJAX */
function _doAddToCart(name, price, image, qty, onSuccess) {
    const body = new URLSearchParams({
        action: 'add',
        name:   name,
        price:  String(price),
        image:  image || '',
        qty:    String(qty || 1)
    });
    fetch('add_to_cart.php', { method:'POST', body })
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                /* Cập nhật badge giỏ hàng trên header */
                document.querySelectorAll('.bdg').forEach(b => b.textContent = d.count);
                showCartToast(d.msg || 'Đã thêm vào giỏ hàng!');
                if (onSuccess) onSuccess(d);
            } else {
                alert('❌ ' + (d.msg || 'Có lỗi xảy ra!'));
            }
        })
        .catch(() => alert('❌ Không kết nối được server!'));
}

/* Toast thông báo nhỏ */
function showCartToast(msg) {
    let t = document.getElementById('cartToast');
    if (!t) {
        t = document.createElement('div');
        t.id = 'cartToast';
        t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#047857;color:#fff;padding:12px 20px;border-radius:12px;font-size:13px;font-weight:700;z-index:9999;box-shadow:0 8px 24px rgba(6,95,70,.4);transition:.3s;opacity:0;transform:translateY(20px);display:flex;align-items:center;gap:8px;';
        document.body.appendChild(t);
    }
    t.innerHTML = '<i class="fas fa-check-circle"></i>' + msg;
    t.style.opacity = '1'; t.style.transform = 'translateY(0)';
    clearTimeout(t._timer);
    t._timer = setTimeout(() => { t.style.opacity='0'; t.style.transform='translateY(20px)'; }, 2800);
}

/* Thêm sản phẩm từ product modal (DB products hoặc static array) */
function addCurrentProductToCart(buyNow) {
    const p = _currentProduct;
    if (!p.name || !p.price) { alert('Không lấy được thông tin sản phẩm!'); return; }
    _doAddToCart(p.name, p.price, p.image, 1, (d) => {
        if (buyNow) { closeModal('productModal'); window.location.href = 'cart.php'; }
    });
}

/* Thêm sản phẩm từ ad modal (sản phẩm admin) */
function addAdProductToCart() {
    const name  = document.getElementById('adm-title')?.textContent?.trim() || '';
    const priceText = document.getElementById('adm-price')?.textContent || '0';
    const price = parseInt(priceText.replace(/[^\d]/g, '')) || 0;
    const imgEl = document.querySelector('#adm-slider img');
    const image = imgEl ? imgEl.src : '';
    if (!name || !price) { alert('Không lấy được thông tin sản phẩm!'); return; }
    _doAddToCart(name, price, image, 1, null);
}

/* Gắn sự kiện cho nút .pcard-add (nút giỏ hàng nhỏ trên card) */
document.querySelectorAll('.pcard-add').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        const card  = this.closest('.pcard');
        const name  = card.dataset.name  || '';
        const priceStr = card.dataset.price || '0';
        const price = parseInt(priceStr.replace(/[^\d]/g, '')) || 0;
        const image = card.dataset.img   || '';
        if (!name || !price) { alert('Không lấy được thông tin sản phẩm!'); return; }
        _doAddToCart(name, price, image, 1, null);
    });
});
function openProductModal(card){
    const d = card.dataset;
    const name = d.name || 'Sản phẩm';
    const brand = d.brand || '';
    const price = d.price || '—';
    const old = d.old || '';
    const disc = parseInt(d.disc)||0;
    const ico = d.ico || '💻';
    const img = d.img || '';
    const desc = d.desc || '';
    let specs = [];
    try { specs = JSON.parse(d.specs||'[]'); } catch(e){}
    let highlights = [];
    try { highlights = JSON.parse(d.highlights||'[]'); } catch(e){}
    let reviews = [];
    try { reviews = JSON.parse(d.reviews||'[]'); } catch(e){}

    /* Lưu sản phẩm hiện tại để nút Giỏ hàng / Mua ngay sử dụng */
    _currentProduct = {
        name:  name,
        price: parseInt((price||'0').replace(/[^\d]/g,'')) || 0,
        image: img
    };

    document.getElementById('pm-title').textContent = name;
    document.getElementById('pm-brand').textContent = brand;
    document.getElementById('pm-name').textContent = name;
    document.getElementById('pm-price').textContent = price + 'đ';

    // Product image
    const imgBox = document.getElementById('pm-img-box');
    if(img){
        imgBox.innerHTML = `<img src="${img}" alt="${name}" style="width:100%;height:100%;object-fit:cover;border-radius:12px;">`;
    } else {
        imgBox.innerHTML = `<span style="font-size:80px;">${ico}</span>`;
    }

    const oldEl = document.getElementById('pm-old');
    const discEl = document.getElementById('pm-disc');
    if(old){ oldEl.textContent = old + 'đ'; oldEl.style.display=''; } else { oldEl.textContent=''; oldEl.style.display='none'; }
    if(disc>0){
        discEl.textContent='-'+disc+'%'; discEl.style.display='';
        const ribbon=document.getElementById('pm-ribbon');
        if(ribbon){ribbon.textContent='-'+disc+'%';ribbon.style.display='';}
    } else {
        discEl.style.display='none';
        const ribbon=document.getElementById('pm-ribbon');
        if(ribbon)ribbon.style.display='none';
    }

    // Description
    document.getElementById('pm-desc-text').textContent = desc;

    // Highlights (điểm nổi bật – quảng cáo)
    const hlBox = document.getElementById('pm-highlights');
    if(highlights.length){
        hlBox.innerHTML = highlights.map(h=>`
            <div class="pd-highlight">
                <i class="fas fa-check-circle" style="color:var(--g3);font-size:15px;flex-shrink:0;margin-top:2px;"></i>
                <span>${h}</span>
            </div>`).join('');
        hlBox.style.display='';
    } else { hlBox.style.display='none'; }

    // Specs grid
    const specLabels = ['CPU','RAM','Bộ nhớ','Card đồ họa','Màn hình','Kết nối'];
    const specGrid = document.getElementById('pm-specs');
    specGrid.innerHTML = specs.map((s,i)=>`
        <div class="pd-spec-row">
            <span class="pd-spec-lbl">${specLabels[i]||'Thông số '+(i+1)}</span>
            <span class="pd-spec-val">${s}</span>
        </div>`).join('');

    // Detail specs tab
    document.getElementById('pm-detail-specs').innerHTML = specs.length ? `
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
            ${specs.map((s,i)=>`<tr style="border-bottom:1px solid var(--border2);">
                <td style="padding:9px 12px;color:var(--muted);font-weight:600;width:38%;background:var(--bg2);">${specLabels[i]||'Thông số '+(i+1)}</td>
                <td style="padding:9px 12px;font-weight:700;color:var(--txt);">${s}</td>
            </tr>`).join('')}
        </table>` : '<p style="color:var(--muted);font-size:13px;">Chưa có thông số chi tiết.</p>';

    // Reviews
    const revBox = document.getElementById('pm-reviews-list');
    if(reviews.length){
        revBox.innerHTML = reviews.map(r=>`
            <div class="pd-review-item">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:5px;">
                    <div style="width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,var(--g2),var(--g4));display:flex;align-items:center;justify-content:center;color:#fff;font-weight:800;font-size:13px;">
                        ${r.name?r.name.charAt(0).toUpperCase():'?'}
                    </div>
                    <div>
                        <span class="pd-review-name">${r.name||'Khách hàng'}</span>
                        <div class="pd-review-stars" style="font-size:12px;margin-top:1px;">${'<i class="fas fa-star" style="color:var(--acc)"></i>'.repeat(r.star||5)}</div>
                    </div>
                    <span class="pd-review-date" style="margin-left:auto;">Đã mua</span>
                </div>
                <p class="pd-review-text">${r.text||''}</p>
            </div>`).join('');
    } else {
        revBox.innerHTML = '<p style="color:var(--muted);font-size:13px;text-align:center;padding:20px 0;">Chưa có đánh giá. Hãy là người đầu tiên đánh giá sản phẩm này!</p>';
    }

    // Reset tabs
    swPdTab('pd-info', document.querySelector('.pd-tab'));
    openModal('productModal');
}

function swPdTab(id, btn){
    document.querySelectorAll('.pd-tabpanel').forEach(p=>p.classList.remove('on'));
    document.querySelectorAll('.pd-tab').forEach(b=>b.classList.remove('on'));
    document.getElementById(id).classList.add('on');
    if(btn) btn.classList.add('on');
}

// ===== BLOG MODAL (bài viết từ admin) =====
function openBlogModal(card){
    const title   = card.dataset.title   || '';
    const content = card.dataset.content || '';
    document.getElementById('blogModal-title').textContent   = title;
    document.getElementById('blogModal-content').innerHTML   = content;
    openModal('blogModal');
}

// ===== AD DETAIL MODAL (sản phẩm từ admin — ảnh + nội dung đầy đủ) =====
function openAdModal(card){
    const d    = card.dataset;
    const name = d.name  || 'Sản phẩm';
    const price= d.price || '—';
    const old  = d.old   || '';
    const disc = parseInt(d.disc)||0;

    // Lấy danh sách ảnh
    let imgs = [];
    try { imgs = JSON.parse(d.imgs || '[]'); } catch(e){}
    if(!imgs.length && d.img) imgs = [d.img];
    // Lọc bỏ ảnh rỗng
    imgs = imgs.filter(s => s && s.trim() !== '');

    // Giải mã nội dung base64 từ TinyMCE — hỗ trợ UTF-8 đầy đủ
    let htmlContent = '';
    try {
        const bytes = Uint8Array.from(atob(d.contentB64 || ''), c => c.charCodeAt(0));
        htmlContent = new TextDecoder('utf-8').decode(bytes);
    } catch(e) {
        try { htmlContent = atob(d.contentB64 || ''); } catch(e2) { htmlContent = ''; }
    }
    // Dọn sạch \r\n và \\r\\n literal còn sót (phòng thủ phía JS)
    htmlContent = htmlContent
        .replace(/\\r\\n/g, '')
        .replace(/\r\n/g, '<br>')
        .replace(/\\\\r\\\\n/g, '');

    // --- Render modal ---
    document.getElementById('adm-title').textContent = name;
    const nameR = document.getElementById('adm-name-r');
    if(nameR) nameR.textContent = name;
    document.getElementById('adm-price').textContent = price + 'đ';

    const oldEl = document.getElementById('adm-old');
    const discEl= document.getElementById('adm-disc');
    oldEl.textContent  = old ? old+'đ' : '';
    oldEl.style.display= old ? '' : 'none';
    discEl.textContent = disc>0 ? '-'+disc+'%' : '';
    discEl.style.display = disc>0 ? '' : 'none';

    // Slider ảnh
    const sliderEl = document.getElementById('adm-slider');
    window._admIdx  = 0;
    window._admImgs = imgs;
    if(imgs.length){
        sliderEl.innerHTML = imgs.map((src,i)=>`
            <div class="adm-slide${i===0?' active':''}" onclick="admZoom('${src.replace(/'/g,"\\'")}')">
                <img src="${src}"
                     alt="${name.replace(/"/g,'')}"
                     style="max-width:100%;max-height:320px;object-fit:contain;border-radius:10px;cursor:zoom-in;"
                     onerror="this.style.display='none';this.parentElement.querySelector('.adm-img-err').style.display='flex';">
                <div class="adm-img-err" style="display:none;font-size:60px;width:100%;min-height:200px;align-items:center;justify-content:center;">💻</div>
            </div>`).join('');
        document.getElementById('adm-dots').innerHTML = imgs.length>1
            ? imgs.map((_,i)=>`<span class="adm-dot${i===0?' on':''}" onclick="admGoSlide(${i})"></span>`).join('')
            : '';
        document.getElementById('adm-prev').style.display = imgs.length>1?'':'none';
        document.getElementById('adm-next').style.display = imgs.length>1?'':'none';
    } else {
        sliderEl.innerHTML = '<div style="font-size:80px;text-align:center;padding:40px;width:100%;">💻</div>';
        document.getElementById('adm-dots').innerHTML='';
        document.getElementById('adm-prev').style.display='none';
        document.getElementById('adm-next').style.display='none';
    }

    // Nội dung HTML đầy đủ
    document.getElementById('adm-content').innerHTML = htmlContent ||
        '<p style="color:var(--muted);text-align:center;padding:20px;">Chưa có mô tả chi tiết.</p>';

    openModal('adModal');
}

let _admIdx = 0;
function admGoSlide(n){
    const slides = document.querySelectorAll('.adm-slide');
    const dots   = document.querySelectorAll('.adm-dot');
    if(!slides.length) return;
    slides[_admIdx].classList.remove('active');
    if(dots[_admIdx]) dots[_admIdx].classList.remove('on');
    _admIdx = (n + slides.length) % slides.length;
    slides[_admIdx].classList.add('active');
    if(dots[_admIdx]) dots[_admIdx].classList.add('on');
}
function admZoom(src){
    const ov = document.createElement('div');
    ov.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:9999;display:flex;align-items:center;justify-content:center;cursor:zoom-out;';
    ov.innerHTML=`<img src="${src}" style="max-width:92vw;max-height:92vh;object-fit:contain;border-radius:8px;">`;
    ov.onclick=()=>ov.remove();
    document.body.appendChild(ov);
}

// Esc key closes modals
document.addEventListener('keydown',e=>{
    if(e.key==='Escape'){
        ['productModal','warrantyModal','repairModal','blogModal','adModal'].forEach(id=>{
            const el=document.getElementById(id);
            if(el) el.classList.remove('open');
        });
        document.body.style.overflow='';
    }
});
</script>

<!-- =====================================================
     MODAL: BÀI VIẾT TIN TỨC (từ admin/advertising.php)
====================================================== -->
<div class="modal-overlay" id="blogModal" onclick="modalOverlayClick(event,'blogModal')">
    <div class="modal-box" style="max-width:800px;">
        <div class="modal-hdr">
            <div class="modal-hdr-icon">📰</div>
            <div><div class="modal-hdr-title" id="blogModal-title">Bài viết</div><div class="modal-hdr-sub">Máy Tính Quang Anh – Hải Phòng</div></div>
            <button class="modal-close" onclick="closeModal('blogModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" id="blogModal-content" style="font-size:14px;line-height:1.8;color:var(--txt2);">
        </div>
    </div>
</div>

<!-- =====================================================
     MODAL: CHI TIẾT SẢN PHẨM ADMIN (ảnh slider + nội dung đầy đủ)
====================================================== -->
<div class="modal-overlay" id="adModal" onclick="modalOverlayClick(event,'adModal')">
    <div class="modal-box" style="max-width:1100px;max-height:94vh;">
        <div class="modal-hdr">
            <div class="modal-hdr-icon">🛍️</div>
            <div>
                <div class="modal-hdr-title" id="adm-title">Chi tiết sản phẩm</div>
                <div class="modal-hdr-sub">Máy Tính Quang Anh – Hải Phòng</div>
            </div>
            <button class="modal-close" onclick="closeModal('adModal')"><i class="fas fa-times"></i></button>
        </div>
        <div class="modal-body" style="padding:0;overflow-y:auto;flex:1;">

            <!-- Layout 2 cột: slider trái, info phải -->
            <div style="display:grid;grid-template-columns:1.1fr 1fr;gap:0;min-height:420px;">

                <!-- CỘT TRÁI: Image slider -->
                <div style="background:var(--bg);border-right:1px solid var(--border2);padding:20px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;position:relative;">
                    <!-- Slides -->
                    <div id="adm-slider" style="width:100%;display:flex;align-items:center;justify-content:center;min-height:360px;"></div>
                    <!-- Prev/Next -->
                    <button id="adm-prev" onclick="admGoSlide(_admIdx-1)"
                        style="position:absolute;left:10px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.95);border:none;border-radius:50%;width:40px;height:40px;font-size:20px;cursor:pointer;box-shadow:0 3px 12px rgba(0,0,0,.18);transition:.2s;">&#8249;</button>
                    <button id="adm-next" onclick="admGoSlide(_admIdx+1)"
                        style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.95);border:none;border-radius:50%;width:40px;height:40px;font-size:20px;cursor:pointer;box-shadow:0 3px 12px rgba(0,0,0,.18);transition:.2s;">&#8250;</button>
                    <!-- Dots -->
                    <div id="adm-dots" style="display:flex;gap:7px;justify-content:center;flex-wrap:wrap;"></div>
                </div>

                <!-- CỘT PHẢI: Thông tin giá + CTA -->
                <div style="padding:28px 26px;display:flex;flex-direction:column;justify-content:center;gap:16px;">
                    <div style="font-size:11px;color:var(--g3);font-weight:800;text-transform:uppercase;letter-spacing:.7px;display:flex;align-items:center;gap:6px;">
                        <span style="width:8px;height:8px;border-radius:50%;background:var(--g3);display:inline-block;animation:pulse 1.5s infinite;"></span>
                        Còn hàng • Hàng mới đăng
                    </div>
                    <div style="font-size:22px;font-weight:900;color:var(--txt);line-height:1.35;font-family:'Oswald',sans-serif;" id="adm-name-r"></div>
                    <div style="background:var(--gll);border-radius:14px;padding:18px 20px;border:1.5px solid var(--border);">
                        <div style="font-size:11px;color:var(--muted);margin-bottom:6px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Giá bán</div>
                        <div style="display:flex;align-items:baseline;gap:12px;flex-wrap:wrap;">
                            <span style="font-size:34px;font-weight:900;color:var(--g2);font-family:'Oswald',sans-serif;" id="adm-price">—</span>
                            <span style="font-size:15px;color:var(--muted);text-decoration:line-through;" id="adm-old"></span>
                            <span style="background:var(--red);color:#fff;font-size:12px;font-weight:800;padding:3px 10px;border-radius:8px;" id="adm-disc"></span>
                        </div>
                    </div>
                    <!-- Tags bảo hành -->
                    <div style="display:flex;flex-wrap:wrap;gap:8px;">
                        <span style="background:var(--gl);color:var(--g2);font-size:11px;font-weight:800;padding:5px 12px;border-radius:20px;display:flex;align-items:center;gap:5px;"><i class="fas fa-shield-alt"></i> Bảo hành 12 tháng</span>
                        <span style="background:#fef3c7;color:#92400e;font-size:11px;font-weight:800;padding:5px 12px;border-radius:20px;display:flex;align-items:center;gap:5px;"><i class="fas fa-undo"></i> Đổi trả 30 ngày</span>
                        <span style="background:#dbeafe;color:#1d4ed8;font-size:11px;font-weight:800;padding:5px 12px;border-radius:20px;display:flex;align-items:center;gap:5px;"><i class="fas fa-truck"></i> Giao hàng toàn quốc</span>
                    </div>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                        <button onclick="addAdProductToCart()"
                            style="flex:1;background:linear-gradient(90deg,var(--g2),var(--g3));color:#fff;border:none;border-radius:12px;padding:14px 18px;font-size:14px;font-weight:800;cursor:pointer;font-family:inherit;display:flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 4px 16px rgba(4,120,87,.3);transition:.2s;">
                            <i class="fas fa-cart-plus"></i>Thêm vào giỏ hàng
                        </button>
                        <a href="tel:0787911555"
                            style="background:var(--g1);color:#fff;border-radius:12px;padding:14px 18px;display:flex;align-items:center;justify-content:center;gap:7px;font-size:13px;font-weight:800;white-space:nowrap;">
                            <i class="fas fa-phone-alt"></i> Gọi ngay
                        </a>
                    </div>
                    <!-- Hotline box -->
                    <div style="background:var(--bg2);border-radius:11px;padding:13px 15px;font-size:12px;color:var(--txt2);border:1px solid var(--border2);display:flex;align-items:center;gap:10px;">
                        <i class="fas fa-headset" style="font-size:20px;color:var(--g3);"></i>
                        <div>
                            <div style="font-weight:800;color:var(--g1);font-size:13px;">Hỗ trợ tư vấn miễn phí</div>
                            <div style="color:var(--muted);margin-top:2px;">Hotline: <strong style="color:var(--g2);">0787 911 555</strong> – 8:00 ~ 20:00</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Nội dung mô tả đầy đủ từ TinyMCE -->
            <div style="border-top:2px solid var(--border2);padding:24px 30px;">
                <div style="font-size:15px;font-weight:800;color:var(--g1);margin-bottom:16px;display:flex;align-items:center;gap:10px;">
                    <span style="width:32px;height:32px;background:var(--gl);border-radius:9px;display:flex;align-items:center;justify-content:center;">
                        <i class="fas fa-align-left" style="color:var(--g2);font-size:13px;"></i>
                    </span>
                    Mô tả chi tiết sản phẩm
                </div>
                <div id="adm-content"
                     style="font-size:14px;line-height:1.9;color:var(--txt2);">
                </div>
            </div>

        </div>
    </div>
</div>

<style>
/* Ad Modal slider */
.adm-slide { display:none; }
.adm-slide.active { display:flex; align-items:center; justify-content:center; width:100%; }
.adm-dot { width:8px;height:8px;border-radius:50%;background:var(--border2);cursor:pointer;transition:.2s; }
.adm-dot.on { background:var(--g3);width:24px;border-radius:4px; }
/* Pulse animation */
@keyframes pulse {
    0%,100%{opacity:1;transform:scale(1);}
    50%{opacity:.5;transform:scale(1.4);}
}
/* Full content from TinyMCE — giữ nguyên font và ảnh */
#adm-content {
    font-family:'Nunito',sans-serif;
    font-size:14px;
    line-height:1.9;
    color:var(--txt2);
}
#adm-content img { max-width:100%;height:auto;border-radius:10px;margin:12px 0;display:block;box-shadow:0 2px 12px rgba(0,0,0,.08); }
#adm-content p { margin-bottom:12px; }
#adm-content ul,#adm-content ol { padding-left:22px;margin-bottom:12px; }
#adm-content li { margin-bottom:5px; }
#adm-content h1,#adm-content h2,#adm-content h3,
#adm-content h4,#adm-content h5,#adm-content h6 {
    font-family:'Oswald',sans-serif;
    color:var(--g1);
    margin:18px 0 10px;
}
#adm-content h2 { font-size:20px; }
#adm-content h3 { font-size:17px; }
#adm-content table { width:100%;border-collapse:collapse;margin:14px 0;border-radius:8px;overflow:hidden; }
#adm-content table thead { background:var(--gl); }
#adm-content table td,#adm-content table th {
    border:1px solid var(--border2);
    padding:10px 14px;
    font-size:13.5px;
}
#adm-content table th { font-weight:800;color:var(--g1); }
#adm-content table tr:nth-child(even) { background:var(--gll); }
#adm-content strong { color:var(--txt);font-weight:800; }
#adm-content a { color:var(--g2);text-decoration:underline; }
#adm-content blockquote { border-left:4px solid var(--g3);padding:10px 16px;background:var(--gll);border-radius:0 8px 8px 0;margin:12px 0;font-style:italic; }
/* Modal body scroll */
#adModal .modal-body { overflow-y:auto; }
/* Slide image full */
.adm-slide img { max-height:380px;width:100%;object-fit:contain;border-radius:10px; }
/* Responsive */
@media(max-width:720px){
    #adModal .modal-box { max-width:100% !important; }
    #adModal .modal-body > div:first-child { grid-template-columns:1fr !important; }
    .adm-slide img { max-height:240px; }
}
</style>

<script>
// Gán title vào cột phải khi mở modal
const _origOpenAdModal = openAdModal;
// Patch: sync tên sang cột phải
document.getElementById('adModal').addEventListener('transitionend', function(){
    const t = document.getElementById('adm-title').textContent;
    const r = document.getElementById('adm-name-r');
    if(r) r.textContent = t;
});
</script>

</body>
</html>