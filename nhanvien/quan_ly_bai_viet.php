<?php
// file: nhanvien/quan_ly_bai_viet.php
session_start();
require_once '../db.php';

// Kiểm tra đăng nhập và role nhân viên
if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'nhan_vien' && $_SESSION['role'] !== 'employee')) {
    header("Location: ../login.php");
    exit();
}

$employee_name = $_SESSION['fullname'] ?? $_SESSION['username'] ?? 'Nhân viên';
$ma_nv = $_SESSION['user_id'] ?? 0;

// ========================
// TẠO BẢNG BÀI VIẾT (nếu chưa có)
// ========================
$conn->query("CREATE TABLE IF NOT EXISTS `news` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `title` varchar(500) NOT NULL,
    `slug` varchar(500) DEFAULT NULL,
    `content` longtext,
    `excerpt` text,
    `image` varchar(500) DEFAULT NULL,
    `images_json` text,
    `author` varchar(100) DEFAULT NULL,
    `author_id` int(11) DEFAULT NULL,
    `category` varchar(100) DEFAULT 'Tin tức',
    `tags` text,
    `views` int(11) DEFAULT 0,
    `status` enum('draft','published') DEFAULT 'draft',
    `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Tạo bảng categories nếu chưa có
$conn->query("CREATE TABLE IF NOT EXISTS `news_categories` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `name` varchar(100) NOT NULL,
    `slug` varchar(100) DEFAULT NULL,
    `description` text,
    `status` tinyint(1) DEFAULT 1,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Thêm danh mục mặc định
$conn->query("INSERT IGNORE INTO `news_categories` (`id`, `name`, `slug`, `status`) VALUES
    (1, 'Tin tức', 'tin-tuc', 1),
    (2, 'Khuyến mãi', 'khuyen-mai', 1),
    (3, 'Sản phẩm mới', 'san-pham-moi', 1),
    (4, 'Thủ thuật', 'thu-thuat', 1),
    (5, 'Tuyển dụng', 'tuyen-dung', 1)");

// ========================
// XỬ LÝ CRUD
// ========================

function uploadImage($file) {
    if (isset($file) && $file['error'] === 0 && $file['size'] > 0) {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($ext, $allowed)) return null;
        $filename = time() . '_' . uniqid() . '.' . $ext;
        $target = "../uploads/news/" . $filename;
        if (!is_dir('../uploads/news/')) mkdir('../uploads/news/', 0777, true);
        if (move_uploaded_file($file['tmp_name'], $target)) return $filename;
    }
    return null;
}

function uploadMultipleImages($files) {
    $uploaded = [];
    if (!empty($files['name'][0])) {
        $target_dir = "../uploads/news/";
        if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        foreach ($files['tmp_name'] as $k => $tmp) {
            if ($files['error'][$k] !== 0) continue;
            $ext = strtolower(pathinfo($files['name'][$k], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) continue;
            $fname = time() . '_' . $k . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', basename($files['name'][$k]));
            if (move_uploaded_file($tmp, $target_dir . $fname)) {
                $uploaded[] = 'uploads/news/' . $fname;
            }
        }
    }
    return $uploaded;
}

function createSlug($string) {
    $string = preg_replace('/[^a-zA-Z0-9\p{L}]/u', '-', $string);
    $string = preg_replace('/-+/', '-', $string);
    $string = trim($string, '-');
    return strtolower($string) . '-' . time();
}

// Thêm bài viết mới
if (isset($_POST['add_post'])) {
    $title = $conn->real_escape_string($_POST['title'] ?? '');
    $content = $conn->real_escape_string($_POST['content'] ?? '');
    $excerpt = $conn->real_escape_string($_POST['excerpt'] ?? '');
    $category = $conn->real_escape_string($_POST['category'] ?? 'Tin tức');
    $tags = $conn->real_escape_string($_POST['tags'] ?? '');
    $status = $_POST['status'] ?? 'draft';
    $slug = createSlug($title);
    
    // Upload ảnh đại diện
    $image = uploadImage($_FILES['image'] ?? null);
    
    // Upload nhiều ảnh
    $gallery_images = uploadMultipleImages($_FILES['gallery_images'] ?? ['name' => []]);
    $images_json = json_encode($gallery_images);
    
    // Nếu không có excerpt, lấy 150 ký tự đầu của content
    if (empty($excerpt) && !empty($content)) {
        $excerpt = mb_substr(strip_tags($content), 0, 150) . '...';
    }
    
    $sql = "INSERT INTO news (title, slug, content, excerpt, image, images_json, author, author_id, category, tags, status) 
            VALUES ('$title', '$slug', '$content', '$excerpt', '$image', '$images_json', '$employee_name', $ma_nv, '$category', '$tags', '$status')";
    
    if ($conn->query($sql)) {
        $_SESSION['success'] = "✓ Bài viết đã được đăng thành công!";
    } else {
        $_SESSION['error'] = "Lỗi: " . $conn->error;
    }
    header("Location: quan_ly_bai_viet.php");
    exit();
}

// Cập nhật bài viết
if (isset($_POST['edit_post'])) {
    $id = (int)$_POST['id'];
    $title = $conn->real_escape_string($_POST['title'] ?? '');
    $content = $conn->real_escape_string($_POST['content'] ?? '');
    $excerpt = $conn->real_escape_string($_POST['excerpt'] ?? '');
    $category = $conn->real_escape_string($_POST['category'] ?? 'Tin tức');
    $tags = $conn->real_escape_string($_POST['tags'] ?? '');
    $status = $_POST['status'] ?? 'draft';
    
    // Upload ảnh đại diện mới (nếu có)
    $image_update = "";
    $new_image = uploadImage($_FILES['image'] ?? null);
    if ($new_image) {
        $image_update = ", image='$new_image'";
    }
    
    // Upload ảnh gallery mới
    $existing_images = $_POST['existing_images'] ?? '';
    $new_gallery = uploadMultipleImages($_FILES['gallery_images'] ?? ['name' => []]);
    if (!empty($new_gallery)) {
        $old_images = json_decode($existing_images, true) ?: [];
        $all_images = array_merge($old_images, $new_gallery);
        $images_json = json_encode($all_images);
        $image_update .= ", images_json='$images_json'";
    } elseif (!empty($existing_images)) {
        $image_update .= ", images_json='$existing_images'";
    }
    
    if (empty($excerpt) && !empty($content)) {
        $excerpt = mb_substr(strip_tags($content), 0, 150) . '...';
    }
    
    $sql = "UPDATE news SET title='$title', content='$content', excerpt='$excerpt', 
            category='$category', tags='$tags', status='$status' $image_update WHERE id=$id";
    
    if ($conn->query($sql)) {
        $_SESSION['success'] = "✓ Đã cập nhật bài viết!";
    } else {
        $_SESSION['error'] = "Lỗi: " . $conn->error;
    }
    header("Location: quan_ly_bai_viet.php");
    exit();
}

// Xóa bài viết
if (isset($_GET['del'])) {
    $id = (int)$_GET['del'];
    $conn->query("DELETE FROM news WHERE id = $id");
    $_SESSION['success'] = "Đã xóa bài viết!";
    header("Location: quan_ly_bai_viet.php");
    exit();
}

// Xóa ảnh trong gallery
if (isset($_GET['remove_image']) && isset($_GET['id']) && isset($_GET['img'])) {
    $id = (int)$_GET['id'];
    $img_to_remove = urldecode($_GET['img']);
    
    $result = $conn->query("SELECT images_json FROM news WHERE id = $id");
    if ($result && $row = $result->fetch_assoc()) {
        $images = json_decode($row['images_json'], true) ?: [];
        $images = array_filter($images, function($img) use ($img_to_remove) {
            return $img !== $img_to_remove;
        });
        $new_json = json_encode(array_values($images));
        $conn->query("UPDATE news SET images_json = '$new_json' WHERE id = $id");
        
        // Xóa file vật lý
        if (file_exists("../" . $img_to_remove)) {
            unlink("../" . $img_to_remove);
        }
    }
    header("Location: quan_ly_bai_viet.php");
    exit();
}

// ========================
// LỌC & TÌM KIẾM
// ========================
$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : 'all';
$filter_category = isset($_GET['category']) ? $conn->real_escape_string($_GET['category']) : '';

$where = "WHERE 1=1";
if ($search) {
    $where .= " AND (title LIKE '%$search%' OR content LIKE '%$search%')";
}
if ($filter_status !== 'all') {
    $where .= " AND status = '$filter_status'";
}
if ($filter_category) {
    $where .= " AND category = '$filter_category'";
}

$sort = isset($_GET['sort']) ? $_GET['sort'] : 'id_desc';
$order_by = match($sort) {
    'date_asc' => "created_at ASC",
    'title_asc' => "title ASC",
    'views_desc' => "views DESC",
    default => "id DESC"
};

$posts = $conn->query("SELECT * FROM news $where ORDER BY $order_by");

// Thống kê
$total_posts = $conn->query("SELECT COUNT(*) as c FROM news")->fetch_assoc()['c'] ?? 0;
$total_published = $conn->query("SELECT COUNT(*) as c FROM news WHERE status='published'")->fetch_assoc()['c'] ?? 0;
$total_draft = $conn->query("SELECT COUNT(*) as c FROM news WHERE status='draft'")->fetch_assoc()['c'] ?? 0;
$total_views = $conn->query("SELECT SUM(views) as total FROM news")->fetch_assoc()['total'] ?? 0;

// Lấy danh sách danh mục
$categories = $conn->query("SELECT * FROM news_categories WHERE status = 1 ORDER BY name");
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Quản lý Bài viết — QA Tech (Nhân viên)</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.tiny.cloud/1/no-api-key/tinymce/6/tinymce.min.js" referrerpolicy="origin"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<style>
:root {
    --blue-primary: #2563eb;
    --blue-dark: #1d4ed8;
    --blue-light: #dbeafe;
    --sidebar-bg: #0f172a;
    --sidebar-w: 268px;
    --surface: #ffffff;
    --surface-2: #f8fafc;
    --border: #e2e8f0;
    --text-primary: #0f172a;
    --text-muted: #64748b;
    --radius: 14px;
    --shadow-sm: 0 1px 3px rgba(0,0,0,.06);
    --shadow-md: 0 4px 16px rgba(0,0,0,.08);
}
*,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Be Vietnam Pro', sans-serif;
    background: #f1f5f9;
    color: var(--text-primary);
}
::-webkit-scrollbar { width: 5px; }
::-webkit-scrollbar-track { background: #e2e8f0; }
::-webkit-scrollbar-thumb { background: var(--blue-primary); border-radius: 99px; }

/* Sidebar */
.sidebar {
    width: var(--sidebar-w);
    height: 100vh;
    position: fixed;
    top: 0; left: 0;
    background: var(--sidebar-bg);
    display: flex;
    flex-direction: column;
    z-index: 1000;
    border-right: 1px solid rgba(255,255,255,0.05);
}
.sidebar-brand {
    padding: 24px 20px 20px;
    border-bottom: 1px solid rgba(255,255,255,0.07);
}
.brand-logo { display: flex; align-items: center; gap: 12px; }
.brand-icon {
    width: 40px; height: 40px;
    background: linear-gradient(135deg, var(--blue-primary), #3b82f6);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; color: white;
}
.brand-name { font-size: 15px; font-weight: 800; color: #fff; }
.brand-sub { font-size: 10px; font-weight: 500; color: #64748b; text-transform: uppercase; }
.sidebar-nav { flex: 1; padding: 16px 0; overflow-y: auto; }
.nav-section-label {
    font-size: 9px; font-weight: 700; letter-spacing: 1.4px;
    text-transform: uppercase; color: #334155;
    padding: 16px 20px 6px;
}
.nav-link-item {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 20px;
    color: #94a3b8;
    text-decoration: none;
    font-size: 13.5px; font-weight: 500;
    transition: all 0.2s ease;
}
.nav-link-item:hover { color: #e2e8f0; background: rgba(255,255,255,.04); }
.nav-link-item.active { color: #fff; background: rgba(37,99,235,.12); }
.nav-icon {
    width: 32px; height: 32px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 8px;
    font-size: 13px;
    background: rgba(255,255,255,.05);
}
.sidebar-footer {
    padding: 16px 20px;
    border-top: 1px solid rgba(255,255,255,.07);
}
.logout-btn {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 12px;
    color: #f87171;
    text-decoration: none;
    font-size: 13px; font-weight: 600;
    border-radius: 10px;
}
.logout-btn:hover { background: rgba(239,68,68,.1); color: #fca5a5; }

/* Main */
.main {
    margin-left: var(--sidebar-w);
    min-height: 100vh;
    padding: 28px 32px;
}
.topbar {
    position: sticky; top: 0; z-index: 100;
    background: rgba(248,250,252,.9);
    backdrop-filter: blur(12px);
    border-bottom: 1px solid var(--border);
    padding: 0 32px;
    height: 64px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin: -28px -32px 28px -32px;
}
.avatar-btn {
    display: flex; align-items: center; gap: 10px;
    padding: 6px 12px 6px 6px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 40px;
    text-decoration: none;
    color: var(--text-primary);
}
.avatar-img { width: 32px; height: 32px; border-radius: 50%; }
.avatar-name { font-size: 13px; font-weight: 600; }

/* Page header */
.page-header { margin-bottom: 24px; }
.page-header h1 { font-size: 22px; font-weight: 800; color: var(--text-primary); }
.page-header p { font-size: 13px; color: var(--text-muted); margin-top: 4px; }

/* Stats */
.stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
.stat-card {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 18px 20px;
    border: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 14px;
    transition: all .2s;
}
.stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
.stat-icon {
    width: 48px; height: 48px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
}
.stat-icon.blue { background: #dbeafe; color: #2563eb; }
.stat-icon.green { background: #d1fae5; color: #059669; }
.stat-icon.amber { background: #fef3c7; color: #d97706; }
.stat-icon.purple { background: #ede9fe; color: #7c3aed; }
.stat-value { font-size: 26px; font-weight: 800; line-height: 1; }
.stat-label { font-size: 12px; color: var(--text-muted); margin-top: 4px; }

/* Toolbar */
.toolbar {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px 20px;
    margin-bottom: 20px;
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: center;
    justify-content: space-between;
}
.filter-pills { display: flex; gap: 8px; flex-wrap: wrap; }
.filter-pill {
    padding: 6px 16px;
    border-radius: 20px;
    font-size: 12.5px;
    font-weight: 600;
    color: var(--text-muted);
    background: #f8fafc;
    border: 1px solid var(--border);
    text-decoration: none;
    transition: all .15s;
}
.filter-pill:hover { background: var(--blue-light); color: var(--blue-primary); }
.filter-pill.active { background: var(--blue-primary); color: #fff; border-color: var(--blue-primary); }
.btn-primary-sm {
    background: var(--blue-primary);
    color: #fff;
    border: none;
    padding: 8px 18px;
    border-radius: 8px;
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.btn-primary-sm:hover { background: var(--blue-dark); }

/* Table */
.table-wrap {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    overflow: hidden;
}
table { width: 100%; border-collapse: collapse; }
thead th {
    padding: 12px 16px;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    color: var(--text-muted);
    border-bottom: 1px solid var(--border);
    background: #f8fafc;
}
tbody td {
    padding: 12px 16px;
    border-bottom: 1px solid #f1f5f9;
    vertical-align: middle;
}
tbody tr:hover { background: #f8fafc; }
.post-image {
    width: 50px;
    height: 50px;
    border-radius: 8px;
    object-fit: cover;
}
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
}
.status-published { background: #d1fae5; color: #065f46; }
.status-draft { background: #fef3c7; color: #92400e; }
.action-btns { display: flex; gap: 6px; }
.action-btn {
    width: 30px; height: 30px;
    border-radius: 7px;
    border: 1px solid var(--border);
    background: #fff;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    color: var(--text-muted);
    text-decoration: none;
}
.action-btn:hover { background: var(--blue-light); color: var(--blue-primary); }
.action-btn.del:hover { background: #fee2e2; color: #ef4444; }

/* Modal */
.modal-content { border-radius: 14px; border: none; }
.modal-header { border-bottom: 1px solid var(--border); padding: 16px 20px; }
.modal-title { font-weight: 700; font-size: 15px; }
.form-label-sm { font-size: 12px; font-weight: 600; margin-bottom: 5px; }
.form-control-sm, .form-select-sm { font-size: 13px; border-radius: 8px; }
.gallery-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(80px, 1fr));
    gap: 10px;
    margin-top: 10px;
}
.gallery-item {
    position: relative;
    border: 1px solid var(--border);
    border-radius: 8px;
    overflow: hidden;
}
.gallery-item img { width: 100%; height: 80px; object-fit: cover; }
.gallery-item .remove-img {
    position: absolute;
    top: 2px;
    right: 2px;
    width: 20px;
    height: 20px;
    background: rgba(239,68,68,.9);
    color: #fff;
    border: none;
    border-radius: 50%;
    font-size: 10px;
    cursor: pointer;
}
.empty-state { text-align: center; padding: 60px; color: var(--text-muted); }
.empty-icon { font-size: 48px; margin-bottom: 16px; opacity: 0.3; }
.alert-custom {
    padding: 12px 18px;
    border-radius: 10px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.alert-success { background: #d1fae5; border: 1px solid #86efac; color: #065f46; }
.alert-danger { background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b; }

@media (max-width: 768px) {
    .sidebar { transform: translateX(-100%); }
    .main { margin-left: 0; padding: 16px; }
    .topbar { margin: -16px -16px 20px -16px; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); }
    .toolbar { flex-direction: column; align-items: stretch; }
}
</style>
</head>
<body>

<!-- Sidebar -->
<aside class="sidebar">
    <div class="sidebar-brand">
        <div class="brand-logo">
            <div class="brand-icon"><i class="fas fa-store"></i></div>
            <div class="brand-text">
                <div class="brand-name">Quang Anh Tech</div>
                <div class="brand-sub">Nhân viên</div>
            </div>
        </div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-section-label">Tổng quan</div>
        <a href="trang_chu.php" class="nav-link-item">
            <div class="nav-icon"><i class="fas fa-chart-pie"></i></div> Trang chủ
        </a>
        <div class="nav-section-label">Quản lý</div>
        <a href="quan_ly_khach_hang.php" class="nav-link-item">
            <div class="nav-icon"><i class="fas fa-users"></i></div> Khách hàng
        </a>
        <div class="nav-section-label">Nội dung</div>
        <a href="quan_ly_bai_viet.php" class="nav-link-item active">
            <div class="nav-icon"><i class="fas fa-newspaper"></i></div> Quản lý bài viết
        </a>
        <a href="dang_bai_moi.php" class="nav-link-item">
            <div class="nav-icon"><i class="fas fa-pen-fancy"></i></div> Đăng bài mới
        </a>
        <div class="nav-section-label">Marketing</div>
        <a href="khuyen_mai.php" class="nav-link-item">
            <div class="nav-icon"><i class="fas fa-percentage"></i></div> Khuyến mãi
        </a>
        <div class="nav-section-label">Cá nhân</div>
        <a href="thong_tin_ca_nhan.php" class="nav-link-item">
            <div class="nav-icon"><i class="fas fa-user-circle"></i></div> Thông tin cá nhân
        </a>
        <a href="doi_mat_khau.php" class="nav-link-item">
            <div class="nav-icon"><i class="fas fa-key"></i></div> Đổi mật khẩu
        </a>
    </nav>
    <div class="sidebar-footer">
        <a href="../logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i> Đăng xuất
        </a>
    </div>
</aside>

<!-- Main -->
<main class="main">
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <span class="fw-bold">Quản lý bài viết</span>
            <span class="text-muted">/</span>
            <span class="text-muted">Tin tức & quảng bá</span>
        </div>
        <div class="dropdown">
            <a href="#" class="avatar-btn dropdown-toggle text-decoration-none" data-bs-toggle="dropdown">
                <img src="https://ui-avatars.com/api/?name=<?= urlencode($employee_name) ?>&background=2563eb&color=fff&bold=true"
                     class="avatar-img" alt="avatar">
                <span class="avatar-name"><?= htmlspecialchars($employee_name) ?></span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end shadow-lg border-0 mt-2">
                <li><a class="dropdown-item" href="thong_tin_ca_nhan.php"><i class="fas fa-user-circle me-2"></i> Thông tin cá nhân</a></li>
                <li><a class="dropdown-item" href="doi_mat_khau.php"><i class="fas fa-key me-2"></i> Đổi mật khẩu</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="../logout.php"><i class="fas fa-sign-out-alt me-2"></i> Đăng xuất</a></li>
            </ul>
        </div>
    </div>

    <div class="page-header">
        <h1>Quản lý bài viết</h1>
        <p>Quản lý tin tức, bài viết quảng bá trên website</p>
    </div>

    <!-- Alert -->
    <?php if(isset($_SESSION['success'])): ?>
    <div class="alert-custom alert-success">
        <i class="fas fa-check-circle"></i> <?= $_SESSION['success']; unset($_SESSION['success']); ?>
    </div>
    <?php endif; ?>
    <?php if(isset($_SESSION['error'])): ?>
    <div class="alert-custom alert-danger">
        <i class="fas fa-exclamation-triangle"></i> <?= $_SESSION['error']; unset($_SESSION['error']); ?>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fas fa-newspaper"></i></div>
            <div><div class="stat-value"><?= $total_posts ?></div><div class="stat-label">Tổng bài viết</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
            <div><div class="stat-value"><?= $total_published ?></div><div class="stat-label">Đã đăng</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon amber"><i class="fas fa-pen-fancy"></i></div>
            <div><div class="stat-value"><?= $total_draft ?></div><div class="stat-label">Bản nháp</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon purple"><i class="fas fa-eye"></i></div>
            <div><div class="stat-value"><?= number_format($total_views) ?></div><div class="stat-label">Tổng lượt xem</div></div>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
        <div class="filter-pills">
            <a href="?status=all" class="filter-pill <?= $filter_status == 'all' ? 'active' : '' ?>">Tất cả</a>
            <a href="?status=published" class="filter-pill <?= $filter_status == 'published' ? 'active' : '' ?>">Đã đăng</a>
            <a href="?status=draft" class="filter-pill <?= $filter_status == 'draft' ? 'active' : '' ?>">Bản nháp</a>
        </div>
        <div class="d-flex gap-2">
            <form method="GET" class="d-flex gap-2">
                <input type="hidden" name="status" value="<?= $filter_status ?>">
                <select name="category" class="form-select form-select-sm" style="width: 150px;" onchange="this.form.submit()">
                    <option value="">Tất cả danh mục</option>
                    <?php if($categories): while($cat = $categories->fetch_assoc()): ?>
                    <option value="<?= htmlspecialchars($cat['name']) ?>" <?= $filter_category == $cat['name'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                    <?php endwhile; endif; ?>
                </select>
                <select name="sort" class="form-select form-select-sm" style="width: 130px;" onchange="this.form.submit()">
                    <option value="id_desc" <?= $sort == 'id_desc' ? 'selected' : '' ?>>Mới nhất</option>
                    <option value="date_asc" <?= $sort == 'date_asc' ? 'selected' : '' ?>>Cũ nhất</option>
                    <option value="title_asc" <?= $sort == 'title_asc' ? 'selected' : '' ?>>Tên A-Z</option>
                    <option value="views_desc" <?= $sort == 'views_desc' ? 'selected' : '' ?>>Xem nhiều</option>
                </select>
                <div class="input-group" style="width: 250px;">
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Tìm bài viết..." value="<?= htmlspecialchars($search) ?>">
                    <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-search"></i></button>
                </div>
            </form>
            <a href="dang_bai_moi.php" class="btn-primary-sm"><i class="fas fa-plus"></i> Đăng bài mới</a>
        </div>
    </div>

    <!-- Table -->
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width: 60px;">ID</th>
                    <th>Hình ảnh</th>
                    <th>Tiêu đề</th>
                    <th>Danh mục</th>
                    <th>Trạng thái</th>
                    <th>Lượt xem</th>
                    <th>Ngày đăng</th>
                    <th style="width: 100px;">Thao tác</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($posts && $posts->num_rows > 0): 
                while($post = $posts->fetch_assoc()):
            ?>
                <tr>
                    <td><?= $post['id'] ?></td>
                    <td>
                        <?php if($post['image']): ?>
                        <img src="../uploads/news/<?= $post['image'] ?>" class="post-image" alt="<?= htmlspecialchars($post['title']) ?>">
                        <?php else: ?>
                        <div class="post-image bg-light d-flex align-items-center justify-content-center"><i class="fas fa-image text-muted"></i></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="fw-bold" style="max-width: 300px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                            <?= htmlspecialchars($post['title']) ?>
                        </div>
                        <small class="text-muted"><?= htmlspecialchars(substr(strip_tags($post['content'] ?? ''), 0, 80)) ?>...</small>
                    </td>
                    <td><span class="badge bg-light text-dark"><?= htmlspecialchars($post['category']) ?></span></td>
                    <td>
                        <span class="status-badge <?= $post['status'] == 'published' ? 'status-published' : 'status-draft' ?>">
                            <i class="fas <?= $post['status'] == 'published' ? 'fa-check-circle' : 'fa-edit' ?>"></i>
                            <?= $post['status'] == 'published' ? 'Đã đăng' : 'Nháp' ?>
                        </span>
                    </td>
                    <td><?= number_format($post['views']) ?></td>
                    <td><?= date('d/m/Y', strtotime($post['created_at'])) ?></td>
                    <td>
                        <div class="action-btns">
                            <a href="sua_bai_viet.php?id=<?= $post['id'] ?>" class="action-btn" title="Sửa">
                                <i class="fas fa-edit"></i>
                            </a>
                            <a href="?del=<?= $post['id'] ?>" class="action-btn del" title="Xóa" onclick="return confirmDelete(this.href, '<?= addslashes($post['title']) ?>')">
                                <i class="fas fa-trash"></i>
                            </a>
                        </div>
                    </td>
                </tr>
            <?php endwhile; else: ?>
                <tr>
                    <td colspan="8" style="text-align: center; padding: 60px;">
                        <div class="empty-icon"><i class="fas fa-newspaper"></i></div>
                        <h5>Chưa có bài viết nào</h5>
                        <p>Hãy tạo bài viết đầu tiên</p>
                        <a href="dang_bai_moi.php" class="btn-primary-sm mt-3"><i class="fas fa-plus"></i> Đăng bài mới</a>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function confirmDelete(url, title) {
    event.preventDefault();
    Swal.fire({
        title: 'Xóa bài viết?',
        html: `Bạn sắp xóa bài viết <strong>${title}</strong>.<br>Thao tác này không thể hoàn tác!`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#ef4444',
        cancelButtonColor: '#64748b',
        confirmButtonText: '<i class="fas fa-trash"></i> Xóa vĩnh viễn',
        cancelButtonText: 'Hủy'
    }).then(result => {
        if (result.isConfirmed) window.location.href = url;
    });
    return false;
}
</script>
</body>
</html>