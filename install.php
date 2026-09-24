<?php
/**
 * 数据库初始化脚本 - 运行一次后删除
 */
$host = 'localhost';
$user = 'root';
$pass = '123456';

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `community_board` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `community_board`");

    // 留言表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `messages` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `nickname` VARCHAR(50) NOT NULL COMMENT '昵称',
        `phone` VARCHAR(20) DEFAULT NULL COMMENT '联系电话',
        `type` ENUM('help','suggest','lost') NOT NULL DEFAULT 'help' COMMENT '类型: help求助, suggest建议, lost失物招领',
        `title` VARCHAR(100) NOT NULL COMMENT '标题',
        `content` TEXT NOT NULL COMMENT '内容',
        `image` VARCHAR(255) DEFAULT NULL COMMENT '图片路径',
        `status` TINYINT NOT NULL DEFAULT 0 COMMENT '状态: 0待审核, 1已通过, 2已拒绝',
        `risk_level` VARCHAR(10) NOT NULL DEFAULT 'low' COMMENT '风险级别: low低, medium中, high高',
        `risk_priority` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '处置优先级，数值越大越优先',
        `risk_label` VARCHAR(50) NOT NULL DEFAULT '低风险' COMMENT '风险标签',
        `risk_score` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '风险评分',
        `risk_snapshot_json` TEXT COMMENT '分级因子快照JSON',
        `risk_rule_version` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '分级时使用的规则版本',
        `risk_graded_at` DATETIME DEFAULT NULL COMMENT '最近分级时间',
        `risk_stale` TINYINT NOT NULL DEFAULT 1 COMMENT '是否待重算: 0否, 1是',
        `views` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '浏览量',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_type` (`type`),
        INDEX `idx_status` (`status`),
        INDEX `idx_risk_level` (`risk_level`),
        INDEX `idx_risk_stale` (`risk_stale`),
        INDEX `idx_priority` (`status`, `risk_stale`, `risk_priority`),
        INDEX `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='留言表'");

    // 管理员表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `admins` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `username` VARCHAR(50) NOT NULL UNIQUE,
        `password` VARCHAR(255) NOT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员表'");

    // 收藏表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `favorites` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `visitor_id` VARCHAR(64) NOT NULL COMMENT '访客唯一标识',
        `message_id` INT UNSIGNED NOT NULL COMMENT '留言ID',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '收藏时间',
        UNIQUE KEY `uk_visitor_message` (`visitor_id`, `message_id`),
        INDEX `idx_visitor_id` (`visitor_id`),
        INDEX `idx_message_id` (`message_id`),
        FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='收藏表'");

    // 举报表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `reports` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `message_id` INT UNSIGNED NOT NULL COMMENT '被举报的留言ID',
        `visitor_id` VARCHAR(64) NOT NULL COMMENT '举报人访客标识',
        `report_type` VARCHAR(50) NOT NULL COMMENT '举报类型: spam垃圾信息, abuse辱骂攻击, illegal违法违规, porn色情低俗, other其他',
        `description` TEXT COMMENT '补充说明',
        `status` TINYINT NOT NULL DEFAULT 0 COMMENT '状态: 0待处理, 1已处理-已删除, 2已处理-已忽略, 3已驳回',
        `processed_by` INT UNSIGNED DEFAULT NULL COMMENT '处理人管理员ID',
        `processed_at` DATETIME DEFAULT NULL COMMENT '处理时间',
        `process_note` VARCHAR(500) DEFAULT NULL COMMENT '处理备注',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '举报时间',
        UNIQUE KEY `uk_visitor_message` (`visitor_id`, `message_id`),
        INDEX `idx_message_id` (`message_id`),
        INDEX `idx_visitor_id` (`visitor_id`),
        INDEX `idx_status` (`status`),
        INDEX `idx_created` (`created_at`),
        FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE,
        FOREIGN KEY (`processed_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='举报表'");

    // 风险分级规则版本表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `risk_rules` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `version` INT UNSIGNED NOT NULL COMMENT '规则版本号，从1开始递增',
        `config_json` TEXT NOT NULL COMMENT '规则配置JSON',
        `is_active` TINYINT NOT NULL DEFAULT 1 COMMENT '是否当前生效: 0否, 1是',
        `remark` VARCHAR(255) DEFAULT NULL COMMENT '调整说明',
        `created_by` INT UNSIGNED DEFAULT NULL COMMENT '调整人管理员ID',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        UNIQUE KEY `uk_version` (`version`),
        INDEX `idx_active` (`is_active`),
        FOREIGN KEY (`created_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='风险分级规则版本表'");

    // 历史处置结果表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `risk_disposal_history` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `identity_key` VARCHAR(64) NOT NULL COMMENT '发布者标识: 优先手机号，无则昵称小写',
        `message_id` INT UNSIGNED DEFAULT NULL COMMENT '关联留言ID（删除后置NULL）',
        `result_type` VARCHAR(20) NOT NULL COMMENT '处置结果: approved通过, rejected拒绝, reported_deleted举报删除',
        `processed_by` INT UNSIGNED DEFAULT NULL COMMENT '处理人管理员ID',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '处置时间',
        INDEX `idx_identity` (`identity_key`),
        INDEX `idx_message` (`message_id`),
        INDEX `idx_result` (`result_type`),
        INDEX `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='留言历史处置记录表'");

    // 插入默认管理员 admin/admin123
    $hash = password_hash('admin123', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT IGNORE INTO `admins` (`username`, `password`) VALUES ('admin', ?)");
    $stmt->execute([$hash]);

    // 插入默认风险分级规则（与 includes/risk.php 中 riskDefaultConfig 保持一致）
    $defaultRuleConfig = '{"baseScore":{"help":10,"suggest":5,"lost":0},"repeatThresholds":[{"count":3,"score":20},{"count":5,"score":40}],"rejectedScore":15,"reportedDeletedScore":30,"historyDays":30,"thresholds":{"medium":20,"high":40},"priorityWeights":{"high":1000,"medium":100,"low":0},"labels":{"low":"低风险","medium":"中风险","high":"高风险"}}';
    $stmt = $pdo->prepare("INSERT IGNORE INTO `risk_rules` (`version`, `config_json`, `is_active`, `remark`) VALUES (1, ?, 1, '系统默认分级规则')");
    $stmt->execute([$defaultRuleConfig]);

    // 插入测试数据
    $testData = [
        ['张大爷', '13800001111', 'help', '楼道灯坏了', '3号楼2单元楼道灯已经坏了一周，晚上出行很不方便，希望能尽快维修。', null, 1],
        ['李阿姨', '13800002222', 'suggest', '建议增加健身器材', '小区广场上没有健身器材，建议物业能增加一些简单的健身设施，方便居民锻炼。', null, 1],
        ['王先生', '13800003333', 'lost', '捡到一只白色小猫', '昨天在小区门口捡到一只白色小猫，有项圈，应该是附近居民养的。联系电话联系我。', null, 1],
        ['赵女士', '13800004444', 'help', '下水道堵塞', '1号楼1单元下水道堵塞严重，污水都漫出来了，影响整栋楼居民生活，急需处理！', null, 1],
        ['孙师傅', '13800005555', 'suggest', '停车位规划建议', '小区停车位紧张，建议物业重新规划停车区域，利用闲置空地增加停车位。', null, 1],
        ['周同学', '13800006666', 'lost', '丢失蓝色书包', '今天下午在小区花园丢失一个蓝色书包，里面有课本和文具，如有拾到请联系我，万分感谢！', null, 1],
    ];

    $stmt = $pdo->prepare("INSERT INTO `messages` (`nickname`, `phone`, `type`, `title`, `content`, `image`, `status`, `views`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($testData as $i => $d) {
        $stmt->execute([$d[0], $d[1], $d[2], $d[3], $d[4], $d[5], $d[6], rand(10, 200)]);
    }

    // 创建上传目录
    if (!is_dir(__DIR__ . '/uploads')) {
        mkdir(__DIR__ . '/uploads', 0755, true);
    }

    echo "<h2>安装成功！</h2>";
    echo "<p>数据库和表已创建完成，测试数据已插入。</p>";
    echo "<p>后台管理账号：<strong>admin</strong> / <strong>admin123</strong></p>";
    echo "<p><a href='index.php'>访问首页</a> | <a href='admin/login.php'>进入后台</a></p>";
    echo "<p style='color:red;'>请删除此安装文件 (install.php) 以确保安全！</p>";

} catch (PDOException $e) {
    die("安装失败: " . $e->getMessage());
}
