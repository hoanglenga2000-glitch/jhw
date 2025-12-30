<?php
// api/user_api.php - 终极全功能合并版 (V16)
header('Content-Type: application/json');
// 开启错误报告以便调试 (正式上线可关闭)
error_reporting(E_ALL);
ini_set('display_errors', 0);
require '../config/db.php';

$action = isset($_GET['action']) ? $_GET['action'] : '';

// ==================== 1. 基础信息管理 ====================

// 获取用户信息
if ($action == 'get_info') {
    $p = $_GET['phone'];
    $r = $conn->query("SELECT * FROM users WHERE phone='$p'")->fetch_assoc();
    if ($r) {
        if(empty($r['avatar'])) $r['avatar'] = 'default_student.png';
        echo json_encode(['status'=>'success','data'=>$r]);
    } else {
        echo json_encode(['status'=>'error', 'message'=>'用户不存在']);
    }
}

// 修改资料
else if ($action == 'update_profile') {
    $p = $_POST['phone'];
    $u = $conn->real_escape_string($_POST['username']);
    if($conn->query("UPDATE users SET username='$u' WHERE phone='$p'")) {
        echo json_encode(['status'=>'success']);
    } else {
        echo json_encode(['status'=>'error', 'message'=>$conn->error]);
    }
}

// 充值余额
else if ($action == 'recharge') {
    $p = $_POST['phone'];
    $a = floatval($_POST['amount']);
    if($a <= 0) { echo json_encode(['status'=>'error', 'message'=>'金额无效']); exit; }
    
    $conn->query("UPDATE users SET balance=balance+$a WHERE phone='$p'");
    $conn->query("INSERT INTO transactions (user_phone, type, amount, title) VALUES ('$p', 'recharge', '+$a', '在线充值')");
    echo json_encode(['status'=>'success']);
}

// 获取钱包流水
else if ($action == 'get_wallet_history') {
    $p = $_GET['phone'];
    $r = $conn->query("SELECT * FROM transactions WHERE user_phone='$p' ORDER BY create_time DESC LIMIT 50");
    $l = [];
    if($r) while($row = $r->fetch_assoc()) $l[] = $row;
    echo json_encode(['status'=>'success','data'=>$l]);
}

// ==================== 2. 预约与订单管理 ====================

// 提交预约 (新功能)
else if ($action == 'book_tutor') {
    $user_phone = $_POST['phone'];
    $tutor_id = $_POST['tutor_id'];
    $date = $_POST['date']; 
    $time = $_POST['time'];
    $remark = isset($_POST['remark']) ? $conn->real_escape_string($_POST['remark']) : '';

    $tutor = $conn->query("SELECT name, price, phone FROM tutors WHERE id='$tutor_id'")->fetch_assoc();
    if (!$tutor) { echo json_encode(["status"=>"error", "message"=>"教员不存在"]); exit; }

    $lesson_time = "$date $time";
    $price = $tutor['price'];
    $tutor_name = $tutor['name'];
    
    $sql = "INSERT INTO bookings (user_phone, tutor_name, lesson_time, status, price, create_time) 
            VALUES ('$user_phone', '$tutor_name', '$lesson_time', '待确认', '$price', NOW())";

    if ($conn->query($sql)) {
        $conn->query("INSERT INTO notifications (user_phone, content) VALUES ('".$tutor['phone']."', '新预约：$lesson_time')");
        echo json_encode(["status"=>"success"]);
    } else {
        echo json_encode(["status"=>"error", "message"=>$conn->error]);
    }
}

// 获取我的订单
else if ($action == 'get_my_bookings') {
    $p = $_GET['phone'];
    $r = $conn->query("SELECT * FROM bookings WHERE user_phone='$p' ORDER BY create_time DESC");
    $l = [];
    if($r) while($row = $r->fetch_assoc()) {
        // 数据修复逻辑 (防止旧数据没价格)
        if(empty($row['price']) || floatval($row['price']) <= 0) {
            $tn = $conn->real_escape_string($row['tutor_name']);
            $tr = $conn->query("SELECT price FROM tutors WHERE name='$tn'")->fetch_assoc();
            if($tr) {
                $row['price'] = $tr['price'];
                $conn->query("UPDATE bookings SET price='".$tr['price']."' WHERE id='".$row['id']."'");
            }
        }
        $l[] = $row;
    }
    echo json_encode(["status"=>"success","data"=>$l]);
}

