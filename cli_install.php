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
        `visitor_id` VARCHAR(64) DEFAULT NULL COMMENT '发布访客标识（匿名用户）',
        `type` ENUM('help','suggest','lost') NOT NULL DEFAULT 'help' COMMENT '类型: help求助, suggest建议, lost失物招领',
        `title` VARCHAR(100) NOT NULL COMMENT '标题',
        `content` TEXT NOT NULL COMMENT '内容',
        `image` VARCHAR(255) DEFAULT NULL COMMENT '图片路径',
        `status` TINYINT NOT NULL DEFAULT 0 COMMENT '状态: 0待审核, 1已通过, 2已拒绝',
        `risk_level` VARCHAR(10) DEFAULT NULL COMMENT '风险级别: low低, medium中, high高（NULL为从未分级）',
        `risk_score` INT NOT NULL DEFAULT 0 COMMENT '风险分值（按规则版本计算）',
        `risk_priority` INT NOT NULL DEFAULT 0 COMMENT '处置优先级，数值越大越优先',
        `risk_labels` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '风险标签，逗号分隔',
        `risk_factors` TEXT COMMENT '分级因子快照JSON（重复次数/历史处置等）',
        `risk_rule_version` INT UNSIGNED DEFAULT NULL COMMENT '分级时使用的规则版本号',
        `risk_calculated_at` DATETIME DEFAULT NULL COMMENT '最近分级时间',
        `risk_stale` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否待重算: 0否, 1是（含分级失败保留原级别）',
        `views` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '浏览量',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_type` (`type`),
        INDEX `idx_status` (`status`),
        INDEX `idx_created` (`created_at`),
        INDEX `idx_risk_level` (`risk_level`),
        INDEX `idx_risk_priority` (`risk_priority`),
        INDEX `idx_risk_stale` (`risk_stale`),
        INDEX `idx_visitor_id` (`visitor_id`)
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
    $pdo->exec("CREATE TABLE IF NOT EXISTS `risk_rule_versions` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `version` INT UNSIGNED NOT NULL COMMENT '规则版本号，递增',
        `rules_json` TEXT NOT NULL COMMENT '分级口径快照JSON',
        `remark` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '调整说明',
        `created_by` INT UNSIGNED DEFAULT NULL COMMENT '调整人管理员ID',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '生效时间',
        UNIQUE KEY `uk_version` (`version`),
        INDEX `idx_created_at` (`created_at`),
        FOREIGN KEY (`created_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='风险分级规则版本表'");

    // 初始风险分级口径 v1
    $defaultRiskRules = '{"base_score":{"help":20,"suggest":10,"lost":5},"repeat_count_thresholds":[{"gte":3,"score":40,"label":"repeat_high"},{"gte":2,"score":20,"label":"repeat"}],"duplicate_title_score":20,"reject_thresholds":[{"gte":3,"score":40,"label":"reject_high"},{"gte":1,"score":25,"label":"reject_history"}],"report_thresholds":[{"gte":2,"score":30,"label":"reported"},{"gte":1,"score":15,"label":"reported"}],"level_thresholds":{"medium":40,"high":70},"priority_base":{"high":100,"medium":50,"low":0}}';
    $stmt = $pdo->prepare("INSERT IGNORE INTO `risk_rule_versions` (`version`, `rules_json`, `remark`) VALUES (1, ?, '初始分级口径')");
    $stmt->execute([$defaultRiskRules]);

    echo "数据库表创建成功！\n";

} catch (PDOException $e) {
    die("安装失败: " . $e->getMessage() . "\n");
}
