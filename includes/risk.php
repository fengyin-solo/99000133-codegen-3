<?php
/**
 * 留言风险分级服务
 *
 * 根据留言类型、重复发布次数和历史处置结果给出风险标签与处置优先级：
 * - 低风险进入普通处理，高风险进入人工复核队列
 * - 规则/条件/标准变化时，审核结果按同一口径（当前生效规则版本）重算
 * - 分级服务失败时保留原级别并标明待重算(risk_stale=1)，不阻断审核列表
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../config/database.php';

/* ------------------------------- 常量 ------------------------------- */

// 风险级别（级别key本身固定，展示文案可由规则配置覆盖）
const RISK_LOW = 'low';
const RISK_MEDIUM = 'medium';
const RISK_HIGH = 'high';

/**
 * 默认分级口径
 * - baseScore: 留言类型基础分
 * - repeatThresholds: 窗口内同身份发布次数达到 count 时累加 score（取达到的最高档）
 * - rejectedScore / reportedDeletedScore: 历史处置结果加分
 * - historyDays: 重复发布与历史处置统计窗口（天）
 * - thresholds: 级别分数线 medium <= score < high 为中风险
 * - priorityWeights: 处置优先级权重，priority = 权重 + 分数（同级别内高分靠前）
 */
function riskDefaultConfig() {
    return [
        'baseScore' => ['help' => 10, 'suggest' => 5, 'lost' => 0],
        'repeatThresholds' => [
            ['count' => 3, 'score' => 20],
            ['count' => 5, 'score' => 40],
        ],
        'rejectedScore' => 15,
        'reportedDeletedScore' => 30,
        'historyDays' => 30,
        'thresholds' => ['medium' => 20, 'high' => 40],
        'priorityWeights' => ['high' => 1000, 'medium' => 100, 'low' => 0],
        'labels' => ['low' => '低风险', 'medium' => '中风险', 'high' => '高风险'],
    ];
}

/**
 * 级别固定展示文案（配置文案缺失时兜底，保证不同查看条件下口径一致）
 */
function riskLevelLabel($level) {
    $map = [RISK_LOW => '低风险', RISK_MEDIUM => '中风险', RISK_HIGH => '高风险'];
    return $map[$level] ?? '低风险';
}

/**
 * 高风险进入人工复核队列
 */
function riskIsManualReview($level) {
    return $level === RISK_HIGH;
}

/* --------------------------- 规则版本管理 --------------------------- */

/**
 * 校验并归一化规则配置，非法值回退为默认值，保证分级口径始终可用
 */
