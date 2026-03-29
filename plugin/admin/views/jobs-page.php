<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$repo = new APB_Job_Repository();

if ( isset( $_GET['apb_notice'] ) ) {
    $msg = '';
    $cleared_count = (int) ( $_GET['count'] ?? 0 );
    switch ( $_GET['apb_notice'] ) {
        case 'job_deleted':          $msg = '任务已删除.'; break;
        case 'job_reset':            $msg = '任务已重置为待处理.'; break;
        case 'jobs_cleared_all':     $msg = sprintf( '已清空全部记录(共 %d 条).', $cleared_count ); break;
        case 'jobs_cleared_failed':  $msg = sprintf( '已清空失败记录(共 %d 条).', $cleared_count ); break;
        case 'jobs_cleared_orphaned':$msg = sprintf( '已清空文章不存在的记录(共 %d 条).', $cleared_count ); break;
        case 'jobs_clear_invalid':   $msg = '无效的清空操作.'; break;
    }
    if ( $msg ) {
        printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $msg ) );
    }
}

$status_filter = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';
$paged         = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$per_page      = 20;
$offset        = ( $paged - 1 ) * $per_page;

$jobs        = $repo->list( $status_filter, $per_page, $offset );
$total       = $repo->count( $status_filter );
$total_pages = (int) ceil( $total / $per_page );

$statuses = array( '', 'pending', 'processing', 'completed', 'published', 'failed' );

$status_labels = array(
    'pending'    => '待处理',
    'processing' => '处理中',
    'completed'  => '已完成',
    'published'  => '已发布',
    'failed'     => '失败',
);

$status_icons = array(
    'pending'    => '⏳',
    'processing' => '⚙️',
    'completed'  => '✅',
    'published'  => '📰',
    'failed'     => '❌',
);

$status_colors = array(
    'pending'    => 'status-pending',
    'processing' => 'status-processing',
    'completed'  => 'status-completed',
    'published'  => 'status-published',
    'failed'     => 'status-failed',
);
?>

