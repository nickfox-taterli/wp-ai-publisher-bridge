<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;
$table = $wpdb->prefix . APB_TABLE_JOBS;
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

delete_option( APB_OPTION_KEY );
