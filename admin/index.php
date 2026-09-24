<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/risk_service.php';
requireAdmin();

$pageTitle = '后台管理 - 社区便民留言板';
$currentPage = 'admin';
$cssPath = '../assets/css/style.css';
$jsPath = '../assets/js/main.js';

$db = getDB();
$risk = new RiskService($db);
$riskEnabled = $risk->isAvailable();

// 筛选参数
$status = $_GET['status'] ?? '';
$type = $_GET['type'] ?? '';
$riskLevel = $_GET['risk_level'] ?? '';
$queue = $_GET['queue'] ?? ''; // review = 人工复核队列（高风险待审）
$keyword = trim($_GET['keyword'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$pageSize = 15;
$offset = ($page - 1) * $pageSize;

$where = "WHERE 1=1";
$params = [];

if ($queue === 'review') {
    // 人工复核队列：高风险 + 待审核
    $status = '0';
    $riskLevel = 'high';
}

if ($status !== '' && in_array($status, ['0', '1', '2'])) {
    $where .= " AND status = ?";
    $params[] = intval($status);
}
if ($type && in_array($type, ['help', 'suggest', 'lost'])) {
    $where .= " AND type = ?";
    $params[] = $type;
}
if ($riskLevel && in_array($riskLevel, ['low', 'medium', 'high'])) {
    $where .= " AND risk_level = ?";
    $params[] = $riskLevel;
}
if ($keyword) {
    $where .= " AND (title LIKE ? OR content LIKE ? OR nickname LIKE ?)";
    $kw = "%$keyword%";
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
}

// 待审记录展示前先做兜底重算（服务失败/口径变更后遗留的待重算记录）。
// 重算失败的记录保留原级别与待重算标记，不阻断列表加载。
if ($riskEnabled && $status === '0') {
    try {
        $staleRows = $db->prepare("SELECT * FROM messages WHERE status = 0 AND risk_stale = 1");
        $staleRows->execute();
        $risk->gradeMessages($staleRows->fetchAll());
    } catch (Throwable $e) {
        // 分级服务异常：继续使用原级别展示
    }
}

$countStmt = $db->prepare("SELECT COUNT(*) FROM messages $where");
$countStmt->execute($params);
$total = $countStmt->fetchColumn();
$totalPages = ceil($total / $pageSize);

// 待审核视图按「处置优先级倒序」排列（高风险进入人工复核队列并排在最前），
// 其它视图维持时间倒序；级别来自同一套落库口径，不同查看条件下列序一致。
if ($riskEnabled && $status === '0') {
    $orderBy = "ORDER BY risk_priority DESC, risk_stale ASC, created_at DESC";
} else {
    $orderBy = "ORDER BY created_at DESC";
}
$sql = "SELECT * FROM messages $where $orderBy LIMIT $pageSize OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$messages = $stmt->fetchAll();

// 统计
$pendingCount = $db->query("SELECT COUNT(*) FROM messages WHERE status = 0")->fetchColumn();
$highRiskPendingCount = $riskEnabled ? $risk->getHighRiskPendingCount() : 0;
$staleCount = $riskEnabled ? $risk->getStaleCount() : 0;

$isReviewQueue = ($queue === 'review');

include __DIR__ . '/header.php';
?>

<div class="admin-container">
    <aside class="admin-sidebar">
        <div class="sidebar-header">
            <h3>📋 管理后台</h3>
        </div>
        <nav class="sidebar-nav">
            <a href="index.php" class="sidebar-link<?= $isReviewQueue ? '' : ' active' ?>">📝 留言管理</a>
            <a href="index.php?status=0" class="sidebar-link">⏳ 待审核 <?= $pendingCount > 0 ? "($pendingCount)" : '' ?></a>
            <a href="index.php?queue=review" class="sidebar-link<?= $isReviewQueue ? ' active' : '' ?>">🔴 人工复核队列 <?= $highRiskPendingCount > 0 ? "($highRiskPendingCount)" : '' ?></a>
            <a href="risk_rules.php" class="sidebar-link">⚖️ 分级口径设置</a>
            <a href="reports.php" class="sidebar-link">🚩 举报管理</a>
            <?php $pendingReportCount = getPendingReportCount(); ?>
            <a href="reports.php?status=0" class="sidebar-link">⏳ 待处理举报 <?= $pendingReportCount > 0 ? "($pendingReportCount)" : '' ?></a>
            <a href="../index.php" class="sidebar-link" target="_blank">🌐 查看前台</a>
            <a href="logout.php" class="sidebar-link">🚪 退出登录</a>
        </nav>
    </aside>

    <div class="admin-main">
        <div class="admin-header">
            <h2><?= $isReviewQueue ? '人工复核队列（高风险待审留言）' : '留言管理' ?></h2>
            <span class="admin-user">👤 <?= cleanInput($_SESSION['admin_name']) ?></span>
        </div>

        <?php if ($staleCount > 0): ?>
        <div class="risk-notice">
            ⚠️ 有 <strong><?= $staleCount ?></strong> 条留言的风险级别待重新计算（分级服务曾不可用或口径已更新）。
            <button type="button" class="btn btn-xs btn-warning" onclick="recalculateRisk()">立即重算</button>
        </div>
        <?php endif; ?>

        <!-- 筛选栏 -->
        <div class="admin-filter">
            <form method="GET" class="filter-form">
                <?php if ($isReviewQueue): ?><input type="hidden" name="queue" value="review"><?php endif; ?>
                <select name="status" <?= $isReviewQueue ? 'disabled' : '' ?>>
                    <option value="">全部状态</option>
                    <option value="0" <?= $status === '0' ? 'selected' : '' ?>>待审核</option>
                    <option value="1" <?= $status === '1' ? 'selected' : '' ?>>已通过</option>
                    <option value="2" <?= $status === '2' ? 'selected' : '' ?>>已拒绝</option>
                </select>
                <select name="type">
                    <option value="">全部类型</option>
                    <option value="help" <?= $type === 'help' ? 'selected' : '' ?>>居民求助</option>
                    <option value="suggest" <?= $type === 'suggest' ? 'selected' : '' ?>>意见建议</option>
                    <option value="lost" <?= $type === 'lost' ? 'selected' : '' ?>>失物招领</option>
                </select>
                <?php if ($riskEnabled): ?>
                <select name="risk_level">
                    <option value="">全部风险级别</option>
                    <option value="high" <?= $riskLevel === 'high' ? 'selected' : '' ?>>高风险</option>
                    <option value="medium" <?= $riskLevel === 'medium' ? 'selected' : '' ?>>中风险</option>
                    <option value="low" <?= $riskLevel === 'low' ? 'selected' : '' ?>>低风险</option>
                </select>
                <?php endif; ?>
                <input type="text" name="keyword" placeholder="搜索关键词..." value="<?= cleanInput($keyword) ?>">
                <button type="submit" class="btn btn-primary btn-sm">筛选</button>
                <a href="index.php" class="btn btn-secondary btn-sm">重置</a>
            </form>
        </div>

        <!-- 留言表格 -->
        <div class="admin-table-wrapper">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>类型</th>
                        <th>标题</th>
                        <th>昵称</th>
                        <?php if ($riskEnabled): ?><th>风险级别 / 优先级</th><?php endif; ?>
                        <th>状态</th>
                        <th>浏览</th>
                        <th>时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($messages)): ?>
                    <tr><td colspan="<?= $riskEnabled ? 9 : 8 ?>" class="text-center">暂无数据</td></tr>
                    <?php else: ?>
                    <?php foreach ($messages as $msg): ?>
                    <tr>
                        <td><?= $msg['id'] ?></td>
                        <td><span class="badge badge-<?= $msg['type'] ?>"><?= getTypeLabel($msg['type']) ?></span></td>
                        <td class="td-title" title="<?= cleanInput($msg['title']) ?>"><?= cleanInput(mb_substr($msg['title'], 0, 20)) ?></td>
                        <td><?= cleanInput($msg['nickname']) ?></td>
                        <?php if ($riskEnabled): ?>
                        <td class="td-risk">
                            <?= renderRiskLevelBadge($msg) ?>
                            <span class="risk-priority">P<?= intval($msg['risk_priority']) ?></span>
                        </td>
                        <?php endif; ?>
                        <td><span class="status-badge status-<?= getStatusClass($msg['status']) ?>"><?= getStatusLabel($msg['status']) ?></span></td>
                        <td><?= $msg['views'] ?></td>
                        <td class="td-time"><?= date('m-d H:i', strtotime($msg['created_at'])) ?></td>
                        <td class="td-actions">
                            <button class="btn btn-xs btn-info" onclick="viewMessage(<?= $msg['id'] ?>)">查看</button>
                            <?php if ($msg['status'] != 1): ?>
                            <button class="btn btn-xs btn-success" onclick="auditMessage(<?= $msg['id'] ?>, 1)">通过</button>
                            <?php endif; ?>
                            <?php if ($msg['status'] != 2): ?>
                            <button class="btn btn-xs btn-warning" onclick="auditMessage(<?= $msg['id'] ?>, 2)">拒绝</button>
                            <?php endif; ?>
                            <button class="btn btn-xs btn-danger" onclick="deleteMessage(<?= $msg['id'] ?>)">删除</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- 分页 -->
        <?php if ($totalPages > 1): ?>
        <?php
            $queryBase = 'status=' . urlencode($status) . '&type=' . urlencode($type)
                . ($riskEnabled ? '&risk_level=' . urlencode($riskLevel) : '')
                . ($isReviewQueue ? '&queue=review' : '')
                . '&keyword=' . urlencode($keyword);
        ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="index.php?page=<?= $page - 1 ?>&<?= $queryBase ?>" class="page-btn">上一页</a>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="index.php?page=<?= $i ?>&<?= $queryBase ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="index.php?page=<?= $page + 1 ?>&<?= $queryBase ?>" class="page-btn">下一页</a>
            <?php endif; ?>
            <span class="page-info">共 <?= $total ?> 条</span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 查看弹窗 -->
<div class="modal" id="viewModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>留言详情</h3>
            <button class="modal-close" onclick="closeModal()">&times;</button>
        </div>
        <div class="modal-body" id="modalBody">加载中...</div>
    </div>
</div>

<script>
function auditMessage(id, status) {
    const action = status === 1 ? '通过' : '拒绝';
    if (!confirm('确定要' + action + '这条留言吗？')) return;
    fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=audit&id=' + id + '&status=' + status
    })
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            alert('操作成功');
            location.reload();
        } else {
            alert(data.msg);
        }
    });
}

