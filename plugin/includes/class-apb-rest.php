<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class APB_REST {

    private APB_Job_Repository $repo;
    private APB_Post_Publisher $publisher;

    public function __construct() {
        $this->repo      = new APB_Job_Repository();
        $this->publisher = new APB_Post_Publisher();
    }

    public function register_routes(): void {
        $ns = APB_REST_NAMESPACE;

        register_rest_route( $ns, '/categories', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_categories' ),
            'permission_callback' => array( $this, 'check_token' ),
        ) );

        register_rest_route( $ns, '/config', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_config' ),
            'permission_callback' => array( $this, 'check_token' ),
        ) );

        register_rest_route( $ns, '/jobs', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_jobs' ),
            'permission_callback' => array( $this, 'check_token' ),
        ) );

        register_rest_route( $ns, '/jobs', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'create_job' ),
            'permission_callback' => array( $this, 'check_token' ),
        ) );

        register_rest_route( $ns, '/jobs/(?P<id>\d+)/claim', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'claim_job' ),
            'permission_callback' => array( $this, 'check_token' ),
            'args' => array(
                'id' => array(
                    'validate_callback' => function( $v ) { return is_numeric( $v ) && (int) $v > 0; },
                ),
            ),
        ) );

        register_rest_route( $ns, '/jobs/(?P<id>\d+)/complete', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'complete_job' ),
            'permission_callback' => array( $this, 'check_token' ),
            'args' => array(
                'id' => array(
                    'validate_callback' => function( $v ) { return is_numeric( $v ) && (int) $v > 0; },
                ),
            ),
        ) );

        register_rest_route( $ns, '/jobs/(?P<id>\d+)/fail', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'fail_job' ),
            'permission_callback' => array( $this, 'check_token' ),
            'args' => array(
                'id' => array(
                    'validate_callback' => function( $v ) { return is_numeric( $v ) && (int) $v > 0; },
                ),
            ),
        ) );
    }

    public function check_token( WP_REST_Request $request ): WP_Error|bool {
        $token   = $request->get_header( 'x_apb_token' );
        $secret  = $this->get_setting( 'shared_secret' );

        if ( empty( $secret ) ) {
            return new WP_Error( 'apb_no_secret', __( 'Shared secret is not configured.', 'ai-publisher-bridge' ), array( 'status' => 500 ) );
        }

        if ( empty( $token ) || ! hash_equals( $secret, $token ) ) {
            return new WP_Error( 'apb_unauthorized', __( 'Invalid or missing token.', 'ai-publisher-bridge' ), array( 'status' => 401 ) );
        }

        return true;
    }

    public function get_categories( WP_REST_Request $request ): WP_REST_Response {
        $uncategorized_id = (int) get_option( 'default_category', 1 );

        $cats = get_categories( array(
            'hide_empty' => false,
            'exclude'    => $uncategorized_id,
        ) );

        $result = array();
        foreach ( $cats as $cat ) {
            $generator_raw = get_term_meta( $cat->term_id, 'apb_generator', true ) ?: 'generic';
            $generator     = in_array( $generator_raw, array( 'generic', 'kernel' ), true ) ? $generator_raw : 'generic';
            $source_map_raw = get_term_meta( $cat->term_id, 'apb_source_map', true ) ?: '';
            $source_map = '';
            if ( ! empty( $source_map_raw ) ) {
                $decoded = json_decode( $source_map_raw, true );
                $source_map = ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) ? $source_map_raw : '';
            }

            $result[] = array(
                'id'             => (int) $cat->term_id,
                'name'           => $cat->name,
                'slug'           => $cat->slug,
                'count'          => (int) $cat->count,
                'prompt_append'  => get_term_meta( $cat->term_id, 'apb_prompt_append', true ) ?: '',
                'topic_keywords' => get_term_meta( $cat->term_id, 'apb_topic_keywords', true ) ?: '',
                'topic_priority' => (int) ( get_term_meta( $cat->term_id, 'apb_topic_priority', true ) ?: 0 ),
                'source_path'    => get_term_meta( $cat->term_id, 'apb_source_path', true ) ?: '',
                'system_prompt'  => get_term_meta( $cat->term_id, 'apb_system_prompt', true ) ?: '',
                'generator'      => $generator,
                'source_map'     => $source_map,
            );
        }

        return $this->ok( $result );
    }

    public function get_config( WP_REST_Request $request ): WP_REST_Response {
        $settings = get_option( APB_OPTION_KEY, array() );

        // 只暴露安全字段,shared_secret 不能漏出去
        $safe = array(
            'default_post_status'       => $settings['default_post_status'] ?? 'draft',
            'default_post_author'       => (int) ( $settings['default_post_author'] ?? 0 ),
            'default_category'          => (int) ( $settings['default_category'] ?? 0 ),
            'site_tone'                 => $settings['site_tone'] ?? '',
            'worker_pull_enabled'       => (bool) ( $settings['worker_pull_enabled'] ?? true ),
            'ai_api_key'                => $settings['ai_api_key'] ?? '',
            'ai_base_url'               => $settings['ai_base_url'] ?? '',
            'ai_model'                  => $settings['ai_model'] ?? '',
            'retry_multiplier'          => (int) ( $settings['retry_multiplier'] ?? 30 ),
            'default_system_prompt'     => $settings['default_system_prompt'] ?? '',
            'topic_system_prompt'       => $settings['topic_system_prompt'] ?? '',
            'slug_system_prompt'        => $settings['slug_system_prompt'] ?? '',
            'title_similarity_threshold' => (float) ( $settings['title_similarity_threshold'] ?? 0.55 ),
            'max_quality_retries'       => (int) ( $settings['max_quality_retries'] ?? 2 ),
            'max_slug_retries'          => (int) ( $settings['max_slug_retries'] ?? 10 ),
            'min_article_words'         => (int) ( $settings['min_article_words'] ?? 1500 ),
            'code_lines_per_segment'    => (int) ( $settings['code_lines_per_segment'] ?? 40 ),
            'code_max_segments'         => (int) ( $settings['code_max_segments'] ?? 3 ),
            'article_prompt_template'   => $settings['article_prompt_template'] ?? '',
            'kernel_prompt_template'    => $settings['kernel_prompt_template'] ?? '',
            'kernel_max_total_segments' => (int) ( $settings['kernel_max_total_segments'] ?? 5 ),
            'max_tokens_article'        => (int) ( $settings['max_tokens_article'] ?? 8192 ),
            'max_tokens_topic'          => (int) ( $settings['max_tokens_topic'] ?? 1024 ),

            'post_date_randomize'         => (bool) ( $settings['post_date_randomize'] ?? false ),
            'post_date_max_offset_days'   => (int) ( $settings['post_date_max_offset_days'] ?? 90 ),
            'persona_injection'           => (bool) ( $settings['persona_injection'] ?? true ),
            'half_width_punctuation'      => (bool) ( $settings['half_width_punctuation'] ?? false ),
            'typo_injection'              => (bool) ( $settings['typo_injection'] ?? false ),
            'typo_density'                => (float) ( $settings['typo_density'] ?? 0.8 ),
            'max_article_words'           => (int) ( $settings['max_article_words'] ?? 2500 ),
        );

        return $this->ok( $safe );
    }

    public function get_jobs( WP_REST_Request $request ): WP_REST_Response {
        if ( ! $this->get_setting( 'worker_pull_enabled' ) ) {
            return $this->fail_response( 'Worker pull is disabled.', 403 );
        }

        $status = sanitize_text_field( $request->get_param( 'status' ) ?: 'pending' );
        $limit  = min( (int) ( $request->get_param( 'limit' ) ?: 20 ), 100 );

        $valid_statuses = array( 'pending', 'processing', 'completed', 'failed', 'published' );
        if ( ! in_array( $status, $valid_statuses, true ) ) {
            return $this->fail_response( 'Invalid status value.', 400 );
        }

        $jobs = $this->repo->get_by_status( $status, $limit );

        return $this->ok( $jobs );
    }

    public function create_job( WP_REST_Request $request ): WP_REST_Response {
        $body = $request->get_json_params();
        if ( empty( $body ) ) {
            $body = $request->get_body_params();
        }

        $topic = sanitize_text_field( $body['topic'] ?? '' );
        if ( empty( $topic ) ) {
            return $this->fail_response( 'topic is required.', 400 );
        }

        $keywords     = sanitize_text_field( $body['keywords'] ?? '' );
        $site_profile = sanitize_textarea_field( $body['site_profile'] ?? '' );
        $category_id  = absint( $body['category_id'] ?? 0 );

        $id = $this->repo->insert( $topic, $keywords, $site_profile, $category_id );

        if ( ! $id ) {
            return $this->fail_response( 'Failed to create job.', 500 );
        }

        return $this->ok( array( 'id' => $id ), 'Job created.', 201 );
    }

    public function claim_job( WP_REST_Request $request ): WP_REST_Response {
        $id = (int) $request['id'];

        $job = $this->repo->get( $id );
        if ( ! $job ) {
            return $this->fail_response( 'Job not found.', 404 );
        }

        if ( $job->status !== 'pending' ) {
            return $this->fail_response( "Job status is '{$job->status}', cannot claim.", 409 );
        }

        $claimed = $this->repo->claim( $id );
        if ( ! $claimed ) {
            return $this->fail_response( 'Failed to claim job. It may have been claimed by another worker.', 409 );
        }

        $job = $this->repo->get( $id );
        return $this->ok( $job, 'Job claimed.' );
    }

    public function complete_job( WP_REST_Request $request ): WP_REST_Response {
        $id = (int) $request['id'];

        $job = $this->repo->get( $id );
        if ( ! $job ) {
            return $this->fail_response( 'Job not found.', 404 );
        }

        if ( $job->status !== 'processing' ) {
            return $this->fail_response( "Job status is '{$job->status}', cannot complete.", 409 );
        }

        $body = $request->get_json_params();
        if ( empty( $body ) ) {
            $body = $request->get_body_params();
        }

        $title   = sanitize_text_field( $body['generated_title'] ?? '' );
        $html    = wp_kses_post( $body['generated_html'] ?? '' );
        $excerpt = sanitize_textarea_field( $body['generated_excerpt'] ?? '' );
        $json    = wp_kses_post( $body['generated_json'] ?? '' );
        $slug    = sanitize_text_field( $body['post_slug'] ?? '' );

        if ( empty( $title ) || empty( $html ) ) {
            return $this->fail_response( 'generated_title and generated_html are required.', 400 );
        }

        // Worker 可能换分类
        $category_id = absint( $body['category_id'] ?? 0 );
        if ( $category_id && term_exists( $category_id, 'category' ) ) {
            $job->category_id = $category_id;
        }

        // 拟人化的随机时间
        $post_date = sanitize_text_field( $body['post_date'] ?? '' );
        if ( ! empty( $post_date ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $post_date ) ) {
            $job->post_date = $post_date;
        }

        // 临时挂字段给 publisher 用
        $job->generated_title   = $title;
        $job->generated_html    = $html;
        $job->generated_excerpt = $excerpt;
        $job->post_slug         = $slug;

        $post_id = $this->publisher->publish( $job );

        if ( ! $post_id ) {
            $this->repo->fail( $id, 'Failed to create WordPress post.' );
            return $this->fail_response( 'Failed to create WordPress post.', 500 );
        }

        // publish 状态标记为 published,其他都是 completed
        $settings    = get_option( APB_OPTION_KEY, array() );
        $post_status = $settings['default_post_status'] ?? 'draft';
        $final_status = ( $post_status === 'publish' ) ? 'published' : 'completed';

        $update_data = array(
            'status'            => $final_status,
            'generated_title'   => $title,
            'generated_excerpt' => $excerpt,
            'generated_html'    => $html,
            'generated_json'    => $json,
            'wp_post_id'        => $post_id,
            'processed_at'      => current_time( 'mysql' ),
        );
        $update_format = array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' );

        if ( $category_id ) {
            $update_data['category_id'] = $category_id;
            $update_format[] = '%d';
        }

        $this->repo->update( $id, $update_data, $update_format );

        $job = $this->repo->get( $id );
        return $this->ok( $job, 'Job completed and post created.' );
    }

    public function fail_job( WP_REST_Request $request ): WP_REST_Response {
        $id = (int) $request['id'];

        $job = $this->repo->get( $id );
        if ( ! $job ) {
            return $this->fail_response( 'Job not found.', 404 );
        }

        if ( ! in_array( $job->status, array( 'pending', 'processing' ), true ) ) {
            return $this->fail_response( "Job status is '{$job->status}', cannot mark as failed.", 409 );
        }

        $body = $request->get_json_params();
        if ( empty( $body ) ) {
            $body = $request->get_body_params();
        }

        $error = sanitize_textarea_field( $body['error_message'] ?? 'Unknown error.' );

        $this->repo->fail( $id, $error );

        $job = $this->repo->get( $id );
        return $this->ok( $job, 'Job marked as failed.' );
    }

    private function get_setting( string $key ) {
        $settings = get_option( APB_OPTION_KEY, array() );
        return $settings[ $key ] ?? null;
    }

    private function ok( mixed $data, string $message = '', int $status = 200 ): WP_REST_Response {
        return new WP_REST_Response( array(
            'success' => true,
            'data'    => $data,
            'message' => $message,
        ), $status );
    }

    private function fail_response( string $message, int $status = 400 ): WP_REST_Response {
        return new WP_REST_Response( array(
            'success' => false,
            'data'    => null,
            'message' => $message,
        ), $status );
    }
}
