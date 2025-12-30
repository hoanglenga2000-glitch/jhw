<?php
// api/login.php - 含封禁检查的学生登录
header('Content-Type: application/json');
require '../config/db.php';

$phone = $_POST['phone'];
$password = $_POST['password'];

$stmt = $conn->prepare("SELECT * FROM users WHERE phone = ? AND password = ?");
$stmt->bind_param("ss", $phone, $password);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    // ✨ 检查封禁状态
    if ($row['is_banned'] == 1) {
        echo json_encode(["status" => "error", "message" => "该账号已被管理员封禁，请联系客服。"]);
        exit;
    }
    
    // 登录成功
    echo json_encode(["status" => "success", "username" => $row['username']]);
} else {
    echo json_encode(["status" => "error", "message" => "手机号或密码错误"]);
}
$stmt->close();
$conn->close();
?>