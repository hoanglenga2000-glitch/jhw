<?php
// api/admin_api.php - 旗舰版 (已增加订单类型支持)
header('Content-Type: application/json');
// 开启错误报告以便调试
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
    $orders = $conn->query("SELECT COUNT(*) as c FROM bookings WHERE status IN ('已支付', '已完成', '待评价')")->fetch_assoc()['c'];
    // 待办事项总数
    $p_tutor = $conn->query("SELECT COUNT(*) as c FROM tutors WHERE status='待审核'")->fetch_assoc()['c'];
    $p_res = $conn->query("SELECT COUNT(*) as c FROM resources WHERE status='待审核'")->fetch_assoc()['c'];
    $p_with = $conn->query("SELECT COUNT(*) as c FROM withdrawals WHERE status='pending'")->fetch_assoc()['c'];
    $p_refund = $conn->query("SELECT COUNT(*) as c FROM refunds WHERE status='pending'")->fetch_assoc()['c'];
    
    // 计算总流水
    $income_res = $conn->query("SELECT SUM(price) as s FROM bookings WHERE status IN ('已支付','已完成','待评价')");
    $income = $income_res ? $income_res->fetch_assoc()['s'] : 0;

    echo json_encode([
        "status" => "success", 
        "data" => [
            "users" => $users,
            "tutors" => $tutors,
            "orders" => $orders,
            "income" => number_format($income ?: 0, 2),
            "pending" => [
                "tutors" => $p_tutor,
                "resources" => $p_res,
                "withdrawals" => $p_with,
                "refunds" => $p_refund
            ]
        ]
    ]);
}

// ==================== 2. 教员审核管理 ====================
else if ($action == 'get_pending_tutors') {
    $res = $conn->query("SELECT * FROM tutors WHERE status='待审核' ORDER BY id DESC");
    $list = [];
    if($res) while($r=$res->fetch_assoc()) $list[]=$r;
    echo json_encode(["status"=>"success", "data"=>$list]);
}
else if ($action == 'verify_tutor') {
    $id = $_POST['id'];
    $s = $_POST['status']; // '已通过' or '已拒绝'
    $conn->query("UPDATE tutors SET status='$s' WHERE id='$id'");
    // 发通知
    $t = $conn->query("SELECT phone FROM tutors WHERE id='$id'")->fetch_assoc();
    if($t) {
        $msg = $s=='已通过' ? "恭喜！您的教员身份审核已通过。" : "很遗憾，您的教员审核未通过，请完善资料。";
        $conn->query("INSERT INTO notifications (user_phone, content) VALUES ('".$t['phone']."', '$msg')");
    }
    echo json_encode(["status"=>"success"]);
}

// ==================== 3. 资源审核管理 ====================
else if ($action == 'get_pending_resources') {
    $res = $conn->query("SELECT * FROM resources WHERE status='待审核' ORDER BY create_time DESC");
    $list = [];
    if($res) while($r=$res->fetch_assoc()) $list[]=$r;
    echo json_encode(["status"=>"success", "data"=>$list]);
}
else if ($action == 'verify_resource') {
    $id = $_POST['id'];
    $s = $_POST['status']; // 'approved' or 'rejected'
    $conn->query("UPDATE resources SET status='$s' WHERE id='$id'");
    echo json_encode(["status"=>"success"]);
}

// ==================== 4. 提现管理 ====================
else if ($action == 'get_withdrawals') {
    $res = $conn->query("SELECT * FROM withdrawals ORDER BY create_time DESC");
    $list = [];
    if($res) while($r=$res->fetch_assoc()) $list[]=$r;
    echo json_encode(["status"=>"success", "data"=>$list]);
}
else if ($action == 'handle_withdrawal') {
    $id = $_POST['id'];
    $act = $_POST['act']; // 'approve' or 'reject'
    $w = $conn->query("SELECT * FROM withdrawals WHERE id='$id'")->fetch_assoc();
    
    if($w['status'] !== 'pending') { echo json_encode(["status"=>"error", "message"=>"已处理过"]); exit; }

    if($act == 'approve') {
        $conn->query("UPDATE withdrawals SET status='approved' WHERE id='$id'");
        // 这里可以接实际转账接口
        $conn->query("INSERT INTO notifications (user_phone, content) VALUES ('".$w['user_phone']."', '提现到账通知：{$w['amount']}元 已打款')");
    } else {
        // 拒绝则退款
        $conn->query("UPDATE withdrawals SET status='rejected' WHERE id='$id'");
        $conn->query("UPDATE users SET balance=balance+".$w['amount']." WHERE phone='".$w['user_phone']."'");
        $conn->query("INSERT INTO notifications (user_phone, content) VALUES ('".$w['user_phone']."', '提现申请被驳回，资金已退回余额')");
    }
    echo json_encode(["status"=>"success"]);
}

// ==================== 5. 订单管理 (核心修改：确保读取类型) ====================
else if ($action == 'get_all_bookings') {
    $res = $conn->query("SELECT * FROM bookings ORDER BY create_time DESC LIMIT 100");
    $list = [];
    if($res) {
        while($r=$res->fetch_assoc()) {
            // 兼容旧数据，如果没有 class_type，默认为线上
            if(empty($r['class_type'])) $r['class_type'] = '线上教学';
            $list[]=$r;
        }
    }
    echo json_encode(["status"=>"success", "data"=>$list]);
}
else if ($action == 'delete_booking') {
    $id = $_POST['id'];
    $conn->query("DELETE FROM bookings WHERE id='$id'");
    echo json_encode(["status"=>"success"]);
}

