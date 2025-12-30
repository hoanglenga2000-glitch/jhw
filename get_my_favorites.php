<?php
// get_my_favorites.php - 获取我看过的/收藏的老师
header('Content-Type: application/json');
require '../config/db.php';

$phone = $_GET['phone'];

// 连表查询：从 favorites 表找 id，再去 tutors 表找详情
$sql = "SELECT t.* FROM favorites f JOIN tutors t ON f.tutor_id = t.id WHERE f.user_phone = '$phone' ORDER BY f.create_time DESC";
$result = $conn->query($sql);

$list = [];
while($row = $result->fetch_assoc()) $list[] = $row;

echo json_encode(["status" => "success", "data" => $list]);
$conn->close();
?>