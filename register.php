<?php
// register.php - 用户注册接口
header('Content-Type: application/json');
require '../config/db.php';

// 1. 获取前端数据
$phone = isset($_POST['phone']) ? $_POST['phone'] : '';
$password = isset($_POST['password']) ? $_POST['password'] : '';
$username = isset($_POST['username']) ? $_POST['username'] : '';

// 2. 基础验证
if(empty($phone) || empty($password) || empty($username)) {
    echo json_encode(["status" => "error", "message" => "请填写完整信息（姓名、手机、密码）"]);
    exit;
}

// 3. 检查手机号是否已被注册
$check = $conn->query("SELECT id FROM users WHERE phone = '$phone'");
if($check->num_rows > 0) {
    echo json_encode(["status" => "error", "message" => "该手机号已被注册，请直接登录"]);
    exit;
}

// 4. 插入新用户
// 注意：实际项目中密码应该加密 (password_hash)，这里为了教学演示使用明文
$sql = "INSERT INTO users (username, password, phone) VALUES ('$username', '$password', '$phone')";

if ($conn->query($sql) === TRUE) {
    echo json_encode(["status" => "success", "message" => "注册成功！请登录"]);
} else {
    echo json_encode(["status" => "error", "message" => "系统错误: " . $conn->error]);
}

$conn->close();
?>