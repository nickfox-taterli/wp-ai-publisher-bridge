<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class APB_Plugin {

    private static ?self $instance = null;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->boot();
    }

    private function boot(): void {
        // 后台每次都跑迁移,反正是先检查再改,不亏
        if ( is_admin() ) {
            add_action( 'admin_init', array( 'APB_Activator', 'migrate_table' ) );
        }

        if ( is_admin() ) {
            $admin = new APB_Admin();
            $admin->init();
        }

        add_action( 'rest_api_init', function() {
            $rest = new APB_REST();
            $rest->register_routes();
        } );
    }
}