function deleteMessage(id) {
    if (!confirm('确定要删除这条留言吗？此操作不可恢复！')) return;
    fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=delete&id=' + id
    })
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            alert('删除成功');
            location.reload();
        } else {
            alert(data.msg);
        }
    });
}

function viewMessage(id) {
    document.getElementById('viewModal').style.display = 'flex';
    document.getElementById('modalBody').innerHTML = '加载中...';
    fetch('api.php?action=detail&id=' + id)
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            const d = data.data;
            let html = '<div class="detail-view">';
            if (d.risk) {
                const rk = d.risk;
                html += '<div class="risk-detail-box">';
                html += '<p><strong>风险分级：</strong>';
                if (rk.level) {
                    html += '<span class="risk-badge ' + rk.level_class + '">' + rk.level_text + '</span>';
                    html += ' <span class="text-muted">分值 ' + rk.score + ' · 处置优先级 P' + rk.priority + ' · ' + rk.queue + '</span>';
                } else {
                    html += '<span class="text-muted">未分级</span>';
                }
                if (rk.stale) html += ' <span class="risk-badge risk-stale">待重算</span>';
                html += '</p>';
                if (rk.labels && rk.labels.length) {
                    html += '<p><strong>风险标签：</strong> ';
                    rk.labels.forEach(l => { html += '<span class="risk-tag ' + l.class + '">' + l.text + '</span> '; });
                    html += '</p>';
                }
                const f = rk.factors || {};
                if (f && Object.keys(f).length) {
                    html += '<p class="text-muted risk-factors">分级因子：类型基础分按「' + d.type_label + '」，同内容重复发布 '
                        + (f.repeat_count || 1) + ' 次，历史被拒 ' + (f.reject_count || 0)
                        + ' 条，待处理举报 ' + (f.pending_reports || 0) + ' 条';
                    if (rk.rule_version) html += '；规则版本 v' + rk.rule_version;
                    html += '</p>';
                }
                html += '</div>';
            }
            html += '<p><strong>类型：</strong>' + d.type_label + '</p>';
            html += '<p><strong>标题：</strong>' + d.title + '</p>';
            html += '<p><strong>昵称：</strong>' + d.nickname + '</p>';
            html += '<p><strong>电话：</strong>' + (d.phone || '未填写') + '</p>';
            html += '<p><strong>内容：</strong></p><div class="detail-text">' + d.content + '</div>';
            if (d.image) html += '<p><strong>图片：</strong><br><img src="../' + d.image + '" style="max-width:100%;margin-top:8px;"></p>';
            html += '<p><strong>状态：</strong>' + d.status_label + '</p>';
            html += '<p><strong>浏览量：</strong>' + d.views + '</p>';
            html += '<p><strong>时间：</strong>' + d.created_at + '</p>';
            html += '</div>';
            document.getElementById('modalBody').innerHTML = html;
        } else {
            document.getElementById('modalBody').innerHTML = data.msg;
        }
    });
}

function recalculateRisk() {
    if (!confirm('按当前分级口径重新计算全部留言？')) return;
    const btn = event.target;
    if (btn) btn.disabled = true;
    fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=risk_recalculate'
    })
    .then(r => r.json())
    .then(data => {
        alert(data.msg);
        if (data.code === 0) location.reload();
        else if (btn) btn.disabled = false;
    })
    .catch(() => { if (btn) btn.disabled = false; });
}

function closeModal() {
    document.getElementById('viewModal').style.display = 'none';
}

document.getElementById('viewModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>
