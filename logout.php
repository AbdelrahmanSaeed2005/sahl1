<?php
// fatura/logout.php

session_start();

// إزالة جميع متغيرات الجلسة
$_SESSION = array();

// تدمير جلسة العمل
session_destroy();

// التوجيه إلى صفحة تسجيل الدخول
header("Location: login.php");
exit;
?>