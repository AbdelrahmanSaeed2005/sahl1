<?php
// fatura/reports.php
// تقارير المشرف العام: حساب إجمالي الأرباح والمبيعات حسب فترة زمنية

require_once 'auth_check.php';
// التحقق: يجب أن يكون super_admin
check_auth('super_admin'); 

require_once 'database/db_conn.php'; 

$report_data = null;
$error_message = '';
$start_date = date("Y-m-01"); // الافتراضي: بداية الشهر الحالي
$end_date = date("Y-m-d");   // الافتراضي: اليوم الحالي

// --------------------------------------------------
// أ. معالجة طلب التقرير (POST Request)
// --------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['run_report'])) {
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    
    // التحقق الأساسي من التواريخ
    if (strtotime($start_date) > strtotime($end_date)) {
        $error_message = "❌ تاريخ البداية لا يمكن أن يكون بعد تاريخ النهاية.";
        goto display_report_form;
    }
    
    // الاستعلام عن الربح والمبيعات
    // المعادلة الرياضية المستخدمة لحساب الربح (كما طلبت):
    // Total Profit = SUM (quantity_sold * (unit_price (selling) - cost_price))
    
    $sql_report = "
    SELECT 
        SUM(id.quantity_sold) AS total_units_sold,
        SUM(id.quantity_sold * id.unit_price) AS gross_sales_amount,
        SUM(id.quantity_sold * p.cost_price) AS total_cost_amount,
        SUM(id.quantity_sold * (id.unit_price - p.cost_price)) AS net_profit
    FROM invoices i
    JOIN invoice_details id ON i.id = id.invoice_id
    JOIN products p ON id.product_id = p.id
    WHERE DATE(i.invoice_date) BETWEEN ? AND ?
    ";

    $stmt_report = mysqli_prepare($conn, $sql_report);
    mysqli_stmt_bind_param($stmt_report, "ss", $start_date, $end_date);
    mysqli_stmt_execute($stmt_report);
    $result_report = mysqli_stmt_get_result($stmt_report);
    $report_data = mysqli_fetch_assoc($result_report);
    
    // إذا لم يتم العثور على أي مبيعات، نضمن أن تكون القيم صفر
    if ($report_data && $report_data['gross_sales_amount'] === null) {
        $report_data = [
            'total_units_sold' => 0,
            'gross_sales_amount' => 0.00,
            'total_cost_amount' => 0.00,
            'net_profit' => 0.00
        ];
    }
    
    mysqli_stmt_close($stmt_report);
}