// ==================== 6. 用户与评论管理 ====================
else if ($action == 'get_users') {
    $t = $_GET['type']; // 'student' or 'teacher'
    if($t == 'student') {
        $res = $conn->query("SELECT id, username, phone, balance, create_time, is_banned FROM users ORDER BY id DESC LIMIT 50");
    } else {
        $res = $conn->query("SELECT id, name as username, phone, balance, create_time, is_banned FROM tutors ORDER BY id DESC LIMIT 50");
    }
    $list = [];
    if($res) while($r=$res->fetch_assoc()) $list[]=$r;
    echo json_encode(["status"=>"success", "data"=>$list]);
}
else if ($action == 'toggle_ban') {
    $type = $_POST['type']; $id = $_POST['id']; $ban = $_POST['is_banned'];
    $table = $type == 'student' ? 'users' : 'tutors';
    $conn->query("UPDATE $table SET is_banned=$ban WHERE id='$id'");
    echo json_encode(["status"=>"success"]);
}
else if ($action == 'reset_password') {
    $type = $_POST['type']; $id = $_POST['id'];
    $table = $type == 'student' ? 'users' : 'tutors';
    // 默认重置为 123456
    $pwd = password_hash("123456", PASSWORD_DEFAULT);
    $conn->query("UPDATE $table SET password='$pwd' WHERE id='$id'");
    echo json_encode(["status"=>"success", "message"=>"密码已重置为123456"]);
}

// 评论管理
else if ($action == 'get_all_reviews') {
    $sql = "SELECT r.*, t.name as tutor_name FROM reviews r JOIN tutors t ON r.tutor_id = t.id ORDER BY r.create_time DESC LIMIT 50";
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

// ==================== 7. 公告管理 ====================
else if ($action == 'manage_announcement') {
    $type = $_POST['type']; // 'add' or 'delete'
    if ($type == 'add') {
        $title = $conn->real_escape_string($_POST['title']);
        $content = $conn->real_escape_string($_POST['content']);
        if ($conn->query("INSERT INTO announcements (title, content, create_time) VALUES ('$title', '$content', NOW())")) {
            // 给全员发通知
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

// ==================== 8. 客服反馈 & FAQ ====================
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
    } else if ($type == 'delete') {
        $id = $_POST['id'];
        $conn->query("DELETE FROM faqs WHERE id='$id'");
    }
    echo json_encode(["status"=>"success"]);
}

// ==================== 9. 财务报表 ====================
else if ($action == 'get_finance_report') {
    // 获取所有已支付的订单（计算抽成）
    $res = $conn->query("
        SELECT 
            b.id, b.user_phone, b.tutor_name, b.price, b.status, b.create_time,
            t.is_vip, t.vip_expire_time,
            CASE 
                WHEN b.status = '已完成' OR b.status = '待评价' THEN b.create_time
                ELSE NULL
            END as settle_time
        FROM bookings b
        LEFT JOIN tutors t ON b.tutor_name = t.name
        WHERE b.status IN ('已支付', '已完成', '待评价')
        ORDER BY b.create_time DESC
        LIMIT 200
    ");
    
    $orders = [];
    $total = 0;
    $commission = 0;
    $payout = 0;
    $pending = 0;
    
    if($res) {
        while($row = $res->fetch_assoc()) {
            // 计算抽成比例：VIP 5%，普通 10%
            $isVip = ($row['is_vip'] == 1 && strtotime($row['vip_expire_time']) > time());
            $rate = $isVip ? 0.05 : 0.10;
            $orderCommission = floatval($row['price']) * $rate;
            $orderPayout = floatval($row['price']) - $orderCommission;
            
            $row['commission_rate'] = $rate;
            $row['commission'] = $orderCommission;
            $row['payout'] = $orderPayout;
            
            $orders[] = $row;
            
            $total += floatval($row['price']);
            if($row['status'] === '已完成' || $row['status'] === '待评价') {
                $commission += $orderCommission;
                $payout += $orderPayout;
            } else {
                $pending += floatval($row['price']);
            }
        }
    }
    
    echo json_encode([
        "status" => "success",
        "data" => [
            "orders" => $orders,
            "stats" => [
                "total" => $total,
                "commission" => $commission,
                "payout" => $payout,
                "pending" => $pending
            ]
        ]
    ]);
}

// ==================== 10. 全局广播 ====================
else if ($action == 'broadcast_message') {
    $content = $conn->real_escape_string($_POST['content']);
    
    if(empty($content)) {
        echo json_encode(["status" => "error", "message" => "广播内容不能为空"]);
        exit;
    }
    
    // 给所有用户和教员发送通知
    $userCount = 0;
    $tutorCount = 0;
    
    $users = $conn->query("SELECT phone FROM users");
    if($users) {
        while($u = $users->fetch_assoc()) {
            $conn->query("INSERT INTO notifications (user_phone, content, create_time) VALUES ('".$u['phone']."', '🔔 系统广播: $content', NOW())");
            $userCount++;
        }
    }
    
    $tutors = $conn->query("SELECT phone FROM tutors");
    if($tutors) {
        while($t = $tutors->fetch_assoc()) {
            $conn->query("INSERT INTO notifications (user_phone, content, create_time) VALUES ('".$t['phone']."', '🔔 系统广播: $content', NOW())");
            $tutorCount++;
        }
    }
    
    echo json_encode([
        "status" => "success",
        "message" => "已向 {$userCount} 位用户和 {$tutorCount} 位教员发送广播"
    ]);
}

$conn->close();
?>