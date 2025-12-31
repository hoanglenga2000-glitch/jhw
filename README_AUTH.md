# 🔐 认证模块重构文档

## 概述
本次重构将认证模块升级为 **Linear/Stripe 风格**，实现了商业级的安全性和用户体验。

---

## ✨ 前端升级

### 1. join.html - 分步式教员入驻表单

#### 特性
- **多步骤表单**：将复杂表单拆分为3个步骤
  - 步骤1：基本信息（姓名、手机、密码）
  - 步骤2：学历背景（学校、专业、科目）
  - 步骤3：身份验证（证件上传）
  
- **密码强度指示器**
  - 实时显示密码强度（弱/中/强）
  - 红-黄-绿三色进度条
  - 要求至少8位，包含字母和数字
  
- **表单验证**
  - 每一步都有独立验证
  - 手机号格式验证（1[3-9]\d{9}）
  - 实时错误提示
  
- **动画效果**
  - 步骤滑入/滑出动画
  - 进度点指示器
  - 成功提交动画

#### 视觉设计
- 深色玻璃拟态背景（Dark Glassmorphism）
- 动态光晕背景
- 输入框 Glow 聚焦效果
- Linear/Stripe 风格按钮

---

### 2. teacher_login.html - 教员登录页面

#### 特性
- **防爆破前端限制**
  - 记录登录失败次数
  - 达到5次后前端禁止继续尝试
  
- **实时错误提示**
  - 错误横幅动画
  - 清晰的错误信息
  
- **表单验证**
  - 手机号格式验证
  - 必填字段验证
  - 回车键登录

#### 视觉设计
- 统一的 Linear 风格
- 玻璃拟态卡片
- 输入框聚焦 Glow 效果
- 平滑的过渡动画

---

## 🛡️ 后端安全加固

### 1. api/login.php - 登录API

#### 安全特性

##### 防爆破机制
```php
// 同一IP连续失败5次，锁定15分钟
function checkLoginAttempts($conn, $ip) {
    $lockDuration = 15 * 60; // 15分钟
    $maxAttempts = 5;
    // ...
}
```

##### 数据清洗
```php
function sanitizeInput($data) {
    $data = trim($data);                              // 去除首尾空格
    $data = stripslashes($data);                      // 移除反斜杠
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8'); // 转义HTML特殊字符
    return $data;
}
```

##### SQL注入防护
```php
// 使用预处理语句
$stmt = $conn->prepare("SELECT * FROM users WHERE phone = ? LIMIT 1");
$stmt->bind_param("s", $phone);
```

##### 统一JSON响应
```json
{
    "status": "success|error",
    "message": "操作结果描述",
    "data": { ... },
    "timestamp": 1234567890
}
```

##### HTTP状态码
- 200: 成功
- 400: 请求错误（参数缺失/格式错误）
- 401: 认证失败（密码错误）
- 403: 禁止访问（账号封禁）
- 429: 请求过多（触发防爆破）
- 500: 服务器错误

---

### 2. api/register.php - 注册API

#### 安全特性

##### 输入验证
- 手机号格式：`/^1[3-9]\d{9}$/`
- 密码强度：至少6位
- 用户名长度：2-20个字符

##### 数据清洗
- XSS防护：`htmlspecialchars`
- SQL注入防护：预处理语句
- 去除多余空格和特殊字符

##### 防重复注册
```php
$checkStmt = $conn->prepare("SELECT id FROM users WHERE phone = ? LIMIT 1");
```

---

## 📊 数据库表结构

### login_attempts 表（新增）

```sql
CREATE TABLE `login_attempts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `ip_address` VARCHAR(45) NOT NULL,
    `phone` VARCHAR(11) NOT NULL,
    `attempt_time` DATETIME NOT NULL,
    INDEX `idx_ip_time` (`ip_address`, `attempt_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### 使用方法
1. 导入SQL文件创建表：
   ```bash
   mysql -u root -p your_database < config/create_login_attempts_table.sql
   ```

2. 或在phpMyAdmin中执行SQL语句

---

## 🎨 设计系统

### 颜色变量
```css
:root {
    --primary: #5E6AD2;        /* 主色 */
    --primary-hover: #4C5FD5;  /* 悬停态 */
    --success: #00D084;        /* 成功 */
    --warning: #F5A623;        /* 警告 */
    --danger: #F04438;         /* 危险 */
    --bg-dark: #0A0A0A;        /* 深色背景 */
    --glass: rgba(18, 18, 18, 0.85); /* 玻璃效果 */
    --text: #FAFAFA;           /* 文本色 */
    --text-gray: #A1A1AA;      /* 次要文本 */
}
```

### 动画
- `slideUp`: 卡片弹出动画
- `fadeSlide`: 步骤切换动画
- `orbFloat`: 背景光晕浮动
- `successPop`: 成功图标弹出

---

## 🚀 部署注意事项

### 1. PHP环境要求
- PHP >= 7.0
- MySQLi 扩展
- JSON 扩展

### 2. 数据库配置
确保 `config/db.php` 配置正确：
```php
$conn = new mysqli($host, $user, $pass, $dbname);
```

### 3. 文件权限
- `api/` 目录需要可执行权限
- `uploads/` 目录需要写入权限

### 4. 安全建议
- 生产环境使用 `password_hash()` 加密密码
- 启用 HTTPS
- 配置 CORS 策略
- 定期清理 `login_attempts` 表

---

## 📱 测试流程

### 测试登录防爆破
1. 使用错误密码连续登录6次
2. 第6次应显示"登录失败次数过多"
3. 15分钟后解锁

### 测试注册表单
1. 填写不符合规则的密码（< 8位）
2. 观察密码强度指示器
3. 尝试跳过步骤验证
4. 测试上传功能

### 测试数据清洗
1. 输入包含 `<script>` 标签的内容
2. 检查数据库中是否被转义
3. 输入 SQL 注入代码如 `' OR '1'='1`
4. 确认被预处理语句阻止

---

## 🎯 性能优化

### 前端
- 使用 CSS 动画代替 JS 动画
- 合理的过渡时间（0.2-0.5s）
- 懒加载背景图片

### 后端
- 使用索引加速查询
- 定期清理过期记录
- 连接池复用

---

## 📝 更新日志

### v2.0.0 (2025-01-30)
- ✨ 重构为 Linear/Stripe 风格
- 🔒 添加防爆破机制
- 🛡️ 加固数据清洗
- 📊 统一 JSON 响应格式
- 🎨 实现分步式表单
- 💪 添加密码强度检查
- ⚡ 优化动画性能

---

## 🤝 贡献指南

如需修改认证逻辑：
1. 修改前端验证规则
2. 同步修改后端验证
3. 更新测试用例
4. 更新本文档

---

## 📞 技术支持

如有问题，请查看：
- 浏览器控制台错误
- PHP 错误日志
- MySQL 慢查询日志

---

**祝您使用愉快！** 🎉

