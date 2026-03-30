<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class APB_Admin {

    private APB_Job_Repository $repo;

    public function __construct() {
        $this->repo = new APB_Job_Repository();
    }

    public function init(): void {
        add_action( 'admin_menu', array( $this, 'add_menus' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_post_apb_delete_job', array( $this, 'handle_delete_job' ) );
        add_action( 'admin_post_apb_reset_job', array( $this, 'handle_reset_job' ) );
        add_action( 'admin_post_apb_bulk_clear_jobs', array( $this, 'handle_bulk_clear_jobs' ) );
        add_action( 'admin_post_apb_save_category_prompts', array( $this, 'handle_save_category_prompts' ) );
    }

    public function add_menus(): void {
        add_menu_page(
            'AI Publisher',
            'AI Publisher',
            'manage_options',
            'ai-publisher-bridge',
            array( $this, 'render_jobs_page' ),
            'dashicons-admin-generic',
            80
        );

        add_submenu_page(
            'ai-publisher-bridge',
            '任务列表',
            '任务列表',
            'manage_options',
            'ai-publisher-bridge',
            array( $this, 'render_jobs_page' )
        );

        add_submenu_page(
            'ai-publisher-bridge',
            '设置',
            '设置',
            'manage_options',
            'apb-settings',
            array( $this, 'render_settings_page' )
        );

        add_submenu_page(
            'ai-publisher-bridge',
            'API 文档',
            'API 文档',
            'manage_options',
            'apb-api-docs',
            array( $this, 'render_api_docs_page' )
        );
    }

    public function register_settings(): void {
        register_setting( 'apb_settings_group', APB_OPTION_KEY, array(
            'sanitize_callback' => array( $this, 'sanitize_settings' ),
        ) );
    }

    public function sanitize_settings( array $input ): array {
        $clean = array();

        $clean['shared_secret']       = sanitize_text_field( $input['shared_secret'] ?? '' );
        $clean['default_post_status'] = in_array( $input['default_post_status'] ?? '', array( 'draft', 'publish', 'pending' ), true )
                                        ? $input['default_post_status'] : 'draft';
        $clean['default_post_author'] = absint( $input['default_post_author'] ?? 0 );
        $clean['default_category']    = absint( $input['default_category'] ?? 0 );
        $clean['site_tone']           = sanitize_textarea_field( $input['site_tone'] ?? '' );
        $clean['worker_pull_enabled'] = ! empty( $input['worker_pull_enabled'] ) ? '1' : '0';

        $clean['ai_api_key']       = sanitize_text_field( $input['ai_api_key'] ?? '' );
        $clean['ai_base_url']      = esc_url_raw( $input['ai_base_url'] ?? '' );
        $clean['ai_model']         = sanitize_text_field( $input['ai_model'] ?? '' );
        $clean['retry_multiplier'] = max( 1, absint( $input['retry_multiplier'] ?? 30 ) );

        $clean['default_system_prompt'] = sanitize_textarea_field( $input['default_system_prompt'] ?? '' );
        $clean['topic_system_prompt']   = sanitize_textarea_field( $input['topic_system_prompt'] ?? '' );
        $clean['slug_system_prompt']    = sanitize_textarea_field( $input['slug_system_prompt'] ?? '' );

        $threshold = floatval( $input['title_similarity_threshold'] ?? 0.55 );
        $clean['title_similarity_threshold'] = ( $threshold > 0 && $threshold <= 1 ) ? $threshold : 0.55;
        $clean['max_quality_retries'] = max( 1, absint( $input['max_quality_retries'] ?? 2 ) );
        $clean['max_slug_retries']    = max( 1, absint( $input['max_slug_retries'] ?? 10 ) );
        $clean['min_article_words']   = max( 500, absint( $input['min_article_words'] ?? 1500 ) );
        $clean['code_lines_per_segment'] = max( 5, absint( $input['code_lines_per_segment'] ?? 40 ) );
        $clean['code_max_segments']     = max( 1, absint( $input['code_max_segments'] ?? 3 ) );

        $clean['article_prompt_template']      = sanitize_textarea_field( $input['article_prompt_template'] ?? '' );
        $clean['kernel_prompt_template']       = sanitize_textarea_field( $input['kernel_prompt_template'] ?? '' );
        $clean['kernel_max_total_segments']    = max( 1, absint( $input['kernel_max_total_segments'] ?? 5 ) );

        $clean['max_tokens_article']           = max( 1024, absint( $input['max_tokens_article'] ?? 8192 ) );
        $clean['max_tokens_topic']             = max( 256, absint( $input['max_tokens_topic'] ?? 1024 ) );

        $clean['post_date_randomize']          = ! empty( $input['post_date_randomize'] ) ? '1' : '0';
        $clean['post_date_max_offset_days']    = max( 1, absint( $input['post_date_max_offset_days'] ?? 90 ) );
        $clean['persona_injection']            = ! empty( $input['persona_injection'] ) ? '1' : '0';
        $clean['half_width_punctuation']       = ! empty( $input['half_width_punctuation'] ) ? '1' : '0';
        $clean['typo_injection']               = ! empty( $input['typo_injection'] ) ? '1' : '0';
        $typo_density = floatval( $input['typo_density'] ?? 0.8 );
        $clean['typo_density']                 = ( $typo_density > 0 && $typo_density <= 10 ) ? $typo_density : 0.8;
        $clean['max_article_words']            = max( 500, absint( $input['max_article_words'] ?? 2500 ) );

        // 图像生成设置
        $clean['image_gen_enabled']            = ! empty( $input['image_gen_enabled'] ) ? '1' : '0';
        $clean['minimax_api_key']              = sanitize_text_field( $input['minimax_api_key'] ?? '' );
        $clean['image_gen_model']              = in_array( $input['image_gen_model'] ?? '', array( 'image-01', 'image-01-live' ), true )
                                                 ? $input['image_gen_model'] : 'image-01';
        $clean['image_gen_max_per_article']    = max( 1, min( 10, absint( $input['image_gen_max_per_article'] ?? 3 ) ) );
        $clean['image_gen_prompt_template']    = sanitize_textarea_field( $input['image_gen_prompt_template'] ?? '' );
        $clean['image_gen_aspect_ratios']      = sanitize_text_field( $input['image_gen_aspect_ratios'] ?? '16:9,4:3,1:1' );

        return $clean;
    }

    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        include APB_PLUGIN_DIR . 'admin/views/settings-page.php';
    }

    public function render_jobs_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        include APB_PLUGIN_DIR . 'admin/views/jobs-page.php';
    }

    public function handle_delete_job(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '无权操作.' );
        }

        check_admin_referer( 'apb_delete_job_' . ( $_POST['job_id'] ?? 0 ), 'apb_nonce' );

        $id = absint( $_POST['job_id'] ?? 0 );
        if ( $id ) {
            $this->repo->delete( $id );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=ai-publisher-bridge&apb_notice=job_deleted' ) );
        exit;
    }

    public function handle_reset_job(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '无权操作.' );
        }

        check_admin_referer( 'apb_reset_job_' . ( $_POST['job_id'] ?? 0 ), 'apb_nonce' );

        $id = absint( $_POST['job_id'] ?? 0 );
        if ( $id ) {
            $this->repo->reset( $id );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=ai-publisher-bridge&apb_notice=job_reset' ) );
        exit;
    }

    public function handle_bulk_clear_jobs(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '无权操作.' );
        }

        check_admin_referer( 'apb_bulk_clear_jobs', 'apb_bulk_nonce' );

        $mode  = sanitize_text_field( $_POST['clear_mode'] ?? '' );
        $count = 0;

        switch ( $mode ) {
            case 'all':
                $count = $this->repo->delete_all();
                $msg   = 'jobs_cleared_all';
                break;
            case 'failed':
                $count = $this->repo->delete_all( 'failed' );
                $msg   = 'jobs_cleared_failed';
                break;
            case 'orphaned':
                $count = $this->repo->delete_orphaned();
                $msg   = 'jobs_cleared_orphaned';
                break;
            default:
                $msg = 'jobs_clear_invalid';
        }

        wp_safe_redirect( admin_url( "admin.php?page=ai-publisher-bridge&apb_notice={$msg}&count={$count}" ) );
        exit;
    }

    public function handle_save_category_prompts(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( '无权操作.' );
        }

        check_admin_referer( 'apb_category_prompts', 'apb_cat_prompts_nonce' );

        $prompts       = $_POST['cat_prompts'] ?? array();
        $keywords      = $_POST['cat_keywords'] ?? array();
        $priorities    = $_POST['cat_priority'] ?? array();
        $source_paths  = $_POST['cat_source_path'] ?? array();
        $sys_prompts   = $_POST['cat_system_prompt'] ?? array();
        $generators    = $_POST['cat_generator'] ?? array();
        $source_maps   = $_POST['cat_source_map'] ?? array();

        if ( ! is_array( $prompts ) )      { $prompts      = array(); }
        if ( ! is_array( $keywords ) )     { $keywords     = array(); }
        if ( ! is_array( $priorities ) )   { $priorities   = array(); }
        if ( ! is_array( $source_paths ) ) { $source_paths = array(); }
        if ( ! is_array( $sys_prompts ) )  { $sys_prompts  = array(); }
        if ( ! is_array( $generators ) )   { $generators   = array(); }
        if ( ! is_array( $source_maps ) )  { $source_maps  = array(); }

        // 把所有字段涉及的 term ID 汇总一下
        $all_term_ids = array_unique( array_merge(
            array_keys( $prompts ),
            array_keys( $keywords ),
            array_keys( $priorities ),
            array_keys( $source_paths ),
            array_keys( $sys_prompts ),
            array_keys( $generators ),
            array_keys( $source_maps )
        ) );

        foreach ( $all_term_ids as $term_id ) {
            $term_id = absint( $term_id );
            if ( ! $term_id || ! term_exists( $term_id, 'category' ) ) {
                continue;
            }

            $prompt_text = sanitize_textarea_field( wp_unslash( $prompts[ $term_id ] ?? '' ) );
            if ( ! empty( $prompt_text ) ) {
                update_term_meta( $term_id, 'apb_prompt_append', $prompt_text );
            } else {
                delete_term_meta( $term_id, 'apb_prompt_append' );
            }

            $kw_text = sanitize_text_field( $keywords[ $term_id ] ?? '' );
            if ( ! empty( $kw_text ) ) {
                update_term_meta( $term_id, 'apb_topic_keywords', $kw_text );
            } else {
                delete_term_meta( $term_id, 'apb_topic_keywords' );
            }

            $priority = absint( $priorities[ $term_id ] ?? 0 );
            if ( $priority > 0 ) {
                update_term_meta( $term_id, 'apb_topic_priority', $priority );
            } else {
                delete_term_meta( $term_id, 'apb_topic_priority' );
            }

            $sp_text = sanitize_text_field( $source_paths[ $term_id ] ?? '' );
            if ( ! empty( $sp_text ) ) {
                update_term_meta( $term_id, 'apb_source_path', $sp_text );
            } else {
                delete_term_meta( $term_id, 'apb_source_path' );
            }

            $sys_text = sanitize_textarea_field( wp_unslash( $sys_prompts[ $term_id ] ?? '' ) );
            if ( ! empty( $sys_text ) ) {
                update_term_meta( $term_id, 'apb_system_prompt', $sys_text );
            } else {
                delete_term_meta( $term_id, 'apb_system_prompt' );
            }

            $gen = sanitize_text_field( $generators[ $term_id ] ?? '' );
            if ( in_array( $gen, array( 'generic', 'kernel' ), true ) ) {
                update_term_meta( $term_id, 'apb_generator', $gen );
            } else {
                delete_term_meta( $term_id, 'apb_generator' );
            }

            $sm_text = sanitize_textarea_field( wp_unslash( $source_maps[ $term_id ] ?? '' ) );
            if ( ! empty( $sm_text ) ) {
                // 校验 JSON 格式
                $decoded = json_decode( $sm_text, true );
                if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
                    update_term_meta( $term_id, 'apb_source_map', $sm_text );
                } else {
                    // JSON 坏了记个日志,但别崩
                    error_log( "APB: Invalid source_map JSON for category {$term_id}: " . json_last_error_msg() );
                    delete_term_meta( $term_id, 'apb_source_map' );
                }
            } else {
                delete_term_meta( $term_id, 'apb_source_map' );
            }
        }

        wp_safe_redirect( admin_url( 'admin.php?page=apb-settings&apb_notice=cat_prompts_saved' ) );
        exit;
    }

    public function render_api_docs_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        include APB_PLUGIN_DIR . 'admin/views/api-docs-page.php';
    }
}
