<?php
// api/user_api.php - 终极全功能合并版 (V17 - 安全加固版)
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);
require '../config/db.php';

// ==================== Session安全验证 ====================
session_start();

/**
 * 验证用户Session权限
 * @param string $phone 请求操作的用户手机号
 * @return bool 是否验证通过
 */
function verifyUserSession($phone) {
    // 检查Session是否存在用户信息
    if (!isset($_SESSION['user_phone'])) {
        return false;
    }
    // 确保Session中的用户与操作目标一致
    return $_SESSION['user_phone'] === $phone;
}

/**
 * 返回未授权错误
 */
function unauthorizedResponse() {
    http_response_code(401);
    echo json_encode([
        'status' => 'error', 
        'message' => '未授权操作，请重新登录',
        'code' => 'UNAUTHORIZED'
    ]);
    exit;
}

/**
 * 安全的数据清洗
 */
function sanitize($conn, $data) {
    return htmlspecialchars($conn->real_escape_string(trim($data)), ENT_QUOTES, 'UTF-8');
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

// ==================== 1. 基础信息管理 ====================

// 用户登录 - 设置Session
if ($action == 'login') {
    $phone = sanitize($conn, $_POST['phone']);
    $password = sanitize($conn, $_POST['password']);
    
    $stmt = $conn->prepare("SELECT * FROM users WHERE phone = ? AND password = ?");
    $stmt->bind_param("ss", $phone, $password);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    
    if ($user) {
        // 设置Session
        $_SESSION['user_phone'] = $user['phone'];
        $_SESSION['user_name'] = $user['username'];
        $_SESSION['login_time'] = time();
        
        if(empty($user['avatar'])) $user['avatar'] = 'default_student.png';
        unset($user['password']); // 不返回密码
        
        echo json_encode(['status'=>'success', 'data'=>$user]);
    } else {
        echo json_encode(['status'=>'error', 'message'=>'手机号或密码错误']);
    }
}

// 用户登出 - 清除Session
else if ($action == 'logout') {
    $_SESSION = array();
    session_destroy();
    echo json_encode(['status'=>'success', 'message'=>'已退出登录']);
}

// 获取用户信息
else if ($action == 'get_info') {
    $p = sanitize($conn, $_GET['phone']);
    
    $stmt = $conn->prepare("SELECT * FROM users WHERE phone = ?");
    $stmt->bind_param("s", $p);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    
    if ($r) {
        if(empty($r['avatar'])) $r['avatar'] = 'default_student.png';
        unset($r['password']); // 不返回密码
        echo json_encode(['status'=>'success','data'=>$r]);
    } else {
        echo json_encode(['status'=>'error', 'message'=>'用户不存在']);
    }
}

// 修改资料 - 需要Session验证
else if ($action == 'update_profile') {
    $p = sanitize($conn, $_POST['phone']);
    
    // 安全验证
    if (!verifyUserSession($p)) {
        unauthorizedResponse();
    }
    
    $u = sanitize($conn, $_POST['username']);
    
    $stmt = $conn->prepare("UPDATE users SET username = ? WHERE phone = ?");
    $stmt->bind_param("ss", $u, $p);
    
    if($stmt->execute()) {
        echo json_encode(['status'=>'success']);
    } else {
        echo json_encode(['status'=>'error', 'message'=>'更新失败']);
    }
}

// 充值余额 - 需要Session验证
else if ($action == 'recharge') {
    $p = sanitize($conn, $_POST['phone']);
    
    // 安全验证
    if (!verifyUserSession($p)) {
        unauthorizedResponse();
    }
    
    $a = floatval($_POST['amount']);
    if($a <= 0) { echo json_encode(['status'=>'error', 'message'=>'金额无效']); exit; }
    
    $conn->begin_transaction();
    try {
        $stmt1 = $conn->prepare("UPDATE users SET balance = balance + ? WHERE phone = ?");
        $stmt1->bind_param("ds", $a, $p);
        $stmt1->execute();
        
        $stmt2 = $conn->prepare("INSERT INTO transactions (user_phone, type, amount, title) VALUES (?, 'recharge', ?, '在线充值')");
        $amountStr = "+$a";
        $stmt2->bind_param("ss", $p, $amountStr);
        $stmt2->execute();
        
        $conn->commit();
        echo json_encode(['status'=>'success']);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['status'=>'error', 'message'=>'充值失败']);
    }
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

// 提交预约 (安全加固版 - 使用预处理语句)
else if ($action == 'book_tutor') {
    // 兼容新旧两种参数格式
    $user_phone = sanitize($conn, isset($_POST['user_phone']) ? $_POST['user_phone'] : (isset($_POST['phone']) ? $_POST['phone'] : ''));
    $tutor_name = sanitize($conn, isset($_POST['tutor_name']) ? $_POST['tutor_name'] : '');
    $tutor_id = isset($_POST['tutor_id']) ? intval($_POST['tutor_id']) : 0;
    $lesson_time = sanitize($conn, isset($_POST['lesson_time']) ? $_POST['lesson_time'] : '');
    $class_type = sanitize($conn, isset($_POST['class_type']) ? $_POST['class_type'] : '线上教学');
    $requirement = sanitize($conn, isset($_POST['requirement']) ? $_POST['requirement'] : '');
    
    // 旧格式兼容
    if (empty($lesson_time) && isset($_POST['date']) && isset($_POST['time'])) {
        $lesson_time = sanitize($conn, $_POST['date']) . ' ' . sanitize($conn, $_POST['time']);
    }

    if (empty($user_phone)) {
        echo json_encode(["status"=>"error", "message"=>"用户信息缺失"]);
        exit;
    }

    // 如果通过tutor_id获取tutor_name
    if (!empty($tutor_id) && empty($tutor_name)) {
        $stmt = $conn->prepare("SELECT name, price, phone FROM tutors WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $tutor_id);
        $stmt->execute();
        $tutor = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$tutor) {
            echo json_encode(["status"=>"error", "message"=>"教员不存在"]);
            exit;
        }
        $tutor_name = $tutor['name'];
        $tutor_phone = $tutor['phone'];
        $price = floatval($tutor['price']);
    } else if (!empty($tutor_name)) {
        // 通过tutor_name获取价格和phone
        $stmt = $conn->prepare("SELECT price, phone FROM tutors WHERE name = ? LIMIT 1");
        $stmt->bind_param("s", $tutor_name);
        $stmt->execute();
        $tutor = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$tutor) {
            echo json_encode(["status"=>"error", "message"=>"教员不存在"]);
            exit;
        }
        $tutor_phone = $tutor['phone'];
        $price = floatval($tutor['price']);
    } else {
        echo json_encode(["status"=>"error", "message"=>"教员信息缺失"]);
        exit;
    }

    if ($price <= 0) {
        echo json_encode(["status"=>"error", "message"=>"教员价格信息异常"]);
        exit;
    }

    // 使用预处理语句插入订单
    $stmt2 = $conn->prepare("INSERT INTO bookings (user_phone, tutor_name, lesson_time, requirement, class_type, status, payment_status, price, create_time) 
            VALUES (?, ?, ?, ?, ?, '待确认', 'unpaid', ?, NOW())");
    $stmt2->bind_param("sssssd", $user_phone, $tutor_name, $lesson_time, $requirement, $class_type, $price);

    if ($stmt2->execute()) {
        // 通知老师
        $notifContent = "新预约：$lesson_time";
        $stmt3 = $conn->prepare("INSERT INTO notifications (user_phone, content) VALUES (?, ?)");
        $stmt3->bind_param("ss", $tutor_phone, $notifContent);
        $stmt3->execute();
        $stmt3->close();
        
        echo json_encode(["status"=>"success", "message"=>"预约申请已提交"]);
    } else {
        echo json_encode(["status"=>"error", "message"=>"预约失败，请稍后重试"]);
    }
    $stmt2->close();
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

// 支付订单 (含优惠券逻辑) - 需要Session验证
else if ($action == 'pay_order') {
    $id = intval($_POST['id']);
    $phone = sanitize($conn, $_POST['phone']);
    
    // 安全验证
    if (!verifyUserSession($phone)) {
        unauthorizedResponse();
    }
    
    $c_id = isset($_POST['coupon_id']) ? intval($_POST['coupon_id']) : 0;

    // 使用预处理语句查询订单
    $stmt = $conn->prepare("SELECT price, tutor_name, status, user_phone FROM bookings WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $bk = $stmt->get_result()->fetch_assoc();
    
    if(!$bk) { echo json_encode(["status"=>"error", "message"=>"订单不存在"]); exit; }
    if($bk['user_phone'] != $phone) { unauthorizedResponse(); } // 确保是订单所有者
    if($bk['status'] == '已支付') { echo json_encode(["status"=>"error", "message"=>"请勿重复支付"]); exit; }

    $orig = floatval($bk['price']);
    $final = $orig;
    
    // 校验优惠券
    if($c_id > 0) {
        $stmt2 = $conn->prepare("SELECT c.discount, c.min_spend, uc.status 
                            FROM user_coupons uc 
                            JOIN coupons c ON uc.coupon_id = c.id 
                            WHERE uc.id = ? AND uc.user_phone = ?");
        $stmt2->bind_param("is", $c_id, $phone);
        $stmt2->execute();
        $cp = $stmt2->get_result()->fetch_assoc();
                            
        if($cp && $cp['status'] == 'unused' && $orig >= $cp['min_spend']) {
            $final = $orig - floatval($cp['discount']);
            if($final < 0) $final = 0;
        }
    }

    // 校验余额
    $stmt3 = $conn->prepare("SELECT balance FROM users WHERE phone = ?");
    $stmt3->bind_param("s", $phone);
    $stmt3->execute();
    $user = $stmt3->get_result()->fetch_assoc();
    if(floatval($user['balance']) < $final) { echo json_encode(["status"=>"error", "message"=>"余额不足"]); exit; }

    $conn->begin_transaction();
    try {
        $stmt4 = $conn->prepare("UPDATE users SET balance = balance - ? WHERE phone = ?");
        $stmt4->bind_param("ds", $final, $phone);
        $stmt4->execute();
        
        $stmt5 = $conn->prepare("UPDATE bookings SET status = '已支付', payment_status = 'paid' WHERE id = ?");
        $stmt5->bind_param("i", $id);
        $stmt5->execute();
        
        if($c_id > 0) {
            $stmt6 = $conn->prepare("UPDATE user_coupons SET status = 'used' WHERE id = ?");
            $stmt6->bind_param("i", $c_id);
            $stmt6->execute();
        }
        
        $amountStr = "-$final";
        $stmt7 = $conn->prepare("INSERT INTO transactions (user_phone, type, amount, title) VALUES (?, 'payment', ?, '支付课程费')");
        $stmt7->bind_param("ss", $phone, $amountStr);
        $stmt7->execute();
        
        // 通知老师
        $tn = $bk['tutor_name'];
        $stmt8 = $conn->prepare("SELECT phone FROM tutors WHERE name = ?");
        $stmt8->bind_param("s", $tn);
        $stmt8->execute();
        $tr = $stmt8->get_result()->fetch_assoc();
        if($tr) {
            $notifContent = "新订单入账: +$final";
            $stmt9 = $conn->prepare("INSERT INTO notifications (user_phone, content) VALUES (?, ?)");
            $stmt9->bind_param("ss", $tr['phone'], $notifContent);
            $stmt9->execute();
        }
        
        $conn->commit();
        echo json_encode(["status"=>"success"]);
    } catch(Exception $e) {
        $conn->rollback();
        echo json_encode(["status"=>"error", "message"=>"支付处理失败"]);
    }
}

// 申请退款 - 需要Session验证
else if ($action == 'apply_refund') {
    $id = intval($_POST['id']);
    $phone = sanitize($conn, $_POST['phone']);
    
    // 安全验证
    if (!verifyUserSession($phone)) {
        unauthorizedResponse();
    }
    
    $reason = sanitize($conn, $_POST['reason']);
    
    // 验证订单所属
    $stmt = $conn->prepare("SELECT * FROM bookings WHERE id = ? AND user_phone = ?");
    $stmt->bind_param("is", $id, $phone);
    $stmt->execute();
    $bk = $stmt->get_result()->fetch_assoc();
    
    if(!$bk || $bk['status'] != '已支付') { 
        echo json_encode(["status"=>"error", "message"=>"无法退款"]); 
        exit; 
    }
    
    $conn->begin_transaction();
    try {
        $stmt2 = $conn->prepare("UPDATE bookings SET status = '退款中' WHERE id = ?");
        $stmt2->bind_param("i", $id);
        $stmt2->execute();
        
        $price = $bk['price'];
        $stmt3 = $conn->prepare("INSERT INTO refunds (user_phone, booking_id, amount, reason, status) VALUES (?, ?, ?, ?, 'pending')");
        $stmt3->bind_param("sids", $phone, $id, $price, $reason);
        $stmt3->execute();
        
        $conn->commit();
        echo json_encode(["status"=>"success"]);
    } catch(Exception $e) {
        $conn->rollback();
        echo json_encode(["status"=>"error", "message"=>"退款申请失败"]);
    }
}

// 提交评价 - 需要Session验证
else if ($action == 'submit_review') {
    $bid = intval($_POST['booking_id']);
    $p = sanitize($conn, $_POST['phone']);
    
    // 安全验证
    if (!verifyUserSession($p)) {
        unauthorizedResponse();
    }
    
    $r = intval($_POST['rating']);
    $c = sanitize($conn, $_POST['content']);
    
    // 验证订单所属
    $stmt = $conn->prepare("SELECT tutor_name, user_phone FROM bookings WHERE id = ?");
    $stmt->bind_param("i", $bid);
    $stmt->execute();
    $bk = $stmt->get_result()->fetch_assoc();
    
    if(!$bk || $bk['user_phone'] != $p) {
        unauthorizedResponse();
    }
    
    $tn = $bk['tutor_name'];
    $stmt2 = $conn->prepare("SELECT id FROM tutors WHERE name = ?");
    $stmt2->bind_param("s", $tn);
    $stmt2->execute();
    $t = $stmt2->get_result()->fetch_assoc();
    
    if($t) {
        $tid = $t['id'];
        
        $stmt3 = $conn->prepare("INSERT INTO reviews (user_phone, tutor_id, booking_id, rating, content, create_time) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt3->bind_param("siiis", $p, $tid, $bid, $r, $c);
        $stmt3->execute();
        
        $stmt4 = $conn->prepare("UPDATE bookings SET status = '已完成' WHERE id = ?");
        $stmt4->bind_param("i", $bid);
        $stmt4->execute();
        
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

// 切换收藏 - 需要Session验证
else if ($action == 'toggle_favorite') {
    $phone = sanitize($conn, $_POST['phone']);
    $tid = intval($_POST['tutor_id']);
    
    // 安全验证
    if (!verifyUserSession($phone)) {
        unauthorizedResponse();
    }
    
    $stmt = $conn->prepare("SELECT id FROM favorites WHERE user_phone = ? AND tutor_id = ?");
    $stmt->bind_param("si", $phone, $tid);
    $stmt->execute();
    $check = $stmt->get_result();
    
    if ($check && $check->num_rows > 0) {
        $stmt2 = $conn->prepare("DELETE FROM favorites WHERE user_phone = ? AND tutor_id = ?");
        $stmt2->bind_param("si", $phone, $tid);
        $stmt2->execute();
        echo json_encode(["status"=>"success", "action"=>"removed", "message"=>"已取消收藏"]);
    } else {
        $stmt3 = $conn->prepare("INSERT INTO favorites (user_phone, tutor_id) VALUES (?, ?)");
        $stmt3->bind_param("si", $phone, $tid);
        $stmt3->execute();
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