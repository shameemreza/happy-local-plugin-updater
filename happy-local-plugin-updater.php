<?php
/**
 * Plugin Name: Happy Local Plugin Updater
 * Plugin URI: https://github.com/shameemreza/happy-local-plugin-updater
 * Description: Updates WordPress plugins from your local repository.
 * Version: 1.0.0
 * Author: Shameem Reza
 * Author URI: https://shameem.dev
 * Text Domain: happy-local-plugin-updater
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define constants
define('LPU_VERSION', '1.0.0');
define('LPU_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LPU_PLUGIN_URL', plugin_dir_url(__FILE__));
define('LPU_PLUGIN_BASENAME', plugin_basename(__FILE__));
// Empty default path - force users to enter their own path in settings
define('LPU_DEFAULT_REPO_PATH', '');
// Define plugin file path for hooks
define('LPU_PLUGIN_FILE', __FILE__);

/**
 * Main Plugin Class
 * 
 * @since 1.0.0
 */
class Local_Plugin_Updater {
    /**
     * Plugin instance
     * 
     * @var object
     */
    private static $instance = null;

    /**
     * Plugin settings
     * 
     * @var array
     */
    private $settings;

    /**
     * Get plugin instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Load plugin settings
        $settings = get_option('lpu_settings', [
            'repo_path' => LPU_DEFAULT_REPO_PATH,
            'auto_check' => false,
            'check_frequency' => 'daily',
            'auto_update' => false,
            'debug_mode' => false,
        ]);
        
        // Ensure settings is an array
        if (!is_array($settings)) {
            $settings = [
                'repo_path' => LPU_DEFAULT_REPO_PATH,
                'auto_check' => false,
                'check_frequency' => 'daily',
                'auto_update' => false,
                'debug_mode' => false,
            ];
        }
        
        $this->settings = $settings;
        
        // Load dependencies
        $this->load_dependencies();
        
        // Set locale
        $this->set_locale();

        // Admin hooks
        if (is_admin()) {
            add_action('admin_menu', [$this, 'admin_menu']);
            add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);
            add_action('admin_init', [$this, 'register_settings']);
            add_action('admin_init', [$this, 'reset_update_count']);
            add_action('admin_init', [$this, 'check_repository_path']);
            add_filter('plugin_action_links_' . LPU_PLUGIN_BASENAME, [$this, 'plugin_action_links']);
            
            // AJAX hooks
            add_action('wp_ajax_lpu_check_updates', [$this, 'ajax_check_updates']);
            add_action('wp_ajax_lpu_update_plugin', [$this, 'ajax_update_plugin']);
            add_action('wp_ajax_lpu_sync_updates', [$this, 'ajax_sync_updates']);
        }
    }

    /**
     * Load dependencies
     */
    private function load_dependencies() {
        // Include required files
        require_once LPU_PLUGIN_DIR . 'includes/class-updater.php';
        require_once LPU_PLUGIN_DIR . 'includes/admin-functions.php';
    }

    /**
     * Set locale
     */
    private function set_locale() {
        add_action('plugins_loaded', function() {
            load_plugin_textdomain('happy-local-plugin-updater', false, dirname(plugin_basename(__FILE__)) . '/languages');
        });
    }

    /**
     * Add admin menu
     */
    public function admin_menu() {
        add_submenu_page(
            'plugins.php',
            __('Happy Local Plugin Updater', 'happy-local-plugin-updater'),
            __('Happy Updater', 'happy-local-plugin-updater'),
            'manage_options',
            'local-plugin-updater',
            [$this, 'admin_page']
        );
    }

    /**
     * Admin scripts
     */
    public function admin_scripts($hook) {
        // Only load on our admin page and plugins page
        if ($hook !== 'plugins.php' && $hook !== 'plugins_page_local-plugin-updater') {
            return;
        }

        // Enqueue dashicons - needed for checkboxes and other icons
        wp_enqueue_style('dashicons');

        wp_enqueue_style(
            'local-plugin-updater-css',
            LPU_PLUGIN_URL . 'assets/css/admin.css',
            ['dashicons'],
            LPU_VERSION
        );

        wp_enqueue_script(
            'local-plugin-updater-js',
            LPU_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            LPU_VERSION,
            true
        );

        wp_localize_script('local-plugin-updater-js', 'LPU', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('lpu_nonce'),
            'checking' => __('Checking for updates...', 'happy-local-plugin-updater'),
            'updating' => __('Updating plugin...', 'happy-local-plugin-updater'),
            'updated' => __('Plugin updated successfully!', 'happy-local-plugin-updater'),
            'error' => __('An error occurred. Please try again.', 'happy-local-plugin-updater'),
            'no_updates' => __('No updates available.', 'happy-local-plugin-updater'),
            'check' => __('Check for Updates', 'happy-local-plugin-updater'),
            'update' => __('Update', 'happy-local-plugin-updater'),
        ]);
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting(
            'lpu_settings_group', 
            'lpu_settings',
            [
                'sanitize_callback' => [$this, 'sanitize_settings'],
                'default' => [
                    'repo_path' => LPU_DEFAULT_REPO_PATH,
                    'auto_check' => false,
                    'check_frequency' => 'daily',
                    'auto_update' => false,
                    'debug_mode' => false,
                ],
            ]
        );
        
