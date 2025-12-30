<?php
// cancel_booking.php - 取消预约接口
header('Content-Type: application/json');
require '../config/db.php';

$id = isset($_POST['id']) ? $_POST['id'] : '';
$phone = isset($_POST['phone']) ? $_POST['phone'] : '';

if(empty($id) || empty($phone)) {
    echo json_encode(["status" => "error", "message" => "参数错误"]);
    exit;
}

// 验证该订单是否属于该用户，且状态为'待确认'
$check = $conn->query("SELECT * FROM bookings WHERE id='$id' AND user_phone='$phone'");
if($check->num_rows == 0) {
    echo json_encode(["status" => "error", "message" => "订单不存在或无权操作"]);
    exit;
}

$row = $check->fetch_assoc();
if($row['status'] !== '待确认') {
    echo json_encode(["status" => "error", "message" => "只能取消'待确认'状态的申请"]);
    exit;
}

// 执行取消
$sql = "UPDATE bookings SET status='已取消' WHERE id='$id'";
if ($conn->query($sql) === TRUE) {
    echo json_encode(["status" => "success", "message" => "预约已取消"]);
} else {
    echo json_encode(["status" => "error", "message" => "系统错误"]);
}
$conn->close();
?>