<?php
/**
 * Plugin Name: Mega.nz WP
 * Description: Allow users to upload files to your Mega.nz account via shortcode with customizable permissions and limits
 * Version: 1.0.0
 * Author: OhJey
 * Author URI: https://x.com/OlijaHasan
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: mega-nz-wp
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('MEGA_UPLOAD_VERSION', '1.0.0');
define('MEGA_UPLOAD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MEGA_UPLOAD_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Main Mega Upload Plugin Class
 */
class Mega_Upload_Plugin {

    /**
     * Instance of this class
     */
    private static $instance = null;

    /**
     * Get instance of this class
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
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Load required dependencies
     */
    private function load_dependencies() {
        require_once MEGA_UPLOAD_PLUGIN_DIR . 'includes/class-mega-upload-ajax.php';
        require_once MEGA_UPLOAD_PLUGIN_DIR . 'includes/class-mega-upload-shortcode.php';
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Admin hooks
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_head', array($this, 'add_menu_icon_styles'));

        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Prevent WordPress from converting quotes in shortcode output
        add_filter('run_wptexturize', array($this, 'disable_wptexturize_for_shortcode'), 10, 1);
        add_filter('no_texturize_shortcodes', array($this, 'prevent_texturize_shortcode'));

        // Initialize shortcode and AJAX handlers
        Mega_Upload_Shortcode::init();
        Mega_Upload_Ajax::init();
    }

    /**
     * Prevent wptexturize from converting quotes in our shortcode
     */
    public function prevent_texturize_shortcode($shortcodes) {
        $shortcodes[] = 'mega_upload';
        return $shortcodes;
    }

    /**
     * Disable wptexturize for mega_upload shortcode content
     */
    public function disable_wptexturize_for_shortcode($run) {
        global $shortcode_tags;
        if (isset($shortcode_tags['mega_upload'])) {
            return false;
        }
        return $run;
    }