<div class="wrap apb-jobs-wrap">
    <h1 class="apb-page-title">📋 任务列表</h1>

    <div class="apb-stats">
        <?php foreach ( array( 'pending', 'processing', 'completed', 'published', 'failed' ) as $st ) : 
            $count = $repo->count( $st );
            $active_class = ( $status_filter === $st ) ? 'active' : '';
            $url = add_query_arg( array( 'page' => 'ai-publisher-bridge', 'status' => $st ), admin_url( 'admin.php' ) );
        ?>
        <a href="<?php echo esc_url( $url ); ?>" class="apb-stat-card <?php echo esc_attr( $active_class . ' ' . $status_colors[$st] ); ?>">
            <span class="apb-stat-icon"><?php echo $status_icons[$st]; ?></span>
            <span class="apb-stat-count"><?php echo (int) $count; ?></span>
            <span class="apb-stat-label"><?php echo esc_html( $status_labels[$st] ); ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <div class="apb-toolbar">
        <div class="apb-filter">
            <form method="get" class="apb-filter-form">
                <input type="hidden" name="page" value="ai-publisher-bridge" />
                <label for="apb_status_filter">状态筛选:</label>
                <select id="apb_status_filter" name="status">
                    <option value="" <?php selected( $status_filter, '' ); ?>>📋 全部</option>
                    <?php foreach ( $statuses as $s ) : ?>
                        <?php if ( $s === '' ) continue; ?>
                        <option value="<?php echo esc_attr( $s ); ?>" <?php selected( $status_filter, $s ); ?>>
                            <?php echo $status_icons[$s] . ' ' . esc_html( $status_labels[ $s ] ?? ucfirst( $s ) ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php submit_button( '筛选', 'secondary', '', false, array( 'class' => 'apb-btn-filter' ) ); ?>
            </form>
        </div>

        <div class="apb-bulk-actions">
            <span class="apb-bulk-label">批量操作:</span>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="apb-bulk-clear-form">
                <input type="hidden" name="action" value="apb_bulk_clear_jobs" />
                <?php wp_nonce_field( 'apb_bulk_clear_jobs', 'apb_bulk_nonce' ); ?>
                <input type="hidden" name="clear_mode" id="apb_clear_mode" value="" />
                <button type="button" class="apb-btn-bulk apb-btn-danger" onclick="apbBulkClear('all')">🗑️ 清空全部</button>
                <button type="button" class="apb-btn-bulk apb-btn-warning" onclick="apbBulkClear('failed')">🧹 清空失败</button>
                <button type="button" class="apb-btn-bulk apb-btn-info" onclick="apbBulkClear('orphaned')">🧹 清理孤儿</button>
            </form>
        </div>
    </div>

    <script>
    function apbBulkClear(mode) {
        var labels = { all: '全部记录', failed: '失败记录', orphaned: '文章不存在的记录' };
        if (confirm('⚠️ 确定要清空' + labels[mode] + '吗?\n\n此操作不可撤销!')) {
            document.getElementById('apb_clear_mode').value = mode;
            document.getElementById('apb-bulk-clear-form').submit();
        }
    }
    </script>

    <div class="apb-table-section">
        <div class="apb-table-header">
            <span class="apb-total">共 <strong><?php echo (int) $total; ?></strong> 个任务</span>
            <?php if ( $status_filter ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ai-publisher-bridge' ) ); ?>" class="apb-clear-filter">清除筛选</a>
            <?php endif; ?>
        </div>

        <table class="widefat apb-jobs-table">
            <thead>
                <tr>
                    <th class="col-id">ID</th>
                    <th class="col-topic">主题</th>
                    <th class="col-category">分类</th>
                    <th class="col-status">状态</th>
                    <th class="col-post">文章</th>
                    <th class="col-created">创建时间</th>
                    <th class="col-updated">更新时间</th>
                    <th class="col-error">错误信息</th>
                    <th class="col-action">操作</th>
                </tr>
            </thead>
            <tbody>
            <?php if ( empty( $jobs ) ) : ?>
                <tr>
                    <td colspan="9" class="apb-empty">
                        <div class="apb-empty-content">
                            <span class="apb-empty-icon">📭</span>
                            <p>暂无任务</p>
                            <span class="apb-empty-hint">任务由 AI 通过 API 自动创建</span>
                        </div>
                    </td>
                </tr>
            <?php else : ?>
                <?php foreach ( $jobs as $job ) : ?>
                    <tr class="apb-job-row status-<?php echo esc_attr( $job->status ); ?>">
                        <td class="col-id" data-label="ID"><code>#<?php echo (int) $job->id; ?></code></td>
                        <td class="col-topic" data-label="主题">
                            <span class="apb-topic-text" title="<?php echo esc_attr( $job->topic ); ?>">
                                <?php echo esc_html( mb_substr( $job->topic, 0, 100 ) ); ?><?php echo mb_strlen( $job->topic ) > 100 ? '...' : ''; ?>
                            </span>
                        </td>
                        <td class="col-category" data-label="分类">
                            <?php if ( ! empty( $job->category_id ) ) : 
                                $cat = get_category( (int) $job->category_id );
                                if ( $cat && ! is_wp_error( $cat ) ) : ?>
                                    <span class="apb-badge apb-badge-cat"><?php echo esc_html( $cat->name ); ?></span>
                                <?php else : ?>
                                    <span class="apb-badge apb-badge-na">-</span>
                                <?php endif;
                            else : ?>
                                <span class="apb-badge apb-badge-default">默认</span>
                            <?php endif; ?>
                        </td>
                        <td class="col-status" data-label="状态">
                            <span class="apb-status-badge <?php echo esc_attr( $status_colors[$job->status] ?? 'status-pending' ); ?>">
                                <span class="apb-status-dot"></span>
                                <?php echo $status_icons[$job->status] . ' ' . esc_html( $status_labels[$job->status] ?? $job->status ); ?>
                            </span>
                        </td>
                        <td class="col-post" data-label="文章">
                            <?php if ( $job->wp_post_id ) : ?>
                                <a href="<?php echo esc_url( get_edit_post_link( (int) $job->wp_post_id ) ); ?>" target="_blank" class="apb-post-link">
                                    📝 <?php echo (int) $job->wp_post_id; ?>
                                </a>
                            <?php else : ?>
                                <span class="apb-na">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="col-created" data-label="创建时间"><?php echo esc_html( $job->created_at ); ?></td>
                        <td class="col-updated" data-label="更新时间"><?php echo esc_html( $job->updated_at ); ?></td>
                        <td class="col-error" data-label="错误信息">
                            <?php if ( $job->error_message ) : ?>
                                <span class="apb-error-text" title="<?php echo esc_attr( $job->error_message ); ?>">
                                    ⚠️ <?php echo esc_html( mb_substr( $job->error_message, 0, 50 ) ); ?><?php echo mb_strlen( $job->error_message ) > 50 ? '...' : ''; ?>
                                </span>
                            <?php else : ?>
                                <span class="apb-na">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="col-action" data-label="操作">
                            <div class="apb-actions">
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('确定要删除此任务吗?');">
                                    <input type="hidden" name="action" value="apb_delete_job" />
                                    <input type="hidden" name="job_id" value="<?php echo (int) $job->id; ?>" />
                                    <?php wp_nonce_field( 'apb_delete_job_' . $job->id, 'apb_nonce' ); ?>
                                    <button type="submit" class="apb-btn-action apb-btn-delete" title="删除">🗑️</button>
                                </form>

                                <?php if ( $job->status === 'failed' ) : ?>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                    <input type="hidden" name="action" value="apb_reset_job" />
                                    <input type="hidden" name="job_id" value="<?php echo (int) $job->id; ?>" />
                                    <?php wp_nonce_field( 'apb_reset_job_' . $job->id, 'apb_nonce' ); ?>
                                    <button type="submit" class="apb-btn-action apb-btn-reset" title="重置">🔄</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <?php if ( $total_pages > 1 ) : ?>
        <div class="apb-pagination">
            <?php for ( $i = 1; $i <= $total_pages; $i++ ) : 
                $url = add_query_arg( array(
                    'page'   => 'ai-publisher-bridge',
                    'status' => $status_filter,
                    'paged'  => $i,
                ), admin_url( 'admin.php' ) );
            ?>
                <?php if ( $i === $paged ) : ?>
                    <span class="apb-page-btn apb-page-current"><?php echo $i; ?></span>
                <?php else : ?>
                    <a href="<?php echo esc_url( $url ); ?>" class="apb-page-btn"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php endfor; ?>
        </div>
        <?php endif; ?>
    </div>

    <style>
        .apb-jobs-wrap {
            max-width: 100%;
            box-sizing: border-box;
        }
        
        .apb-page-title {
            font-size: 22px;
            font-weight: 600;
            color: #1f2937;
            margin: 0 0 20px 0;
        }
        
        .apb-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .apb-stat-card {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            text-decoration: none;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border: 2px solid transparent;
            transition: all 0.2s;
        }
        
        .apb-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .apb-stat-card.active {
            border-color: currentColor;
        }
        
        .apb-stat-card.status-pending { color: #6b7280; }
        .apb-stat-card.status-processing { color: #f59e0b; }
        .apb-stat-card.status-completed { color: #10b981; }
        .apb-stat-card.status-published { color: #059669; }
        .apb-stat-card.status-failed { color: #ef4444; }
        
        .apb-stat-icon {
            font-size: 28px;
            display: block;
            margin-bottom: 8px;
        }
        
        .apb-stat-count {
            display: block;
            font-size: 32px;
            font-weight: 700;
            color: #1f2937;
            line-height: 1;
        }
        
        .apb-stat-label {
            display: block;
            font-size: 13px;
            color: #6b7280;
            margin-top: 5px;
        }
        
        .apb-toolbar {
            background: #fff;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border: 1px solid #e5e7eb;
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            align-items: center;
            justify-content: space-between;
        }
        
        .apb-filter-form {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .apb-filter-form label {
            font-weight: 500;
            color: #374151;
        }
        
        .apb-filter-form select {
            border-radius: 6px;
            border: 1px solid #d1d5db;
            padding: 6px 12px;
            min-width: 150px;
        }
        
        .apb-btn-filter {
            margin-left: 5px !important;
        }
        
        .apb-bulk-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .apb-bulk-label {
            color: #6b7280;
            font-size: 13px;
        }
        
        .apb-btn-bulk {
            padding: 8px 16px;
            border-radius: 6px;
            border: none;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
            font-weight: 500;
        }
        
        .apb-btn-bulk:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        
        .apb-btn-danger {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .apb-btn-danger:hover {
            background: #fecaca;
        }
        
        .apb-btn-warning {
            background: #fef3c7;
            color: #d97706;
        }
        
        .apb-btn-warning:hover {
            background: #fde68a;
        }
        
        .apb-btn-info {
            background: #dbeafe;
            color: #2563eb;
        }
        
        .apb-btn-info:hover {
            background: #bfdbfe;
        }
        
        .apb-table-section {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border: 1px solid #e5e7eb;
            overflow: hidden;
        }
        
        .apb-table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            background: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .apb-total {
            color: #6b7280;
            font-size: 14px;
        }
        
        .apb-total strong {
            color: #1f2937;
            font-size: 18px;
        }
        
        .apb-clear-filter {
            color: #667eea;
            text-decoration: none;
            font-size: 13px;
        }
        
        .apb-clear-filter:hover {
            text-decoration: underline;
        }
        
        .apb-jobs-table {
            border: none;
        }
        
        .apb-jobs-table thead {
            background: #f3f4f6;
        }
        
        .apb-jobs-table thead th {
            font-weight: 600;
            color: #374151;
            padding: 15px;
            border-bottom: 2px solid #e5e7eb;
            font-size: 13px;
        }
        
        .apb-jobs-table tbody td {
            padding: 15px;
            vertical-align: middle;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .apb-jobs-table tbody tr:hover {
            background: #f9fafb;
        }
        
        .apb-jobs-table tbody tr:last-child td {
            border-bottom: none;
        }
        
        .col-id { width: 60px; }
        .col-topic { min-width: 250px; }
        .col-category { width: 120px; }
        .col-status { width: 110px; }
        .col-post { width: 80px; }
        .col-created { width: 150px; }
        .col-updated { width: 150px; }
        .col-error { min-width: 150px; }
        .col-action { width: 100px; }
        
        .apb-empty {
            text-align: center;
            padding: 60px 20px !important;
        }
        
        .apb-empty-content {
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .apb-empty-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }
        
        .apb-empty p {
            font-size: 18px;
            color: #374151;
            margin: 0 0 5px 0;
            font-weight: 500;
        }
        
        .apb-empty-hint {
            color: #9ca3af;
            font-size: 13px;
        }
        
        .apb-topic-text {
            color: #1f2937;
            line-height: 1.5;
        }
        
        .apb-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .apb-badge-cat {
            background: #e0e7ff;
            color: #4338ca;
        }
        
        .apb-badge-default {
            background: #f3f4f6;
            color: #9ca3af;
        }
        
        .apb-badge-na {
            background: #f3f4f6;
            color: #9ca3af;
        }
        
        .apb-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .apb-status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        
        .apb-status-badge.status-pending {
            background: #f3f4f6;
            color: #6b7280;
        }
        .apb-status-badge.status-pending .apb-status-dot { background: #9ca3af; }
        
        .apb-status-badge.status-processing {
            background: #fef3c7;
            color: #d97706;
        }
        .apb-status-badge.status-processing .apb-status-dot { background: #f59e0b; }
        
        .apb-status-badge.status-completed {
            background: #d1fae5;
            color: #059669;
        }
        .apb-status-badge.status-completed .apb-status-dot { background: #10b981; }
        
        .apb-status-badge.status-published {
            background: #dbeafe;
            color: #2563eb;
        }
        .apb-status-badge.status-published .apb-status-dot { background: #3b82f6; }
        
        .apb-status-badge.status-failed {
            background: #fee2e2;
            color: #dc2626;
        }
        .apb-status-badge.status-failed .apb-status-dot { background: #ef4444; }
        
        .apb-post-link {
            color: #667eea;
            text-decoration: none;
            font-size: 13px;
        }
        
        .apb-post-link:hover {
            text-decoration: underline;
        }
        
        .apb-na {
            color: #9ca3af;
            font-size: 13px;
        }
        
        .apb-error-text {
            color: #dc2626;
            font-size: 13px;
        }
        
        .apb-actions {
            display: flex;
            gap: 8px;
        }
        
        .apb-btn-action {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .apb-btn-action:hover {
            transform: scale(1.1);
        }
        
        .apb-btn-delete {
            background: #fee2e2;
        }
        
        .apb-btn-delete:hover {
            background: #fecaca;
        }
        
        .apb-btn-reset {
            background: #dbeafe;
        }
        
        .apb-btn-reset:hover {
            background: #bfdbfe;
        }
        
        .apb-pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            padding: 20px;
            border-top: 1px solid #e5e7eb;
            background: #f9fafb;
        }
        
        .apb-page-btn {
            min-width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .apb-page-btn:not(.apb-page-current) {
            background: #fff;
            color: #374151;
            border: 1px solid #d1d5db;
        }
        
        .apb-page-btn:not(.apb-page-current):hover {
            background: #f3f4f6;
            border-color: #9ca3af;
        }
        
        .apb-page-current {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        @media (max-width: 1100px) {
            .apb-table-section {
                background: transparent;
                border: none;
                box-shadow: none;
                overflow: visible;
            }

            .apb-jobs-table thead {
                display: none;
            }

            .apb-jobs-table,
            .apb-jobs-table tbody {
                display: block;
                width: 100%;
            }

            .apb-jobs-table {
                border: none;
            }

            .apb-jobs-table tr:not(.apb-job-row) {
                display: block;
            }

            .apb-jobs-table tr:not(.apb-job-row) td {
                display: block;
                width: 100%;
            }

            .apb-jobs-table tr.apb-job-row {
                display: flex;
                flex-wrap: wrap;
                background: #fff;
                border: 1px solid #e5e7eb;
                border-radius: 12px;
                margin-bottom: 12px;
                box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            }

            .apb-jobs-table tr.apb-job-row td {
                border: none !important;
                box-sizing: border-box;
            }

            .apb-jobs-table td.col-id {
                order: 1;
                flex: 0 0 auto;
                background: #f9fafb;
                padding: 12px 4px 12px 16px;
                border-bottom: 1px solid #e5e7eb;
                display: flex;
                align-items: center;
            }

            .apb-jobs-table td.col-status {
                order: 2;
                flex: 1 1 auto;
                background: #f9fafb;
                padding: 12px 4px;
                border-bottom: 1px solid #e5e7eb;
                display: flex;
                align-items: center;
            }

            .apb-jobs-table td.col-action {
                order: 3;
                flex: 0 0 auto;
                background: #f9fafb;
                padding: 12px 16px 12px 4px;
                border-bottom: 1px solid #e5e7eb;
                display: flex;
                align-items: center;
            }

            .apb-jobs-table td.col-id::before,
            .apb-jobs-table td.col-status::before,
            .apb-jobs-table td.col-action::before {
                display: none;
            }

            .apb-jobs-table td.col-topic {
                order: 4;
                flex: 0 0 100%;
                padding: 12px 16px 4px;
                display: flex;
                align-items: flex-start;
                gap: 6px;
            }

            .apb-jobs-table td.col-category {
                order: 5;
                flex: 0 0 50%;
                padding: 4px 16px;
                display: flex;
                align-items: center;
                gap: 6px;
            }

            .apb-jobs-table td.col-post {
                order: 6;
                flex: 0 0 50%;
                padding: 4px 16px;
                display: flex;
                align-items: center;
                gap: 6px;
            }

            .apb-jobs-table td.col-created {
                order: 7;
                flex: 0 0 50%;
                padding: 4px 16px;
                display: flex;
                align-items: center;
                gap: 6px;
            }

            .apb-jobs-table td.col-updated {
                order: 8;
                flex: 0 0 50%;
                padding: 4px 16px;
                display: flex;
                align-items: center;
                gap: 6px;
            }

            .apb-jobs-table td.col-error {
                order: 9;
                flex: 0 0 100%;
                padding: 4px 16px 12px;
                display: flex;
                align-items: center;
                gap: 6px;
            }

            .apb-jobs-table td:not(.col-id):not(.col-status):not(.col-action)::before {
                content: attr(data-label) ":";
                font-weight: 500;
                color: #6b7280;
                font-size: 13px;
                white-space: nowrap;
                flex-shrink: 0;
            }
        }

        @media (max-width: 782px) {
            .apb-stats {
                grid-template-columns: repeat(3, 1fr);
            }

            .apb-toolbar {
                flex-direction: column;
                align-items: stretch;
            }

            .apb-bulk-actions {
                flex-wrap: wrap;
            }
        }
    </style>
</div>
