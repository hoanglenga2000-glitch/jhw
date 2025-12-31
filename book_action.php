<?php
// api/book_action.php - 安全加固版：支持保存时间和备注（使用预处理语句）
header('Content-Type: application/json');
require '../config/db.php';

/**
 * 安全的数据清洗
 */
function sanitize($conn, $data) {
    return htmlspecialchars($conn->real_escape_string(trim($data)), ENT_QUOTES, 'UTF-8');
}

try {
    $phone = isset($_POST['phone']) ? sanitize($conn, $_POST['phone']) : '';
    $tutor_name = isset($_POST['tutor_name']) ? sanitize($conn, $_POST['tutor_name']) : '';
    $lesson_time = isset($_POST['lesson_time']) ? sanitize($conn, $_POST['lesson_time']) : '协商';
    $requirement = isset($_POST['requirement']) ? sanitize($conn, $_POST['requirement']) : '无';

    if(empty($phone) || empty($tutor_name)) {
        echo json_encode(["status" => "error", "message" => "信息不完整"]);
        exit;
    }

    // 使用预处理语句检查重复
    $stmt = $conn->prepare("SELECT id FROM bookings WHERE user_phone = ? AND tutor_name = ? AND status = '待确认' LIMIT 1");
    $stmt->bind_param("ss", $phone, $tutor_name);
    $stmt->execute();
    $result = $stmt->get_result();
    if($result->num_rows > 0) {
        $stmt->close();
        echo json_encode(["status" => "error", "message" => "您已申请过该老师，请勿重复提交"]);
        exit;
    }
    $stmt->close();

    // 使用预处理语句插入数据
    $stmt2 = $conn->prepare("INSERT INTO bookings (user_phone, tutor_name, lesson_time, requirement) VALUES (?, ?, ?, ?)");
    $stmt2->bind_param("ssss", $phone, $tutor_name, $lesson_time, $requirement);

    if ($stmt2->execute()) {
        echo json_encode(["status" => "success", "message" => "预约申请已提交"]);
    } else {
        echo json_encode(["status" => "error", "message" => "系统错误，请稍后重试"]);
    }
    $stmt2->close();
    
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => "系统错误，请稍后重试"]);
} finally {
    $conn->close();
}
?>