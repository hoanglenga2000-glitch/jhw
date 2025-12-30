<?php
// api/gamification_api.php - 积分系统核心接口
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);
require '../config/db.php';

$action = isset($_GET['action']) ? $_GET['action'] : '';

// 1. 获取积分信息 & 签到状态
if ($action == 'get_status') {
    $phone = $_GET['phone'];
    $today = date('Y-m-d');
    
    // 查积分
    $u = $conn->query("SELECT points FROM users WHERE phone='$phone'")->fetch_assoc();
    $points = $u ? intval($u['points']) : 0;
    
    // 查今日是否签到
    $check = $conn->query("SELECT id FROM signins WHERE user_phone='$phone' AND signin_date='$today'");
    $is_signed = ($check && $check->num_rows > 0);
    
    // 查连续签到天数 (简化版：只查最近7天)
    // 商业版通常需要复杂的递归查询，这里为了性能做简化
    
    echo json_encode([
        "status" => "success", 
        "points" => $points, 
        "is_signed" => $is_signed
    ]);
}

// 2. 执行签到
else if ($action == 'do_signin') {
    $phone = $_POST['phone'];
    $role = 'student'; // 默认学生，教员端也可以复用
    $today = date('Y-m-d');
    
    // 防止重复
    $check = $conn->query("SELECT id FROM signins WHERE user_phone='$phone' AND signin_date='$today'");
    if($check->num_rows > 0) { echo json_encode(["status"=>"error", "message"=>"今日已签到"]); exit; }
    
    // 随机积分算法 (10-50分) + 连签奖励逻辑可在此扩展
    $add_points = rand(10, 30); 
    
    $conn->begin_transaction();
    try {
        // 记录签到
        $conn->query("INSERT INTO signins (user_phone, role, signin_date, points) VALUES ('$phone', '$role', '$today', '$add_points')");
        // 加积分
        $conn->query("UPDATE users SET points = points + $add_points WHERE phone='$phone'");
        // 记流水
        $conn->query("INSERT INTO points_log (user_phone, type, amount, description) VALUES ('$phone', 'signin', '$add_points', '每日签到奖励')");
        
        $conn->commit();
        echo json_encode(["status"=>"success", "added" => $add_points, "message" => "签到成功！获得 $add_points 积分"]);
    } catch(Exception $e) {
        $conn->rollback();
        echo json_encode(["status"=>"error", "message"=>"签到失败"]);
    }
}

// 3. 获取商城商品列表
else if ($action == 'get_mall_items') {
    $res = $conn->query("SELECT * FROM coupons WHERE points_cost > 0 ORDER BY points_cost ASC");
    $list = []; if($res) while($r=$res->fetch_assoc()) $list[]=$r;
    echo json_encode(["status"=>"success", "data"=>$list]);
}

// 4. 兑换商品
else if ($action == 'exchange_item') {
    $phone = $_POST['phone'];
    $coupon_id = $_POST['coupon_id'];
    
    $conn->begin_transaction();
    try {
        // 查商品价格
        $item = $conn->query("SELECT * FROM coupons WHERE id='$coupon_id' FOR UPDATE")->fetch_assoc();
        if(!$item) throw new Exception("商品不存在");
        $cost = intval($item['points_cost']);
        
        // 查用户积分
        $user = $conn->query("SELECT points FROM users WHERE phone='$phone'")->fetch_assoc();
        if(intval($user['points']) < $cost) throw new Exception("积分不足");
        
        // 扣积分
        $conn->query("UPDATE users SET points = points - $cost WHERE phone='$phone'");
        // 发优惠券
        $conn->query("INSERT INTO user_coupons (user_phone, coupon_id, status, create_time) VALUES ('$phone', '$coupon_id', 'unused', NOW())");
        // 记流水
        $conn->query("INSERT INTO points_log (user_phone, type, amount, description) VALUES ('$phone', 'exchange', '-$cost', '兑换: {$item['name']}')");
        
        $conn->commit();
        echo json_encode(["status"=>"success"]);
    } catch(Exception $e) {
        $conn->rollback();
        echo json_encode(["status"=>"error", "message"=>$e->getMessage()]);
    }
}

$conn->close();
?>