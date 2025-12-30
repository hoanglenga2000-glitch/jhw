<?php
// api/admin_api.php - 旗舰版 (基于原版无损升级)
header('Content-Type: application/json');
// 开启错误报告以便调试 (如果页面白屏，请查看 Network 里的返回值)
error_reporting(E_ALL);
ini_set('display_errors', 0); 
require '../config/db.php';

$action = isset($_GET['action']) ? $_GET['action'] : '';

// ==================== 1. 数据概览 (Dashboard) ====================
if ($action == 'get_stats') {
    // 用户总数
    $users = $conn->query("SELECT COUNT(*) as c FROM users")->fetch_assoc()['c'];
    // 入驻教员 (已通过)
    $tutors = $conn->query("SELECT COUNT(*) as c FROM tutors WHERE status='已通过'")->fetch_assoc()['c'];
    // 累计订单 (已支付)
    $orders = $conn->query("SELECT COUNT(*) as c FROM bookings WHERE payment_status='paid'")->fetch_assoc()['c'];
    // 待办事项总数
    $p_tutor = $conn->query("SELECT COUNT(*) as c FROM tutors WHERE status='待审核'")->fetch_assoc()['c'];
    $p_res = $conn->query("SELECT COUNT(*) as c FROM resources WHERE status='待审核'")->fetch_assoc()['c'];
    $p_with = $conn->query("SELECT COUNT(*) as c FROM withdrawals WHERE status='pending'")->fetch_assoc()['c'];
    $p_refund = $conn->query("SELECT COUNT(*) as c FROM refunds WHERE status='pending'")->fetch_assoc()['c'];
    
    $pending_total = $p_tutor + $p_res + $p_with + $p_refund;
    
    // 计算GMV (可选)
    $gmv = 0;
    $g_res = $conn->query("SELECT SUM(price) as t FROM bookings WHERE payment_status='paid'");
    if($g_res && $row=$g_res->fetch_assoc()) $gmv = $row['t'] ? $row['t'] : 0;
    
    echo json_encode([
        "users" => $users,
        "tutors" => $tutors,
        "orders" => $orders,
        "pending" => $pending_total,
        "gmv" => number_format($gmv, 2)
    ]);
}

// ✨✨✨ [新增] 获取图表数据接口 ✨✨✨
else if ($action == 'get_chart_data') {
    // 1. 最近7天订单趋势
    $dates = [];
    $counts = [];
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $dates[] = $date; // 日期轴 X
        // 统计当天的订单数
        $sql = "SELECT COUNT(*) as c FROM bookings WHERE DATE(create_time) = '$date'";
        $row = $conn->query($sql)->fetch_assoc();
        $counts[] = intval($row['c']); // 数据轴 Y
    }

    // 2. 热门科目分布 (饼图)
    // 简单统计：根据已成交订单关联的老师科目，或者直接统计 tutors 表分布
    $pie_data = [];
    $sql_pie = "SELECT subject, COUNT(*) as cnt FROM tutors WHERE status='已通过' GROUP BY subject ORDER BY cnt DESC LIMIT 5";
    $res_pie = $conn->query($sql_pie);
    if($res_pie) {
        while($row = $res_pie->fetch_assoc()) {
            // 处理一下科目可能包含逗号的情况，只取主科目
            $sub = explode(',', $row['subject'])[0]; 
            if(!$sub) $sub = '其他';
            $pie_data[] = ["name" => $sub, "value" => intval($row['cnt'])];
        }
    }
    // 如果没数据，给点默认值防止图表空白
    if(empty($pie_data)) {
        $pie_data = [["name"=>"暂无数据", "value"=>0]];
    }

    echo json_encode([
        "status" => "success",
        "trend" => ["dates" => $dates, "counts" => $counts],
        "pie" => $pie_data
    ]);
}

