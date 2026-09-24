<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/risk.php';
require_once __DIR__ . '/../config/database.php';
requireAdmin();

$pageTitle = '分级规则 - 社区便民留言板';
$currentPage = 'admin';
$cssPath = '../assets/css/style.css';
$jsPath = '../assets/js/main.js';

$db = getDB();
$rules = riskActiveRules($db);
$config = $rules['config'];
$versions = riskListRuleVersions($db);

$pendingCount = riskGetPendingCount($db);
$manualReviewCount = riskGetManualReviewCount($db);
$staleCount = riskGetStaleCount($db);
$pendingReportCount = getPendingReportCount();

include __DIR__ . '/header.php';
?>

<div class="admin-container">
    <aside class="admin-sidebar">
        <div class="sidebar-header">
            <h3>📋 管理后台</h3>
        </div>
        <nav class="sidebar-nav">
            <a href="index.php" class="sidebar-link">📝 留言管理</a>
            <a href="index.php?status=0" class="sidebar-link">⏳ 待审核 <?= $pendingCount > 0 ? "($pendingCount)" : '' ?></a>
            <a href="index.php?queue=manual" class="sidebar-link">🔎 人工复核队列 <?= $manualReviewCount > 0 ? "($manualReviewCount)" : '' ?></a>
            <a href="index.php?queue=stale" class="sidebar-link">🔄 待重算 <?= $staleCount > 0 ? "($staleCount)" : '' ?></a>
            <a href="risk_rules.php" class="sidebar-link active">⚖️ 分级规则</a>
            <a href="reports.php" class="sidebar-link">🚩 举报管理</a>
            <a href="reports.php?status=0" class="sidebar-link">⏳ 待处理举报 <?= $pendingReportCount > 0 ? "($pendingReportCount)" : '' ?></a>
            <a href="../index.php" class="sidebar-link" target="_blank">🌐 查看前台</a>
            <a href="logout.php" class="sidebar-link">🚪 退出登录</a>
        </nav>
    </aside>

    <div class="admin-main">
        <div class="admin-header">
            <h2>风险分级规则</h2>
            <span class="admin-user">👤 <?= cleanInput($_SESSION['admin_name']) ?></span>
        </div>

        <div class="risk-rules-tip">
            当前生效版本：<strong>v<?= $rules['version'] ?></strong>。
            系统根据<strong>留言类型、重复发布次数、历史处置结果</strong>计算风险评分并给出风险标签与处置优先级；
            保存新口径后，<strong>已有记录（含待审记录）会按同一标准重算并重新排列</strong>：低风险进入普通处理，高风险进入人工复核队列。
        </div>

        <form id="rulesForm" class="risk-rules-form">
            <h3>① 留言类型基础分</h3>
            <div class="risk-form-grid">
                <div class="form-group">
                    <label>居民求助（help）</label>
                    <input type="number" name="base_help" min="0" value="<?= intval($config['baseScore']['help']) ?>">
                </div>
                <div class="form-group">
                    <label>意见建议（suggest）</label>
                    <input type="number" name="base_suggest" min="0" value="<?= intval($config['baseScore']['suggest']) ?>">
                </div>
                <div class="form-group">
                    <label>失物招领（lost）</label>
                    <input type="number" name="base_lost" min="0" value="<?= intval($config['baseScore']['lost']) ?>">
                </div>
            </div>

            <h3>② 重复发布次数加分</h3>
            <p class="text-muted" style="font-size:.85rem;margin-bottom:10px;">
                统计窗口内同一发布者（优先按手机号，无手机号按昵称）的发布次数，达到下列次数时加对应分数（取达到的最高档）。
            </p>
            <div id="tierList">
                <?php foreach ($config['repeatThresholds'] as $tier): ?>
                <div class="tier-row risk-form-grid">
                    <div class="form-group">
                        <label>发布次数达到</label>
                        <input type="number" name="tier_count[]" min="1" value="<?= intval($tier['count']) ?>">
                    </div>
                    <div class="form-group">
                        <label>加分</label>
                        <input type="number" name="tier_score[]" min="0" value="<?= intval($tier['score']) ?>">
                    </div>
                    <div class="form-group tier-actions">
                        <button type="button" class="btn btn-sm btn-danger" onclick="removeTier(this)">删除档位</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-sm btn-secondary" onclick="addTier()">＋ 增加档位</button>

            <h3>③ 历史处置结果加分</h3>
            <div class="risk-form-grid">
                <div class="form-group">
                    <label>历史被拒绝每次加分</label>
                    <input type="number" name="rejected_score" min="0" value="<?= intval($config['rejectedScore']) ?>">
                </div>
                <div class="form-group">
                    <label>举报后删除每次加分</label>
                    <input type="number" name="reported_deleted_score" min="0" value="<?= intval($config['reportedDeletedScore']) ?>">
                </div>
                <div class="form-group">
                    <label>统计窗口（天）</label>
                    <input type="number" name="history_days" min="1" value="<?= intval($config['historyDays']) ?>">
                </div>
            </div>

            <h3>④ 风险级别分数线与标签</h3>
            <div class="risk-form-grid">
                <div class="form-group">
                    <label>中风险分数线（≥）</label>
                    <input type="number" name="threshold_medium" min="1" value="<?= intval($config['thresholds']['medium']) ?>">
                </div>
                <div class="form-group">
                    <label>高风险分数线（≥，进入人工复核）</label>
                    <input type="number" name="threshold_high" min="1" value="<?= intval($config['thresholds']['high']) ?>">
                </div>
            </div>
            <div class="risk-form-grid">
                <div class="form-group">
                    <label>低风险标签</label>
                    <input type="text" name="label_low" maxlength="50" value="<?= cleanInput($config['labels']['low']) ?>">
                </div>
                <div class="form-group">
                    <label>中风险标签</label>
                    <input type="text" name="label_medium" maxlength="50" value="<?= cleanInput($config['labels']['medium']) ?>">
                </div>
                <div class="form-group">
                    <label>高风险标签</label>
                    <input type="text" name="label_high" maxlength="50" value="<?= cleanInput($config['labels']['high']) ?>">
                </div>
            </div>

            <h3>⑤ 调整说明</h3>
            <div class="form-group">
                <textarea name="remark" rows="2" maxlength="255" placeholder="本次调整分级口径的原因（可选）"></textarea>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">保存新口径并重算全部留言</button>
            </div>
        </form>

        <h3 style="margin:30px 0 12px;">历史规则版本</h3>
        <div class="admin-table-wrapper">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>版本</th>
                        <th>状态</th>
                        <th>调整说明</th>
                        <th>调整人</th>
                        <th>调整时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($versions)): ?>
                    <tr><td colspan="6" class="text-center">暂无规则版本</td></tr>
                    <?php else: ?>
                    <?php foreach ($versions as $v): ?>
                    <tr>
                        <td>v<?= intval($v['version']) ?></td>
                        <td>
                            <?php if ($v['is_active']): ?>
                            <span class="risk-badge risk-medium">当前生效</span>
                            <?php else: ?>
                            <span class="text-muted">已归档</span>
                            <?php endif; ?>
                        </td>
                        <td><?= cleanInput($v['remark'] ?? '-') ?></td>
                        <td><?= cleanInput($v['admin_name'] ?? '-') ?></td>
                        <td class="td-time"><?= $v['created_at'] ?></td>
                        <td class="td-actions">
                            <?php if (!$v['is_active']): ?>
                            <button type="button" class="btn btn-xs btn-warning" onclick="reactivate(<?= intval($v['id']) ?>)">重新启用</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
