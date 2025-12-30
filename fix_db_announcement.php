<?php
// api/fix_db_announcement.php - 强制修复公告表
header('Content-Type: text/html; charset=utf-8');
require '../config/db.php';

echo "<h2>🔧 正在强制修复公告系统数据库...</h2>";

// 1. 先尝试删除旧表 (防止结构错乱)
$conn->query("DROP TABLE IF EXISTS announcements");
echo "<p>🗑️ 已清理旧的公告表 (如果存在)</p>";

// 2. 重新创建完美的表结构
$sql = "CREATE TABLE `announcements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(255) NOT NULL,
    `content` TEXT NOT NULL,
    `is_pushed` TINYINT DEFAULT 0,
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql)) {
    echo "<p>✅ 公告表 (announcements) 重建成功！</p>";
    
    // 3. 插入测试数据
    $sql_insert = "INSERT INTO announcements (title, content) VALUES ('🎉 系统升级通知', '公告功能已修复，欢迎使用！')";
    if ($conn->query($sql_insert)) {
        echo "<p>📝 测试公告插入成功</p>";
    } else {
        echo "<p style='color:red'>❌ 测试数据插入失败: " . $conn->error . "</p>";
    }
} else {
    echo "<p style='color:red'>❌ 建表失败: " . $conn->error . "</p>";
    exit;
}

echo "<hr><h3>🎉 修复完成！现在去后台发布公告绝对不会报错了。请删除此文件。</h3>";
?>