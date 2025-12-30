<?php
// api/notification_api.php - 通知中心接口
header('Content-Type: application/json');
require '../config/db.php';

$action = isset($_GET['action']) ? $_GET['action'] : '';

// 1. 获取全站公告
if ($action == 'get_announcements') {
    $sql = "SELECT * FROM announcements ORDER BY create_time DESC LIMIT 5";
    $result = $conn->query($sql);
    $list = [];
    while($row = $result->fetch_assoc()) $list[] = $row;
    echo json_encode(["status" => "success", "data" => $list]);
}

// 2. 获取我的消息
else if ($action == 'get_my_messages') {
    $phone = isset($_GET['phone']) ? $_GET['phone'] : '';
    if(!$phone) { echo json_encode(["status"=>"error"]); exit; }
    
    $sql = "SELECT * FROM messages WHERE user_phone = '$phone' ORDER BY create_time DESC";
    $result = $conn->query($sql);
    $list = [];
    while($row = $result->fetch_assoc()) $list[] = $row;
    echo json_encode(["status" => "success", "data" => $list]);
}

// 3. 模拟发送消息 (用于测试，实际应在预约逻辑里触发)
else if ($action == 'send_test_msg') {
    $phone = $_GET['phone'];
    $sql = "INSERT INTO messages (user_phone, title, content) VALUES ('$phone', '系统通知', '这是一条测试消息 ".date('H:i')."')";
    $conn->query($sql);
    echo json_encode(["status" => "success"]);
}

$conn->close();
?>