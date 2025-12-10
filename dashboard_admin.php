<?php
// fatura/dashboard_admin.php
// الصفحة الرئيسية للمشرف العام

// 1. التحقق من الدخول والصلاحية
require_once 'auth_check.php';
// يجب أن يكون المستخدم super_admin لدخول هذه الصفحة
check_auth('super_admin'); 

require_once 'database/db_conn.php'; 

// 2. جلب إجمالي الأرباح والمبيعات اليومية (ملخص سريع)
// نحتاج إلى استعلام SQL معقد هنا لحساب الأرباح بدقة.
$today = date("Y-m-d");
$profit_data = [];

// الاستعلام عن الربح اليومي:
// يجمع بين الفواتير (invoices)، تفاصيل الفواتير (invoice_details)، وتكاليف المنتجات (products)
$sql_summary = "
SELECT 
    SUM(id.quantity_sold * (id.unit_price - p.cost_price)) AS total_profit,
    SUM(i.total_amount) AS total_sales_amount,
    COUNT(i.id) AS total_invoices_count
FROM invoices i
JOIN invoice_details id ON i.id = id.invoice_id
JOIN products p ON id.product_id = p.id
WHERE DATE(i.invoice_date) = '$today';
";

$result_summary = mysqli_query($conn, $sql_summary);
if ($result_summary) {
    $profit_data = mysqli_fetch_assoc($result_summary);
} else {
    // في حالة عدم وجود أي مبيعات بعد
    $profit_data = [
        'total_profit' => 0.00,
        'total_sales_amount' => 0.00,
        'total_invoices_count' => 0
    ];
}

// 3. جلب تنبيهات المخزون المنخفض
$low_stock_limit = 10; // يمكن تعريف حد التنبيه كمتغير إعدادات
$sql_low_stock = "SELECT name, stock_quantity FROM products WHERE stock_quantity <= $low_stock_limit AND stock_quantity > 0 ORDER BY stock_quantity ASC LIMIT 5";
$result_low_stock = mysqli_query($conn, $sql_low_stock);

