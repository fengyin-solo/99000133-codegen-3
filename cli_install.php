<?php
/**
 * 命令行数据库初始化脚本 - 用于创建表结构
 * 用法: php cli_install.php
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

    // 默认风险分级规则（与 includes/risk.php 中 riskDefaultConfig 保持一致）
    $defaultRuleConfig = '{"baseScore":{"help":10,"suggest":5,"lost":0},"repeatThresholds":[{"count":3,"score":20},{"count":5,"score":40}],"rejectedScore":15,"reportedDeletedScore":30,"historyDays":30,"thresholds":{"medium":20,"high":40},"priorityWeights":{"high":1000,"medium":100,"low":0},"labels":{"low":"低风险","medium":"中风险","high":"高风险"}}';
    $stmt = $pdo->prepare("INSERT IGNORE INTO `risk_rules` (`version`, `config_json`, `is_active`, `remark`) VALUES (1, ?, 1, '系统默认分级规则')");
    $stmt->execute([$defaultRuleConfig]);

    echo "数据库表创建成功！\n";

} catch (PDOException $e) {
    die("安装失败: " . $e->getMessage() . "\n");
}
