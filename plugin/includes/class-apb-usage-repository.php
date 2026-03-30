<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class APB_Usage_Repository {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . APB_TABLE_USAGE;
    }

    /**
     * 记录一条 API 用量日志
     */
    public function insert( array $data ): int|false {
        global $wpdb;

        $now = current_time( 'mysql' );

        $inserted = $wpdb->insert(
            $this->table,
            array(
                'job_id'            => $data['job_id'] ?? null,
                'call_type'         => sanitize_text_field( $data['call_type'] ?? 'article' ),
                'model'             => sanitize_text_field( $data['model'] ?? '' ),
                'prompt_tokens'     => absint( $data['prompt_tokens'] ?? 0 ),
                'completion_tokens' => absint( $data['completion_tokens'] ?? 0 ),
                'total_tokens'      => absint( $data['total_tokens'] ?? 0 ),
                'latency_ms'        => absint( $data['latency_ms'] ?? 0 ),
                'tps'               => floatval( $data['tps'] ?? 0 ),
                'extra'             => isset( $data['extra'] ) ? wp_json_encode( $data['extra'] ) : null,
                'created_at'        => $now,
            ),
            array( '%d', '%s', '%s', '%d', '%d', '%d', '%d', '%f', '%s', '%s' )
        );

        return $inserted ? (int) $wpdb->insert_id : false;
    }

    /**
     * 批量记录 API 用量 (一个 job 可能多次调用)
     */
    public function insert_batch( int $job_id, array $usage_items ): int {
        $count = 0;
        foreach ( $usage_items as $item ) {
            $item['job_id'] = $job_id;
            if ( $this->insert( $item ) ) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * 获取汇总统计: 总 token、平均延迟、平均 TPS
     */
    public function get_summary( string $since = '' ): object {
        global $wpdb;

        $where = '';
        if ( $since ) {
            $where = $wpdb->prepare( ' WHERE created_at >= %s', $since );
        }

        $row = $wpdb->get_row(
            "SELECT
                COUNT(*) AS total_calls,
                COALESCE(SUM(prompt_tokens), 0) AS total_prompt_tokens,
                COALESCE(SUM(completion_tokens), 0) AS total_completion_tokens,
                COALESCE(SUM(total_tokens), 0) AS total_tokens,
                COALESCE(AVG(latency_ms), 0) AS avg_latency_ms,
                COALESCE(MAX(latency_ms), 0) AS max_latency_ms,
                COALESCE(MIN(latency_ms), 0) AS min_latency_ms,
                COALESCE(AVG(tps), 0) AS avg_tps,
                COALESCE(SUM(total_tokens) / NULLIF(SUM(latency_ms), 0) * 1000, 0) AS overall_tps
            FROM {$this->table}{$where}"
        );

        return $row ?: (object) array(
            'total_calls'            => 0,
            'total_prompt_tokens'    => 0,
            'total_completion_tokens' => 0,
            'total_tokens'           => 0,
            'avg_latency_ms'         => 0,
            'max_latency_ms'         => 0,
            'min_latency_ms'         => 0,
            'avg_tps'                => 0,
            'overall_tps'            => 0,
        );
    }

    /**
     * 按 call_type 分组统计
     */
    public function get_by_call_type( string $since = '' ): array {
        global $wpdb;

        $where = '';
        if ( $since ) {
            $where = $wpdb->prepare( ' WHERE created_at >= %s', $since );
        }

        return $wpdb->get_results(
            "SELECT
                call_type,
                COUNT(*) AS total_calls,
                COALESCE(SUM(prompt_tokens), 0) AS total_prompt_tokens,
                COALESCE(SUM(completion_tokens), 0) AS total_completion_tokens,
                COALESCE(SUM(total_tokens), 0) AS total_tokens,
                COALESCE(AVG(latency_ms), 0) AS avg_latency_ms,
                COALESCE(AVG(tps), 0) AS avg_tps
            FROM {$this->table}{$where}
            GROUP BY call_type
            ORDER BY total_tokens DESC"
        );
    }

    /**
     * 按 model 分组统计
     */
    public function get_by_model( string $since = '' ): array {
        global $wpdb;

        $where = '';
        if ( $since ) {
            $where = $wpdb->prepare( ' WHERE created_at >= %s', $since );
        }

        return $wpdb->get_results(
            "SELECT
                model,
                COUNT(*) AS total_calls,
                COALESCE(SUM(total_tokens), 0) AS total_tokens,
                COALESCE(AVG(latency_ms), 0) AS avg_latency_ms,
                COALESCE(AVG(tps), 0) AS avg_tps
            FROM {$this->table}{$where}
            GROUP BY model
            ORDER BY total_tokens DESC"
        );
    }

    /**
     * 按天汇总 (用于趋势图)
     */
    public function get_daily_trend( int $days = 30 ): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    DATE(created_at) AS date,
                    COUNT(*) AS total_calls,
                    COALESCE(SUM(prompt_tokens), 0) AS total_prompt_tokens,
                    COALESCE(SUM(completion_tokens), 0) AS total_completion_tokens,
                    COALESCE(SUM(total_tokens), 0) AS total_tokens,
                    COALESCE(AVG(latency_ms), 0) AS avg_latency_ms,
                    COALESCE(AVG(tps), 0) AS avg_tps
                FROM {$this->table}
                WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
                GROUP BY DATE(created_at)
                ORDER BY date ASC",
                $days
            )
        );
    }

    /**
     * 最近 N 条用量日志
     */
    public function get_recent( int $limit = 50 ): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT u.*, j.topic AS job_topic
                FROM {$this->table} u
                LEFT JOIN {$wpdb->prefix}apb_jobs j ON u.job_id = j.id
                ORDER BY u.created_at DESC
                LIMIT %d",
                $limit
            )
        );
    }

    /**
     * 按 job_id 获取所有调用记录
     */
    public function get_by_job( int $job_id ): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE job_id = %d ORDER BY created_at ASC",
                $job_id
            )
        );
    }

    /**
     * 检查表是否存在
     */
    public function table_exists(): bool {
        global $wpdb;
        return (bool) $wpdb->get_var(
            "SHOW TABLES LIKE '{$this->table}'"
        );
    }
}
