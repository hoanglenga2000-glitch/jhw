<?php
// api/fix_db_chat_v2.php - 强制修复聊天数据库
header('Content-Type: text/html; charset=utf-8');
require '../config/db.php';

echo "<h2>🔧 正在修复聊天系统数据库...</h2>";

// 1. 检查并创建 messages 表
$sql = "CREATE TABLE IF NOT EXISTS `messages` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `sender_phone` VARCHAR(20) NOT NULL COMMENT '发送者手机',
    `receiver_phone` VARCHAR(20) NOT NULL COMMENT '接收者手机',
    `content` TEXT NOT NULL COMMENT '消息内容',
    `is_read` TINYINT DEFAULT 0 COMMENT '0未读 1已读',
    `create_time` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

if ($conn->query($sql)) {
    echo "<p>✅ 消息表 (messages) 检测/修复成功</p>";
    
    // 2. 插入一条测试消息 (方便你调试)
    // 假设发给一个测试号码
    $test_sql = "INSERT INTO messages (sender_phone, receiver_phone, content) VALUES ('13800000000', '13900000000', '这是一条测试消息，看到说明数据库通了')";
    if($conn->query($test_sql)) {
        echo "<p>📝 测试消息写入成功！数据库连接正常。</p>";
    } else {
        echo "<p style='color:red'>❌ 测试写入失败: " . $conn->error . "</p>";
    }
    
} else {
    echo "<p style='color:red'>❌ 建表失败: " . $conn->error . "</p>";
}

echo "<hr><h3>🎉 修复完成！请删除此文件并刷新页面尝试发送。</h3>";
?>