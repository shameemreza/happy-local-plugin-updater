<?php
/**
 * Admin Functions
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add update buttons to plugins list
 */
add_action('admin_init', 'lpu_add_plugin_update_buttons');
function lpu_add_plugin_update_buttons() {
    // Hook into the plugin action links
    add_filter('plugin_action_links', 'lpu_plugin_action_links', 10, 4);
}

/**
 * Add update from local button to plugin actions
 */
function lpu_plugin_action_links($actions, $plugin_file, $plugin_data, $context) {
    // Only add for plugins that are directories (not single file plugins)
    $plugin_slug = dirname($plugin_file);
    if ($plugin_slug !== '.') {
        $settings = get_option('lpu_settings', [
            'repo_path' => LPU_DEFAULT_REPO_PATH,
        ]);
        
        // Check if plugin exists in repository
        $repo_path = $settings['repo_path'];
        
        if (file_exists($repo_path)) {
            // Add update button
            $update_link = sprintf(
                '<a href="#" class="lpu-update-link" data-plugin="%s" data-nonce="%s">%s</a>',
                esc_attr($plugin_file),
                wp_create_nonce('lpu_update_' . $plugin_file),
                __('Update from Local', 'happy-local-plugin-updater')
            );
            
            // Add after the regular update link if it exists
            if (isset($actions['update'])) {
                $update_action = $actions['update'];
                unset($actions['update']);
                $actions['update'] = $update_action;
                $actions['lpu_update'] = $update_link;
            } else {
                // Otherwise add after activate/deactivate
                $actions['lpu_update'] = $update_link;
            }
        }
    }
    
    return $actions;
}

/**
 * Check if updates are available
 */
function lpu_check_for_updates() {
    $settings = get_option('lpu_settings', [
        'repo_path' => LPU_DEFAULT_REPO_PATH,
        'auto_check' => false,
        'auto_update' => false,
        'debug_mode' => false,
    ]);
    
    // Get debug mode setting
    $debug_mode = isset($settings['debug_mode']) ? $settings['debug_mode'] : false;
    
    // Skip if auto-check is disabled
    if (!$settings['auto_check']) {
        return;
    }
    
    // Check repository path
    $repo_path = $settings['repo_path'];
    if (!file_exists($repo_path)) {
        return;
    }
    
    // Get installed plugins
    $installed_plugins = get_plugins();
    
    // Check for updates
    $updater = new LPU_Updater($repo_path);
    $updates = $updater->check_updates($installed_plugins);
    
    // Auto-update plugins if enabled
    if (!empty($updates) && $settings['auto_update']) {
        $log_file = dirname(dirname(__FILE__)) . '/debug.log';
        
        if ($debug_mode) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Starting automatic updates\n", FILE_APPEND);
        }
        
        foreach ($updates as $update) {
            try {
                if ($debug_mode) {
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Auto-updating plugin: " . $update['plugin'] . "\n", FILE_APPEND);
                }
                
                $result = $updater->direct_update_plugin($update['plugin']);
                
                if (is_wp_error($result)) {
                    if ($debug_mode) {
                        file_put_contents($log_file, date('Y-m-d H:i:s') . " - Auto-update failed: " . $result->get_error_message() . "\n", FILE_APPEND);
                    }
                } else {
                    if ($debug_mode) {
                        file_put_contents($log_file, date('Y-m-d H:i:s') . " - Auto-update successful for: " . $update['plugin'] . "\n", FILE_APPEND);
                    }
                }
            } catch (Exception $e) {
                if ($debug_mode) {
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Auto-update exception: " . $e->getMessage() . "\n", FILE_APPEND);
                }
            }
        }
        
        // Force refresh of update data
        delete_site_transient('update_plugins');
        wp_clean_plugins_cache(true);
        
        // Check for updates again after auto-updating
        $updates = $updater->check_updates($installed_plugins);
    }
    
    // Store updates
    update_option('lpu_available_updates', [
        'updates' => $updates,
        'count' => count($updates),
        'last_checked' => current_time('timestamp'),
    ]);
    
    // Add notification if updates are available
    if (!empty($updates)) {
        add_action('admin_notices', 'lpu_update_notice');
    }
}

/**
 * Display update notice
 */
function lpu_update_notice() {
    $updates = get_option('lpu_available_updates', [
        'updates' => [],
        'count' => 0,
        'last_checked' => 0,
    ]);
    
    if ($updates['count'] > 0) {
        ?>
        <div class="notice notice-info is-dismissible">
            <p>
                <?php 
                /* translators: %s: Number of plugins with available updates */
                printf(
                    esc_html(_n(
                        'Local Plugin Updater: %s plugin has an update available from your local repository.',
                        'Local Plugin Updater: %s plugins have updates available from your local repository.',
                        $updates['count'],
                        'happy-local-plugin-updater'
                    )),
                    '<strong>' . esc_html($updates['count']) . '</strong>'
                ); 
                ?>
                <a href="<?php echo esc_url(admin_url('plugins.php?page=local-plugin-updater')); ?>">
                    <?php esc_html_e('View Updates', 'happy-local-plugin-updater'); ?>
                </a>
            </p>
        </div>
        <?php
    }
}

/**
 * Schedule update checks
 */
add_action('wp', 'lpu_schedule_update_checks');
function lpu_schedule_update_checks() {
    $settings = get_option('lpu_settings', [
        'auto_check' => false,
        'check_frequency' => 'daily',
    ]);
    
    // Skip if auto-check is disabled
    if (!$settings['auto_check']) {
        // Unschedule if disabled
        if (wp_next_scheduled('lpu_check_updates_event')) {
            wp_clear_scheduled_hook('lpu_check_updates_event');
        }
        return;
    }
    
    // Schedule event if not already scheduled
    if (!wp_next_scheduled('lpu_check_updates_event')) {
        wp_schedule_event(
            time(),
            $settings['check_frequency'],
            'lpu_check_updates_event'
        );
    }
}

/**
 * Hook for scheduled update checks
 */
add_action('lpu_check_updates_event', 'lpu_check_for_updates');

/**
 * Add plugin admin body class
 */
add_filter('admin_body_class', 'lpu_admin_body_class');
function lpu_admin_body_class($classes) {
    $screen = get_current_screen();
    
    if ($screen && ($screen->id === 'plugins' || $screen->id === 'plugins_page_local-plugin-updater')) {
        $classes .= ' lpu-admin ';
    }
    
    return $classes;
} 