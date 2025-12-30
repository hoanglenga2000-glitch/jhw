<?php
// api/chat_api.php - 聊天核心接口 (最终修复版)
header('Content-Type: application/json');
// 暂时关闭错误回显，避免破坏 JSON
error_reporting(E_ALL);
ini_set('display_errors', 0); 
require '../config/db.php';

$action = isset($_GET['action']) ? $_GET['action'] : '';

// 1. 获取联系人列表 (基于订单关系)
if ($action == 'get_contacts') {
    $role = $_GET['role']; // 'student' or 'teacher'
    $my_phone = $_GET['phone'];
    $list = [];

    if ($role == 'student') {
        // 学生看老师
        $sql = "SELECT DISTINCT t.name, t.phone, t.avatar, t.school 
                FROM bookings b 
                JOIN tutors t ON b.tutor_name = t.name 
                WHERE b.user_phone = '$my_phone'";
    } else {
        // 老师看学生
        $t_res = $conn->query("SELECT name FROM tutors WHERE phone='$my_phone'");
        if ($t_res && $row = $t_res->fetch_assoc()) {
            $tName = $conn->real_escape_string($row['name']);
            $sql = "SELECT DISTINCT u.username as name, u.phone, u.avatar, '学员' as school 
                    FROM bookings b 
                    JOIN users u ON b.user_phone = u.phone 
                    WHERE b.tutor_name = '$tName'";
        } else {
            echo json_encode(["status"=>"success", "data"=>[]]); exit;
        }
    }

    $res = $conn->query($sql);
    if($res) {
        while($row = $res->fetch_assoc()) {
            if(empty($row['avatar'])) $row['avatar'] = 'default_boy.png';
            $list[] = $row;
        }
    }
    echo json_encode(["status"=>"success", "data"=>$list]);
}

// 2. 获取聊天记录
else if ($action == 'get_history') {
    $me = $_GET['me'];
    $other = $_GET['other'];
    
    if(empty($me) || empty($other)) {
        echo json_encode(["status"=>"success", "data"=>[]]); 
        exit;
    }
    
    // 标记为已读
    $conn->query("UPDATE messages SET is_read=1 WHERE sender_phone='$other' AND receiver_phone='$me'");
    
    // 获取记录
    $sql = "SELECT * FROM messages 
            WHERE (sender_phone='$me' AND receiver_phone='$other') 
               OR (sender_phone='$other' AND receiver_phone='$me') 
            ORDER BY create_time ASC";
            
    $res = $conn->query($sql);
    $msgs = [];
    if($res) while($r = $res->fetch_assoc()) $msgs[] = $r;
    
    echo json_encode(["status"=>"success", "data"=>$msgs]);
}

// 3. 发送消息
else if ($action == 'send_msg') {
    $sender = isset($_POST['sender']) ? $_POST['sender'] : '';
    $receiver = isset($_POST['receiver']) ? $_POST['receiver'] : '';
    $content = isset($_POST['content']) ? trim($_POST['content']) : '';
    
    if (empty($sender) || empty($receiver) || empty($content)) {
        echo json_encode(["status"=>"error", "message"=>"参数不完整"]);
        exit;
    }
    
    $content = $conn->real_escape_string($content);
    
    // 这里的字段名 sender_phone 必须和数据库一致
    $sql = "INSERT INTO messages (sender_phone, receiver_phone, content) VALUES ('$sender', '$receiver', '$content')";
    
    if ($conn->query($sql)) {
        echo json_encode(["status"=>"success"]);
    } else {
        echo json_encode(["status"=>"error", "message"=>"数据库错误: " . $conn->error]);
    }
}

$conn->close();
?>