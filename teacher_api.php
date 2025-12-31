<?php
// api/teacher_api.php - 教员端全功能核心接口 (V2 - 安全加固版)
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0); 
require '../config/db.php';

// ==================== Session安全验证 ====================
session_start();

/**
 * 验证教员Session权限
 * @param int $tutor_id 请求操作的教员ID
 * @return bool 是否验证通过
 */
function verifyTutorSession($tutor_id) {
    if (!isset($_SESSION['tutor_id'])) {
        return false;
    }
    return $_SESSION['tutor_id'] == $tutor_id;
}

/**
 * 通过手机号验证教员Session
 */
function verifyTutorByPhone($phone) {
    if (!isset($_SESSION['tutor_phone'])) {
        return false;
    }
    return $_SESSION['tutor_phone'] === $phone;
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

// ==================== 1. 核心认证 (安全加固版) ====================

// 教员登录 - 设置Session
if ($action == 'login') {
    $phone = sanitize($conn, $_POST['phone']);
    $pass = sanitize($conn, $_POST['password']);
    
    // 使用预处理语句查询教员
    $stmt = $conn->prepare("SELECT * FROM tutors WHERE phone = ? AND password = ?");
    $stmt->bind_param("ss", $phone, $pass);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    
    if ($res) {
        // 检查封禁状态
        if ($res['is_banned'] == 1) { 
            echo json_encode(["status"=>"error", "message"=>"账号已封禁，请联系客服"]); 
            exit; 
        }

        // 处理 VIP 过期逻辑
        if ($res['is_vip'] == 1 && strtotime($res['vip_expire_time']) < time()) {
            $stmt2 = $conn->prepare("UPDATE tutors SET is_vip = 0 WHERE id = ?");
            $stmt2->bind_param("i", $res['id']);
            $stmt2->execute();
            $res['is_vip'] = 0;
        }
        
        // 设置Session
        $_SESSION['tutor_id'] = $res['id'];
        $_SESSION['tutor_phone'] = $res['phone'];
        $_SESSION['tutor_name'] = $res['name'];
        $_SESSION['login_time'] = time();
        
        // 默认头像处理
        if (empty($res['avatar'])) $res['avatar'] = 'default_boy.png';
        
        // 不返回密码
        unset($res['password']);
        
        echo json_encode(["status"=>"success", "data"=>$res]);
    } else {
        echo json_encode(["status"=>"error", "message"=>"手机号或密码错误"]);
    }
}

// 教员登出 - 清除Session
else if ($action == 'logout') {
    $_SESSION = array();
    session_destroy();
    echo json_encode(['status'=>'success', 'message'=>'已退出登录']);
}

// 教员注册 (简易版 & 完整版兼容)
else if ($action == 'register' || $action == 'register_simple') {
    $phone = $_POST['phone'];
    
    $check = $conn->query("SELECT id FROM tutors WHERE phone='$phone'");
    if($check->num_rows > 0) { echo json_encode(["status"=>"error", "message"=>"手机号已存在"]); exit; }

    $name = $conn->real_escape_string(isset($_POST['username']) ? $_POST['username'] : $_POST['name']); // 兼容不同字段名
    $pass = $conn->real_escape_string($_POST['password']);
    
    // 初始化默认值
    $school = isset($_POST['school']) ? $conn->real_escape_string($_POST['school']) : '未填写';
    $major = isset($_POST['major']) ? $conn->real_escape_string($_POST['major']) : '';
    $subject = isset($_POST['subject']) ? $conn->real_escape_string($_POST['subject']) : '';
    $price = isset($_POST['price']) ? floatval($_POST['price']) : 0;
    
    $sql = "INSERT INTO tutors (name, phone, password, school, major, subject, price, status, create_time) 
            VALUES ('$name', '$phone', '$pass', '$school', '$major', '$subject', '$price', '待审核', NOW())";
            
    if($conn->query($sql)) echo json_encode(["status"=>"success"]);
    else echo json_encode(["status"=>"error", "message"=>$conn->error]);
}

// 获取教员个人信息
else if ($action == 'get_info') {
    $id = $_GET['id'];
    $r = $conn->query("SELECT * FROM tutors WHERE id='$id'")->fetch_assoc();
    if($r){
        if(!isset($r['balance'])) $r['balance'] = 0;
        if($r['is_vip']==1 && strtotime($r['vip_expire_time']) < time()) { 
            $conn->query("UPDATE tutors SET is_vip=0 WHERE id='$id'"); 
            $r['is_vip'] = 0; 
        }
        echo json_encode(['status'=>'success','data'=>$r]);
    } else {
        echo json_encode(['status'=>'error', 'message'=>'账号异常']);
    }
}

// 更新资料 - 需要Session验证
else if ($action == 'update_info') { 
    $id = intval($_POST['id']); 
    
    // 安全验证
    if (!verifyTutorSession($id)) {
        unauthorizedResponse();
    }
    
    $sc = sanitize($conn, $_POST['school']); 
    $ma = sanitize($conn, $_POST['major']); 
    $su = sanitize($conn, $_POST['subject']); 
    $pr = floatval($_POST['price']); 
    $st = sanitize($conn, $_POST['teaching_style']); 
    $in = sanitize($conn, $_POST['intro']); 
    $ex = sanitize($conn, $_POST['experience']); 
    $ho = sanitize($conn, $_POST['honors']); 
    
    $avatar_name = null; 
    if(isset($_FILES['avatar']) && $_FILES['avatar']['error']==0) {
        // 验证文件类型
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($_FILES['avatar']['type'], $allowed_types)) {
            echo json_encode(['status'=>'error', 'message'=>'不支持的图片格式']);
            exit;
        }
        
        $ext = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
        $avatar_name = "tutor_".$id."_".time().".".$ext;
        if(!move_uploaded_file($_FILES['avatar']['tmp_name'], "../assets/".$avatar_name)) {
            $avatar_name = null;
        }
    }
    
    if ($avatar_name) {
        $stmt = $conn->prepare("UPDATE tutors SET school=?, major=?, subject=?, price=?, teaching_style=?, intro=?, experience=?, honors=?, avatar=? WHERE id=?");
        $stmt->bind_param("sssdsssssi", $sc, $ma, $su, $pr, $st, $in, $ex, $ho, $avatar_name, $id);
    } else {
        $stmt = $conn->prepare("UPDATE tutors SET school=?, major=?, subject=?, price=?, teaching_style=?, intro=?, experience=?, honors=? WHERE id=?");
        $stmt->bind_param("sssdssssi", $sc, $ma, $su, $pr, $st, $in, $ex, $ho, $id);
    }
    
    if($stmt->execute()) echo json_encode(['status'=>'success']); 
    else echo json_encode(['status'=>'error', 'message'=>'更新失败']);
}

