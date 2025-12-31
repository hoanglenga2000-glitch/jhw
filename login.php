<?php
/**
 * 学生登录API - 安全加固版
 * 功能：防爆破、数据清洗、统一JSON响应
 */

// 清除之前的输出
ob_start();
if (ob_get_level() > 0) ob_clean();

// CORS 头
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

require_once '../config/db.php';

// ====== 1. 防爆破机制 ======
function checkLoginAttempts($conn, $ip) {
    $lockDuration = 15 * 60; // 15分钟
    $maxAttempts = 5;
    
    // 清理过期记录
    $conn->query("DELETE FROM login_attempts WHERE attempt_time < DATE_SUB(NOW(), INTERVAL {$lockDuration} SECOND)");
    
    // 检查当前IP的尝试次数
    $stmt = $conn->prepare("SELECT COUNT(*) as attempts FROM login_attempts WHERE ip_address = ? AND attempt_time > DATE_SUB(NOW(), INTERVAL {$lockDuration} SECOND)");
    $stmt->bind_param("s", $ip);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($result['attempts'] >= $maxAttempts) {
        sendJsonResponse('error', '登录失败次数过多，请15分钟后再试', null, 429);
    }
}

function recordLoginAttempt($conn, $ip, $phone, $success) {
    if (!$success) {
        $stmt = $conn->prepare("INSERT INTO login_attempts (ip_address, phone, attempt_time) VALUES (?, ?, NOW())");
        $stmt->bind_param("ss", $ip, $phone);
        $stmt->execute();
        $stmt->close();
    } else {
        // 登录成功后清除该IP的失败记录
        $stmt = $conn->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
        $stmt->bind_param("s", $ip);
        $stmt->execute();
        $stmt->close();
    }
}

// ====== 2. 统一JSON响应函数 ======
function sendJsonResponse($status, $message, $data = null, $httpCode = 200) {
    // 清除所有之前的输出
    if (ob_get_level() > 0) ob_clean();
    
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

// ====== 3. 数据清洗函数 ======
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

// ====== 主逻辑 ======
try {
    // 获取客户端IP
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    
    // 检查登录尝试次数
    checkLoginAttempts($conn, $clientIp);
    
    // 获取并清洗输入数据
    $phone = isset($_POST['phone']) ? sanitizeInput($_POST['phone']) : '';
    $password = isset($_POST['password']) ? sanitizeInput($_POST['password']) : '';
    
    // 验证必填字段
    if (empty($phone) || empty($password)) {
        recordLoginAttempt($conn, $clientIp, $phone, false);
        sendJsonResponse('error', '手机号和密码不能为空', null, 400);
    }
    
    // 验证手机号格式
    if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
        recordLoginAttempt($conn, $clientIp, $phone, false);
        sendJsonResponse('error', '手机号格式不正确', null, 400);
    }
    
    // 使用预处理语句查询用户
    $stmt = $conn->prepare("SELECT id, username, password, is_banned, balance FROM users WHERE phone = ? LIMIT 1");
    $stmt->bind_param("s", $phone);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($row = $result->fetch_assoc()) {
        // 检查账号是否被封禁
        if ($row['is_banned'] == 1) {
            $stmt->close();
            recordLoginAttempt($conn, $clientIp, $phone, false);
            sendJsonResponse('error', '该账号已被管理员封禁，请联系客服', null, 403);
        }
        
        // 验证密码（实际项目应使用password_verify）
        if ($row['password'] === $password) {
            $stmt->close();
            
            // 记录登录成功
            recordLoginAttempt($conn, $clientIp, $phone, true);
            
            // 更新最后登录时间
            $updateStmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
            $updateStmt->bind_param("i", $row['id']);
            $updateStmt->execute();
            $updateStmt->close();
            
            // 返回成功响应
            sendJsonResponse('success', '登录成功', [
                'id' => $row['id'],
                'username' => $row['username'],
                'phone' => $phone,
                'balance' => $row['balance']
            ]);
        } else {
            $stmt->close();
            recordLoginAttempt($conn, $clientIp, $phone, false);
            sendJsonResponse('error', '手机号或密码错误', null, 401);
        }
    } else {
        $stmt->close();
        recordLoginAttempt($conn, $clientIp, $phone, false);
        sendJsonResponse('error', '手机号或密码错误', null, 401);
    }
    
} catch (Exception $e) {
    error_log('Login error: ' . $e->getMessage());
    sendJsonResponse('error', '系统错误，请稍后重试', null, 500);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
