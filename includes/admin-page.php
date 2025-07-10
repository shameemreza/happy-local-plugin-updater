<?php
/**
 * Admin Page Template
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Get settings
$settings = get_option('lpu_settings', [
    'repo_path' => LPU_DEFAULT_REPO_PATH,
    'auto_check' => false,
    'check_frequency' => 'daily',
    'auto_update' => false,
    'debug_mode' => false,
]);

// Get available updates
$updates = get_option('lpu_available_updates', [
    'updates' => [],
    'count' => 0,
    'last_checked' => 0,
]);

// Check if repository path is set
$repo_path_set = !empty($settings['repo_path']);

// Check for settings update message
$settings_updated = isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'true';

?>
<div class="wrap">
    <h1><?php esc_html_e('Happy Local Plugin Updater', 'happy-local-plugin-updater'); ?></h1>

    <?php if ($settings_updated): ?>
    <div class="notice notice-success is-dismissible">
        <p><?php esc_html_e('Settings saved successfully!', 'happy-local-plugin-updater'); ?></p>
    </div>
    <?php endif; ?>

    <?php if (!$repo_path_set): ?>
    <div class="notice notice-error">
        <p>
            <strong><?php esc_html_e('Repository path is not set!', 'happy-local-plugin-updater'); ?></strong>
            <?php esc_html_e('You must configure the repository path in the Settings section below before you can use this plugin.', 'happy-local-plugin-updater'); ?>
        </p>
    </div>
    <?php endif; ?>

    <div class="lpu-admin-content">
        <div class="lpu-admin-main">
            <div class="lpu-card">
                <div class="lpu-section-header">
                    <h2><?php esc_html_e('Available Updates', 'happy-local-plugin-updater'); ?></h2>
                    <p class="lpu-section-description"><?php esc_html_e('The following updates are available from your local repository.', 'happy-local-plugin-updater'); ?></p>
                </div>
                
                <div class="lpu-section-content">
                    <div class="lpu-option-wrapper">
                        <div class="lpu-update-actions">
                            <button type="button" id="lpu-check-updates" class="button button-primary"><?php esc_html_e('Check for Updates', 'happy-local-plugin-updater'); ?></button>
                            
                            <?php if (!$repo_path_set): ?>
                                <span class="lpu-warning"><?php esc_html_e('Repository path must be set first', 'happy-local-plugin-updater'); ?></span>
                            <?php elseif ($updates['last_checked'] > 0): ?>
                                <span class="lpu-last-checked">
                                    <?php
                                    // Force last_checked to be current timestamp if it was recently checked
                                    $recent_threshold = current_time('timestamp') - 60; // Within the last minute
                                    $last_checked = $updates['last_checked'] > $recent_threshold ? current_time('timestamp') : $updates['last_checked'];
                                    $time_diff = human_time_diff($last_checked, current_time('timestamp'));
                                    $time_string = $time_diff . ' ' . esc_html__('ago', 'happy-local-plugin-updater');
                                    
                                    if ($last_checked > $recent_threshold) {
                                        $time_string = esc_html__('just now', 'happy-local-plugin-updater');
                                    }
                                    
                                    /* translators: %s: human-readable time difference, e.g. "5 minutes ago" */
                                    $format = esc_html__('Last checked: %s', 'happy-local-plugin-updater');
                                    echo esc_html(sprintf($format, $time_string));
                                    ?>
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <?php
                        // Always force a check for recent updates to prevent stale data
                        $force_refresh = isset($_GET['refresh']) || $updates['last_checked'] < (current_time('timestamp') - 5 * MINUTE_IN_SECONDS);
                        if ($force_refresh && $repo_path_set) {
                            try {
                                $updater = new LPU_Updater($settings['repo_path']);
                                $fresh_updates = $updater->check_updates(get_plugins());
                                
                                // Update the option with fresh data
                                update_option('lpu_available_updates', [
                                    'updates' => $fresh_updates,
                                    'count' => count($fresh_updates),
                                    'last_checked' => current_time('timestamp'),
                                ]);
                                
                                // Use the fresh data
                                $updates['updates'] = $fresh_updates;
                                $updates['count'] = count($fresh_updates);
                                $updates['last_checked'] = current_time('timestamp');
                            } catch (Exception $e) {
                                // Silently continue with existing data
                            }
                        }
                        ?>
                        
                        <div id="lpu-update-results" class="lpu-update-results-wrapper">
                            <?php if (empty($updates['updates'])): ?>
                                <div class="lpu-no-updates-container">
                                    <span class="dashicons dashicons-yes-alt"></span>
                                    <p class="lpu-no-updates"><?php esc_html_e('No updates available at this time.', 'happy-local-plugin-updater'); ?></p>
                                </div>
                            <?php else: ?>
                                <table class="widefat striped lpu-updates-table">
                                    <thead>
                                        <tr>
                                            <th><?php esc_html_e('Plugin', 'happy-local-plugin-updater'); ?></th>
                                            <th><?php esc_html_e('Current Version', 'happy-local-plugin-updater'); ?></th>
                                            <th><?php esc_html_e('New Version', 'happy-local-plugin-updater'); ?></th>
                                            <th><?php esc_html_e('Actions', 'happy-local-plugin-updater'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($updates['updates'] as $update): ?>
                                            <tr>
                                                <td class="plugin-name"><?php echo esc_html($update['name']); ?></td>
                                                <td class="current-version"><?php echo esc_html($update['current_version']); ?></td>
                                                <td class="new-version"><?php echo esc_html($update['new_version']); ?></td>
                                                <td class="plugin-actions">
                                                    <button type="button" class="button button-primary lpu-update-plugin" data-plugin="<?php echo esc_attr($update['plugin']); ?>">
                                                        <?php esc_html_e('Update', 'happy-local-plugin-updater'); ?>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="lpu-admin-sidebar">
            <div class="lpu-card">
                <h2><?php esc_html_e('Settings', 'happy-local-plugin-updater'); ?></h2>
                
                <div class="lpu-card-content">
                    <form method="post" action="options.php" id="lpu-settings-form">
                        <?php settings_fields('lpu_settings_group'); ?>
                        
                        <!-- We'll handle the settings sections output manually for better control -->
                        <div id="lpu_settings_section">
                            <?php 
                            // Get the section description callback
                            global $wp_settings_sections;
                            $section_callback = $wp_settings_sections['lpu_settings']['lpu_settings_section']['callback'];
                            if (!empty($section_callback)) {
                                call_user_func($section_callback);
                            }
                            
                            // Get the settings fields
                            global $wp_settings_fields;
                            $fields = $wp_settings_fields['lpu_settings']['lpu_settings_section'];
                            
                            // Output fields in desired order
                            $field_order = ['lpu_repo_path', 'lpu_debug_mode', 'lpu_auto_check', 'lpu_check_frequency', 'lpu_auto_update'];
                            
                            // Start table
                            echo '<table class="form-table" role="presentation">';
                            
                            foreach ($field_order as $field_id) {
                                if (isset($fields[$field_id])) {
                                    $field = $fields[$field_id];
                                    $class = !empty($field['args']['class']) ? ' class="' . esc_attr($field['args']['class']) . '"' : '';
                                    
                                    // For dependent fields, we need to add them to the container but keep their existing classes
                                    echo '<tr id="' . esc_attr($field_id) . '-row"' . $class . '>';
                                    
                                    // Empty TH needed for WP Settings API compatibility
                                    echo '<th scope="row"></th>';
                                    
                                    echo '<td>';
                                    call_user_func($field['callback'], $field['args']);
                                    echo '</td>';
                                    
                                    echo '</tr>';
                                }
                            }
                            
                            echo '</table>';
                            ?>
                        </div>
                        
                        <?php submit_button(); ?>
                    </form>
                </div>
            </div>
            
            <div class="lpu-card">
                <h2><?php esc_html_e('How to Use', 'happy-local-plugin-updater'); ?></h2>
                
                <div class="lpu-card-content">
                    <p><?php esc_html_e('This plugin allows you to update your WordPress plugins from a local repository.', 'happy-local-plugin-updater'); ?></p>
                    
                    <ol>
                        <li><?php esc_html_e('Set the repository path in the settings above.', 'happy-local-plugin-updater'); ?></li>
                        <li><?php esc_html_e('Click "Check for Updates" to check for plugin updates.', 'happy-local-plugin-updater'); ?></li>
                        <li><?php esc_html_e('Click "Update" for any plugin you want to update from your local repository.', 'happy-local-plugin-updater'); ?></li>
                    </ol>
                    
                    <p><?php esc_html_e('You can also update plugins directly from the Plugins page by clicking "Update from Local" next to any plugin.', 'happy-local-plugin-updater'); ?></p>
                    
                    <p><strong><?php esc_html_e('Automatic Updates:', 'happy-local-plugin-updater'); ?></strong><br>
                    <?php esc_html_e('Enable "Auto Check Updates" to periodically check for plugin updates. If you also enable "Auto Update Plugins", updates will be automatically installed when found.', 'happy-local-plugin-updater'); ?></p>
                </div>
            </div>
        </div>
    </div>
</div> 