function riskNormalizeConfig($config) {
    $default = riskDefaultConfig();
    if (!is_array($config)) return $default;

    $out = $default;

    // 类型基础分：非负整数
    if (isset($config['baseScore']) && is_array($config['baseScore'])) {
        foreach (['help', 'suggest', 'lost'] as $t) {
            if (isset($config['baseScore'][$t]) && is_numeric($config['baseScore'][$t])) {
                $out['baseScore'][$t] = max(0, intval($config['baseScore'][$t]));
            }
        }
    }

    // 重复发布档位：count>=1 升序，score 非负
    if (isset($config['repeatThresholds']) && is_array($config['repeatThresholds'])) {
        $tiers = [];
        foreach ($config['repeatThresholds'] as $tier) {
            if (is_array($tier) && isset($tier['count'], $tier['score'])
                && is_numeric($tier['count']) && is_numeric($tier['score'])
                && intval($tier['count']) >= 1) {
                $tiers[] = ['count' => intval($tier['count']), 'score' => max(0, intval($tier['score']))];
            }
        }
        usort($tiers, function ($a, $b) { return $a['count'] <=> $b['count']; });
        if (!empty($tiers)) $out['repeatThresholds'] = $tiers;
    }

    foreach (['rejectedScore', 'reportedDeletedScore'] as $k) {
        if (isset($config[$k]) && is_numeric($config[$k])) {
            $out[$k] = max(0, intval($config[$k]));
        }
    }
    if (isset($config['historyDays']) && is_numeric($config['historyDays']) && intval($config['historyDays']) >= 1) {
        $out['historyDays'] = intval($config['historyDays']);
    }
    if (isset($config['thresholds']) && is_array($config['thresholds'])
        && is_numeric($config['thresholds']['medium'] ?? null)
        && is_numeric($config['thresholds']['high'] ?? null)) {
        $medium = max(1, intval($config['thresholds']['medium']));
        $high = max($medium + 1, intval($config['thresholds']['high']));
        $out['thresholds'] = ['medium' => $medium, 'high' => $high];
    }
    if (isset($config['priorityWeights']) && is_array($config['priorityWeights'])) {
        foreach (['high', 'medium', 'low'] as $lv) {
            if (isset($config['priorityWeights'][$lv]) && is_numeric($config['priorityWeights'][$lv])) {
                $out['priorityWeights'][$lv] = intval($config['priorityWeights'][$lv]);
            }
        }
        // 强制保持权重次序，避免优先级倒挂
        if ($out['priorityWeights']['high'] <= $out['priorityWeights']['medium']) {
            $out['priorityWeights']['high'] = $out['priorityWeights']['medium'] + 900;
        }
        if ($out['priorityWeights']['medium'] <= $out['priorityWeights']['low']) {
            $out['priorityWeights']['medium'] = $out['priorityWeights']['low'] + 90;
        }
    }
    if (isset($config['labels']) && is_array($config['labels'])) {
        foreach (['low', 'medium', 'high'] as $lv) {
            if (!empty($config['labels'][$lv]) && is_string($config['labels'][$lv])) {
                $label = trim(mb_substr($config['labels'][$lv], 0, 50));
                if ($label !== '') $out['labels'][$lv] = $label;
            }
        }
    }

    return $out;
}

/**
 * 获取当前生效规则（单次请求内缓存）
 * 返回 ['id'=>int, 'version'=>int, 'config'=>array]
 * 规则表不可用时回退默认口径（version=0），保证审核列表不被阻断
 */
function riskActiveRules($db = null) {
    static $cache = null;
    if ($cache !== null) return $cache;

    $fallback = ['id' => 0, 'version' => 0, 'config' => riskDefaultConfig()];
    if ($db === null) {
        try { $db = getDB(); } catch (Exception $e) { return $cache = $fallback; }
    }
    try {
        $row = $db->query("SELECT id, version, config_json FROM risk_rules WHERE is_active = 1 ORDER BY version DESC LIMIT 1")->fetch();
        if (!$row) return $cache = $fallback;
        $config = json_decode($row['config_json'], true);
        return $cache = [
            'id' => intval($row['id']),
            'version' => intval($row['version']),
            'config' => riskNormalizeConfig($config),
        ];
    } catch (Exception $e) {
        return $cache = $fallback;
    }
}

/**
 * 获取规则版本列表（管理页展示），失败时返回空数组
 */
