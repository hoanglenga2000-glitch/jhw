<?php
// api/fix_booking.php - 修复预约表字段
header('Content-Type: text/html; charset=utf-8');
require '../config/db.php';

echo "<h2>正在修复 bookings 表结构...</h2>";

// 1. 添加 payment_status 字段
$sql1 = "ALTER TABLE `bookings` ADD COLUMN `payment_status` VARCHAR(20) DEFAULT 'unpaid'";
if ($conn->query($sql1)) echo "<p style='color:green'>✅ 成功添加 payment_status 字段</p>";
else echo "<p style='color:orange'>提示: " . $conn->error . "</p>";

// 2. 添加 price 字段
$sql2 = "ALTER TABLE `bookings` ADD COLUMN `price` DECIMAL(10,2) DEFAULT 0.00";
if ($conn->query($sql2)) echo "<p style='color:green'>✅ 成功添加 price 字段</p>";
else echo "<p style='color:orange'>提示: " . $conn->error . "</p>";

// 3. 添加 is_reviewed 字段
$sql3 = "ALTER TABLE `bookings` ADD COLUMN `is_reviewed` INT DEFAULT 0";
if ($conn->query($sql3)) echo "<p style='color:green'>✅ 成功添加 is_reviewed 字段</p>";
else echo "<p style='color:orange'>提示: " . $conn->error . "</p>";

echo "<hr><h3>🎉 修复完成！请现在去测试支付。</h3>";
echo "<p>建议删除本文件。</p>";
?>