-- 留言风险分级功能迁移脚本
-- 执行此 SQL 来添加风险分级所需的字段与分级规则版本表
--
-- 设计说明：
--   1. messages 表新增风险分级相关字段，级别由「类型基础分 + 重复发布次数
--      + 历史处置结果 + 待处理举报数」按规则版本统一计算后落库；
--   2. risk_rule_version 保存每一次分级口径，调整口径后写入新版本，
--      并把存量记录按同一口径重新计算；
--   3. 分级服务不可用（表结构未就绪/计算异常）时保留原级别、置位待重算，
--      审核列表照常可用。

USE `community_board`;

-- 留言表新增访客标识（用于统计同一人的重复发布）
ALTER TABLE `messages`
    ADD COLUMN `visitor_id` VARCHAR(64) DEFAULT NULL COMMENT '发布访客标识（匿名用户）' AFTER `phone`;

-- 留言表新增风险分级字段
ALTER TABLE `messages`
    ADD COLUMN `risk_level` VARCHAR(10) DEFAULT NULL COMMENT '风险级别: low低, medium中, high高（NULL为从未分级）' AFTER `status`,
    ADD COLUMN `risk_score` INT NOT NULL DEFAULT 0 COMMENT '风险分值（按规则版本计算）' AFTER `risk_level`,
    ADD COLUMN `risk_priority` INT NOT NULL DEFAULT 0 COMMENT '处置优先级，数值越大越优先' AFTER `risk_score`,
    ADD COLUMN `risk_labels` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '风险标签，逗号分隔' AFTER `risk_priority`,
    ADD COLUMN `risk_factors` TEXT COMMENT '分级因子快照JSON（重复次数/历史处置等）' AFTER `risk_labels`,
    ADD COLUMN `risk_rule_version` INT UNSIGNED DEFAULT NULL COMMENT '分级时使用的规则版本号' AFTER `risk_factors`,
    ADD COLUMN `risk_calculated_at` DATETIME DEFAULT NULL COMMENT '最近分级时间' AFTER `risk_rule_version`,
    ADD COLUMN `risk_stale` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否待重算: 0否, 1是（含分级失败保留原级别）' AFTER `risk_calculated_at`;

ALTER TABLE `messages`
    ADD INDEX `idx_risk_level` (`risk_level`),
    ADD INDEX `idx_risk_priority` (`risk_priority`),
    ADD INDEX `idx_risk_stale` (`risk_stale`),
    ADD INDEX `idx_visitor_id` (`visitor_id`);

-- 分级规则版本表：处理人员调整口径后追加新版本，并按新版本重算全部记录
CREATE TABLE IF NOT EXISTS `risk_rule_versions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `version` INT UNSIGNED NOT NULL COMMENT '规则版本号，递增',
    `rules_json` TEXT NOT NULL COMMENT '分级口径快照JSON',
    `remark` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '调整说明',
    `created_by` INT UNSIGNED DEFAULT NULL COMMENT '调整人管理员ID',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '生效时间',
    UNIQUE KEY `uk_version` (`version`),
    INDEX `idx_created_at` (`created_at`),
    FOREIGN KEY (`created_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='风险分级规则版本表';

-- 初始规则版本 v1
INSERT INTO `risk_rule_versions` (`version`, `rules_json`, `remark`, `created_by`)
SELECT 1,
'{"base_score":{"help":20,"suggest":10,"lost":5},"repeat_count_thresholds":[{"gte":3,"score":40,"label":"repeat_high"},{"gte":2,"score":20,"label":"repeat"}],"duplicate_title_score":20,"reject_thresholds":[{"gte":3,"score":40,"label":"reject_high"},{"gte":1,"score":25,"label":"reject_history"}],"report_thresholds":[{"gte":2,"score":30,"label":"reported"},{"gte":1,"score":15,"label":"reported"}],"level_thresholds":{"medium":40,"high":70},"priority_base":{"high":100,"medium":50,"low":0}}',
'初始分级口径', NULL
FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM `risk_rule_versions` WHERE `version` = 1);

-- 执行完成后验证：
-- DESCRIBE messages;
-- SHOW TABLES LIKE 'risk_rule_versions';
-- SELECT version, remark, created_at FROM risk_rule_versions ORDER BY version;
