<?php
// api/book_tutor.php - 修复版：预约时自动锁定价格
header('Content-Type: application/json');
require '../config/db.php';

$user_phone = isset($_POST['user_phone']) ? $_POST['user_phone'] : '';
$tutor_name = isset($_POST['tutor_name']) ? trim($_POST['tutor_name']) : ''; // 去除首尾空格
$lesson_time = isset($_POST['lesson_time']) ? $_POST['lesson_time'] : '';
$requirement = isset($_POST['requirement']) ? $conn->real_escape_string($_POST['requirement']) : '';

if (empty($user_phone) || empty($tutor_name)) {
    echo json_encode(["status" => "error", "message" => "参数缺失"]);
    exit;
}

// 1. 核心修复：先查询该老师的当前价格
$price = 0;
$t_res = $conn->query("SELECT price FROM tutors WHERE name='$tutor_name'");
if ($t_res && $row = $t_res->fetch_assoc()) {
    $price = $row['price'];
}

// 2. 插入订单时，把 price 也存进去
$sql = "INSERT INTO bookings (user_phone, tutor_name, lesson_time, requirement, status, payment_status, price, create_time) 
        VALUES ('$user_phone', '$tutor_name', '$lesson_time', '$requirement', '待确认', 'unpaid', '$price', NOW())";

if ($conn->query($sql)) {
    echo json_encode(["status" => "success"]);
} else {
    echo json_encode(["status" => "error", "message" => "数据库错误: " . $conn->error]);
}

$conn->close();
?>