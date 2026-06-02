/* script.js */

document.addEventListener("DOMContentLoaded", function() {
    // --- 1. HIỆU ỨNG TRƯỢT BANNER ---
    const track = document.getElementById('carousel-track');
    const dots = document.querySelectorAll('.carousel-dots .dot');

    // Thêm lệnh if để tránh lỗi nếu trang nào không có banner
    if (track && dots.length > 0) {
        let currentIndex = 0;
        const totalImages = dots.length;

        function moveToSlide(index) {
            track.style.transform = `translateX(-${index * 100}%)`;
            dots.forEach(dot => dot.classList.remove('active'));
            dots[index].classList.add('active');
            currentIndex = index;
        }

        dots.forEach((dot, index) => {
            dot.addEventListener('click', () => {
                moveToSlide(index);
            });
        });

        setInterval(() => {
            let nextIndex = (currentIndex + 1) % totalImages;
            moveToSlide(nextIndex);
        }, 5000);
    }

    // --- 2. HIỆU ỨNG GIỎ HÀNG NHẢY SỐ ---
    const addToCartBtns = document.querySelectorAll('.add-to-cart');
    const cartCountElement = document.querySelector('.fa-shopping-cart').nextElementSibling;

    if(cartCountElement) {
        let currentCartCount = parseInt(cartCountElement.innerText) || 0;

        addToCartBtns.forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault(); // Ngăn trình duyệt nhảy lên đầu trang
                
                currentCartCount++;
                cartCountElement.innerText = currentCartCount;
                
                // Mẹo kích hoạt lại hiệu ứng CSS Animation (bump)
                cartCountElement.classList.remove('cart-bump');
                void cartCountElement.offsetWidth; 
                cartCountElement.classList.add('cart-bump');
            });
        });
    }
});

// --- 3. ĐIỀU KHIỂN BẢNG POPUP (MODAL) THÊM / SỬA SẢN PHẨM ---
// Lưu ý: Các hàm này phải để ngoài DOMContentLoaded thì trên HTML mới gọi được bằng onclick=""

function openAddModal() {
    document.getElementById('modalTitle').innerText = "Thêm Sản Phẩm Mới";
    document.getElementById('form_id').value = "";
    document.getElementById('form_category').value = "pc_gaming_intel"; // Mặc định là danh mục đầu
    document.getElementById('form_code').value = "";
    document.getElementById('form_title').value = "";
    document.getElementById('form_price').value = "";
    document.getElementById('form_oldprice').value = "";
    document.getElementById('form_saving').value = "";
    document.getElementById('form_image').value = "images/"; 
    document.getElementById('form_specs').value = "";
    
    document.getElementById('productModal').style.display = "flex";
}

function openEditModal(btn) {
    document.getElementById('modalTitle').innerText = "Sửa Sản Phẩm";
    
    // Lấy dữ liệu từ nút bấm (bao gồm cả data-category) để điền vào form
    document.getElementById('form_id').value = btn.getAttribute('data-id');
    document.getElementById('form_category').value = btn.getAttribute('data-category') || 'pc_gaming_intel';
    document.getElementById('form_code').value = btn.getAttribute('data-code');
    document.getElementById('form_title').value = btn.getAttribute('data-title');
    document.getElementById('form_price').value = btn.getAttribute('data-price');
    document.getElementById('form_oldprice').value = btn.getAttribute('data-oldprice');
    document.getElementById('form_saving').value = btn.getAttribute('data-saving');
    document.getElementById('form_image').value = btn.getAttribute('data-image');
    document.getElementById('form_warranty').value = btn.getAttribute('data-warranty');
    document.getElementById('form_specs').value = btn.getAttribute('data-specs');
    
    document.getElementById('productModal').style.display = "flex";
}

function closeModal() {
    document.getElementById('productModal').style.display = "none";
}