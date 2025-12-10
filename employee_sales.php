<?php
// fatura/employee_sales.php
// لوحة تحكم الموظف: عرض المبيعات اليومية وسجل اليوميات الخاص به

// ... (كود PHP كما هو) ...

require_once 'auth_check.php';
// التحقق: يمكن للموظف والمشرف الدخول هنا
check_auth('employee'); 

require_once 'database/db_conn.php'; 

$user_id = $_SESSION['user_id'];
$full_name = $_SESSION['full_name'];
$today = date("Y-m-d");

// --------------------------------------------------
// أ. جلب ملخص المبيعات الشخصية لليوم الحالي
// --------------------------------------------------
$daily_sales_summary = [
    'total_sales' => 0.00,
    'invoices_count' => 0
];

$sql_summary = "
SELECT 
    SUM(total_amount) AS total_sales,
    COUNT(id) AS invoices_count
FROM invoices 
WHERE user_id = ? AND DATE(invoice_date) = ?
";

$stmt_summary = mysqli_prepare($conn, $sql_summary);
mysqli_stmt_bind_param($stmt_summary, "is", $user_id, $today);
mysqli_stmt_execute($stmt_summary);
$result_summary = mysqli_stmt_get_result($stmt_summary);

if ($row = mysqli_fetch_assoc($result_summary)) {
    // التأكد من أن القيم ليست NULL (في حالة عدم وجود مبيعات اليوم)
    $daily_sales_summary['total_sales'] = $row['total_sales'] !== null ? $row['total_sales'] : 0.00;
    $daily_sales_summary['invoices_count'] = $row['invoices_count'] !== null ? $row['invoices_count'] : 0;
}
mysqli_stmt_close($stmt_summary);


// --------------------------------------------------
// ب. جلب سجل اليوميات الخاصة بالموظف (آخر 20 عملية)
// --------------------------------------------------
$log_entries = [];

$sql_log = "
SELECT 
    action, timestamp 
FROM employee_log 
WHERE user_id = ?
ORDER BY timestamp DESC
LIMIT 20
";

$stmt_log = mysqli_prepare($conn, $sql_log);
mysqli_stmt_bind_param($stmt_log, "i", $user_id);
mysqli_stmt_execute($stmt_log);
$result_log = mysqli_stmt_get_result($stmt_log);

while ($row = mysqli_fetch_assoc($result_log)) {
    $log_entries[] = $row;
}
mysqli_stmt_close($stmt_log);

