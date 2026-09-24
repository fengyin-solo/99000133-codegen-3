<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/risk_service.php';
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

        // 风险信息（分级服务不可用时不影响查看详情）
        $risk = new RiskService($db);
        if ($risk->isAvailable()) {
            $riskMeta = riskLevelMeta($msg['risk_level'] ?? 'low');
            $labelCodes = array_filter(explode(',', (string)($msg['risk_labels'] ?? '')));
            $labels = [];
            foreach ($labelCodes as $code) {
                $lm = riskLabelMeta($code);
                $labels[] = ['code' => $code, 'text' => $lm['text'], 'class' => $lm['class']];
            }
            $factors = [];
            if (!empty($msg['risk_factors'])) {
                $decoded = json_decode($msg['risk_factors'], true);
                if (is_array($decoded)) $factors = $decoded;
            }
            $msg['risk'] = [
                'level' => $msg['risk_level'],
                'level_text' => $msg['risk_level'] ? $riskMeta['text'] : '未分级',
                'level_class' => $riskMeta['class'],
                'score' => intval($msg['risk_score']),
                'priority' => intval($msg['risk_priority']),
                'queue' => $riskMeta['queue'],
                'labels' => $labels,
                'factors' => $factors,
                'rule_version' => $msg['risk_rule_version'] !== null ? intval($msg['risk_rule_version']) : null,
                'calculated_at' => $msg['risk_calculated_at'],
                'stale' => !empty($msg['risk_stale']),
            ];
        } else {
            $msg['risk'] = null;
        }

        $msg['type_label'] = getTypeLabel($msg['type']);
        $msg['status_label'] = getStatusLabel($msg['status']);
        $msg['content'] = nl2br(cleanInput($msg['content']));
        $msg['title'] = cleanInput($msg['title']);
        $msg['nickname'] = cleanInput($msg['nickname']);
        jsonResponse(0, 'ok', $msg);
        break;

    case 'audit':
        $id = intval($_POST['id'] ?? 0);
        $status = intval($_POST['status'] ?? 0);
        if (!in_array($status, [1, 2])) jsonResponse(1, '无效状态');
        $stmt = $db->prepare("UPDATE messages SET status = ? WHERE id = ?");
        $stmt->execute([$status, $id]);

        // 审核处置改变了“历史处置结果”，同身份的其余待审留言按同一口径重新分级
        $risk = new RiskService($db);
        if ($risk->isAvailable()) {
            $risk->recalculatePending();
        }
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

        // 删除会改变同身份留言的重复发布计数，按当前口径重算
        $risk = new RiskService($db);
        if ($risk->isAvailable()) {
            $risk->recalculateAll();
        }
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
                $stmt = $db->prepare("SELECT image FROM messages WHERE id = ?");
                $stmt->execute([$report['message_id']]);
                $msg = $stmt->fetch();
                if ($msg && $msg['image']) {
                    $imgFile = __DIR__ . '/../' . $msg['image'];
                    if (file_exists($imgFile)) unlink($imgFile);
                }
                $db->prepare("DELETE FROM messages WHERE id = ?")->execute([$report['message_id']]);
            }

            $stmt = $db->prepare("UPDATE reports SET status = ?, processed_by = ?, processed_at = NOW(), process_note = ? WHERE id = ?");
            $stmt->execute([$status, $_SESSION['admin_id'], $note, $id]);

            $db->commit();

            // 举报处置结果会影响“待处理举报数”这一分级因子，待审留言按同一口径重算
            $risk = new RiskService($db);
            if ($risk->isAvailable()) {
                $risk->recalculatePending();
            }

            $statusMsg = [1 => '已删除留言', 2 => '已忽略举报', 3 => '已驳回举报'];
            jsonResponse(0, $statusMsg[$status] . '成功');
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(1, '操作失败: ' . $e->getMessage());
        }
        break;

    case 'risk_rules_get':
        // 取当前口径与历史版本
        $risk = new RiskService($db);
        if (!$risk->isAvailable()) jsonResponse(1, '风险分级功能未安装，请先执行 database/migration_add_risk_grading.sql');
        $current = $risk->getCurrentRules();
        jsonResponse(0, 'ok', [
            'current_version' => $current['version'],
            'rules' => $current['rules'],
            'versions' => $risk->getAllVersions(),
        ]);
        break;

    case 'risk_rules_save':
        // 调整分级口径：写新版本并按同一口径重算全部记录
        $rules = json_decode($_POST['rules'] ?? '', true);
        if (!is_array($rules)) jsonResponse(1, '规则参数格式不正确');
        $remark = trim($_POST['remark'] ?? '');
        $risk = new RiskService($db);
        try {
            $res = $risk->saveRules($rules, $remark, $_SESSION['admin_id'] ?? null);
            jsonResponse(0, "口径已更新至 v{$res['version']}，已按新标准重算 {$res['recalculated']} 条记录", $res);
        } catch (Exception $e) {
            jsonResponse(1, $e->getMessage());
        }
        break;

    case 'risk_recalculate':
        // 手动触发全量重算（如分级服务曾失败、存在待重算记录）
        $risk = new RiskService($db);
        if (!$risk->isAvailable()) jsonResponse(1, '风险分级功能未安装');
        $count = $risk->recalculateAll();
        jsonResponse(0, "重算完成，成功 {$count} 条记录", ['recalculated' => $count]);
        break;

    default:
        jsonResponse(1, '未知操作');
}
