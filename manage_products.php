<?php
// fatura/manage_products.php
// إدارة المنتجات: إضافة، تعديل، حذف، عرض (CRUD)

require_once 'auth_check.php';
// التحقق: يجب أن يكون super_admin
check_auth('super_admin'); 

require_once 'database/db_conn.php'; 

$message = '';
$edit_product = null; // لتخزين بيانات المنتج المراد تعديله

// --------------------------------------------------
// أ. معالجة الإضافة/التعديل (Add/Update)
// --------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // جلب البيانات من النموذج
    $name = trim($_POST['name']);
    $cost_price = floatval($_POST['cost_price']);
    $selling_price = floatval($_POST['selling_price']);
    $stock_quantity = intval($_POST['stock_quantity']);
    $barcode = trim($_POST['barcode']);
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    
    // التأكد من أن السعر لا يقل عن صفر وأن سعر البيع لا يقل عن سعر التكلفة
    if ($cost_price < 0 || $selling_price < 0 || $selling_price < $cost_price) {
        $message = "❌ خطأ: يجب أن تكون الأسعار موجبة، وسعر البيع لا يجب أن يقل عن سعر التكلفة.";
    } elseif ($product_id > 0) {
        // --- تعديل المنتج الحالي ---
        $sql = "UPDATE products SET name=?, cost_price=?, selling_price=?, stock_quantity=?, barcode=? WHERE id=?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "sddisi", $name, $cost_price, $selling_price, $stock_quantity, $barcode, $product_id);

        if (mysqli_stmt_execute($stmt)) {
            $message = "✅ تم تحديث المنتج بنجاح.";
        } else {
            $message = "❌ خطأ في التحديث: " . mysqli_error($conn);
        }
        mysqli_stmt_close($stmt);
    } else {
        // --- إضافة منتج جديد ---
        $sql = "INSERT INTO products (name, cost_price, selling_price, stock_quantity, barcode) VALUES (?, ?, ?, ?, ?)";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "sddis", $name, $cost_price, $selling_price, $stock_quantity, $barcode);

        if (mysqli_stmt_execute($stmt)) {
            $message = "✅ تم إضافة المنتج الجديد بنجاح.";
        } else {
            // خطأ شائع هنا هو تكرار الباركود
            $message = "❌ خطأ في الإضافة: " . (mysqli_errno($conn) == 1062 ? "رمز الباركود موجود بالفعل." : mysqli_error($conn));
        }
        mysqli_stmt_close($stmt);
    }
}

// --------------------------------------------------
// ب. معالجة عمليات الحذف/طلب التعديل (Delete/Fetch for Edit)
// --------------------------------------------------
if (isset($_GET['action']) && isset($_GET['id'])) {
    $action = $_GET['action'];
    $id = intval($_GET['id']);

    if ($action == 'delete') {
        // --- حذف المنتج ---
        $sql = "DELETE FROM products WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $id);

        if (mysqli_stmt_execute($stmt)) {
            $message = "✅ تم حذف المنتج بنجاح.";
        } else {
            // إذا كان المنتج مرتبط بفواتير، سيمنع FOREIGN KEY عملية الحذف
            $message = "❌ لا يمكن حذف المنتج. المنتج مرتبط بعمليات بيع سابقة.";
        }
        mysqli_stmt_close($stmt);
    } elseif ($action == 'edit') {
        // --- جلب بيانات المنتج المراد تعديله ---
        $sql = "SELECT * FROM products WHERE id = ?";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "i", $id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        
        if ($row = mysqli_fetch_assoc($result)) {
            $edit_product = $row;
        }
        mysqli_stmt_close($stmt);
    }
    // لإعادة تحميل الصفحة بعد الحذف أو التعديل، منع إرسال بيانات GET مرة أخرى
    if ($action == 'delete') {
         header("Location: manage_products.php?msg=" . urlencode($message));
         exit;
    }
}

// عرض رسالة التأكيد بعد التوجيه
if (isset($_GET['msg'])) {
    $message = urldecode($_GET['msg']);
}

// --------------------------------------------------
// ج. جلب جميع المنتجات للعرض في الجدول
// --------------------------------------------------
$products = [];
$sql_fetch = "SELECT * FROM products ORDER BY name ASC";
$result_fetch = mysqli_query($conn, $sql_fetch);

if ($result_fetch) {
    while ($row = mysqli_fetch_assoc($result_fetch)) {
        $products[] = $row;
    }
}

