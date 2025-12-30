<?php
header('Content-Type: application/json');
require '../config/db.php';

$phone = $_POST['phone'];
$tutor_id = $_POST['tutor_id'];
$action = $_POST['action']; // 'add' or 'remove'

if(empty($phone)) { echo json_encode(["status"=>"error","message"=>"请先登录"]); exit; }

if($action == 'add') {
    // 查重
    $check = $conn->query("SELECT id FROM favorites WHERE user_phone='$phone' AND tutor_id='$tutor_id'");
    if($check->num_rows == 0) {
        $conn->query("INSERT INTO favorites (user_phone, tutor_id) VALUES ('$phone', '$tutor_id')");
    }
    echo json_encode(["status"=>"success", "message"=>"已收藏"]);
} else {
    $conn->query("DELETE FROM favorites WHERE user_phone='$phone' AND tutor_id='$tutor_id'");
    echo json_encode(["status"=>"success", "message"=>"已取消收藏"]);
}
$conn->close();
?>