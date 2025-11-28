<?php
/**
 * Admin Settings Page Template
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Handle form submission
$message = '';
$message_type = '';

if (isset($_POST['mega_upload_settings_submit']) && check_admin_referer('mega_upload_settings_action', 'mega_upload_settings_nonce')) {
    // Update settings
    update_option('mega_upload_email', sanitize_email($_POST['mega_upload_email']));

    // Only update password if provided and not the placeholder
    if (!empty($_POST['mega_upload_password']) && $_POST['mega_upload_password'] !== '••••••••••••') {
        $encrypted = Mega_Upload_Plugin::get_instance()->encrypt_password($_POST['mega_upload_password']);
        update_option('mega_upload_password', $encrypted);
    }

    // Mark credentials as unknown (needs validation)
    update_option('mega_upload_credentials_valid', 'unknown');

    $message = __('Settings saved successfully! Please validate your credentials using the "Validate Now" button below.', 'mega-nz-wp');
    $message_type = 'success';
}

// Get current settings
$email = get_option('mega_upload_email', '');
$has_password = !empty(get_option('mega_upload_password', ''));
$credentials_status = get_option('mega_upload_credentials_valid', 'unknown');

// Determine if we should show the shortcode generator
$credentials_are_valid = ($credentials_status === 'valid');
$has_credentials = ($has_password && $email);
$show_shortcode_generator = $credentials_are_valid;
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <?php if (!empty($message)): ?>
        <div class="notice notice-<?php echo esc_attr($message_type); ?> is-dismissible">
            <p><?php echo esc_html($message); ?></p>
        </div>
    <?php endif; ?>

    <?php if ($show_shortcode_generator): ?>
    <script>
    // Configuration for shortcode builder
    window.megaShortcodeBuilderConfig = {
        ajaxUrl: '<?php echo admin_url('admin-ajax.php'); ?>',
        nonce: '<?php echo wp_create_nonce('mega_upload_nonce'); ?>',
        roles: <?php echo json_encode(wp_roles()->get_names()); ?>
    };
    </script>
    <?php endif; ?>

    <div class="mega-upload-settings-container">
        <!-- Account Settings Card (full width) -->
        <div class="card credentials-card">
            <!-- Credential Status Alert (if credentials exist) -->
            <?php if ($has_credentials): ?>
                <div class="credential-status-alert">
                    <?php if ($credentials_status === 'valid'): ?>
                        <div class="status-alert status-success">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <span><?php _e('Credentials validated successfully! You can now use the shortcode generator below.', 'mega-nz-wp'); ?></span>
                        </div>
                    <?php elseif ($credentials_status === 'invalid'): ?>
                        <div class="status-alert status-error">
                            <span class="dashicons dashicons-warning"></span>
                            <span><?php _e('Unable to connect to Mega.nz. Please check your credentials below.', 'mega-nz-wp'); ?></span>
                        </div>
                    <?php elseif ($credentials_status === 'pending'): ?>
                        <div class="status-alert status-warning">
                            <span class="dashicons dashicons-update spin-animation"></span>
                            <span><?php _e('Validating credentials...', 'mega-nz-wp'); ?></span>
                        </div>
                    <?php else: ?>
                        <div class="status-alert status-info">
                            <span class="dashicons dashicons-info"></span>
                            <span><?php _e('Credentials not yet validated.', 'mega-nz-wp'); ?></span>
                            <button type="button" class="button button-small" id="validate-credentials-btn"><?php _e('Validate Now', 'mega-nz-wp'); ?></button>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <h2>
                <?php _e('Mega.nz Account Settings', 'mega-nz-wp'); ?>
                <span class="security-info-tooltip">
                    <span class="dashicons dashicons-editor-help security-info-icon"></span>
                    <span class="security-info-popup">
                        <strong><?php _e('Your credentials are secure:', 'mega-nz-wp'); ?></strong>
                        <span><?php _e('Your Mega.nz password is encrypted using AES-256 encryption (military-grade security) and can only be decrypted with access to both your database and WordPress configuration files. All uploaded files go directly to your Mega.nz account.', 'mega-nz-wp'); ?></span>
                    </span>
                </span>
            </h2>
            <p><?php _e('Enter your Mega.nz account credentials. These will be used to authenticate uploads from your website visitors.', 'mega-nz-wp'); ?></p>

            <form method="post" action="">
                <?php wp_nonce_field('mega_upload_settings_action', 'mega_upload_settings_nonce'); ?>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="mega_upload_email">
                                    <?php _e('Mega.nz Email', 'mega-nz-wp'); ?>
                                    <span class="required" style="color: red;">*</span>
                                </label>
                            </th>
                            <td>
                                <input
                                    type="email"
                                    name="mega_upload_email"
                                    id="mega_upload_email"
                                    value="<?php echo esc_attr($email); ?>"
                                    class="regular-text"
                                    required
                                />
                                <p class="description">
                                    <?php _e('Your Mega.nz account email address', 'mega-nz-wp'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="mega_upload_password">
                                    <?php _e('Mega.nz Password', 'mega-nz-wp'); ?>
                                    <?php if (!$has_password): ?>
                                        <span class="required" style="color: red;">*</span>
                                    <?php endif; ?>
                                </label>
                            </th>
                            <td>
                                <input
                                    type="password"
                                    name="mega_upload_password"
                                    id="mega_upload_password"
                                    value="<?php echo $has_password ? '••••••••••••' : ''; ?>"
                                    class="regular-text"
                                    <?php echo $has_password ? '' : 'required'; ?>
                                    placeholder="<?php echo $has_password ? __('Leave blank to keep current password', 'mega-nz-wp') : __('Enter your password', 'mega-nz-wp'); ?>"
                                />
                                <p class="description">
                                    <?php if ($has_password): ?>
                                        <?php _e('Password is set. Leave blank to keep current password, or enter a new one to update.', 'mega-nz-wp'); ?>
                                    <?php else: ?>
                                        <?php _e('Your Mega.nz account password (stored encrypted)', 'mega-nz-wp'); ?>
                                    <?php endif; ?>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <p class="submit">
                    <input
                        type="submit"
                        name="mega_upload_settings_submit"
                        id="submit"
                        class="button button-primary"
                        value="<?php esc_attr_e('Save Settings', 'mega-nz-wp'); ?>"
                    />
                </p>
            </form>
        </div>

        <?php if ($show_shortcode_generator): ?>
        <div class="card mega-shortcode-builder">
            <h2><?php _e('Shortcode Generator', 'mega-nz-wp'); ?></h2>
            <p><?php _e('Build your shortcode visually by selecting options below. The shortcode will update in real-time.', 'mega-nz-wp'); ?></p>

            <div class="builder-wrapper" x-data="megaShortcodeBuilder()" x-init="init()">

                <!-- Loading Overlay -->
                <div x-show="isInitializing"
                     class="builder-loading-overlay"
                     :class="{ 'fade-out': initializationComplete }">
                    <div class="loading-content">
                        <div x-show="!showCheckmark" class="loading-spinner"></div>
                        <div x-show="showCheckmark" class="loading-checkmark">
                            <svg width="80" height="80" viewBox="0 0 80 80">
                                <circle cx="40" cy="40" r="35" fill="none" stroke="#00a32a" stroke-width="4"/>
                                <path d="M25 40 L35 50 L55 30" fill="none" stroke="#00a32a" stroke-width="4" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <p x-show="!showCheckmark" class="loading-text"><?php _e('Connecting to Mega.nz...', 'mega-nz-wp'); ?></p>
                        <p x-show="showCheckmark" class="loading-text success"><?php _e('Connected!', 'mega-nz-wp'); ?></p>
                    </div>
                </div>

                <!-- Error Message (if connection fails) -->
                <div x-show="connectionError && !isConnecting && !isInitializing" class="builder-error-message">
                    <span class="error-icon">❌</span>
                    <span class="error-text" x-text="connectionError"></span>
                    <button type="button" class="button button-primary retry-button" @click="retryConnection()">
                        <?php _e('Retry Connection', 'mega-nz-wp'); ?>
                    </button>
                </div>

                <!-- Builder Layout -->
                <div class="builder-layout" x-show="!isInitializing && isConnected">

                    <!-- Left Column: Folder Browser -->
                    <div class="builder-section file-explorer">
                        <div class="explorer-header">
                            <div class="explorer-title">
                                <span class="folder-icon-large">📁</span>
                                <h4><?php _e('Mega.nz Files', 'mega-nz-wp'); ?></h4>
                                <span class="folder-count" x-text="folders.length + ' folders'"></span>
                            </div>
                        </div>

                        <!-- Breadcrumb Navigation -->
                        <div class="breadcrumb-nav">
                            <button type="button"
                                    class="breadcrumb-back"
                                    x-show="breadcrumbs.length > 1"
                                    @click="goBack()">
                                ← <?php _e('Back', 'mega-nz-wp'); ?>
                            </button>
                            <div class="breadcrumb-path">
                                <template x-for="(crumb, index) in breadcrumbs" :key="index">
                                    <span>
                                        <a href="#"
                                           @click.prevent="navigateToBreadcrumb(index)"
                                           :class="{ 'active': index === breadcrumbs.length - 1 }"
                                           x-text="crumb.name"></a>
                                        <span x-show="index < breadcrumbs.length - 1" class="separator">/</span>
                                    </span>
                                </template>
                            </div>
                        </div>

                        <!-- File Explorer Table -->
                        <div class="file-explorer-table">
                            <!-- Table Header -->
                            <div class="table-header">
                                <div class="col-name"><?php _e('Name', 'mega-nz-wp'); ?></div>
                                <div class="col-size"><?php _e('Size', 'mega-nz-wp'); ?></div>
                                <div class="col-modified"><?php _e('Modified', 'mega-nz-wp'); ?></div>
                            </div>

                            <!-- Loading State -->
                            <div x-show="isLoadingFolders" class="table-loading">
                                <div class="builder-loading"></div>
                                <p><?php _e('Loading...', 'mega-nz-wp'); ?></p>
                            </div>

                            <!-- Table Body -->
                            <div x-show="!isLoadingFolders" class="table-body">
                                <!-- Folders -->
                                <template x-for="folder in folders" :key="folder.nodeId">
                                    <div class="table-row folder-row" @click="navigateIntoFolder(folder)">
                                        <div class="col-name">
                                            <span class="file-icon">📁</span>
                                            <span class="file-name" x-text="folder.name"></span>
                                        </div>
                                        <div class="col-size">
                                            <span x-text="formatFileSize(folder.size)"></span>
                                        </div>
                                        <div class="col-modified">
                                            <span x-text="formatDate(folder.modified)"></span>
                                        </div>
                                    </div>
                                </template>

                                <!-- Files (for display only) -->
                                <template x-for="file in files" :key="file.nodeId">
                                    <div class="table-row file-row disabled">
                                        <div class="col-name">
                                            <span class="file-icon">📄</span>
                                            <span class="file-name" x-text="file.name"></span>
                                        </div>
                                        <div class="col-size">
                                            <span x-text="formatFileSize(file.size)"></span>
                                        </div>
                                        <div class="col-modified">
                                            <span x-text="formatDate(file.modified)"></span>
                                        </div>
                                    </div>
                                </template>

                                <!-- Empty State -->
                                <div x-show="folders.length === 0 && files.length === 0" class="table-empty">
                                    <div class="empty-icon">📂</div>
                                    <p><?php _e('This folder is empty', 'mega-nz-wp'); ?></p>
                                </div>
                            </div>
                        </div>

                        <!-- Use This Folder Button - Bottom Center -->
                        <div class="explorer-footer">
                            <button type="button"
                                    class="button button-primary select-folder-btn-bottom"
                                    @click="selectCurrentFolder()">
                                <span class="dashicons dashicons-yes"></span>
                                <?php _e('Use This Folder', 'mega-nz-wp'); ?>
                            </button>
                        </div>
                    </div>

                    <!-- Right Column: Configuration -->
                    <div class="builder-section">
                        <h4><?php _e('2. Configure Settings', 'mega-nz-wp'); ?></h4>

                        <!-- Max Size -->
                        <div class="builder-form-group">
                            <label><?php _e('Maximum File Size', 'mega-nz-wp'); ?></label>
                            <select x-model="maxSize" @change="onMaxSizeChange()">
                                <option value="10MB">10 MB</option>
                                <option value="25MB">25 MB</option>
                                <option value="50MB">50 MB</option>
                                <option value="100MB">100 MB (Default)</option>
                                <option value="250MB">250 MB</option>
                                <option value="500MB">500 MB</option>
                                <option value="1GB">1 GB</option>
                                <option value="custom">Custom...</option>
                            </select>

                            <div x-show="maxSize === 'custom'" class="max-size-custom">
                                <input type="text"
                                       x-model="customMaxSize"
                                       @input="onCustomMaxSizeChange()"
                                       placeholder="e.g., 150MB, 2GB">
                                <span class="description"><?php _e('Format: 10MB, 100MB, 1GB, etc.', 'mega-nz-wp'); ?></span>
                            </div>
                        </div>

                        <!-- Allowed Types -->
                        <div class="builder-form-group">
                            <label><?php _e('Allowed File Types', 'mega-nz-wp'); ?></label>
                            <input type="text"
                                   x-model="allowedTypes"
                                   @input="onAllowedTypesChange()"
                                   placeholder="jpg,png,pdf">
                            <span class="description">
                                <?php _e('Comma-separated file extensions. Leave blank to allow all types.', 'mega-nz-wp'); ?>
                            </span>
                        </div>

                        <!-- Roles -->
                        <div class="builder-form-group">
                            <label><?php _e('Who Can Upload?', 'mega-nz-wp'); ?></label>
                            <div class="roles-grid">
                                <div class="role-checkbox">
                                    <input type="checkbox"
                                           id="role-all"
                                           :checked="isRoleSelected('all')"
                                           @change="toggleRole('all')">
                                    <label for="role-all"><?php _e('Everyone (All Users)', 'mega-nz-wp'); ?></label>
                                </div>
                                <?php foreach (wp_roles()->get_names() as $role_key => $role_name): ?>
                                <div class="role-checkbox">
                                    <input type="checkbox"
                                           id="role-<?php echo esc_attr($role_key); ?>"
                                           :checked="isRoleSelected('<?php echo esc_js($role_key); ?>')"
                                           @change="toggleRole('<?php echo esc_js($role_key); ?>')">
                                    <label for="role-<?php echo esc_attr($role_key); ?>">
                                        <?php echo esc_html($role_name); ?>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Shortcode Output -->
                <div class="builder-section shortcode-output-wrapper" x-show="!isInitializing && isConnected">
                    <h4><?php _e('3. Copy Your Shortcode', 'mega-nz-wp'); ?></h4>

                    <div class="shortcode-output-section">
                        <div class="shortcode-preview-clickable"
                             @click="copyShortcode()"
                             tabindex="0"
                             role="button"
                             :class="{ 'copied': copySuccess }">
                            <code><span x-html="generatedShortcode || '[Loading...]'"></span></code>
                            <template x-if="!copySuccess">
                                <div class="shortcode-hover-hint">
                                    <span class="dashicons dashicons-clipboard"></span>
                                    <?php _e('Click to copy', 'mega-nz-wp'); ?>
                                </div>
                            </template>
                            <template x-if="copySuccess">
                                <div class="shortcode-copied-hint">
                                    <span class="dashicons dashicons-yes"></span>
                                    <?php _e('Copied!', 'mega-nz-wp'); ?>
                                </div>
                            </template>
                        </div>

                        <p class="description shortcode-output-description">
                            <?php _e('Paste this shortcode into any post or page to display the upload form with your selected settings.', 'mega-nz-wp'); ?>
                        </p>
                    </div>
                </div>

            </div><!-- End x-data="megaShortcodeBuilder()" -->
        </div>
        <?php else: ?>
        <!-- Message when credentials are not valid -->
        <?php if ($has_credentials && $credentials_status === 'invalid'): ?>
        <div class="card">
            <h2><?php _e('Shortcode Generator', 'mega-nz-wp'); ?></h2>
            <div class="notice notice-error inline">
                <p>
                    <strong><?php _e('Shortcode generator is unavailable', 'mega-nz-wp'); ?></strong><br>
                    <?php _e('Your Mega.nz credentials could not be validated. Please update your email and password above, then save the settings to try again.', 'mega-nz-wp'); ?>
                </p>
            </div>
        </div>
        <?php elseif ($has_credentials && $credentials_status === 'pending'): ?>
        <div class="card">
            <h2><?php _e('Shortcode Generator', 'mega-nz-wp'); ?></h2>
            <div class="notice notice-warning inline">
                <p>
                    <strong><?php _e('Validating credentials...', 'mega-nz-wp'); ?></strong><br>
                    <?php _e('Please wait while we validate your Mega.nz credentials. The shortcode generator will appear once validation is complete.', 'mega-nz-wp'); ?>
                </p>
            </div>
        </div>
        <?php elseif ($has_credentials): ?>
        <div class="card">
            <h2><?php _e('Shortcode Generator', 'mega-nz-wp'); ?></h2>
            <div class="notice notice-info inline">
                <p>
                    <strong><?php _e('Credentials need validation', 'mega-nz-wp'); ?></strong><br>
                    <?php _e('Your credentials have not been validated yet. Click "Validate Now" in the Credential Status section above to proceed.', 'mega-nz-wp'); ?>
                </p>
            </div>
        </div>
        <?php else: ?>
        <div class="card">
            <h2><?php _e('Shortcode Generator', 'mega-nz-wp'); ?></h2>
            <div class="notice notice-info inline">
                <p>
                    <strong><?php _e('Setup required', 'mega-nz-wp'); ?></strong><br>
                    <?php _e('Please enter your Mega.nz account credentials above to use the shortcode generator.', 'mega-nz-wp'); ?>
                </p>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

    </div><!-- End mega-upload-settings-container -->
</div><!-- End wrap -->

<style>
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
.spin-animation {
    animation: spin 1s linear infinite;
    display: inline-block;
}
</style>

<script>
// Security tooltip click handler for mobile
document.addEventListener('DOMContentLoaded', function() {
    const tooltip = document.querySelector('.security-info-tooltip');
    if (tooltip) {
        tooltip.addEventListener('click', function(e) {
            e.stopPropagation();
            this.classList.toggle('active');
        });

        // Close tooltip when clicking outside
        document.addEventListener('click', function() {
            tooltip.classList.remove('active');
        });
    }
});
</script>

<script type="module">
// Handler for "Validate Now" button
document.addEventListener('DOMContentLoaded', function() {
    const validateBtn = document.getElementById('validate-credentials-btn');
    if (validateBtn) {
        validateBtn.addEventListener('click', async function() {
            const statusAlertDiv = document.querySelector('.credential-status-alert');

            // Show validating state
            statusAlertDiv.innerHTML = '<div class="status-alert status-warning"><span class="dashicons dashicons-update spin-animation"></span><span><?php _e('Validating credentials...', 'mega-nz-wp'); ?></span></div>';

            try {
                // Dynamically import Mega.js
                const { Storage } = await import('https://unpkg.com/megajs@1/dist/main.browser-es.mjs');

                // Get credentials
                const response = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'mega_upload_get_credentials',
                        nonce: '<?php echo wp_create_nonce('mega_upload_nonce'); ?>'
                    })
                });

                const data = await response.json();

                if (!data.success) {
                    throw new Error('Failed to get credentials');
                }

                // Try to connect with timeout
                const connectPromise = new Storage({
                    email: data.data.email,
                    password: data.data.password,
                    userAgent: null
                }).ready;

                const timeoutPromise = new Promise((_, reject) =>
                    setTimeout(() => reject(new Error('Connection timeout')), 30000)
                );

                await Promise.race([connectPromise, timeoutPromise]);

                // Success - update status
                await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'mega_upload_set_validation_status',
                        nonce: '<?php echo wp_create_nonce('mega_upload_admin_nonce'); ?>',
                        status: 'valid'
                    })
                });

                // Update UI and reload page to show shortcode generator
                statusAlertDiv.innerHTML = '<div class="status-alert status-success"><span class="dashicons dashicons-yes-alt"></span><span><?php _e('Credentials validated successfully! Reloading...', 'mega-nz-wp'); ?></span></div>';

                // Reload page after 1 second to show shortcode generator
                setTimeout(() => {
                    window.location.reload();
                }, 1000);

            } catch (error) {
                console.error('Validation error:', error);

                // Failed - update status
                await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'mega_upload_set_validation_status',
                        nonce: '<?php echo wp_create_nonce('mega_upload_admin_nonce'); ?>',
                        status: 'invalid'
                    })
                });

                // Update UI and reload page to show error message
                statusAlertDiv.innerHTML = '<div class="status-alert status-error"><span class="dashicons dashicons-warning"></span><span><?php _e('Unable to connect to Mega.nz with these credentials. Reloading...', 'mega-nz-wp'); ?></span></div>';

                // Reload page after 1 second to show error state
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            }
        });
    }
});
</script>
