<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/risk_service.php';
requireAdmin();

$pageTitle = '分级口径设置 - 社区便民留言板';
$cssPath = '../assets/css/style.css';

$db = getDB();
$risk = new RiskService($db);
$riskEnabled = $risk->isAvailable();

$current = ['version' => 1, 'rules' => riskDefaultRules()];
$versions = [];
$staleCount = 0;
if ($riskEnabled) {
    $current = $risk->getCurrentRules();
    $versions = $risk->getAllVersions();
    $staleCount = $risk->getStaleCount();
}
$rules = $current['rules'];

$pendingCount = $db->query("SELECT COUNT(*) FROM messages WHERE status = 0")->fetchColumn();
$highRiskPendingCount = $riskEnabled ? $risk->getHighRiskPendingCount() : 0;

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
            <a href="index.php?queue=review" class="sidebar-link">🔴 人工复核队列 <?= $highRiskPendingCount > 0 ? "($highRiskPendingCount)" : '' ?></a>
            <a href="risk_rules.php" class="sidebar-link active">⚖️ 分级口径设置</a>
            <a href="reports.php" class="sidebar-link">🚩 举报管理</a>
            <a href="reports.php?status=0" class="sidebar-link">⏳ 待处理举报</a>
            <a href="../index.php" class="sidebar-link" target="_blank">🌐 查看前台</a>
            <a href="logout.php" class="sidebar-link">🚪 退出登录</a>
        </nav>
    </aside>

    <div class="admin-main">
        <div class="admin-header">
            <h2>⚖️ 留言风险分级口径</h2>
            <span class="admin-user">👤 <?= cleanInput($_SESSION['admin_name']) ?></span>
        </div>

        <?php if (!$riskEnabled): ?>
        <div class="risk-notice risk-notice-error">
            风险分级功能尚未安装：请先执行迁移脚本
            <code>database/migration_add_risk_grading.sql</code>，安装后本页即可用。审核列表不受影响。
        </div>
        <?php else: ?>

        <div class="risk-rule-intro">
            <p>系统根据 <strong>留言类型基础分 + 重复发布次数 + 历史处置结果 + 待处理举报数</strong>
            计算风险分值与处置优先级：低风险进入普通处理，高风险进入人工复核队列。</p>
            <p>调整口径后保存将生成新规则版本，并<strong>按同一口径重新计算全部已有记录</strong>；
            同类留言在任何查看条件下级别一致。当前生效版本：
            <span class="risk-tag risk-low">v<?= intval($current['version']) ?></span>
            <?php if ($staleCount > 0): ?>
            ，另有 <strong><?= $staleCount ?></strong> 条记录待重算。
            <?php endif; ?>
            </p>
        </div>

        <form id="riskRuleForm" class="risk-rule-form">
            <div class="risk-rule-section">
                <h3>① 留言类型基础分</h3>
                <div class="risk-form-row">
                    <label>居民求助 <input type="number" min="0" max="100" data-path="base_score.help" value="<?= intval($rules['base_score']['help']) ?>"></label>
                    <label>意见建议 <input type="number" min="0" max="100" data-path="base_score.suggest" value="<?= intval($rules['base_score']['suggest']) ?>"></label>
                    <label>失物招领 <input type="number" min="0" max="100" data-path="base_score.lost" value="<?= intval($rules['base_score']['lost']) ?>"></label>
                </div>
            </div>

            <div class="risk-rule-section">
                <h3>② 重复发布次数（同一身份、相同标题内容）</h3>
                <div class="risk-threshold-list" data-group="repeat_count_thresholds"></div>
                <label class="risk-inline">存在内容重复时附加分
                    <input type="number" min="0" max="100" data-path="duplicate_title_score" value="<?= intval($rules['duplicate_title_score']) ?>">
                </label>
            </div>

            <div class="risk-rule-section">
                <h3>③ 历史处置结果（历史被拒留言数）</h3>
                <div class="risk-threshold-list" data-group="reject_thresholds"></div>
            </div>

            <div class="risk-rule-section">
                <h3>④ 待处理举报数</h3>
                <div class="risk-threshold-list" data-group="report_thresholds"></div>
            </div>

            <div class="risk-rule-section">
                <h3>⑤ 风险级别分数线</h3>
                <div class="risk-form-row">
                    <label>≥ <input type="number" min="1" data-path="level_thresholds.medium" value="<?= intval($rules['level_thresholds']['medium']) ?>"> 分为中风险</label>
                    <label>≥ <input type="number" min="1" data-path="level_thresholds.high" value="<?= intval($rules['level_thresholds']['high']) ?>"> 分为高风险（进入人工复核队列）</label>
                </div>
            </div>

            <div class="risk-rule-section">
                <h3>⑥ 调整说明</h3>
                <input type="text" name="remark" maxlength="255" placeholder="例如：加强对重复发布的处置力度" style="width:100%;max-width:480px;padding:8px;">
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-primary">保存并按新标准重算全部记录</button>
                <button type="button" class="btn btn-secondary" onclick="recalculateAll()">仅手动重算（不修改口径）</button>
            </div>
        </form>

        <div class="risk-rule-section">
            <h3>历史口径版本</h3>
            <div class="admin-table-wrapper">
                <table class="admin-table">
                    <thead>
                        <tr><th>版本</th><th>调整说明</th><th>调整人</th><th>生效时间</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($versions)): ?>
                        <tr><td colspan="4" class="text-center">暂无版本记录</td></tr>
                        <?php else: foreach ($versions as $v): ?>
                        <tr>
                            <td>v<?= intval($v['version']) ?><?= intval($v['version']) === intval($current['version']) ? ' <span class="risk-tag risk-low">当前</span>' : '' ?></td>
                            <td><?= cleanInput($v['remark'] ?: '-') ?></td>
                            <td><?= !empty($v['admin_name']) ? cleanInput($v['admin_name']) : '-' ?></td>
                            <td class="td-time"><?= cleanInput($v['created_at']) ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// 当前规则（含阈值阶梯），页面初始化渲染