// ==================== 2. 业务功能 (接单/资源/VIP) ====================

// 获取预约订单
else if ($action == 'get_bookings') { 
    $n = isset($_GET['name']) ? $conn->real_escape_string($_GET['name']) : '';
    // 如果名字为空，可能是新注册还没名字，防止报错
    if(empty($n)) { echo json_encode(['status'=>'success','data'=>[]]); exit; }
    
    $r = $conn->query("SELECT * FROM bookings WHERE tutor_name='$n' ORDER BY create_time DESC"); 
    $l = []; if($r) while($row=$r->fetch_assoc()) $l[]=$row; 
    echo json_encode(['status'=>'success','data'=>$l]); 
}

// 处理订单 (接单/拒绝)
else if ($action == 'handle_booking') { 
    $id = $_POST['id']; 
    $s = $_POST['status']; 
    $conn->query("UPDATE bookings SET status='$s' WHERE id='$id'"); 
    
    // 如果是拒绝，发个通知给学生
    if($s == '已拒绝') {
        $bk = $conn->query("SELECT user_phone, tutor_name FROM bookings WHERE id='$id'")->fetch_assoc();
        if($bk) $conn->query("INSERT INTO notifications (user_phone, content) VALUES ('".$bk['user_phone']."', '您的预约被 ".$bk['tutor_name']." 老师拒绝了')");
    }
    echo json_encode(['status'=>'success']); 
}

