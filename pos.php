<?php
// fatura/pos.php
// نظام نقطة البيع (Point of Sale)

require_once 'auth_check.php';
// التحقق: يمكن للموظف والمشرف الدخول هنا
check_auth('employee'); 

require_once 'database/db_conn.php'; 

$message = '';
$products_list = []; // قائمة المنتجات للعرض والاختيار في الواجهة
$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];

// --------------------------------------------------
// جلب قائمة المنتجات المتاحة حالياً
// --------------------------------------------------
$sql_products = "SELECT id, name, selling_price, stock_quantity FROM products WHERE stock_quantity > 0 ORDER BY name ASC";
$result_products = mysqli_query($conn, $sql_products);
if ($result_products) {
    while ($row = mysqli_fetch_assoc($result_products)) {
        $products_list[] = $row;
    }
}

// --------------------------------------------------
// معالجة طلب البيع (POST Request)
// --------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['cart_items'])) {
    
    $cart_items = json_decode($_POST['cart_items'], true);
    
    if (empty($cart_items)) {
        $message = "❌ سلة المبيعات فارغة. يرجى إضافة منتجات.";
        goto display_form; 
    }

    // 1. بدء المعاملة (START TRANSACTION)
    mysqli_begin_transaction($conn);
    $new_invoice_id = 0;
    $total_amount = 0;

    try {
        // 2. إجمالي مبلغ الفاتورة
        foreach ($cart_items as $item) {
            $total_amount += $item['quantity'] * $item['price'];
        }
        
        // 3. INSERT في جدول invoices (رأس الفاتورة)
        $sql_invoice = "INSERT INTO invoices (user_id, total_amount) VALUES (?, ?)";
        $stmt_invoice = mysqli_prepare($conn, $sql_invoice);
        mysqli_stmt_bind_param($stmt_invoice, "id", $user_id, $total_amount);
        if (!mysqli_stmt_execute($stmt_invoice)) {
            throw new Exception("فشل في إنشاء رأس الفاتورة.");
        }
        $new_invoice_id = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt_invoice);

        // 4. INSERT في جدول invoice_details و UPDATE جدول products
        $log_action = "فاتورة جديدة رقم #$new_invoice_id. المنتجات: ";
        
        foreach ($cart_items as $item) {
            $product_id = $item['id'];
            $quantity_sold = $item['quantity'];
            $unit_price = $item['price'];
            $product_name = $item['name'];

            // أ. التحقق من المخزون الكافي
            $check_stock_sql = "SELECT stock_quantity FROM products WHERE id = ?";
            $stmt_check = mysqli_prepare($conn, $check_stock_sql);
            mysqli_stmt_bind_param($stmt_check, "i", $product_id);
            mysqli_stmt_execute($stmt_check);
            $result_check = mysqli_stmt_get_result($stmt_check);
            $stock_row = mysqli_fetch_assoc($result_check);
            mysqli_stmt_close($stmt_check);
            
            if ($stock_row['stock_quantity'] < $quantity_sold) {
                 throw new Exception("نفاد المخزون للمنتج $product_name. الكمية المتبقية: " . $stock_row['stock_quantity']);
            }

            // ب. INSERT في جدول invoice_details
            $sql_details = "INSERT INTO invoice_details (invoice_id, product_id, quantity_sold, unit_price) VALUES (?, ?, ?, ?)";
            $stmt_details = mysqli_prepare($conn, $sql_details);
            mysqli_stmt_bind_param($stmt_details, "iiid", $new_invoice_id, $product_id, $quantity_sold, $unit_price);
            if (!mysqli_stmt_execute($stmt_details)) {
                 throw new Exception("فشل في إدراج تفاصيل المنتج $product_name.");
            }
            mysqli_stmt_close($stmt_details);

            // ج. UPDATE جدول products لتقليل stock_quantity
            $sql_update_stock = "UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?";
            $stmt_update = mysqli_prepare($conn, $sql_update_stock);
            mysqli_stmt_bind_param($stmt_update, "ii", $quantity_sold, $product_id);
            if (!mysqli_stmt_execute($stmt_update)) {
                 throw new Exception("فشل في تحديث مخزون المنتج $product_name.");
            }
            mysqli_stmt_close($stmt_update);
            
            $log_action .= "$product_name ($quantity_sold وحدة)؛ ";
        }
        
        // 5. INSERT في جدول employee_log لتسجيل العملية
        $log_action = "عملية بيع مكتملة. $log_action. الإجمالي: " . number_format($total_amount, 2);
        $sql_log = "INSERT INTO employee_log (user_id, action) VALUES (?, ?)";
        $stmt_log = mysqli_prepare($conn, $sql_log);
        mysqli_stmt_bind_param($stmt_log, "is", $user_id, $log_action);
        mysqli_stmt_execute($stmt_log);
        mysqli_stmt_close($stmt_log);

        // 6. إذا تم كل شيء بنجاح: COMMIT
        mysqli_commit($conn);
        $message = "✅ تمت عملية البيع بنجاح. رقم الفاتورة: #$new_invoice_id";
        
        // التوجيه إلى صفحة طباعة الفاتورة
        header("Location: print_invoice.php?invoice_id=$new_invoice_id");
        exit;

    } catch (Exception $e) {
        // إذا حدث أي خطأ: ROLLBACK
        mysqli_rollback($conn);
        $message = "❌ فشل عملية البيع: " . $e->getMessage() . " (تم التراجع عن المعاملة)";
    }
}