function addTier() {
    const row = document.createElement('div');
    row.className = 'tier-row risk-form-grid';
    row.innerHTML =
        '<div class="form-group"><label>发布次数达到</label><input type="number" name="tier_count[]" min="1" value="3"></div>' +
        '<div class="form-group"><label>加分</label><input type="number" name="tier_score[]" min="0" value="20"></div>' +
        '<div class="form-group tier-actions"><button type="button" class="btn btn-sm btn-danger" onclick="removeTier(this)">删除档位</button></div>';
    document.getElementById('tierList').appendChild(row);
}

function removeTier(btn) {
    const list = document.getElementById('tierList');
    if (list.querySelectorAll('.tier-row').length <= 1) {
        alert('至少保留一个档位');
        return;
    }
    btn.closest('.tier-row').remove();
}

document.getElementById('rulesForm').addEventListener('submit', function(e) {
    e.preventDefault();
    if (!confirm('保存后将生成新版本规则，并按同一口径重算全部留言（含待审记录），确定继续？')) return;
    const formData = new FormData(this);
    formData.append('action', 'risk_save_rules');
    fetch('api.php', {method: 'POST', body: formData})
    .then(r => r.json())
    .then(data => {
        alert(data.msg + (data.data ? '\n新版本：v' + data.data.version + '，已重算 ' + data.data.regraded + ' 条' : ''));
        if (data.code === 0) location.reload();
    });
});

function reactivate(ruleId) {
    if (!confirm('将以该历史版本的口径生成新规则并全部重算，确定继续？')) return;
    const formData = new FormData();
    formData.append('action', 'risk_reactivate');
    formData.append('rule_id', ruleId);
    fetch('api.php', {method: 'POST', body: formData})
    .then(r => r.json())
    .then(data => {
        alert(data.msg);
        if (data.code === 0) location.reload();
    });
}
</script>
