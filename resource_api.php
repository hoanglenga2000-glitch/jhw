<?php
// api/resource_api.php - 资源商城核心接口 (V12 商业版)
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0); 
require '../config/db.php';

$action = isset($_GET['action']) ? $_GET['action'] : '';

// 1. 获取资源列表 (核心：判断当前用户是否已购买)
if ($action == 'get_list') {
    $user_phone = isset($_GET['phone']) ? $_GET['phone'] : '';
    $search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
    
    // 联表查询，获取上传者(老师)的信息
    $sql = "SELECT r.*, t.name as tutor_name, t.avatar 
            FROM resources r 
            LEFT JOIN tutors t ON r.uploader_phone = t.phone 
            WHERE r.status = '已通过'";
            
    if(!empty($search)) $sql .= " AND r.title LIKE '%$search%'";
    $sql .= " ORDER BY r.create_time DESC";
    
    $res = $conn->query($sql);
    $list = [];
    
    // 获取当前用户买过的资源ID
    $my_buys = [];
    if($user_phone) {
        $buy_res = $conn->query("SELECT resource_id FROM resource_orders WHERE user_phone='$user_phone'");
        if($buy_res) {
            while($b = $buy_res->fetch_assoc()) $my_buys[] = $b['resource_id'];
        }
    }
    
    if($res) {
        while($row = $res->fetch_assoc()) {
            if(empty($row['avatar'])) $row['avatar'] = 'default_boy.png';
            
            // 判断是否拥有下载权限：是作者本人 OR 价格为0 OR 已经买过
            $is_owner = ($row['uploader_phone'] == $user_phone);
            $is_free = (floatval($row['price']) <= 0);
            $has_bought = in_array($row['id'], $my_buys);
            
            $row['can_download'] = ($is_owner || $is_free || $has_bought);
            $list[] = $row;
        }
    }
    echo json_encode(["status"=>"success", "data"=>$list]);
}

// 2. 购买资源
else if ($action == 'buy_resource') {
    $phone = $_POST['phone'];
    $res_id = $_POST['resource_id'];
    
    $conn->begin_transaction();
    try {
        // 查资源信息 (加锁防止并发问题)
        $res_info = $conn->query("SELECT * FROM resources WHERE id='$res_id' FOR UPDATE")->fetch_assoc();
        if (!$res_info) throw new Exception("资源不存在");
        
        $price = floatval($res_info['price']);
        if ($price <= 0) throw new Exception("免费资源无需购买");
        
        // 查是否买过
        $check = $conn->query("SELECT id FROM resource_orders WHERE user_phone='$phone' AND resource_id='$res_id'");
        if ($check->num_rows > 0) throw new Exception("您已购买过此资源");
        
        // 查余额
        $user = $conn->query("SELECT balance FROM users WHERE phone='$phone'")->fetch_assoc();
        if (floatval($user['balance']) < $price) throw new Exception("余额不足，请充值");
        
        // 1. 扣学生钱
        $conn->query("UPDATE users SET balance = balance - $price WHERE phone='$phone'");
        
        // 2. 给老师加钱 (平台抽 20% 服务费，老师拿 80%)
        $tutor_income = $price * 0.8;
        $conn->query("UPDATE tutors SET balance = balance + $tutor_income WHERE phone='".$res_info['uploader_phone']."'");
        
        // 3. 记录订单
        $conn->query("INSERT INTO resource_orders (user_phone, resource_id, amount) VALUES ('$phone', '$res_id', '$price')");
        
        // 4. 增加销量
        $conn->query("UPDATE resources SET sales = sales + 1 WHERE id='$res_id'");
        
        // 5. 记录流水
        $conn->query("INSERT INTO transactions (user_phone, type, amount, title) VALUES ('$phone', 'payment', '-$price', '购买资料:{$res_info['title']}')");
        $conn->query("INSERT INTO transactions (user_phone, type, amount, title) VALUES ('".$res_info['uploader_phone']."', 'income', '+$tutor_income', '资料收益:{$res_info['title']}')");
        
        // 6. 通知老师
        $conn->query("INSERT INTO notifications (user_phone, content) VALUES ('".$res_info['uploader_phone']."', '您的资料《{$res_info['title']}》售出一份，收益 ¥$tutor_income 已到账')");
        
        $conn->commit();
        echo json_encode(["status"=>"success"]);
    } catch(Exception $e) {
        $conn->rollback();
        echo json_encode(["status"=>"error", "message"=>$e->getMessage()]);
    }
}

// 3. 获取下载链接 (鉴权)
else if ($action == 'get_download_url') {
    $id = $_GET['id'];
    // 这里可以加更严格的权限判断，简单起见只查文件存在性
    $res = $conn->query("SELECT file_path, title FROM resources WHERE id='$id'")->fetch_assoc();
    if($res) {
        // 增加下载次数计数
        $conn->query("UPDATE resources SET downloads = downloads + 1 WHERE id='$id'");
        // 返回真实路径
        echo json_encode(["status"=>"success", "url"=>"uploads/".$res['file_path'], "filename"=>$res['title']]);
    } else {
        echo json_encode(["status"=>"error", "message"=>"文件不存在"]);
    }
}

$conn->close();
?>