?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>لوحة تحكم المشرف العام</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        /* ==================================== */
        /* تصميم مودرن بألوان هادئة ومريحة للعين */
        /* ==================================== */
        body { 
            font-family: 'Cairo', Tahoma, sans-serif; 
            background-color: #f4f6f9; /* خلفية ناعمة جداً */
            margin: 0; padding: 0; display: flex; /* لدمج الشريط الجانبي */
            color: #343a40; /* لون نص أساسي داكن مريح */
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
            color: #ecf0f1; /* لون نص فاتح وواضح */
            text-decoration: none; 
            border-radius: 6px; 
            margin-bottom: 8px; 
            transition: background-color 0.3s, color 0.3s;
            font-weight: 600;
        }
        .sidebar a:hover { 
            background-color: #34495e; 
            color: white; 
        }
        
        .main-content { 
            margin-right: 290px; 
            padding: 35px 30px; 
            flex-grow: 1; 
        }
        .main-content h1 { color: #1e3a8a; margin-bottom: 30px; }

        /* البطاقات الإحصائية (Summary Cards) - تصميم البطاقة المودرن */
        .card-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); 
            gap: 25px; 
            margin-bottom: 30px; 
        }
        .summary-card { 
            background-color: white; 
            padding: 25px; 
            border-radius: 12px; 
            box-shadow: 0 6px 15px rgba(0, 0, 0, 0.08); /* ظل ناعم وعميق */
            position: relative;
            overflow: hidden;
            border-top: 5px solid; /* شريط علوي للتمييز */
            transition: transform 0.3s;
        }
        .summary-card:hover { transform: translateY(-5px); }
        .summary-card h4 { margin-top: 0; color: #7f8c8d; font-weight: 600; font-size: 1.1em; }
        .summary-card h2 { font-size: 2.8em; font-weight: 700; margin-top: 5px; margin-bottom: 10px; }
        .summary-card p { font-size: 0.9em; color: #95a5a6; }
        
        /* ألوان البطاقات الهادئة والمريحة */
        
        /* الربح (أخضر هادئ) */
        .card-profit { 
            border-color: #2ecc71; /* أخضر فاتح */
            color: #27ae60; 
        }
        .card-profit h2 { color: #2ecc71; }
        
        /* المبيعات (أزرق سماوي هادئ) */
        .card-sales { 
            border-color: #3498db; /* أزرق سماوي */
            color: #2980b9; 
        }
        .card-sales h2 { color: #3498db; }

        /* عدد الفواتير (بنفسجي فاتح/أرجواني هادئ) */
        .card-invoices { 
            border-color: #9b59b6; /* أرجواني هادئ */
            color: #8e44ad; 
        }
        .card-invoices h2 { color: #9b59b6; }

        /* تنبيه المخزون */
        .alert { 
            padding: 20px; 
            margin-bottom: 30px; 
            border-radius: 10px; 
            background-color: #fef3c7; /* أصفر ناعم جداً */
            color: #92400e; 
            border: 1px solid #fde68a; 
            border-right: 5px solid #f59e0b; /* شريط جانبي للتأكيد */
            font-weight: 600;
        }
        .alert h4 { color: #f59e0b; margin-top: 0; font-size: 1.2em; }
        .alert ul { margin: 10px 0 0 0; padding-right: 20px; }
        .alert li { margin-bottom: 5px; }

        /* تحليل الأداء (لوحة عادية) */
        .chart-panel {
            background-color: white; 
            padding: 25px; 
            border-radius: 12px; 
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05); 
            border-top: 5px solid #bdc3c7; /* رمادي هادئ */
        }
        .chart-panel h3 { color: #2c3e50; border-bottom: 1px solid #ecf0f1; padding-bottom: 10px; }

    </style>
</head>
<body>
    <div class="sidebar">
        <h3>🚀 لوحة تحكم المشرف        </h3>
        <h3>   </h3>
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
        <h1>📊 نظرة عامة على أداء النظام</h1>

        <?php 
        // إعادة تنفيذ الكود للتحقق من المخزون المنخفض
        mysqli_data_seek($result_low_stock, 0); // إعادة المؤشر إلى البداية للتكرار
        if (mysqli_num_rows($result_low_stock) > 0): 
        ?>
            <div class="alert">
                <h4>⚠️ تنبيه: مخزون منخفض</h4>
                <p>المنتجات التالية تحتاج إلى إعادة طلب عاجلة (الحد: <?php echo $low_stock_limit; ?>):</p>
                <ul>
                    <?php while($row = mysqli_fetch_assoc($result_low_stock)): ?>
                        <li><?php echo htmlspecialchars($row['name']); ?>: الكمية المتبقية (<?php echo $row['stock_quantity']; ?>)</li>
                    <?php endwhile; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card-grid">
            <div class="summary-card card-profit">
                <h4>صافي الربح اليومي</h4>
                <h2><?php echo number_format($profit_data['total_profit'], 2); ?> ر.س</h2>
                <p>الأرباح المحققة اليوم: <?php echo $today; ?></p>
            </div>

            <div class="summary-card card-sales">
                <h4>إجمالي مبيعات اليوم</h4>
                <h2><?php echo number_format($profit_data['total_sales_amount'], 2); ?> ر.س</h2>
                <p>قيمة الفواتير المنجزة.</p>
            </div>

            <div class="summary-card card-invoices">
                <h4>عدد فواتير اليوم</h4>
                <h2><?php echo $profit_data['total_invoices_count']; ?></h2>
                <p>عدد العمليات المكتملة.</p>
            </div>
        </div>
        
        <div class="chart-panel">
            <h3>📈 تحليل الأداء</h3>
            <p style="color: #7f8c8d;">يمكن إضافة الرسوم البيانية الديناميكية هنا لملخص مبيعات الشهر الماضي (باستخدام مكتبات مثل Chart.js).</p>
        </div>
    </div>
</body>
</html>
<?php 
// إغلاق الاتصال بعد الانتهاء من جميع العمليات
mysqli_close($conn); 
?>