function riskListRuleVersions($db) {
    try {
        $stmt = $db->query("SELECT r.*, a.username AS admin_name FROM risk_rules r
            LEFT JOIN admins a ON r.created_by = a.id
            ORDER BY r.version DESC");
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/**
 * 保存新规则版本：旧版本失效 -> 写入新版本 -> 全量重算（同一口径）
 * 返回 ['version'=>新版本号, 'regraded'=>重算条数]
 * 调用方需自行处理异常（事务在本函数内完成）
 */
function riskSaveRules($db, array $config, $remark, $adminId) {
    $config = riskNormalizeConfig($config);

    $db->beginTransaction();
    try {
        $row = $db->query("SELECT COALESCE(MAX(version), 0) AS v FROM risk_rules FOR UPDATE")->fetch();
        $version = intval($row['v']) + 1;

        $db->prepare("UPDATE risk_rules SET is_active = 0 WHERE is_active = 1")->execute();
        $stmt = $db->prepare("INSERT INTO risk_rules (version, config_json, is_active, remark, created_by) VALUES (?, ?, 1, ?, ?)");
        $stmt->execute([$version, json_encode($config, JSON_UNESCAPED_UNICODE), $remark ?: null, $adminId]);

        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    // 分级服务/数据库异常不应阻断规则保存，未完成部分以 risk_stale=1 待重算
    $regraded = riskRegradeAll($db, $version, $config);
    return ['version' => $version, 'regraded' => $regraded];
}

/**
 * 重新激活历史版本（在其配置基础上生成新版本，保证版本号只增、口径可追溯）
 */
function riskReactivateRules($db, $versionId, $adminId) {
    $stmt = $db->prepare("SELECT config_json, remark FROM risk_rules WHERE id = ?");
    $stmt->execute([$versionId]);
    $row = $stmt->fetch();
    if (!$row) throw new Exception('规则版本不存在');
    $config = json_decode($row['config_json'], true);
    return riskSaveRules($db, $config, '重新启用历史规则口径', $adminId);
}

/* ----------------------------- 分级计算 ----------------------------- */

/**
 * 发布者标识：优先手机号，无手机号则用昵称（小写归一）
 * 同一发布者的重复发布与历史处置据此统计
 */
function riskIdentityKey($phone, $nickname) {
    $phone = trim((string)$phone);
    if ($phone !== '') return 'p:' . $phone;
    return 'n:' . mb_strtolower(trim((string)$nickname));
}

/**
 * 计算分级因子（任何统计失败都向外抛出，由调用方决定降级策略）
 * 返回: [repeatCount, rejectedCount, reportedDeletedCount, identityKey]
 */
function riskCollectFactors($db, $message, array $config) {
    $identityKey = riskIdentityKey($message['phone'] ?? '', $message['nickname'] ?? '');
    $days = max(1, intval($config['historyDays']));

    // 窗口内同身份发布次数（重复发布次数，含本条及待审/已通过/已拒绝记录）
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM messages
         WHERE (CASE WHEN phone IS NOT NULL AND phone <> '' THEN CONCAT('p:', phone)
                     ELSE CONCAT('n:', LOWER(nickname)) END) = ?
         AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)"
    );
    $stmt->execute([$identityKey]);
    $repeatCount = intval($stmt->fetchColumn());

    // 窗口内同身份历史处置结果：被拒绝次数、举报后删除次数
    $stmt = $db->prepare(
        "SELECT result_type, COUNT(*) AS cnt FROM risk_disposal_history
         WHERE identity_key = ? AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)
         GROUP BY result_type"
    );
    $stmt->execute([$identityKey]);
    $counts = ['rejected' => 0, 'reported_deleted' => 0];
    foreach ($stmt->fetchAll() as $r) {
        if (isset($counts[$r['result_type']])) $counts[$r['result_type']] = intval($r['cnt']);
    }

    return [
        'identityKey' => $identityKey,
        'repeatCount' => $repeatCount,
        'rejectedCount' => $counts['rejected'],
        'reportedDeletedCount' => $counts['reported_deleted'],
    ];
}

/**
 * 按给定规则口径计算级别（纯函数：同类因子 + 同一规则 => 同一级别，与查看条件无关）
 */
function riskEvaluate(array $config, $type, array $factors) {
    // 1. 留言类型基础分
    $score = intval($config['baseScore'][$type] ?? 0);
    $breakdown = ['base' => $score];

    // 2. 重复发布次数（取达到的最高档位）
    $repeatScore = 0;
    $matchedTier = 0;
    foreach ($config['repeatThresholds'] as $tier) {
        if ($factors['repeatCount'] >= intval($tier['count'])) {
            $matchedTier = intval($tier['count']);
            $repeatScore = intval($tier['score']);
        }
    }
    $score += $repeatScore;
    $breakdown['repeat'] = $repeatScore;

    // 3. 历史处置结果
    $rejectedAdd = intval($config['rejectedScore']) * $factors['rejectedCount'];
    $deletedAdd = intval($config['reportedDeletedScore']) * $factors['reportedDeletedCount'];
    $score += $rejectedAdd + $deletedAdd;
    $breakdown['rejected'] = $rejectedAdd;
    $breakdown['reportedDeleted'] = $deletedAdd;

    // 4. 定级
    if ($score >= intval($config['thresholds']['high'])) {
        $level = RISK_HIGH;
    } elseif ($score >= intval($config['thresholds']['medium'])) {
        $level = RISK_MEDIUM;
    } else {
        $level = RISK_LOW;
    }

    // 5. 处置优先级：级别权重 + 分数，保证高风险整体靠前、同级别高分靠前
    $priority = intval($config['priorityWeights'][$level]) + $score;
    $label = $config['labels'][$level] ?? riskLevelLabel($level);

    return [
        'level' => $level,
        'score' => $score,
        'priority' => $priority,
        'label' => $label,
        'manualReview' => riskIsManualReview($level),
        'breakdown' => $breakdown,
    ];
}

/**
 * 对单条留言执行分级并落库
 * 分级服务失败时保留原级别，仅标记 risk_stale=1，不阻断调用方（如审核列表）
 * 返回: true=已按规则完成分级; false=服务失败，保留原级别并标记待重算
 */
function riskGradeMessage($db, array $message, array $rules = null) {
    if ($rules === null) $rules = riskActiveRules($db);

    try {
        $factors = riskCollectFactors($db, $message, $rules['config']);
        $result = riskEvaluate($rules['config'], $message['type'], $factors);

        $snapshot = [
            'rule_version' => $rules['version'],
            'type' => $message['type'],
            'identity_key' => $factors['identityKey'],
            'factors' => [
                'repeat_count' => $factors['repeatCount'],
                'rejected_count' => $factors['rejectedCount'],
                'reported_deleted_count' => $factors['reportedDeletedCount'],
                'history_days' => intval($rules['config']['historyDays']),
            ],
            'score_breakdown' => $result['breakdown'],
            'thresholds' => $rules['config']['thresholds'],
        ];

        $stmt = $db->prepare(
            "UPDATE messages SET
                risk_level = ?, risk_priority = ?, risk_label = ?, risk_score = ?,
                risk_snapshot_json = ?, risk_rule_version = ?, risk_graded_at = NOW(),
                risk_stale = 0
             WHERE id = ?"
        );
        $stmt->execute([
            $result['level'], $result['priority'], $result['label'], $result['score'],
            json_encode($snapshot, JSON_UNESCAPED_UNICODE), $rules['version'],
            $message['id'],
        ]);
        return true;
    } catch (Exception $e) {
        // 降级：保留原级别，标记待重算
        try {
            $db->prepare("UPDATE messages SET risk_stale = 1 WHERE id = ?")->execute([$message['id']]);
        } catch (Exception $ignore) {
        }
        return false;
    }
}

/**
 * 全量重算（规则口径变化后，审核结果按同一口径重算）
 * 返回成功重算条数；单条失败标记待重算并继续，不影响其它记录
 */
function riskRegradeAll($db, $version = null, array $config = null) {
    $rules = ($version !== null && $config !== null)
        ? ['id' => 0, 'version' => $version, 'config' => riskNormalizeConfig($config)]
        : riskActiveRules($db);

    // 无生效规则版本（分级服务不可用）时不执行，记录继续保留待重算
    if ($version === null && $rules['id'] === 0) return 0;

    $count = 0;
    try {
        $stmt = $db->query("SELECT * FROM messages ORDER BY id");
    } catch (Exception $e) {
        return 0;
    }
    while ($msg = $stmt->fetch()) {
        if (riskGradeMessage($db, $msg, $rules)) $count++;
    }
    return $count;
}

/**
 * 待审记录惰性重算：列表加载时把待重算的待审记录按当前口径补算
 * 有上限保护，避免首次上线时拖慢页面；未处理完的记录保留 risk_stale=1 待下次继续
 * 返回本次成功重算条数
 */
function riskGradePendingStale($db, $limit = 50) {
    $rules = riskActiveRules($db);
    // 分级服务不可用（规则表缺失等）时不补算，保留原级别与待重算标记，不阻断列表
    if ($rules['id'] === 0) return 0;
    $count = 0;
    try {
        $stmt = $db->prepare(
            "SELECT * FROM messages WHERE status = 0 AND (risk_stale = 1 OR risk_rule_version <> ?)
             ORDER BY id ASC LIMIT " . intval($limit)
        );
        $stmt->execute([$rules['version']]);
        $rows = $stmt->fetchAll();
    } catch (Exception $e) {
        return 0;
    }
    foreach ($rows as $msg) {
        if (riskGradeMessage($db, $msg, $rules)) $count++;
    }
    return $count;
}

/* --------------------------- 历史处置记录 --------------------------- */

/**
 * 追加一条历史处置记录（失败不影响审核/举报主流程）
 */
function riskRecordDisposal($db, $identityKey, $messageId, $resultType, $adminId = null) {
    try {
        $stmt = $db->prepare(
            "INSERT INTO risk_disposal_history (identity_key, message_id, result_type, processed_by)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$identityKey, $messageId, $resultType, $adminId]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * 某条留言审核完成后：记录处置结果，并把同身份其它待审留言标记待重算
 * （历史处置结果是分级因子，处置后相关待审记录按新标准重新排列）
 */
function riskAfterDisposal($db, $message, $resultType, $adminId = null) {
    try {
        $identityKey = riskIdentityKey($message['phone'] ?? '', $message['nickname'] ?? '');
    } catch (Exception $e) {
        return;
    }
    riskRecordDisposal($db, $identityKey, $message['id'], $resultType, $adminId);
    try {
        $db->prepare(
            "UPDATE messages SET risk_stale = 1
             WHERE status = 0 AND id <> ?
             AND (CASE WHEN phone IS NOT NULL AND phone <> '' THEN CONCAT('p:', phone)
                       ELSE CONCAT('n:', LOWER(nickname)) END) = ?"
        )->execute([$message['id'], $identityKey]);
    } catch (Exception $e) {
    }
}

/**
 * 新留言提交后触发首次分级；失败时保留默认级别并标记待重算，不阻断留言提交
 */
function riskGradeNewMessage($db, $messageId) {
    try {
        $stmt = $db->prepare("SELECT * FROM messages WHERE id = ?");
        $stmt->execute([$messageId]);
        $message = $stmt->fetch();
        if ($message) riskGradeMessage($db, $message);
    } catch (Exception $e) {
    }
}

/* ------------------------------- 统计 ------------------------------- */

/**
 * 待审核数量
 */
function riskGetPendingCount($db) {
    try {
        return intval($db->query("SELECT COUNT(*) FROM messages WHERE status = 0")->fetchColumn());
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * 人工复核队列数量（待审 + 高风险；含待重算记录，避免分级失败时漏审）
 */
function riskGetManualReviewCount($db) {
    try {
        return intval($db->query("SELECT COUNT(*) FROM messages WHERE status = 0 AND risk_level = 'high'")->fetchColumn());
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * 待重算数量（分级服务失败或口径变化后尚未补算的记录）
 */
function riskGetStaleCount($db) {
    try {
        return intval($db->query("SELECT COUNT(*) FROM messages WHERE risk_stale = 1")->fetchColumn());
    } catch (Exception $e) {
        return 0;
    }
}
