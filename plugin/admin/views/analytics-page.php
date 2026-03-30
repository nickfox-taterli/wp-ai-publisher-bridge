<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$usage_repo = new APB_Usage_Repository();

// 检查表是否存在
if ( ! $usage_repo->table_exists() ) {
    // 触发一次表创建
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    APB_Activator::activate();
}

// 时间范围筛选
$range = isset( $_GET['range'] ) ? sanitize_text_field( $_GET['range'] ) : '7d';
$since = '';
switch ( $range ) {
    case '1d':  $since = date( 'Y-m-d H:i:s', strtotime( '-1 day' ) ); break;
    case '7d':  $since = date( 'Y-m-d H:i:s', strtotime( '-7 days' ) ); break;
    case '30d': $since = date( 'Y-m-d H:i:s', strtotime( '-30 days' ) ); break;
    case '90d': $since = date( 'Y-m-d H:i:s', strtotime( '-90 days' ) ); break;
    case 'all': $since = ''; break;
    default:    $since = date( 'Y-m-d H:i:s', strtotime( '-7 days' ) ); $range = '7d';
}

$summary    = $usage_repo->get_summary( $since );
$by_type    = $usage_repo->get_by_call_type( $since );
$by_model   = $usage_repo->get_by_model( $since );
$daily      = $usage_repo->get_daily_trend( $range === 'all' ? 365 : (int) str_replace( 'd', '', $range ) );
$recent     = $usage_repo->get_recent( 30 );

// 计算 TPS (用整体数据算)
$overall_tps = 0;
if ( $summary->total_tokens > 0 && $summary->avg_latency_ms > 0 ) {
    $overall_tps = round( $summary->total_tokens / ( $summary->total_calls * $summary->avg_latency_ms / 1000 ), 2 );
}

// call_type 中文标签
$call_type_labels = array(
    'article'       => '文章生成',
    'topic'         => '话题生成',
    'slug'          => 'Slug 生成',
    'image'         => '图像生成',
    'quality_check' => '质量检查',
    'revision'      => '内容修订',
    'other'         => '其他',
);

$range_labels = array(
    '1d'   => '最近 1 天',
    '7d'   => '最近 7 天',
    '30d'  => '最近 30 天',
    '90d'  => '最近 90 天',
    'all'  => '全部',
);
?>

