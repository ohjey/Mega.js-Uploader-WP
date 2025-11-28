<?php
/**
 * Shortcode Handler Class
 * Registers and handles the [mega_upload] shortcode
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class Mega_Upload_Shortcode {

    /**
     * Initialize shortcode
     */
    public static function init() {
        add_shortcode('mega_upload', array(__CLASS__, 'render_shortcode'));
    }

    /**
     * Render the shortcode
     */
    public static function render_shortcode($atts) {
        // Disable wpautop for this shortcode to prevent quote conversion
        remove_filter('the_content', 'wpautop');
        remove_filter('the_excerpt', 'wpautop');

        // Parse attributes with defaults
        $atts = shortcode_atts(array(
            'folder' => '',              // Target folder in Mega.nz (empty = root)
            'max_size' => '100MB',       // Maximum file size
            'allowed_types' => '',       // Comma-separated file extensions (empty = all)
            'roles' => 'all',            // Required WordPress roles (all = anyone)
        ), $atts, 'mega_upload');

        // Check if credentials are configured
        $credentials = Mega_Upload_Plugin::get_credentials();
        if (empty($credentials['email']) || empty($credentials['password'])) {
            return self::render_error(__('Mega.nz upload is not configured. Please contact the site administrator.', 'mega-nz-wp'));
        }

        // Check if credentials are validated
        $credentials_status = get_option('mega_upload_credentials_valid', 'unknown');
        if ($credentials_status !== 'valid') {
            return self::render_error_card(__('Upload Unavailable', 'mega-nz-wp'), __('The file uploader is not configured correctly. Please contact the website administrator.', 'mega-nz-wp'));
        }

        // Check if user has permission
        if (!self::user_has_permission($atts['roles'])) {
            if (is_user_logged_in()) {
                return self::render_error(__('You do not have permission to upload files.', 'mega-nz-wp'));
            } else {
                return self::render_error(__('Please log in to upload files.', 'mega-nz-wp'));
            }
        }

        // Generate unique ID for this instance
        $instance_id = 'mega-upload-' . uniqid();

        // Render the upload form
        ob_start();
        ?>
        <div
            id="<?php echo esc_attr($instance_id); ?>"
            class="mega-upload-container"
            x-data="megaUploader()"
            x-cloak
            data-folder="<?php echo esc_attr($atts['folder']); ?>"
            data-maxsize="<?php echo esc_attr($atts['max_size']); ?>"
            data-allowedtypes="<?php echo esc_attr($atts['allowed_types']); ?>"
            data-allowedroles="<?php echo esc_attr($atts['roles']); ?>"
        >
            <!-- Loading State -->
            <div x-show="isInitializing" class="mega-upload-initializing">
                <div class="mega-upload-spinner">
                    <div class="mega-upload-spinner-circle"></div>
                    <p><?php _e('One moment...', 'mega-nz-wp'); ?></p>
                </div>
            </div>

            <!-- Auth Error -->
            <div x-show="authError" class="mega-upload-auth-error">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width: 48px; height: 48px; margin: 0 auto 16px; color: #ef4444;">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <h3 style="margin: 0 0 8px 0; font-size: 18px; font-weight: 600; color: #1a1a1a;">
                    <?php _e('Upload Unavailable', 'mega-nz-wp'); ?>
                </h3>
                <p style="margin: 0; font-size: 14px; color: #6b7280; line-height: 1.6;">
                    <?php _e('The file uploader is not configured correctly. Please contact the website administrator.', 'mega-nz-wp'); ?>
                </p>
            </div>

            <!-- Main Content -->
            <div x-show="!isInitializing && !authError">
                <!-- Header -->
                <div class="mega-upload-header">
                    <h3><?php _e('Upload files', 'mega-nz-wp'); ?></h3>
                </div>

                <!-- Drop Zone -->
                <div
                    class="mega-upload-dropzone"
                    :class="{ 'is-dragging': isDragging, 'is-loading': isLoading, 'is-error': validationError }"
                    @dragover.prevent="handleDragOver($event)"
                    @dragleave.prevent="handleDragLeave($event)"
                    @drop.prevent="handleDrop($event)"
                >
                    <div class="mega-upload-dropzone-content">
                        <svg class="mega-upload-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11v6m-3-3l3-3 3 3" />
                        </svg>

                        <p class="mega-upload-dropzone-text" x-show="!isLoading">
                            <?php _e('Choose a file or drag & drop here.', 'mega-nz-wp'); ?>
                        </p>
                        <p class="mega-upload-dropzone-text-alt" x-show="!isLoading">
                            <?php
                            $formats = !empty($atts['allowed_types']) ? strtoupper(str_replace(',', ', ', $atts['allowed_types'])) : 'All';
                            printf(__('%s formats, up to %s.', 'mega-nz-wp'), $formats, esc_html($atts['max_size']));
                            ?>
                        </p>

                        <label for="<?php echo esc_attr($instance_id); ?>-file-input" class="mega-upload-button" x-show="!isLoading">
                            <?php _e('Browse file', 'mega-nz-wp'); ?>
                        </label>

                        <input
                            type="file"
                            id="<?php echo esc_attr($instance_id); ?>-file-input"
                            class="mega-upload-file-input"
                            multiple
                            @change="handleFileSelect($event)"
                            style="display: none;"
                        />

                        <div x-show="isLoading" class="mega-upload-spinner">
                            <div class="mega-upload-spinner-circle"></div>
                            <p><?php _e('Initializing...', 'mega-nz-wp'); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Validation Error Message -->
                <div x-show="validationError" x-transition class="mega-upload-validation-error">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width: 16px; height: 16px; flex-shrink: 0;">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <span x-text="validationMessage"></span>
                </div>

                <!-- File Queue -->
                <div x-show="fileQueue.length > 0" x-transition class="mega-upload-queue">
                    <h4><?php _e('Files to Upload', 'mega-nz-wp'); ?></h4>

                    <template x-for="(file, index) in fileQueue" :key="index">
                        <div class="mega-upload-file-item" :class="'status-' + file.status">
                            <!-- File Icon -->
                            <div class="mega-upload-file-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width: 24px; height: 24px;">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                </svg>
                            </div>

                            <!-- File Info -->
                            <div class="mega-upload-file-info">
                                <span class="mega-upload-file-name" x-text="file.name"></span>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span class="mega-upload-file-size" x-text="formatFileSize(file.size)"></span>
                                    <span x-show="file.status === 'uploading'" class="mega-upload-progress-text" x-text="'· ' + file.progress + '%'"></span>
                                    <span x-show="file.status === 'uploading'" class="mega-upload-progress-text" x-text="'· ' + formatSpeed(file.uploadSpeed)"></span>
                                    <span x-show="file.status === 'completed'" style="color: #10b981; font-size: 13px; font-weight: 600;">
                                        <?php _e('· Completed', 'mega-nz-wp'); ?>
                                    </span>
                                </div>

                                <!-- Progress Bar -->
                                <div class="mega-upload-file-progress" x-show="file.status === 'uploading'">
                                    <div class="mega-upload-progress-bar">
                                        <div class="mega-upload-progress-fill" :style="'width: ' + file.progress + '%'"></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div class="mega-upload-file-actions">
                                <button
                                    x-show="file.status === 'queued' || file.status === 'error' || file.status === 'completed'"
                                    @click="removeFromQueue(file.id)"
                                    class="mega-upload-button-icon"
                                    type="button"
                                    title="<?php esc_attr_e('Remove', 'mega-nz-wp'); ?>"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width: 20px; height: 20px;">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                                <button
                                    x-show="file.status === 'uploading'"
                                    @click="cancelUpload(file.id)"
                                    class="mega-upload-button-icon"
                                    type="button"
                                    title="<?php esc_attr_e('Cancel upload', 'mega-nz-wp'); ?>"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width: 20px; height: 20px;">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                    </svg>
                                </button>
                            </div>

                            <p x-show="file.error" class="mega-upload-error-message" x-text="file.error"></p>
                        </div>
                    </template>

                    <!-- Action Buttons (Sticky) -->
                    <div class="mega-upload-actions mega-upload-actions-sticky">
                        <button
                            @click="uploadFiles()"
                            :disabled="uploadSessionStartTime !== null || getQueuedCount() === 0"
                            class="mega-upload-button mega-upload-button-primary"
                            style="width: 100%;"
                            type="button"
                        >
                            <span x-show="uploadSessionStartTime === null">
                                <?php _e('Upload', 'mega-nz-wp'); ?>
                            </span>
                            <span x-show="uploadSessionStartTime !== null">
                                <span x-text="'Uploading ' + getOverallProgress() + '%'"></span>
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
        $output = ob_get_clean();

        // Re-enable wpautop after our shortcode
        add_filter('the_content', 'wpautop');
        add_filter('the_excerpt', 'wpautop');

        // Return output - WordPress may still process it, so we'll handle that in the main plugin
        return $output;
    }

    /**
     * Render error message
     */
    private static function render_error($message) {
        return sprintf(
            '<div class="mega-upload-error">%s</div>',
            esc_html($message)
        );
    }

    /**
     * Render error card with icon
     */
    private static function render_error_card($title, $message) {
        ob_start();
        ?>
        <div class="mega-upload-container">
            <div class="mega-upload-auth-error">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width: 48px; height: 48px; margin: 0 auto 16px; color: #ef4444;">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <h3 style="margin: 0 0 8px 0; font-size: 18px; font-weight: 600; color: #1a1a1a;">
                    <?php echo esc_html($title); ?>
                </h3>
                <p style="margin: 0; font-size: 14px; color: #6b7280; line-height: 1.6;">
                    <?php echo esc_html($message); ?>
                </p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Check if user has required permission
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
}
