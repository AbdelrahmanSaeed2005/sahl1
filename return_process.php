<?php
// fatura/return_process.php
// واجهة ومنطق معالجة إرجاع المنتجات

require_once 'auth_check.php';
// التحقق: يمكن للموظف والمشرف الدخول هنا
check_auth('employee'); 

require_once 'database/db_conn.php'; 

$message = '';
$invoice_data = null; 
$details_data = [];   

$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];

// --------------------------------------------------
// أ. معالجة طلب بحث AJAX عن الفواتير حسب المنتج
// --------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == 'search_invoice_by_product' && isset($_GET['product_query'])) {
    
    $query = '%' . trim($_GET['product_query']) . '%';
    
    $sql_invoices = "
        SELECT 
            i.id, i.invoice_date, i.total_amount, u.full_name AS employee_name, p.name AS product_name
        FROM invoices i
        JOIN invoice_details id ON i.id = id.invoice_id
        JOIN products p ON id.product_id = p.id
        JOIN users u ON i.user_id = u.id
        WHERE p.name LIKE ? OR p.barcode LIKE ? 
        GROUP BY i.id 
        ORDER BY i.invoice_date DESC
        LIMIT 10
    ";
    
    $stmt = mysqli_prepare($conn, $sql_invoices);
    mysqli_stmt_bind_param($stmt, "ss", $query, $query);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    $results = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $results[] = $row;
    }
    
    mysqli_stmt_close($stmt);
    mysqli_close($conn);
    
    header('Content-Type: application/json');
    echo json_encode($results);
    exit;
}


// --------------------------------------------------
// ب. معالجة البحث عن الفاتورة برقمها (الأساسي)
// --------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['search_invoice_id'])) {
    $search_id = intval($_POST['search_invoice_id']);

    if ($search_id > 0) {
        $sql_invoice = "SELECT id, total_amount, invoice_date FROM invoices WHERE id = ?";
        $stmt_invoice = mysqli_prepare($conn, $sql_invoice);
        mysqli_stmt_bind_param($stmt_invoice, "i", $search_id);
        mysqli_stmt_execute($stmt_invoice);
        $result_invoice = mysqli_stmt_get_result($stmt_invoice);
        $invoice_data = mysqli_fetch_assoc($result_invoice);
        mysqli_stmt_close($stmt_invoice);

        if ($invoice_data) {
            
            $sql_details = "
                SELECT 
                    id.product_id, id.quantity_sold, id.unit_price, 
                    p.name AS product_name, p.barcode
                FROM invoice_details id
                JOIN products p ON id.product_id = p.id
                WHERE id.invoice_id = ?
            ";
            $stmt_details = mysqli_prepare($conn, $sql_details);
            mysqli_stmt_bind_param($stmt_details, "i", $search_id);
            mysqli_stmt_execute($stmt_details);
            $result_details = mysqli_stmt_get_result($stmt_details);
            
            while ($row = mysqli_fetch_assoc($result_details)) {
                $details_data[] = $row;
            }
            mysqli_stmt_close($stmt_details);

            if (empty($details_data)) {
                 $message = "❌ لم يتم العثور على تفاصيل منتجات لهذه الفاتورة.";
                 $invoice_data = null;
            }

        } else {
            $message = "❌ لم يتم العثور على فاتورة بالرقم $search_id.";
        }
    }
}

