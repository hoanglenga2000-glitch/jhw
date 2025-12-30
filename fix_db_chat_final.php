<?php
// api/fix_db_chat_final.php - 强制重构聊天表
header('Content-Type: text/html; charset=utf-8');
// 显示所有错误，方便调试
error_reporting(E_ALL);
ini_set('display_errors', 1);
require '../config/db.php';

echo "<h2>🔧 正在强制重构聊天数据库...</h2>";

// 1. 暴力删除旧表 (解决字段不匹配的根源)
$drop = "DROP TABLE IF EXISTS `messages`";
if ($conn->query($drop)) {
    echo "<p>🗑️ 旧消息表已清除</p>";
}

// 2. 创建正确的新表
$sql = "CREATE TABLE `messages` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `sender_phone` VARCHAR(20) NOT NULL COMMENT '发送者手机',
    `receiver_phone` VARCHAR(20) NOT NULL COMMENT '接收者手机',
    `content` TEXT NOT NULL COMMENT '消息内容',
    `is_read` TINYINT DEFAULT 0 COMMENT '0未读 1已读',
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql)) {
    echo "<p>✅ 新消息表 (messages) 创建成功！</p>";
    
    // 3. 插入一条测试数据，验证字段是否正确
    $test_sql = "INSERT INTO messages (sender_phone, receiver_phone, content) VALUES ('13800000000', '13900000000', '系统测试消息：如果您看到这条，说明数据库修好了！')";
    
    if ($conn->query($test_sql)) {
        echo "<p style='color:green; font-weight:bold;'>🎉 字段验证通过！写入测试数据成功。</p>";
    } else {
        echo "<p style='color:red'>❌ 写入失败: " . $conn->error . "</p>";
    }
    
} else {
    echo "<p style='color:red'>❌ 建表失败: " . $conn->error . "</p>";
}

echo "<hr><h3>🚀 修复完成！请删除此文件，然后去页面尝试发送消息。</h3>";
?>