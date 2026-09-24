<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/risk.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

$pageTitle = '后台管理 - 社区便民留言板';
$currentPage = 'admin';
$cssPath = '../assets/css/style.css';
$jsPath = '../assets/js/main.js';

$db = getDB();

// 列表加载时对待重算的待审记录按当前口径补算（失败保留待重算标记，不阻断列表）
riskGradePendingStale($db);

// 筛选参数
$status = $_GET['status'] ?? '';
$type = $_GET['type'] ?? '';
$risk = $_GET['risk'] ?? '';
$queue = $_GET['queue'] ?? '';
$keyword = trim($_GET['keyword'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$pageSize = 15;
$offset = ($page - 1) * $pageSize;

$where = "WHERE 1=1";
$params = [];

if ($status !== '' && in_array($status, ['0', '1', '2'])) {
    $where .= " AND m.status = ?";
    $params[] = intval($status);
}
if ($type && in_array($type, ['help', 'suggest', 'lost'])) {
    $where .= " AND m.type = ?";
    $params[] = $type;
}
if ($risk && in_array($risk, [RISK_LOW, RISK_MEDIUM, RISK_HIGH])) {
    $where .= " AND m.risk_level = ?";
    $params[] = $risk;
}
if ($queue === 'stale') {
    // 待重算（分级服务失败或口径变化后尚未补算）
    $where .= " AND m.risk_stale = 1";
} elseif ($queue === 'manual') {
    // 人工复核队列：待审 + 高风险
    $where .= " AND m.status = 0 AND m.risk_level = 'high'";
}
if ($keyword) {
    $where .= " AND (m.title LIKE ? OR m.content LIKE ? OR m.nickname LIKE ?)";
    $kw = "%$keyword%";
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
}

$countStmt = $db->prepare("SELECT COUNT(*) FROM messages m $where");
$countStmt->execute($params);
$total = $countStmt->fetchColumn();
$totalPages = ceil($total / $pageSize);

// 待审记录按处置优先级排列（高风险人工复核队列优先），其他状态按时间倒序
$isPendingOnly = ($status === '0' && $queue !== 'stale');
$orderBy = $isPendingOnly || $queue === 'manual'
    ? "m.risk_priority DESC, m.created_at DESC"
    : "m.created_at DESC";

$sql = "SELECT m.* FROM messages m $where ORDER BY $orderBy LIMIT $pageSize OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$messages = $stmt->fetchAll();

// 统计
$pendingCount = riskGetPendingCount($db);
$manualReviewCount = riskGetManualReviewCount($db);
$staleCount = riskGetStaleCount($db);

// 分页链接保留当前筛选条件
$pageQuery = http_build_query(array_filter([
    'status' => $status, 'type' => $type, 'risk' => $risk,
    'queue' => $queue, 'keyword' => $keyword,
]));

include __DIR__ . '/header.php';
?>

<div class="admin-container">
    <aside class="admin-sidebar">
        <div class="sidebar-header">
            <h3>📋 管理后台</h3>
        </div>
        <nav class="sidebar-nav">
            <a href="index.php" class="sidebar-link active">📝 留言管理</a>
            <a href="index.php?status=0" class="sidebar-link">⏳ 待审核 <?= $pendingCount > 0 ? "($pendingCount)" : '' ?></a>
            <a href="index.php?queue=manual" class="sidebar-link">🔎 人工复核队列 <?= $manualReviewCount > 0 ? "($manualReviewCount)" : '' ?></a>
            <a href="index.php?queue=stale" class="sidebar-link">🔄 待重算 <?= $staleCount > 0 ? "($staleCount)" : '' ?></a>
            <a href="risk_rules.php" class="sidebar-link">⚖️ 分级规则</a>
            <a href="reports.php" class="sidebar-link">🚩 举报管理</a>
            <?php $pendingReportCount = getPendingReportCount(); ?>
            <a href="reports.php?status=0" class="sidebar-link">⏳ 待处理举报 <?= $pendingReportCount > 0 ? "($pendingReportCount)" : '' ?></a>
            <a href="../index.php" class="sidebar-link" target="_blank">🌐 查看前台</a>
            <a href="logout.php" class="sidebar-link">🚪 退出登录</a>
        </nav>
    </aside>

    <div class="admin-main">
        <div class="admin-header">
            <h2>留言管理</h2>
            <span class="admin-user">👤 <?= cleanInput($_SESSION['admin_name']) ?></span>
        </div>

        <!-- 筛选栏 -->
        <div class="admin-filter">
            <form method="GET" class="filter-form">
                <input type="hidden" name="queue" value="<?= cleanInput($queue) ?>">
                <select name="status">
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
                <select name="risk">
                    <option value="">全部风险级别</option>
                    <option value="high" <?= $risk === 'high' ? 'selected' : '' ?>>高风险（人工复核）</option>
                    <option value="medium" <?= $risk === 'medium' ? 'selected' : '' ?>>中风险</option>
                    <option value="low" <?= $risk === 'low' ? 'selected' : '' ?>>低风险（普通处理）</option>
                </select>
                <input type="text" name="keyword" placeholder="搜索关键词..." value="<?= cleanInput($keyword) ?>">
                <button type="submit" class="btn btn-primary btn-sm">筛选</button>
                <a href="index.php" class="btn btn-secondary btn-sm">重置</a>
                <button type="button" class="btn btn-sm btn-warning" onclick="regradeAll()">全部重算</button>
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
                        <th>状态</th>
                        <th>风险级别</th>
                        <th>浏览</th>
                        <th>时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($messages)): ?>
                    <tr><td colspan="9" class="text-center">暂无数据</td></tr>
                    <?php else: ?>
                    <?php foreach ($messages as $msg): ?>
                    <tr>
                        <td><?= $msg['id'] ?></td>
                        <td><span class="badge badge-<?= $msg['type'] ?>"><?= getTypeLabel($msg['type']) ?></span></td>
                        <td class="td-title" title="<?= cleanInput($msg['title']) ?>"><?= cleanInput(mb_substr($msg['title'], 0, 20)) ?></td>
                        <td><?= cleanInput($msg['nickname']) ?></td>
                        <td><span class="status-badge status-<?= getStatusClass($msg['status']) ?>"><?= getStatusLabel($msg['status']) ?></span></td>
                        <td>
                            <span class="risk-badge <?= getRiskLevelClass($msg['risk_level']) ?>"><?= getRiskDisplayLabel($msg['risk_level'], $msg['risk_label']) ?></span>
                            <?php if ($msg['risk_stale']): ?>
                            <span class="risk-stale-tag" title="分级服务暂不可用或分级口径已变更，保留原级别等待重算">待重算</span>
                            <?php endif; ?>
                            <div class="risk-priority-text">优先级 <?= intval($msg['risk_priority']) ?></div>
                        </td>
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
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="index.php?page=<?= $page - 1 ?>&<?= $pageQuery ?>" class="page-btn">上一页</a>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="index.php?page=<?= $i ?>&<?= $pageQuery ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="index.php?page=<?= $page + 1 ?>&<?= $pageQuery ?>" class="page-btn">下一页</a>
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
            html += '<p><strong>类型：</strong>' + d.type_label + '</p>';
            html += '<p><strong>标题：</strong>' + d.title + '</p>';
            html += '<p><strong>昵称：</strong>' + d.nickname + '</p>';
            html += '<p><strong>电话：</strong>' + (d.phone || '未填写') + '</p>';
            html += '<p><strong>内容：</strong></p><div class="detail-text">' + d.content + '</div>';
            if (d.image) html += '<p><strong>图片：</strong><br><img src="../' + d.image + '" style="max-width:100%;margin-top:8px;"></p>';
            html += '<p><strong>状态：</strong>' + d.status_label + '</p>';
            html += '<hr style="margin:16px 0;border:none;border-top:1px solid #e5e7eb;">';
            html += '<p><strong>风险分级：</strong><span class="risk-badge ' + d.risk_level_class + '">' + d.risk_label_display + '</span>';
            if (d.risk_stale == 1) html += ' <span class="risk-stale-tag">待重算</span>';
            if (d.manual_review) html += ' <span class="risk-queue-tag">进入人工复核队列</span>';
            html += '</p>';
            html += '<p><strong>风险评分：</strong>' + (d.risk_score || 0) + '　<strong>处置优先级：</strong>' + (d.risk_priority || 0) + '</p>';
            const f = d.risk_factors || {};
            html += '<p class="text-muted" style="font-size:.85rem;">分级因子：'
                + f.history_days + '天内发布 ' + (f.repeat_count || 0) + ' 次、历史被拒 '
                + (f.rejected_count || 0) + ' 次、举报删除 ' + (f.reported_deleted_count || 0) + ' 次'
                + '（规则版本 v' + (d.risk_rule_version || 0) + '）</p>';
            html += '<p><button type="button" class="btn btn-sm btn-secondary" onclick="regradeOne(' + id + ')">按当前口径重新计算</button></p>';
            html += '<p><strong>浏览量：</strong>' + d.views + '</p>';
            html += '<p><strong>时间：</strong>' + d.created_at + '</p>';
            html += '</div>';
            document.getElementById('modalBody').innerHTML = html;
        } else {
            document.getElementById('modalBody').innerHTML = data.msg;
        }
    });
}

function regradeOne(id) {
    fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=risk_regrade&scope=one&id=' + id
    })
    .then(r => r.json())
    .then(data => {
        alert(data.msg);
        if (data.code === 0) {
            viewMessage(id);
            setTimeout(() => location.reload(), 800);
        }
    });
}

function regradeAll() {
    if (!confirm('将按当前生效的分级规则重新计算全部留言级别，确定继续？')) return;
    fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=risk_regrade&scope=all'
    })
    .then(r => r.json())
    .then(data => {
        alert(data.msg + (data.data ? '\n成功重算 ' + data.data.regraded + ' 条，待重算 ' + data.data.stale_count + ' 条' : ''));
        location.reload();
    });
}

function closeModal() {
    document.getElementById('viewModal').style.display = 'none';
}

document.getElementById('viewModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>
