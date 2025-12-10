<?php
// fatura/auth_check.php

// بدء جلسة العمل (Session)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

/**
 * دالة التحقق من الدخول والصلاحية
 * @param string $required_role الدور المطلوب للدخول (مثل 'super_admin' أو 'employee')
 */
function check_auth($required_role = 'employee') {
    // التحقق الأول: هل المستخدم مسجل دخول؟
    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        // إذا لم يكن مسجلاً، قم بتحويله لصفحة الدخول
        header("Location: login.php");
        exit;
    }

    $current_role = $_SESSION['role'];

    // التحقق الثاني: هل لديه الصلاحية المطلوبة؟
    if ($required_role == 'super_admin' && $current_role !== 'super_admin') {
        // إذا كان مطلوبًا إداري، والمستخدم ليس كذلك، قم بمنعه
        // يمكن تحويله إلى لوحة تحكم الموظفين أو صفحة خطأ
        header("Location: dashboard_employee.php?error=unauthorized_access");
        exit;
    }
    
    // (إضافة لضمان أن الموظف لا يدخل صفحة الأدمن بالخطأ)
    if ($required_role == 'employee' && $current_role == 'super_admin') {
        // إذا كان مشرف عام يدخل صفحة موظف، دعه يمر ولكن قد تحتاج لتوحيد المسارات
        // هذا مجرد مثال على منطق الفصل
    }
}

// ملاحظة: لن يتم استدعاء check_auth هنا. سيتم استدعاؤها في رأس كل صفحة محمية.
?>