// ==================== 2. 教员审核 (含证件) ====================
else if ($action == 'get_pending_tutors') {
    $res = $conn->query("SELECT * FROM tutors WHERE status='待审核' ORDER BY create_time DESC");
    $list = [];
    if($res) while($r = $res->fetch_assoc()) $list[] = $r;
    echo json_encode(["status"=>"success", "data"=>$list]);
}
else if ($action == 'audit_tutor') {
    $id = $_POST['id'];
    $s = $_POST['status'];
    $conn->query("UPDATE tutors SET status='$s' WHERE id='$id'");
    echo json_encode(["status"=>"success"]);
}

// ==================== 3. 资源/资料审核 ====================
else if ($action == 'get_pending_resources') {
    $res = $conn->query("SELECT * FROM resources WHERE status='待审核' ORDER BY create_time DESC");
    $list = [];
    if($res) while($r = $res->fetch_assoc()) $list[] = $r;
    echo json_encode(["status"=>"success", "data"=>$list]);
}
else if ($action == 'audit_resource') {
    $id = $_POST['id'];
    $s = $_POST['status'];
    $conn->query("UPDATE resources SET status='$s' WHERE id='$id'");
    echo json_encode(["status"=>"success"]);
}

// ==================== 4. 财务提现管理 ====================
// (注意：这里如果前端调用的是 withdraw_api.php，请确保那个文件也在。为了兼容性，这里不重复写 admin_get_pending，防止冲突)

// ==================== 5. 售后退款管理 ====================
else if ($action == 'get_pending_refunds') {
    $sql = "SELECT r.*, b.tutor_name FROM refunds r JOIN bookings b ON r.booking_id = b.id WHERE r.status='pending'";
    $res = $conn->query($sql);
    $list = [];
    if($res) while($r=$res->fetch_assoc()) $list[]=$r;
    echo json_encode(["status"=>"success", "data"=>$list]);
}
else if ($action == 'process_refund') {
    $id = $_POST['id'];
    $s = $_POST['status'];
    $conn->query("UPDATE refunds SET status='$s' WHERE id='$id'");
    
    if($s == 'approved'){
        $rf = $conn->query("SELECT * FROM refunds WHERE id='$id'")->fetch_assoc();
        $conn->query("UPDATE users SET balance=balance+".$rf['amount']." WHERE phone='".$rf['user_phone']."'");
        $conn->query("UPDATE bookings SET status='已退款' WHERE id='".$rf['booking_id']."'");
    } else {
        $rf = $conn->query("SELECT * FROM refunds WHERE id='$id'")->fetch_assoc();
        $conn->query("UPDATE bookings SET status='已支付' WHERE id='".$rf['booking_id']."'");
    }
    echo json_encode(["status"=>"success"]);
}

// ==================== 6. 用户与账号管理 ====================
else if ($action == 'get_all_users') {
    $type = $_GET['type'];
    $list = [];
    if ($type == 'student') {
        $res = $conn->query("SELECT id, username as name, phone, balance, is_banned FROM users ORDER BY create_time DESC");
    } else {
        $res = $conn->query("SELECT id, name, phone, balance, is_banned FROM tutors ORDER BY create_time DESC");
    }
    if($res) while($r=$res->fetch_assoc()) $list[]=$r;
    echo json_encode(["status"=>"success", "data"=>$list]);
}
else if ($action == 'toggle_ban') {
    $type = $_POST['type'];
    $id = $_POST['id'];
    $b = $_POST['is_banned'];
    $table = ($type == 'student') ? 'users' : 'tutors';
    $conn->query("UPDATE $table SET is_banned='$b' WHERE id='$id'");
    echo json_encode(["status"=>"success"]);
}
else if ($action == 'reset_password') {
    $type = $_POST['type'];
    $id = $_POST['id'];
    $table = ($type == 'student') ? 'users' : 'tutors';
    $conn->query("UPDATE $table SET password='123456' WHERE id='$id'");
    echo json_encode(["status"=>"success", "message"=>"密码已重置为 123456"]);
}