// نقطة عرض النموذج بعد فشل المعاملة
display_form:

// --------------------------------------------------
// إغلاق الاتصال وعرض الواجهة
// --------------------------------------------------
mysqli_close($conn); 
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>نقطة البيع (POS)</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ==================================== */
        /* التعديلات الجديدة للتصميم المودرن (POS) */
        /* ==================================== */
        :root {
            --primary-color: #1e3a8a; /* أزرق داكن */
            --secondary-color: #f59e0b; /* برتقالي/ذهبي */
            --bg-light: #f9fafb; /* خلفية فاتحة */
            --bg-dark: #374151; /* خلفية جانبية داكنة */
            --success-color: #10b981; /* أخضر للنجاح */
            --error-color: #ef4444; /* أحمر للخطأ */
        }

        body { 
            font-family: 'Cairo', sans-serif; 
            background-color: var(--bg-light); 
            margin: 0; 
            padding: 0; 
            display: flex; 
            color: #333;
            overflow-x: hidden;
        }
        
        /* القائمة الجانبية (Sidebar) */
        .sidebar { 
            width: 260px; 
            background-color: var(--bg-dark); 
            color: white; 
            height: 100vh; 
            position: fixed; 
            padding: 25px 20px;
            box-shadow: 3px 0 10px rgba(0, 0, 0, 0.15);
        }
        .sidebar h3 { color: #f3f4f6; border-bottom: 1px solid #4b5563; padding-bottom: 15px; margin-bottom: 20px; }
        .sidebar p { color: #d1d5db; font-size: 0.9em; }
        .sidebar a { 
            display: block; 
            padding: 12px 10px; 
            color: #d1d5db; 
            text-decoration: none; 
            border-radius: 6px; 
            margin-bottom: 8px; 
            transition: background-color 0.3s;
            font-weight: 600;
        }
        .sidebar a:hover { background-color: #4b5563; color: white; }
        .sidebar hr { border-top: 1px solid #4b5563; }
        
        /* المحتوى الرئيسي (Main Content) */
        .main-content { 
            margin-right: 260px; 
            padding: 20px; 
            flex-grow: 1; 
            display: flex; 
            gap: 20px; /* المسافة بين اللوحتين */
            width: calc(100% - 260px);
        }
        
        /* لوحات المنتجات والسلة */
        .products-panel, .cart-panel { 
            background-color: white; 
            padding: 25px; 
            border-radius: 12px; 
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05); 
            height: 95vh; /* ليغطي معظم الشاشة */
            overflow-y: auto;
        }
        .products-panel { width: 65%; }
        .cart-panel { 
            width: 35%; 
            display: flex; 
            flex-direction: column;
            justify-content: space-between; /* لدفع الزر للأسفل */
        }
        
        h2 { color: var(--primary-color); margin-top: 0; margin-bottom: 20px; font-weight: 800; border-bottom: 2px solid #e5e7eb; padding-bottom: 10px; }
        
        /* تصميم حقل البحث */
        #product-search { 
            width: 100%; 
            padding: 12px; 
            margin-bottom: 20px; 
            border: 2px solid #d1d5db; 
            border-radius: 8px; 
            box-sizing: border-box; 
            transition: border-color 0.3s;
            font-size: 1.1em;
        }
        #product-search:focus { border-color: var(--secondary-color); outline: none; }
        
        /* تصميم المنتجات (الأزرار/البطاقات) */
        .product-item { 
            padding: 15px; 
            border: 1px solid #e5e7eb; 
            background-color: #fff;
            margin-bottom: 12px; 
            border-radius: 8px; 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            cursor: pointer;
            transition: background-color 0.2s, box-shadow 0.2s;
        }
        .product-item:hover { 
            background-color: #f0f4ff; 
            box-shadow: 0 2px 8px rgba(30, 58, 138, 0.1);
        }
        .product-info strong { font-size: 1.1em; color: var(--primary-color); }
        .product-info small { display: block; color: #6b7280; font-size: 0.9em; margin-top: 3px; }
        .product-item button { 
            background-color: var(--success-color); 
            color: white; 
            border: none; 
            padding: 8px 15px; 
            border-radius: 6px; 
            cursor: pointer; 
            font-weight: 700;
            transition: background-color 0.3s;
        }
        .product-item button:hover { background-color: #059669; }
        
        /* تنسيق السلة */
        .cart-item { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            padding: 15px 0; 
            border-bottom: 1px solid #f3f4f6; 
            font-size: 1.05em;
        }
        .cart-item:last-child { border-bottom: none; }

        .quantity-controls { 
            display: flex; 
            align-items: center; 
            flex-wrap: nowrap; 
            min-width: 150px;
        }
        .qty-btn { 
            background-color: #007bff; color: white; border: none; width: 30px; height: 30px; 
            border-radius: 50%; cursor: pointer; font-weight: bold; margin: 0 5px; 
            display: flex; justify-content: center; align-items: center; font-size: 1.2em;
            transition: background-color 0.2s;
        }
        .qty-btn:hover { background-color: #0056b3; }
        .qty-input {
            width: 50px; 
            padding: 8px 5px;
            margin: 0;
            border: 1px solid #ccc;
            border-radius: 4px;
            text-align: center;
            font-weight: 600;
            font-size: 1em;
        }
        .cart-item strong { margin-right: 15px; color: var(--primary-color); }

        /* منطقة الإجمالي */
        .total-box { 
            margin-top: 30px; 
            padding: 20px; 
            background-color: var(--primary-color); 
            color: white;
            border-radius: 8px; 
            font-size: 1.3em; 
            font-weight: 700; 
            text-align: center;
        }
        #cart-total-display {
            display: block;
            font-size: 2.5em; /* تكبير الإجمالي */
            font-weight: 800;
            margin-top: 10px;
        }

        /* زر الدفع (Checkout) */
        .checkout-btn { 
            background-color: var(--success-color); 
            color: white; 
            padding: 18px; 
            border: none; 
            border-radius: 8px; 
            cursor: pointer; 
            width: 100%; 
            font-size: 1.2em; 
            margin-top: 20px; 
            font-weight: 800;
            transition: background-color 0.3s;
        }
        .checkout-btn:hover:not(:disabled) { background-color: #059669; }
        .checkout-btn:disabled { 
            background-color: #ccc; 
            cursor: not-allowed; 
        }
        
        /* رسائل النظام */
        .message { 
            padding: 15px; 
            margin-bottom: 20px; 
            border-radius: 8px; 
            font-weight: 600;
            font-size: 1.1em;
            width: 100%;
            margin-left: 20px; /* ليتناسب مع محاذاة اللوحات */
        }
        .success { background-color: #d1fae5; color: var(--success-color); border: 1px solid #a7f3d0; }
        .error { background-color: #fee2e2; color: var(--error-color); border: 1px solid #fca5a5; }
    </style>
</head>
<body>
    <div class="sidebar">
        <h3>👋 مرحباً، <?php echo $full_name; ?></h3>
        <p style="color: #adb5bd;">(<?php echo $_SESSION['role']; ?>)</p>
        <hr>
        <?php if ($_SESSION['role'] == 'super_admin'): ?>
            <a href="dashboard_admin.php">لوحة المشرف العام</a>
        <?php else: ?>
            <a href="pos.php">نقطة البيع (POS)</a>
            <a href="employee_sales.php">مبيعاتي</a>
            <a href="return_process.php">معالجة الإرجاع</a>
        <?php endif; ?>
        <hr>
        <a href="logout.php">تسجيل الخروج</a>
    </div>

    <div class="main-content">
        <?php if ($message): ?>
            <div class="message <?php echo strpos($message, '✅') !== false ? 'success' : 'error'; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="products-panel">
            <h2>🛒 المنتجات المتاحة</h2>
            
            <input type="text" id="product-search" placeholder="ابحث باسم المنتج أو الباركود..." onkeyup="filterProducts()">

            <div id="product-list">
                <?php foreach ($products_list as $product): ?>
                <div class="product-item" 
                     data-id="<?php echo $product['id']; ?>" 
                     data-name="<?php echo htmlspecialchars($product['name']); ?>" 
                     data-price="<?php echo $product['selling_price']; ?>"
                     data-stock="<?php echo $product['stock_quantity']; ?>"
                     onclick="addToCart(this)"> <span class="product-info">
                        <strong><?php echo htmlspecialchars($product['name']); ?></strong> 
                        <small>السعر: <?php echo number_format($product['selling_price'], 2); ?> ر.س | 
                        متبقي: <?php echo $product['stock_quantity']; ?></small>
                    </span>
                    <button>إضافة (+1)</button>
                </div>
                <?php endforeach; ?>
                <?php if (empty($products_list)): ?>
                    <p style="padding: 20px; background-color: #fff7ed; border-radius: 8px;">لا توجد منتجات متاحة للبيع حالياً.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="cart-panel">
            <h2>🛍️ سلة المبيعات</h2>
            
            <div id="cart-items-container" style="flex-grow: 1; margin-bottom: 20px;">
                <p style="color:#6c757d;">السلة فارغة.</p>
            </div>
            
            <div>
                <div class="total-box">
                    الإجمالي المطلوب
                    <span id="cart-total-display">0.00 ر.س</span> 
                </div>

                <form method="post" action="pos.php" onsubmit="return submitCart()">
                    <input type="hidden" name="cart_items" id="cart-items-input">
                    <button type="submit" class="checkout-btn" id="checkout-button" disabled>تنفيذ عملية البيع (F10)</button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        let cart = {}; 

        // 1. منطق فلترة المنتجات
        function filterProducts() {
            const input = document.getElementById('product-search').value.toLowerCase();
            const productList = document.getElementById('product-list');
            const items = productList.getElementsByClassName('product-item');

            for (let i = 0; i < items.length; i++) {
                const name = items[i].dataset.name.toLowerCase();
                if (name.includes(input)) {
                    items[i].style.display = 'flex';
                } else {
                    items[i].style.display = 'none';
                }
            }
        }
        
        // 2. منطق إضافة المنتج للسلة
        function addToCart(itemElement) {
            // يتم استدعاء هذه الدالة إما من زر الإضافة أو من النقر على العنصر نفسه
            const id = itemElement.dataset.id;
            const name = itemElement.dataset.name;
            const price = parseFloat(itemElement.dataset.price);
            const max_stock = parseInt(itemElement.dataset.stock);

            if (!cart[id]) {
                cart[id] = { id, name, price, quantity: 0, max_stock };
            }

            if (cart[id].quantity < max_stock) {
                 cart[id].quantity += 1;
            } else {
                 alert('لا يمكن إضافة المزيد: تم الوصول إلى الحد الأقصى للمخزون المتاح (' + max_stock + ' وحدات).');
            }

            renderCart();
        }
        
        // 3. منطق تغيير الكمية (+ و -)
        function changeQuantity(id, delta) {
            if (!cart[id]) return;
            
            const newQuantity = cart[id].quantity + delta;
            
            setQuantity(id, newQuantity);
        }

        // 4. منطق تعيين الكمية مباشرة
        function setQuantity(id, newQuantity) {
            if (!cart[id]) return;

            newQuantity = parseInt(newQuantity) || 0;
            const max_stock = cart[id].max_stock;

            if (newQuantity <= 0) {
                delete cart[id];
            } else if (newQuantity > max_stock) {
                alert('لا يمكن تعيين الكمية إلى ' + newQuantity + '، الحد الأقصى المتاح هو ' + max_stock + ' وحدات.');
                cart[id].quantity = max_stock; 
            } else {
                cart[id].quantity = newQuantity;
            }
            renderCart();
        }

        // 5. عرض محتوى السلة
        function renderCart() {
            const container = document.getElementById('cart-items-container');
            const totalDisplay = document.getElementById('cart-total-display');
            const checkoutButton = document.getElementById('checkout-button');
            let total = 0;
            let cartHTML = '';

            const cartArray = Object.values(cart);

            if (cartArray.length === 0) {
                container.innerHTML = '<p style="color:#6c757d; text-align: center; margin-top: 50px;">السلة فارغة.</p>';
                checkoutButton.disabled = true;
                totalDisplay.textContent = '0.00 ر.س';
                return;
            }

            cartArray.forEach(item => {
                const itemTotal = item.quantity * item.price;
                total += itemTotal;

                cartHTML += `
                    <div class="cart-item" data-id="${item.id}">
                        <div>
                            ${item.name} 
                            <small class="text-secondary">(${item.price.toFixed(2)} ر.س)</small>
                        </div>
                        <div class="quantity-controls">
                            <button type="button" class="qty-btn" onclick="changeQuantity(${item.id}, 1)">+</button>
                            
                            <input type="number" 
                                class="qty-input" 
                                value="${item.quantity}" 
                                min="1" 
                                max="${item.max_stock}"
                                onchange="setQuantity(${item.id}, this.value)">
                                
                            <button type="button" class="qty-btn" style="background-color: var(--error-color);" onclick="changeQuantity(${item.id}, -1)">-</button>
                            
                            <strong style="min-width: 80px; text-align: left;">${itemTotal.toFixed(2)} ر.س</strong>
                        </div>
                    </div>
                `;
            });

            container.innerHTML = cartHTML;
            totalDisplay.textContent = total.toFixed(2) + ' ر.س';
            checkoutButton.disabled = false;
        }

        // 6. إرسال البيانات
        function submitCart() {
            const cartArray = Object.values(cart).map(item => ({
                id: item.id,
                name: item.name,
                price: item.price,
                quantity: item.quantity
            }));
            
            if (cartArray.length === 0) {
                 alert("يرجى إضافة منتجات إلى السلة قبل إتمام البيع.");
                 return false;
            }

            // تعطيل الزر لمنع الإرسال المزدوج
            document.getElementById('checkout-button').disabled = true;

            document.getElementById('cart-items-input').value = JSON.stringify(cartArray);
            return true;
        }

        // 7. اختصارات لوحة المفاتيح
        document.addEventListener('keydown', (e) => {
            // F10 لتنفيذ عملية البيع
            if (e.key === 'F10') {
                e.preventDefault();
                document.getElementById('checkout-button').click();
            }
            // Esc لإلغاء البحث
            if (e.key === 'Escape') {
                document.getElementById('product-search').value = '';
                filterProducts();
            }
        });

        // تهيئة عند تحميل الصفحة
        window.onload = () => {
             renderCart();
             // التركيز على حقل البحث فور تحميل الصفحة للبدء السريع
             document.getElementById('product-search').focus(); 
        };
    </script>
</body>
</html>