// --------------------------------------------------
// ج. معالجة طلب الإرجاع النهائي (Submit Return) 
// --------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_return']) && isset($_POST['invoice_id'])) {
    
    $invoice_id_to_return = intval($_POST['invoice_id']);
    $returned_items = json_decode($_POST['returned_items_json'] ?? '[]', true);
    
    if (empty($returned_items) || $invoice_id_to_return <= 0) {
         $message = "❌ خطأ: لم يتم تحديد منتجات للإرجاع أو رقم الفاتورة غير صحيح.";
         goto display_form_end;
    }

    mysqli_begin_transaction($conn);
    $total_returned_amount = 0;

    try {
        $log_action = "عملية إرجاع مكتملة للفاتورة رقم #$invoice_id_to_return. المنتجات المرجعة: ";
        
        foreach ($returned_items as $item) {
            $product_id = intval($item['product_id']);
            $quantity_returned = intval($item['quantity']);
            $unit_price = floatval($item['unit_price']);
            $product_name = $item['product_name'];

            if ($quantity_returned <= 0) continue;

            $item_return_amount = $quantity_returned * $unit_price;
            $total_returned_amount += $item_return_amount;

            $sql_update_stock = "UPDATE products SET stock_quantity = stock_quantity + ? WHERE id = ?";
            $stmt_update = mysqli_prepare($conn, $sql_update_stock);
            mysqli_stmt_bind_param($stmt_update, "ii", $quantity_returned, $product_id);
            if (!mysqli_stmt_execute($stmt_update)) {
                 throw new Exception("فشل في تحديث مخزون المنتج $product_name.");
            }
            mysqli_stmt_close($stmt_update);
            
            $log_action .= "$product_name ($quantity_returned وحدة، بقيمة $item_return_amount ر.س)؛ ";
        }

        if ($total_returned_amount == 0) {
             throw new Exception("لم يتم تحديد كمية صالحة للإرجاع.");
        }

        $sql_return = "INSERT INTO returns (invoice_id, user_id, returned_amount) VALUES (?, ?, ?)";
        $stmt_return = mysqli_prepare($conn, $sql_return);
        mysqli_stmt_bind_param($stmt_return, "iid", $invoice_id_to_return, $user_id, $total_returned_amount);
        if (!mysqli_stmt_execute($stmt_return)) {
            throw new Exception("فشل في إدراج سجل الإرجاع.");
        }
        mysqli_stmt_close($stmt_return);

        $log_action = "عملية إرجاع مكتملة للفاتورة #$invoice_id_to_return. الإجمالي المسترد: " . number_format($total_returned_amount, 2);
        $sql_log = "INSERT INTO employee_log (user_id, action) VALUES (?, ?)";
        $stmt_log = mysqli_prepare($conn, $sql_log);
        mysqli_stmt_bind_param($stmt_log, "is", $user_id, $log_action);
        mysqli_stmt_execute($stmt_log);
        mysqli_stmt_close($stmt_log);

        mysqli_commit($conn);
        $message = "✅ تمت عملية الإرجاع بنجاح. المبلغ المسترد: " . number_format($total_returned_amount, 2) . " ر.س";

    } catch (Exception $e) {
        mysqli_rollback($conn);
        $message = "❌ فشل عملية الإرجاع: " . $e->getMessage() . " (تم التراجع عن المعاملة)";
    }
}

