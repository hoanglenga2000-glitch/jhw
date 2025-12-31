<?php
/**
 * 学生注册API - 安全加固版
 * 功能：数据清洗、XSS防护、SQL注入防护、统一JSON响应
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

require_once '../config/db.php';

// ====== 统一JSON响应函数 ======
function sendJsonResponse($status, $message, $data = null, $httpCode = 200) {
    http_response_code($httpCode);
    $response = [
        'status' => $status,
        'message' => $message,
        'data' => $data,
        'timestamp' => time()
    ];
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// ====== 数据清洗函数 ======
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// ====== 主逻辑 ======
try {
    // 获取并清洗输入数据
    $phone = isset($_POST['phone']) ? sanitizeInput($_POST['phone']) : '';
    $password = isset($_POST['password']) ? sanitizeInput($_POST['password']) : '';
    $username = isset($_POST['username']) ? sanitizeInput($_POST['username']) : '';
    
    // 验证必填字段
    if (empty($phone) || empty($password) || empty($username)) {
        sendJsonResponse('error', '请填写完整信息（姓名、手机、密码）', null, 400);
    }
    
    // 验证手机号格式
    if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
        sendJsonResponse('error', '手机号格式不正确', null, 400);
    }
    
    // 验证密码强度
    if (strlen($password) < 6) {
        sendJsonResponse('error', '密码至少需要6位字符', null, 400);
    }
    
    // 验证用户名长度
    if (mb_strlen($username, 'UTF-8') < 2 || mb_strlen($username, 'UTF-8') > 20) {
        sendJsonResponse('error', '用户名长度应在2-20个字符之间', null, 400);
    }
    
    // 检查手机号是否已注册（使用预处理语句）
    $checkStmt = $conn->prepare("SELECT id FROM users WHERE phone = ? LIMIT 1");
    $checkStmt->bind_param("s", $phone);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows > 0) {
        $checkStmt->close();
        sendJsonResponse('error', '该手机号已被注册，请直接登录', null, 409);
    }
    $checkStmt->close();
    
    // 插入新用户（使用预处理语句防止SQL注入）
    $insertStmt = $conn->prepare("INSERT INTO users (username, password, phone, created_at) VALUES (?, ?, ?, NOW())");
    $insertStmt->bind_param("sss", $username, $password, $phone);
    
    if ($insertStmt->execute()) {
        $userId = $insertStmt->insert_id;
        $insertStmt->close();
        
        sendJsonResponse('success', '注册成功！请登录', [
            'user_id' => $userId,
            'phone' => $phone,
            'username' => $username
        ], 201);
    } else {
        $insertStmt->close();
        throw new Exception('Database insertion failed');
    }
    
} catch (Exception $e) {
    error_log('Registration error: ' . $e->getMessage());
    sendJsonResponse('error', '系统错误，请稍后重试', null, 500);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
