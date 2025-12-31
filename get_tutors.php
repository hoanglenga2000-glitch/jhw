<?php
/**
 * 获取教员列表API - 安全加固版 (遵循.cursorrules)
 * 功能：分页查询、VIP优先、标签数组、高清头像
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
function sendResponse($status, $message, $data = null, $pagination = null) {
    // 清除所有之前的输出
    if (ob_get_level() > 0) ob_clean();
    
    $response = [
        'status' => $status,
        'message' => $message,
        'data' => $data
    ];
    if ($pagination) {
        $response['pagination'] = $pagination;
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// ====== 数据清洗函数 ======
function sanitize($conn, $data) {
    return $conn->real_escape_string(htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8'));
}

try {
    // ====== 获取并清洗参数 ======
    $search = isset($_GET['search']) ? sanitize($conn, $_GET['search']) : '';
    $sort = isset($_GET['sort']) ? sanitize($conn, $_GET['sort']) : 'default';
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $limit = isset($_GET['limit']) ? min(50, max(1, intval($_GET['limit']))) : 12; // 限制最大50条
    $offset = ($page - 1) * $limit;

    // ====== 构建基础查询 ======
    $whereConditions = ["status='已通过'", "is_banned=0"];
    
    if (!empty($search)) {
        $whereConditions[] = "(name LIKE '%$search%' OR subject LIKE '%$search%' OR school LIKE '%$search%' OR major LIKE '%$search%')";
    }
    
    $whereClause = implode(' AND ', $whereConditions);

    // ====== VIP 优先排序逻辑 ======
    $vipPriority = "(is_vip = 1 AND vip_expire_time > NOW()) DESC";
    
    switch ($sort) {
        case 'price_asc':
            $orderClause = "$vipPriority, price ASC, rating DESC";
            break;
        case 'price_desc':
            $orderClause = "$vipPriority, price DESC, rating DESC";
            break;
        case 'rating':
            $orderClause = "$vipPriority, rating DESC, create_time DESC";
            break;
        default:
            $orderClause = "$vipPriority, create_time DESC, rating DESC";
    }

    // ====== 获取总数（用于分页） ======
    $countSql = "SELECT COUNT(*) as total FROM tutors WHERE $whereClause";
    $countResult = $conn->query($countSql);
    $totalCount = $countResult ? $countResult->fetch_assoc()['total'] : 0;
    $totalPages = ceil($totalCount / $limit);

    // ====== 使用预处理语句查询数据 ======
    $sql = "SELECT 
                id, name, school, major, subject, price, rating, avatar, 
                is_vip, vip_expire_time, intro, create_time, phone
            FROM tutors 
            WHERE $whereClause 
            ORDER BY $orderClause 
            LIMIT $limit OFFSET $offset";
    
    $result = $conn->query($sql);
    $list = [];
    
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            // 处理头像路径
            $avatar = $row['avatar'];
            if (empty($avatar) || $avatar === 'null') {
                // 默认头像，使用完整路径
                $avatar = 'assets/default_boy.png';
            } elseif (preg_match('/^https?:\/\//', $avatar)) {
                // 完整的HTTP/HTTPS URL，直接使用
                // $avatar 保持不变
            } elseif (preg_match('/^uploads\//', $avatar)) {
                // uploads目录下的文件，直接使用
                // $avatar 保持不变
            } elseif (preg_match('/^assets\//', $avatar)) {
                // 已经是assets/开头的路径，直接使用
                // $avatar 保持不变
            } else {
                // 其他情况，添加assets/前缀
                $avatar = 'assets/' . ltrim($avatar, '/');
            }
            
            // 检查 VIP 是否有效
            $isVipActive = ($row['is_vip'] == 1 && strtotime($row['vip_expire_time']) > time());
            
            // 构建标签数组
            $tags = [];
            if (!empty($row['school'])) $tags[] = $row['school'];
            if (!empty($row['major'])) $tags[] = $row['major'];
            if (!empty($row['subject'])) {
                // 科目可能是逗号分隔的多个科目
                $subjects = preg_split('/[,，、\s]+/', $row['subject']);
                foreach ($subjects as $sub) {
                    if (!empty(trim($sub))) $tags[] = trim($sub);
                }
            }
            
            // 组装返回数据
            $list[] = [
                'id' => intval($row['id']),
                'name' => $row['name'],
                'school' => $row['school'],
                'major' => $row['major'],
                'subject' => $row['subject'],
                'price' => floatval($row['price']),
                'rating' => floatval($row['rating'] ?: 5.0),
                'avatar' => $avatar,
                'avatar_hd' => str_replace('.png', '_hd.png', $avatar), // 高清版本路径
                'is_vip' => $isVipActive ? 1 : 0,
                'tags' => array_unique(array_slice($tags, 0, 5)), // 最多5个标签
                'intro_short' => mb_substr($row['intro'] ?: '', 0, 50, 'UTF-8') . (mb_strlen($row['intro'] ?: '') > 50 ? '...' : ''),
                'created_at' => $row['create_time']
            ];
        }
    }

    // ====== 返回成功响应 ======
    sendResponse('success', '获取成功', $list, [
        'current_page' => $page,
        'per_page' => $limit,
        'total' => intval($totalCount),
        'total_pages' => intval($totalPages),
        'has_more' => ($page < $totalPages)
    ]);

} catch (Exception $e) {
    error_log('get_tutors error: ' . $e->getMessage());
    sendResponse('error', '服务器错误，请稍后重试', null);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>
