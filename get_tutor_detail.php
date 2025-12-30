<?php
// get_tutor_detail.php - 获取特定教员详情
header('Content-Type: application/json');
require '../config/db.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id > 0) {
    // 查询教员信息
    $sql = "SELECT * FROM tutors WHERE id = $id";
    $result = $conn->query($sql);
    
    if ($result->num_rows > 0) {
        $tutor = $result->fetch_assoc();
        echo json_encode(["status" => "success", "data" => $tutor]);
    } else {
        echo json_encode(["status" => "error", "message" => "教员不存在"]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "参数错误"]);
}
$conn->close();
?>