// --------------------------------------------------
// ب. إغلاق الاتصال وعرض الواجهة
// --------------------------------------------------
display_report_form:
mysqli_close($conn); 
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تقارير الأرباح</title>
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
            margin-right: 290px; /* تم زيادة الهامش ليناسب الشريط الجانبي الأعرض */
            padding: 35px 30px; 
            flex-grow: 1; 
        }
        .main-content h1 { color: #1e3a8a; margin-top: 0; margin-bottom: 30px; }

        /* حاويات النماذج والتقارير (البطاقات) */
        .panel { 
            background-color: white; 
            padding: 30px; 
            border-radius: 12px; 
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08); 
            margin-bottom: 30px; 
            border-top: 5px solid #3498db; /* شريط أزرق مخصص للتقارير */
        }
        .panel h2 { color: #2c3e50; margin-top: 0; border-bottom: 1px solid #ecf0f1; padding-bottom: 10px; margin-bottom: 20px; }

        /* حقول الإدخال */
        .form-group { 
            display: flex;
            align-items: center;
            gap: 20px; /* تباعد بين العناصر في نفس الصف */
        }
        .form-group label { 
            font-weight: 600; 
            color: #34495e; 
            white-space: nowrap; /* منع انقسام النص */
        }
        input[type="date"] { 
            padding: 10px; 
            border: 1px solid #bdc3c7; 
            border-radius: 6px; 
            flex-grow: 1; 
            max-width: 200px; 
            transition: border-color 0.3s, box-shadow 0.3s;
        }
        input[type="date"]:focus {
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
            outline: none;
        }
        button { 
            padding: 10px 25px; 
            border: none; 
            border-radius: 6px; 
            cursor: pointer; 
            background-color: #3498db; /* زر أزرق */
            color: white; 
            font-weight: 600;
            transition: opacity 0.3s;
        }
        button:hover { opacity: 0.9; }

        /* رسائل الخطأ */
        .error { 
            padding: 15px; 
            margin-bottom: 25px; 
            border-radius: 8px; 
            font-weight: 600;
            border: 1px solid #e74c3c; 
            background-color: #f8d7da; 
            color: #721c24; 
        }

        /* شبكة النتائج */
        .results-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); 
            gap: 20px; 
            margin-top: 20px; 
        }
        .result-card { 
            padding: 20px; 
            border-radius: 10px; 
            text-align: center; 
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            transition: transform 0.2s;
            border-left: 5px solid;
        }
        .result-card:hover { transform: translateY(-5px); }

        .result-card h4 { font-size: 0.9em; margin: 0 0 10px; color: #5a6a7b; }
        .result-card h3 { font-size: 1.8em; margin: 5px 0 0; font-weight: 700; }

        /* ألوان البطاقات */
        .profit { 
            background-color: #e8f5e9; /* أخضر فاتح جداً */
            color: #2e7d32; /* نص أخضر داكن */
            border-color: #4caf50; 
        }
        .sales { 
            background-color: #e3f2fd; /* أزرق فاتح جداً */
            color: #1976d2; /* نص أزرق داكن */
            border-color: #2196f3;
        }
        .cost { 
            background-color: #ffebee; /* أحمر فاتح جداً */
            color: #d32f2f; /* نص أحمر داكن */
            border-color: #f44336;
        }
        .units { 
            background-color: #fffde7; /* أصفر فاتح جداً */
            color: #f9a825; /* نص أصفر داكن */
            border-color: #ffeb3b;
        }

    </style>
</head>
<body>
    <div class="sidebar">
        <h3>👋 مرحباً، <?php echo $_SESSION['full_name']; ?></h3>
        <p style="color: #adb5bd;">(<?php echo $_SESSION['role']; ?>)</p>
        <hr>
        <a href="dashboard_admin.php">الرئيسية</a>
        <a href="manage_users.php">إدارة المستخدمين</a>
        <a href="manage_products.php">إدارة المخزون (المنتجات)</a>
        <a href="reports.php">التقارير المالية والأرباح</a>
        <a href="view_log.php">يوميات العمال</a>
        <hr>
        <a href="logout.php">تسجيل الخروج</a>
    </div>

    <div class="main-content">
        <h1>📈 تقارير الأرباح والمبيعات</h1>
        
        <?php if ($error_message): ?>
            <p class="error"><?php echo $error_message; ?></p>
        <?php endif; ?>

        <div class="panel">
            <h2>تحديد الفترة الزمنية</h2>
            <form method="post" action="reports.php">
                <input type="hidden" name="run_report" value="1">
                <div class="form-group">
                    
                    <label for="start_date">من تاريخ:</label>
                    <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" required>
                    
                    <label for="end_date">إلى تاريخ:</label>
                    <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" required>
                    
                    <button type="submit">عرض التقرير</button>
                </div>
            </form>
        </div>

        <?php if ($report_data !== null): ?>
            <div class="panel">
                <h2>نتائج التقرير (<?php echo $start_date . ' - ' . $end_date; ?>)</h2>
                
                <div class="results-grid">
                    
                    <div class="result-card profit">
                        <h4>صافي الربح</h4>
                        <h3><?php echo number_format($report_data['net_profit'], 2); ?> ر.س</h3>
                    </div>
                    
                    <div class="result-card sales">
                        <h4>إجمالي المبيعات (الخام)</h4>
                        <h3><?php echo number_format($report_data['gross_sales_amount'], 2); ?> ر.س</h3>
                    </div>
                    
                    <div class="result-card cost">
                        <h4>إجمالي التكلفة</h4>
                        <h3><?php echo number_format($report_data['total_cost_amount'], 2); ?> ر.س</h3>
                    </div>
                    
                    <div class="result-card units">
                        <h4>إجمالي الوحدات المباعة</h4>
                        <h3><?php echo number_format($report_data['total_units_sold']); ?> وحدة</h3>
                    </div>
                </div>
                
                <blockquote style="margin-top: 30px; border-right: 5px solid #3498db; padding-right: 15px; background-color: #f7f9fc;">
                <br>
                </blockquote>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>