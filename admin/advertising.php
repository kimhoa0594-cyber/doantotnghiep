<?php
session_start();
require_once '../db.php';

// 1. Kiểm tra quyền admin
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../login.php"); exit();
}

// Tự động tạo bảng ads nếu chưa có
if($conn){
    $conn->query("CREATE TABLE IF NOT EXISTS `ads` (
        `id`         INT AUTO_INCREMENT PRIMARY KEY,
        `title`      VARCHAR(500) NOT NULL DEFAULT '',
        `content`    LONGTEXT,
        `old_p`      DOUBLE DEFAULT 0,
        `new_p`      DOUBLE DEFAULT 0,
        `status`     VARCHAR(20) DEFAULT 'draft',
        `tags`       TEXT,
        `categories` TEXT,
        `image`      VARCHAR(500) DEFAULT '',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Tự động thêm các cột còn thiếu nếu bảng đã tồn tại từ trước
    $existing_cols = [];
    $col_res = $conn->query("SHOW COLUMNS FROM `ads`");
    if($col_res) while($c = $col_res->fetch_assoc()) $existing_cols[] = $c['Field'];

    if(!in_array('image', $existing_cols))
        $conn->query("ALTER TABLE `ads` ADD COLUMN `image` VARCHAR(500) DEFAULT ''");
    if(!in_array('categories', $existing_cols))
        $conn->query("ALTER TABLE `ads` ADD COLUMN `categories` TEXT");
    if(!in_array('tags', $existing_cols))
        $conn->query("ALTER TABLE `ads` ADD COLUMN `tags` TEXT");
    if(!in_array('old_p', $existing_cols))
        $conn->query("ALTER TABLE `ads` ADD COLUMN `old_p` DOUBLE DEFAULT 0");
    if(!in_array('new_p', $existing_cols))
        $conn->query("ALTER TABLE `ads` ADD COLUMN `new_p` DOUBLE DEFAULT 0");
    if(!in_array('created_at', $existing_cols))
        $conn->query("ALTER TABLE `ads` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
}

// 2. --- XỬ LÝ XÓA ---
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $id_to_delete = intval($_GET['id']);
    $stmt = $conn->prepare("DELETE FROM ads WHERE id = ?");
    $stmt->bind_param("i", $id_to_delete);
    if ($stmt->execute()) {
        $_SESSION['status'] = "success";
        $_SESSION['msg'] = "Đã xóa sản phẩm thành công!";
    }
    $stmt->close();
    header("Location: advertising.php"); exit();
}
// --- XỬ LÝ LƯU ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['publish']) || isset($_POST['save_draft']))) {
    $post_id = !empty($_POST['post_id']) ? intval($_POST['post_id']) : null;
    $title = $conn->real_escape_string($_POST['title']);
    $content = $conn->real_escape_string($_POST['content']);
    $old_p = floatval($_POST['old_p']);
    $new_p = floatval($_POST['new_p']);
    $status = isset($_POST['publish']) ? 'published' : 'draft';
    $tags = $conn->real_escape_string($_POST['product_tags'] ?? '');
    $cats = isset($_POST['cats']) ? implode(',', $_POST['cats']) : '';

    // ============================================================
    // Xử lý upload nhiều ảnh → lưu JSON vào cột `image`
    // images/ nằm ở root (ngang với admin/)
    // ============================================================
    $target_dir = "../images/";
    if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);

    $uploaded_images = [];

    // Ảnh mới upload từ input file
    if (!empty($_FILES['image_files']['name'][0])) {
        $allowed = ['jpg','jpeg','png','gif','webp'];
        foreach ($_FILES['image_files']['tmp_name'] as $k => $tmp) {
            if ($_FILES['image_files']['error'][$k] !== 0) continue;
            $ext = strtolower(pathinfo($_FILES['image_files']['name'][$k], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) continue;
            $fname = time() . '_' . $k . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', basename($_FILES['image_files']['name'][$k]));
            if (move_uploaded_file($tmp, $target_dir . $fname)) {
                $uploaded_images[] = 'images/' . $fname;
            }
        }
    }

    // Nếu đang edit và không upload ảnh mới → giữ ảnh cũ
    $existing_images_json = trim($_POST['existing_images'] ?? '');
    if (empty($uploaded_images) && $existing_images_json !== '') {
        $decoded = json_decode($existing_images_json, true);
        if (is_array($decoded)) $uploaded_images = $decoded;
    }

    // Ảnh đại diện (ảnh đầu tiên), toàn bộ ảnh lưu dạng JSON
    $main_image    = !empty($uploaded_images) ? $uploaded_images[0] : '';
    $all_images_json = json_encode($uploaded_images, JSON_UNESCAPED_UNICODE);

    if ($post_id) {
        if (!empty($uploaded_images)) {
            // Đảm bảo cột images_json tồn tại
            $conn->query("ALTER TABLE ads ADD COLUMN IF NOT EXISTS images_json TEXT");
            $sql  = "UPDATE ads SET title=?, content=?, old_p=?, new_p=?, status=?, tags=?, categories=?, image=?, images_json=? WHERE id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssddsssssi", $title, $content, $old_p, $new_p, $status, $tags, $cats, $main_image, $all_images_json, $post_id);
        } else {
            $sql  = "UPDATE ads SET title=?, content=?, old_p=?, new_p=?, status=?, tags=?, categories=? WHERE id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssddsssi", $title, $content, $old_p, $new_p, $status, $tags, $cats, $post_id);
        }
    } else {
        // Đảm bảo cột images_json tồn tại
        $conn->query("ALTER TABLE ads ADD COLUMN IF NOT EXISTS images_json TEXT");
        $sql  = "INSERT INTO ads (title, content, old_p, new_p, status, tags, categories, image, images_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssddsssss", $title, $content, $old_p, $new_p, $status, $tags, $cats, $main_image, $all_images_json);
    }

    if ($stmt && $stmt->execute()) {
        $_SESSION['status'] = "success";
        $_SESSION['msg']    = "Bài viết đã được hiển thị trên Website!";
    } else {
        $_SESSION['status'] = "error";
        $_SESSION['msg']    = "Có lỗi xảy ra: " . ($stmt ? $stmt->error : $conn->error);
    }
    if ($stmt) $stmt->close();
    header("Location: advertising.php"); exit();
}

