<?php
// api/get_tutor_detail.php - 升级版：支持获取忙碌时段
/**
 * 获取教员详情API - 安全加固版 (遵循.cursorrules)
 * 功能：完整教员信息、评价统计、预处理语句
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

require_once '../config/db.php';

// ====== 统一响应函数 ======
function sendResponse($status, $message, $data = null) {
    // 清除所有之前的输出
    if (ob_get_level() > 0) ob_clean();
    
    echo json_encode([
        'status' => $status,
        'message' => $message,
        'data' => $data,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    
    if ($id <= 0) {
        sendResponse('error', '参数错误：缺少有效的教员ID', null);
    }

    // ====== 使用预处理语句查询教员信息 ======
    $stmt = $conn->prepare("
        SELECT 
            id, name, phone, school, major, subject, price, rating,
            avatar, intro, honors, is_vip, vip_expire_time, 
            status, create_time, gender
        FROM tutors 
        WHERE id = ? AND status = '已通过' AND is_banned = 0
        LIMIT 1
    ");
    
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $stmt->close();
        sendResponse('error', '教员不存在或已下架', null);
    }
    
    $tutor = $result->fetch_assoc();
    $stmt->close();

    // ====== 处理头像路径 ======
    $avatar = $tutor['avatar'];
    if (empty($avatar)) {
        $avatar = 'assets/default_boy.png';
    } elseif (!preg_match('/^(http|uploads\/|assets\/)/', $avatar)) {
        $avatar = 'assets/' . $avatar;
    }

    // ====== 检查 VIP 状态 ======
    $isVipActive = ($tutor['is_vip'] == 1 && strtotime($tutor['vip_expire_time']) > time());

    // ====== 构建标签数组 ======
    $tags = [];
    if (!empty($tutor['school'])) $tags[] = $tutor['school'];
    if (!empty($tutor['major'])) $tags[] = $tutor['major'];
    if (!empty($tutor['subject'])) {
        $subjects = preg_split('/[,，、\s]+/', $tutor['subject']);
        foreach ($subjects as $sub) {
            if (!empty(trim($sub))) $tags[] = trim($sub);
        }
    }

    // ====== 获取评价统计 ======
    $reviewStmt = $conn->prepare("
        SELECT 
            COUNT(*) as review_count,
            AVG(rating) as avg_rating,
            SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as five_star_count
        FROM reviews 
        WHERE tutor_id = ?
    ");
    $reviewStmt->bind_param("i", $id);
    $reviewStmt->execute();
    $reviewStats = $reviewStmt->get_result()->fetch_assoc();
    $reviewStmt->close();

    // ====== 获取最近5条评价 ======
    $recentReviewsStmt = $conn->prepare("
        SELECT 
            r.rating, r.content, r.create_time,
            CONCAT(LEFT(r.user_phone, 3), '****', RIGHT(r.user_phone, 4)) as user_phone_masked
        FROM reviews r
        WHERE r.tutor_id = ?
        ORDER BY r.create_time DESC
        LIMIT 5
    ");
    $recentReviewsStmt->bind_param("i", $id);
    $recentReviewsStmt->execute();
    $recentReviewsResult = $recentReviewsStmt->get_result();
    $recentReviews = [];
    while ($review = $recentReviewsResult->fetch_assoc()) {
        $recentReviews[] = $review;
    }
    $recentReviewsStmt->close();

    // ====== 获取已完成订单数 ======
    $orderStmt = $conn->prepare("
        SELECT COUNT(*) as completed_count 
        FROM bookings 
        WHERE tutor_name = ? AND status IN ('已完成', '已支付')
    ");
    $orderStmt->bind_param("s", $tutor['name']);
    $orderStmt->execute();
    $orderStats = $orderStmt->get_result()->fetch_assoc();
    $orderStmt->close();

    // ====== 获取忙碌时段（未来30天内已预约的时间） ======
    $busyTimesStmt = $conn->prepare("
        SELECT lesson_time 
        FROM bookings 
        WHERE tutor_name = ? 
        AND status NOT IN ('已拒绝', '已取消', '退款中')
        AND lesson_time > NOW()
        AND lesson_time < DATE_ADD(NOW(), INTERVAL 30 DAY)
        ORDER BY lesson_time ASC
    ");
    $busyTimesStmt->bind_param("s", $tutor['name']);
    $busyTimesStmt->execute();
    $busyTimesResult = $busyTimesStmt->get_result();
    $busySlots = [];
    while ($row = $busyTimesResult->fetch_assoc()) {
        $busySlots[] = $row['lesson_time'];
    }
    $busyTimesStmt->close();

    // ====== 获取导师勋章 ======
    $completedCount = intval($orderStats['completed_count'] ?: 0);
    $badges = [];
    if ($completedCount >= 100) {
        $badges[] = ['id' => 'golden_tutor', 'name' => '金牌讲师', 'icon' => '👑', 'color' => '#FFD700'];
    }
    if ($completedCount >= 50) {
        $badges[] = ['id' => 'star_tutor', 'name' => '明星导师', 'icon' => '🌟', 'color' => '#F59E0B'];
    }
    if ($isVipActive) {
        $badges[] = ['id' => 'vip', 'name' => 'VIP认证', 'icon' => '💎', 'color' => '#A855F7'];
    }
    if ($reviewStats['review_count'] >= 20 && floatval($reviewStats['avg_rating']) >= 4.8) {
        $badges[] = ['id' => 'excellent', 'name' => '优质导师', 'icon' => '⭐', 'color' => '#10B981'];
    }

    // ====== 组装返回数据 ======
    $responseData = [
        'id' => intval($tutor['id']),
        'name' => $tutor['name'],
        'gender' => $tutor['gender'] ?: '未知',
        'school' => $tutor['school'],
        'major' => $tutor['major'],
        'subject' => $tutor['subject'],
        'price' => floatval($tutor['price']),
        'rating' => floatval($tutor['rating'] ?: 5.0),
        'avatar' => $avatar,
        'avatar_hd' => str_replace('.png', '_hd.png', $avatar),
        'intro' => $tutor['intro'] ?: '这位老师很低调，暂时没有填写详细介绍。',
        'honors' => $tutor['honors'] ?: '暂无公开的成功案例。',
        'is_vip' => $isVipActive ? 1 : 0,
        'tags' => array_unique($tags),
        'stats' => [
            'review_count' => intval($reviewStats['review_count'] ?: 0),
            'avg_rating' => round(floatval($reviewStats['avg_rating'] ?: 5.0), 1),
            'five_star_rate' => $reviewStats['review_count'] > 0 
                ? round(($reviewStats['five_star_count'] / $reviewStats['review_count']) * 100) 
                : 100,
            'completed_orders' => intval($orderStats['completed_count'] ?: 0),
            'teaching_hours' => intval($orderStats['completed_count'] ?: 0) * 2, // 假设平均每单2小时
            'teaching_years' => max(1, floor((time() - strtotime($tutor['create_time'])) / (365 * 24 * 3600)))
        ],
        'recent_reviews' => $recentReviews,
        'busy_slots' => $busySlots, // 忙碌时段数组
        'badges' => $badges, // 勋章数组
        'created_at' => $tutor['create_time']
    ];

    sendResponse('success', '获取成功', $responseData);

} catch (Exception $e) {
    error_log('get_tutor_detail error: ' . $e->getMessage());
    sendResponse('error', '服务器错误，请稍后重试', null);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
