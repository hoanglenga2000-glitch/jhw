<?php
// api/demand_api.php - 需求大厅核心接口
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);
require '../config/db.php';

$action = isset($_GET['action']) ? $_GET['action'] : '';

// ================== 学生端功能 ==================

// 1. 发布需求
if ($action == 'post_demand') {
    $phone = $_POST['phone'];
    $subject = $conn->real_escape_string($_POST['subject']);
    $grade = $conn->real_escape_string($_POST['grade']);
    $budget = floatval($_POST['budget']);
    $req = $conn->real_escape_string($_POST['requirement']);
    
    $sql = "INSERT INTO demands (student_phone, subject, grade, budget, requirement, status, create_time) 
            VALUES ('$phone', '$subject', '$grade', '$budget', '$req', 'open', NOW())";
            
    if ($conn->query($sql)) echo json_encode(["status"=>"success"]);
    else echo json_encode(["status"=>"error", "message"=>$conn->error]);
}

// 2. 获取我的需求 (含应聘人数)
else if ($action == 'get_my_demands') {
    $phone = $_GET['phone'];
    // 统计每个需求的应聘人数
    $sql = "SELECT d.*, (SELECT COUNT(*) FROM demand_applies da WHERE da.demand_id = d.id) as apply_count 
            FROM demands d 
            WHERE d.student_phone='$phone' 
            ORDER BY d.create_time DESC";
    $res = $conn->query($sql);
    $list = []; if($res) while($r=$res->fetch_assoc()) $list[]=$r;
    echo json_encode(["status"=>"success", "data"=>$list]);
}

// 3. 查看某需求的应聘老师列表
else if ($action == 'get_appliers') {
    $did = $_GET['demand_id'];
    $sql = "SELECT da.*, t.name, t.school, t.major, t.avatar, t.price 
            FROM demand_applies da 
            JOIN tutors t ON da.tutor_id = t.id 
            WHERE da.demand_id='$did' AND da.status='pending'";
    $res = $conn->query($sql);
    $list = []; if($res) while($r=$res->fetch_assoc()) $list[]=$r;
    echo json_encode(["status"=>"success", "data"=>$list]);
}

// 4. 录用老师 (自动生成订单)
else if ($action == 'accept_tutor') {
    $apply_id = $_POST['apply_id'];
    
    // 获取应聘信息
    $apply = $conn->query("SELECT da.*, d.student_phone, d.budget, t.name as tutor_name, t.phone as tutor_phone 
                           FROM demand_applies da 
                           JOIN demands d ON da.demand_id = d.id 
                           JOIN tutors t ON da.tutor_id = t.id 
                           WHERE da.id='$apply_id'")->fetch_assoc();
                           
    if($apply) {
        $conn->begin_transaction();
        try {
            // 1. 标记需求为已关闭
            $conn->query("UPDATE demands SET status='closed' WHERE id='".$apply['demand_id']."'");
            // 2. 标记该应聘为已录用
            $conn->query("UPDATE demand_applies SET status='accepted' WHERE id='$apply_id'");
            // 3. 生成待支付订单
            $lesson_time = "协商时间"; // 需求单通常不指定具体时间，需后续沟通
            $price = $apply['budget'];
            $sql = "INSERT INTO bookings (user_phone, tutor_name, lesson_time, status, price, create_time) 
                    VALUES ('".$apply['student_phone']."', '".$apply['tutor_name']."', '$lesson_time', '已通过', '$price', NOW())"; // 状态直接给已通过(待支付)
            $conn->query($sql);
            
            // 4. 通知老师
            $conn->query("INSERT INTO notifications (user_phone, content) VALUES ('".$apply['tutor_phone']."', '恭喜！您的应聘已被录用，请等待学生支付。')");
            
            $conn->commit();
            echo json_encode(["status"=>"success"]);
        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(["status"=>"error", "message"=>$e->getMessage()]);
        }
    } else {
        echo json_encode(["status"=>"error", "message"=>"记录不存在"]);
    }
}

// ================== 教员端功能 ==================

// 5. 获取需求大厅列表 (仅显示 open 的)
else if ($action == 'get_hall_list') {
    $tutor_id = $_GET['tutor_id'];
    
    // 获取所有开启的需求，并标记当前老师是否已申请
    $sql = "SELECT d.*, 
            (SELECT COUNT(*) FROM demand_applies da WHERE da.demand_id = d.id AND da.tutor_id = '$tutor_id') as has_applied
            FROM demands d 
            WHERE d.status='open' 
            ORDER BY d.create_time DESC";
            
    $res = $conn->query($sql);
    $list = []; if($res) while($r=$res->fetch_assoc()) $list[]=$r;
    echo json_encode(["status"=>"success", "data"=>$list]);
}

// 6. 抢单/应聘
else if ($action == 'apply_demand') {
    $tid = $_POST['tutor_id'];
    $did = $_POST['demand_id'];
    
    // 检查是否重复
    $check = $conn->query("SELECT id FROM demand_applies WHERE demand_id='$did' AND tutor_id='$tid'");
    if($check->num_rows > 0) { echo json_encode(["status"=>"error", "message"=>"已抢过此单"]); exit; }
    
    $sql = "INSERT INTO demand_applies (demand_id, tutor_id, status, create_time) VALUES ('$did', '$tid', 'pending', NOW())";
    if($conn->query($sql)) echo json_encode(["status"=>"success"]);
    else echo json_encode(["status"=>"error"]);
}

$conn->close();
?>