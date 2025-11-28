<?php
/**
 * AJAX Handler Class
 * Handles AJAX requests from the frontend
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Mega_Upload_Ajax {

    /**
     * Initialize AJAX handlers
     */
    public static function init() {
        // AJAX action for logged-in users
        add_action('wp_ajax_mega_upload_get_credentials', array(__CLASS__, 'get_credentials'));

        // AJAX action for non-logged-in users (if allowed)
        add_action('wp_ajax_nopriv_mega_upload_get_credentials', array(__CLASS__, 'get_credentials'));

        // Validate upload permissions
        add_action('wp_ajax_mega_upload_validate', array(__CLASS__, 'validate_upload'));
        add_action('wp_ajax_nopriv_mega_upload_validate', array(__CLASS__, 'validate_upload'));

        // Set validation status (admin only)
        add_action('wp_ajax_mega_upload_set_validation_status', array(__CLASS__, 'set_validation_status'));
    }

    /**
     * Get Mega.nz credentials for frontend use
     * This should only return credentials if user has proper permissions
     */
    public static function get_credentials() {
        // Verify nonce
        if (!check_ajax_referer('mega_upload_nonce', 'nonce', false)) {
            wp_send_json_error(array(
                'message' => __('Security check failed', 'mega-nz-wp')
            ));
        }

        // Get shortcode settings from request
        $allowed_roles = isset($_POST['allowed_roles']) ? sanitize_text_field($_POST['allowed_roles']) : 'all';

        // Check if user has permission
        if (!self::user_has_permission($allowed_roles)) {
            wp_send_json_error(array(
                'message' => __('You do not have permission to upload files', 'mega-nz-wp')
            ));
        }

        // Get credentials
        $credentials = Mega_Upload_Plugin::get_credentials();

        // Check if credentials are configured
        if (empty($credentials['email']) || empty($credentials['password'])) {
            wp_send_json_error(array(
                'message' => __('Mega.nz credentials not configured. Please contact the site administrator.', 'mega-nz-wp')
            ));
        }

        // Return credentials
        wp_send_json_success(array(
            'email' => $credentials['email'],
            'password' => $credentials['password']
        ));
    }

    /**
     * Validate upload before processing
     */
    public static function validate_upload() {
        // Verify nonce
        if (!check_ajax_referer('mega_upload_nonce', 'nonce', false)) {
            wp_send_json_error(array(
                'message' => __('Security check failed', 'mega-nz-wp')
            ));
        }

        // Get parameters
        $file_name = isset($_POST['file_name']) ? sanitize_text_field($_POST['file_name']) : '';
        $file_size = isset($_POST['file_size']) ? intval($_POST['file_size']) : 0;
        $file_type = isset($_POST['file_type']) ? sanitize_text_field($_POST['file_type']) : '';
        $max_size = isset($_POST['max_size']) ? sanitize_text_field($_POST['max_size']) : '100MB';
        $allowed_types = isset($_POST['allowed_types']) ? sanitize_text_field($_POST['allowed_types']) : '';
        $allowed_roles = isset($_POST['allowed_roles']) ? sanitize_text_field($_POST['allowed_roles']) : 'all';

        // Check permissions
        if (!self::user_has_permission($allowed_roles)) {
            wp_send_json_error(array(
                'message' => __('You do not have permission to upload files', 'mega-nz-wp')
            ));
        }

        // Validate file size
        $max_size_bytes = self::parse_size($max_size);
        if ($file_size > $max_size_bytes) {
            wp_send_json_error(array(
                'message' => sprintf(
                    __('File size exceeds maximum allowed size of %s', 'mega-nz-wp'),
                    $max_size
                )
            ));
        }

        // Validate file type
        if (!empty($allowed_types)) {
            $extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $allowed_extensions = array_map('trim', explode(',', strtolower($allowed_types)));

            if (!in_array($extension, $allowed_extensions)) {
                wp_send_json_error(array(
                    'message' => sprintf(
                        __('File type .%s is not allowed. Allowed types: %s', 'mega-nz-wp'),
                        $extension,
                        $allowed_types
                    )
                ));
            }
        }

        // If all validations pass
        wp_send_json_success(array(
            'message' => __('File validation passed', 'mega-nz-wp')
        ));
    }

    /**
     * Check if current user has required permission
     */
    private static function user_has_permission($allowed_roles) {
        // If 'all' is specified, anyone can upload
        if ($allowed_roles === 'all' || empty($allowed_roles)) {
            return true;
        }

        // If user is not logged in and specific roles are required
        if (!is_user_logged_in()) {
            return false;
        }

        // Get current user
        $user = wp_get_current_user();

        // Parse allowed roles
        $roles = array_map('trim', explode(',', $allowed_roles));

        // Check if user has any of the allowed roles
        foreach ($roles as $role) {
            if (in_array($role, $user->roles)) {
                return true;
            }
        }

        // Admin always has permission
        if (in_array('administrator', $user->roles)) {
            return true;
        }

        return false;
    }

    /**
     * Parse size string (e.g., "10MB", "2GB") to bytes
     */
    private static function parse_size($size_string) {
        $size_string = strtoupper(trim($size_string));

        // Extract number and unit
        preg_match('/^(\d+(?:\.\d+)?)\s*([A-Z]*)$/', $size_string, $matches);

        if (empty($matches)) {
            return 100 * 1024 * 1024; // Default 100MB
        }

        $number = floatval($matches[1]);
        $unit = isset($matches[2]) ? $matches[2] : 'B';

        // Convert to bytes
        switch ($unit) {
            case 'KB':
                return $number * 1024;
            case 'MB':
                return $number * 1024 * 1024;
            case 'GB':
                return $number * 1024 * 1024 * 1024;
            case 'TB':
                return $number * 1024 * 1024 * 1024 * 1024;
            default:
                return $number; // Assume bytes
        }
    }

    /**
     * Set credential validation status (admin only)
     */
    public static function set_validation_status() {
        // Verify nonce
        if (!check_ajax_referer('mega_upload_admin_nonce', 'nonce', false)) {
            wp_send_json_error(array(
                'message' => __('Security check failed', 'mega-nz-wp')
            ));
        }

        // Check if user is admin
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'message' => __('Permission denied', 'mega-nz-wp')
            ));
        }

        // Get status
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';

        // Validate status
        if (!in_array($status, array('valid', 'invalid', 'pending', 'unknown'))) {
            wp_send_json_error(array(
                'message' => __('Invalid status', 'mega-nz-wp')
            ));
        }

        // Update option
        update_option('mega_upload_credentials_valid', $status);

        wp_send_json_success(array(
            'message' => __('Status updated', 'mega-nz-wp'),
            'status' => $status
        ));
    }
}
