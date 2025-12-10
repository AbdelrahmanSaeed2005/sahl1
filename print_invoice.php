<?php
// fatura/print_invoice.php
// صفحة عرض الفاتورة وطباعتها

require_once 'auth_check.php';
// هذه الصفحة محمية لكن يمكن للموظفين الوصول إليها
check_auth('employee'); 

require_once 'database/db_conn.php'; 

$invoice_id = isset($_GET['invoice_id']) ? intval($_GET['invoice_id']) : 0;
$invoice = null;
$details = [];

if ($invoice_id > 0) {
    
    // 1. جلب بيانات رأس الفاتورة والموظف
    $sql_invoice = "
        SELECT 
            i.id, i.invoice_date, i.total_amount, u.full_name AS employee_name
        FROM invoices i
        JOIN users u ON i.user_id = u.id
        WHERE i.id = ?
    ";
    $stmt_invoice = mysqli_prepare($conn, $sql_invoice);
    mysqli_stmt_bind_param($stmt_invoice, "i", $invoice_id);
    mysqli_stmt_execute($stmt_invoice);
    $result_invoice = mysqli_stmt_get_result($stmt_invoice);
    $invoice = mysqli_fetch_assoc($result_invoice);
    mysqli_stmt_close($stmt_invoice);

    if ($invoice) {
        // 2. جلب تفاصيل المنتجات في الفاتورة
        $sql_details = "
            SELECT 
                id.quantity_sold, id.unit_price, id.unit_price * id.quantity_sold AS subtotal,
                p.name AS product_name
            FROM invoice_details id
            JOIN products p ON id.product_id = p.id
            WHERE id.invoice_id = ?
        ";
        $stmt_details = mysqli_prepare($conn, $sql_details);
        mysqli_stmt_bind_param($stmt_details, "i", $invoice_id);
        mysqli_stmt_execute($stmt_details);
        $result_details = mysqli_stmt_get_result($stmt_details);
        
        while ($row = mysqli_fetch_assoc($result_details)) {
            $details[] = $row;
        }
        mysqli_stmt_close($stmt_details);
        
    }
}

mysqli_close($conn);

if (!$invoice) {
    die("❌ خطأ: لم يتم العثور على الفاتورة المطلوبة.");
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>فاتورة رقم #<?php echo $invoice['id']; ?></title>
    <style>
        body { font-family: 'Tahoma', sans-serif; background-color: #f4f4f4; padding: 20px; }
        .invoice-box { 
            max-width: 600px; margin: auto; padding: 30px; border: 1px solid #eee; 
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.15); font-size: 14px; line-height: 24px; 
            color: #555; background: #fff; 
        }
        .invoice-box table { width: 100%; line-height: inherit; text-align: right; }
        .invoice-box table td { padding: 5px; vertical-align: top; }
        .invoice-box table tr.top table td { padding-bottom: 20px; }
        .invoice-box table tr.heading td { background: #eee; border-bottom: 1px solid #ddd; font-weight: bold; }
        .invoice-box table tr.item td { border-bottom: 1px solid #eee; }
        .invoice-box table tr.total td { border-top: 2px solid #eee; font-weight: bold; }
        .rtl { text-align: right; }

        /* Media query للطباعة */
        @media print {
            body { background: none; }
            .print-btn, .back-btn { display: none; }
            .invoice-box { box-shadow: none; border: 0; padding: 0; }
        }
    </style>
</head>
<body>
    <div class="invoice-box">
        <table cellpadding="0" cellspacing="0">
            <tr class="top">
                <td colspan="4">
                    <table>
                        <tr>
                            <td class="rtl">
                                🏢 **اسم الشركة/المتجر**<br>
                                التاريخ: <?php echo date('Y-m-d H:i', strtotime($invoice['invoice_date'])); ?><br>
                                البائع: <?php echo htmlspecialchars($invoice['employee_name']); ?>
                            </td>
                            <td class="rtl" style="text-align: left;">
                                الفاتورة رقم: #<?php echo $invoice['id']; ?>
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
            
            <tr class="heading">
                <td>المنتج</td>
                <td>الكمية</td>
                <td>سعر الوحدة</td>
                <td style="text-align: left;">الإجمالي</td>
            </tr>
            
            <?php foreach ($details as $item): ?>
            <tr class="item">
                <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                <td><?php echo $item['quantity_sold']; ?></td>
                <td><?php echo number_format($item['unit_price'], 2); ?> ر.س</td>
                <td style="text-align: left;"><?php echo number_format($item['subtotal'], 2); ?> ر.س</td>
            </tr>
            <?php endforeach; ?>
            
            <tr class="total">
                <td></td>
                <td></td>
                <td></td>
                <td style="text-align: left;">
                   المجموع الكلي: <?php echo number_format($invoice['total_amount'], 2); ?> ر.س
                </td>
            </tr>
        </table>
        
        <p style="text-align: center; margin-top: 30px; font-size: 12px; color: #aaa;">شكراً لتعاملكم معنا. نأمل أن نراكم قريباً!</p>
    </div>
    
    <div style="max-width: 600px; margin: 15px auto; text-align: center;">
        <button onclick="window.print()" class="print-btn" style="padding: 10px 20px; background-color: #28a745; color: white; border: none; border-radius: 4px; cursor: pointer;">طباعة الفاتورة</button>
        <a href="pos.php" class="back-btn" style="padding: 10px 20px; background-color: #6c757d; color: white; border-radius: 4px; text-decoration: none;">عملية بيع جديدة</a>
    </div>

</body>
</html>