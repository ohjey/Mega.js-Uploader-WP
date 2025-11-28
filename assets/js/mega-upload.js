/**
 * Mega Upload - Alpine.js Component
 * Handles file uploads to Mega.nz using the Mega.js SDK
 */

// Import Mega.js Storage at the top level
import { Storage } from 'https://unpkg.com/megajs@1/dist/main.browser-es.mjs';

console.log('Mega Upload module loaded, Storage imported');

// Wait for Alpine to be available, then register our component
document.addEventListener('alpine:init', () => {
    console.log('Alpine init event fired, registering megaUploader');

    // Register using Alpine.data() for proper initialization
    Alpine.data('megaUploader', () => ({
            // Configuration
            config: {
                folder: '',
                maxSize: '100MB',
                allowedTypes: '',
                allowedRoles: 'all'
            },

            // Mega.js instances
            storage: null,
            targetFolder: null,

            // State
            isLoading: false,
            isUploading: false,
            isDragging: false,
            statusMessage: '',
            statusType: '', // 'success', 'error', 'info'

            // File queue
            fileQueue: [],
            nextFileId: 1,

            /**
             * Initialize the component
             */
            async init(settings) {
                console.log('Initializing Mega Upload with settings:', settings);

                this.config = { ...this.config, ...settings };
                this.isLoading = true;

                try {
                    // Get credentials from server
                    const credentials = await this.getCredentials();

                    // Connect to Mega.nz
                    await this.connectToMega(credentials.email, credentials.password);

                    // Set up target folder
                    await this.ensureTargetFolder();

                    this.showStatus('Ready to upload files', 'success');
                } catch (error) {
                    console.error('Initialization error:', error);
                    this.showStatus(error.message || 'Failed to initialize. Please try again.', 'error');
                } finally {
                    this.isLoading = false;
                }
            },

            /**
             * Get credentials from WordPress backend
             */
            async getCredentials() {
                const formData = new FormData();
                formData.append('action', 'mega_upload_get_credentials');
                formData.append('nonce', megaUploadConfig.nonce);
                formData.append('allowed_roles', this.config.allowedRoles);

                const response = await fetch(megaUploadConfig.ajaxUrl, {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.data.message || 'Failed to get credentials');
                }

                return data.data;
            },

            /**
             * Connect to Mega.nz
             */
            async connectToMega(email, password) {
                try {
                    this.storage = await new Storage({
                        email: email,
                        password: password,
                        userAgent: null
                    }).ready;

                    console.log('Connected to Mega.nz successfully');
                } catch (error) {
                    console.error('Mega.nz connection error:', error);
                    throw new Error('Failed to connect to Mega.nz. Please check your credentials.');
                }
            },

            /**
             * Ensure target folder exists
             */
            async ensureTargetFolder() {
                if (!this.config.folder || this.config.folder === '') {
                    // Use root folder
                    this.targetFolder = this.storage.root;
                    console.log('Using root folder');
                    return;
                }

                // Find or create the target folder
                const folderPath = this.config.folder.trim();
                let currentFolder = this.storage.root;

                // Split path and create folders as needed
                const pathParts = folderPath.split('/').filter(part => part.length > 0);

                for (const folderName of pathParts) {
                    let foundFolder = null;

                    // Search for existing folder
                    for (const child of currentFolder.children) {
                        if (child.directory && child.name === folderName) {
                            foundFolder = child;
                            break;
                        }
                    }

                    // Create folder if it doesn't exist
                    if (!foundFolder) {
                        console.log('Creating folder:', folderName);
                        foundFolder = await currentFolder.mkdir(folderName);
                    }

                    currentFolder = foundFolder;
                }

                this.targetFolder = currentFolder;
                console.log('Target folder set:', folderPath);
            },

            /**
             * Handle drag over
             */
            handleDragOver(event) {
                event.preventDefault();
                this.isDragging = true;
            },

            /**
             * Handle drag leave
             */
            handleDragLeave(event) {
                event.preventDefault();
                this.isDragging = false;
            },

            /**
             * Handle file drop
             */
            handleDrop(event) {
                event.preventDefault();
                this.isDragging = false;

                const files = Array.from(event.dataTransfer.files);
                this.addFilesToQueue(files);
            },

            /**
             * Handle file selection from input
             */
            handleFileSelect(event) {
                const files = Array.from(event.target.files);
                this.addFilesToQueue(files);

                // Reset input
                event.target.value = '';
            },

            /**
             * Add files to upload queue
             */
            async addFilesToQueue(files) {
                for (const file of files) {
                    // Validate file
                    const validation = await this.validateFile(file);

                    if (!validation.valid) {
                        this.showStatus(validation.message, 'error');
                        continue;
                    }

                    // Add to queue
                    this.fileQueue.push({
                        id: this.nextFileId++,
                        file: file,
                        name: file.name,
                        size: file.size,
                        status: 'queued', // queued, uploading, completed, error, cancelled
                        progress: 0,
                        error: null,
                        uploadStream: null
                    });
                }

                if (files.length > 0) {
                    this.showStatus(`${files.length} file(s) added to queue`, 'success');
                }
            },

            /**
             * Validate file
             */
            async validateFile(file) {
                // Client-side validation
                const maxSizeBytes = this.parseSize(this.config.maxSize);

                if (file.size > maxSizeBytes) {
                    return {
                        valid: false,
                        message: `File "${file.name}" exceeds maximum size of ${this.config.maxSize}`
                    };
                }

                // Check file type
                if (this.config.allowedTypes) {
                    const extension = file.name.split('.').pop().toLowerCase();
                    const allowedTypes = this.config.allowedTypes.toLowerCase().split(',').map(t => t.trim());

                    if (!allowedTypes.includes(extension)) {
                        return {
                            valid: false,
                            message: `File type ".${extension}" is not allowed`
                        };
                    }
                }

                // Server-side validation
                try {
                    const formData = new FormData();
                    formData.append('action', 'mega_upload_validate');
                    formData.append('nonce', megaUploadConfig.nonce);
                    formData.append('file_name', file.name);
                    formData.append('file_size', file.size);
                    formData.append('file_type', file.type);
                    formData.append('max_size', this.config.maxSize);
                    formData.append('allowed_types', this.config.allowedTypes);
                    formData.append('allowed_roles', this.config.allowedRoles);

                    const response = await fetch(megaUploadConfig.ajaxUrl, {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();

                    if (!data.success) {
                        return {
                            valid: false,
                            message: data.data.message
                        };
                    }
                } catch (error) {
                    console.error('Validation error:', error);
                    return {
                        valid: false,
                        message: 'Failed to validate file'
                    };
                }

                return { valid: true };
            },

            /**
             * Upload all queued files
             */
            async uploadFiles() {
                const queuedFiles = this.fileQueue.filter(f => f.status === 'queued');

                if (queuedFiles.length === 0) {
                    this.showStatus('No files to upload', 'info');
                    return;
                }

                this.isUploading = true;
                this.showStatus(`Uploading ${queuedFiles.length} file(s)...`, 'info');

                for (const fileItem of queuedFiles) {
                    if (fileItem.status !== 'queued') {
                        continue;
                    }

                    await this.uploadFile(fileItem);
                }

                this.isUploading = false;

                const completedCount = this.fileQueue.filter(f => f.status === 'completed').length;
                const errorCount = this.fileQueue.filter(f => f.status === 'error').length;

                if (errorCount === 0) {
                    this.showStatus(`All ${completedCount} file(s) uploaded successfully!`, 'success');
                } else {
                    this.showStatus(
                        `Upload completed: ${completedCount} succeeded, ${errorCount} failed`,
                        'error'
                    );
                }
            },

            /**
             * Upload a single file
             */
            async uploadFile(fileItem) {
                fileItem.status = 'uploading';
                fileItem.progress = 0;
                fileItem.error = null;

                try {
                    const file = fileItem.file;

                    // Create upload stream
                    const uploadStream = this.targetFolder.upload({
                        name: file.name,
                        size: file.size
                    });

                    fileItem.uploadStream = uploadStream;

                    // Track progress
                    uploadStream.on('progress', (info) => {
                        fileItem.progress = Math.round((info.bytesUploaded / info.bytesTotal) * 100);
                    });

                    // Read and upload file in chunks
                    const chunkSize = 1024 * 1024; // 1MB
                    let offset = 0;

                    while (offset < file.size && fileItem.status === 'uploading') {
                        const slice = file.slice(offset, offset + chunkSize);
                        const arrayBuffer = await slice.arrayBuffer();
                        const chunk = new Uint8Array(arrayBuffer);

                        uploadStream.write(chunk);
                        offset += chunkSize;
                    }

                    // Check if cancelled
                    if (fileItem.status === 'cancelled') {
                        uploadStream.destroy();
                        return;
                    }

                    // Finish upload
                    uploadStream.end();
                    await uploadStream.complete;

                    fileItem.status = 'completed';
                    fileItem.progress = 100;
                    console.log('File uploaded successfully:', file.name);
                } catch (error) {
                    console.error('Upload error:', error);
                    fileItem.status = 'error';
                    fileItem.error = error.message || 'Upload failed';
                }
            },

            /**
             * Cancel an upload
             */
            cancelUpload(fileId) {
                const fileItem = this.fileQueue.find(f => f.id === fileId);

                if (fileItem && fileItem.uploadStream) {
                    fileItem.uploadStream.destroy();
                    fileItem.status = 'cancelled';
                    fileItem.error = 'Upload cancelled by user';
                    this.showStatus(`Upload of "${fileItem.name}" cancelled`, 'info');
                }
            },

            /**
             * Remove file from queue
             */
            removeFromQueue(fileId) {
                const index = this.fileQueue.findIndex(f => f.id === fileId);
                if (index !== -1) {
                    const fileName = this.fileQueue[index].name;
                    this.fileQueue.splice(index, 1);
                    this.showStatus(`"${fileName}" removed from queue`, 'info');
                }
            },

            /**
             * Clear completed files
             */
            clearCompleted() {
                const completedCount = this.fileQueue.filter(f => f.status === 'completed').length;
                this.fileQueue = this.fileQueue.filter(f => f.status !== 'completed');

                if (completedCount > 0) {
                    this.showStatus(`${completedCount} completed file(s) cleared`, 'info');
                }
            },

            /**
             * Clear all files
             */
            clearAll() {
                if (this.isUploading) {
                    this.showStatus('Cannot clear queue while uploading', 'error');
                    return;
                }

                const count = this.fileQueue.length;
                this.fileQueue = [];

                if (count > 0) {
                    this.showStatus('Queue cleared', 'info');
                }
            },

            /**
             * Get count of queued files
             */
            getQueuedCount() {
                return this.fileQueue.filter(f => f.status === 'queued').length;
            },

            /**
             * Get count of completed files
             */
            getCompletedCount() {
                return this.fileQueue.filter(f => f.status === 'completed').length;
            },

            /**
             * Format file size for display
             */
            formatFileSize(bytes) {
                if (bytes === 0) return '0 Bytes';

                const k = 1024;
                const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));

                return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
            },

            /**
             * Parse size string to bytes
             */
            parseSize(sizeString) {
                const match = sizeString.match(/^(\d+(?:\.\d+)?)\s*([A-Z]*)$/i);

                if (!match) {
                    return 100 * 1024 * 1024; // Default 100MB
                }

                const number = parseFloat(match[1]);
                const unit = (match[2] || 'B').toUpperCase();

                const multipliers = {
                    'B': 1,
                    'KB': 1024,
                    'MB': 1024 * 1024,
                    'GB': 1024 * 1024 * 1024,
                    'TB': 1024 * 1024 * 1024 * 1024
                };

                return number * (multipliers[unit] || 1);
            },

            /**
             * Show status message
             */
            showStatus(message, type = 'info') {
                this.statusMessage = message;
                this.statusType = type;

                // Auto-hide after 5 seconds for success/info messages
                if (type === 'success' || type === 'info') {
                    setTimeout(() => {
                        if (this.statusMessage === message) {
                            this.statusMessage = '';
                        }
                    }, 5000);
                }
            }
    }));

    console.log('megaUploader component registered with Alpine');
});
