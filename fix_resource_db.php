<?php
// api/fix_resource_db.php - 修复资料库并注入测试数据
header('Content-Type: text/html; charset=utf-8');
require '../config/db.php';

echo "<h2>🔧 正在修复资料商城数据库...</h2>";

// 1. 重建 resources 表 (确保有 price 和 sales 字段)
$conn->query("DROP TABLE IF EXISTS resources");
$sql = "CREATE TABLE `resources` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(255) NOT NULL,
    `type` VARCHAR(50) DEFAULT 'PDF',
    `description` TEXT,
    `file_path` VARCHAR(255) NOT NULL,
    `uploader_phone` VARCHAR(20) NOT NULL,
    `price` DECIMAL(10,2) DEFAULT 0.00,
    `sales` INT DEFAULT 0,
    `downloads` INT DEFAULT 0,
    `status` ENUM('待审核', '已通过', '已拒绝') DEFAULT '待审核',
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql)) {
    echo "<p>✅ 资料表 (resources) 重建成功</p>";
    
    // 2. 插入测试数据
    // 插入一条【待审核】的 (管理员应该能看到)
    $sql1 = "INSERT INTO resources (title, type, uploader_phone, price, status, file_path) 
             VALUES ('【测试】高中数学必修一笔记 (待审核)', 'PDF', '13800000000', 9.90, '待审核', 'demo.pdf')";
    
    // 插入一条【已通过】的 (前台应该能看到)
    $sql2 = "INSERT INTO resources (title, type, uploader_phone, price, status, file_path) 
             VALUES ('【测试】英语满分作文模板 (已上架)', 'DOCX', '13800000000', 0.00, '已通过', 'demo.docx')";
             
    if ($conn->query($sql1) && $conn->query($sql2)) {
        echo "<p>📝 测试数据注入成功！<br>- 一条待审核 (去后台看)<br>- 一条已上架 (去前台看)</p>";
    }
} else {
    echo "<p style='color:red'>❌ 建表失败: " . $conn->error . "</p>";
}

echo "<hr><h3>🎉 修复完成！请删除此文件。</h3>";
?>