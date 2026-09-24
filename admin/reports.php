<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/risk.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

$pageTitle = '举报管理 - 社区便民留言板';
$currentPage = 'admin';
$cssPath = '../assets/css/style.css';
$jsPath = '../assets/js/main.js';

$db = getDB();

$status = $_GET['status'] ?? '';
$reportType = $_GET['report_type'] ?? '';
$keyword = trim($_GET['keyword'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$pageSize = 15;
$offset = ($page - 1) * $pageSize;

$where = "WHERE 1=1";
$params = [];

if ($status !== '' && in_array($status, ['0', '1', '2', '3'])) {
    $where .= " AND r.status = ?";
    $params[] = intval($status);
}
if ($reportType && in_array($reportType, ['spam', 'abuse', 'illegal', 'porn', 'other'])) {
    $where .= " AND r.report_type = ?";
    $params[] = $reportType;
}
if ($keyword) {
    $where .= " AND (m.title LIKE ? OR m.content LIKE ? OR r.description LIKE ?)";
    $kw = "%$keyword%";
    $params[] = $kw;
    $params[] = $kw;
    $params[] = $kw;
}

$countStmt = $db->prepare("SELECT COUNT(*) FROM reports r LEFT JOIN messages m ON r.message_id = m.id $where");
$countStmt->execute($params);
$total = $countStmt->fetchColumn();
$totalPages = ceil($total / $pageSize);

$sql = "SELECT r.*, m.title as message_title, m.nickname as message_nickname, m.type as message_type, a.username as admin_name 
        FROM reports r 
        LEFT JOIN messages m ON r.message_id = m.id 
        LEFT JOIN admins a ON r.processed_by = a.id 
        $where 
        ORDER BY r.created_at DESC 
        LIMIT $pageSize OFFSET $offset";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$reports = $stmt->fetchAll();

$pendingCount = getPendingReportCount();
$totalReportCount = $db->query("SELECT COUNT(*) FROM reports")->fetchColumn();
$deletedCount = $db->query("SELECT COUNT(*) FROM reports WHERE status = 1")->fetchColumn();
$ignoredCount = $db->query("SELECT COUNT(*) FROM reports WHERE status = 2")->fetchColumn();
$messagePendingCount = riskGetPendingCount($db);
$manualReviewCount = riskGetManualReviewCount($db);
$staleCount = riskGetStaleCount($db);

include __DIR__ . '/header.php';
?>

<div class="admin-container">
    <aside class="admin-sidebar">
        <div class="sidebar-header">
            <h3>📋 管理后台</h3>
        </div>
        <nav class="sidebar-nav">
            <a href="index.php" class="sidebar-link">📝 留言管理</a>
            <a href="index.php?status=0" class="sidebar-link">⏳ 待审核 <?= $messagePendingCount > 0 ? "($messagePendingCount)" : '' ?></a>
            <a href="index.php?queue=manual" class="sidebar-link">🔎 人工复核队列 <?= $manualReviewCount > 0 ? "($manualReviewCount)" : '' ?></a>
            <a href="index.php?queue=stale" class="sidebar-link">🔄 待重算 <?= $staleCount > 0 ? "($staleCount)" : '' ?></a>
            <a href="risk_rules.php" class="sidebar-link">⚖️ 分级规则</a>
            <a href="reports.php" class="sidebar-link active">🚩 举报管理</a>
            <a href="reports.php?status=0" class="sidebar-link">⏳ 待处理 <?= $pendingCount > 0 ? "($pendingCount)" : '' ?></a>
            <a href="../index.php" class="sidebar-link" target="_blank">🌐 查看前台</a>
            <a href="logout.php" class="sidebar-link">🚪 退出登录</a>
        </nav>
    </aside>

    <div class="admin-main">
        <div class="admin-header">
            <h2>举报管理</h2>
            <span class="admin-user">👤 <?= cleanInput($_SESSION['admin_name']) ?></span>
        </div>

        <div class="stats-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom: 20px;">
            <div class="stat-card">
                <div class="stat-number"><?= $totalReportCount ?></div>
                <div class="stat-label">总举报数</div>
            </div>
            <div class="stat-card stat-help">
                <div class="stat-number"><?= $pendingCount ?></div>
                <div class="stat-label">待处理</div>
            </div>
            <div class="stat-card stat-suggest">
                <div class="stat-number"><?= $deletedCount ?></div>
                <div class="stat-label">已删除</div>
            </div>
            <div class="stat-card stat-lost">
                <div class="stat-number"><?= $ignoredCount ?></div>
                <div class="stat-label">已忽略</div>
            </div>
        </div>

        <div class="admin-filter">
            <form method="GET" class="filter-form">
                <select name="status">
                    <option value="">全部状态</option>
                    <option value="0" <?= $status === '0' ? 'selected' : '' ?>>待处理</option>
                    <option value="1" <?= $status === '1' ? 'selected' : '' ?>>已处理-已删除</option>
                    <option value="2" <?= $status === '2' ? 'selected' : '' ?>>已处理-已忽略</option>
                    <option value="3" <?= $status === '3' ? 'selected' : '' ?>>已驳回</option>
                </select>
                <select name="report_type">
                    <option value="">全部类型</option>
                    <option value="spam" <?= $reportType === 'spam' ? 'selected' : '' ?>>垃圾信息</option>
                    <option value="abuse" <?= $reportType === 'abuse' ? 'selected' : '' ?>>辱骂攻击</option>
                    <option value="illegal" <?= $reportType === 'illegal' ? 'selected' : '' ?>>违法违规</option>
                    <option value="porn" <?= $reportType === 'porn' ? 'selected' : '' ?>>色情低俗</option>
                    <option value="other" <?= $reportType === 'other' ? 'selected' : '' ?>>其他</option>
                </select>
                <input type="text" name="keyword" placeholder="搜索关键词..." value="<?= cleanInput($keyword) ?>">
                <button type="submit" class="btn btn-primary btn-sm">筛选</button>
                <a href="reports.php" class="btn btn-secondary btn-sm">重置</a>
            </form>
        </div>

        <div class="admin-table-wrapper">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>举报类型</th>
                        <th>被举报留言</th>
                        <th>留言作者</th>
                        <th>状态</th>
                        <th>举报时间</th>
                        <th>处理人</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reports)): ?>
                    <tr><td colspan="8" class="text-center">暂无数据</td></tr>
                    <?php else: ?>
                    <?php foreach ($reports as $r): ?>
                    <tr>
                        <td><?= $r['id'] ?></td>
                        <td><span class="badge badge-<?= $r['report_type'] ?>"><?= getReportTypeLabel($r['report_type']) ?></span></td>
                        <td class="td-title" title="<?= cleanInput($r['message_title'] ?? '留言已删除') ?>">
                            <?php if ($r['message_title']): ?>
                                <a href="../detail.php?id=<?= $r['message_id'] ?>" target="_blank"><?= cleanInput(mb_substr($r['message_title'], 0, 15)) ?></a>
                            <?php else: ?>
                                <span class="text-muted">留言已删除</span>
                            <?php endif; ?>
                        </td>
                        <td><?= cleanInput($r['message_nickname'] ?? '-') ?></td>
                        <td><span class="status-badge report-status-<?= getReportStatusClass($r['status']) ?>"><?= getReportStatusLabel($r['status']) ?></span></td>
                        <td class="td-time"><?= date('m-d H:i', strtotime($r['created_at'])) ?></td>
                        <td><?= $r['admin_name'] ? cleanInput($r['admin_name']) : '-' ?></td>
                        <td class="td-actions">
                            <button class="btn btn-xs btn-info" onclick="viewReport(<?= $r['id'] ?>)">查看</button>
                            <?php if ($r['status'] == 0): ?>
                                <button class="btn btn-xs btn-danger" onclick="processReport(<?= $r['id'] ?>, 1)">删除留言</button>
                                <button class="btn btn-xs btn-success" onclick="processReport(<?= $r['id'] ?>, 2)">忽略</button>
                                <button class="btn btn-xs btn-warning" onclick="processReport(<?= $r['id'] ?>, 3)">驳回</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="reports.php?page=<?= $page - 1 ?>&status=<?= $status ?>&report_type=<?= $reportType ?>&keyword=<?= urlencode($keyword) ?>" class="page-btn">上一页</a>
            <?php endif; ?>
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="reports.php?page=<?= $i ?>&status=<?= $status ?>&report_type=<?= $reportType ?>&keyword=<?= urlencode($keyword) ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
            <a href="reports.php?page=<?= $page + 1 ?>&status=<?= $status ?>&report_type=<?= $reportType ?>&keyword=<?= urlencode($keyword) ?>" class="page-btn">下一页</a>
            <?php endif; ?>
            <span class="page-info">共 <?= $total ?> 条</span>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="modal" id="reportViewModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>举报详情</h3>
            <button class="modal-close" onclick="closeReportViewModal()">&times;</button>
        </div>
        <div class="modal-body" id="reportViewBody">加载中...</div>
    </div>
