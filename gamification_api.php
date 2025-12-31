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
    $phone = isset($_POST['phone']) ? $_POST['phone'] : '';
    if (empty($phone)) {
        echo json_encode(["status"=>"error", "message"=>"手机号不能为空"]);
        exit;
    }
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

// ==================== 5. 获取勋章/成就列表 ====================
else if ($action == 'get_badges') {
    $phone = $_GET['phone'];
    $role = isset($_GET['role']) ? $_GET['role'] : 'student';
    
    // 获取用户数据
    if ($role === 'student') {
        $user = $conn->query("SELECT points FROM users WHERE phone='$phone'")->fetch_assoc();
        $points = $user ? intval($user['points']) : 0;
        
        // 获取签到天数
        $signinCount = $conn->query("SELECT COUNT(DISTINCT signin_date) as c FROM signins WHERE user_phone='$phone'")->fetch_assoc()['c'];
        
        // 获取已完成课程数
        $completedCourses = $conn->query("SELECT COUNT(*) as c FROM bookings WHERE user_phone='$phone' AND status IN ('已完成', '待评价')")->fetch_assoc()['c'];
    } else {
        $tutor = $conn->query("SELECT id, price FROM tutors WHERE phone='$phone'")->fetch_assoc();
        if (!$tutor) {
            echo json_encode(["status" => "error", "message" => "教员不存在"]);
            exit;
        }
        $tutorId = intval($tutor['id']);
        $completedRes = $conn->query("SELECT COUNT(*) as c FROM bookings WHERE tutor_id=$tutorId AND status IN ('已完成', '待评价')");
        $completedCourses = $completedRes ? intval($completedRes->fetch_assoc()['c']) : 0;
        $points = 0; // 教员暂时不使用积分系统
        $signinCount = 0; // 教员暂不使用签到系统
    }
    
    // 定义所有勋章
    $allBadges = [
        ['id' => 'first_signin', 'name' => '初次见面', 'icon' => '👋', 'desc' => '完成首次签到', 'unlocked' => $signinCount > 0, 'category' => '签到'],
        ['id' => 'week_warrior', 'name' => '周战士', 'icon' => '⚔️', 'desc' => '连续签到7天', 'unlocked' => $signinCount >= 7, 'category' => '签到'],
        ['id' => 'month_master', 'name' => '月度大师', 'icon' => '📅', 'desc' => '连续签到30天', 'unlocked' => $signinCount >= 30, 'category' => '签到'],
        ['id' => 'hundred_days', 'name' => '百日签到', 'icon' => '💯', 'desc' => '累计签到100天', 'unlocked' => $signinCount >= 100, 'category' => '签到'],
        ['id' => 'beginner', 'name' => '初学者', 'icon' => '📚', 'desc' => '完成1门课程', 'unlocked' => $completedCourses >= 1, 'category' => '学习'],
        ['id' => 'learner', 'name' => '勤奋好学', 'icon' => '🎓', 'desc' => '完成10门课程', 'unlocked' => $completedCourses >= 10, 'category' => '学习'],
        ['id' => 'expert', 'name' => '学习专家', 'icon' => '🏆', 'desc' => '完成50门课程', 'unlocked' => $completedCourses >= 50, 'category' => '学习'],
        ['id' => 'points_1000', 'name' => '积分达人', 'icon' => '⭐', 'desc' => '累积1000积分', 'unlocked' => $points >= 1000, 'category' => '积分'],
        ['id' => 'golden_tutor', 'name' => '金牌讲师', 'icon' => '👑', 'desc' => '完成100+课程（教员专属）', 'unlocked' => ($role === 'teacher' && $completedCourses >= 100), 'category' => '教学'],
        ['id' => 'star_tutor', 'name' => '明星导师', 'icon' => '🌟', 'desc' => '完成50+课程（教员专属）', 'unlocked' => ($role === 'teacher' && $completedCourses >= 50), 'category' => '教学'],
    ];
    
    // 过滤掉不适用于当前角色的勋章
    if ($role === 'student') {
        $allBadges = array_filter($allBadges, function($badge) {
            return !in_array($badge['id'], ['golden_tutor', 'star_tutor']);
        });
    } else {
        $allBadges = array_filter($allBadges, function($badge) {
            return !in_array($badge['id'], ['points_1000', 'beginner', 'learner', 'expert']);
        });
    }
    
    echo json_encode([
        "status" => "success",
        "badges" => array_values($allBadges),
        "unlocked_count" => count(array_filter($allBadges, function($b) { return $b['unlocked']; }))
    ]);
}

