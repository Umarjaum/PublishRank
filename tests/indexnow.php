<?php
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
/** Source-level regression checks for the IndexNow module. */
$root = dirname( __DIR__ );
$module = file_get_contents( $root . '/src/Indexing/IndexNow.php' );
$page = file_get_contents( $root . '/src/Admin/IndexNowPage.php' );
$plugin = file_get_contents( $root . '/src/Core/Plugin.php' );
$checks = array(
    'shared IndexNow endpoint' => false !== strpos( $module, 'https://api.indexnow.org/indexnow' ),
    'public key endpoint' => false !== strpos( $module, 'publishrank-indexnow-key\.txt' ),
    'scheduled submissions' => false !== strpos( $module, 'wp_schedule_single_event' ),
    'automatic batch queue' => false !== strpos( $module, 'function flush_queue' ) && false !== strpos( $module, 'publishrank_indexnow_flush_queue' ),
    'bounded 100 URL background batches' => false !== strpos( $module, 'array_slice( $queue, 0, 100, true )' ),
    'batch logging reduces database writes' => false !== strpos( $module, 'function record_many' ) && false !== strpos( $module, '$this->record_many( $log_entries )' ),
    'bounded persistent queue' => false !== strpos( $module, 'count( $queue ) > 5000' ),
    'lock contention reschedules queue' => false !== strpos( $module, 'publishrank_indexnow_queue_lock' ) && false !== strpos( $module, 'time() + 30' ),
    're-enable schedules pending queue' => false !== strpos( $page, "wp_schedule_single_event( time() + 15, 'publishrank_indexnow_flush_queue' )" ),
    'bounded retries' => false !== strpos( $module, 'MAX_RETRIES = 3' ),
    'bulk submission support' => false !== strpos( $module, 'function submit_bulk' ),
    'submission history' => false !== strpos( $module, 'publishrank_indexnow_log' ),
    'automatic key generation on activation' => false !== strpos( $module, 'function activate' ) && false !== strpos( $module, 'wp_generate_password( 32, false, false )' ),
    'upgrade rewrite refresh' => false !== strpos( $module, 'function maybe_upgrade' ) && false !== strpos( $module, 'flush_rewrite_rules( false )' ),
    'single post submission action' => false !== strpos( $page, 'function single_submit' ),
    'post list bulk action' => false !== strpos( $page, 'function handle_bulk_action' ),
    'custom post type bulk actions' => false !== strpos( $page, 'function register_post_type_bulk_actions' ),
    'path exclusion controls' => false !== strpos( $module, 'function is_excluded_url' ) && false !== strpos( $page, 'excluded_paths' ),
    'automatic duplicate throttle' => false !== strpos( $module, 'publishrank_indexnow_dedupe_' ),
    '100 entry activity history' => false !== strpos( $module, 'const MAX_LOGS = 100' ),
    'CSV history export' => false !== strpos( $page, 'function export_logs' ) && false !== strpos( $page, 'publishrank_indexnow_export' ),
    'admin nonce protection' => false !== strpos( $page, 'check_admin_referer' ),
    'admin permission checks' => false !== strpos( $page, "current_user_can( 'manage_options' )" ),
    'admin module registered' => false !== strpos( $plugin, 'IndexNowPage::class' ),
    'canonical IndexNow endpoint with trailing slash' => false !== strpos( $module, 'https://api.indexnow.org/indexnow/' ),
    'status transition notifications' => false !== strpos( $module, "add_action( 'transition_post_status'") && false !== strpos( $module, 'function queue_status_transition' ),
    'permalink change notifications' => false !== strpos( $module, "add_action( 'post_updated'") && false !== strpos( $module, 'function queue_permalink_change' ),
    'queue race preserves changed items' => false !== strpos( $module, '$current[ $queue_key ] === $item' ),
    'queue health dashboard' => false !== strpos( $page, 'Queued URLs:' ) && false !== strpos( $page, 'Next queue run:' ),
    'clearable history' => false !== strpos( $page, 'function clear_logs' ) && false !== strpos( $page, 'publishrank_indexnow_clear_logs' ),
    'default public posts and pages' => false !== strpos( $page, "'post,page'" ),
);
$failed = array();
foreach ( $checks as $name => $passed ) { echo ( $passed ? 'PASS: ' : 'FAIL: ' ) . $name . PHP_EOL; if ( ! $passed ) { $failed[] = $name; } }
if ( $failed ) { exit( 1 ); }
echo 'IndexNow checks passed.' . PHP_EOL;
