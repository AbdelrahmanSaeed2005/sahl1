<?php
// fatura/manage_users.php
// إدارة المستخدمين: إضافة، تعديل، تفعيل/تعطيل (CRUD)

require_once 'auth_check.php';
// التحقق: يجب أن يكون super_admin
check_auth('super_admin'); 

require_once 'database/db_conn.php'; 

$message = '';
$edit_user = null; // لتخزين بيانات المستخدم المراد تعديله

// --------------------------------------------------
// أ. معالجة الإضافة/التعديل (Add/Update)
// --------------------------------------------------
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $full_name = trim($_POST['full_name']);
    $role = $_POST['role'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $password = $_POST['password'] ?? '';
    $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;

    // التحقق من الدور المدخل
    if (!in_array($role, ['super_admin', 'employee'])) {
        $message = "❌ خطأ: دور غير صالح.";
    } 
    // التأكد من أن المشرف لا يلغي تفعيل حسابه الخاص
    elseif ($user_id === $_SESSION['user_id'] && $is_active == 0) {
        $message = "❌ خطأ: لا يمكنك تعطيل حسابك الخاص أثناء تسجيل الدخول.";
    } 
    // التأكد من أن المشرف لا يغير صلاحية حسابه الخاص
    elseif ($user_id === $_SESSION['user_id'] && $role !== 'super_admin') {
        $message = "❌ خطأ: لا يمكنك تغيير دورك الخاص من مشرف إلى دور أقل.";
    }
    elseif ($user_id > 0) {
        // --- تعديل المستخدم الحالي ---
        
        $sql = "UPDATE users SET username=?, full_name=?, role=?, is_active=? ";
        $params = "sssi";
        $data = [$username, $full_name, $role, $is_active];
        
        // إذا تم إدخال كلمة مرور جديدة، يجب تشفيرها
        if (!empty($password)) {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $sql .= ", password=? ";
            $params .= "s";
            $data[] = $hashed_password;
        }
// ...
// بعد إضافة البارامترات الخاصة بكلمة المرور (إن وجدت)
$sql .= " WHERE id=?";
$params .= "i"; // 💡 يجب إضافة نوع البيانات 'i' هنا لتطابق عدد المتغيرات المربوطة
$data[] = $user_id;

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $params, ...$data); // هذا هو السطر 56 الذي كان يعطي الخطأ
// ...

        if (mysqli_stmt_execute($stmt)) {
            $message = "✅ تم تحديث بيانات المستخدم بنجاح." . (!empty($password) ? " (وتم تحديث كلمة المرور)." : "");
        } else {
            // خطأ شائع هو تكرار اسم المستخدم
            $message = "❌ خطأ في التحديث: " . (mysqli_errno($conn) == 1062 ? "اسم المستخدم موجود بالفعل." : mysqli_error($conn));
        }
        mysqli_stmt_close($stmt);

    } else {
        // --- إضافة مستخدم جديد (يتطلب كلمة مرور) ---
        if (empty($password)) {
            $message = "❌ خطأ: يجب إدخال كلمة مرور للمستخدم الجديد.";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $sql = "INSERT INTO users (username, password, full_name, role, is_active) VALUES (?, ?, ?, ?, ?)";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "ssssi", $username, $hashed_password, $full_name, $role, $is_active);

            if (mysqli_stmt_execute($stmt)) {
                $message = "✅ تم إضافة المستخدم الجديد بنجاح.";
            } else {
                 $message = "❌ خطأ في الإضافة: " . (mysqli_errno($conn) == 1062 ? "اسم المستخدم موجود بالفعل." : mysqli_error($conn));
            }
            mysqli_stmt_close($stmt);
        }
    }
}

// --------------------------------------------------
// ب. معالجة طلب التعديل (Fetch for Edit)
// --------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    // --- جلب بيانات المستخدم المراد تعديله ---
    $sql = "SELECT id, username, full_name, role, is_active FROM users WHERE id = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        $edit_user = $row;
    }
    mysqli_stmt_close($stmt);
}


// --------------------------------------------------
// ج. جلب جميع المستخدمين للعرض في الجدول
// --------------------------------------------------
$users = [];
// لا يتم عرض كلمة المرور
$sql_fetch = "SELECT id, username, full_name, role, is_active FROM users ORDER BY id DESC";
$result_fetch = mysqli_query($conn, $sql_fetch);

if ($result_fetch) {
    while ($row = mysqli_fetch_assoc($result_fetch)) {
        $users[] = $row;
    }
}