// ==================== 6. 获取积分等级信息 ====================
else if ($action == 'get_level_info') {
    $phone = $_GET['phone'];
    $user = $conn->query("SELECT points FROM users WHERE phone='$phone'")->fetch_assoc();
    $points = $user ? intval($user['points']) : 0;
    
    // 积分等级定义：每500积分一个等级
    $level = floor($points / 500) + 1;
    $currentLevelPoints = ($level - 1) * 500;
    $nextLevelPoints = $level * 500;
    $progress = $points - $currentLevelPoints;
    $needed = $nextLevelPoints - $points;
    $progressPercent = $needed > 0 ? (($progress / ($nextLevelPoints - $currentLevelPoints)) * 100) : 100;
    
    $levelNames = [
        1 => '青铜学员', 2 => '白银学员', 3 => '黄金学员', 
        4 => '白金学员', 5 => '钻石学员', 6 => '大师学员',
        7 => '传奇学员', 8 => '至尊学员'
    ];
    
    echo json_encode([
        "status" => "success",
        "level" => $level,
        "level_name" => $levelNames[$level] ?? "等级 $level",
        "points" => $points,
        "current_level_points" => $currentLevelPoints,
        "next_level_points" => $nextLevelPoints,
        "progress" => $progress,
        "needed" => $needed,
        "progress_percent" => min(100, max(0, $progressPercent))
    ]);
}

// ==================== 7. 获取每周明星导师排行榜 ====================
else if ($action == 'get_leaderboard') {
    $type = isset($_GET['type']) ? $_GET['type'] : 'weekly'; // weekly or monthly
    
    // 计算时间范围
    $startDate = $type === 'weekly' 
        ? date('Y-m-d', strtotime('monday this week'))
        : date('Y-m-01');
    
    // 获取本周/本月完成课程最多的导师
    $sql = "SELECT 
                t.id, t.name, t.avatar, t.school, t.price, t.is_vip,
                COUNT(b.id) as course_count,
                AVG(r.rating) as avg_rating
            FROM tutors t
            LEFT JOIN bookings b ON t.id = b.tutor_id 
                AND b.status IN ('已完成', '待评价')
                AND b.create_time >= '$startDate'
            LEFT JOIN reviews r ON t.id = r.tutor_id
            WHERE t.status = '已通过' AND t.is_banned = 0
            GROUP BY t.id
            ORDER BY course_count DESC, avg_rating DESC
            LIMIT 10";
    
    $res = $conn->query($sql);
    $list = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $avatar = $r['avatar'] ?: 'default_boy.png';
            if (!strpos($avatar, '/') && !strpos($avatar, 'http')) {
                $avatar = 'assets/' . $avatar;
            }
            $list[] = [
                'id' => $r['id'],
                'name' => $r['name'],
                'avatar' => $avatar,
                'school' => $r['school'],
                'price' => $r['price'],
                'is_vip' => $r['is_vip'],
                'course_count' => intval($r['course_count']),
                'avg_rating' => $r['avg_rating'] ? round(floatval($r['avg_rating']), 1) : 5.0
            ];
        }
    }
    
    echo json_encode([
        "status" => "success",
        "type" => $type,
        "data" => $list
    ]);
}

$conn->close();
?>