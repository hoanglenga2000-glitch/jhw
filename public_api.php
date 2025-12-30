<?php
// api/public_api.php - 公共接口 (已升级：获取公告详情)
header('Content-Type: application/json');
require '../config/db.php';

$action = isset($_GET['action']) ? $_GET['action'] : '';

// 获取最新的系统公告 (用于首页展示)
if ($action == 'get_latest_notices') {
    // ✨✨✨ 关键修改：增加了 content 字段查询 ✨✨✨
    $sql = "SELECT id, title, content, create_time FROM announcements ORDER BY create_time DESC LIMIT 1";
    $res = $conn->query($sql);
    $list = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) $list[] = $row;
    }
    echo json_encode(["status" => "success", "data" => $list]);
}

// 获取 FAQ 列表 (保持不变)
else if ($action == 'get_faqs') {
    $res = $conn->query("SELECT id, question, answer FROM faqs ORDER BY id DESC");
    $list = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) $list[] = $row;
    }
    echo json_encode(["status" => "success", "data" => $list]);
}

$conn->close();
?>