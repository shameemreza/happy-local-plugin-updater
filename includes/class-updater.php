<?php
/**
 * Plugin Updater Class
 * 
 * Handles updating plugins from local repository
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class LPU_Updater {
    
    /**
     * Repository path
     */
    private $repo_path;
    
    /**
     * Constructor
     * 
     * @param string $repo_path Repository path
     * @throws Exception If repository path is not valid
     */
    public function __construct($repo_path) {
        // Validate repository path
        if (empty($repo_path)) {
            throw new Exception(__('Repository path is empty. Please configure it in the plugin settings.', 'happy-local-plugin-updater'));
        }
        
        if (!file_exists($repo_path)) {
            throw new Exception(__('Repository path does not exist: ', 'happy-local-plugin-updater') . $repo_path);
        }
        
        if (!is_dir($repo_path)) {
            throw new Exception(__('Repository path is not a directory: ', 'happy-local-plugin-updater') . $repo_path);
        }
        
        if (!is_readable($repo_path)) {
            throw new Exception(__('Repository path is not readable: ', 'happy-local-plugin-updater') . $repo_path);
        }
        
        $this->repo_path = $repo_path;
    }
    
    /**
     * Check for updates
     */
    public function check_updates($installed_plugins) {
        $updates = [];
        
        // Loop through installed plugins
        foreach ($installed_plugins as $plugin_file => $plugin_data) {
            $plugin_slug = dirname($plugin_file);
            
            // Skip plugins without a directory (single file plugins)
            if ($plugin_slug === '.') {
                continue;
            }
            
            // Check if plugin exists in repository
            $repo_plugin_dir = $this->find_plugin_in_repo($plugin_slug);
            
            if ($repo_plugin_dir) {
                // Check if the repository version is newer
                $repo_version = $this->get_repo_plugin_version($repo_plugin_dir, $plugin_slug);
                
                if ($repo_version && version_compare($repo_version, $plugin_data['Version'], '>')) {
                    $updates[] = [
                        'plugin' => $plugin_file,
                        'slug' => $plugin_slug,
                        'name' => $plugin_data['Name'],
                        'current_version' => $plugin_data['Version'],
                        'new_version' => $repo_version,
                        'repo_path' => $repo_plugin_dir,
                    ];
                }
            }
        }
        
        return $updates;
    }
    
    /**
     * Find plugin in repository
     */
    private function find_plugin_in_repo($plugin_slug) {
        // Look for exact match first
        $exact_match = $this->repo_path . '/' . $plugin_slug;
        if (is_dir($exact_match)) {
            return $exact_match;
        }
        
        // Then look for a zip file
        $zip_file = $this->repo_path . '/' . $plugin_slug . '/' . $plugin_slug . '.zip';
        if (file_exists($zip_file)) {
            return $this->repo_path . '/' . $plugin_slug;
        }
        
        // Search for alternative matches (case insensitive, hyphens vs underscores)
        $normalized_slug = strtolower(str_replace(['-', '_'], '', $plugin_slug));
        $dirs = glob($this->repo_path . '/*', GLOB_ONLYDIR);
        
        foreach ($dirs as $dir) {
            $dir_name = basename($dir);
            $normalized_dir = strtolower(str_replace(['-', '_'], '', $dir_name));
            
            if ($normalized_dir === $normalized_slug) {
                // Check for zip file
                $zip_file = $dir . '/' . $dir_name . '.zip';
                if (file_exists($zip_file)) {
                    return $dir;
                }
                
                // Check for any zip file
                $zip_files = glob($dir . '/*.zip');
                if (!empty($zip_files)) {
                    return $dir;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Get plugin version from repository
     */
    private function get_repo_plugin_version($repo_plugin_dir, $plugin_slug) {
        // Check for zip file
        $zip_file = $repo_plugin_dir . '/' . $plugin_slug . '.zip';
        if (!file_exists($zip_file)) {
            // Try with directory name
            $dir_name = basename($repo_plugin_dir);
            $zip_file = $repo_plugin_dir . '/' . $dir_name . '.zip';
            
            // If still not found, look for any zip file
            if (!file_exists($zip_file)) {
                $zip_files = glob($repo_plugin_dir . '/*.zip');
                if (!empty($zip_files)) {
                    $zip_file = $zip_files[0];
                } else {
                    return false;
                }
            }
        }
        
        // Create a temporary directory
        $temp_dir = wp_tempnam('lpu-');
        
        // Initialize WP_Filesystem
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH . '/wp-admin/includes/file.php');
            WP_Filesystem();
        }
        
        // Delete file and create directory
        $wp_filesystem->delete($temp_dir);
        $wp_filesystem->mkdir($temp_dir, 0755);
        
        // Extract the plugin header from the zip file
        $zip = new ZipArchive();
        if ($zip->open($zip_file) !== true) {
            $this->cleanup_temp_dir($temp_dir);
            return false;
        }
        
        // Find the main plugin file
        $main_file = $this->find_main_plugin_file($zip, $plugin_slug);
        if (!$main_file) {
            $zip->close();
            $this->cleanup_temp_dir($temp_dir);
            return false;
        }
        
        // Extract the main plugin file
        $zip->extractTo($temp_dir, $main_file);
        $zip->close();
        
        // Parse plugin header
        $plugin_data = get_plugin_data($temp_dir . '/' . $main_file, false, false);
        
        // Clean up
        $this->cleanup_temp_dir($temp_dir);
        
        return isset($plugin_data['Version']) ? $plugin_data['Version'] : false;
    }
    
    /**
     * Find main plugin file in zip
     */
    private function find_main_plugin_file($zip, $plugin_slug) {
        // First, try to find a file with the plugin slug name
        $plugin_file = $plugin_slug . '/' . $plugin_slug . '.php';
        if ($zip->locateName($plugin_file) !== false) {
            return $plugin_file;
        }
        
        // Next, try to find any PHP file in the root that might be the main file
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            
            // Only look at PHP files in the plugin directory
            if (strpos($filename, $plugin_slug . '/') === 0 && 
                substr($filename, -4) === '.php' && 
                substr_count($filename, '/') === 1) {
                
                // Read the file content to check for Plugin Name header
                $content = $zip->getFromIndex($i);
                if (strpos($content, 'Plugin Name:') !== false) {
                    return $filename;
                }
            }
        }
        
        // If we can't find it with the exact plugin slug, try with a more generic approach
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            
            // Check for PHP files at the root of any directory
            if (substr($filename, -4) === '.php' && substr_count($filename, '/') === 1) {
                $content = $zip->getFromIndex($i);
                if (strpos($content, 'Plugin Name:') !== false) {
                    return $filename;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Clean up temporary directory
     */
    private function cleanup_temp_dir($dir) {
        if (!is_dir($dir)) {
            return;
        }
        
        // Initialize WP_Filesystem if not already
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once(ABSPATH . '/wp-admin/includes/file.php');
            WP_Filesystem();
        }
        
        // Remove directory and all contents recursively
        $wp_filesystem->rmdir($dir, true);
    }
    
    /**
     * Update plugin
     */
    public function update_plugin($plugin_file) {
        // Get debug mode setting
        $settings = get_option('lpu_settings', [
            'debug_mode' => false,
        ]);
        
        // Ensure settings is an array
        if (!is_array($settings)) {
            $settings = [
                'debug_mode' => false,
            ];
        }
        
        $debug_mode = isset($settings['debug_mode']) ? $settings['debug_mode'] : false;
        
        // Log file
        $log_file = dirname(dirname(__FILE__)) . '/debug.log';
        
        // Only log if debug mode is enabled
        if ($debug_mode) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Starting update for: " . $plugin_file . "\n", FILE_APPEND);
        }
        
        // Get plugin info
        $plugin_slug = dirname($plugin_file);
        if ($debug_mode) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Plugin slug: " . $plugin_slug . "\n", FILE_APPEND);
        }
        
        // Find plugin in repository
        $repo_plugin_dir = $this->find_plugin_in_repo($plugin_slug);
        if ($debug_mode) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Repo plugin dir: " . ($repo_plugin_dir ? $repo_plugin_dir : 'not found') . "\n", FILE_APPEND);
        }
        
        if (!$repo_plugin_dir) {
            return new WP_Error('plugin_not_found', __('Plugin not found in repository.', 'happy-local-plugin-updater'));
        }
        
        // Find zip file
        $zip_file = $repo_plugin_dir . '/' . $plugin_slug . '.zip';
        if ($debug_mode) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Checking zip file: " . $zip_file . "\n", FILE_APPEND);
        }
        
        if (!file_exists($zip_file)) {
            // Try with directory name
            $dir_name = basename($repo_plugin_dir);
            $zip_file = $repo_plugin_dir . '/' . $dir_name . '.zip';
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Checking alternate zip file: " . $zip_file . "\n", FILE_APPEND);
            }
            
            // If still not found, look for any zip file
            if (!file_exists($zip_file)) {
                $zip_files = glob($repo_plugin_dir . '/*.zip');
                if ($debug_mode) {
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Searching for any zip files, found: " . count($zip_files) . "\n", FILE_APPEND);
                }
                
                if (!empty($zip_files)) {
                    $zip_file = $zip_files[0];
                    if ($debug_mode) {
                        file_put_contents($log_file, date('Y-m-d H:i:s') . " - Using zip file: " . $zip_file . "\n", FILE_APPEND);
                    }
                } else {
                    return new WP_Error('zip_not_found', __('Plugin ZIP file not found in repository.', 'happy-local-plugin-updater'));
                }
            }
        }
        
        // Prepare for update
        if ($debug_mode) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Preparing upgrader\n", FILE_APPEND);
        }
        
        try {
            if (!class_exists('Plugin_Upgrader')) {
                require_once(ABSPATH . 'wp-admin/includes/class-wp-upgrader.php');
                if ($debug_mode) {
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Loaded upgrader class\n", FILE_APPEND);
                }
            }
            
            $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Created upgrader instance\n", FILE_APPEND);
            }
            
            // Initialize WP_Filesystem
            global $wp_filesystem;
            if (empty($wp_filesystem)) {
                require_once(ABSPATH . 'wp-admin/includes/file.php');
                require_once(ABSPATH . 'wp-admin/includes/class-wp-upgrader.php');
                if ($debug_mode) {
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Initializing filesystem\n", FILE_APPEND);
                }
                WP_Filesystem();
            }
            
            // Use a temporary copy of the zip file
            $temp_zip = wp_tempnam('lpu-update-');
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Created temp file: " . $temp_zip . "\n", FILE_APPEND);
            }
            
            if (!$wp_filesystem->copy($zip_file, $temp_zip, true)) {
                if ($debug_mode) {
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Failed to copy zip file to temp location\n", FILE_APPEND);
                }
                return new WP_Error('copy_failed', __('Failed to create temporary copy of plugin ZIP file.', 'happy-local-plugin-updater'));
            }
            
            // Perform the update
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Starting upgrade\n", FILE_APPEND);
            }
            
            // Get the current timestamp of the plugin file
            $current_timestamp = filemtime(WP_PLUGIN_DIR . '/' . $plugin_file);
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Current plugin file timestamp: " . $current_timestamp . "\n", FILE_APPEND);
            }
            
            // Record the size of the zip file
            $zip_size = filesize($zip_file);
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Zip file size: " . $zip_size . " bytes\n", FILE_APPEND);
            }
            
            // Record plugin directory permissions
            $plugin_dir = WP_PLUGIN_DIR . '/' . $plugin_slug;
            $dir_perms = substr(sprintf('%o', fileperms($plugin_dir)), -4);
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Plugin directory permissions: " . $dir_perms . "\n", FILE_APPEND);
            }
            
            // Verify WordPress can write to the plugins directory
            if (!is_writable($plugin_dir)) {
                if ($debug_mode) {
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - WARNING: Plugin directory is not writable!\n", FILE_APPEND);
                }
            }
            
            // Change error reporting for the upgrade call
            $old_error_level = error_reporting();
            error_reporting(E_ALL);
            
            try {
                // Start output buffering to capture messages
                ob_start();
                
                // Add skin feedback capture
                $upgrader->skin->feedback('Installing the latest version...');
                
                $result = $upgrader->upgrade($plugin_file, [
                    'source' => $temp_zip,
                    'clear_destination' => true,
                    'hook_extra' => [
                        'plugin' => $plugin_file,
                        'type' => 'plugin',
                        'action' => 'update',
                    ],
                ]);
                
                // Get any errors or messages from the skin
                $errors = $upgrader->skin->get_errors();
                if (!empty($errors)) {
                    if ($debug_mode) {
                        file_put_contents($log_file, date('Y-m-d H:i:s') . " - Upgrader skin errors: " . print_r($errors, true) . "\n", FILE_APPEND);
                    }
                }
                
                // Get any feedback messages
                $feedback = ob_get_contents();
                if ($debug_mode) {
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Upgrader feedback: " . $feedback . "\n", FILE_APPEND);
                }
                
                // End output buffering
                ob_end_clean();
                
            } catch (Exception $e) {
                if ($debug_mode) {
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Exception during upgrade: " . $e->getMessage() . "\n", FILE_APPEND);
                }
                if (ob_get_level()) {
                    ob_end_clean();
                }
            }
            
            // Restore error reporting
            error_reporting($old_error_level);
            
            // Get the new timestamp of the plugin file
            $new_timestamp = filemtime(WP_PLUGIN_DIR . '/' . $plugin_file);
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - New plugin file timestamp: " . $new_timestamp . "\n", FILE_APPEND);
            }
            
            // Check if the timestamp changed (indicating the file was actually updated)
            if ($new_timestamp == $current_timestamp) {
                if ($debug_mode) {
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - WARNING: Plugin file timestamp didn't change. File may not have been updated!\n", FILE_APPEND);
                }
            }
            
            // Check plugin version after update
            if (function_exists('get_plugin_data')) {
                $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
                if ($debug_mode) {
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Plugin version after update: " . $plugin_data['Version'] . "\n", FILE_APPEND);
                }
            } else {
                // Try to load the function
                if (!function_exists('get_plugin_data')) {
                    require_once(ABSPATH . 'wp-admin/includes/plugin.php');
                }
                
                if (function_exists('get_plugin_data')) {
                    $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
                    if ($debug_mode) {
                        file_put_contents($log_file, date('Y-m-d H:i:s') . " - Plugin version after update: " . $plugin_data['Version'] . "\n", FILE_APPEND);
                    }
                } else {
                    if ($debug_mode) {
                        file_put_contents($log_file, date('Y-m-d H:i:s') . " - Could not load get_plugin_data function\n", FILE_APPEND);
                    }
                }
            }
            
            // Clean up
            $wp_filesystem->delete($temp_zip);
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Deleted temp file\n", FILE_APPEND);
            }
            
            if (is_wp_error($result)) {
                if ($debug_mode) {
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Upgrade error: " . $result->get_error_message() . "\n", FILE_APPEND);
                }
                return $result;
            } elseif (false === $result) {
                if ($debug_mode) {
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Upgrade failed for unknown reason\n", FILE_APPEND);
                }
                return new WP_Error('update_failed', __('Plugin update failed for an unknown reason.', 'happy-local-plugin-updater'));
            }
            
            // Update WordPress update cache
            if (function_exists('get_plugin_data') || require_once(ABSPATH . 'wp-admin/includes/plugin.php')) {
                $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
                $this->update_wordpress_update_cache($plugin_file, $plugin_data['Version']);
                
                // Force WordPress to refresh its plugin data
                wp_clean_plugins_cache(true);
                wp_update_plugins();
            }
            
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Upgrade successful\n", FILE_APPEND);
            }
            return true;
        } catch (Exception $e) {
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Exception: " . $e->getMessage() . "\n", FILE_APPEND);
            }
            return new WP_Error('exception', $e->getMessage());
        }
    }
    
    /**
     * Alternative update plugin using direct filesystem operations
     */
    public function direct_update_plugin($plugin_file) {
        // Get debug mode setting
        $settings = get_option('lpu_settings', [
            'debug_mode' => false,
        ]);
        
        // Ensure settings is an array
        if (!is_array($settings)) {
            $settings = [
                'debug_mode' => false,
            ];
        }
        
        $debug_mode = isset($settings['debug_mode']) ? $settings['debug_mode'] : false;
        
        // Log file
        $log_file = dirname(dirname(__FILE__)) . '/debug.log';
        
        // Only log if debug mode is enabled
        if ($debug_mode) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Starting DIRECT update for: " . $plugin_file . "\n", FILE_APPEND);
        }
        
        // Get plugin info
        $plugin_slug = dirname($plugin_file);
        if ($debug_mode) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Plugin slug: " . $plugin_slug . "\n", FILE_APPEND);
        }
        
        // Find plugin in repository
        $repo_plugin_dir = $this->find_plugin_in_repo($plugin_slug);
        if (!$repo_plugin_dir) {
            return new WP_Error('plugin_not_found', __('Plugin not found in repository.', 'happy-local-plugin-updater'));
        }
        
        // Find zip file
        $zip_file = $repo_plugin_dir . '/' . $plugin_slug . '.zip';
        if (!file_exists($zip_file)) {
            // Try with directory name
            $dir_name = basename($repo_plugin_dir);
            $zip_file = $repo_plugin_dir . '/' . $dir_name . '.zip';
            
            // If still not found, look for any zip file
            if (!file_exists($zip_file)) {
                $zip_files = glob($repo_plugin_dir . '/*.zip');
                if (!empty($zip_files)) {
                    $zip_file = $zip_files[0];
                } else {
                    return new WP_Error('zip_not_found', __('Plugin ZIP file not found in repository.', 'happy-local-plugin-updater'));
                }
            }
        }
        
        // Create a temporary directory
        $temp_dir = WP_CONTENT_DIR . '/upgrade/' . basename($plugin_slug) . '_' . time();
        
        // Make sure the temp directory exists
        if (!file_exists(WP_CONTENT_DIR . '/upgrade')) {
            mkdir(WP_CONTENT_DIR . '/upgrade', 0755, true);
        }
        
        if (!file_exists($temp_dir)) {
            mkdir($temp_dir, 0755, true);
        }
        
        if ($debug_mode) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Temp directory: " . $temp_dir . "\n", FILE_APPEND);
        }
        
        // Extract zip file
        $zip = new ZipArchive();
        if ($zip->open($zip_file) !== true) {
            return new WP_Error('zip_open_failed', __('Could not open ZIP file.', 'happy-local-plugin-updater'));
        }
        
        if ($debug_mode) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Extracting ZIP file to temp directory\n", FILE_APPEND);
        }
        $zip->extractTo($temp_dir);
        $zip->close();
        
        // Get the plugin directory inside the extracted contents
        $extracted_dir = $temp_dir;
        $dirs = glob($temp_dir . '/*', GLOB_ONLYDIR);
        if (!empty($dirs)) {
            // Usually the zip contains a directory with the plugin name
            $extracted_dir = $dirs[0];
        }
        
        if ($debug_mode) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Extracted directory: " . $extracted_dir . "\n", FILE_APPEND);
        }
        
        // Verify the extracted directory has the plugin file
        if (!file_exists($extracted_dir . '/' . basename($plugin_file))) {
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - ERROR: Main plugin file not found in extracted directory\n", FILE_APPEND);
            }
            $this->cleanup_temp_dir($temp_dir);
            return new WP_Error('invalid_plugin', __('The extracted ZIP does not contain the expected plugin file.', 'happy-local-plugin-updater'));
        }
        
        // Get the current version and the new version
        if (!function_exists('get_plugin_data')) {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        
        // Include necessary functions
        if (!function_exists('is_plugin_active')) {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }
        
        $current_version = '';
        $new_version = '';
        
        if (function_exists('get_plugin_data')) {
            $current_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
            $current_version = $current_data['Version'];
            
            $new_data = get_plugin_data($extracted_dir . '/' . basename($plugin_file));
            $new_version = $new_data['Version'];
            
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Current version: " . $current_version . ", New version: " . $new_version . "\n", FILE_APPEND);
            }
        }
        
        // Destination directory
        $destination = WP_PLUGIN_DIR . '/' . $plugin_slug;
        if ($debug_mode) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Destination directory: " . $destination . "\n", FILE_APPEND);
        }
        
        // Check if destination is writable
        if (!is_writable($destination)) {
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - ERROR: Destination directory is not writable\n", FILE_APPEND);
            }
            $this->cleanup_temp_dir($temp_dir);
            return new WP_Error('destination_not_writable', __('The plugin directory is not writable.', 'happy-local-plugin-updater'));
        }
        
        // Deactivate the plugin if it's active
        $was_active = is_plugin_active($plugin_file);
        if ($was_active) {
            deactivate_plugins($plugin_file, true);
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Plugin was active, deactivated before update\n", FILE_APPEND);
            }
        }
        
        // Remove the existing plugin directory
        $this->recursive_remove_directory($destination);
        if ($debug_mode) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Removed existing plugin directory\n", FILE_APPEND);
        }
        
        // Create the destination directory
        if (!file_exists($destination)) {
            mkdir($destination, 0755, true);
        }
        
        // Copy the extracted files to the destination
        $this->recursive_copy($extracted_dir, $destination);
        if ($debug_mode) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Copied new plugin files to destination\n", FILE_APPEND);
        }
        
        // Clean up
        $this->cleanup_temp_dir($temp_dir);
        if ($debug_mode) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Cleaned up temporary directory\n", FILE_APPEND);
        }
        
        // Reactivate the plugin if it was active
        if ($was_active) {
            activate_plugin($plugin_file);
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Reactivated plugin\n", FILE_APPEND);
            }
        }
        
        // Verify the update was successful
        if (function_exists('get_plugin_data')) {
            $updated_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
            $updated_version = $updated_data['Version'];
            if ($debug_mode) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Updated version: " . $updated_version . "\n", FILE_APPEND);
            }
            
            if ($updated_version === $current_version) {
                if ($debug_mode) {
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - WARNING: Version didn't change after update\n", FILE_APPEND);
                }
            }
            
            // Update WordPress update cache
            $this->update_wordpress_update_cache($plugin_file, $updated_version);
            
            // Force WordPress to refresh its plugin data
            wp_clean_plugins_cache(true);
            wp_update_plugins();
        }
        
        if ($debug_mode) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Direct update completed successfully\n", FILE_APPEND);
        }
        return true;
    }
    
    /**
     * Recursively copy files and directories
     */
    private function recursive_copy($source, $dest) {
        $dir = opendir($source);
        @mkdir($dest, 0755, true);
        
        while (($file = readdir($dir)) !== false) {
            if ($file == '.' || $file == '..') {
                continue;
            }
            
            $source_path = $source . '/' . $file;
            $dest_path = $dest . '/' . $file;
            
            if (is_dir($source_path)) {
                $this->recursive_copy($source_path, $dest_path);
            } else {
                copy($source_path, $dest_path);
                chmod($dest_path, 0644); // Set appropriate file permissions
            }
        }
        
        closedir($dir);
    }
    
    /**
     * Recursively remove a directory and its contents
     */
    private function recursive_remove_directory($directory) {
        if (!is_dir($directory)) {
            return;
        }
        
        $files = array_diff(scandir($directory), ['.', '..']);
        foreach ($files as $file) {
            $path = $directory . '/' . $file;
            if (is_dir($path)) {
                $this->recursive_remove_directory($path);
            } else {
                @unlink($path);
            }
        }
        
        @rmdir($directory);
    }
    
    /**
     * Update WordPress update cache
     * 
     * This removes the plugin from WordPress's update cache so it no longer shows as needing an update
     */
    private function update_wordpress_update_cache($plugin_file, $new_version) {
        // Get debug mode setting
        $settings = get_option('lpu_settings', [
            'debug_mode' => false,
        ]);
        
        // Ensure settings is an array
        if (!is_array($settings)) {
            $settings = [
                'debug_mode' => false,
            ];
        }
        
        $debug_mode = isset($settings['debug_mode']) ? $settings['debug_mode'] : false;
        
        $log_file = dirname(dirname(__FILE__)) . '/debug.log';
        
        // Track this plugin as updated
        $updated_plugins = get_transient('lpu_updated_plugins');
        if (!is_array($updated_plugins)) {
            $updated_plugins = [];
        }
        
        if (!in_array($plugin_file, $updated_plugins)) {
            $updated_plugins[] = $plugin_file;
            set_transient('lpu_updated_plugins', $updated_plugins, DAY_IN_SECONDS);
            if ($debug_mode && file_exists($log_file)) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Added " . $plugin_file . " to updated plugins list\n", FILE_APPEND);
            }
        }
        
        // Get current update transient
        $update_cache = get_site_transient('update_plugins');
        if (!is_object($update_cache)) {
            if ($debug_mode && file_exists($log_file)) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - No update cache found\n", FILE_APPEND);
            }
            return;
        }
        
        if ($debug_mode && file_exists($log_file)) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Updating WordPress update cache for: " . $plugin_file . "\n", FILE_APPEND);
        }
        
        // Log the update cache state before modification
        if (isset($update_cache->response) && is_array($update_cache->response)) {
            if ($debug_mode && file_exists($log_file)) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Update cache has " . count($update_cache->response) . " plugins that need updates\n", FILE_APPEND);
            
                // List all plugins in the update cache
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Plugins in update cache:\n", FILE_APPEND);
                foreach ($update_cache->response as $plugin => $data) {
                    file_put_contents($log_file, date('Y-m-d H:i:s') . " - " . $plugin . "\n", FILE_APPEND);
                }
            }
        }
        
        // Remove this plugin from the updates list
        if (isset($update_cache->response[$plugin_file])) {
            unset($update_cache->response[$plugin_file]);
            if ($debug_mode && file_exists($log_file)) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Removed plugin from update cache\n", FILE_APPEND);
            }
        }
        
        // Add to no_update list to prevent it from showing as an update
        if (!isset($update_cache->no_update[$plugin_file])) {
            // Get plugin data
            if (!function_exists('get_plugin_data')) {
                require_once(ABSPATH . 'wp-admin/includes/plugin.php');
            }
            $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file, false, false);
            
            // Create a stdClass object with the plugin info
            $update_cache->no_update[$plugin_file] = (object) [
                'id' => $plugin_file,
                'slug' => dirname($plugin_file),
                'plugin' => $plugin_file,
                'new_version' => $new_version,
                'url' => $plugin_data['PluginURI'] ?? '',
                'package' => '',
                'icons' => [],
                'banners' => [],
                'banners_rtl' => [],
                'tested' => '',
                'requires_php' => '',
                'compatibility' => new stdClass(),
            ];
            
            if ($debug_mode && file_exists($log_file)) {
                file_put_contents($log_file, date('Y-m-d H:i:s') . " - Added plugin to no_update list\n", FILE_APPEND);
            }
        }
        
        // Update the transient
        set_site_transient('update_plugins', $update_cache);
        if ($debug_mode && file_exists($log_file)) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - Updated WordPress update transient\n", FILE_APPEND);
        }
    }
} 