// --------------------------------------------------
// د. إغلاق الاتصال وعرض الواجهة
// --------------------------------------------------
display_form_end:
mysqli_close($conn); 
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>معالجة الإرجاع</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght=400;700&display=swap" rel="stylesheet">
    <style>
        /* ==================================== */
        /* تصميم موحد مطابق للنسخة الأصلية */
        /* ==================================== */
        body { 
            font-family: 'Cairo', Tahoma, sans-serif; 
            background-color: #f4f6f9; /* رمادي فاتح للخلفية */
            margin: 0; padding: 0; display: flex; 
        }
        
        /* القائمة الجانبية (Sidebar) */
        .sidebar { 
            width: 250px; 
            background-color: #4b5563; /* أسود داكن */
            color: white; height: 100vh; position: fixed; padding: 20px; 
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
        }
        .sidebar a { 
            display: block; padding: 12px 10px; color: #ced4da; text-decoration: none; 
            border-radius: 4px; margin-bottom: 5px; transition: background-color 0.3s;
        }
        .sidebar a:hover { background-color: #343a40; color: white; }
        
        .main-content { margin-right: 270px; padding: 30px; flex-grow: 1; }
        
        /* البطاقات / اللوحات (Panels) */
        .panel { 
            background-color: white; 
            padding: 25px; 
            border-radius: 10px; 
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08); /* تظليل محسّن */
            margin-bottom: 25px; 
            border-top: 4px solid #007bff; /* شريط علوي أزرق */
        }
        
        /* حقول الإدخال والأزرار */
        input[type="number"], input[type="text"], #product-search, #modal-product-search { 
            padding: 10px; 
            border: 1px solid #dee2e6; 
            border-radius: 6px; 
            transition: border-color 0.3s, box-shadow 0.3s;
            box-sizing: border-box;
        }
        input[type="number"]:focus, input[type="text"]:focus, #product-search:focus, #modal-product-search:focus {
            border-color: #007bff;
            box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.25);
            outline: none;
        }

        button { 
            padding: 10px 20px; 
            border: none; 
            border-radius: 6px; 
            cursor: pointer; 
            font-weight: bold;
            transition: opacity 0.3s;
        }
        button:hover { opacity: 0.9; }
        button:disabled { opacity: 0.6; cursor: not-allowed; }
        
        .btn-search { background-color: #007bff; color: white; }
        .btn-return { background-color: #dc3545; color: white; }
        .btn-helper { background-color: #ffc107; color: #343a40; } /* الزر المساعد */
        
        /* الجداول */
        table { width: 100%; border-collapse: separate; border-spacing: 0; margin-top: 20px; border-radius: 8px; overflow: hidden; }
        th, td { border-bottom: 1px solid #e9ecef; padding: 12px; text-align: right; }
        th { background-color: #e9ecef; color: #495057; font-weight: 700; }
        tr:last-child td { border-bottom: none; }
        
        /* رسائل النظام */
        .message { padding: 15px; margin-bottom: 20px; border-radius: 6px; font-weight: bold; border-right: 5px solid; } /* تم تعديل left إلى right للغة العربية */
        .success { background-color: #d4edda; color: #155724; border-color: #c3e6cb; }
        .error { background-color: #f8d7da; color: #721c24; border-color: #f5c6cb; }
        
        /* المودال (النافذة المنبثقة) */
        .modal {
            display: none; 
            position: fixed; 
            z-index: 1; 
            left: 0; top: 0; 
            width: 100%; height: 100%; 
            overflow: auto; 
            background-color: rgba(0,0,0,0.4); 
            padding-top: 60px;
        }
        .modal-content { 
            background-color: #ffffff; 
            margin: 10% auto; 
            padding: 30px; 
            border-radius: 10px; 
            box-shadow: 0 5px 20px rgba(0,0,0,0.2); 
            width: 90%;
            max-width: 600px;
        }
        .close-btn { 
            color: #aaa; 
            float: left; 
            font-size: 28px; 
            font-weight: bold; 
            transition: color 0.2s;
        }
        .close-btn:hover, .close-btn:focus { color: #dc3545; text-decoration: none; cursor: pointer; }

        #invoice-search-results td:hover { background-color: #f8f9fa; cursor: pointer; }
        
        .search-group { 
            display: flex; 
            align-items: center; 
            gap: 15px; 
            flex-wrap: wrap; 
        }
        .search-group form { display: flex; gap: 10px; align-items: center; }
        #product-search { margin-top: 15px; width: 100%; }
        
        .grand-total { 
            font-size: 1.6em; 
            font-weight: 700; 
            color: #212529; /* لون نص داكن */
            padding: 10px 0;
            border-top: 1px solid #dee2e6;
            margin-top: 20px;
        }
        
        /* استجابة الشاشات الصغيرة */
        @media (max-width: 768px) {
            .sidebar { 
                width: 100%; 
                height: auto; 
                position: relative; 
                padding: 10px;
            }
            .main-content { 
                margin-right: 0; 
                padding: 15px; 
                width: 100%;
            }
            .search-group { flex-direction: column; align-items: stretch; }
            .search-group form { flex-direction: column; align-items: stretch; gap: 5px; }
            input[type="number"] { width: 100% !important; }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <h3>👋 مرحباً، <?php echo $full_name; ?></h3>
        <p style="color: #adb5bd; font-size: 0.9em;">(<?php echo $_SESSION['role']; ?>)</p>
        <hr style="border-top: 1px solid #495057;">
        <?php if ($_SESSION['role'] == 'super_admin'): ?>
            <a href="dashboard_admin.php">لوحة المشرف العام</a>
        <?php else: ?>
            <a href="pos.php">نقطة البيع (POS)</a>
            <a href="employee_sales.php">مبيعاتي</a>
            <a href="return_process.php">معالجة الإرجاع</a>
        <?php endif; ?>
        <hr style="border-top: 1px solid #495057;">
        <a href="logout.php">تسجيل الخروج</a>
    </div>

    <div class="main-content">
        <h1>↩️ معالجة إرجاع المنتجات</h1>

        <?php if ($message): ?>
            <div class="message <?php echo strpos($message, '✅') !== false ? 'success' : 'error'; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="panel">
            <h2>البحث عن الفاتورة الأصلية</h2>
            <div class="search-group">
                <form method="post" action="return_process.php">
                    <label for="search_invoice_id" style="font-weight: 700;">أدخل رقم الفاتورة:</label>
                    <input type="number" id="search_invoice_id" name="search_invoice_id" min="1" required 
                            value="<?php echo isset($_POST['search_invoice_id']) ? htmlspecialchars($_POST['search_invoice_id']) : ''; ?>" style="width: 120px;">
                    <button type="submit" class="btn-search">بحث</button>
                </form>
                
                <button type="button" class="btn-helper" onclick="openSearchModal()">
                    🔍 مساعدة: بحث بالمنتج
                </button>
            </div>
        </div>

        <?php if ($invoice_data): ?>
        <div class="panel" id="return-details-panel">
            <h2>تفاصيل الفاتورة رقم #<?php echo $invoice_data['id']; ?></h2>
            <p><strong>تاريخ الفاتورة:</strong> <?php echo $invoice_data['invoice_date']; ?> | <strong>القيمة الإجمالية:</strong> <span style="color: #28a745; font-weight: bold;"><?php echo number_format($invoice_data['total_amount'], 2); ?> ر.س</span></p>
            
            <hr>
            
            <input type="text" id="product-search" placeholder="ابحث باسم المنتج أو الباركود لفلترة الجدول..." onkeyup="filterReturnItems()">

            <form method="post" action="return_process.php" onsubmit="return submitReturn()">
                <input type="hidden" name="submit_return" value="1">
                <input type="hidden" name="invoice_id" value="<?php echo $invoice_data['id']; ?>">
                <input type="hidden" name="returned_items_json" id="returned-items-json">

                <table>
                    <thead>
                        <tr>
                            <th>المنتج</th>
                            <th>سعر الوحدة</th>
                            <th>الكمية المشتراة</th>
                            <th>كمية الإرجاع</th>
                            <th>الإجمالي المرتجع (للبند)</th>
                        </tr>
                    </thead>
                    <tbody id="return-table-body">
                        <?php foreach ($details_data as $item): ?>
                            <?php 
                                $product_id = $item['product_id'];
                                $max_qty = $item['quantity_sold'];
                                $unit_price = $item['unit_price'];
                                $product_name = htmlspecialchars($item['product_name']);
                                $barcode = htmlspecialchars($item['barcode'] ?? '');
                            ?>
                            <tr class="item-row" 
                                data-id="<?php echo $product_id; ?>" 
                                data-price="<?php echo $unit_price; ?>" 
                                data-name="<?php echo $product_name; ?>"
                                data-barcode="<?php echo $barcode; ?>">
                                
                                <td><?php echo $product_name; ?></td>
                                <td><?php echo number_format($unit_price, 2); ?> ر.س</td>
                                <td><?php echo $max_qty; ?></td>
                                <td>
                                    <input type="number" 
                                            class="return-qty" 
                                            min="0" 
                                            max="<?php echo $max_qty; ?>" 
                                            value="0" 
                                            oninput="calculateReturnTotal(this)" style="width: 80px; text-align: center;">
                                </td>
                                <td class="item-total-display">0.00 ر.س</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div style="text-align: left; margin-top: 20px;">
                    <p class="grand-total">
                        إجمالي المبلغ المسترد: <span id="grand-total-display" style="color: #dc3545;">0.00</span> ر.س
                    </p>
                    <button type="submit" class="btn-return" id="submit-return-btn" disabled style="width: 100%;">تأكيد عملية الإرجاع</button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
    
    <div id="search-modal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeSearchModal()">&times;</span>
            <h3>🔍 البحث عن رقم الفاتورة عن طريق المنتج</h3>
            <p style="color: #6c757d;">أدخل اسم المنتج أو الباركود للعثور على آخر 10 فواتير تحتوي عليه:</p>
            <input type="text" id="modal-product-search" placeholder="اسم المنتج أو الباركود..." onkeyup="searchInvoicesByProduct()" style="width: 100%;">
            <br><br>
            <table>
                <thead>
                    <tr>
                        <th>رقم الفاتورة</th>
                        <th>التاريخ</th>
                        <th>المبلغ</th>
                        <th>البائع</th>
                    </tr>
                </thead>
                <tbody id="invoice-search-results">
                    <tr><td colspan="4">ابدأ بالبحث...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        // **********************************************
        // منطق النافذة المنبثقة والبحث المساعد (كما هو)
        // **********************************************

        function openSearchModal() {
            document.getElementById('search-modal').style.display = 'block';
            document.getElementById('modal-product-search').focus();
        }

        function closeSearchModal() {
            document.getElementById('search-modal').style.display = 'none';
        }

        window.onclick = function(event) {
            const modal = document.getElementById('search-modal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }
        
        let searchTimeout;

        function searchInvoicesByProduct() {
            clearTimeout(searchTimeout);
            
            const query = document.getElementById('modal-product-search').value.trim();
            const resultsBody = document.getElementById('invoice-search-results');

            if (query.length < 3) {
                resultsBody.innerHTML = '<tr><td colspan="4">أدخل 3 أحرف على الأقل للبحث...</td></tr>';
                return;
            }
            
            resultsBody.innerHTML = '<tr><td colspan="4">جاري البحث...</td></tr>';
            
            searchTimeout = setTimeout(() => {
                fetch(`return_process.php?action=search_invoice_by_product&product_query=${encodeURIComponent(query)}`)
                    .then(response => response.json())
                    .then(data => {
                        let html = '';
                        if (data.length > 0) {
                            data.forEach(invoice => {
                                html += `
                                    <tr onclick="selectInvoiceId(${invoice.id})" style="cursor: pointer;">
                                        <td>#${invoice.id}</td>
                                        <td>${invoice.invoice_date.substring(0, 10)}</td>
                                        <td>${parseFloat(invoice.total_amount).toFixed(2)} ر.س</td>
                                        <td>${invoice.employee_name}</td>
                                    </tr>
                                `;
                            });
                        } else {
                            html = '<tr><td colspan="4">لا توجد فواتير مطابقة تحتوي على هذا المنتج.</td></tr>';
                        }
                        resultsBody.innerHTML = html;
                    })
                    .catch(error => {
                        console.error('Error fetching data:', error);
                        resultsBody.innerHTML = '<tr><td colspan="4">حدث خطأ أثناء تحميل البيانات.</td></tr>';
                    });
            }, 500); 
        }
        
        function selectInvoiceId(invoiceId) {
            if (confirm(`هل تريد البحث عن الفاتورة رقم #${invoiceId}؟`)) {
                document.getElementById('search_invoice_id').value = invoiceId;
                closeSearchModal();
                // إرسال النموذج تلقائيا لتسهيل العمل على الموظف
                document.querySelector('.search-group form').submit(); 
            }
        }


        /* ---------------------------------------------------------------------- */
        /* الدوال الأساسية (لم تتغير في المنطق) */
        /* ---------------------------------------------------------------------- */

        function filterReturnItems() {
            const input = document.getElementById('product-search').value.toLowerCase();
            const rows = document.querySelectorAll('#return-table-body .item-row');
            rows.forEach(row => {
                const name = row.dataset.name.toLowerCase();
                const barcode = row.dataset.barcode.toLowerCase();
                if (name.includes(input) || barcode.includes(input)) {
                    row.style.display = 'table-row';
                } else {
                    row.style.display = 'none';
                }
            });
        }
        
        function calculateReturnTotal(inputElement = null) {
            let grandTotal = 0;
            const rows = document.querySelectorAll('#return-table-body .item-row');
            let hasValidReturn = false;
            
            rows.forEach(row => {
                const qtyInput = row.querySelector('.return-qty');
                const itemTotalDisplay = row.querySelector('.item-total-display');
                
                let quantity = parseInt(qtyInput.value) || 0;
                const price = parseFloat(row.dataset.price);
                const max_qty = parseInt(qtyInput.max); 

                if (quantity > max_qty) {
                    quantity = max_qty;
                    qtyInput.value = max_qty;
                }
                
                if (quantity < 0) {
                     quantity = 0;
                     qtyInput.value = 0;
                }

                const itemTotal = quantity * price;
                itemTotalDisplay.textContent = itemTotal.toFixed(2) + ' ر.س';
                grandTotal += itemTotal;
                
                if (quantity > 0) {
                    hasValidReturn = true;
                }
            });

            document.getElementById('grand-total-display').textContent = grandTotal.toFixed(2);
            document.getElementById('submit-return-btn').disabled = !hasValidReturn;
        }

        function submitReturn() {
            const rows = document.querySelectorAll('#return-table-body .item-row');
            let returnedItems = [];
            
            rows.forEach(row => {
                const qtyInput = row.querySelector('.return-qty');
                const quantity = parseInt(qtyInput.value) || 0;

                if (quantity > 0) {
                    returnedItems.push({
                        product_id: parseInt(row.dataset.id),
                        product_name: row.dataset.name,
                        unit_price: parseFloat(row.dataset.price),
                        quantity: quantity
                    });
                }
            });

            if (returnedItems.length === 0) {
                alert("يرجى تحديد كمية الإرجاع للمنتجات.");
                return false;
            }

            document.getElementById('returned-items-json').value = JSON.stringify(returnedItems);
            return confirm("هل أنت متأكد من تنفيذ عملية الإرجاع بقيمة " + document.getElementById('grand-total-display').textContent + "؟");
        }


        window.onload = calculateReturnTotal;
    </script>
</body>
</html>