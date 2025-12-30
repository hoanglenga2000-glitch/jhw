<?php
// api/my_courses.php - 完整版 (关联教员ID)
header('Content-Type: application/json');
require '../config/db.php';

$phone = isset($_GET['phone']) ? $_GET['phone'] : '';

if($phone) {
    // 关联查询：通过 bookings 表的 tutor_name 找到 tutors 表的 id
    // 这样前端才能拿到 tutor_id 去提交评价
    $sql = "SELECT b.*, t.id as real_tutor_id 
            FROM bookings b 
            LEFT JOIN tutors t ON b.tutor_name = t.name 
            WHERE b.user_phone = '$phone' 
            ORDER BY b.create_time DESC";
            
    $result = $conn->query($sql);
    
    $list = [];
    if($result) {
        while($row = $result->fetch_assoc()) {
            $list[] = $row;
        }
    }
    echo json_encode(["status" => "success", "data" => $list]);
} else {
    echo json_encode(["status" => "error", "message" => "未登录"]);
}
$conn->close();
?>