<?php
// api/fix_student_db.php - 学生端数据库升级工具
header('Content-Type: text/html; charset=utf-8');
require '../config/db.php';

echo "<h2>正在升级学生端数据库...</h2>";

// 1. 升级 users 表 (增加头像、余额、年级)
$sql1 = "ALTER TABLE `users` 
         ADD COLUMN `avatar` VARCHAR(255) DEFAULT 'default_student.png',
         ADD COLUMN `balance` DECIMAL(10,2) DEFAULT 0.00,
         ADD COLUMN `grade` VARCHAR(50) DEFAULT '小学/初中',
         ADD COLUMN `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP";

if ($conn->query($sql1)) echo "<p style='color:green'>✅ users 表升级成功</p>";
else echo "<p style='color:orange'>提示 (users): " . $conn->error . "</p>";

// 2. 升级 bookings 表 (增加支付状态、金额)
$sql2 = "ALTER TABLE `bookings` 
         ADD COLUMN `price` DECIMAL(10,2) DEFAULT 0.00,
         ADD COLUMN `payment_status` VARCHAR(20) DEFAULT 'unpaid' COMMENT 'unpaid/paid',
         ADD COLUMN `is_reviewed` INT DEFAULT 0";

if ($conn->query($sql2)) echo "<p style='color:green'>✅ bookings 表升级成功</p>";
else echo "<p style='color:orange'>提示 (bookings): " . $conn->error . "</p>";

echo "<hr><h3>🎉 升级完成！请删除本文件。</h3>";
$conn->close();
?>