// 确认完课 (结算) - 需要验证操作者是订单对应的教员
else if ($action == 'finish_class') { 
    $id = intval($_POST['id']); 
    
    // 查询订单信息
    $stmt = $conn->prepare("SELECT * FROM bookings WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $bk = $stmt->get_result()->fetch_assoc(); 
    
    if(!$bk) {
        echo json_encode(['status'=>'error', 'message'=>'订单不存在']);
        exit;
    }
    
    // 验证操作者是否是该订单的教员
    $tn = $bk['tutor_name'];
    $stmt2 = $conn->prepare("SELECT id, phone, is_vip, vip_expire_time FROM tutors WHERE name = ?");
    $stmt2->bind_param("s", $tn);
    $stmt2->execute();
    $t = $stmt2->get_result()->fetch_assoc();
    
    if(!$t || !verifyTutorSession($t['id'])) {
        unauthorizedResponse();
    }
    
    if($bk['status']=='已支付') { 
        $p = floatval($bk['price']); 
        
        // 平台抽成：VIP抽5%，普通抽10%
        $rate = ($t['is_vip']==1 && strtotime($t['vip_expire_time']) > time()) ? 0.05 : 0.10; 
        $income = round($p * (1 - $rate), 2); 
        
        $conn->begin_transaction(); 
        try {
            $stmt3 = $conn->prepare("UPDATE bookings SET status = '待评价' WHERE id = ?");
            $stmt3->bind_param("i", $id);
            $stmt3->execute();
            
            $stmt4 = $conn->prepare("UPDATE tutors SET balance = balance + ? WHERE id = ?");
            $stmt4->bind_param("di", $income, $t['id']);
            $stmt4->execute();
            
            $amountStr = "+$income";
            $stmt5 = $conn->prepare("INSERT INTO transactions (user_phone, type, amount, title) VALUES (?, 'income', ?, '课时费结算')");
            $stmt5->bind_param("ss", $t['phone'], $amountStr);
            $stmt5->execute();
            
            $conn->commit(); 
            echo json_encode(['status'=>'success']); 
        } catch(Exception $e) {
            $conn->rollback();
            echo json_encode(['status'=>'error', 'message'=>'结算失败']);
        }
    } else {
        echo json_encode(['status'=>'error', 'message'=>'订单状态不正确']);
    }
}

// 上传资源 (含价格) - 需要Session验证
else if ($action == 'upload_resource') {
    $phone = sanitize($conn, $_POST['uploader_phone']);
    
    // 安全验证
    if (!verifyTutorByPhone($phone)) {
        unauthorizedResponse();
    }
    
    $title = sanitize($conn, $_POST['title']);
    $price = isset($_POST['price']) ? floatval($_POST['price']) : 0;
    
    if(isset($_FILES['file']) && $_FILES['file']['error'] == 0) {
        // 验证文件大小（最大10MB）
        if($_FILES['file']['size'] > 10 * 1024 * 1024) {
            echo json_encode(["status" => "error", "message" => "文件大小不能超过10MB"]);
            exit;
        }
        
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        $file_name = "res_" . time() . "_" . rand(100,999) . "." . $ext;
        
        // 检查上传目录
        if (!file_exists('../uploads')) mkdir('../uploads', 0777, true);
        
        if(move_uploaded_file($_FILES['file']['tmp_name'], "../uploads/" . $file_name)) {
            $type = strtoupper($ext);
            $stmt = $conn->prepare("INSERT INTO resources (title, type, description, file_path, uploader_phone, price, status, create_time) 
                    VALUES (?, ?, ?, ?, ?, ?, '待审核', NOW())");
            $stmt->bind_param("sssssd", $title, $type, $title, $file_name, $phone, $price);
            
            if($stmt->execute()) echo json_encode(["status" => "success"]);
            else echo json_encode(["status" => "error", "message" => "保存失败"]);
        } else {
            echo json_encode(["status" => "error", "message" => "文件移动失败"]);
        }
    } else {
        echo json_encode(["status" => "error", "message" => "未选择文件"]);
    }
}

// 获取我的资源
else if ($action == 'get_my_resources') { 
    $p = sanitize($conn, $_GET['phone']); 
    
    $stmt = $conn->prepare("SELECT * FROM resources WHERE uploader_phone = ? ORDER BY create_time DESC");
    $stmt->bind_param("s", $p);
    $stmt->execute();
    $r = $stmt->get_result();
    
    $l = []; 
    while($row = $r->fetch_assoc()) $l[] = $row; 
    echo json_encode(["status"=>"success","data"=>$l]); 
}

// 删除资源 - 需要Session验证
else if ($action == 'delete_resource') { 
    $id = intval($_POST['id']); 
    
    // 先查询资源所属
    $stmt = $conn->prepare("SELECT uploader_phone FROM resources WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    
    if (!$res || !verifyTutorByPhone($res['uploader_phone'])) {
        unauthorizedResponse();
    }
    
    $stmt2 = $conn->prepare("DELETE FROM resources WHERE id = ?");
    $stmt2->bind_param("i", $id);
    $stmt2->execute();
    
    echo json_encode(["status"=>"success"]); 
}

// 购买 VIP - 需要Session验证
else if ($action == 'buy_vip') { 
    $id = intval($_POST['id']); 
    
    // 安全验证
    if (!verifyTutorSession($id)) {
        unauthorizedResponse();
    }
    
    $price = 299; 
    
    $stmt = $conn->prepare("SELECT balance, is_vip, vip_expire_time, phone FROM tutors WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $t = $stmt->get_result()->fetch_assoc(); 
    
    if(floatval($t['balance']) < $price) { 
        echo json_encode(['status'=>'error','message'=>'余额不足']); 
        exit; 
    }
    
    $start_time = ($t['is_vip'] && strtotime($t['vip_expire_time']) > time()) ? strtotime($t['vip_expire_time']) : time();
    $new_expire = date('Y-m-d H:i:s', strtotime('+30 days', $start_time));
    
    $conn->begin_transaction();
    try {
        $stmt2 = $conn->prepare("UPDATE tutors SET balance = balance - ?, is_vip = 1, vip_expire_time = ? WHERE id = ?");
        $stmt2->bind_param("dsi", $price, $new_expire, $id);
        $stmt2->execute();
        
        $amountStr = "-$price";
        $stmt3 = $conn->prepare("INSERT INTO transactions (user_phone, type, amount, title) VALUES (?, 'payment', ?, '购买VIP会员')");
        $stmt3->bind_param("ss", $t['phone'], $amountStr);
        $stmt3->execute();
        
        $conn->commit();
        echo json_encode(['status'=>'success']); 
    } catch(Exception $e) {
        $conn->rollback();
        echo json_encode(['status'=>'error', 'message'=>'购买失败']);
    }
}

$conn->close();
?>