        add_settings_section(
            'lpu_settings_section',
            esc_html__('Repository Settings', 'happy-local-plugin-updater'),
            [$this, 'settings_section_callback'],
            'lpu_settings'
        );
        
        add_settings_field(
            'lpu_repo_path',
            esc_html__('Repository Path', 'happy-local-plugin-updater'),
            [$this, 'repo_path_callback'],
            'lpu_settings',
            'lpu_settings_section'
        );
        
        add_settings_field(
            'lpu_debug_mode',
            esc_html__('Debug Mode', 'happy-local-plugin-updater'),
            [$this, 'debug_mode_callback'],
            'lpu_settings',
            'lpu_settings_section'
        );
        
        add_settings_field(
            'lpu_auto_check',
            esc_html__('Auto Check Updates', 'happy-local-plugin-updater'),
            [$this, 'auto_check_callback'],
            'lpu_settings',
            'lpu_settings_section'
        );
        
        add_settings_field(
            'lpu_check_frequency',
            esc_html__('Check Frequency', 'happy-local-plugin-updater'),
            [$this, 'check_frequency_callback'],
            'lpu_settings',
            'lpu_settings_section',
            ['class' => 'lpu-frequency-row hidden']
        );
        
        add_settings_field(
            'lpu_auto_update',
            esc_html__('Auto Update Plugins', 'happy-local-plugin-updater'),
            [$this, 'auto_update_callback'],
            'lpu_settings',
            'lpu_settings_section',
            ['class' => 'lpu-auto-update-row hidden']
        );
    }
    
    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $sanitized = [];
        
        // Sanitize repository path
        $sanitized['repo_path'] = isset($input['repo_path']) 
            ? sanitize_text_field($input['repo_path']) 
            : LPU_DEFAULT_REPO_PATH;
            
        // Sanitize auto check (boolean)
        $sanitized['auto_check'] = isset($input['auto_check']) && $input['auto_check'] 
            ? true 
            : false;
            
        // Sanitize check frequency (must be one of the allowed values)
        $allowed_frequencies = ['hourly', 'twicedaily', 'daily'];
        $sanitized['check_frequency'] = isset($input['check_frequency']) && in_array($input['check_frequency'], $allowed_frequencies) 
            ? $input['check_frequency'] 
            : 'daily';
            
        // Sanitize auto update (boolean)
        $sanitized['auto_update'] = isset($input['auto_update']) && $input['auto_update'] 
            ? true 
            : false;

        // Sanitize debug mode (boolean)
        $sanitized['debug_mode'] = isset($input['debug_mode']) && $input['debug_mode'] 
            ? true 
            : false;
            
        return $sanitized;
    }

    /**
     * Settings section callback
     */
    public function settings_section_callback() {
        echo '<p>' . esc_html__('Configure the local plugin repository settings. The repository should contain plugin ZIP files organized by plugin slug.', 'happy-local-plugin-updater') . '</p>';
        echo '<p>' . esc_html__('When Auto Check Updates is enabled, the plugin will periodically check for updates based on your selected frequency. If Auto Update Plugins is also enabled, found updates will be automatically installed.', 'happy-local-plugin-updater') . '</p>';
        
        // Check if repository path is set
        if (empty($this->settings['repo_path'])) {
            echo '<div class="notice notice-warning inline"><p><strong>' . 
                esc_html__('Repository path is not set!', 'happy-local-plugin-updater') . 
                '</strong> ' . 
                esc_html__('You must set a valid repository path for the plugin to work.', 'happy-local-plugin-updater') . 
                '</p></div>';
        }
        
        echo '<p>' . esc_html__('Example repository structure:', 'happy-local-plugin-updater') . '</p>';
        echo '<pre style="background:#f8f8f8;padding:10px;border-left:4px solid #0073aa;margin-bottom:15px;">your-repository-path/
├── woocommerce-shipping-ups/
│   └── woocommerce-shipping-ups.zip
├── woocommerce-subscriptions/
│   └── woocommerce-subscriptions.zip
└── other-plugin/
    └── other-plugin.zip</pre>';
    }

    /**
     * Auto check field callback
     */
    public function auto_check_callback() {
        $auto_check = isset($this->settings['auto_check']) ? $this->settings['auto_check'] : false;
        echo '<div class="lpu-option-wrapper">';
        echo '<div class="lpu-toggle-switch">';
        echo '<input type="checkbox" id="lpu_auto_check" name="lpu_settings[auto_check]" value="1" ' . checked($auto_check, 1, false) . ' />';
        echo '</div>';
        echo '<h4>' . esc_html__('Automatically check for updates', 'happy-local-plugin-updater') . '</h4>';
        echo '<span class="lpu-option-description">' . esc_html__('When enabled, the plugin will periodically check for updates from your local repository.', 'happy-local-plugin-updater') . '</span>';
        echo '</div>';
    }

    /**
     * Auto update field callback
     */
    public function auto_update_callback() {
        $auto_update = isset($this->settings['auto_update']) ? $this->settings['auto_update'] : false;
        echo '<div class="lpu-option-wrapper">';
        echo '<div class="lpu-toggle-switch">';
        echo '<input type="checkbox" id="lpu_auto_update" name="lpu_settings[auto_update]" value="1" ' . checked($auto_update, 1, false) . ' />';
        echo '</div>';
        echo '<h4>' . esc_html__('Automatically update plugins', 'happy-local-plugin-updater') . '</h4>';
        echo '<span class="lpu-option-description">' . esc_html__('When enabled, plugins will be automatically updated when updates are found in your local repository.', 'happy-local-plugin-updater') . '</span>';
        echo '</div>';
    }

    /**
     * Check frequency field callback
     */
    public function check_frequency_callback() {
        $check_frequency = isset($this->settings['check_frequency']) ? $this->settings['check_frequency'] : 'daily';
        $frequencies = [
            'hourly' => esc_html__('Hourly', 'happy-local-plugin-updater'),
            'twicedaily' => esc_html__('Twice Daily', 'happy-local-plugin-updater'),
            'daily' => esc_html__('Daily', 'happy-local-plugin-updater'),
            'weekly' => esc_html__('Weekly', 'happy-local-plugin-updater'),
        ];
        
        echo '<h4>' . esc_html__('How often to check for updates', 'happy-local-plugin-updater') . '</h4>';
        echo '<span class="lpu-option-description">' . esc_html__('Select how frequently the plugin should check for updates.', 'happy-local-plugin-updater') . '</span>';
        echo '<div class="lpu-select-wrapper">';
        echo '<select id="lpu_check_frequency" name="lpu_settings[check_frequency]">';
        foreach ($frequencies as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($check_frequency, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</div>';
    }

    /**
     * Debug mode field callback
     */
    public function debug_mode_callback() {
        $debug_mode = isset($this->settings['debug_mode']) ? $this->settings['debug_mode'] : false;
        echo '<div class="lpu-option-wrapper">';
        echo '<div class="lpu-toggle-switch">';
        echo '<input type="checkbox" id="lpu_debug_mode" name="lpu_settings[debug_mode]" value="1" ' . checked($debug_mode, 1, false) . ' />';
        echo '</div>';
        echo '<h4>' . esc_html__('Enable detailed logging', 'happy-local-plugin-updater') . '</h4>';
        echo '<span class="lpu-option-description">' . esc_html__('When enabled, detailed error messages and logs will be saved to help troubleshoot issues.', 'happy-local-plugin-updater') . '</span>';
        echo '</div>';
    }

    /**
     * Repository path field callback
     */
    public function repo_path_callback() {
        $repo_path = isset($this->settings['repo_path']) ? $this->settings['repo_path'] : LPU_DEFAULT_REPO_PATH;
        echo '<h4>' . esc_html__('Local plugin repository location', 'happy-local-plugin-updater') . '</h4>';
        echo '<span class="lpu-option-description">' . esc_html__('The absolute path to your local plugin repository.', 'happy-local-plugin-updater') . '</span>';
        echo '<div class="lpu-repo-path-wrapper">';
        echo '<textarea id="lpu_repo_path" name="lpu_settings[repo_path]" class="regular-text" style="width:100%;max-width:500px;min-height:60px;box-sizing:border-box;font-family:monospace;resize:vertical;">' . esc_textarea($repo_path) . '</textarea>';
        echo '<div class="lpu-path-actions">';
        echo '<button type="button" id="lpu-copy-path" class="button button-secondary" style="margin-top:5px;"><span class="dashicons dashicons-clipboard" style="margin-top:3px;"></span> ' . esc_html__('Copy Path', 'happy-local-plugin-updater') . '</button>';
        echo '</div>';
        echo '</div>';
    }

    /**
     * Plugin action links
     */
    public function plugin_action_links($links) {
        $settings_link = '<a href="' . admin_url('plugins.php?page=local-plugin-updater') . '">' . __('Settings', 'happy-local-plugin-updater') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Admin page
     */
    public function admin_page() {
        require_once LPU_PLUGIN_DIR . 'includes/admin-page.php';
    }

    /**
     * AJAX check updates
     */
    public function ajax_check_updates() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'lpu_nonce')) {
            wp_send_json_error(['message' => __('Security check failed.', 'happy-local-plugin-updater')]);
        }
        
        // Check permissions
        if (!current_user_can('update_plugins')) {
            wp_send_json_error(['message' => __('You do not have permission to update plugins.', 'happy-local-plugin-updater')]);
        }
        
        // Get installed plugins
        $installed_plugins = get_plugins();
        $repo_path = $this->settings['repo_path'];
        
        // Check if repo path is set
        if (empty($repo_path)) {
            wp_send_json_error(['message' => __('Repository path is not set. Please configure it in the plugin settings.', 'happy-local-plugin-updater')]);
        }
        
        try {
            $updater = new LPU_Updater($repo_path);
            $updates = $updater->check_updates($installed_plugins);
            
            // Reset the lpu_available_updates option to prevent stale data
            update_option('lpu_available_updates', [
                'updates' => $updates,
                'count' => count($updates),
                'last_checked' => current_time('timestamp'),
            ]);
            
            // Generate HTML for the updates
            $html = '';
            if (count($updates) > 0) {
                $html .= '<table class="widefat striped lpu-updates-table">';
                $html .= '<thead>';
                $html .= '<tr>';
                $html .= '<th>' . esc_html__('Plugin', 'happy-local-plugin-updater') . '</th>';
                $html .= '<th>' . esc_html__('Current Version', 'happy-local-plugin-updater') . '</th>';
                $html .= '<th>' . esc_html__('New Version', 'happy-local-plugin-updater') . '</th>';
                $html .= '<th>' . esc_html__('Actions', 'happy-local-plugin-updater') . '</th>';
                $html .= '</tr>';
                $html .= '</thead>';
                $html .= '<tbody>';
                
                foreach ($updates as $update) {
                    $html .= '<tr>';
                    $html .= '<td>' . esc_html($update['name']) . '</td>';
                    $html .= '<td>' . esc_html($update['current_version']) . '</td>';
                    $html .= '<td>' . esc_html($update['new_version']) . '</td>';
                    $html .= '<td>';
                    $html .= '<button type="button" class="button lpu-update-plugin" data-plugin="' . esc_attr($update['plugin']) . '">';
                    $html .= esc_html__('Update', 'happy-local-plugin-updater');
                    $html .= '</button>';
                    $html .= '</td>';
                    $html .= '</tr>';
                }
                
                $html .= '</tbody>';
                $html .= '</table>';
            } else {
                $html .= '<p class="lpu-no-updates">' . esc_html__('No updates available.', 'happy-local-plugin-updater') . '</p>';
            }
            
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
        
        wp_send_json_success([
            'updates' => $updates,
            'count' => count($updates),
            'html' => $html,
            'last_checked' => 'just now'
        ]);
    }

    /**
     * AJAX update plugin
     */
    public function ajax_update_plugin() {
        // Get debug mode setting
        $debug_mode = isset($this->settings['debug_mode']) ? $this->settings['debug_mode'] : false;
        
        // Log file
        $log_file = LPU_PLUGIN_DIR . 'debug.log';
        
        try {
        // Verify nonce
            if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'lpu_nonce')) {
                if ($debug_mode) {
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Nonce verification failed\n", FILE_APPEND);
                }
            wp_send_json_error(['message' => __('Security check failed.', 'happy-local-plugin-updater')]);
        }
        
        // Check permissions
        if (!current_user_can('update_plugins')) {
                if ($debug_mode) {
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Permissions check failed\n", FILE_APPEND);
                }
            wp_send_json_error(['message' => __('You do not have permission to update plugins.', 'happy-local-plugin-updater')]);
        }
        
        // Check required data
        if (!isset($_POST['plugin']) || empty($_POST['plugin'])) {
                if ($debug_mode) {
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - No plugin specified\n", FILE_APPEND);
                }
            wp_send_json_error(['message' => __('No plugin specified.', 'happy-local-plugin-updater')]);
        }
        
            $plugin_file = sanitize_text_field(wp_unslash($_POST['plugin']));
        $repo_path = $this->settings['repo_path'];
        
            // Check if repository path is set
            if (empty($repo_path)) {
                if ($debug_mode) {
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Repository path not set\n", FILE_APPEND);
                }
                wp_send_json_error(['message' => __('Repository path is not set. Please configure it in the plugin settings.', 'happy-local-plugin-updater')]);
            }
            
            // Log debug info
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Updating plugin: " . $plugin_file . "\n", FILE_APPEND);
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Repository path: " . $repo_path . "\n", FILE_APPEND);
            }
            
            // Check if repository path exists
            if (!file_exists($repo_path)) {
                if ($debug_mode) {
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Repository path does not exist\n", FILE_APPEND);
                }
                wp_send_json_error(['message' => __('Repository path does not exist.', 'happy-local-plugin-updater')]);
            }
            
            try {
        $updater = new LPU_Updater($repo_path);
                
                // Try the direct update method first
                if ($debug_mode) {
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Using direct update method\n", FILE_APPEND);
                }
                $result = $updater->direct_update_plugin($plugin_file);
                
                if (is_wp_error($result)) {
                    if ($debug_mode) {
                        file_put_contents($log_file, date('Y-m-d H:i:s') . " - Direct update failed: " . $result->get_error_message() . "\n", FILE_APPEND);
                        file_put_contents($log_file, date('Y-m-d H:i:s') . " - Falling back to standard update method\n", FILE_APPEND);
                    }
                    
                    // Fall back to standard method
        $result = $updater->update_plugin($plugin_file);
                }
            } catch (Exception $e) {
                if ($debug_mode) {
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Repository error: " . $e->getMessage() . "\n", FILE_APPEND);
                }
                wp_send_json_error(['message' => $e->getMessage()]);
            }
        
        if (is_wp_error($result)) {
                if ($debug_mode) {
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Update error: " . $result->get_error_message() . "\n", FILE_APPEND);
                }
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
            
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Update successful\n", FILE_APPEND);
            }
            
            // Force refresh update count
            $this->maybe_clear_update_count();
        
            wp_send_json_success([
                'message' => __('Plugin updated successfully!', 'happy-local-plugin-updater'),
                'plugin' => $plugin_file,
                'plugin_slug' => dirname($plugin_file),
                'plugin_base' => plugin_basename($plugin_file)
            ]);
        } catch (Exception $e) {
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Exception: " . $e->getMessage() . "\n", FILE_APPEND);
            }
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * AJAX synchronize updates
     */
    public function ajax_sync_updates() {
        // Get debug mode setting
        $debug_mode = isset($this->settings['debug_mode']) ? $this->settings['debug_mode'] : false;
        
        // Log file
        $log_file = LPU_PLUGIN_DIR . 'debug.log';
        
        if ($debug_mode) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Running AJAX sync updates\n", FILE_APPEND);
        }
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'lpu_nonce')) {
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Nonce verification failed in sync\n", FILE_APPEND);
            }
            wp_send_json_error(['message' => __('Security check failed.', 'happy-local-plugin-updater')]);
        }
        
        // Force a complete update refresh
        delete_site_transient('update_plugins');
        wp_clean_plugins_cache(true);
        delete_user_meta(get_current_user_id(), 'update_plugins');
        
        if ($debug_mode) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Deleted update_plugins transient and cleaned cache\n", FILE_APPEND);
        }
        
        // Get the plugin list
        $plugins = get_plugins();
        if ($debug_mode) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Retrieved " . count($plugins) . " installed plugins\n", FILE_APPEND);
        }
        
        // Get the update data fresh
        wp_update_plugins();
        
        // Get updated plugins list
        $updated_plugins = get_transient('lpu_updated_plugins');
        $refresh_needed = false;
        
        // Get the update cache after the refresh
        $update_cache = get_site_transient('update_plugins');
        
        if (is_object($update_cache) && isset($update_cache->response)) {
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Found " . count($update_cache->response) . " plugins in WordPress update cache\n", FILE_APPEND);
            }
            
            // Process any plugins we've recently updated
            if (is_array($updated_plugins) && !empty($updated_plugins)) {
                if ($debug_mode) {
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Processing " . count($updated_plugins) . " recently updated plugins\n", FILE_APPEND);
                }
                
                // Look for any plugins that shouldn't be in the update list
                foreach ($updated_plugins as $plugin_file) {
                    if (isset($update_cache->response[$plugin_file])) {
                        if ($debug_mode) {
                            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Removing " . $plugin_file . " from update cache\n", FILE_APPEND);
                        }
                        unset($update_cache->response[$plugin_file]);
                        $refresh_needed = true;
                    }
                }
                
                // Clear the updated plugins list after processing
                delete_transient('lpu_updated_plugins');
                if ($debug_mode) {
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Cleared updated plugins transient\n", FILE_APPEND);
                }
            }
            
            // If we made changes, update the transient
            if ($refresh_needed) {
                if ($debug_mode) {
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Updating site transient with corrected data\n", FILE_APPEND);
                }
                set_site_transient('update_plugins', $update_cache);
            }
        } else {
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - No plugins found in WordPress update cache or cache is invalid\n", FILE_APPEND);
            }
        }
        
        // Force an extra cleanup for good measure
        wp_version_check([], true);
        wp_update_plugins();
        
        wp_send_json_success([
            'message' => __('Update sync complete.', 'happy-local-plugin-updater'),
            'refreshNeeded' => $refresh_needed
        ]);
    }

    /**
     * Clear update count if needed
     */
    private function maybe_clear_update_count() {
        // Get debug mode setting
        $debug_mode = isset($this->settings['debug_mode']) ? $this->settings['debug_mode'] : false;
        $log_file = LPU_PLUGIN_DIR . 'debug.log';
        
        if ($debug_mode) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Running update cache cleanup\n", FILE_APPEND);
        }
        
        // Get current update cache
        $update_cache = get_site_transient('update_plugins');
        if (!is_object($update_cache) || empty($update_cache->response)) {
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - No updates in cache\n", FILE_APPEND);
            }
            return;
        }
        
        // Count remaining updates
        $remaining_updates = count($update_cache->response);
        if ($debug_mode) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Remaining updates in WordPress cache: " . $remaining_updates . "\n", FILE_APPEND);
        }
        
        // Get our updated plugins
        $plugin_file = isset($_POST['plugin']) ? sanitize_text_field(wp_unslash($_POST['plugin'])) : '';
        if (empty($plugin_file)) {
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - No plugin file specified in request\n", FILE_APPEND);
            }
            return;
        }
        
        // Make sure we add this plugin to our updates list
        $updated_plugins = get_transient('lpu_updated_plugins');
        if (!is_array($updated_plugins)) {
            $updated_plugins = [];
        }
        
        if (!in_array($plugin_file, $updated_plugins)) {
            $updated_plugins[] = $plugin_file;
            set_transient('lpu_updated_plugins', $updated_plugins, HOUR_IN_SECONDS);
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Added " . $plugin_file . " to updated plugins list\n", FILE_APPEND);
            }
        }
        
        // Step 1: Clear all transients and caches
        delete_site_transient('update_plugins');
        wp_clean_plugins_cache(true);
        delete_option('_site_transient_update_plugins');
        
        // Step 2: Get a fresh copy of installed plugins
        $plugins = get_plugins();
        if ($debug_mode) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Retrieved fresh plugin list with " . count($plugins) . " plugins\n", FILE_APPEND);
        }
        
        // Step 3: Remove our updated plugin from any user update counts
        $user_ids = get_users(['fields' => 'ID']);
        foreach ($user_ids as $user_id) {
            delete_user_meta($user_id, 'update_plugins');
        }
        
        if ($debug_mode) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Cleared update_plugins meta for all users\n", FILE_APPEND);
        }
        
        // Step 4: Force WordPress to check for updates again
        wp_update_plugins();
        
        // Step 5: Get the new update cache and manually remove our plugin
        $update_cache = get_site_transient('update_plugins');
        if (is_object($update_cache) && isset($update_cache->response[$plugin_file])) {
            unset($update_cache->response[$plugin_file]);
            set_site_transient('update_plugins', $update_cache);
            
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Manually removed " . $plugin_file . " from update cache\n", FILE_APPEND);
            }
        }
        
        // Step 6: Record that we've updated this plugin so we can keep checking
        // for it in the update cache until it's properly removed
        $expiration = HOUR_IN_SECONDS * 24; // Keep checking for 24 hours
        set_transient('lpu_updated_plugins', $updated_plugins, $expiration);
        
        if ($debug_mode) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Update cache cleanup complete\n", FILE_APPEND);
        }
    }
    
    /**
     * Reset update count on page load
     */
    public function reset_update_count() {
        // Get debug mode setting
        $debug_mode = isset($this->settings['debug_mode']) ? $this->settings['debug_mode'] : false;
        $log_file = LPU_PLUGIN_DIR . 'debug.log';
        
        if ($debug_mode) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Resetting update count on page load\n", FILE_APPEND);
        }
        
        // Get the plugins we've updated
        $updated_plugins = get_transient('lpu_updated_plugins');
        
        // Check the current update cache first
        $update_cache = get_site_transient('update_plugins');
        $needs_cleaning = false;
        
        // Special case handling for WooCommerce Subscriptions which can be problematic
        $subscriptions_plugin = 'woocommerce-subscriptions/woocommerce-subscriptions.php';
        
        if (is_object($update_cache) && isset($update_cache->response)) {
            // Log all plugins in the update cache
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Current plugins in update cache:\n", FILE_APPEND);
                foreach ($update_cache->response as $plugin => $data) {
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - " . $plugin . "\n", FILE_APPEND);
                }
            }
            
            // Check if WooCommerce Subscriptions is in the update cache
            if (isset($update_cache->response[$subscriptions_plugin])) {
                // Check if WooCommerce Subscriptions has actually been updated
                $subscriptions_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $subscriptions_plugin, false, false);
                $cached_version = isset($update_cache->response[$subscriptions_plugin]->new_version) ? 
                    $update_cache->response[$subscriptions_plugin]->new_version : '';
                
                if ($debug_mode) {
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - WooCommerce Subscriptions found in update cache\n", FILE_APPEND);
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Installed version: " . $subscriptions_data['Version'] . "\n", FILE_APPEND);
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Cached update version: " . $cached_version . "\n", FILE_APPEND);
                }
                
                // If the installed version is >= the cached update version, remove from cache
                if (version_compare($subscriptions_data['Version'], $cached_version, '>=')) {
                    if ($debug_mode) {
                        file_put_contents($log_file, date('Y-m-d H:i:s') . " - Removing WooCommerce Subscriptions from update cache (already updated)\n", FILE_APPEND);
                    }
                    unset($update_cache->response[$subscriptions_plugin]);
                    $needs_cleaning = true;
                    
                    // Add to updated plugins list if not already there
                    if (!is_array($updated_plugins)) {
                        $updated_plugins = [];
                    }
                    if (!in_array($subscriptions_plugin, $updated_plugins)) {
                        $updated_plugins[] = $subscriptions_plugin;
                    }
                }
            }
            
            // If we need to update the cache, do it now
            if ($needs_cleaning) {
                set_site_transient('update_plugins', $update_cache);
                set_transient('lpu_updated_plugins', $updated_plugins, HOUR_IN_SECONDS * 24);
                if ($debug_mode) {
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Update cache cleaned for specific plugins\n", FILE_APPEND);
                }
            }
        }
        
        // Process any updated plugins from our history
        if (is_array($updated_plugins) && !empty($updated_plugins)) {
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Found " . count($updated_plugins) . " plugins in the update history\n", FILE_APPEND);
            }
            
            // Force WordPress to get fresh update data by deleting the transient
            delete_site_transient('update_plugins');
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Deleted update_plugins transient\n", FILE_APPEND);
            }
            
            // Clear user update counts
            delete_user_meta(get_current_user_id(), 'update_plugins');
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Deleted user update count meta\n", FILE_APPEND);
            }
            
            // Force WordPress to get fresh plugin data
            wp_clean_plugins_cache(true);
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Cleaned plugins cache\n", FILE_APPEND);
            }
            
            // Reset the transient to prevent continuous cache clearing, but keep our list of updated plugins
            // We'll maintain this list for a while to ensure those plugins don't come back into the update list
            set_transient('lpu_updated_plugins', $updated_plugins, HOUR_IN_SECONDS * 24);
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Maintained updated plugins history for 24 hours\n", FILE_APPEND);
            }
        }
        
        // Force WordPress to refresh all update data
        wp_version_check([], true);
        wp_update_plugins();
        wp_update_themes();
        if ($debug_mode) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Forced WordPress to check for updates\n", FILE_APPEND);
        }
        
        // Check the new update count
        $update_cache = get_site_transient('update_plugins');
        if (is_object($update_cache) && isset($update_cache->response)) {
            $count = count($update_cache->response);
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - New update count: " . $count . "\n", FILE_APPEND);
                
                // Log all plugins in the update cache
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Plugins in update cache after reset:\n", FILE_APPEND);
                foreach ($update_cache->response as $plugin => $data) {
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - " . $plugin . "\n", FILE_APPEND);
                }
            }
            
            // Perform a final cleanup on any plugins that should have been removed
            if (is_array($updated_plugins) && !empty($updated_plugins) && $count > 0) {
                $cache_changed = false;
                
                foreach ($updated_plugins as $plugin_file) {
                    if (isset($update_cache->response[$plugin_file])) {
                        unset($update_cache->response[$plugin_file]);
                        $cache_changed = true;
                        
                        if ($debug_mode) {
                            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Final cleanup: Removed " . $plugin_file . " from update cache\n", FILE_APPEND);
                        }
                    }
                }
                
                if ($cache_changed) {
                    set_site_transient('update_plugins', $update_cache);
                }
            }
        }
    }

    /**
     * Check repository path for issues and display admin notice if needed
     */
    public function check_repository_path() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Check if we're on our plugin settings page
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'plugins_page_local-plugin-updater') === false) {
            // Only check repository path and show notice on other admin pages
            if (empty($this->settings['repo_path'])) {
                // Repository path is empty
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-error is-dismissible">';
                    echo '<p><strong>' . esc_html__('Happy Local Plugin Updater', 'happy-local-plugin-updater') . ':</strong> ';
                    echo esc_html__('Repository path is not set. You must configure the plugin before it will work.', 'happy-local-plugin-updater') . '</p>';
                    echo '<p><a href="' . esc_url(admin_url('plugins.php?page=local-plugin-updater')) . '" class="button button-primary">';
                    echo esc_html__('Configure Settings', 'happy-local-plugin-updater') . '</a></p>';
                    echo '</div>';
                });
            } elseif (!file_exists($this->settings['repo_path'])) {
                // Repository path doesn't exist
                $repo_path = $this->settings['repo_path'];
                add_action('admin_notices', function() use ($repo_path) {
                    echo '<div class="notice notice-error is-dismissible">';
                    echo '<p><strong>' . esc_html__('Happy Local Plugin Updater', 'happy-local-plugin-updater') . ':</strong> ';
                    echo esc_html__('Repository path does not exist: ', 'happy-local-plugin-updater') . '<code>' . esc_html($repo_path) . '</code></p>';
                    echo '<p><a href="' . esc_url(admin_url('plugins.php?page=local-plugin-updater')) . '" class="button button-primary">';
                    echo esc_html__('Fix Settings', 'happy-local-plugin-updater') . '</a></p>';
                    echo '</div>';
                });
            } elseif (!is_dir($this->settings['repo_path'])) {
                // Repository path is not a directory
                $repo_path = $this->settings['repo_path'];
                add_action('admin_notices', function() use ($repo_path) {
                    echo '<div class="notice notice-error is-dismissible">';
                    echo '<p><strong>' . esc_html__('Happy Local Plugin Updater', 'happy-local-plugin-updater') . ':</strong> ';
                    echo esc_html__('Repository path is not a directory: ', 'happy-local-plugin-updater') . '<code>' . esc_html($repo_path) . '</code></p>';
                    echo '<p><a href="' . esc_url(admin_url('plugins.php?page=local-plugin-updater')) . '" class="button button-primary">';
                    echo esc_html__('Fix Settings', 'happy-local-plugin-updater') . '</a></p>';
                    echo '</div>';
                });
            } elseif (!is_readable($this->settings['repo_path'])) {
                // Repository path is not readable
                $repo_path = $this->settings['repo_path'];
                add_action('admin_notices', function() use ($repo_path) {
                    echo '<div class="notice notice-error is-dismissible">';
                    echo '<p><strong>' . esc_html__('Happy Local Plugin Updater', 'happy-local-plugin-updater') . ':</strong> ';
                    echo esc_html__('Repository path is not readable: ', 'happy-local-plugin-updater') . '<code>' . esc_html($repo_path) . '</code></p>';
                    echo '<p><a href="' . esc_url(admin_url('plugins.php?page=local-plugin-updater')) . '" class="button button-primary">';
                    echo esc_html__('Fix Settings', 'happy-local-plugin-updater') . '</a></p>';
                    echo '</div>';
                });
            }
        }
    }

    /**
     * Auto check for updates
     */
    public function auto_check_updates() {
        // Get debug mode setting
        $debug_mode = isset($this->settings['debug_mode']) ? $this->settings['debug_mode'] : false;
        
        // Log file
        $log_file = LPU_PLUGIN_DIR . 'debug.log';
        
        if ($debug_mode) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Running auto check for updates\n", FILE_APPEND);
        }
        
        // Check if auto check is enabled
        if (!isset($this->settings['auto_check']) || !$this->settings['auto_check']) {
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Auto check disabled\n", FILE_APPEND);
            }
            return;
        }
        
        // Get last check time
        $updates = get_option('lpu_available_updates', [
            'updates' => [],
            'count' => 0,
            'last_checked' => 0
        ]);
        
        // Get check frequency
        $frequency = isset($this->settings['check_frequency']) ? $this->settings['check_frequency'] : 'daily';
        
        // Calculate time since last check
        $time_since_last_check = current_time('timestamp') - $updates['last_checked'];
        
        // Get time threshold based on frequency
        $threshold = 0;
        switch ($frequency) {
            case 'hourly':
                $threshold = HOUR_IN_SECONDS;
                break;
            case 'twicedaily':
                $threshold = 12 * HOUR_IN_SECONDS;
                break;
            case 'daily':
            default:
                $threshold = DAY_IN_SECONDS;
                break;
        }
        
        // Check if it's time to check for updates
        if ($time_since_last_check < $threshold) {
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Not time to check yet. Last check: " . human_time_diff($updates['last_checked'], current_time('timestamp')) . " ago\n", FILE_APPEND);
            }
            return;
        }
        
        // Get repository path
        $repo_path = $this->settings['repo_path'];
        
        // Check if repository path is set
        if (empty($repo_path)) {
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Repository path not set\n", FILE_APPEND);
            }
            return;
        }
        
        // Check if repository path exists
        if (!file_exists($repo_path)) {
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Repository path does not exist\n", FILE_APPEND);
            }
            return;
        }
        
        try {
            // Get installed plugins
            $installed_plugins = get_plugins();
            
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Checking for updates in " . $repo_path . "\n", FILE_APPEND);
            }
            
            $updater = new LPU_Updater($repo_path);
            $available_updates = $updater->check_updates($installed_plugins);
            
            // Update available updates
            update_option('lpu_available_updates', [
                'updates' => $available_updates,
                'count' => count($available_updates),
                'last_checked' => current_time('timestamp')
            ]);
            
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Found " . count($available_updates) . " updates\n", FILE_APPEND);
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Updated 'last_checked' timestamp\n", FILE_APPEND);
            }
            
            // Auto update plugins if enabled
            if (isset($this->settings['auto_update']) && $this->settings['auto_update'] && !empty($available_updates)) {
                if ($debug_mode) {
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Auto update enabled. Updating plugins...\n", FILE_APPEND);
                }
                
                foreach ($available_updates as $update) {
                    if ($debug_mode) {
                        file_put_contents($log_file, date('Y-m-d H:i:s') . " - Updating " . $update['plugin'] . "\n", FILE_APPEND);
                    }
                    
                    $result = $updater->direct_update_plugin($update['plugin']);
                    
                    if (is_wp_error($result)) {
                        if ($debug_mode) {
                            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Error updating " . $update['plugin'] . ": " . $result->get_error_message() . "\n", FILE_APPEND);
                        }
                        
                        // Try standard update method
                        $result = $updater->update_plugin($update['plugin']);
                        
                        if (is_wp_error($result)) {
                            if ($debug_mode) {
                                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Error updating " . $update['plugin'] . ": " . $result->get_error_message() . "\n", FILE_APPEND);
                            }
                        } else {
                            if ($debug_mode) {
                                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Successfully updated " . $update['plugin'] . "\n", FILE_APPEND);
                            }
                        }
                    } else {
                        if ($debug_mode) {
                            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Successfully updated " . $update['plugin'] . "\n", FILE_APPEND);
                        }
                    }
                }
                
                // Check for updates again to update the list
                $available_updates = $updater->check_updates($installed_plugins);
                
                // Update available updates
                update_option('lpu_available_updates', [
                    'updates' => $available_updates,
                    'count' => count($available_updates),
                    'last_checked' => current_time('timestamp')
                ]);
                
                if ($debug_mode) {
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Updated available updates list after auto update\n", FILE_APPEND);
                }
            }
        } catch (Exception $e) {
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Error checking for updates: " . $e->getMessage() . "\n", FILE_APPEND);
            }
        }
    }
}