    /**
     * Add admin menu page
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Mega.nz WP Settings', 'mega-nz-wp'),      // Page title
            __('Mega.nz WP', 'mega-nz-wp'),               // Menu title
            'manage_options',                              // Capability
            'mega-upload-settings',                        // Menu slug
            array($this, 'render_admin_page'),             // Callback function
            MEGA_UPLOAD_PLUGIN_URL . 'assets/images/mega-nz.svg', // Icon URL
            30                                             // Position (30 = below Dashboard)
        );
    }

    /**
     * Add styles for the menu icon
     */
    public function add_menu_icon_styles() {
        ?>
        <style>
            #adminmenu .toplevel_page_mega-upload-settings .wp-menu-image img {
                width: 20px;
                height: 20px;
                padding: 6px 0;
                opacity: 0.6;
            }
            #adminmenu .toplevel_page_mega-upload-settings:hover .wp-menu-image img,
            #adminmenu .toplevel_page_mega-upload-settings.current .wp-menu-image img {
                opacity: 1;
            }
        </style>
        <?php
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting('mega_upload_settings', 'mega_upload_email', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_email',
            'default' => ''
        ));

        register_setting('mega_upload_settings', 'mega_upload_password', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'encrypt_password'),
            'default' => ''
        ));
    }

    /**
     * Generate encryption key from WordPress salts
     */
    public function get_encryption_key() {
        // Use WordPress authentication keys to create encryption key
        $key_parts = array(
            defined('AUTH_KEY') ? AUTH_KEY : '',
            defined('SECURE_AUTH_KEY') ? SECURE_AUTH_KEY : '',
            defined('LOGGED_IN_KEY') ? LOGGED_IN_KEY : ''
        );

        // Create a consistent 32-byte key for AES-256
        return substr(hash('sha256', implode('', $key_parts)), 0, 32);
    }

    /**
     * Encrypt password before storing using AES-256-CBC
     */
    public function encrypt_password($password) {
        if (empty($password)) {
            return '';
        }

        // Don't re-encrypt if already encrypted (starts with encrypted_v2: marker)
        if (strpos($password, 'encrypted_v2:') === 0) {
            return $password;
        }

        // Auto-upgrade old base64 passwords (backward compatibility)
        if (strpos($password, 'encrypted:') === 0) {
            $password = base64_decode(str_replace('encrypted:', '', $password));
        }

        // Use OpenSSL for strong encryption
        $encryption_key = $this->get_encryption_key();
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));

        $encrypted = openssl_encrypt(
            $password,
            'aes-256-cbc',
            $encryption_key,
            0,
            $iv
        );

        // Store IV with encrypted data (IV doesn't need to be secret)
        return 'encrypted_v2:' . base64_encode($iv . '::' . $encrypted);
    }

    /**
     * Decrypt password when retrieving using AES-256-CBC
     */
    public static function decrypt_password($encrypted_password) {
        if (empty($encrypted_password)) {
            return '';
        }

        // Handle new encrypted format (AES-256-CBC)
        if (strpos($encrypted_password, 'encrypted_v2:') === 0) {
            $data = base64_decode(str_replace('encrypted_v2:', '', $encrypted_password));
            $parts = explode('::', $data, 2);

            if (count($parts) !== 2) {
                return '';
            }

            list($iv, $encrypted) = $parts;

            // Get encryption key
            $instance = self::get_instance();
            $encryption_key = $instance->get_encryption_key();

            $decrypted = openssl_decrypt(
                $encrypted,
                'aes-256-cbc',
                $encryption_key,
                0,
                $iv
            );

            return $decrypted !== false ? $decrypted : '';
        }

        // Handle legacy base64 format (backward compatibility)
        if (strpos($encrypted_password, 'encrypted:') === 0) {
            $encrypted = str_replace('encrypted:', '', $encrypted_password);
            return base64_decode($encrypted);
        }

        // Return as-is if not encrypted
        return $encrypted_password;
    }

    /**
     * Render admin settings page
     */
    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'mega-nz-wp'));
        }

        include MEGA_UPLOAD_PLUGIN_DIR . 'admin/settings-page.php';
    }

    /**
     * Enqueue frontend assets
     */
    public function enqueue_frontend_assets() {
        // Only enqueue if shortcode is present on the page
        global $post;
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'mega_upload')) {
            // Add config to footer right before Alpine
            add_action('wp_footer', function() {
                ?>
<script>
// Configuration
window.megaUploadConfig = <?php echo json_encode(array(
    'ajaxUrl' => admin_url('admin-ajax.php'),
    'nonce' => wp_create_nonce('mega_upload_nonce')
)); ?>;

// Alpine component placeholder that will be replaced when module loads
window.megaUploader = function() {
    return {
        // Minimal state to prevent Alpine errors during loading
        isInitializing: true,
        authError: false,
        isLoading: true,
        isDragging: false,
        activeUploadCount: 0,
        maxConcurrentUploads: 1,
        validationError: false,
        validationMessage: '',
        fileQueue: [],
        uploadSessionStartTime: null,
        uploadSessionTotalBytes: 0,
        uploadSessionBytesUploaded: 0,
        smoothedSpeed: 0,
        lastSpeedUpdate: null,

        // Placeholder methods to prevent errors
        handleDragOver(event) { event.preventDefault(); },
        handleDragLeave(event) { event.preventDefault(); },
        handleDrop(event) { event.preventDefault(); },
        handleFileSelect(event) { event.preventDefault(); },
        uploadFiles() { },
        removeFromQueue() { },
        cancelUpload() { },
        clearCompleted() { },
        clearAll() { },
        getQueuedCount() { return 0; },
        getCompletedCount() { return 0; },
        getOverallProgress() { return 0; },
        formatFileSize(bytes) { return '0 Bytes'; },
        getTimeRemaining() { return ''; },

        init() {
            const element = this.$el;
            // Wait for the real component to be available
            const checkInterval = setInterval(() => {
                if (window.megaUploaderReady) {
                    clearInterval(checkInterval);
                    // Replace this placeholder with the real component
                    Object.assign(this, window.megaUploaderComponent);
                    // Re-initialize with the real component
                    if (this.realInit) {
                        this.realInit(element.dataset);
                    }
                }
            }, 100);

            // Timeout after 10 seconds
            setTimeout(() => {
                clearInterval(checkInterval);
                if (!window.megaUploaderReady) {
                    this.isInitializing = false;
                    this.authError = true;
                    console.error('Failed to load upload module');
                }
            }, 10000);
        }
    };
};
</script>
<script type="module">
// Load and register the real component
import { Storage } from 'https://unpkg.com/megajs@1/dist/main.browser-es.mjs';

console.log('Loading Mega Upload module...');

// Define the real component
window.megaUploaderComponent = <?php
    // Read and output the component methods
    $js_file = file_get_contents(MEGA_UPLOAD_PLUGIN_DIR . 'assets/js/mega-upload-component.js');
    echo $js_file;
?>;

// Make Storage available to the component
window.MegaStorage = Storage;

// Signal that component is ready
window.megaUploaderReady = true;
console.log('Mega Upload module ready');
</script>
                <?php
            }, 19);

            // Load Alpine.js in footer
            wp_enqueue_script(
                'alpinejs',
                'https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js',
                array(),
                '3.0.0',
                true // Load in footer
            );

            // No need for defer - it's already in footer
            wp_script_add_data('alpinejs', 'async', true);

            // Custom styles
            wp_enqueue_style(
                'mega-upload-frontend',
                MEGA_UPLOAD_PLUGIN_URL . 'assets/css/mega-upload.css',
                array(),
                MEGA_UPLOAD_VERSION
            );
        }
    }

    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Only load on our settings page
        if ('toplevel_page_mega-upload-settings' !== $hook) {
            return;
        }

        // Enqueue shortcode builder JavaScript FIRST
        wp_enqueue_script(
            'mega-upload-shortcode-builder',
            MEGA_UPLOAD_PLUGIN_URL . 'admin/js/shortcode-builder.js',
            array(),
            MEGA_UPLOAD_VERSION,
            true
        );

        // Enqueue Alpine.js AFTER our component (so it's defined first)
        wp_enqueue_script(
            'alpinejs-admin',
            'https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js',
            array('mega-upload-shortcode-builder'),
            '3.0.0',
            true
        );

        // Defer Alpine.js so it initializes after DOM is ready
        wp_script_add_data('alpinejs-admin', 'defer', true);

        // Enqueue admin settings CSS
        wp_enqueue_style(
            'mega-upload-admin-settings',
            MEGA_UPLOAD_PLUGIN_URL . 'admin/css/admin-settings.css',
            array(),
            MEGA_UPLOAD_VERSION
        );

        // Enqueue shortcode builder CSS
        wp_enqueue_style(
            'mega-upload-shortcode-builder',
            MEGA_UPLOAD_PLUGIN_URL . 'admin/css/shortcode-builder.css',
            array(),
            MEGA_UPLOAD_VERSION
        );

        // Enqueue WordPress dashicons for copy button icon
        wp_enqueue_style('dashicons');
    }

    /**
     * Get Mega.nz credentials
     */
    public static function get_credentials() {
        $email = get_option('mega_upload_email', '');
        $encrypted_password = get_option('mega_upload_password', '');
        $password = self::decrypt_password($encrypted_password);

        return array(
            'email' => $email,
            'password' => $password
        );
    }
}

/**
 * Initialize the plugin
 */
function mega_upload_init() {
    return Mega_Upload_Plugin::get_instance();
}

// Start the plugin
add_action('plugins_loaded', 'mega_upload_init');

/**
 * Activation hook
 */
function mega_upload_activate() {
    // Create default options
    add_option('mega_upload_email', '');
    add_option('mega_upload_password', '');
    add_option('mega_upload_credentials_valid', 'unknown');
}
register_activation_hook(__FILE__, 'mega_upload_activate');

/**
 * Deactivation hook
 */
function mega_upload_deactivate() {
    // Clean up if needed
}
register_deactivation_hook(__FILE__, 'mega_upload_deactivate');
