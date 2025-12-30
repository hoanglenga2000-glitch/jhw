<?php
header('Content-Type: application/json');
require '../config/db.php';

$name = $_POST['name'];
$gender = $_POST['gender'];
$phone = $_POST['phone'];
$pwd = $_POST['password']; // 新增：接收密码
$university = $_POST['university'];
$major = $_POST['major'];
$good_at = $_POST['good_at'];

if(empty($name) || empty($phone) || empty($pwd)) {
    echo json_encode(["status" => "error", "message" => "信息不完整"]); exit;
}

// 插入申请表
$sql = "INSERT INTO applications (name, gender, phone, password, university, major, good_at) 
        VALUES ('$name', '$gender', '$phone', '$pwd', '$university', '$major', '$good_at')";

if ($conn->query($sql) === TRUE) {
    echo json_encode(["status" => "success", "message" => "申请已提交！请等待管理员审核。"]);
} else {
    echo json_encode(["status" => "error", "message" => "提交失败: " . $conn->error]);
}
$conn->close();
?>