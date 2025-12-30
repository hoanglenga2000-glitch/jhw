<?php
// api/get_tutors.php - 获取教员列表 (VIP优先排序)
header('Content-Type: application/json');
require '../config/db.php';

$search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'default';

// 基础查询：必须是已通过审核的，且未被封禁
$sql = "SELECT id, name, school, major, subject, price, rating, avatar, is_vip, vip_expire_time 
        FROM tutors 
        WHERE status='已通过' AND is_banned=0";

if (!empty($search)) {
    $sql .= " AND (name LIKE '%$search%' OR subject LIKE '%$search%' OR school LIKE '%$search%')";
}

// ✨✨✨ 核心排序逻辑 ✨✨✨
// VIP (未过期) 永远排在最前面
$vip_check = " (is_vip = 1 AND vip_expire_time > NOW()) DESC ";

if ($sort == 'price_asc') {
    $sql .= " ORDER BY $vip_check, price ASC";
} else if ($sort == 'rating') {
    $sql .= " ORDER BY $vip_check, rating DESC";
} else {
    // 默认综合排序
    $sql .= " ORDER BY $vip_check, create_time DESC";
}

$result = $conn->query($sql);
$list = [];
if ($result) {
    while($row = $result->fetch_assoc()) {
        if(empty($row['avatar'])) $row['avatar'] = 'default_boy.png';
        
        // 检查 VIP 是否过期，如果是，在输出时标记为 0 (数据库可由定时任务清理，这里做实时判断)
        $is_vip_active = ($row['is_vip'] == 1 && strtotime($row['vip_expire_time']) > time());
        $row['is_vip'] = $is_vip_active ? 1 : 0;
        
        $list[] = $row;
    }
}

echo json_encode(["status" => "success", "data" => $list]);
$conn->close();
?>