// ==================== 7. 内容评价管理 ====================
else if ($action == 'get_all_reviews') {
    $sql = "SELECT r.*, t.name as tutor_name FROM reviews r JOIN tutors t ON r.tutor_id = t.id ORDER BY r.create_time DESC";
    $res = $conn->query($sql);
    $list = [];
    if($res) while($r=$res->fetch_assoc()) $list[]=$r;
    echo json_encode(["status"=>"success", "data"=>$list]);
}
else if ($action == 'delete_review') {
    $id = $_POST['id'];
    $conn->query("DELETE FROM reviews WHERE id='$id'");
    echo json_encode(["status"=>"success"]);
}

// ==================== 8. 营销中心 (优惠券) ====================
else if ($action == 'get_coupons') {
    $res = $conn->query("SELECT * FROM coupons");
    $list = [];
    if($res) while($r=$res->fetch_assoc()) $list[]=$r;
    echo json_encode(["status"=>"success", "data"=>$list]);
}
else if ($action == 'issue_coupon') {
    $cid = $_POST['coupon_id'];
    $users = $conn->query("SELECT phone FROM users");
    if($users) {
        while($u = $users->fetch_assoc()) {
            $conn->query("INSERT INTO user_coupons (user_phone, coupon_id) VALUES ('".$u['phone']."', '$cid')");
        }
    }
    echo json_encode(["status"=>"success"]);
}

// ==================== 9. 公告系统 ====================
else if ($action == 'get_announcements') {
    $res = $conn->query("SELECT * FROM announcements ORDER BY create_time DESC");
    $list = [];
    if($res) while($r=$res->fetch_assoc()) $list[]=$r;
    echo json_encode(["status"=>"success", "data"=>$list]);
}
else if ($action == 'manage_announcement') {
    $type = $_POST['type'];
    if ($type == 'add') {
        $title = $conn->real_escape_string($_POST['title']);
        $content = $conn->real_escape_string($_POST['content']);
        $push = $_POST['push'];
        $conn->query("INSERT INTO announcements (title, content, is_pushed) VALUES ('$title', '$content', ".($push=='true'?1:0).")");
        if($push == 'true'){
            $us = $conn->query("SELECT phone FROM users");
            while($u=$us->fetch_assoc()) $conn->query("INSERT INTO notifications (user_phone, content) VALUES ('".$u['phone']."', '公告: $title')");
            $ts = $conn->query("SELECT phone FROM tutors");
            while($t=$ts->fetch_assoc()) $conn->query("INSERT INTO notifications (user_phone, content) VALUES ('".$t['phone']."', '公告: $title')");
        }
    } else {
        $id = $_POST['id'];
        $conn->query("DELETE FROM announcements WHERE id='$id'");
    }
    echo json_encode(["status"=>"success"]);
}

// ==================== 10. 客服反馈 & FAQ ====================
else if ($action == 'get_feedbacks') {
    $res = $conn->query("SELECT * FROM feedbacks ORDER BY create_time DESC");
    $list = [];
    if($res) while($r=$res->fetch_assoc()) $list[]=$r;
    echo json_encode(["status"=>"success", "data"=>$list]);
}
else if ($action == 'read_feedback') {
    $id = $_POST['id'];
    $conn->query("UPDATE feedbacks SET status='read' WHERE id='$id'");
    echo json_encode(["status"=>"success"]);
}
else if ($action == 'manage_faq') {
    $type = $_POST['type'];
    if ($type == 'add') {
        $q = $conn->real_escape_string($_POST['question']);
        $a = $conn->real_escape_string($_POST['answer']);
        $conn->query("INSERT INTO faqs (question, answer) VALUES ('$q', '$a')");
    } else {
        $id = $_POST['id'];
        $conn->query("DELETE FROM faqs WHERE id='$id'");
    }
    echo json_encode(["status"=>"success"]);
}

$conn->close();
?>