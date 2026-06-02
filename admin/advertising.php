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
            $sql  = "UPDATE ads SET title=?, content=?, old_p=?, new_p=?, status=?, tags=?, categories=?, image=?, images_json=? WHERE id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssddssssi", $title, $content, $old_p, $new_p, $status, $tags, $cats, $main_image, $all_images_json, $post_id);
            // Thêm cột images_json nếu chưa có
            $conn->query("ALTER TABLE ads ADD COLUMN IF NOT EXISTS images_json TEXT");
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
    <title>Nội dung quảng bá - QA Tech</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css" rel="stylesheet">
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.7.0/tinymce.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.all.min.js"></script>

    <style>
        :root { --sb-bg: #1e1e2d; --primary: #4361ee; --body-bg: #f4f7f6; }
        body { font-family: 'Inter', sans-serif; background-color: var(--body-bg); margin: 0; color: #3f4254; }
        .sidebar { width: 250px; height: 100vh; background: var(--sb-bg); position: fixed; left: 0; top: 0; z-index: 1000; }
        .sidebar-logo { padding: 25px; color: #fff; font-weight: 700; border-bottom: 1px solid #2d2d44; text-transform: uppercase; }
        .nav-menu { list-style: none; padding: 20px 0; margin: 0; }
        .nav-link-custom { padding: 12px 25px; display: flex; align-items: center; color: #a2a3b7; text-decoration: none; transition: 0.3s; }
        .nav-link-custom:hover { background: #1b1b28; color: #fff; }
        .nav-link-custom.active { background: var(--primary); color: #fff; }
        .main-wrapper { margin-left: 250px; padding: 30px; min-height: 100vh; }
        .postbox { background: #fff; border-radius: 8px; border: 1px solid #e1e4e8; margin-bottom: 25px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
        .postbox-header { padding: 12px 20px; background: #fcfcfc; border-bottom: 1px solid #e1e4e8; font-weight: 600; font-size: 14px; display: flex; justify-content: space-between; align-items: center; }
        .postbox-body { padding: 20px; }
        #gallery-sortable { display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 10px; min-height: 120px; padding: 10px; border: 1px dashed #ccc; border-radius: 8px; background: #fafafa; }
        .gallery-item { position: relative; border: 2px solid #fff; border-radius: 6px; overflow: hidden; box-shadow: 0 2px 5px rgba(0,0,0,0.1); cursor: grab; }
        .gallery-item img { width: 100%; aspect-ratio: 1; object-fit: cover; }
        .btn-del-img { position: absolute; top: 2px; right: 2px; background: red; color: #fff; border: none; border-radius: 50%; width: 20px; height: 20px; font-size: 10px; cursor: pointer; z-index: 10; }
        .main-img-badge { position: absolute; bottom: 0; width: 100%; background: rgba(67, 97, 238, 0.8); color: #fff; font-size: 9px; text-align: center; padding: 2px 0; display: none; }
        .gallery-item:first-child .main-img-badge { display: block; }
        .category-checklist { border: 1px solid #ddd; background: #fff; height: 220px; overflow-y: auto; padding: 10px; border-radius: 4px; }
        .category-checklist ul { list-style: none; padding-left: 20px; margin: 5px 0; }
        .category-checklist > ul { padding-left: 0; }
        .category-checklist li { margin-bottom: 4px; font-size: 13.5px; }
        .cat-label { cursor: pointer; user-select: none; vertical-align: middle; }
        .tag-cloud { padding: 10px; border: 1px solid #eee; background: #fafafa; border-radius: 4px; margin-top: 10px; }
        .tag-cloud a { color: #2271b1; text-decoration: none; font-size: 13px; margin-right: 8px; display: inline-block; cursor: pointer; }
        .selected-tags { margin-top: 10px; display: flex; flex-wrap: wrap; gap: 5px; }
        .tag-item { background: #f0f0f1; border: 1px solid #c3c4c7; padding: 2px 8px; border-radius: 3px; font-size: 12px; display: flex; align-items: center; }
        .tag-item i { margin-left: 6px; cursor: pointer; color: #646970; }
        .wp-table { width: 100%; background: #fff; border-collapse: collapse; }
        .wp-table th { background: #f8f9fa; padding: 12px; border-bottom: 2px solid #dee2e6; text-align: left; font-size: 13px; }
        .wp-table td { padding: 12px; border-bottom: 1px solid #eee; font-size: 14px; }
    </style>
</head>
<body>

<div class="sidebar">
    <div class="sidebar-logo"><h4 class="fw-bold mb-0 text-white">QA TECH <span class="text-success">ADMIN</span></h4></div>
    <ul class="nav-menu">
        <li><a href="index.php" class="nav-link-custom"><i class="fas fa-home me-2"></i>Tổng quan</a></li>
        <li><a href="advertising.php" class="nav-link-custom active"><i class="fas fa-edit me-2"></i> Bài viết quảng bá</a></li>
        <li><a href="../index.php" class="nav-link-custom"><i class="fas fa-external-link-alt me-2"></i> Xem Website</a></li>
        <li style="margin-top: 50px;"><a href="../logout.php" class="nav-link-custom text-danger"><i class="fas fa-sign-out-alt me-2"></i>Đăng xuất</a></li>
    </ul>
</div>

<div class="main-wrapper">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h3 class="fw-bold">Quản lý nội dung quảng bá</h3>
        <button class="btn btn-primary" onclick="resetFormForNew()"><i class="fas fa-plus me-2"></i>Thêm bài mới</button>
    </div>

    <form id="adForm" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="post_id" id="postIdInput">
        <input type="hidden" name="product_tags" id="tagsHiddenInp"> 
        
        <div class="row">
            <div class="col-lg-8">
                <div class="postbox">
                    <div class="postbox-body">
                        <label class="form-label fw-bold">Tiêu đề sản phẩm</label>
                        <input type="text" id="postTitle" name="title" class="form-control mb-3" placeholder="Nhập tên laptop..." required>
                        <label class="form-label fw-bold">Mô tả chi tiết sản phẩm</label>
                        <textarea id="editor" name="content"></textarea>
                    </div>
                </div>

                <div class="postbox">
                    <div class="postbox-header">
                        <span>Ảnh sản phẩm</span>
                        <button type="button" class="btn btn-sm btn-outline-danger border-0" onclick="clearAllImages()">Xóa all</button>
                    </div>
                    <div class="postbox-body text-center">
                        <input type="file" id="fileInp" name="image_files[]" multiple accept="image/*" class="d-none">
                        <button type="button" class="btn btn-outline-secondary btn-sm mb-3" onclick="document.getElementById('fileInp').click()">
                            <i class="fas fa-upload me-1"></i>Chọn ảnh từ máy tính
                        </button>
                        <p class="text-muted small mb-2">Ảnh đầu tiên sẽ là ảnh đại diện. Kéo để sắp xếp lại thứ tự.</p>
                        <div id="gallery-sortable"></div>
                        <!-- Hidden field lưu thứ tự ảnh đã upload -->
                        <input type="hidden" name="existing_images" id="existingImagesInp">
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="postbox">
                    <div class="postbox-header">Giá</div>
                    <div class="postbox-body">
                        <div class="row g-2 mb-3">
                            <div class="col-6"><label class="small fw-bold">Giá gốc</label><input type="number" id="oldP" name="old_p" class="form-control" oninput="calc()"></div>
                            <div class="col-6"><label class="small fw-bold">Giá bán</label><input type="number" id="newP" name="new_p" class="form-control" oninput="calc()"></div>
                        </div>
                        <div class="p-2 mb-3 border rounded bg-light text-center">
                            <span class="small text-muted d-block">MỨC GIẢM</span>
                            <span id="saleTag" class="h4 fw-bold text-danger">0%</span>
                        </div>
                        <div class="d-grid gap-2">
                            <button type="submit" name="publish" class="btn btn-primary fw-bold">UP BÀI</button>
                            <button type="submit" name="save_draft" class="btn btn-light border">LƯU NHÁP</button>
                        </div>
                    </div>
                </div>

                <div class="postbox">
                    <div class="postbox-header">Danh mục sản phẩm</div>
                    <div class="postbox-body p-0">
                        <div class="category-checklist p-3" id="catTree"></div>
                        <div id="quick-add-box" class="p-2 bg-light border-top d-none">
                            <input type="text" id="newCatName" class="form-control form-control-sm mb-1" placeholder="Tên danh mục...">
                            <select id="parentCatSelect" class="form-select form-select-sm mb-1">
                                <option value="-1">-- Danh mục chính --</option>
                            </select>
                            <button type="button" class="btn btn-secondary btn-sm w-100" onclick="addNewCategory()">Thêm</button>
                        </div>
                        <div class="p-2 px-3 border-top bg-light">
                            <a href="javascript:void(0)" class="small text-decoration-none fw-bold" onclick="document.getElementById('quick-add-box').classList.toggle('d-none')">+ Thêm danh mục mới</a>
                        </div>
                    </div>
                </div>

                <div class="postbox">
                    <div class="postbox-header">Từ khóa tìm kiếm </div>
                    <div class="postbox-body">
                        <div class="input-group input-group-sm mb-2">
                            <input type="text" id="tagInp" class="form-control" placeholder="Tag...">
                            <button type="button" class="btn btn-secondary" onclick="addTag()">Thêm</button>
                        </div>
                        <div class="selected-tags" id="selectedTags"></div>
                        <div class="mt-3">
                            <a href="javascript:void(0)" class="small text-decoration-none" onclick="document.getElementById('popularTags').classList.toggle('d-none')">Gợi ý từ khóa tìm kiếm</a>
                            <div id="popularTags" class="tag-cloud d-none">
                                <a onclick="addTag('laptop cũ')">laptop cũ</a>
                                <a onclick="addTag('laptop giá rẻ')">laptop giá rẻ</a>
                                <a onclick="addTag('dell latitude')">dell latitude</a>
                                <a onclick="addTag('thinkpad')">thinkpad</a>
                                <a onclick="addTag('macbook giá tốt')">macbook giá tốt</a>
                                <a onclick="addTag('macbook giá tốt')">laptop văn phòng giá rẻ</a>
                                <a onclick="addTag('macbook giá tốt')">laptop sinh viên</a>
                                <a onclick="addTag('macbook giá tốt')">laptop gaming</a>
                                <a onclick="addTag('macbook giá tốt')">laptop đồ họa</a>
                                <a onclick="addTag('macbook giá tốt')">card rời</a>
                                <a onclick="addTag('macbook giá tốt')">laptop dell</a>
                                <a onclick="addTag('macbook giá tốt')">laptop cũ giá rẻ</a>
                                <a onclick="addTag('macbook giá tốt')">laptop chơi game</a>
                                <a onclick="addTag('macbook giá tốt')">laptop giá rẻ tại Hải Phòng</a>
                                <a onclick="addTag('macbook giá tốt')">laptop cũ giá rẻ tại Hải Phòng</a>
                                <a onclick="addTag('macbook giá tốt')">laptop HP</a>
                                <a onclick="addTag('macbook giá tốt')">laptop văn phòng</a>



                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <div class="postbox">
        <div class="postbox-header">Danh sách các bài viết</div>
        <div class="postbox-body p-0">
            <table class="wp-table">
                <thead><tr><th>Sản phẩm</th><th>Giá bán</th><th>Trạng thái</th><th class="text-end">Quản lý</th></tr></thead>
                <tbody>
                    <?php foreach($ads_list as $ad): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($ad['title']) ?></strong></td>
                        <td><span class="text-primary fw-bold"><?= number_format($ad['new_p']) ?>đ</span></td>
                        <td><span class="badge <?= $ad['status']=='published'?'bg-success':'bg-warning' ?>"><?= $ad['status'] ?></span></td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary" onclick='fillEditForm(<?= json_encode($ad) ?>)'><i class="fas fa-edit"></i></button>
                            <button class="btn btn-sm btn-outline-danger" onclick="deletePost(<?= $ad['id'] ?>, '<?= addslashes($ad['title']) ?>')"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
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
                confirmButtonColor: '#4361ee',
                timer: 3000,
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
        onEnd: updateMainBadge
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
        tinymce.get('editor').setContent(data.content || '');
        currentTags = data.tags ? data.tags.split(',').filter(t=>t) : [];
        renderTags(); calc();

        // Hiển thị ảnh đã lưu từ trước
        const gallery = document.getElementById('gallery-sortable');
        gallery.innerHTML = '';
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
                div.innerHTML = `
                    <button type="button" class="btn-del-img" onclick="this.parentElement.remove();syncExisting()">×</button>
                    <img src="${displaySrc}" alt="ảnh sản phẩm">
                    <div class="main-img-badge" style="display:${i===0?'block':'none'}">ẢNH CHÍNH</div>`;
                gallery.appendChild(div);
            });
        }
    }

    function syncExisting() {
        // Cập nhật lại hidden field khi xóa ảnh cũ
        const imgs = [];
        document.querySelectorAll('#gallery-sortable img').forEach(img => {
            let s = img.src.replace(window.location.origin, '').replace(/^\//, '').replace(/^\.\.\//, '');
            imgs.push(s);
        });
        document.getElementById('existingImagesInp').value = JSON.stringify(imgs);
        updateMainBadge();
    }

    function resetFormForNew() {
        document.getElementById('adForm').reset();
        document.getElementById('postIdInput').value = "";
        tinymce.get('editor').setContent('');
        currentTags = []; renderTags(); calc();
    }

    // XỬ LÝ XÓA BẰNG SWEETALERT
    function deletePost(id, title) {
        Swal.fire({
            title: 'Bạn có chắc chắn?',
            text: `Sản phẩm "${title}" sẽ bị xóa vĩnh viễn!`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#4361ee',
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