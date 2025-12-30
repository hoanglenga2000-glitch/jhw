<?php
// book_action.php - 升级版：支持保存时间和备注
header('Content-Type: application/json');
require '../config/db.php';

$phone = isset($_POST['phone']) ? $_POST['phone'] : '';
$tutor_name = isset($_POST['tutor_name']) ? $_POST['tutor_name'] : '';
// 新增接收两个参数
$lesson_time = isset($_POST['lesson_time']) ? $_POST['lesson_time'] : '协商';
$requirement = isset($_POST['requirement']) ? $_POST['requirement'] : '无';

if(empty($phone) || empty($tutor_name)) {
    echo json_encode(["status" => "error", "message" => "信息不完整"]);
    exit;
}

// 检查重复
$check = $conn->query("SELECT * FROM bookings WHERE user_phone='$phone' AND tutor_name='$tutor_name' AND status='待确认'");
if($check->num_rows > 0) {
    echo json_encode(["status" => "error", "message" => "您已申请过该老师，请勿重复提交"]);
    exit;
}

// 插入数据 (包含新字段)
$sql = "INSERT INTO bookings (user_phone, tutor_name, lesson_time, requirement) VALUES ('$phone', '$tutor_name', '$lesson_time', '$requirement')";

if ($conn->query($sql) === TRUE) {
    echo json_encode(["status" => "success", "message" => "预约申请已提交"]);
} else {
    echo json_encode(["status" => "error", "message" => "系统错误: " . $conn->error]);
}
$conn->close();
?>