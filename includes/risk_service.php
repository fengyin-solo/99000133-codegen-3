<?php
/**
 * 留言风险分级服务
 *
 * 分级依据：留言类型（基础分）+ 重复发布次数 + 历史处置结果 + 待处理举报数。
 *
 * 关键设计：
 * - 分级口径以「规则版本」落库（risk_rule_versions）。calculate() 是纯计算，
 *   同一输入在任何查看条件下结果相同，保证「同类留言在不同查看条件下得到相同级别」。
 * - 规则、条件、标准变化时写入新版本并对存量记录统一重算（版本号写回每条记录）。
 * - 分级服务失败（表结构未就绪 / 计算异常）时不抛出阻断调用方：保留原级别并
 *   标记 risk_stale=1（待重算），审核列表照常展示。
 */

require_once __DIR__ . '/functions.php';

/**
 * 默认分级口径（首次初始化 / 无可用版本时使用，与迁移脚本 v1 保持一致）
 */
function riskDefaultRules() {
    return [
        // 留言类型基础分
        'base_score' => ['help' => 20, 'suggest' => 10, 'lost' => 5],
        // 重复发布次数阶梯（同一身份、相同标题内容）
        'repeat_count_thresholds' => [
            ['gte' => 3, 'score' => 40, 'label' => 'repeat_high'],
            ['gte' => 2, 'score' => 20, 'label' => 'repeat'],
        ],
        // 存在重复发布时的附加分
        'duplicate_title_score' => 20,
        // 历史处置结果：历史被拒次数阶梯
        'reject_thresholds' => [
            ['gte' => 3, 'score' => 40, 'label' => 'reject_high'],
            ['gte' => 1, 'score' => 25, 'label' => 'reject_history'],
        ],
        // 待处理举报数阶梯
        'report_thresholds' => [
            ['gte' => 2, 'score' => 30, 'label' => 'reported'],
            ['gte' => 1, 'score' => 15, 'label' => 'reported'],
        ],
        // 级别分数线
        'level_thresholds' => ['medium' => 40, 'high' => 70],
        // 处置优先级基数（实际优先级 = 级别基数 + 风险分）
        'priority_base' => ['high' => 100, 'medium' => 50, 'low' => 0],
    ];
}

/**
 * 风险标签元信息（标签文案与样式由展示层统一取用，保证各处显示一致）
 */
function riskLabelMeta($code) {
    $map = [
        'repeat_high'    => ['text' => '多次重复发布', 'class' => 'risk-high'],
        'repeat'         => ['text' => '重复发布', 'class' => 'risk-medium'],
        'duplicate_title'=> ['text' => '内容重复', 'class' => 'risk-medium'],
        'reject_high'    => ['text' => '多次历史拒绝', 'class' => 'risk-high'],
        'reject_history' => ['text' => '历史曾被拒', 'class' => 'risk-medium'],
        'reported'       => ['text' => '存在待处理举报', 'class' => 'risk-medium'],
    ];
    return $map[$code] ?? ['text' => $code, 'class' => 'risk-low'];
}

function riskLevelMeta($level) {
    $map = [
        'high'   => ['text' => '高风险', 'class' => 'risk-high', 'queue' => '人工复核队列'],
        'medium' => ['text' => '中风险', 'class' => 'risk-medium', 'queue' => '普通处理'],
        'low'    => ['text' => '低风险', 'class' => 'risk-low', 'queue' => '普通处理'],
    ];
    return $map[$level] ?? $map['low'];
}

/**
 * 纯计算：根据规则与因子计算风险结果。
 *
 * @param array $rules   规则口径（riskDefaultRules 的结构）
 * @param array $factors [
 *      'type'           => string  留言类型
 *      'repeat_count'   => int     同身份相同标题的发布次数（含本条）
 *      'reject_count'   => int     同身份历史被拒留言数
 *      'pending_reports'=> int     该留言待处理举报数
 * ]
 * @return array {level, score, priority, labels[]}
 */
