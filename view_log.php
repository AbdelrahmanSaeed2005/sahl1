<?php
// fatura/view_log.php
// تقرير يوميات العمال (Employee Log) للمشرف العام

require_once 'auth_check.php';
// التحقق: يجب أن يكون super_admin
check_auth('super_admin'); 

require_once 'database/db_conn.php'; 

$log_entries = [];
$employees = [];

// قيم الفلترة الافتراضية
$filter_user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0; // 0 يعني الكل
$filter_date = isset($_POST['log_date']) ? $_POST['log_date'] : date("Y-m-d"); // الافتراضي: اليوم

// --------------------------------------------------
// أ. جلب قائمة الموظفين (للفلترة)
// --------------------------------------------------
$sql_employees = "SELECT id, full_name, role FROM users ORDER BY full_name ASC";
$result_employees = mysqli_query($conn, $sql_employees);

while ($row = mysqli_fetch_assoc($result_employees)) {
    $employees[] = $row;
}

// --------------------------------------------------
// ب. جلب سجل اليوميات بناءً على الفلاتر
// --------------------------------------------------
$sql_log = "
SELECT 
    el.action, el.timestamp, u.full_name, u.role
FROM employee_log el
JOIN users u ON el.user_id = u.id
WHERE 1=1 
";

$params = "";
$data = [];

// إضافة فلتر التاريخ
if (!empty($filter_date)) {
    $sql_log .= " AND DATE(el.timestamp) = ?";
    $params .= "s";
    $data[] = $filter_date;
}

// إضافة فلتر الموظف
if ($filter_user_id > 0) {
    $sql_log .= " AND el.user_id = ?";
    $params .= "i";
    $data[] = $filter_user_id;
}

$sql_log .= " ORDER BY el.timestamp DESC";

$stmt_log = mysqli_prepare($conn, $sql_log);

if (!empty($params)) {
    // يجب تمرير البيانات كمرجع باستخدام ...$data
    mysqli_stmt_bind_param($stmt_log, $params, ...$data);
}

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
    <title>يوميات العمال</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        /* ==================================== */
        /* تصميم مودرن بتركيز على وضوح البيانات */
        /* ==================================== */
        body { 
            font-family: 'Cairo', Tahoma, sans-serif; 
            background-color: #f4f6f9; 
            margin: 0; padding: 0; 
            display: flex; 
            color: #343a40; 
        }
        
        /* القائمة الجانبية (Sidebar) */
        .sidebar { 
            width: 260px; 
            background-color: #2c3e50; 
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
            transition: background-color 0.3s;
            font-weight: 600;
        }
        .sidebar a:hover { background-color: #34495e; }
        
        .main-content { 
            margin-right: 290px; 
            padding: 35px 30px; 
            flex-grow: 1; 
        }
        .main-content h1 { color: #1e3a8a; margin-top: 0; margin-bottom: 30px; }

        /* حاويات النماذج والجداول */
        .panel { 
            background-color: white; 
            padding: 30px; 
            border-radius: 12px; 
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08); 
            margin-bottom: 30px; 
        }
        .panel:nth-child(2) { /* فلترة السجل */
            border-top: 5px solid #e67e22; /* شريط برتقالي للفلترة */
        }
        .panel:nth-child(3) { /* الإجراءات المسجلة */
            border-top: 5px solid #2ecc71; /* شريط أخضر للبيانات */
        }
        .panel h2 { color: #2c3e50; margin-top: 0; border-bottom: 1px solid #ecf0f1; padding-bottom: 10px; margin-bottom: 20px; }

        /* نموذج الفلترة */
        .filter-form form {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .filter-form label {
            font-weight: 600;
            color: #34495e;
            white-space: nowrap;
        }
        .filter-form select, .filter-form input[type="date"] { 
            padding: 10px; 
            border: 1px solid #bdc3c7; 
            border-radius: 6px; 
            min-width: 150px;
            transition: border-color 0.3s;
        }
        .filter-form button { 
            padding: 10px 25px; 
            border: none; 
            border-radius: 6px; 
            cursor: pointer; 
            background-color: #e67e22; /* زر برتقالي */
            color: white; 
            font-weight: 600;
            transition: background-color 0.3s;
        }
        .filter-form button:hover { background-color: #d35400; }

        /* الجدول */
        table { 
            width: 100%; 
            border-collapse: separate; /* استخدام separate لتحسين border-radius */
            border-spacing: 0;
            margin-top: 20px; 
            border-radius: 8px;
            overflow: hidden; /* لإخفاء الزوايا الحادة */
        }
        th, td { 
            padding: 12px 15px; 
            text-align: right; 
            border-bottom: 1px solid #e9ecef;
        }
        th { 
            background-color: #3498db; /* لون أزرق لرؤوس الأعمدة */
            color: white; 
            font-weight: 700; 
            text-align: center;
        }
        tr:nth-child(even) { background-color: #f8f9fa; } /* تظليل الصفوف الزوجية */
        tr:hover { background-color: #eaf6ff; }

        /* تنسيق الأدوار */
        .admin-role { 
            color: #e74c3c; /* لون أحمر للإدارة */
            font-weight: 700; 
            background-color: #fdeded;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 0.9em;
        }
        .employee-role {
            color: #2c3e50;
            font-weight: 600;
        }
        
        td:nth-child(1), th:nth-child(1) { width: 25%; text-align: center; }
        td:nth-child(2), th:nth-child(2) { width: 20%; text-align: center; }
    </style>
</head>
<body>
    <div class="sidebar">
        <h3>👋 مرحباً، <?php echo $_SESSION['full_name']; ?></h3>
        <p style="color: #adb5bd;">(<?php echo $_SESSION['role']; ?>)</p>
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
        <h1>📜 سجل يوميات العمال</h1>

        <div class="panel filter-form">
            <h2>فلترة السجل</h2>
            <form method="post" action="view_log.php">
                <label for="user_id">اختر الموظف:</label>
                <select id="user_id" name="user_id">
                    <option value="0">--- جميع الموظفين ---</option>
                    <?php foreach ($employees as $emp): ?>
                        <option value="<?php echo $emp['id']; ?>" <?php echo ($filter_user_id == $emp['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($emp['full_name']); ?> (<?php echo ($emp['role'] == 'super_admin' ? 'مشرف' : 'موظف'); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <label for="log_date">التاريخ:</label>
                <input type="date" id="log_date" name="log_date" value="<?php echo htmlspecialchars($filter_date); ?>" required>
                
                <button type="submit">تطبيق الفلترة</button>
            </form>
        </div>

        <div class="panel">
            <h2>الإجراءات المسجلة</h2>
            <?php if (empty($log_entries)): ?>
                <p style="padding: 15px; background-color: #fef4e5; border: 1px solid #f9d7a9; border-radius: 8px;">لا توجد سجلات مطابقة لمعايير الفلترة المحددة.</p>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>التاريخ والوقت</th>
                            <th>الموظف (الدور)</th>
                            <th>الإجراء</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($log_entries as $entry): ?>
                            <tr>
                                <td><?php echo date("Y-m-d H:i:s", strtotime($entry['timestamp'])); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($entry['full_name']); ?> 
                                    (<?php 
                                        if ($entry['role'] == 'super_admin') {
                                            echo '<span class="admin-role">مشرف</span>';
                                        } else {
                                            echo '<span class="employee-role">موظف</span>';
                                        }
                                    ?>)
                                </td>
                                <td><?php echo htmlspecialchars($entry['action']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>