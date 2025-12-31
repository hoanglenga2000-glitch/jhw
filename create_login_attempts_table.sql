-- 创建防爆破登录尝试记录表
-- 用于记录登录失败次数，实现IP封禁机制

CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY COMMENT '记录ID',
    `ip_address` VARCHAR(45) NOT NULL COMMENT '客户端IP地址（支持IPv6）',
    `phone` VARCHAR(11) NOT NULL COMMENT '尝试登录的手机号',
    `attempt_time` DATETIME NOT NULL COMMENT '尝试时间',
    INDEX `idx_ip_time` (`ip_address`, `attempt_time`),
    INDEX `idx_phone` (`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='登录尝试记录表';

-- 添加自动清理过期记录的事件（可选）
-- 需要确保MySQL的事件调度器已开启：SET GLOBAL event_scheduler = ON;

DELIMITER $$

CREATE EVENT IF NOT EXISTS `cleanup_old_login_attempts`
ON SCHEDULE EVERY 1 HOUR
DO
BEGIN
    -- 删除24小时前的记录
    DELETE FROM `login_attempts` 
    WHERE `attempt_time` < DATE_SUB(NOW(), INTERVAL 24 HOUR);
END$$

DELIMITER ;

