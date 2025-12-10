<?php
// fatura/login.php
// واجهة تسجيل الدخول الموحدة للموظفين والمشرفين

session_start(); // ابدأ الجلسة فوراً
require_once 'database/db_conn.php'; // لفتح الاتصال بالداتا بيز

$error_message = '';

// التحقق مما إذا كان المستخدم قد أرسل بيانات النموذج
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    // 1. استخدام Prepared Statement لضمان الحماية من SQL Injection
    $sql = "SELECT id, password, full_name, role, is_active FROM users WHERE username = ?";
    
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $username);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    
    if ($row = mysqli_fetch_assoc($result)) {
        
        // 2. التحقق من حالة تفعيل الحساب
        if ($row['is_active'] == 0) {
             $error_message = "🔴 هذا الحساب غير مفعل. يرجى التواصل مع الإدارة.";
        }
        
        // 3. التحقق من كلمة المرور المشفرة
        elseif (password_verify($password, $row['password'])) {
            // تسجيل الدخول ناجح: تخزين بيانات الجلسة
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['username'] = $username;
            $_SESSION['full_name'] = $row['full_name'];
            $_SESSION['role'] = $row['role']; // super_admin أو employee
            
            // 4. التوجيه بناءً على الدور
            if ($row['role'] == 'super_admin') {
                header("Location: dashboard_admin.php");
            } else {
                header("Location: pos.php"); // توجيه الموظف مباشرة لنقطة البيع أو لوحة التحكم الخاصة به
            }
            exit;
        } else {
            $error_message = "🔴 اسم المستخدم أو كلمة المرور غير صحيحة.";
        }
    } else {
        $error_message = "🔴 اسم المستخدم أو كلمة المرور غير صحيحة.";
    }

    mysqli_stmt_close($stmt);
}

mysqli_close($conn);
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>تسجيل الدخول - نظام إدارة المبيعات</title>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        /* ==================================== */
        /* التعديلات الجديدة للتصميم الحديث */
        /* ==================================== */
        body { 
            font-family: 'Cairo', Tahoma, sans-serif; /* استخدام خط Cairo */
            background-color: #eef1f5; /* خلفية رمادية فاتحة حديثة */
            display: flex; 
            justify-content: center; 
            align-items: center; 
            height: 100vh; 
            margin: 0;
            direction: rtl; /* ضمان الاتجاه من اليمين لليسار */
        }
        
        .login-container { 
            background-color: #ffffff; /* خلفية بيضاء نظيفة */
            padding: 40px; 
            border-radius: 15px; /* حواف أكثر استدارة */
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1); /* ظل أعمق وأكثر انتشاراً */
            width: 350px; 
            text-align: center;
            transition: transform 0.3s ease;
        }

        .login-container:hover {
            transform: translateY(-3px); /* تأثير رفع خفيف عند المرور */
        }

        h2 {
            color: #1e3a8a; /* لون أزرق داكن للعناوين */
            margin-bottom: 25px;
            font-weight: 700;
        }

        label {
            display: block;
            text-align: right;
            margin-top: 15px;
            margin-bottom: 5px;
            color: #4a5568; /* لون نص رمادي داكن للقراءة */
            font-weight: 600;
            font-size: 0.95em;
        }

        input[type="text"], input[type="password"] { 
            width: 100%; 
            padding: 12px; 
            margin: 0 0 15px 0;
            border: 1px solid #e2e8f0; /* حدود خفيفة جداً */
            border-radius: 8px; 
            box-sizing: border-box;
            background-color: #f7f9fb; /* لون خلفية خفيف لحقول الإدخال */
            transition: border-color 0.3s, box-shadow 0.3s;
        }
        
        input[type="text"]:focus, input[type="password"]:focus {
            border-color: #1e3a8a; /* حدود زرقاء عند التركيز */
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.2); /* ظل التركيز */
            outline: none;
            background-color: #ffffff;
        }

        button { 
            background-color: #1e3a8a; /* اللون الأساسي (أزرق داكن) */
            color: white; 
            padding: 12px 15px; 
            border: none; 
            border-radius: 8px; 
            cursor: pointer; 
            width: 100%; 
            font-size: 16px; 
            font-weight: 700;
            margin-top: 20px;
            transition: background-color 0.3s ease, transform 0.2s;
        }

        button:hover { 
            background-color: #1c336b; /* لون أغمق عند المرور */
            transform: translateY(-1px);
        }
        
        button:active {
            transform: translateY(0);
        }

        .error { 
            color: #e53e3e; /* أحمر داكن للأخطاء */
            background-color: #fee2e2;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid #fbb6ce;
            font-weight: 600;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <h2>تسجيل الدخول 🔑</h2>
        <?php if (isset($error_message) && $error_message): ?>
            <p class="error"><?php echo htmlspecialchars($error_message); ?></p>
        <?php endif; ?>
        <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
            
            <label for="username">اسم المستخدم:</label>
            <input type="text" id="username" name="username" required autocomplete="username">
            
            <label for="password">كلمة المرور:</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
            
            <button type="submit">دخول إلى النظام</button>
        </form>
    </div>
</body>
</html>