<?php
// api/calendar_api.php - 日历数据接口
header('Content-Type: application/json');
require '../config/db.php';

$phone = isset($_GET['phone']) ? $_GET['phone'] : '';
$role = isset($_GET['role']) ? $_GET['role'] : 'student';

if(empty($phone)) {
    echo json_encode([]);
    exit;
}

$events = [];

if ($role == 'student') {
    // 学生看自己的课程
    $sql = "SELECT * FROM bookings WHERE user_phone='$phone' AND status != '已拒绝' AND status != '已取消'";
} else {
    // 老师看自己的排课 (先要把手机号转成老师名字)
    $t_res = $conn->query("SELECT name FROM tutors WHERE phone='$phone'");
    if($t_res && $t_row=$t_res->fetch_assoc()) {
        $tName = $conn->real_escape_string($t_row['name']);
        $sql = "SELECT * FROM bookings WHERE tutor_name='$tName' AND status != '已拒绝' AND status != '已取消'";
    } else {
        echo json_encode([]); exit;
    }
}

$result = $conn->query($sql);
if ($result) {
    while($row = $result->fetch_assoc()) {
        // 核心：处理时间格式
        // 如果是新版 "2023-12-25 14:00" 格式，直接用
        // 如果是旧版 "周末全天"，我们为了不报错，暂不显示或设为当天
        $start = $row['lesson_time'];
        
        // 简单的正则判断是否包含日期格式
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $start)) {
            $color = '#3788d8'; // 默认蓝
            $title = ($role == 'student' ? $row['tutor_name'].'老师' : '学员'.$row['user_phone']);
            
            if ($row['status'] == '待确认') $color = '#F59E0B'; // 橙色
            if ($row['status'] == '已支付') $color = '#10B981'; // 绿色 (待上课)
            if ($row['status'] == '已完成' || $row['status'] == '待评价') $color = '#64748B'; // 灰色

            $events[] = [
                'title' => $title . ' (' . $row['status'] . ')',
                'start' => $start,
                'backgroundColor' => $color,
                'borderColor' => $color
            ];
        }
    }
}

echo json_encode($events);
$conn->close();
?>