// 支付订单 (含优惠券逻辑)
else if ($action == 'pay_order') {
    $id = $_POST['id'];
    $phone = $_POST['phone'];
    $c_id = isset($_POST['coupon_id']) ? $_POST['coupon_id'] : '';
    $amt = floatval($_POST['amount']); // 前端传来的最终金额，后端需再次校验

    $bk = $conn->query("SELECT price, tutor_name, status FROM bookings WHERE id='$id'")->fetch_assoc();
    if(!$bk) { echo json_encode(["status"=>"error", "message"=>"订单不存在"]); exit; }
    if($bk['status'] == '已支付') { echo json_encode(["status"=>"error", "message"=>"请勿重复支付"]); exit; }

    $orig = floatval($bk['price']);
    $final = $orig;
    
    // 校验优惠券
    if($c_id) {
        $cp = $conn->query("SELECT c.discount, c.min_spend, uc.status 
                            FROM user_coupons uc 
                            JOIN coupons c ON uc.coupon_id = c.id 
                            WHERE uc.id='$c_id' AND uc.user_phone='$phone'")->fetch_assoc();
                            
        if($cp && $cp['status'] == 'unused' && $orig >= $cp['min_spend']) {
            $final = $orig - floatval($cp['discount']);
            if($final < 0) $final = 0;
        }
    }

    // 校验余额
    $user = $conn->query("SELECT balance FROM users WHERE phone='$phone'")->fetch_assoc();
    if(floatval($user['balance']) < $final) { echo json_encode(["status"=>"error", "message"=>"余额不足"]); exit; }

    $conn->begin_transaction();
    try {
        $conn->query("UPDATE users SET balance=balance-$final WHERE phone='$phone'");
        $conn->query("UPDATE bookings SET status='已支付', payment_status='paid' WHERE id='$id'");
        if($c_id) $conn->query("UPDATE user_coupons SET status='used' WHERE id='$c_id'"); // 注意状态值 unified to 'used'
        
        $conn->query("INSERT INTO transactions (user_phone, type, amount, title) VALUES ('$phone', 'payment', '-$final', '支付课程费')");
        
        // 通知老师
        $tn = $conn->real_escape_string($bk['tutor_name']);
        $tr = $conn->query("SELECT phone FROM tutors WHERE name='$tn'")->fetch_assoc();
        if($tr) $conn->query("INSERT INTO notifications (user_phone, content) VALUES ('".$tr['phone']."', '新订单入账: +$final')");
        
        $conn->commit();
        echo json_encode(["status"=>"success"]);
    } catch(Exception $e) {
        $conn->rollback();
        echo json_encode(["status"=>"error", "message"=>$e->getMessage()]);
    }
}

// 申请退款
else if ($action == 'apply_refund') {
    $id = $_POST['id'];
    $phone = $_POST['phone'];
    $reason = $conn->real_escape_string($_POST['reason']);
    
    $bk = $conn->query("SELECT * FROM bookings WHERE id='$id' AND user_phone='$phone'")->fetch_assoc();
    if(!$bk || $bk['status'] != '已支付') { echo json_encode(["status"=>"error", "message"=>"无法退款"]); exit; }
    
    $conn->query("UPDATE bookings SET status='退款中' WHERE id='$id'");
    $conn->query("INSERT INTO refunds (user_phone, booking_id, amount, reason, status) VALUES ('$phone', '$id', '".$bk['price']."', '$reason', 'pending')");
    echo json_encode(["status"=>"success"]);
}

// 提交评价
else if ($action == 'submit_review') {
    $bid = $_POST['booking_id'];
    $p = $_POST['phone'];
    $r = $_POST['rating'];
    $c = $conn->real_escape_string($_POST['content']);
    
    $bk = $conn->query("SELECT tutor_name FROM bookings WHERE id='$bid'")->fetch_assoc();
    $tn = $conn->real_escape_string($bk['tutor_name']);
    $t = $conn->query("SELECT id FROM tutors WHERE name='$tn'")->fetch_assoc();
    
    if($t) {
        $tid = $t['id'];
        $conn->query("INSERT INTO reviews (user_phone, tutor_id, booking_id, rating, content, create_time) VALUES ('$p', '$tid', '$bid', '$r', '$c', NOW())");
        $conn->query("UPDATE bookings SET status='已完成' WHERE id='$bid'");
        echo json_encode(["status"=>"success"]);
    } else {
        echo json_encode(["status"=>"error", "message"=>"教员信息错误"]);
    }
}

// ==================== 3. 优惠券与通知 ====================

// 获取我的优惠券
else if ($action == 'get_my_coupons') {
    $p = $_GET['phone'];
    // 兼容 is_used 和 status 字段 (优先 status='unused')
    $sql = "SELECT uc.id as cid, c.* FROM user_coupons uc JOIN coupons c ON uc.coupon_id=c.id WHERE uc.user_phone='$p' AND (uc.status='unused' OR uc.status IS NULL) ORDER BY c.discount DESC";
    $r = $conn->query($sql);
    $l = [];
    if($r) while($row = $r->fetch_assoc()) $l[] = $row;
    echo json_encode(["status"=>"success","data"=>$l]);
}

// 获取通知列表
else if ($action == 'get_notifications') {
    $p = $_GET['phone'];
    $r = $conn->query("SELECT * FROM notifications WHERE user_phone='$p' ORDER BY create_time DESC LIMIT 20");
    $l = []; if($r) while($row = $r->fetch_assoc()) $l[] = $row;
    echo json_encode(["status"=>"success","data"=>$l]);
}

// 检查未读通知
else if ($action == 'check_unread') {
    $p = $_GET['phone'];
    $c = $conn->query("SELECT COUNT(*) as c FROM notifications WHERE user_phone='$p' AND is_read=0")->fetch_assoc()['c'];
    echo json_encode(["count"=>$c]);
}

// ==================== 4. 资源商城 (新) ====================

// 获取已购资源
else if ($action == 'get_my_downloads') {
    $phone = $_GET['phone'];
    $sql = "SELECT r.id, r.title, r.type, r.file_path, r.uploader_phone, ro.create_time as buy_time 
            FROM resource_orders ro
            JOIN resources r ON ro.resource_id = r.id
            WHERE ro.user_phone = '$phone'
            ORDER BY ro.create_time DESC";
    $res = $conn->query($sql);
    $list = [];
    if($res) while($r = $res->fetch_assoc()) $list[] = $r;
    echo json_encode(["status"=>"success", "data"=>$list]);
}

// ==================== 5. 收藏功能 (找回) ====================

// 切换收藏
else if ($action == 'toggle_favorite') {
    $phone = $_POST['phone'];
    $tid = $_POST['tutor_id'];
    $check = $conn->query("SELECT id FROM favorites WHERE user_phone='$phone' AND tutor_id='$tid'");
    if ($check && $check->num_rows > 0) {
        $conn->query("DELETE FROM favorites WHERE user_phone='$phone' AND tutor_id='$tid'");
        echo json_encode(["status"=>"success", "action"=>"removed", "message"=>"已取消收藏"]);
    } else {
        $conn->query("INSERT INTO favorites (user_phone, tutor_id) VALUES ('$phone', '$tid')");
        echo json_encode(["status"=>"success", "action"=>"added", "message"=>"收藏成功"]);
    }
}

// 检查是否收藏
else if ($action == 'check_favorite') {
    $phone = $_GET['phone'];
    $tid = $_GET['tutor_id'];
    $check = $conn->query("SELECT id FROM favorites WHERE user_phone='$phone' AND tutor_id='$tid'");
    $is_fav = ($check && $check->num_rows > 0);
    echo json_encode(["status"=>"success", "is_favorite"=>$is_fav]);
}

// 获取收藏列表
else if ($action == 'get_my_favorites') {
    $phone = $_GET['phone'];
    $sql = "SELECT f.*, t.id as tutor_id, t.name, t.avatar, t.school, t.major, t.price 
            FROM favorites f 
            JOIN tutors t ON f.tutor_id = t.id 
            WHERE f.user_phone='$phone' 
            ORDER BY f.create_time DESC";
    $res = $conn->query($sql);
    $list = []; if($res) while($r = $res->fetch_assoc()) $list[] = $r;
    echo json_encode(["status"=>"success", "data"=>$list]);
}

// ==================== 6. 聊天辅助 ====================

// 检查聊天权限 (判断是否预约过)
else if ($action == 'check_booking_status') {
    $phone = $_GET['phone'];
    $tid = $_GET['tutor_id'];
    $t_res = $conn->query("SELECT name FROM tutors WHERE id='$tid'")->fetch_assoc();
    if ($t_res) {
        $tName = $conn->real_escape_string($t_res['name']);
        // 只要有过非拒绝非取消的订单，就算预约过
        $sql = "SELECT id FROM bookings WHERE user_phone='$phone' AND tutor_name='$tName' AND status NOT IN ('已拒绝', '已取消') LIMIT 1";
        $check = $conn->query($sql);
        $can_chat = ($check && $check->num_rows > 0);
        echo json_encode(["status" => "success", "can_chat" => $can_chat]);
    } else {
        echo json_encode(["status" => "error", "message" => "教员不存在"]);
    }
}

$conn->close();
?>