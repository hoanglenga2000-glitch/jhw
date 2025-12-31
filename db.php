<?php
$servername = "localhost";
$username = "jhw";  // ⚠️请确认这是你的数据库账号
$password = "jhw20041108"; // ⚠️请务必修改为宝塔里的真实密码
$dbname = "jhw";    // ⚠️请确认这是你的数据库名

$conn = @new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) { 
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        "status" => "error",
        "message" => "数据库连接失败: " . $conn->connect_error
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$conn->set_charset("utf8mb4");
?>