$ads_list = [];
$result = $conn->query("SELECT * FROM ads ORDER BY id DESC");
if ($result) {
    while($row = $result->fetch_assoc()) { $ads_list[] = $row; }
}
?>

<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nội dung quảng bá - QA Tech Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css" rel="stylesheet">
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.7.0/tinymce.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.all.min.js"></script>

    <style>
        :root {
            --green-900: #052e16;
            --green-800: #14532d;
            --green-700: #15803d;
            --green-600: #16a34a;
            --green-500: #22c55e;
            --green-400: #4ade80;
            --green-300: #86efac;
            --green-100: #dcfce7;
            --green-50:  #f0fdf4;
            --sidebar-bg: #0a1f0f;
            --sidebar-border: rgba(34,197,94,0.12);
            --body-bg: #f3f8f4;
            --card-bg: #ffffff;
            --text-main: #1a2e1d;
            --text-muted: #5c7f62;
            --border-color: #d4e8d8;
            --accent: #16a34a;
            --accent-hover: #15803d;
            --danger: #ef4444;
        }

        * { box-sizing: border-box; }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background: var(--body-bg);
            margin: 0;
            color: var(--text-main);
            min-height: 100vh;
        }

        /* ── SIDEBAR ─────────────────────────────────── */
        .sidebar {
            width: 260px;
            height: 100vh;
            background: var(--sidebar-bg);
            position: fixed;
            left: 0; top: 0;
            z-index: 1000;
            display: flex;
            flex-direction: column;
            border-right: 1px solid var(--sidebar-border);
        }

        .sidebar-logo {
            padding: 28px 24px 22px;
            border-bottom: 1px solid var(--sidebar-border);
        }
        .sidebar-logo .brand-name {
            font-size: 20px;
            font-weight: 800;
            color: #fff;
            letter-spacing: -0.5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .sidebar-logo .brand-name .dot {
            width: 10px; height: 10px;
            background: var(--green-500);
            border-radius: 50%;
            display: inline-block;
            box-shadow: 0 0 8px var(--green-500);
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.6; transform: scale(0.85); }
        }
        .sidebar-logo .brand-sub {
            font-size: 11px;
            color: var(--green-400);
            font-weight: 500;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-top: 4px;
        }

        .nav-section-label {
            padding: 20px 24px 6px;
            font-size: 10px;
            font-weight: 700;
            color: rgba(255,255,255,0.25);
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        .nav-menu { list-style: none; padding: 8px 12px; margin: 0; flex: 1; }
        .nav-link-custom {
            padding: 11px 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: rgba(255,255,255,0.55);
            text-decoration: none;
            transition: all 0.2s;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 2px;
        }
        .nav-link-custom i { width: 18px; text-align: center; font-size: 13px; }
        .nav-link-custom:hover {
            background: rgba(34,197,94,0.08);
            color: var(--green-400);
        }
        .nav-link-custom.active {
            background: linear-gradient(135deg, rgba(34,197,94,0.2), rgba(22,163,74,0.12));
            color: var(--green-400);
            border: 1px solid rgba(34,197,94,0.2);
        }
        .nav-link-custom.active i { color: var(--green-500); }

        .sidebar-footer {
            padding: 16px 12px;
            border-top: 1px solid var(--sidebar-border);
        }
        .sidebar-footer .nav-link-custom { color: rgba(239,68,68,0.7); }
        .sidebar-footer .nav-link-custom:hover { color: #ef4444; background: rgba(239,68,68,0.08); }

        /* ── MAIN CONTENT ─────────────────────────────── */
        .main-wrapper {
            margin-left: 260px;
            padding: 32px 36px;
            min-height: 100vh;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
            padding-bottom: 20px;
            border-bottom: 1px solid var(--border-color);
        }
        .page-title {
            font-size: 22px;
            font-weight: 800;
            color: var(--text-main);
            letter-spacing: -0.5px;
        }
        .page-title span { color: var(--accent); }

        /* ── POSTBOX CARDS ────────────────────────────── */
        .postbox {
            background: var(--card-bg);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            margin-bottom: 20px;
            box-shadow: 0 1px 6px rgba(0,0,0,0.04);
            overflow: hidden;
            transition: box-shadow 0.2s;
        }
        .postbox:hover { box-shadow: 0 4px 16px rgba(22,163,74,0.08); }

        .postbox-header {
            padding: 14px 20px;
            background: linear-gradient(to right, #f6fbf7, #f0fdf4);
            border-bottom: 1px solid var(--border-color);
            font-weight: 700;
            font-size: 13px;
            color: var(--green-800);
            display: flex;
            justify-content: space-between;
            align-items: center;
            letter-spacing: 0.2px;
            text-transform: uppercase;
        }
        .postbox-header i { color: var(--accent); margin-right: 6px; }

        .postbox-body { padding: 20px; }

        /* ── FORM ELEMENTS ────────────────────────────── */
        .form-label {
            font-weight: 600;
            font-size: 13px;
            color: var(--text-main);
            margin-bottom: 6px;
        }
        .form-control, .form-select {
            border: 1.5px solid var(--border-color);
            border-radius: 8px;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 14px;
            color: var(--text-main);
            background: #fff;
            transition: border-color 0.2s, box-shadow 0.2s;
            padding: 10px 14px;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--green-500);
            box-shadow: 0 0 0 3px rgba(34,197,94,0.12);
            outline: none;
        }
        .form-control::placeholder { color: #aac4ae; }

        /* ── BUTTONS ──────────────────────────────────── */
        .btn-primary {
            background: linear-gradient(135deg, var(--green-600), var(--green-700));
            border: none;
            border-radius: 8px;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-weight: 700;
            font-size: 14px;
            padding: 10px 20px;
            color: #fff;
            letter-spacing: 0.3px;
            transition: all 0.2s;
            box-shadow: 0 2px 8px rgba(22,163,74,0.3);
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, var(--green-500), var(--green-600));
            transform: translateY(-1px);
            box-shadow: 0 4px 14px rgba(22,163,74,0.4);
            color: #fff;
        }
        .btn-light {
            background: #fff;
            border: 1.5px solid var(--border-color);
            border-radius: 8px;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-weight: 600;
            font-size: 14px;
            padding: 10px 20px;
            color: var(--text-muted);
            transition: all 0.2s;
        }
        .btn-light:hover {
            border-color: var(--green-400);
            color: var(--accent);
            background: var(--green-50);
        }
        .btn-outline-primary {
            border: 1.5px solid var(--green-500);
            color: var(--accent);
            border-radius: 7px;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.2s;
        }
        .btn-outline-primary:hover {
            background: var(--green-600);
            border-color: var(--green-600);
            color: #fff;
        }
        .btn-outline-danger {
            border: 1.5px solid #fca5a5;
            color: var(--danger);
            border-radius: 7px;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.2s;
        }
        .btn-outline-danger:hover { background: var(--danger); color: #fff; border-color: var(--danger); }
        .btn-outline-secondary {
            border: 1.5px solid var(--border-color);
            color: var(--text-muted);
            border-radius: 8px;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.2s;
            background: #fff;
        }
        .btn-outline-secondary:hover {
            background: var(--green-50);
            border-color: var(--green-400);
            color: var(--accent);
        }
        .btn-secondary {
            background: var(--green-100);
            border: 1.5px solid var(--green-300);
            color: var(--green-800);
            border-radius: 7px;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.2s;
        }
        .btn-secondary:hover { background: var(--green-200, #bbf7d0); color: var(--green-900); }

        /* ── PRICE PANEL ──────────────────────────────── */
        .sale-badge {
            background: linear-gradient(135deg, #fff1f2, #ffe4e6);
            border: 2px solid #fecdd3;
            border-radius: 10px;
            padding: 14px;
            text-align: center;
            margin-bottom: 14px;
        }
        .sale-badge .label { font-size: 10px; font-weight: 700; color: #9f1239; letter-spacing: 2px; text-transform: uppercase; margin-bottom: 4px; }
        #saleTag { font-size: 32px; font-weight: 800; color: var(--danger); font-family: 'DM Mono', monospace; }

        /* ── GALLERY ──────────────────────────────────── */
        #gallery-sortable {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 10px;
            min-height: 130px;
            padding: 12px;
            border: 2px dashed var(--green-300);
            border-radius: 10px;
            background: var(--green-50);
            transition: border-color 0.2s;
        }
        #gallery-sortable:hover { border-color: var(--green-500); }
        .gallery-item {
            position: relative;
            border: 2px solid transparent;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            cursor: grab;
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .gallery-item:hover { transform: scale(1.03); box-shadow: 0 4px 14px rgba(0,0,0,0.14); }
        .gallery-item:first-child { border-color: var(--green-500); }
        .gallery-item img { width: 100%; aspect-ratio: 1; object-fit: cover; display: block; }
        .btn-del-img {
            position: absolute; top: 4px; right: 4px;
            background: rgba(239,68,68,0.9);
            color: #fff; border: none; border-radius: 50%;
            width: 22px; height: 22px; font-size: 11px;
            cursor: pointer; z-index: 10;
            display: flex; align-items: center; justify-content: center;
            transition: background 0.15s;
        }
        .btn-del-img:hover { background: var(--danger); }
        .main-img-badge {
            position: absolute; bottom: 0; left: 0; right: 0;
            background: linear-gradient(to right, var(--green-600), var(--green-500));
            color: #fff; font-size: 9px; font-weight: 700;
            text-align: center; padding: 3px 0;
            letter-spacing: 1px;
            display: none;
        }
        .gallery-item:first-child .main-img-badge { display: block; }

        /* ── CATEGORY TREE ────────────────────────────── */
        .category-checklist {
            height: 230px;
            overflow-y: auto;
            padding: 12px;
        }
        .category-checklist::-webkit-scrollbar { width: 4px; }
        .category-checklist::-webkit-scrollbar-track { background: var(--green-50); }
        .category-checklist::-webkit-scrollbar-thumb { background: var(--green-300); border-radius: 4px; }
        .category-checklist ul { list-style: none; padding-left: 18px; margin: 4px 0; }
        .category-checklist > ul { padding-left: 0; }
        .category-checklist li { margin-bottom: 5px; }
        .cat-label {
            cursor: pointer;
            user-select: none;
            vertical-align: middle;
            font-size: 13.5px;
            color: var(--text-main);
            font-weight: 500;
        }
        input[type="checkbox"] { accent-color: var(--accent); }

        /* ── TAGS ─────────────────────────────────────── */
        .tag-cloud {
            padding: 12px;
            border: 1.5px solid var(--border-color);
            background: var(--green-50);
            border-radius: 8px;
            margin-top: 10px;
        }
        .tag-cloud a {
            color: var(--accent);
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            margin-right: 8px;
            margin-bottom: 4px;
            display: inline-block;
            cursor: pointer;
            padding: 3px 8px;
            background: var(--green-100);
            border-radius: 20px;
            transition: all 0.15s;
        }
        .tag-cloud a:hover { background: var(--green-600); color: #fff; }
        .selected-tags { margin-top: 10px; display: flex; flex-wrap: wrap; gap: 6px; }
        .tag-item {
            background: var(--green-100);
            border: 1px solid var(--green-300);
            color: var(--green-800);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .tag-item i { cursor: pointer; color: var(--green-600); transition: color 0.15s; }
        .tag-item i:hover { color: var(--danger); }

        /* ── DATA TABLE ───────────────────────────────── */
        .wp-table { width: 100%; background: #fff; border-collapse: collapse; }
        .wp-table thead tr { background: linear-gradient(to right, var(--green-50), #f6fbf7); }
        .wp-table th {
            padding: 13px 16px;
            border-bottom: 2px solid var(--border-color);
            text-align: left;
            font-size: 11px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .wp-table td {
            padding: 13px 16px;
            border-bottom: 1px solid #eef4ef;
            font-size: 14px;
            vertical-align: middle;
        }
        .wp-table tbody tr { transition: background 0.15s; }
        .wp-table tbody tr:hover { background: var(--green-50); }
        .wp-table tbody tr:last-child td { border-bottom: none; }

        .price-tag {
            font-family: 'DM Mono', monospace;
            font-weight: 500;
            color: var(--accent);
            font-size: 14px;
        }

        .badge {
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 20px;
            letter-spacing: 0.5px;
        }
        .bg-success { background: var(--green-100) !important; color: var(--green-800) !important; }
        .bg-warning { background: #fef9c3 !important; color: #854d0e !important; }

        /* ── QUICK ADD CATEGORY BOX ───────────────────── */
        #quick-add-box {
            background: var(--green-50);
            border-top: 1px solid var(--border-color);
        }

        /* ── TINYMCE WRAPPER ──────────────────────────── */
        .tox-tinymce { border-radius: 8px !important; border-color: var(--border-color) !important; }
        .tox-tinymce:focus-within { box-shadow: 0 0 0 3px rgba(34,197,94,0.12) !important; }

        /* ── EMPTY STATE ──────────────────────────────── */
        .empty-row td {
            text-align: center;
            padding: 40px;
            color: var(--text-muted);
            font-size: 13px;
        }

        /* ── ANIMATIONS ───────────────────────────────── */
        .postbox { animation: fadeUp 0.3s ease both; }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(8px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ── INPUT GROUP ──────────────────────────────── */
        .input-group .btn { border-radius: 0 8px 8px 0 !important; }
        .input-group .form-control { border-radius: 8px 0 0 8px !important; }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-logo">
        <div class="brand-name">
            <span class="dot"></span> QA Tech
        </div>
        <div class="brand-sub">Admin Panel</div>
    </div>
    <div class="nav-section-label">Menu chính</div>
    <ul class="nav-menu">
        <li><a href="index.php" class="nav-link-custom"><i class="fas fa-house-chimney"></i>Tổng quan</a></li>
        <li><a href="advertising.php" class="nav-link-custom active"><i class="fas fa-rectangle-ad"></i>Bài viết quảng bá</a></li>
        <li><a href="../index.php" class="nav-link-custom" target="_blank"><i class="fas fa-arrow-up-right-from-square"></i>Xem Website</a></li>
    </ul>
    <div class="sidebar-footer">
        <ul class="nav-menu" style="padding: 0;">
            <li><a href="../logout.php" class="nav-link-custom"><i class="fas fa-right-from-bracket"></i>Đăng xuất</a></li>
        </ul>
    </div>
</div>

<div class="main-wrapper">
    <div class="page-header">
        <div>
            <div class="page-title">Quản lý <span>Bài Viết Quảng Bá</span></div>
            <div style="font-size: 13px; color: var(--text-muted); margin-top: 4px;">Tạo và quản lý nội dung sản phẩm hiển thị trên website</div>
        </div>
        <button class="btn btn-primary" onclick="resetFormForNew()">
            <i class="fas fa-plus me-2"></i>Thêm bài mới
        </button>
    </div>

    <form id="adForm" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="post_id" id="postIdInput">
        <input type="hidden" name="product_tags" id="tagsHiddenInp"> 
        
        <div class="row">
            <div class="col-lg-8">
                <div class="postbox">
                    <div class="postbox-header"><span><i class="fas fa-pen-nib"></i>Thông tin sản phẩm</span></div>
                    <div class="postbox-body">
                        <label class="form-label">Tiêu đề sản phẩm</label>
                        <input type="text" id="postTitle" name="title" class="form-control mb-3" placeholder="Nhập tên laptop..." required>
                        <label class="form-label">Mô tả chi tiết sản phẩm</label>
                        <textarea id="editor" name="content"></textarea>
                    </div>
                </div>

                <div class="postbox">
                    <div class="postbox-header">
                        <span><i class="fas fa-images"></i>Ảnh sản phẩm</span>
                        <button type="button" class="btn btn-outline-danger btn-sm border-0" onclick="clearAllImages()"><i class="fas fa-trash me-1"></i>Xóa all</button>
                    </div>
                    <div class="postbox-body text-center">
                        <input type="file" id="fileInp" name="image_files[]" multiple accept="image/*" class="d-none">
                        <button type="button" class="btn btn-outline-secondary btn-sm mb-3" onclick="document.getElementById('fileInp').click()">
                            <i class="fas fa-cloud-arrow-up me-2"></i>Chọn ảnh từ máy tính
                        </button>
                        <p class="text-muted small mb-2" style="font-size: 12px; color: var(--text-muted) !important;">Ảnh đầu tiên sẽ là ảnh đại diện. Kéo để sắp xếp lại thứ tự.</p>
                        <div id="gallery-sortable"></div>
                        <input type="hidden" name="existing_images" id="existingImagesInp">
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="postbox">
                    <div class="postbox-header"><span><i class="fas fa-tag"></i>Giá bán</span></div>
                    <div class="postbox-body">
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label">Giá gốc</label>
                                <input type="number" id="oldP" name="old_p" class="form-control" placeholder="0" oninput="calc()">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Giá bán</label>
                                <input type="number" id="newP" name="new_p" class="form-control" placeholder="0" oninput="calc()">
                            </div>
                        </div>
                        <div class="sale-badge">
                            <div class="label">Mức giảm giá</div>
                            <span id="saleTag">0%</span>
                        </div>
                        <div class="d-grid gap-2">
                            <button type="submit" name="publish" class="btn btn-primary fw-bold">
                                <i class="fas fa-rocket me-2"></i>UP BÀI NGAY
                            </button>
                            <button type="submit" name="save_draft" class="btn btn-light">
                                <i class="fas fa-floppy-disk me-2"></i>Lưu nháp
                            </button>
                        </div>
                    </div>
                </div>

                <div class="postbox">
                    <div class="postbox-header"><span><i class="fas fa-folder-tree"></i>Danh mục sản phẩm</span></div>
                    <div class="postbox-body p-0">
                        <div class="category-checklist p-3" id="catTree"></div>
                        <div id="quick-add-box" class="p-2 d-none">
                            <input type="text" id="newCatName" class="form-control form-control-sm mb-1" placeholder="Tên danh mục...">
                            <select id="parentCatSelect" class="form-select form-select-sm mb-1">
                                <option value="-1">-- Danh mục chính --</option>
                            </select>
                            <button type="button" class="btn btn-secondary btn-sm w-100" onclick="addNewCategory()">Thêm danh mục</button>
                        </div>
                        <div class="p-2 px-3 border-top" style="background: var(--green-50);">
                            <a href="javascript:void(0)" class="small text-decoration-none fw-bold" style="color: var(--accent);" onclick="document.getElementById('quick-add-box').classList.toggle('d-none')">
                                <i class="fas fa-plus me-1"></i>Thêm danh mục mới
                            </a>
                        </div>
                    </div>
                </div>

                <div class="postbox">
                    <div class="postbox-header"><span><i class="fas fa-hashtag"></i>Từ khóa tìm kiếm</span></div>
                    <div class="postbox-body">
                        <div class="input-group input-group-sm mb-2">
                            <input type="text" id="tagInp" class="form-control" placeholder="Nhập tag rồi nhấn Thêm...">
                            <button type="button" class="btn btn-secondary" onclick="addTag()">Thêm</button>
                        </div>
                        <div class="selected-tags" id="selectedTags"></div>
                        <div class="mt-3">
                            <a href="javascript:void(0)" class="small text-decoration-none fw-bold" style="color: var(--accent);" onclick="document.getElementById('popularTags').classList.toggle('d-none')">
                                <i class="fas fa-lightbulb me-1"></i>Gợi ý từ khóa
                            </a>
                            <div id="popularTags" class="tag-cloud d-none">
                                <a onclick="addTag('laptop cũ')">laptop cũ</a>
                                <a onclick="addTag('laptop giá rẻ')">laptop giá rẻ</a>
                                <a onclick="addTag('dell latitude')">dell latitude</a>
                                <a onclick="addTag('thinkpad')">thinkpad</a>
                                <a onclick="addTag('macbook giá tốt')">macbook giá tốt</a>
                                <a onclick="addTag('laptop văn phòng giá rẻ')">laptop văn phòng giá rẻ</a>
                                <a onclick="addTag('laptop sinh viên')">laptop sinh viên</a>
                                <a onclick="addTag('laptop gaming')">laptop gaming</a>
                                <a onclick="addTag('laptop đồ họa')">laptop đồ họa</a>
                                <a onclick="addTag('card rời')">card rời</a>
                                <a onclick="addTag('laptop dell')">laptop dell</a>
                                <a onclick="addTag('laptop cũ giá rẻ')">laptop cũ giá rẻ</a>
                                <a onclick="addTag('laptop chơi game')">laptop chơi game</a>
                                <a onclick="addTag('laptop giá rẻ tại Hải Phòng')">laptop giá rẻ Hải Phòng</a>
                                <a onclick="addTag('laptop HP')">laptop HP</a>
                                <a onclick="addTag('laptop văn phòng')">laptop văn phòng</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <div class="postbox">
        <div class="postbox-header"><span><i class="fas fa-list-ul"></i>Danh sách bài viết</span></div>
        <div class="postbox-body p-0">
            <table class="wp-table">
                <thead>
                    <tr>
                        <th>Sản phẩm</th>
                        <th>Giá bán</th>
                        <th>Trạng thái</th>
                        <th class="text-end">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($ads_list)): ?>
                    <tr class="empty-row"><td colspan="4"><i class="fas fa-inbox fa-2x mb-2 d-block" style="color: var(--green-300);"></i>Chưa có bài viết nào. Hãy tạo bài đầu tiên!</td></tr>
                    <?php else: ?>
                    <?php foreach($ads_list as $ad): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($ad['title']) ?></strong></td>
                        <td><span class="price-tag"><?= number_format($ad['new_p']) ?>đ</span></td>
                        <td>
                            <span class="badge <?= $ad['status']=='published'?'bg-success':'bg-warning' ?>">
                                <?= $ad['status']=='published' ? '● Đã đăng' : '○ Nháp' ?>
                            </span>
                        </td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary me-1" onclick='fillEditForm(<?= json_encode($ad) ?>)' title="Sửa">
                                <i class="fas fa-pen"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-danger" onclick="deletePost(<?= $ad['id'] ?>, '<?= addslashes($ad['title']) ?>')" title="Xóa">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    // --- KHỞI TẠO SWEETALERT SAU KHI LOAD TRANG ---
    document.addEventListener('DOMContentLoaded', function() {
        <?php if (isset($_SESSION['msg'])): ?>
            Swal.fire({
                icon: '<?= $_SESSION['status'] ?>',
                title: '<?= $_SESSION['status'] == "success" ? "Thành công!" : "Lỗi!" ?>',
                text: '<?= $_SESSION['msg'] ?>',
                confirmButtonColor: '#16a34a',
                timerProgressBar: true
            });
            <?php unset($_SESSION['msg']); unset($_SESSION['status']); ?>
        <?php endif; ?>
    });

    // 1. EDITOR TinyMCE
    tinymce.init({ 
        selector: '#editor', 
        height: 550, 
        plugins: 'lists link image table code media autoresize', 
        toolbar: 'undo redo | formatselect | bold italic forecolor | alignleft aligncenter alignright | bullist numlist | image table | code',
        content_style: 'img { max-width: 100%; height: auto; border-radius: 5px; }'
    });

    // 2. LOGIC DANH MỤC
    let categoriesData = [
        { name: "Laptop Dell", subs: ["Dell Latitude", "Dell Precision", "Dell XPS", "Dell Insprision", "Dell Vostro"] },
        { name: "Laptop HP", subs: ["HP Elitebook", "HP Probook", "HP Victus"] },
        //{ name: "Acer", subs: },
        { name: "Asus", subs: ['Asus VivoBook'] },
        { name: "Surface", subs: ['Surface Go 2'] },
        { name: "Thinkpad", subs: ["X1 Carbon", "T-Series"] },
        { name: "Lenovo", subs: ["Lenovo Gaming"] },
        { name: "MSI", subs: ["MSI Gaming"] }
    ];

    function renderCategories() {
        const tree = document.getElementById('catTree');
        const sel = document.getElementById('parentCatSelect');
        let html = '<ul>';
        let selHtml = '<option value="-1">-- Danh mục chính --</option>';
        categoriesData.forEach((cat, idx) => {
            html += `<li><input type="checkbox" name="cats[]" value="${cat.name}" id="c${idx}"> <label class="cat-label" for="c${idx}">${cat.name}</label>`;
            selHtml += `<option value="${idx}">${cat.name}</option>`;
            if(cat.subs.length > 0) {
                html += '<ul>';
                cat.subs.forEach((sub, sIdx) => {
                    html += `<li><input type="checkbox" name="cats[]" value="${sub}" id="s${idx}_${sIdx}"> <label class="cat-label" for="s${idx}_${sIdx}">${sub}</label></li>`;
                });
                html += '</ul>';
            }
            html += '</li>';
        });
        tree.innerHTML = html + '</ul>';
        sel.innerHTML = selHtml;
    }

    function addNewCategory() {
        const name = document.getElementById('newCatName').value;
        const parent = document.getElementById('parentCatSelect').value;
        if(!name) return;
        if(parent == "-1") categoriesData.push({name: name, subs: []});
        else categoriesData[parent].subs.push(name);
        document.getElementById('newCatName').value = ""; renderCategories();
    }

    // 3. TAGS
    let currentTags = [];
    function addTag(manual = null) {
        const inp = document.getElementById('tagInp');
        let val = manual || inp.value;
        if(!val) return;
        val.split(',').forEach(t => {
            let clean = t.trim();
            if(clean && !currentTags.includes(clean)) currentTags.push(clean);
        });
        inp.value = ""; renderTags();
    }
    function removeTag(t) { currentTags = currentTags.filter(i => i !== t); renderTags(); }
    function renderTags() {
        const box = document.getElementById('selectedTags');
        box.innerHTML = currentTags.map(t => `<span class="tag-item">${t} <i class="fas fa-times" onclick="removeTag('${t}')"></i></span>`).join('');
        document.getElementById('tagsHiddenInp').value = currentTags.join(',');
    }

    // 4. UTILS & GALLERY
    function calc() {
        const o = parseFloat(document.getElementById('oldP').value) || 0;
        const n = parseFloat(document.getElementById('newP').value) || 0;
        document.getElementById('saleTag').innerText = (o > n && o > 0) ? `-${Math.round((o - n) / o * 100)}%` : "0%";
    }

    new Sortable(document.getElementById('gallery-sortable'), {
        animation: 150,
        onEnd: function() { syncExisting(); updateMainBadge(); }
    });

    // Preview ảnh ngay khi chọn file
    document.getElementById('fileInp').onchange = function() {
        // Xóa ảnh cũ nếu đang add mới
        document.getElementById('gallery-sortable').innerHTML = '';
        document.getElementById('existingImagesInp').value = '';
        Array.from(this.files).forEach((file, idx) => {
            const reader = new FileReader();
            reader.onload = e => {
                const div = document.createElement('div');
                div.className = 'gallery-item';
                div.innerHTML = `
                    <button type="button" class="btn-del-img" onclick="this.parentElement.remove();updateMainBadge()">×</button>
                    <img src="${e.target.result}" alt="preview">
                    <div class="main-img-badge">ẢNH CHÍNH</div>`;
                document.getElementById('gallery-sortable').appendChild(div);
                updateMainBadge();
            };
            reader.readAsDataURL(file);
        });
    };

    function updateMainBadge() {
        document.querySelectorAll('#gallery-sortable .main-img-badge').forEach((b,i) => {
            b.style.display = i === 0 ? 'block' : 'none';
        });
    }

    function fillEditForm(data) {
        window.scrollTo({top: 0, behavior: 'smooth'});
        document.getElementById('postIdInput').value = data.id;
        document.getElementById('postTitle').value = data.title;
        document.getElementById('oldP').value = data.old_p;
        document.getElementById('newP').value = data.new_p;

        // Chờ TinyMCE sẵn sàng rồi mới set content
        if (tinymce.get('editor')) {
            tinymce.get('editor').setContent(data.content || '');
        } else {
            tinymce.on('AddEditor', function(e) {
                if (e.editor.id === 'editor') e.editor.setContent(data.content || '');
            });
        }

        currentTags = data.tags ? data.tags.split(',').filter(t=>t) : [];
        renderTags(); calc();

        // ── KHÔI PHỤC DANH MỤC ─────────────────────────────────
        renderCategories(); // vẽ lại cây để đảm bảo DOM đồng bộ
        const savedCats = data.categories ? data.categories.split(',').map(s => s.trim()).filter(Boolean) : [];
        document.querySelectorAll('#catTree input[type="checkbox"]').forEach(cb => {
            cb.checked = savedCats.includes(cb.value);
        });

        // ── KHÔI PHỤC ẢNH ──────────────────────────────────────
        const gallery = document.getElementById('gallery-sortable');
        gallery.innerHTML = '';
        // Reset file input để tránh giữ ảnh cũ từ lần trước
        const fileInp = document.getElementById('fileInp');
        fileInp.value = '';

        let imgs = [];
        try { imgs = JSON.parse(data.images_json || '[]'); } catch(e) {}
        if (!imgs.length && data.image) imgs = [data.image];

        if (imgs.length) {
            document.getElementById('existingImagesInp').value = JSON.stringify(imgs);
            imgs.forEach((src, i) => {
                const div = document.createElement('div');
                div.className = 'gallery-item';
                // Ảnh lưu dưới root/images/ → từ admin/ thêm ../
                const displaySrc = src.startsWith('http') ? src : '../' + src;
                div.dataset.imgPath = src; // lưu path gốc để syncExisting dùng
                div.innerHTML = `
                    <button type="button" class="btn-del-img" onclick="this.parentElement.remove();syncExisting()">×</button>
                    <img src="${displaySrc}" alt="ảnh sản phẩm">
                    <div class="main-img-badge">ẢNH CHÍNH</div>`;
                gallery.appendChild(div);
            });
            updateMainBadge();
        } else {
            document.getElementById('existingImagesInp').value = '';
        }
    }

    function syncExisting() {
        // Cập nhật lại hidden field khi xóa / sắp xếp ảnh cũ
        const imgs = [];
        document.querySelectorAll('#gallery-sortable .gallery-item').forEach(item => {
            // Ưu tiên dùng data-img-path (path gốc đã lưu DB)
            if (item.dataset.imgPath) {
                imgs.push(item.dataset.imgPath);
            } else {
                // Ảnh mới preview từ FileReader → không có path gốc, bỏ qua
                // (sẽ được upload thực sự khi submit)
            }
        });
        document.getElementById('existingImagesInp').value = JSON.stringify(imgs);
        updateMainBadge();
    }

    function resetFormForNew() {
        document.getElementById('adForm').reset();
        document.getElementById('postIdInput').value = "";
        document.getElementById('existingImagesInp').value = "";
        document.getElementById('gallery-sortable').innerHTML = '';
        document.getElementById('fileInp').value = '';
        if (tinymce.get('editor')) tinymce.get('editor').setContent('');
        // Bỏ check tất cả danh mục
        document.querySelectorAll('#catTree input[type="checkbox"]').forEach(cb => cb.checked = false);
        currentTags = []; renderTags(); calc();
    }

    // XỬ LÝ XÓA BẰNG SWEETALERT
    function deletePost(id, title) {
        Swal.fire({
            title: 'Bạn có chắc chắn?',
            text: `Sản phẩm "${title}" sẽ bị xóa vĩnh viễn!`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#16a34a',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Bạn muốn xóa?',
            cancelButtonText: 'Hủy'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = `advertising.php?action=delete&id=${id}`;
            }
        });
    }

    function clearAllImages() {
        Swal.fire({
            title: 'Xóa toàn bộ ảnh?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Xóa hết'
        }).then((result) => {
            if (result.isConfirmed) document.getElementById('gallery-sortable').innerHTML = "";
        });
    }

    renderCategories();
</script>
</body>
</html>