function riskCalculate(array $rules, array $factors) {
    $type = $factors['type'] ?? 'help';
    $repeatCount = max(0, intval($factors['repeat_count'] ?? 0));
    $rejectCount = max(0, intval($factors['reject_count'] ?? 0));
    $pendingReports = max(0, intval($factors['pending_reports'] ?? 0));

    $score = intval($rules['base_score'][$type] ?? 0);
    $labels = [];

    // 重复发布次数（阶梯取满足条件的最高分一档）
    foreach ($rules['repeat_count_thresholds'] as $t) {
        if ($repeatCount >= intval($t['gte']) && !in_array($t['label'], $labels, true)) {
            $score += intval($t['score']);
            $labels[] = $t['label'];
            break;
        }
    }

    // 内容重复（存在同身份相同标题的其它留言即标记，与次数阶梯分别计一次）
    if ($repeatCount >= 2) {
        $score += intval($rules['duplicate_title_score']);
        $labels[] = 'duplicate_title';
    }

    // 历史处置结果：历史被拒次数阶梯
    foreach ($rules['reject_thresholds'] as $t) {
        if ($rejectCount >= intval($t['gte']) && !in_array($t['label'], $labels, true)) {
            $score += intval($t['score']);
            $labels[] = $t['label'];
            break;
        }
    }

    // 待处理举报数阶梯
    foreach ($rules['report_thresholds'] as $t) {
        if ($pendingReports >= intval($t['gte']) && !in_array($t['label'], $labels, true)) {
            $score += intval($t['score']);
            $labels[] = $t['label'];
            break;
        }
    }

    $mediumAt = intval($rules['level_thresholds']['medium']);
    $highAt = intval($rules['level_thresholds']['high']);
    if ($score >= $highAt) {
        $level = 'high';
    } elseif ($score >= $mediumAt) {
        $level = 'medium';
    } else {
        $level = 'low';
    }

    $priority = intval($rules['priority_base'][$level]) + $score;

    return [
        'level' => $level,
        'score' => $score,
        'priority' => $priority,
        'labels' => $labels,
    ];
}

class RiskService
{
    private $db;
    private $available;
    private $rulesCache = null;
    private $versionCache = null;

    public function __construct($db = null)
    {
        $this->db = $db;
        $this->available = $this->checkSchema();
    }

    /** 分级服务是否可用（风险字段与规则表是否已就绪）。失败不影响审核列表 */
    public function isAvailable()
    {
        return $this->available;
    }