<div class="wrap apb-analytics-wrap">
    <h1 class="apb-page-title">AI 用量统计</h1>

    <!-- 时间范围筛选 -->
    <div class="apb-range-bar">
        <?php foreach ( $range_labels as $key => $label ) :
            $url = add_query_arg( array( 'page' => 'apb-analytics', 'range' => $key ), admin_url( 'admin.php' ) );
            $active_class = ( $range === $key ) ? 'apb-range-active' : '';
        ?>
        <a href="<?php echo esc_url( $url ); ?>" class="apb-range-btn <?php echo esc_attr( $active_class ); ?>">
            <?php echo esc_html( $label ); ?>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if ( $summary->total_calls == 0 ) : ?>
    <!-- 无数据状态 - 显示错误提示 -->
    <div class="apb-empty-state apb-empty-error">
        <div class="apb-empty-icon">⚠️</div>
        <h2>暂无 AI 用量数据</h2>
        <p>在所选时间范围内未找到任何 AI 用量记录。Worker 完成任务后会自动回报 Token 用量、延迟等指标。</p>
        <p class="apb-empty-hint">请检查以下项目：</p>
        <ul class="apb-error-list">
            <li>确保 Worker 正在运行且版本支持用量回报功能</li>
            <li>确认 Worker 已成功连接到本站点</li>
            <li>尝试切换时间范围查看其他时段的数据</li>
        </ul>
    </div>
    <?php else : ?>

    <!-- 核心指标卡片 -->
    <div class="apb-metrics">
        <div class="apb-metric-card">
            <div class="apb-metric-header">
                <span class="apb-metric-icon">🔤</span>
                <span class="apb-metric-title">总 Token 消耗</span>
            </div>
            <div class="apb-metric-value"><?php echo number_format( (float) $summary->total_tokens ); ?></div>
            <div class="apb-metric-sub">
                输入 <?php echo number_format( (float) $summary->total_prompt_tokens ); ?> / 输出 <?php echo number_format( (float) $summary->total_completion_tokens ); ?>
            </div>
        </div>

        <div class="apb-metric-card">
            <div class="apb-metric-header">
                <span class="apb-metric-icon">📡</span>
                <span class="apb-metric-title">API 调用次数</span>
            </div>
            <div class="apb-metric-value"><?php echo number_format( (float) $summary->total_calls ); ?></div>
            <div class="apb-metric-sub">共 <?php echo count( $by_type ); ?> 种调用类型</div>
        </div>

        <div class="apb-metric-card">
            <div class="apb-metric-header">
                <span class="apb-metric-icon">⏱️</span>
                <span class="apb-metric-title">平均延迟</span>
            </div>
            <div class="apb-metric-value"><?php echo number_format( round( (float) $summary->avg_latency_ms ), 0 ); ?><span class="apb-metric-unit">ms</span></div>
            <div class="apb-metric-sub">
                最小 <?php echo number_format( (float) $summary->min_latency_ms ); ?>ms / 最大 <?php echo number_format( (float) $summary->max_latency_ms ); ?>ms
            </div>
        </div>

        <div class="apb-metric-card">
            <div class="apb-metric-header">
                <span class="apb-metric-icon">⚡</span>
                <span class="apb-metric-title">平均 TPS</span>
            </div>
            <div class="apb-metric-value"><?php echo number_format( (float) $summary->avg_tps, 2 ); ?><span class="apb-metric-unit">t/s</span></div>
            <div class="apb-metric-sub">Tokens per Second</div>
        </div>
    </div>

    <!-- 趋势图 -->
    <?php if ( ! empty( $daily ) && count( $daily ) > 1 ) : ?>
    <div class="apb-section">
        <h2 class="apb-section-title">📈 用量趋势</h2>
        <div class="apb-chart-container">
            <canvas id="apb-trend-chart" height="100"></canvas>
        </div>
    </div>
    <?php endif; ?>

    <div class="apb-columns">
        <!-- 按调用类型 -->
        <?php if ( ! empty( $by_type ) ) : ?>
        <div class="apb-section apb-col">
            <h2 class="apb-section-title">📂 按调用类型</h2>
            <table class="widefat apb-detail-table">
                <thead>
                    <tr>
                        <th>类型</th>
                        <th>调用次数</th>
                        <th>总 Token</th>
                        <th>平均延迟</th>
                        <th>平均 TPS</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $by_type as $row ) : ?>
                    <tr>
                        <td><span class="apb-badge apb-badge-type"><?php echo esc_html( $call_type_labels[ $row->call_type ] ?? $row->call_type ); ?></span></td>
                        <td><?php echo number_format( (float) $row->total_calls ); ?></td>
                        <td><?php echo number_format( (float) $row->total_tokens ); ?></td>
                        <td><?php echo number_format( round( (float) $row->avg_latency_ms ) ); ?>ms</td>
                        <td><?php echo number_format( (float) $row->avg_tps, 2 ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- 按模型 -->
        <?php if ( ! empty( $by_model ) ) : ?>
        <div class="apb-section apb-col">
            <h2 class="apb-section-title">🤖 按模型</h2>
            <table class="widefat apb-detail-table">
                <thead>
                    <tr>
                        <th>模型</th>
                        <th>调用次数</th>
                        <th>总 Token</th>
                        <th>平均延迟</th>
                        <th>平均 TPS</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $by_model as $row ) : ?>
                    <tr>
                        <td><code class="apb-model-name"><?php echo esc_html( $row->model ); ?></code></td>
                        <td><?php echo number_format( (float) $row->total_calls ); ?></td>
                        <td><?php echo number_format( (float) $row->total_tokens ); ?></td>
                        <td><?php echo number_format( round( (float) $row->avg_latency_ms ) ); ?>ms</td>
                        <td><?php echo number_format( (float) $row->avg_tps, 2 ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- 最近调用记录 -->
    <?php if ( ! empty( $recent ) ) : ?>
    <div class="apb-section">
        <h2 class="apb-section-title">🕐 最近调用记录</h2>
        <div class="apb-table-wrapper">
            <table class="widefat apb-detail-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>关联任务</th>
                        <th>调用类型</th>
                        <th>模型</th>
                        <th>Prompt</th>
                        <th>Completion</th>
                        <th>总 Token</th>
                        <th>延迟</th>
                        <th>TPS</th>
                        <th>时间</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $recent as $row ) : ?>
                    <tr>
                        <td><code>#<?php echo (int) $row->id; ?></code></td>
                        <td>
                            <?php if ( $row->job_id ) : ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ai-publisher-bridge&status=processing' ) ); ?>" title="<?php echo esc_attr( $row->job_topic ?? '' ); ?>">
                                    #<?php echo (int) $row->job_id; ?>
                                </a>
                            <?php else : ?>
                                <span class="apb-na">-</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="apb-badge apb-badge-type"><?php echo esc_html( $call_type_labels[ $row->call_type ] ?? $row->call_type ); ?></span></td>
                        <td><code class="apb-model-name"><?php echo esc_html( $row->model ); ?></code></td>
                        <td><?php echo number_format( (float) $row->prompt_tokens ); ?></td>
                        <td><?php echo number_format( (float) $row->completion_tokens ); ?></td>
                        <td><strong><?php echo number_format( (float) $row->total_tokens ); ?></strong></td>
                        <td><?php echo number_format( (float) $row->latency_ms ); ?>ms</td>
                        <td><?php echo number_format( (float) $row->tps, 2 ); ?></td>
                        <td class="apb-time"><?php echo esc_html( $row->created_at ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; /* has data */ ?>
</div>

<!-- Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var canvas = document.getElementById('apb-trend-chart');
    if (!canvas) return;

    var dailyData = <?php echo wp_json_encode( $daily ); ?>;

    var labels = dailyData.map(function(d) { return d.date; });
    var tokenData = dailyData.map(function(d) { return parseInt(d.total_tokens); });
    var latencyData = dailyData.map(function(d) { return Math.round(parseFloat(d.avg_latency_ms)); });
    var callsData = dailyData.map(function(d) { return parseInt(d.total_calls); });

    new Chart(canvas, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: '总 Token',
                    data: tokenData,
                    backgroundColor: 'rgba(99, 102, 241, 0.6)',
                    borderColor: 'rgba(99, 102, 241, 1)',
                    borderWidth: 1,
                    yAxisID: 'y',
                    order: 2
                },
                {
                    label: 'API 调用次数',
                    data: callsData,
                    backgroundColor: 'rgba(16, 185, 129, 0.6)',
                    borderColor: 'rgba(16, 185, 129, 1)',
                    borderWidth: 1,
                    yAxisID: 'y',
                    order: 3
                },
                {
                    label: '平均延迟 (ms)',
                    data: latencyData,
                    type: 'line',
                    borderColor: 'rgba(245, 158, 11, 1)',
                    backgroundColor: 'rgba(245, 158, 11, 0.1)',
                    borderWidth: 2,
                    pointRadius: 3,
                    fill: true,
                    yAxisID: 'y1',
                    order: 1
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: {
                    position: 'top'
                }
            },
            scales: {
                x: {
                    grid: { display: false }
                },
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    title: { display: true, text: 'Token / 调用次数' }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    title: { display: true, text: '延迟 (ms)' },
                    grid: { drawOnChartArea: false }
                }
            }
        }
    });
});
</script>

