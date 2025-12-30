<?php
// api/teacher_api.php - 教员端全功能核心接口 (修复登录)
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0); 
require '../config/db.php';

$action = isset($_GET['action']) ? $_GET['action'] : '';

// ==================== 1. 核心认证 (修复重点) ====================

// 教员登录
if ($action == 'login') {
    $phone = $_POST['phone'];
    $pass = $_POST['password'];
    
    // 查询教员
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
        
        // 检查审核状态 (根据你的需求，这里允许'待审核'登录，以便他们完善资料)
        // 如果你想禁止待审核登录，把下面注释解开：
        /*
        if ($res['status'] == '待审核') {
            echo json_encode(["status"=>"error", "message"=>"您的入驻申请正在审核中，请耐心等待"]);
            exit;
        }
        if ($res['status'] == '已拒绝') {
            echo json_encode(["status"=>"error", "message"=>"您的审核未通过，请联系管理员"]);
            exit;
        }
        */

        // 处理 VIP 过期逻辑
        if ($res['is_vip'] == 1 && strtotime($res['vip_expire_time']) < time()) {
            $conn->query("UPDATE tutors SET is_vip=0 WHERE id='".$res['id']."'");
            $res['is_vip'] = 0;
        }
        
        // 默认头像处理
        if (empty($res['avatar'])) $res['avatar'] = 'default_boy.png';
        
        echo json_encode(["status"=>"success", "data"=>$res]);
    } else {
        echo json_encode(["status"=>"error", "message"=>"手机号或密码错误"]);
    }
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

// 更新资料
else if ($action == 'update_info') { 
    $id = $_POST['id']; 
    $sc = $conn->real_escape_string($_POST['school']); 
    $ma = $conn->real_escape_string($_POST['major']); 
    $su = $conn->real_escape_string($_POST['subject']); 
    $pr = floatval($_POST['price']); 
    $st = $conn->real_escape_string($_POST['teaching_style']); 
    $in = $conn->real_escape_string($_POST['intro']); 
    $ex = $conn->real_escape_string($_POST['experience']); 
    $ho = $conn->real_escape_string($_POST['honors']); 
    
    $avatar_sql = ""; 
    if(isset($_FILES['avatar']) && $_FILES['avatar']['error']==0) {
        $ext = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
        $new_name = "tutor_".$id."_".time().".".$ext;
        if(move_uploaded_file($_FILES['avatar']['tmp_name'], "../assets/".$new_name)) {
            $avatar_sql = ", avatar='$new_name'";
        }
    }
    
    $sql = "UPDATE tutors SET school='$sc', major='$ma', subject='$su', price='$pr', teaching_style='$st', intro='$in', experience='$ex', honors='$ho' $avatar_sql WHERE id='$id'";
    
    if($conn->query($sql)) echo json_encode(['status'=>'success']); 
    else echo json_encode(['status'=>'error', 'message'=>$conn->error]);
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

// 确认完课 (结算)
else if ($action == 'finish_class') { 
    $id = $_POST['id']; 
    $bk = $conn->query("SELECT * FROM bookings WHERE id='$id'")->fetch_assoc(); 
    
    if($bk && $bk['status']=='已支付') { 
        $p = floatval($bk['price']); 
        $tn = $conn->real_escape_string($bk['tutor_name']); 
        $t = $conn->query("SELECT id, phone, is_vip, vip_expire_time FROM tutors WHERE name='$tn'")->fetch_assoc(); 
        
        // 平台抽成：VIP抽5%，普通抽10%
        $rate = ($t['is_vip']==1 && strtotime($t['vip_expire_time']) > time()) ? 0.05 : 0.10; 
        $income = $p * (1 - $rate); 
        
        $conn->begin_transaction(); 
        try {
            $conn->query("UPDATE bookings SET status='待评价' WHERE id='$id'"); 
            $conn->query("UPDATE tutors SET balance = balance + $income WHERE id='".$t['id']."'"); 
            $conn->query("INSERT INTO transactions (user_phone, type, amount, title) VALUES ('".$t['phone']."', 'income', '+$income', '课时费结算')"); 
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

// 上传资源 (含价格)
else if ($action == 'upload_resource') {
    $phone = $_POST['uploader_phone'];
    $title = $conn->real_escape_string($_POST['title']);
    $price = isset($_POST['price']) ? floatval($_POST['price']) : 0;
    
    if(isset($_FILES['file']) && $_FILES['file']['error'] == 0) {
        $ext = pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION);
        $file_name = "res_" . time() . "_" . rand(100,999) . "." . $ext;
        
        // 检查上传目录
        if (!file_exists('../uploads')) mkdir('../uploads', 0777, true);
        
        if(move_uploaded_file($_FILES['file']['tmp_name'], "../uploads/" . $file_name)) {
            $sql = "INSERT INTO resources (title, type, description, file_path, uploader_phone, price, status, create_time) 
                    VALUES ('$title', '".strtoupper($ext)."', '$title', '$file_name', '$phone', '$price', '待审核', NOW())";
            if($conn->query($sql)) echo json_encode(["status" => "success"]);
            else echo json_encode(["status" => "error", "message" => "DB Error: ".$conn->error]);
        } else {
            echo json_encode(["status" => "error", "message" => "文件移动失败"]);
        }
    } else {
        echo json_encode(["status" => "error", "message" => "未选择文件"]);
    }
}

// 获取我的资源
else if ($action == 'get_my_resources') { 
    $p = $_GET['phone']; 
    $r = $conn->query("SELECT * FROM resources WHERE uploader_phone='$p' ORDER BY create_time DESC"); 
    $l = []; while($row=$r->fetch_assoc()) $l[]=$row; 
    echo json_encode(["status"=>"success","data"=>$l]); 
}

// 删除资源
else if ($action == 'delete_resource') { 
    $id = $_POST['id']; 
    $conn->query("DELETE FROM resources WHERE id='$id'"); 
    echo json_encode(["status"=>"success"]); 
}

// 购买 VIP
else if ($action == 'buy_vip') { 
    $id = $_POST['id']; 
    $price = 299; 
    $t = $conn->query("SELECT balance, is_vip, vip_expire_time FROM tutors WHERE id='$id'")->fetch_assoc(); 
    
    if(floatval($t['balance']) < $price) { echo json_encode(['status'=>'error','message'=>'余额不足']); exit; }
    
    $start_time = ($t['is_vip'] && strtotime($t['vip_expire_time']) > time()) ? strtotime($t['vip_expire_time']) : time();
    $new_expire = date('Y-m-d H:i:s', strtotime('+30 days', $start_time));
    
    $conn->begin_transaction();
    $conn->query("UPDATE tutors SET balance = balance - $price, is_vip=1, vip_expire_time='$new_expire' WHERE id='$id'");
    // 记录教员消费流水
    $t_res = $conn->query("SELECT phone FROM tutors WHERE id='$id'")->fetch_assoc();
    $conn->query("INSERT INTO transactions (user_phone, type, amount, title) VALUES ('".$t_res['phone']."', 'payment', '-$price', '购买VIP会员')");
    $conn->commit();
    
    echo json_encode(['status'=>'success']); 
}

$conn->close();
?>