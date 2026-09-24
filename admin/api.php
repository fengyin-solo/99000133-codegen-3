<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/risk.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db = getDB();

switch ($action) {
    case 'detail':
        $id = intval($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM messages WHERE id = ?");
        $stmt->execute([$id]);
        $msg = $stmt->fetch();
        if (!$msg) jsonResponse(1, '留言不存在');
        $msg['type_label'] = getTypeLabel($msg['type']);
        $msg['status_label'] = getStatusLabel($msg['status']);
        $msg['risk_label_display'] = getRiskDisplayLabel($msg['risk_level'], $msg['risk_label']);
        $msg['risk_level_class'] = getRiskLevelClass($msg['risk_level']);
        $msg['manual_review'] = riskIsManualReview($msg['risk_level']);
        $snapshot = json_decode($msg['risk_snapshot_json'] ?? '', true);
        $msg['risk_factors'] = is_array($snapshot) ? ($snapshot['factors'] ?? []) : [];
        $msg['risk_breakdown'] = is_array($snapshot) ? ($snapshot['score_breakdown'] ?? []) : [];
        $msg['content'] = nl2br(cleanInput($msg['content']));
        $msg['title'] = cleanInput($msg['title']);
        $msg['nickname'] = cleanInput($msg['nickname']);
        jsonResponse(0, 'ok', $msg);
        break;

    case 'audit':
        $id = intval($_POST['id'] ?? 0);
        $status = intval($_POST['status'] ?? 0);
        if (!in_array($status, [1, 2])) jsonResponse(1, '无效状态');
        $stmt = $db->prepare("SELECT * FROM messages WHERE id = ?");
        $stmt->execute([$id]);
        $msg = $stmt->fetch();
        if (!$msg) jsonResponse(1, '留言不存在');
        $stmt = $db->prepare("UPDATE messages SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);

        // 记录历史处置结果（通过/拒绝），并将同身份其它待审留言标记待重算
        $resultType = $status === 1 ? 'approved' : 'rejected';
        riskAfterDisposal($db, $msg, $resultType, $_SESSION['admin_id'] ?? null);

        jsonResponse(0, '操作成功');
        break;

    case 'delete':
        $id = intval($_POST['id'] ?? 0);
        // 删除关联图片
        $stmt = $db->prepare("SELECT image FROM messages WHERE id = ?");
        $stmt->execute([$id]);
        $msg = $stmt->fetch();
        if ($msg && $msg['image']) {
            $imgFile = __DIR__ . '/../' . $msg['image'];
            if (file_exists($imgFile)) unlink($imgFile);
        }
        $db->prepare("DELETE FROM messages WHERE id = ?")->execute([$id]);
        jsonResponse(0, '删除成功');
        break;

    case 'report_detail':
        $id = intval($_GET['id'] ?? 0);
        $stmt = $db->prepare("SELECT r.*, m.title as message_title, m.nickname as message_nickname, m.type as message_type, m.content as message_content, m.image as message_image, a.username as admin_name FROM reports r LEFT JOIN messages m ON r.message_id = m.id LEFT JOIN admins a ON r.processed_by = a.id WHERE r.id = ?");
        $stmt->execute([$id]);
        $report = $stmt->fetch();
        if (!$report) jsonResponse(1, '举报不存在');

        $report['report_type_label'] = getReportTypeLabel($report['report_type']);
        $report['status_label'] = getReportStatusLabel($report['status']);
        $report['status_class'] = getReportStatusClass($report['status']);
        $report['message_exists'] = !empty($report['message_title']);
        $report['message_type_label'] = $report['message_type'] ? getTypeLabel($report['message_type']) : '';
        $report['message_title'] = $report['message_title'] ? cleanInput($report['message_title']) : '';
        $report['message_nickname'] = $report['message_nickname'] ? cleanInput($report['message_nickname']) : '';
        $report['message_content'] = $report['message_content'] ? nl2br(cleanInput($report['message_content'])) : '';
        $report['description'] = $report['description'] ? nl2br(cleanInput($report['description'])) : '';
        $report['process_note'] = $report['process_note'] ? nl2br(cleanInput($report['process_note'])) : '';
        $report['admin_name'] = $report['admin_name'] ? cleanInput($report['admin_name']) : '';

        jsonResponse(0, 'ok', $report);
        break;

    case 'process_report':
        $id = intval($_POST['id'] ?? 0);
        $status = intval($_POST['status'] ?? 0);
        $note = cleanInput($_POST['note'] ?? '');

        if (!in_array($status, [1, 2, 3])) jsonResponse(1, '无效状态');

        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT * FROM reports WHERE id = ? AND status = 0 FOR UPDATE");
            $stmt->execute([$id]);
            $report = $stmt->fetch();
            if (!$report) jsonResponse(1, '举报不存在或已处理');

            if ($status === 1) {
                $stmt = $db->prepare("SELECT * FROM messages WHERE id = ?");
                $stmt->execute([$report['message_id']]);
                $msg = $stmt->fetch();
                if ($msg && $msg['image']) {
                    $imgFile = __DIR__ . '/../' . $msg['image'];
                    if (file_exists($imgFile)) unlink($imgFile);
                }
                // 删除前留存历史处置结果（举报删除），作为同身份后续留言的分级因子
                if ($msg) {
                    $identityKey = riskIdentityKey($msg['phone'] ?? '', $msg['nickname'] ?? '');
                    riskRecordDisposal($db, $identityKey, $msg['id'], 'reported_deleted', $_SESSION['admin_id'] ?? null);
                    // 同身份其它待审留言受历史处置影响，标记待重算
                    try {
                        $db->prepare(
                            "UPDATE messages SET risk_stale = 1
                             WHERE status = 0 AND (CASE WHEN phone IS NOT NULL AND phone <> '' THEN CONCAT('p:', phone)
                                                       ELSE CONCAT('n:', LOWER(nickname)) END) = ?"
                        )->execute([$identityKey]);
                    } catch (Exception $ignore) {
                    }
                }
                $db->prepare("DELETE FROM messages WHERE id = ?")->execute([$report['message_id']]);
            }

            $stmt = $db->prepare("UPDATE reports SET status = ?, processed_by = ?, processed_at = NOW(), process_note = ? WHERE id = ?");
            $stmt->execute([$status, $_SESSION['admin_id'], $note, $id]);

            $db->commit();

            $statusMsg = [1 => '已删除留言', 2 => '已忽略举报', 3 => '已驳回举报'];
            jsonResponse(0, $statusMsg[$status] . '成功');
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(1, '操作失败: ' . $e->getMessage());
        }
        break;

    case 'risk_regrade':
        // 手动重算：单条(id)或全部待重算(scope=all)。重算失败不阻断，返回待重算数
        $scope = $_POST['scope'] ?? 'one';
        if ($scope === 'all') {
            $regraded = riskRegradeAll($db);
            jsonResponse(0, '重算完成', [
                'regraded' => $regraded,
                'stale_count' => riskGetStaleCount($db),
            ]);
        }
        $id = intval($_POST['id'] ?? 0);
        $stmt = $db->prepare("SELECT * FROM messages WHERE id = ?");
        $stmt->execute([$id]);
        $msg = $stmt->fetch();
        if (!$msg) jsonResponse(1, '留言不存在');
        $ok = riskGradeMessage($db, $msg);
        if (!$ok) jsonResponse(1, '分级服务暂不可用，已保留原级别并标记待重算');
        jsonResponse(0, '重算成功');
        break;

    case 'risk_rules':
        // 获取规则版本列表与当前生效配置
        $rules = riskActiveRules($db);
        jsonResponse(0, 'ok', [
            'active_version' => $rules['version'],
            'config' => $rules['config'],
            'versions' => riskListRuleVersions($db),
        ]);
        break;

    case 'risk_save_rules':
        // 处理人员调整分级口径：保存新版本并按同一口径重算全部留言
        $config = riskDefaultConfig();
        $post = $_POST;

        foreach (['help', 'suggest', 'lost'] as $t) {
            if (isset($post['base_' . $t]) && is_numeric($post['base_' . $t])) {
                $config['baseScore'][$t] = max(0, intval($post['base_' . $t]));
            }
        }
        $tiers = [];
        $tierCounts = $post['tier_count'] ?? [];
        $tierScores = $post['tier_score'] ?? [];
        if (is_array($tierCounts) && is_array($tierScores)) {
            foreach ($tierCounts as $i => $cnt) {
                if (is_numeric($cnt) && intval($cnt) >= 1 && isset($tierScores[$i]) && is_numeric($tierScores[$i])) {
                    $tiers[] = ['count' => intval($cnt), 'score' => max(0, intval($tierScores[$i]))];
                }
            }
        }
        $config['repeatThresholds'] = $tiers ?: $config['repeatThresholds'];

        foreach (['rejectedScore' => 'rejected_score', 'reportedDeletedScore' => 'reported_deleted_score', 'historyDays' => 'history_days'] as $key => $field) {
            if (isset($post[$field]) && is_numeric($post[$field])) {
                $config[$key] = max(0, intval($post[$field]));
            }
        }
        foreach (['medium' => 'threshold_medium', 'high' => 'threshold_high'] as $lv => $field) {
            if (isset($post[$field]) && is_numeric($post[$field])) {
                $config['thresholds'][$lv] = max(0, intval($post[$field]));
            }
        }
        foreach (['low' => 'label_low', 'medium' => 'label_medium', 'high' => 'label_high'] as $lv => $field) {
            if (isset($post[$field]) && trim($post[$field]) !== '') {
                $config['labels'][$lv] = trim(mb_substr($post[$field], 0, 50));
            }
        }

        $remark = cleanInput($post['remark'] ?? '');
        try {
            $result = riskSaveRules($db, $config, $remark, $_SESSION['admin_id'] ?? null);
        } catch (Exception $e) {
            jsonResponse(1, '规则保存失败: ' . $e->getMessage());
        }
        jsonResponse(0, '分级口径已更新，全部留言已按新标准重算', $result);
        break;

    case 'risk_reactivate':
        // 重新启用历史版本口径
        $ruleId = intval($_POST['rule_id'] ?? 0);
        try {
            $result = riskReactivateRules($db, $ruleId, $_SESSION['admin_id'] ?? null);
        } catch (Exception $e) {
            jsonResponse(1, '启用失败: ' . $e->getMessage());
        }
        jsonResponse(0, '已重新启用该口径并完成重算', $result);
        break;

    default:
        jsonResponse(1, '未知操作');
}