const INITIAL_RULES = <?= json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?>;

function renderThresholdLists() {
    document.querySelectorAll('.risk-threshold-list').forEach(box => {
        const group = box.dataset.group;
        box.innerHTML = '';
        (INITIAL_RULES[group] || []).forEach(item => {
            const row = document.createElement('div');
            row.className = 'risk-threshold-row';
            row.innerHTML =
                '次数/数量 ≥ <input type="number" min="1" data-field="gte" value="' + item.gte + '">' +
                ' 加 <input type="number" min="0" max="100" data-field="score" value="' + item.score + '"> 分' +
                ' <input type="text" data-field="label" value="' + item.label + '" readonly title="风险标签键固定，仅可调整阈值与分值">' +
                ' <button type="button" class="btn btn-xs btn-danger" onclick="this.parentNode.remove()">删除</button>';
            box.appendChild(row);
        });
    });
}

function collectRules() {
    const rules = JSON.parse(JSON.stringify(INITIAL_RULES));

    // 标量字段
    document.querySelectorAll('[data-path]').forEach(input => {
        const parts = input.dataset.path.split('.');
        let node = rules;
        for (let i = 0; i < parts.length - 1; i++) node = node[parts[i]];
        node[parts[parts.length - 1]] = parseInt(input.value, 10) || 0;
    });

    // 阈值阶梯
    document.querySelectorAll('.risk-threshold-list').forEach(box => {
        const group = box.dataset.group;
        rules[group] = [];
        box.querySelectorAll('.risk-threshold-row').forEach(row => {
            rules[group].push({
                gte: parseInt(row.querySelector('[data-field=gte]').value, 10) || 1,
                score: parseInt(row.querySelector('[data-field=score]').value, 10) || 0,
                label: row.querySelector('[data-field=label]').value.trim()
            });
        });
    });
    return rules;
}

document.getElementById('riskRuleForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const remark = this.querySelector('[name=remark]').value.trim();
    if (!remark && !confirm('未填写调整说明，确定保存新口径吗？')) return;
    if (!confirm('保存后将生成新版本，并按新标准重新计算全部留言，确定继续？')) return;

    const formData = new FormData();
    formData.append('action', 'risk_rules_save');
    formData.append('rules', JSON.stringify(collectRules()));
    formData.append('remark', remark);

    fetch('api.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            alert(data.msg);
            if (data.code === 0) location.reload();
        });
});

function recalculateAll() {
    if (!confirm('按当前口径重新计算全部留言的风险级别？')) return;
    fetch('api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=risk_recalculate'
    })
        .then(r => r.json())
        .then(data => {
            alert(data.msg);
            if (data.code === 0) location.reload();
        });
}

renderThresholdLists();
</script>
</body>
</html>