</div>

<div class="modal" id="processNoteModal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <h3>处理备注 <span id="processNoteTitle"></span></h3>
            <button class="modal-close" onclick="closeProcessNoteModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label for="processNote">处理备注（可选）</label>
                <textarea id="processNote" rows="3" maxlength="500" placeholder="请输入处理备注..."></textarea>
            </div>
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="closeProcessNoteModal()">取消</button>
                <button type="button" class="btn btn-primary" onclick="confirmProcess()">确认处理</button>
            </div>
        </div>
    </div>
</div>

<script>
let pendingProcessId = null;
let pendingProcessStatus = null;

function viewReport(id) {
    document.getElementById('reportViewModal').style.display = 'flex';
    document.getElementById('reportViewBody').innerHTML = '加载中...';
    fetch('api.php?action=report_detail&id=' + id)
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            const d = data.data;
            let html = '<div class="detail-view">';
            html += '<p><strong>举报ID：</strong>' + d.id + '</p>';
            html += '<p><strong>举报类型：</strong><span class="badge badge-' + d.report_type + '">' + d.report_type_label + '</span></p>';
            html += '<p><strong>举报时间：</strong>' + d.created_at + '</p>';
            html += '<p><strong>举报状态：</strong><span class="status-badge report-status-' + d.status_class + '">' + d.status_label + '</span></p>';
            if (d.description) {
                html += '<p><strong>举报说明：</strong></p><div class="detail-text">' + d.description + '</div>';
            }
            html += '<hr style="margin: 16px 0; border: none; border-top: 1px solid #e5e7eb;">';
            html += '<h4 style="margin-bottom: 12px;">被举报留言信息</h4>';
            if (d.message_exists) {
                html += '<p><strong>留言标题：</strong>' + d.message_title + '</p>';
                html += '<p><strong>留言作者：</strong>' + d.message_nickname + '</p>';
                html += '<p><strong>留言类型：</strong>' + d.message_type_label + '</p>';
                html += '<p><strong>留言内容：</strong></p><div class="detail-text">' + d.message_content + '</div>';
                if (d.message_image) {
                    html += '<p><strong>留言图片：</strong><br><img src="../' + d.message_image + '" style="max-width:100%;margin-top:8px;"></p>';
                }
                html += '<p><a href="../detail.php?id=' + d.message_id + '" target="_blank" class="btn btn-sm btn-info">查看原留言</a></p>';
            } else {
                html += '<p class="text-muted">该留言已被删除</p>';
            }
            if (d.status > 0) {
                html += '<hr style="margin: 16px 0; border: none; border-top: 1px solid #e5e7eb;">';
                html += '<h4 style="margin-bottom: 12px;">处理信息</h4>';
                html += '<p><strong>处理人：</strong>' + (d.admin_name || '-') + '</p>';
                html += '<p><strong>处理时间：</strong>' + (d.processed_at || '-') + '</p>';
                if (d.process_note) {
                    html += '<p><strong>处理备注：</strong></p><div class="detail-text">' + d.process_note + '</div>';
                }
            }
            html += '</div>';
            document.getElementById('reportViewBody').innerHTML = html;
        } else {
            document.getElementById('reportViewBody').innerHTML = data.msg;
        }
    });
}