mysqli_close($conn); 
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>إدارة المخزون والمنتجات</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        /* ==================================== */
        /* تصميم مودرن بألوان هادئة ومريحة للعين */
        /* ==================================== */
        body { 
            font-family: 'Cairo', Tahoma, sans-serif; 
            background-color: #f4f6f9; /* خلفية ناعمة جداً */
            margin: 0; padding: 0; 
            display: flex; 
            color: #343a40; 
        }
        
        /* القائمة الجانبية (Sidebar) - أنيقة وداكنة */
        .sidebar { 
            width: 260px; 
            background-color: #2c3e50; /* أزرق داكن/فحمي هادئ */
            color: white; 
            height: 100vh; 
            position: fixed; 
            padding: 25px 20px;
            box-shadow: 3px 0 15px rgba(0,0,0,0.15);
        }
        .sidebar h3 { border-bottom: 2px solid #4a627a; padding-bottom: 15px; margin-bottom: 20px; }
        .sidebar p { color: #bdc3c7; font-size: 0.9em; }
        .sidebar a { 
            display: block; 
            padding: 12px 10px; 
            color: #ecf0f1; 
            text-decoration: none; 
            border-radius: 6px; 
            margin-bottom: 8px; 
            transition: background-color 0.3s, color 0.3s;
            font-weight: 600;
        }
        .sidebar a:hover { background-color: #34495e; color: white; }
        
        .main-content { 
            margin-right: 290px; 
            padding: 35px 30px; 
            flex-grow: 1; 
        }
        .main-content h1 { color: #1e3a8a; margin-top: 0; margin-bottom: 30px; }

        /* حاويات النماذج والجداول (البطاقات) */
        .form-container, .table-container { 
            background-color: white; 
            padding: 30px; 
            border-radius: 12px; 
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08); 
            margin-bottom: 30px; 
            border-top: 5px solid #1abc9c; /* شريط أخضر نعناعي للمخزون */
        }
        .table-container { border-top: 5px solid #7f8c8d; /* شريط رمادي للجداول */ }
        .form-container h2, .table-container h2 { color: #2c3e50; margin-top: 0; border-bottom: 1px solid #ecf0f1; padding-bottom: 10px; margin-bottom: 20px; }

        /* حقول الإدخال */
        input[type="text"], input[type="number"] { 
            padding: 10px; 
            margin: 5px 0; 
            border: 1px solid #bdc3c7; 
            border-radius: 6px; 
            width: 100%; 
            box-sizing: border-box; 
            transition: border-color 0.3s, box-shadow 0.3s;
        }
        input[type="text"]:focus, input[type="number"]:focus {
            border-color: #1abc9c;
            box-shadow: 0 0 0 3px rgba(26, 188, 156, 0.2);
            outline: none;
        }
        .form-group { margin-bottom: 20px; }
        label { font-weight: 600; display: block; margin-bottom: 5px; color: #34495e; }

        /* الأزرار */
        button, .btn-warning, .btn-danger { 
            padding: 12px 25px; 
            border: none; 
            border-radius: 6px; 
            cursor: pointer; 
            font-weight: 600;
            transition: opacity 0.3s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        button { background-color: #1abc9c; color: white; } /* زر الإضافة/الحفظ - أخضر هادئ */
        button:hover { opacity: 0.9; }
        
        .btn-warning { background-color: #f39c12; color: white; padding: 8px 15px; } /* زر التعديل */
        .btn-warning:hover { opacity: 0.9; }
        
        .btn-danger { background-color: #e74c3c; color: white; padding: 8px 15px; } /* زر الحذف */
        .btn-danger:hover { opacity: 0.9; }
        .btn-action-group a { margin-left: 10px; } /* تباعد بين أزرار الإجراء */

        /* رسائل النظام */
        .message { 
            padding: 15px; 
            margin-bottom: 25px; 
            border-radius: 8px; 
            font-weight: 600;
            border-left: 5px solid; 
        }
        .success { background-color: #e8f9ed; color: #27ae60; border-color: #2ecc71; }
        .error { background-color: #fbebeb; color: #721c24; border-color: #e74c3c; }
        
        /* الجدول */
        table { 
            width: 100%; 
            border-collapse: separate; 
            border-spacing: 0; 
            margin-top: 20px; 
            border-radius: 8px; 
            overflow: hidden; 
        }
        th, td { 
            padding: 12px 15px; 
            text-align: right; 
            border-bottom: 1px solid #ecf0f1; 
            font-size: 0.95em;
        }
        th { 
            background-color: #ecf0f1; 
            color: #2c3e50; 
            font-weight: 700; 
        }
        tr:last-child td { border-bottom: none; }
        
        /* تمييز الصفوف ذات المخزون المنخفض (استنادًا إلى ستايل PHP المضمن سابقًا) */
        tr[style*="#fff3cd"] { 
            background-color: #fef3c7 !important; /* لون أصفر ناعم */
            color: #92400e; 
            font-weight: 600;
        }
        tr[style*="#fff3cd"] td a { font-weight: 400; } /* لا يؤثر على الأزرار */

    </style>
</head>
<body>
    <div class="sidebar">
        <h3>👋 مرحباً، <?php echo $_SESSION['full_name']; ?></h3>
        <p>(<?php echo $_SESSION['role']; ?>)</p>
        <hr style="border-top: 1px solid #4a627a;">
        <a href="dashboard_admin.php">الرئيسية</a>
        <a href="manage_users.php">إدارة المستخدمين</a>
        <a href="manage_products.php">إدارة المخزون (المنتجات)</a>
        <a href="reports.php">التقارير المالية والأرباح</a>
        <a href="view_log.php">يوميات العمال</a>
        <hr style="border-top: 1px solid #4a627a;">
        <a href="logout.php">تسجيل الخروج</a>
    </div>

    <div class="main-content">
        <h1>📦 إدارة المخزون والمنتجات</h1>
        
        <?php if ($message): ?>
            <div class="message <?php echo strpos($message, '✅') !== false ? 'success' : 'error'; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="form-container">
            <h2><?php echo $edit_product ? 'تعديل المنتج: ' . htmlspecialchars($edit_product['name']) : 'إضافة منتج جديد'; ?></h2>
            <form method="post" action="manage_products.php">
                <?php if ($edit_product): ?>
                    <input type="hidden" name="product_id" value="<?php echo htmlspecialchars($edit_product['id']); ?>">
                <?php endif; ?>
                
                <div class="form-group">
                    <label for="name">اسم المنتج:</label>
                    <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($edit_product['name'] ?? ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="cost_price">سعر التكلفة (ل/حساب الربح):</label>
                    <input type="number" id="cost_price" name="cost_price" step="0.01" min="0" value="<?php echo htmlspecialchars($edit_product['cost_price'] ?? 0.00); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="selling_price">سعر البيع (ل/العميل):</label>
                    <input type="number" id="selling_price" name="selling_price" step="0.01" min="0" value="<?php echo htmlspecialchars($edit_product['selling_price'] ?? 0.00); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="stock_quantity">الكمية في المخزون:</label>
                    <input type="number" id="stock_quantity" name="stock_quantity" min="0" value="<?php echo htmlspecialchars($edit_product['stock_quantity'] ?? 0); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="barcode">رمز الباركود (اختياري، فريد):</label>
                    <input type="text" id="barcode" name="barcode" value="<?php echo htmlspecialchars($edit_product['barcode'] ?? ''); ?>">
                </div>
                
                <div class="btn-action-group">
                    <button type="submit"><?php echo $edit_product ? 'حفظ التعديلات' : 'إضافة المنتج'; ?></button>
                    <?php if ($edit_product): ?>
                        <a href="manage_products.php" class="btn-warning">إلغاء التعديل</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="table-container">
            <h2>قائمة المنتجات الحالية (<?php echo count($products); ?>)</h2>
            <table>
                <thead>
                    <tr>
                        <th>الاسم</th>
                        <th>التكلفة</th>
                        <th>البيع</th>
                        <th>الكمية</th>
                        <th>الربح المتوقع للوحدة</th>
                        <th>الباركود</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products)): ?>
                        <tr><td colspan="7" style="text-align: center;">لا توجد منتجات مسجلة بعد.</td></tr>
                    <?php else: ?>
                        <?php foreach ($products as $product): ?>
                            <?php $profit_per_unit = $product['selling_price'] - $product['cost_price']; ?>
                            <tr <?php if ($product['stock_quantity'] <= 10 && $product['stock_quantity'] > 0) echo 'style="background-color: #fff3cd;"'; ?>>
                                <td><?php echo htmlspecialchars($product['name']); ?></td>
                                <td><?php echo number_format($product['cost_price'], 2); ?></td>
                                <td><?php echo number_format($product['selling_price'], 2); ?></td>
                                <td><?php echo htmlspecialchars($product['stock_quantity']); ?></td>
                                <td><?php echo number_format($profit_per_unit, 2); ?></td>
                                <td><?php echo htmlspecialchars($product['barcode']); ?></td>
                                <td class="btn-action-group">
                                    <a href="manage_products.php?action=edit&id=<?php echo $product['id']; ?>" class="btn-warning">تعديل</a>
                                    <a href="manage_products.php?action=delete&id=<?php echo $product['id']; ?>" 
                                       onclick="return confirm('هل أنت متأكد من حذف المنتج <?php echo htmlspecialchars($product['name']); ?>؟')" 
                                       class="btn-danger">حذف</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>