// --------------------------------------------------
// د. إغلاق الاتصال وعرض الواجهة
// --------------------------------------------------
mysqli_close($conn); 
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>إدارة المستخدمين</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        /* ==================================== */
        /* تصميم مودرن بألوان هادئة ومريحة للعين */
        /* ==================================== */
        body { 
            font-family: 'Cairo', Tahoma, sans-serif; 
            background-color: #f4f6f9; /* خلفية ناعمة جداً */
            margin: 0; padding: 0; 
            display: flex; /* لدمج الشريط الجانبي */
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
            border-top: 5px solid #3498db; /* شريط أزرق مميز */
        }
        .table-container { border-top: 5px solid #7f8c8d; /* شريط رمادي للجداول */ }
        .form-container h2, .table-container h2 { color: #2c3e50; margin-top: 0; border-bottom: 1px solid #ecf0f1; padding-bottom: 10px; margin-bottom: 20px; }

        /* حقول الإدخال */
        input[type="text"], input[type="password"], select { 
            padding: 10px; 
            margin: 5px 0; 
            border: 1px solid #bdc3c7; 
            border-radius: 6px; 
            width: 100%; 
            box-sizing: border-box; 
            transition: border-color 0.3s, box-shadow 0.3s;
        }
        input[type="text"]:focus, input[type="password"]:focus, select:focus {
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
            outline: none;
        }
        .form-group { margin-bottom: 20px; }
        label { font-weight: 600; display: block; margin-bottom: 5px; color: #34495e; }

        /* الأزرار */
        button, .btn-warning { 
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
        button { background-color: #3498db; color: white; }
        button:hover { opacity: 0.9; }
        .btn-warning { background-color: #f39c12; color: white; }
        .btn-warning:hover { opacity: 0.9; }
        .btn-action-group a { margin-left: 10px; } /* تباعد بين أزرار الإجراء */

        /* رسائل النظام */
        .message { 
            padding: 15px; 
            margin-bottom: 25px; 
            border-radius: 8px; 
            font-weight: 600;
            border-left: 5px solid; 
        }
        .success { background-color: #d4edda; color: #155724; border-color: #2ecc71; }
        .error { background-color: #f8d7da; color: #721c24; border-color: #e74c3c; }
        
        /* الجدول */
        table { 
            width: 100%; 
            border-collapse: separate; 
            border-spacing: 0; 
            margin-top: 20px; 
            border-radius: 8px; 
            overflow: hidden; /* لحفظ الزوايا الدائرية */
        }
        th, td { 
            padding: 12px 15px; 
            text-align: right; 
            border-bottom: 1px solid #ecf0f1; 
        }
        th { 
            background-color: #ecf0f1; 
            color: #2c3e50; 
            font-weight: 700; 
        }
        tr:last-child td { border-bottom: none; }

        /* حالة التفعيل */
        .active-status { font-weight: 700; padding: 5px 10px; border-radius: 4px; display: inline-block; }
        .active-yes { background-color: #e8f9ed; color: #27ae60; }
        .active-no { background-color: #fbebeb; color: #e74c3c; }
        
        /* زر تفعيل/تعطيل الحساب داخل الفورم */
        #is_active + label { display: inline-block; margin-right: 15px; font-weight: 400; }
        #is_active { width: auto; margin-left: 5px; }

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
        <h1>👤 إدارة المستخدمين والموظفين</h1>
        
        <?php if ($message): ?>
            <div class="message <?php echo strpos($message, '✅') !== false ? 'success' : 'error'; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <div class="form-container">
            <h2><?php echo $edit_user ? 'تعديل المستخدم: ' . htmlspecialchars($edit_user['full_name']) : 'إضافة مستخدم جديد'; ?></h2>
            <form method="post" action="manage_users.php">
                <?php if ($edit_user): ?>
                    <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($edit_user['id']); ?>">
                <?php endif; ?>
                
                <div class="form-group">
                    <label for="full_name">الاسم الكامل:</label>
                    <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($edit_user['full_name'] ?? ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="username">اسم المستخدم (للدخول):</label>
                    <input type="text" id="username" name="username" value="<?php echo htmlspecialchars($edit_user['username'] ?? ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="password">كلمة المرور (<?php echo $edit_user ? 'اتركها فارغة لعدم التغيير' : 'مطلوبة'; ?>):</label>
                    <input type="password" id="password" name="password" <?php echo $edit_user ? '' : 'required'; ?>>
                </div>

                <div class="form-group">
                    <label for="role">الدور / الصلاحية:</label>
                    <select id="role" name="role" required>
                        <option value="employee" <?php echo ($edit_user && $edit_user['role'] == 'employee') ? 'selected' : ''; ?>>موظف (Employee)</option>
                        <option value="super_admin" <?php echo ($edit_user && $edit_user['role'] == 'super_admin') ? 'selected' : ''; ?>>مشرف عام (Super Admin)</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <input type="checkbox" id="is_active" name="is_active" value="1" <?php echo (!isset($edit_user) || $edit_user['is_active'] == 1) ? 'checked' : ''; ?>>
                    <label for="is_active">الحساب مفعل</label>
                </div>
                
                <div class="btn-action-group">
                    <button type="submit"><?php echo $edit_user ? 'حفظ التعديلات' : 'إضافة المستخدم'; ?></button>
                    <?php if ($edit_user): ?>
                        <a href="manage_users.php" class="btn-warning">إلغاء التعديل</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="table-container">
            <h2>قائمة المستخدمين الحالية (<?php echo count($users); ?>)</h2>
            <table>
                <thead>
                    <tr>
                        <th>الاسم الكامل</th>
                        <th>اسم المستخدم</th>
                        <th>الدور</th>
                        <th>الحالة</th>
                        <th>الإجراءات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                            <td><?php echo ($user['role'] == 'super_admin') ? 'مشرف عام 👑' : 'موظف 🧑‍💼'; ?></td>
                            <td>
                                <span class="active-status <?php echo $user['is_active'] ? 'active-yes' : 'active-no'; ?>">
                                    <?php echo $user['is_active'] ? 'مفعل' : 'معطل'; ?>
                                </span>
                            </td>
                            <td>
                                <a href="manage_users.php?action=edit&id=<?php echo $user['id']; ?>" class="btn-warning" style="text-decoration: none; padding: 8px 15px;">تعديل</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>