// Initialize the plugin
function lpu_init() {
    return Local_Plugin_Updater::get_instance();
}

// Start the plugin
lpu_init(); 

/**
 * Check for updates on plugin activation
 */
register_activation_hook(__FILE__, 'lpu_activation');
function lpu_activation() {
    // Set default settings with all required fields
    $current_settings = get_option('lpu_settings', []);
    $default_settings = [
        'repo_path' => isset($current_settings['repo_path']) ? $current_settings['repo_path'] : LPU_DEFAULT_REPO_PATH,
        'auto_check' => isset($current_settings['auto_check']) ? $current_settings['auto_check'] : false,
        'check_frequency' => isset($current_settings['check_frequency']) ? $current_settings['check_frequency'] : 'daily',
        'auto_update' => isset($current_settings['auto_update']) ? $current_settings['auto_update'] : false,
        'debug_mode' => isset($current_settings['debug_mode']) ? $current_settings['debug_mode'] : false,
    ];
    update_option('lpu_settings', $default_settings);
    
    // Initial update check
    if (function_exists('lpu_check_for_updates')) {
        lpu_check_for_updates();
    }
    
    // Schedule automatic checks if enabled
    if (function_exists('lpu_schedule_update_checks')) {
        lpu_schedule_update_checks();
    }
}

/**
 * Clean up on plugin deactivation
 */
register_deactivation_hook(__FILE__, 'lpu_deactivation');
function lpu_deactivation() {
    // Unschedule events
    wp_clear_scheduled_hook('lpu_check_updates_event');
    
    // Remove stored updates
    delete_option('lpu_available_updates');
} 