function closeReportViewModal() {
    document.getElementById('reportViewModal').style.display = 'none';
}

function processReport(id, status) {
    let actionText = '';
    if (status === 1) actionText = '删除留言并标记为已处理';
    else if (status === 2) actionText = '忽略此举报';
    else if (status === 3) actionText = '驳回此举报';

    if (!confirm('确定要' + actionText + '吗？')) return;

    pendingProcessId = id;
    pendingProcessStatus = status;

    let titleText = '';
    if (status === 1) titleText = '（删除留言）';
    else if (status === 2) titleText = '（忽略举报）';
    else if (status === 3) titleText = '（驳回举报）';

    document.getElementById('processNoteTitle').textContent = titleText;
    document.getElementById('processNote').value = '';
    document.getElementById('processNoteModal').style.display = 'flex';
}

function closeProcessNoteModal() {
    document.getElementById('processNoteModal').style.display = 'none';
    pendingProcessId = null;
    pendingProcessStatus = null;
}

function confirmProcess() {
    if (!pendingProcessId || !pendingProcessStatus) return;

    const note = document.getElementById('processNote').value;
    const formData = new FormData();
    formData.append('action', 'process_report');
    formData.append('id', pendingProcessId);
    formData.append('status', pendingProcessStatus);
    formData.append('note', note);

    fetch('api.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.code === 0) {
            alert('操作成功');
            closeProcessNoteModal();
            location.reload();
        } else {
            alert(data.msg);
        }
    });
}

document.getElementById('reportViewModal').addEventListener('click', function(e) {
    if (e.target === this) closeReportViewModal();
});

document.getElementById('processNoteModal').addEventListener('click', function(e) {
    if (e.target === this) closeProcessNoteModal();
});
</script>
