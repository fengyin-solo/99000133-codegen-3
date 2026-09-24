-- 留言风险分级功能迁移脚本
-- 执行此 SQL 来添加风险分级所需的表结构
-- 兼容 MySQL >= 5.7

USE `community_board`;

-- 风险分级规则版本表（处理人员调整分级口径时写入新版本，审核结果按同一口径重算）
CREATE TABLE IF NOT EXISTS `risk_rules` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='风险分级规则版本表';

-- 历史处置结果表（留言被删除后仍保留处置口径，作为后续分级的历史依据）
CREATE TABLE IF NOT EXISTS `risk_disposal_history` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='留言历史处置记录表';

-- 留言表增加风险分级字段
ALTER TABLE `messages`
    ADD COLUMN `risk_level` VARCHAR(10) NOT NULL DEFAULT 'low' COMMENT '风险级别: low低, medium中, high高' AFTER `status`,
    ADD COLUMN `risk_priority` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '处置优先级，数值越大越优先' AFTER `risk_level`,
    ADD COLUMN `risk_label` VARCHAR(50) NOT NULL DEFAULT '低风险' COMMENT '风险标签' AFTER `risk_priority`,
    ADD COLUMN `risk_score` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '风险评分' AFTER `risk_label`,
    ADD COLUMN `risk_snapshot_json` TEXT COMMENT '分级因子快照JSON' AFTER `risk_score`,
    ADD COLUMN `risk_rule_version` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '分级时使用的规则版本' AFTER `risk_snapshot_json`,
    ADD COLUMN `risk_graded_at` DATETIME DEFAULT NULL COMMENT '最近分级时间' AFTER `risk_rule_version`,
    ADD COLUMN `risk_stale` TINYINT NOT NULL DEFAULT 1 COMMENT '是否待重算: 0否, 1是（分级服务失败或口径变化时置1）' AFTER `risk_graded_at`,
    ADD INDEX `idx_risk_level` (`risk_level`),
    ADD INDEX `idx_risk_stale` (`risk_stale`),
    ADD INDEX `idx_priority` (`status`, `risk_stale`, `risk_priority`);

-- 写入默认规则版本（与 includes/risk.php 中 riskDefaultConfig 保持一致）
INSERT INTO `risk_rules` (`version`, `config_json`, `is_active`, `remark`, `created_by`)
SELECT 1,
'{"baseScore":{"help":10,"suggest":5,"lost":0},"repeatThresholds":[{"count":3,"score":20},{"count":5,"score":40}],"rejectedScore":15,"reportedDeletedScore":30,"historyDays":30,"thresholds":{"medium":20,"high":40},"priorityWeights":{"high":1000,"medium":100,"low":0},"labels":{"low":"低风险","medium":"中风险","high":"高风险"}}',
1, '系统默认分级规则', NULL
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `risk_rules` WHERE `version` = 1);

-- 已有待审记录标记为待重算（新版本规则上线后按新标准重新排列）
UPDATE `messages` SET `risk_stale` = 1 WHERE `status` = 0;

-- 执行完成后，可以通过以下命令验证：
-- SHOW TABLES LIKE 'risk_rules';
-- SHOW TABLES LIKE 'risk_disposal_history';
-- DESCRIBE messages;
