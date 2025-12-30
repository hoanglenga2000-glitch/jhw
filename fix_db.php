<?php
// api/fix_db.php - 数据库自动修复工具
header('Content-Type: text/html; charset=utf-8');
require '../config/db.php';

echo "<h2>正在修复数据库...</h2>";

// 1. 给 tutors 表增加 create_time 字段
$sql1 = "ALTER TABLE `tutors` ADD COLUMN `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP";
if ($conn->query($sql1)) {
    echo "<p style='color:green'>✅ 成功：'create_time' 字段已添加到 tutors 表。</p>";
} else {
    // 如果已存在会报错，属于正常
    echo "<p style='color:orange'>提示：" . $conn->error . "</p>";
}

// 2. 给 tutors 表增加 id_photo 字段 (防止之前的 SQL 没跑成功)
$sql2 = "ALTER TABLE `tutors` ADD COLUMN `id_photo` VARCHAR(255) DEFAULT NULL";
$conn->query($sql2);

// 3. 检查 uploads 和 assets 文件夹权限
$dirs = ['../uploads', '../assets'];
foreach ($dirs as $dir) {
    if (!file_exists($dir)) {
        if(mkdir($dir, 0777, true)) {
            echo "<p style='color:green'>✅ 成功：创建目录 $dir</p>";
        } else {
            echo "<p style='color:red'>❌ 失败：无法创建目录 $dir，请手动创建并给 777 权限</p>";
        }
    } else {
        echo "<p style='color:green'>✅ 检查：目录 $dir 已存在</p>";
    }
}

echo "<hr><h3>🎉 修复完成！请现在去 <a href='../join.html'>join.html</a> 重新提交注册。</h3>";
echo "<p>注意：为了安全，修复成功后建议删除本文件。</p>";

$conn->close();
?>