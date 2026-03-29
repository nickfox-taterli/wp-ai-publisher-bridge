<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class APB_Job_Repository {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . APB_TABLE_JOBS;
    }

    public function insert( string $topic, string $keywords = '', string $site_profile = '', int $category_id = 0 ): int|false {
        global $wpdb;

        $now = current_time( 'mysql' );

        $inserted = $wpdb->insert(
            $this->table,
            array(
                'topic'        => $topic,
                'keywords'     => $keywords,
                'site_profile' => $site_profile,
                'category_id'  => $category_id ?: null,
                'status'       => 'pending',
                'created_at'   => $now,
                'updated_at'   => $now,
            ),
            array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
        );

        return $inserted ? (int) $wpdb->insert_id : false;
    }

    public function get( int $id ): ?object {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id )
        );

        return $row ?: null;
    }

    public function get_by_status( string $status = 'pending', int $limit = 20 ): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE status = %s ORDER BY created_at ASC LIMIT %d",
                $status,
                $limit
            )
        );
    }

    public function list( string $status_filter = '', int $per_page = 20, int $offset = 0 ): array {
        global $wpdb;

        if ( $status_filter ) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$this->table} WHERE status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
                    $status_filter,
                    $per_page,
                    $offset
                )
            );
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            )
        );
    }

    public function count( string $status_filter = '' ): int {
        global $wpdb;

        if ( $status_filter ) {
            return (int) $wpdb->get_var(
                $wpdb->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE status = %s", $status_filter )
            );
        }

        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" );
    }

    public function update( int $id, array $data, array $format ): bool {
        global $wpdb;

        // 顺手刷一下 updated_at
        $data['updated_at'] = current_time( 'mysql' );
        $format[] = '%s';

        $result = $wpdb->update( $this->table, $data, array( 'id' => $id ), $format, array( '%d' ) );

        return $result !== false;
    }

    public function claim( int $id ): bool {
        global $wpdb;

        // 只有 pending 才能抢到,防并发
        $result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table} SET status = 'processing', updated_at = %s WHERE id = %d AND status = 'pending'",
                current_time( 'mysql' ),
                $id
            )
        );

        return $result > 0;
    }

    public function complete( int $id, string $title, string $excerpt, string $html, string $json = '', int $wp_post_id = 0 ): bool {
        global $wpdb;

        $data = array(
            'status'            => 'completed',
            'generated_title'   => $title,
            'generated_excerpt' => $excerpt,
            'generated_html'    => $html,
            'generated_json'    => $json,
            'wp_post_id'        => $wp_post_id,
            'processed_at'      => current_time( 'mysql' ),
            'updated_at'        => current_time( 'mysql' ),
        );

        $format = array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' );

        $result = $wpdb->update( $this->table, $data, array( 'id' => $id ), $format, array( '%d' ) );

        return $result !== false;
    }

    public function fail( int $id, string $error_message ): bool {
        global $wpdb;

        $data = array(
            'status'        => 'failed',
            'error_message' => $error_message,
            'processed_at'  => current_time( 'mysql' ),
            'updated_at'    => current_time( 'mysql' ),
        );

        $format = array( '%s', '%s', '%s', '%s' );

        $result = $wpdb->update( $this->table, $data, array( 'id' => $id ), $format, array( '%d' ) );

        return $result !== false;
    }

    public function reset( int $id ): bool {
        global $wpdb;

        $data = array(
            'status'        => 'pending',
            'error_message' => null,
            'processed_at'  => null,
            'updated_at'    => current_time( 'mysql' ),
        );

        $format = array( '%s', '%s', '%s', '%s' );

        $result = $wpdb->update( $this->table, $data, array( 'id' => $id, 'status' => 'failed' ), $format, array( '%d', '%s' ) );

        return $result !== false;
    }

    public function delete( int $id ): bool {
        global $wpdb;

        $result = $wpdb->delete( $this->table, array( 'id' => $id ), array( '%d' ) );

        return $result !== false;
    }

    public function delete_all( string $status_filter = '' ): int {
        global $wpdb;

        if ( $status_filter ) {
            return (int) $wpdb->query(
                $wpdb->prepare( "DELETE FROM {$this->table} WHERE status = %s", $status_filter )
            );
        }

        return (int) $wpdb->query( "TRUNCATE TABLE {$this->table}" );
    }

    // 文章都没了,任务留着也没用
    public function delete_orphaned(): int {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT id, wp_post_id FROM {$this->table} WHERE wp_post_id IS NOT NULL AND wp_post_id > 0"
        );

        $deleted = 0;
        foreach ( $rows as $row ) {
            if ( ! get_post( (int) $row->wp_post_id ) ) {
                if ( $wpdb->delete( $this->table, array( 'id' => $row->id ), array( '%d' ) ) ) {
                    $deleted++;
                }
            }
        }

        return $deleted;
    }
}
