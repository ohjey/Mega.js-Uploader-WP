/**
 * Mega Upload Shortcode Builder
 * Interactive shortcode generator with Mega.nz folder browser
 */

window.megaShortcodeBuilder = function() {
    return {
        // Loading state
        isInitializing: true,
        initializationComplete: false,
        showCheckmark: false,

        // Connection state
        storage: null,
        isConnecting: false,
        isConnected: false,
        connectionError: '',

        // Folder browser state
        currentFolder: null, // Current folder we're viewing
        currentFolderNode: null, // The actual Mega node
        folders: [], // Folders in current directory
        files: [], // Files in current directory (for display)
        breadcrumbs: [], // Navigation path
        selectedFolder: null,
        selectedFolderPath: '',
        isLoadingFolders: false,

        // Form inputs
        maxSize: '100MB',
        customMaxSize: '',
        allowedTypes: '',
        selectedRoles: [],
        availableRoles: window.megaShortcodeBuilderConfig?.roles || {},

        // Generated shortcode
        generatedShortcode: '[mega_upload]',
        copySuccess: false,

        /**
         * Initialize the component
         */
        init() {
            console.log('Initializing Mega Shortcode Builder...');
            console.log('Initial generatedShortcode:', this.generatedShortcode);

            // Set default to all roles
            this.selectedRoles = ['all'];

            // Generate initial shortcode
            this.updateShortcode();
            console.log('After updateShortcode:', this.generatedShortcode);

            // Try to connect to Mega.nz automatically (non-blocking)
            this.connectToMega();
        },

        /**
         * Connect to Mega.nz account with timeout
         */
        async connectToMega() {
            this.isConnecting = true;
            this.connectionError = '';

            try {
                // Get credentials from WordPress
                const response = await fetch(window.megaShortcodeBuilderConfig.ajaxUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'mega_upload_get_credentials',
                        nonce: window.megaShortcodeBuilderConfig.nonce,
                        allowed_roles: 'administrator'
                    })
                });

                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.data?.message || 'Failed to get credentials');
                }

                const { email, password } = data.data;

                if (!email || !password) {
                    throw new Error('Please configure your Mega.nz credentials first');
                }

                // Import Mega.js Storage
                const { Storage } = await import('https://unpkg.com/megajs@1/dist/main.browser-es.mjs');

                // Create storage instance and login with timeout
                this.storage = new Storage({
                    email: email,
                    password: password
                });

                // Wait for connection with 15 second timeout
                await Promise.race([
                    this.storage.ready,
                    new Promise((_, reject) =>
                        setTimeout(() => reject(new Error('Connection timeout - please check your credentials')), 15000)
                    )
                ]);

                this.isConnected = true;
                console.log('Connected to Mega.nz successfully');

                // Load root folders
                await this.loadRootFolders();

                // Show success animation
                this.showCheckmark = true;

                // Show checkmark for 1 second, then fade out
                setTimeout(() => {
                    this.initializationComplete = true;
                    setTimeout(() => {
                        this.isInitializing = false;
                    }, 300); // Fade out duration
                }, 1000);

            } catch (error) {
                console.error('Mega.nz connection error:', error);

                // Show user-friendly error message
                let errorMessage = 'Unable to connect to Mega.nz. Please check your email and password, then try again.';

                // Only show different message if credentials are missing
                if (error.message && error.message.includes('configure your Mega.nz credentials')) {
                    errorMessage = 'Please configure your Mega.nz credentials first.';
                }

                this.connectionError = errorMessage;
                this.isConnected = false;

                // Hide loader immediately on error
                this.isInitializing = false;
            } finally {
                this.isConnecting = false;
            }
        },

        /**
         * Load root level folders
         */
        async loadRootFolders() {
            this.isLoadingFolders = true;

            try {
                this.currentFolder = null;
                this.currentFolderNode = this.storage.root;
                this.breadcrumbs = [{ name: 'Root', path: '', node: this.storage.root }];

                await this.loadCurrentFolderContents();

                console.log('Loaded root folders:', this.folders.length);

            } catch (error) {
                console.error('Error loading folders:', error);
            } finally {
                this.isLoadingFolders = false;
            }
        },

        /**
         * Load contents of current folder
         */
        async loadCurrentFolderContents() {
            const folders = [];
            const files = [];

            if (this.currentFolderNode && this.currentFolderNode.children) {
                for (const child of this.currentFolderNode.children) {
                    if (child.directory) {
                        folders.push({
                            nodeId: child.nodeId,
                            name: child.name,
                            node: child,
                            size: this.calculateFolderSize(child),
                            modified: child.timestamp || new Date()
                        });
                    } else {
                        files.push({
                            nodeId: child.nodeId,
                            name: child.name,
                            size: child.size || 0,
                            modified: child.timestamp || new Date(),
                            type: this.getFileType(child.name)
                        });
                    }
                }
            }

            this.folders = folders;
            this.files = files;
        },

        /**
         * Calculate folder size (approximate)
         */
        calculateFolderSize(folderNode) {
            if (!folderNode.children) return 0;

            let size = 0;
            for (const child of folderNode.children) {
                if (child.directory) {
                    size += this.calculateFolderSize(child);
                } else {
                    size += child.size || 0;
                }
            }
            return size;
        },

        /**
         * Get file type icon based on extension
         */
        getFileType(filename) {
            const ext = filename.split('.').pop().toLowerCase();
            const types = {
                'pdf': 'pdf',
                'doc': 'document',
                'docx': 'document',
                'txt': 'text',
                'jpg': 'image',
                'jpeg': 'image',
                'png': 'image',
                'gif': 'image',
                'zip': 'archive',
                'rar': 'archive',
                'mp4': 'video',
                'avi': 'video',
                'mp3': 'audio',
                'wav': 'audio'
            };
            return types[ext] || 'file';
        },

        /**
         * Navigate into a folder
         */
        async navigateIntoFolder(folder) {
            this.isLoadingFolders = true;

            try {
                this.currentFolderNode = folder.node;

                // Build path for breadcrumbs
                const pathParts = [];
                let node = folder.node;

                // Traverse up to build path (we'll reverse it)
                while (node && node !== this.storage.root) {
                    pathParts.unshift(node.name);
                    node = node.parent;
                }

                // Update breadcrumbs
                this.breadcrumbs = [{ name: 'Root', path: '', node: this.storage.root }];

                let currentPath = '';
                let currentNode = this.storage.root;

                for (const part of pathParts) {
                    currentPath = currentPath ? currentPath + '/' + part : part;
                    // Find the node
                    const childNode = currentNode.children.find(c => c.name === part);
                    if (childNode) {
                        currentNode = childNode;
                        this.breadcrumbs.push({ name: part, path: currentPath, node: currentNode });
                    }
                }

                await this.loadCurrentFolderContents();

            } catch (error) {
                console.error('Error navigating to folder:', error);
            } finally {
                this.isLoadingFolders = false;
            }
        },

        /**
         * Navigate to a breadcrumb (go back to a parent folder)
         */
        async navigateToBreadcrumb(index) {
            this.isLoadingFolders = true;

            try {
                const breadcrumb = this.breadcrumbs[index];
                this.currentFolderNode = breadcrumb.node;

                // Trim breadcrumbs to this level
                this.breadcrumbs = this.breadcrumbs.slice(0, index + 1);

                await this.loadCurrentFolderContents();

            } catch (error) {
                console.error('Error navigating to breadcrumb:', error);
            } finally {
                this.isLoadingFolders = false;
            }
        },

        /**
         * Go back to parent folder
         */
        async goBack() {
            if (this.breadcrumbs.length > 1) {
                await this.navigateToBreadcrumb(this.breadcrumbs.length - 2);
            }
        },

        /**
         * Get current folder path for shortcode
         */
        getCurrentFolderPath() {
            if (this.breadcrumbs.length === 1) {
                return ''; // Root
            }
            return this.breadcrumbs.slice(1).map(b => b.name).join('/');
        },

        /**
         * Select current folder for shortcode
         */
        selectCurrentFolder() {
            this.selectedFolderPath = this.getCurrentFolderPath();
            this.updateShortcode();
        },

        /**
         * Format file size
         */
        formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';

            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));

            return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i];
        },

        /**
         * Format date
         */
        formatDate(date) {
            if (!date) return '';

            const d = new Date(date);
            const year = d.getFullYear();
            const month = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');

            return `${year}/${month}/${day}`;
        },

        /**
         * Handle max size change
         */
        onMaxSizeChange() {
            this.updateShortcode();
        },

        /**
         * Handle custom max size input
         */
        onCustomMaxSizeChange() {
            // Don't change this.maxSize here, just update shortcode with custom value
            this.updateShortcode();
        },

        /**
         * Handle allowed types change
         */
        onAllowedTypesChange() {
            this.updateShortcode();
        },

        /**
         * Toggle role selection
         */
        toggleRole(role) {
            if (role === 'all') {
                if (this.selectedRoles.includes('all')) {
                    this.selectedRoles = [];
                } else {
                    this.selectedRoles = ['all'];
                }
            } else {
                // Remove 'all' if selecting specific roles
                if (this.selectedRoles.includes('all')) {
                    this.selectedRoles = [];
                }

                const index = this.selectedRoles.indexOf(role);
                if (index > -1) {
                    this.selectedRoles.splice(index, 1);
                } else {
                    this.selectedRoles.push(role);
                }

                // If no roles selected, default to 'all'
                if (this.selectedRoles.length === 0) {
                    this.selectedRoles = ['all'];
                }
            }

            this.updateShortcode();
        },

        /**
         * Check if role is selected
         */
        isRoleSelected(role) {
            return this.selectedRoles.includes(role);
        },

        /**
         * Update the generated shortcode
         */
        updateShortcode() {
            let shortcode = '[mega_upload';

            // Add folder if not root
            if (this.selectedFolderPath) {
                shortcode += ` folder="${this.selectedFolderPath}"`;
            }

            // Add max size if not default
            const maxSizeValue = this.maxSize === 'custom' ? this.customMaxSize.trim() : this.maxSize;
            if (maxSizeValue && maxSizeValue !== '100MB') {
                shortcode += ` max_size="${maxSizeValue}"`;
            }

            // Add allowed types if specified
            if (this.allowedTypes.trim()) {
                const types = this.allowedTypes.trim().replace(/\s+/g, '');
                shortcode += ` allowed_types="${types}"`;
            }

            // Add roles if not 'all'
            if (!this.selectedRoles.includes('all') && this.selectedRoles.length > 0) {
                shortcode += ` roles="${this.selectedRoles.join(',')}"`;
            }

            shortcode += ']';

            this.generatedShortcode = shortcode;
            console.log('Generated shortcode:', shortcode);
        },

        /**
         * Copy shortcode to clipboard
         */
        async copyShortcode() {
            try {
                await navigator.clipboard.writeText(this.generatedShortcode);
                this.copySuccess = true;

                // Reset success message after 2 seconds
                setTimeout(() => {
                    this.copySuccess = false;
                }, 2000);

            } catch (error) {
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = this.generatedShortcode;
                textArea.style.position = 'fixed';
                textArea.style.left = '-999999px';
                document.body.appendChild(textArea);
                textArea.select();

                try {
                    document.execCommand('copy');
                    this.copySuccess = true;

                    setTimeout(() => {
                        this.copySuccess = false;
                    }, 2000);
                } catch (err) {
                    console.error('Failed to copy:', err);
                }

                document.body.removeChild(textArea);
            }
        },

        /**
         * Retry connection
         */
        retryConnection() {
            // Reset states
            this.isInitializing = true;
            this.initializationComplete = false;
            this.showCheckmark = false;
            this.connectionError = '';

            // Try to connect (non-blocking)
            this.connectToMega();
        }
    };
};
