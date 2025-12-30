<?php
// api/withdraw_api.php - 提现系统核心接口
header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);
require '../config/db.php';

$action = isset($_GET['action']) ? $_GET['action'] : '';

// 1. 提交提现申请 (教员端)
if ($action == 'request_withdrawal') {
    $phone = $_POST['phone'];
    $amount = floatval($_POST['amount']);
    $method = $conn->real_escape_string($_POST['method']);   // alipay / wechat
    $account = $conn->real_escape_string($_POST['account']); // 账号
    
    if ($amount < 10) { echo json_encode(["status"=>"error", "message"=>"最低提现 10 元"]); exit; }
    
    // 检查余额是否足够 (防止并发，最好加锁，这里简化处理)
    $tutor = $conn->query("SELECT balance FROM tutors WHERE phone='$phone'")->fetch_assoc();
    if (!$tutor || floatval($tutor['balance']) < $amount) {
        echo json_encode(["status"=>"error", "message"=>"余额不足"]);
        exit;
    }
    
    $conn->begin_transaction();
    try {
        // 1. 扣除余额
        $conn->query("UPDATE tutors SET balance = balance - $amount WHERE phone='$phone'");
        
        // 2. 创建提现记录
        $sql = "INSERT INTO withdrawals (user_phone, amount, method, account_info, status, create_time) 
                VALUES ('$phone', '$amount', '$method', '$account', 'pending', NOW())";
        $conn->query($sql);
        
        // 3. 记录流水
        $conn->query("INSERT INTO transactions (user_phone, type, amount, title) VALUES ('$phone', 'withdraw', '-$amount', '申请提现')");
        
        $conn->commit();
        echo json_encode(["status"=>"success"]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(["status"=>"error", "message"=>"系统错误"]);
    }
}

// 2. 获取待处理提现列表 (管理员端)
else if ($action == 'admin_get_pending') {
    $res = $conn->query("SELECT * FROM withdrawals WHERE status='pending' ORDER BY create_time DESC");
    $list = [];
    if($res) while($r = $res->fetch_assoc()) $list[] = $r;
    echo json_encode(["status"=>"success", "data"=>$list]);
}

// 3. 处理提现 (管理员端：通过/拒绝)
else if ($action == 'admin_process') {
    $id = $_POST['id'];
    $status = $_POST['status']; // 'approved' 或 'rejected'
    $reason = isset($_POST['reason']) ? $conn->real_escape_string($_POST['reason']) : '';
    
    $conn->begin_transaction();
    try {
        // 更新提现单状态
        $conn->query("UPDATE withdrawals SET status='$status' WHERE id='$id'");
        
        // 如果拒绝，需要把钱退回给教员
        if ($status == 'rejected') {
            $wd = $conn->query("SELECT user_phone, amount FROM withdrawals WHERE id='$id'")->fetch_assoc();
            if ($wd) {
                $phone = $wd['user_phone'];
                $amount = $wd['amount'];
                $conn->query("UPDATE tutors SET balance = balance + $amount WHERE phone='$phone'");
                $conn->query("INSERT INTO transactions (user_phone, type, amount, title) VALUES ('$phone', 'refund', '+$amount', '提现失败退回')");
                // 发通知
                $conn->query("INSERT INTO notifications (user_phone, content) VALUES ('$phone', '您的提现申请被拒绝，原因：$reason')");
            }
        } else {
            // 如果通过，发通知
            $wd = $conn->query("SELECT user_phone FROM withdrawals WHERE id='$id'")->fetch_assoc();
            if ($wd) $conn->query("INSERT INTO notifications (user_phone, content) VALUES ('".$wd['user_phone']."', '您的提现已打款，请留意账户变动')");
        }
        
        $conn->commit();
        echo json_encode(["status"=>"success"]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(["status"=>"error", "message"=>$e->getMessage()]);
    }
}

$conn->close();
?>