    private function checkSchema()
    {
        try {
            $db = $this->db ?: getDB();
            $cols = $db->query("SHOW COLUMNS FROM `messages` LIKE 'risk_level'")->fetchAll();
            if (!$cols) return false;
            $tbl = $db->query("SHOW TABLES LIKE 'risk_rule_versions'")->fetchAll();
            return (bool) $tbl;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * 取当前生效规则（版本号最大的一条）。
     * @return array{version:int, rules:array} 或默认 v1（规则表为空时）
     */
    public function getCurrentRules()
    {
        if ($this->rulesCache !== null) {
            return ['version' => $this->versionCache, 'rules' => $this->rulesCache];
        }

        $rules = riskDefaultRules();
        $version = 1;
        try {
            $db = $this->db ?: getDB();
            $row = $db->query("SELECT version, rules_json FROM risk_rule_versions ORDER BY version DESC LIMIT 1")->fetch();
            if ($row) {
                $decoded = json_decode($row['rules_json'], true);
                if (is_array($decoded)) {
                    $rules = $decoded;
                    $version = intval($row['version']);
                }
            }
        } catch (Throwable $e) {
            // 规则读取失败时回退默认口径，仍可完成本次计算
        }

        $this->rulesCache = $rules;
        $this->versionCache = $version;
        return ['version' => $version, 'rules' => $rules];
    }

    /** 全部历史版本（口径调整记录） */
    public function getAllVersions()
    {
        if (!$this->available) return [];
        try {
            $db = $this->db ?: getDB();
            return $db->query(
                "SELECT v.*, a.username AS admin_name
                 FROM risk_rule_versions v
                 LEFT JOIN admins a ON v.created_by = a.id
                 ORDER BY v.version DESC"
            )->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * 调整分级口径：校验 -> 写入新版本 -> 按同一口径重算全部记录。
     * @return array{version:int, recalculated:int}
     * @throws Exception 规则不合法时抛出
     */
    public function saveRules(array $rules, $remark, $adminId)
    {
        if (!$this->available) {
            throw new Exception('风险分级功能未安装，请先执行迁移脚本');
        }
        $rules = $this->validateRules($rules);
        $db = $this->db ?: getDB();

        $db->beginTransaction();
        try {
            $next = intval($db->query("SELECT COALESCE(MAX(version),0)+1 FROM risk_rule_versions FOR UPDATE")->fetchColumn());
            $stmt = $db->prepare(
                "INSERT INTO risk_rule_versions (version, rules_json, remark, created_by) VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([$next, json_encode($rules, JSON_UNESCAPED_UNICODE), trim($remark), $adminId ?: null]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        // 口径变化：全部已有记录按新版本重算（先清缓存使新版本生效）
        $this->rulesCache = null;
        $this->versionCache = null;
        $recalculated = $this->recalculateAll();
        return ['version' => $next, 'recalculated' => $recalculated];
    }

    /**
     * 校验并归一化规则。阈值阶梯按 gte 降序，保证口径明确。
     */
    private function validateRules(array $r)
    {
        $base = riskDefaultRules();

        $baseScore = [];
        foreach (['help', 'suggest', 'lost'] as $t) {
            $v = isset($r['base_score'][$t]) ? intval($r['base_score'][$t]) : $base['base_score'][$t];
            if ($v < 0 || $v > 100) throw new Exception('类型基础分需在 0~100 之间');
            $baseScore[$t] = $v;
        }

        $repeat = $this->validateThresholds($r['repeat_count_thresholds'] ?? null, $base['repeat_count_thresholds'], 0, 100);
        $reject = $this->validateThresholds($r['reject_thresholds'] ?? null, $base['reject_thresholds'], 0, 100);
        $reports = $this->validateThresholds($r['report_thresholds'] ?? null, $base['report_thresholds'], 0, 100);

        $dupScore = isset($r['duplicate_title_score']) ? intval($r['duplicate_title_score']) : $base['duplicate_title_score'];
        if ($dupScore < 0 || $dupScore > 100) throw new Exception('内容重复分值需在 0~100 之间');

        $mediumAt = intval($r['level_thresholds']['medium'] ?? $base['level_thresholds']['medium']);
        $highAt = intval($r['level_thresholds']['high'] ?? $base['level_thresholds']['high']);
        if ($mediumAt < 1 || $highAt <= $mediumAt || $highAt > 300) {
            throw new Exception('级别分数线需满足 0 < 中风险线 < 高风险线');
        }

        return [
            'base_score' => $baseScore,
            'repeat_count_thresholds' => $repeat,
            'duplicate_title_score' => $dupScore,
            'reject_thresholds' => $reject,
            'report_thresholds' => $reports,
            'level_thresholds' => ['medium' => $mediumAt, 'high' => $highAt],
            'priority_base' => $base['priority_base'],
        ];
    }

    private function validateThresholds($input, $default, $minScore, $maxScore)
    {
        // 标签只允许白名单键（展示文案与样式统一由 riskLabelMeta 提供），
        // 避免管理员输入的原始字符串被前端直接渲染
        $allowedLabels = ['repeat_high', 'repeat', 'duplicate_title',
            'reject_high', 'reject_history', 'reported'];
        if (!is_array($input)) $input = $default;
        $out = [];
        foreach ($input as $row) {
            if (!isset($row['gte'], $row['score'], $row['label'])) continue;
            $gte = intval($row['gte']);
            $score = intval($row['score']);
            $label = preg_replace('/[^a-z_]/', '', strtolower($row['label']));
            if ($gte < 1 || $score < $minScore || $score > $maxScore || !in_array($label, $allowedLabels, true)) continue;
            $out[] = ['gte' => $gte, 'score' => $score, 'label' => $label];
        }
        if (!$out) $out = $default;
        usort($out, function ($a, $b) { return $b['gte'] <=> $a['gte']; });
        return $out;
    }

    /**
     * 收集一组留言的分级因子（批量，避免 N+1）。
     * 身份：优先 visitor_id，其次手机号；按「身份 + 归一化标题」判定重复发布。
     *
     * @param array $messages messages 行数组
     * @return array message_id => factors
     */
    public function collectFactors(array $messages)
    {
        $result = [];
        if (!$messages) return $result;

        $db = $this->db ?: getDB();

        // 1) 待处理举报数
        $reportCounts = [];
        try {
            $ids = array_map('intval', array_column($messages, 'id'));
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare(
                "SELECT message_id, COUNT(*) AS c FROM reports WHERE status = 0 AND message_id IN ($in) GROUP BY message_id"
            );
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $row) {
                $reportCounts[intval($row['message_id'])] = intval($row['c']);
            }
        } catch (Throwable $e) {
            // 举报统计不可用时按 0 处理
        }

        // 2) 同身份维度聚合（重复发布次数、历史被拒数），口径全局一致
        $agg = $this->aggregateIdentityStats();
        $identOf = $agg['identOf'];
        $repeatAgg = $agg['repeat'];
        $rejectAgg = $agg['reject'];

        foreach ($messages as $m) {
            $id = intval($m['id']);
            $ident = $identOf[$id]
                ?? (!empty($m['visitor_id']) ? 'v:' . $m['visitor_id'] : (!empty($m['phone']) ? 'p:' . $m['phone'] : null));

            $repeat = 1;
            $reject = 0;
            if ($ident !== null) {
                $key = $ident . '|' . $this->normalizeTitle($m['title']);
                $repeat = isset($repeatAgg[$key]) ? max(1, $repeatAgg[$key]) : 1;
                $reject = $rejectAgg[$ident] ?? 0;
                // 历史处置结果不计本条自身（本条为待审时本就不在被拒数中；
                // 若本条已被拒，聚合值包含自身，减去 1 表示“历史”）
                if (intval($m['status']) === 2 && $reject > 0) $reject--;
            }

            $result[$id] = [
                'type' => $m['type'],
                'repeat_count' => $repeat,
                'reject_count' => $reject,
                'pending_reports' => $reportCounts[$id] ?? 0,
            ];
        }
        return $result;
    }

    /**
     * 全量身份聚合：一次扫描完成，供批量分级复用。
     * @return array {identOf: id=>身份, repeat: 身份|标题=>次数, reject: 身份=>被拒数}
     */
    private function aggregateIdentityStats()
    {
        $identOf = [];
        $repeatAgg = [];
        $rejectAgg = [];
        try {
            $rows = ($this->db ?: getDB())->query(
                "SELECT id, visitor_id, phone, title, status FROM messages
                 WHERE (visitor_id IS NOT NULL AND visitor_id <> '')
                    OR (phone IS NOT NULL AND phone <> '')"
            )->fetchAll();
            foreach ($rows as $r) {
                $ident = !empty($r['visitor_id']) ? 'v:' . $r['visitor_id'] : 'p:' . $r['phone'];
                $identOf[intval($r['id'])] = $ident;
                $key = $ident . '|' . $this->normalizeTitle($r['title']);
                $repeatAgg[$key] = ($repeatAgg[$key] ?? 0) + 1;
                if (intval($r['status']) === 2) {
                    $rejectAgg[$ident] = ($rejectAgg[$ident] ?? 0) + 1;
                }
            }
        } catch (Throwable $e) {
            // 统计不可用时返回空聚合，调用方退化为仅按本条计算
        }
        return ['identOf' => $identOf, 'repeat' => $repeatAgg, 'reject' => $rejectAgg];
    }

    private function normalizeTitle($title)
    {
        $title = trim(preg_replace('/\s+/u', '', (string)$title));
        return mb_strtolower($title, 'UTF-8');
    }

    /**
     * 对单条留言执行分级并落库。任何失败都保留原级别并标记待重算，不阻断业务。
     * @return bool 是否分级成功
     */
    public function gradeMessage(array $message)
    {
        if (!$this->available) return false;
        try {
            $current = $this->getCurrentRules();
            $factor = $this->collectFactors([$message]);
            $factor = $factor[intval($message['id'])] ?? [
                'type' => $message['type'],
                'repeat_count' => 1,
                'reject_count' => 0,
                'pending_reports' => 0,
            ];
            return $this->applyGrade($message, $current['rules'], $current['version'], $factor);
        } catch (Throwable $e) {
            return $this->markStale(intval($message['id']));
        }
    }

    /**
     * 批量分级（用于审核列表展示前的兜底重算）。
     * 一次聚合身份因子，逐条落库；失败的记录保留原级别并待重算，不影响整页。
     * @return int 成功分级条数
     */
    public function gradeMessages(array $messages)
    {
        if (!$this->available || !$messages) return 0;
        $ok = 0;
        try {
            $current = $this->getCurrentRules();
            $factorsMap = $this->collectFactors($messages);
            foreach ($messages as $m) {
                try {
                    $factor = $factorsMap[intval($m['id'])] ?? [
                        'type' => $m['type'],
                        'repeat_count' => 1,
                        'reject_count' => 0,
                        'pending_reports' => 0,
                    ];
                    if ($this->applyGrade($m, $current['rules'], $current['version'], $factor)) $ok++;
                } catch (Throwable $e) {
                    $this->markStale(intval($m['id']));
                }
            }
        } catch (Throwable $e) {
            foreach ($messages as $m) $this->markStale(intval($m['id']));
        }
        return $ok;
    }

    /**
     * 纯计算 + 落库单条结果。确定性：相同规则与因子必然得到相同级别。
     */
    private function applyGrade(array $message, array $rules, $version, array $factor)
    {
        $r = riskCalculate($rules, $factor);
        $stmt = ($this->db ?: getDB())->prepare(
            "UPDATE messages SET
                risk_level = ?, risk_score = ?, risk_priority = ?, risk_labels = ?,
                risk_factors = ?, risk_rule_version = ?, risk_calculated_at = NOW(), risk_stale = 0
             WHERE id = ?"
        );
        return $stmt->execute([
            $r['level'],
            $r['score'],
            $r['priority'],
            implode(',', $r['labels']),
            json_encode($factor, JSON_UNESCAPED_UNICODE),
            $version,
            intval($message['id']),
        ]);
    }

    /**
     * 按当前口径重算全部记录（规则变化后调用，保证审核结果同一口径）。
     * 分页处理，单次身份聚合，避免内存与重复扫描问题。
     * @return int 成功重算条数
     */
    public function recalculateAll()
    {
        if (!$this->available) return 0;
        $db = $this->db ?: getDB();
        $ok = 0;
        try {
            $current = $this->getCurrentRules();
            // 先把全部记录置为待重算；随后统一按新版本计算，
            // 计算期间查看列表也会保留原级别并显示待重算，不会阻断
            $db->exec("UPDATE messages SET risk_stale = 1");
            $agg = $this->aggregateIdentityStats();

            $lastId = 0;
            $batchSize = 200;
            while (true) {
                $stmt = $db->prepare("SELECT * FROM messages WHERE id > ? ORDER BY id LIMIT $batchSize");
                $stmt->execute([$lastId]);
                $rows = $stmt->fetchAll();
                if (!$rows) break;

                $reportCounts = $this->loadPendingReportCounts($rows);
                foreach ($rows as $row) {
                    try {
                        $factor = $this->factorFromAggregate($row, $agg, $reportCounts);
                        if ($this->applyGrade($row, $current['rules'], $current['version'], $factor)) $ok++;
                    } catch (Throwable $e) {
                        $this->markStale(intval($row['id']));
                    }
                    $lastId = intval($row['id']);
                }
            }
        } catch (Throwable $e) {
            // 全量重算失败不抛出，未处理记录仍保持待重算标记
        }
        return $ok;
    }

    /**
     * 重算所有待审核记录（审核处置后，同身份的待审记录因子可能变化）。
     */
    public function recalculatePending()
    {
        if (!$this->available) return 0;
        $ok = 0;
        try {
            $rows = ($this->db ?: getDB())
                ->query("SELECT * FROM messages WHERE status = 0 ORDER BY id")
                ->fetchAll();
            $ok = $this->gradeMessages($rows);
        } catch (Throwable $e) {
            // 失败保留原级别与待重算标记
        }
        return $ok;
    }

    /**
     * 由全量聚合结果构造单条因子（与 collectFactors 同口径）。
     */
    private function factorFromAggregate(array $m, array $agg, array $reportCounts)
    {
        $id = intval($m['id']);
        $ident = $agg['identOf'][$id]
            ?? (!empty($m['visitor_id']) ? 'v:' . $m['visitor_id'] : (!empty($m['phone']) ? 'p:' . $m['phone'] : null));

        $repeat = 1;
        $reject = 0;
        if ($ident !== null) {
            $key = $ident . '|' . $this->normalizeTitle($m['title']);
            $repeat = isset($agg['repeat'][$key]) ? max(1, $agg['repeat'][$key]) : 1;
            $reject = $agg['reject'][$ident] ?? 0;
            if (intval($m['status']) === 2 && $reject > 0) $reject--;
        }

        return [
            'type' => $m['type'],
            'repeat_count' => $repeat,
            'reject_count' => $reject,
            'pending_reports' => $reportCounts[$id] ?? 0,
        ];
    }

    private function loadPendingReportCounts(array $rows)
    {
        $counts = [];
        try {
            $db = $this->db ?: getDB();
            $ids = array_map('intval', array_column($rows, 'id'));
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare(
                "SELECT message_id, COUNT(*) AS c FROM reports WHERE status = 0 AND message_id IN ($in) GROUP BY message_id"
            );
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $row) {
                $counts[intval($row['message_id'])] = intval($row['c']);
            }
        } catch (Throwable $e) {
            // 举报统计不可用时按 0 处理
        }
        return $counts;
    }

    /**
     * 标记为待重算（分级失败、或因子可能已变化时调用），保留既有级别。
     */
    public function markStale($messageId)
    {
        if (!$this->available) return false;
        try {
            $stmt = ($this->db ?: getDB())->prepare("UPDATE messages SET risk_stale = 1 WHERE id = ?");
            return $stmt->execute([intval($messageId)]);
        } catch (Throwable $e) {
            return false;
        }
    }

    /** 待人工复核（高风险待审）数量 */
    public function getHighRiskPendingCount()
    {
        if (!$this->available) return 0;
        try {
            return intval(($this->db ?: getDB())
                ->query("SELECT COUNT(*) FROM messages WHERE status = 0 AND risk_level = 'high'")
                ->fetchColumn());
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** 待重算记录数量 */
    public function getStaleCount()
    {
        if (!$this->available) return 0;
        try {
            return intval(($this->db ?: getDB())
                ->query("SELECT COUNT(*) FROM messages WHERE risk_stale = 1")
                ->fetchColumn());
        } catch (Throwable $e) {
            return 0;
        }
    }
}