mysqli_close($conn); 
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>لوحة تحكم الموظف</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* ==================================== */
        /* التعديلات الجديدة لراحة العين والعمق البصري */
        /* ==================================== */
        :root {
            --primary-color: #1e3a8a; /* أزرق نيلي داكن */
            --secondary-color: #059669; /* أخضر */
            --bg-light: #eff3f6; /* خلفية أغمق وأدفأ قليلاً (بدلاً من f4f6f9) */
            --bg-dark: #374151; /* خلفية جانبية داكنة */
            --panel-bg: #ffffff; /* خلفية اللوحات */
            --text-dark: #1f2937; /* نص أكثر قتامة لزيادة التباين */
            --danger-color: #ef4444; 
        }

        body { 
            font-family: 'Cairo', sans-serif; 
            background-color: var(--bg-light); /* تطبيق الخلفية الأدفأ */
            margin: 0; 
            padding: 0; 
            display: flex; 
            color: var(--text-dark); /* تطبيق لون النص الداكن */
            direction: rtl;
        }

        /* القائمة الجانبية (Sidebar) - لم تتغير لكن ألوانها متوافقة */
        .sidebar { 
            width: 260px; 
            background-color: var(--bg-dark); 
            color: white; 
            height: 100vh; 
            position: fixed; 
            padding: 25px 20px;
            box-shadow: 3px 0 15px rgba(0, 0, 0, 0.2); /* ظل أعمق */
        }
        /* ... تنسيق الـ sidebar كما هو ... */
        .sidebar h3 { color: #f3f4f6; border-bottom: 1px solid #4b5563; padding-bottom: 15px; margin-bottom: 20px; font-weight: 700; }
        .sidebar p { color: #d1d5db; font-size: 0.9em; }
        .sidebar a { 
            display: block; 
            padding: 12px 10px; 
            color: #d1d5db; 
            text-decoration: none; 
            border-radius: 6px; 
            margin-bottom: 8px; 
            transition: background-color 0.3s, color 0.3s;
            font-weight: 600;
        }
        .sidebar a:hover { background-color: #4b5563; color: white; }
        .sidebar hr { border-top: 1px solid #4b5563; }
        
        /* المحتوى الرئيسي */
        .main-content { 
            margin-right: 260px; 
            padding: 30px; 
            flex-grow: 1; 
            width: calc(100% - 260px);
        }
        h1 { color: var(--primary-color); font-weight: 800; margin-bottom: 10px; }
        .today-info { color: #6b7280; font-size: 1.3em; margin-bottom: 30px; display: block;}

        /* شبكة البطاقات (Cards Grid) */
        .card-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 25px; margin-bottom: 40px; }
        
        .summary-card { 
            background-color: var(--panel-bg); /* خلفية بيضاء */
            padding: 25px; 
            border-radius: 12px; 
            box-shadow: 0 8px 25px rgba(0,0,0,0.15); /* ظل أعمق وأكثر انتشاراً */
            text-align: right;
            transition: transform 0.3s, box-shadow 0.3s;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .summary-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 30px rgba(0,0,0,0.2);
        }
        .card-content h4 { color: #6b7280; margin: 0 0 5px 0; font-weight: 600; font-size: 1.1em; }
        .card-content h2 { font-size: 2.2em; margin: 0; font-weight: 800; }
        
        .card-icon {
            font-size: 3em; 
            padding: 15px; 
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            opacity: 0.9; /* زيادة شفافية الأيقونة */
        }

        /* ألوان البطاقات */
        .card-sales .card-icon { background-color: var(--primary-color); }
        .card-sales h2 { color: var(--primary-color); }

        .card-invoices .card-icon { background-color: var(--secondary-color); }
        .card-invoices h2 { color: var(--secondary-color); }

        /* لوحة السجل */
        .log-panel { 
            background-color: var(--panel-bg); /* خلفية بيضاء */
            padding: 30px; 
            border-radius: 12px; 
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1); /* ظل أعمق للوحة السجل */
        }
        .log-panel h2 { margin-bottom: 25px; color: var(--text-dark); border-bottom: 2px solid #e5e7eb; padding-bottom: 10px; }
        
        /* تنسيق إدخالات السجل (Timeline Style) */
        .log-entry {
            border-right: 3px solid #e5e7eb; /* خط الزمن */
            padding: 15px 15px 15px 0;
            position: relative;
            margin-bottom: 15px;
            /* إضافة حافة سفلية خفيفة جداً لتمييز الإدخالات عن بعضها */
            border-bottom: 1px solid #f3f4f6; 
        }
        /* ... تنسيق الـ log-entry كما هو ... */
        .log-entry::before {
            content: '•';
            position: absolute;
            right: -10px;
            top: 20px;
            background-color: var(--panel-bg); /* استخدام لون خلفية اللوحة */
            border: 3px solid var(--primary-color);
            border-radius: 50%;
            padding: 0 5px;
            font-size: 1.5em;
            line-height: 0;
            color: var(--primary-color);
        }

        .log-entry strong { display: block; font-weight: 700; margin-bottom: 3px; font-size: 1.05em; }
        .log-entry span { display: block; font-size: 0.85em; color: #9ca3af; }
        
        /* التصميم الجديد لتمييز عمليات الإرجاع (Danger) */
        .log-entry.return-action {
            background-color: #fef2f2; 
            border-right: 3px solid var(--danger-color); 
        }
        .log-entry.return-action::before {
            border-color: var(--danger-color);
            color: var(--danger-color);
            content: '✖';
            font-size: 1em;
            padding: 2px 4px;
        }
        .log-entry.return-action strong {
            color: var(--danger-color); 
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <h3>👋 مرحباً، <?php echo $full_name; ?></h3>
        <p style="color: #adb5bd;">(<?php echo $_SESSION['role']; ?>)</p>
        <hr>
        <?php if ($_SESSION['role'] == 'super_admin'): ?>
            <a href="dashboard_admin.php">لوحة المشرف العام</a>
        <?php endif; ?>
        <a href="pos.php">نقطة البيع (POS)</a>
        <a href="employee_sales.php" style="background-color: #4b5563; color: white;">مبيعاتي</a> 
        <a href="return_process.php">معالجة الإرجاع</a>
        <hr>
        <a href="logout.php">تسجيل الخروج</a>
    </div>

    <div class="main-content">
        <h1>🧑‍💼 لوحة تحكم الموظف</h1>
        <!-- <span class="today-info">.             ملخص أدائك الشخصي اليوم: **<?php //echo $today; ?>**</span> -->

        <div class="card-grid">
            <div class="summary-card card-sales">
                <div class="card-content">
                    <h4>إجمالي مبيعاتك اليوم</h4>
                    <h2><?php echo number_format($daily_sales_summary['total_sales'], 2); ?> ر.س</h2>
                </div>
                <div class="card-icon">💰</div>
            </div>
            
            <div class="summary-card card-invoices">
                <div class="card-content">
                    <h4>عدد فواتيرك المنجزة</h4>
                    <h2><?php echo $daily_sales_summary['invoices_count']; ?></h2>
                </div>
                <div class="card-icon">📄</div>
            </div>
        </div>

        <div class="log-panel">
            <h2>📜 سجل اليوميات (آخر 20 إجراء)</h2>
            <div id="log-list">
                <?php if (empty($log_entries)): ?>
                    <p style="color: #6b7280; text-align: center; padding: 20px; background-color: #f9fafb; border-radius: 8px;">لم تقم بتسجيل أي إجراءات بعد.</p>
                <?php else: ?>
                    <?php foreach ($log_entries as $entry): ?>
                        <?php
                            // التحقق من أن الإجراء هو عملية إرجاع
                            $is_return = strpos($entry['action'], 'إرجاع') !== false || strpos($entry['action'], 'استرداد') !== false;
                            $css_class = $is_return ? 'return-action' : '';
                        ?>
                        <div class="log-entry <?php echo $css_class; ?>">
                            <strong><?php echo htmlspecialchars($entry['action']); ?></strong>
                            <span><?php echo $entry['timestamp']; ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>