<style>
    .apb-analytics-wrap {
        max-width: 100%;
        box-sizing: border-box;
    }

    .apb-page-title {
        font-size: 22px;
        font-weight: 600;
        color: #1f2937;
        margin: 0 0 20px 0;
    }

    /* 时间范围筛选条 */
    .apb-range-bar {
        display: flex;
        gap: 8px;
        margin-bottom: 25px;
    }

    .apb-range-btn {
        padding: 8px 18px;
        border-radius: 8px;
        text-decoration: none;
        font-size: 13px;
        font-weight: 500;
        background: #fff;
        color: #6b7280;
        border: 1px solid #e5e7eb;
    }

    .apb-range-btn:hover {
        border-color: #667eea;
        color: #667eea;
        background: #f5f3ff;
    }

    .apb-range-btn.apb-range-active {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: #fff;
        border-color: transparent;
        box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
    }

    /* 核心指标卡片 */
    .apb-metrics {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 20px;
        margin-bottom: 25px;
    }

    .apb-metric-card {
        background: #fff;
        border-radius: 12px;
        padding: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        border: 1px solid #e5e7eb;
    }

    .apb-metric-card:hover {
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    }

    .apb-metric-header {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 12px;
    }

    .apb-metric-icon {
        font-size: 20px;
    }

    .apb-metric-title {
        font-size: 13px;
        color: #6b7280;
        font-weight: 500;
    }

    .apb-metric-value {
        font-size: 32px;
        font-weight: 700;
        color: #1f2937;
        line-height: 1;
        margin-bottom: 8px;
    }

    .apb-metric-unit {
        font-size: 16px;
        color: #9ca3af;
        font-weight: 400;
    }

    .apb-metric-sub {
        font-size: 12px;
        color: #9ca3af;
    }

    /* 区块 */
    .apb-section {
        background: #fff;
        border-radius: 12px;
        padding: 24px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        border: 1px solid #e5e7eb;
        margin-bottom: 25px;
    }

    .apb-section-title {
        font-size: 16px;
        font-weight: 600;
        color: #1f2937;
        margin: 0 0 16px 0;
    }

    /* 双栏布局 */
    .apb-columns {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
        gap: 25px;
    }

    .apb-col {
        margin-bottom: 0;
    }

    /* 图表容器 */
    .apb-chart-container {
        position: relative;
        max-height: 350px;
    }

    /* 表格 */
    .apb-table-wrapper {
        overflow-x: auto;
    }

    .apb-detail-table {
        border: none;
    }

    .apb-detail-table thead {
        background: #f3f4f6;
    }

    .apb-detail-table thead th {
        font-weight: 600;
        color: #374151;
        padding: 12px 15px;
        border-bottom: 2px solid #e5e7eb;
        font-size: 13px;
        white-space: nowrap;
    }

    .apb-detail-table tbody td {
        padding: 10px 15px;
        vertical-align: middle;
        border-bottom: 1px solid #f3f4f6;
        font-size: 13px;
    }

    .apb-detail-table tbody tr:hover {
        background: #f9fafb;
    }

    /* 徽章 */
    .apb-badge-type {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 500;
        background: #e0e7ff;
        color: #4338ca;
    }

    .apb-model-name {
        font-size: 12px;
        background: #f3f4f6;
        padding: 2px 8px;
        border-radius: 4px;
        color: #4b5563;
    }

    .apb-time {
        font-size: 12px;
        color: #9ca3af;
        white-space: nowrap;
    }

    .apb-na {
        color: #9ca3af;
        font-size: 13px;
    }

    /* 空状态 - 错误提示样式 */
    .apb-empty-state {
        border-radius: 12px;
        padding: 50px 30px;
        text-align: center;
    }

    .apb-empty-state.apb-empty-error {
        background: #fef2f2;
        border: 2px solid #fca5a5;
        box-shadow: 0 2px 12px rgba(239, 68, 68, 0.1);
    }

    .apb-empty-icon {
        font-size: 56px;
        margin-bottom: 16px;
    }

    .apb-empty-state h2 {
        font-size: 20px;
        color: #991b1b;
        margin: 0 0 12px 0;
    }

    .apb-empty-state p {
        font-size: 14px;
        color: #7f1d1d;
        margin: 0 0 6px 0;
    }

    .apb-empty-hint {
        font-size: 14px;
        color: #b91c1c;
        font-weight: 600;
        margin-top: 14px !important;
        margin-bottom: 8px !important;
    }

    .apb-error-list {
        display: inline-block;
        text-align: left;
        list-style: disc;
        margin: 10px 0 0 0;
        padding-left: 22px;
    }

    .apb-error-list li {
        font-size: 13px;
        color: #7f1d1d;
        margin-bottom: 6px;
        line-height: 1.5;
    }

    @media (max-width: 782px) {
        .apb-metrics {
            grid-template-columns: repeat(2, 1fr);
        }

        .apb-columns {
            grid-template-columns: 1fr;
        }

        .apb-range-bar {
            flex-wrap: wrap;
        }
    }
</style>
