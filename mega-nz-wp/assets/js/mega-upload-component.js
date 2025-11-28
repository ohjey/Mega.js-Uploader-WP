{
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
    isInitializing: true,
    authError: false,
    isLoading: false,
    activeUploadCount: 0,
    maxConcurrentUploads: 1,
    isDragging: false,
    validationError: false,
    validationMessage: '',

    // File queue
    fileQueue: [],
    nextFileId: 1,

    // Global upload tracking for accurate time estimates
    uploadSessionStartTime: null,
    uploadSessionTotalBytes: 0,
    uploadSessionBytesUploaded: 0,
    smoothedSpeed: 0,
    lastSpeedUpdate: null,

    /**
     * Real initialization (called after component swap)
     */
    async realInit(settings) {
        console.log('Initializing Mega Upload with settings:', settings);

        // Parse settings from data attributes
        const parsedSettings = {};
        if (settings.folder) parsedSettings.folder = settings.folder;
        if (settings.maxsize) parsedSettings.maxSize = settings.maxsize;
        if (settings.allowedtypes) parsedSettings.allowedTypes = settings.allowedtypes;
        if (settings.allowedroles) parsedSettings.allowedRoles = settings.allowedroles;

        this.config = { ...this.config, ...parsedSettings };

        try {
            // Get credentials from server
            const credentials = await this.getCredentials();

            // Connect to Mega.nz
            await this.connectToMega(credentials.email, credentials.password);

            // Set up target folder
            await this.ensureTargetFolder();

            // Success
            this.isInitializing = false;
            this.authError = false;
            console.log('Mega Upload initialized successfully');
        } catch (error) {
            console.error('Initialization error:', error);
            this.isInitializing = false;
            this.authError = true;
        }
    },

    /**
     * Get credentials from WordPress backend
     */
    async getCredentials() {
        const formData = new FormData();
        formData.append('action', 'mega_upload_get_credentials');
        formData.append('nonce', window.megaUploadConfig.nonce);
        formData.append('allowed_roles', this.config.allowedRoles);

        const response = await fetch(window.megaUploadConfig.ajaxUrl, {
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
            this.storage = await new window.MegaStorage({
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
        let hasError = false;
        let errorMessage = '';

        for (const file of files) {
            // Validate file
            const validation = await this.validateFile(file);

            if (!validation.valid) {
                // Show validation error with visual feedback
                console.warn('File validation failed:', validation.message);
                hasError = true;
                errorMessage = validation.message;
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
                uploadStream: null,
                uploadSpeed: 0,
                uploadStartTime: null,
                lastSpeedUpdate: null,
                lastBytesUploaded: 0
            });
        }

        // Trigger visual error feedback if any file was rejected
        if (hasError) {
            this.showValidationError(errorMessage);
        }
    },

    /**
     * Show validation error with visual feedback
     */
    showValidationError(message) {
        this.validationError = true;
        this.validationMessage = message;

        // Clear error after 3 seconds
        setTimeout(() => {
            this.validationError = false;
            this.validationMessage = '';
        }, 3000);
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
            formData.append('nonce', window.megaUploadConfig.nonce);
            formData.append('file_name', file.name);
            formData.append('file_size', file.size);
            formData.append('file_type', file.type);
            formData.append('max_size', this.config.maxSize);
            formData.append('allowed_types', this.config.allowedTypes);
            formData.append('allowed_roles', this.config.allowedRoles);

            const response = await fetch(window.megaUploadConfig.ajaxUrl, {
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
     * Upload all queued files (up to 2 concurrently)
     */
    async uploadFiles() {
        const queuedFiles = this.fileQueue.filter(f => f.status === 'queued');

        if (queuedFiles.length === 0) {
            return;
        }

        // Initialize upload session tracking on first batch
        if (this.uploadSessionStartTime === null) {
            this.uploadSessionStartTime = Date.now();
            this.uploadSessionTotalBytes = this.fileQueue.reduce((total, file) => {
                if (file.status === 'queued' || file.status === 'uploading') {
                    return total + file.size;
                }
                return total;
            }, 0);
            this.uploadSessionBytesUploaded = 0;
        }

        // Process files in batches of maxConcurrentUploads (default: 2)
        for (let i = 0; i < queuedFiles.length; i += this.maxConcurrentUploads) {
            // Get batch of files to upload concurrently
            const batch = queuedFiles
                .slice(i, i + this.maxConcurrentUploads)
                .filter(f => f.status === 'queued');

            if (batch.length === 0) {
                continue;
            }

            // Upload batch concurrently using Promise.allSettled
            await Promise.allSettled(
                batch.map(fileItem => {
                    this.activeUploadCount++;
                    console.log(`Starting upload ${this.activeUploadCount}/${this.maxConcurrentUploads}:`, fileItem.name);

                    return this.uploadFile(fileItem).finally(() => {
                        // Only decrement if file wasn't cancelled (cancellation already decremented it)
                        if (fileItem.status !== 'cancelled') {
                            this.activeUploadCount--;
                            console.log(`Finished upload, active: ${this.activeUploadCount}`);
                        } else {
                            console.log(`Upload was cancelled, counter already decremented`);
                        }
                    });
                })
            );
        }

        // Check if more files were added during upload
        const stillQueued = this.fileQueue.filter(f => f.status === 'queued').length;

        if (stillQueued > 0) {
            // Continue uploading newly added files
            await this.uploadFiles();
            return;
        }

        // Reset session tracking when all uploads complete
        this.uploadSessionStartTime = null;
        this.uploadSessionTotalBytes = 0;
        this.uploadSessionBytesUploaded = 0;
        this.smoothedSpeed = 0;
        this.lastSpeedUpdate = null;

        const completedCount = this.fileQueue.filter(f => f.status === 'completed').length;
        const errorCount = this.fileQueue.filter(f => f.status === 'error').length;

        console.log(`Upload completed: ${completedCount} succeeded, ${errorCount} failed`);
    },

    /**
     * Upload a single file (dispatcher)
     */
    async uploadFile(fileItem) {
        fileItem.status = 'uploading';
        fileItem.progress = 0;
        fileItem.error = null;

        // Initialize speed tracking
        fileItem.uploadStartTime = Date.now();
        fileItem.lastSpeedUpdate = Date.now();
        fileItem.lastBytesUploaded = 0;
        fileItem.uploadSpeed = 0;

        try {
            // Use optimized chunking (pipe approach has compatibility issues)
            console.log('Uploading:', fileItem.file.name);
            await this.uploadFileWithChunks(fileItem);

            // Only mark as completed if not cancelled during upload
            if (fileItem.status !== 'cancelled') {
                fileItem.status = 'completed';
                fileItem.progress = 100;
                console.log('File uploaded successfully:', fileItem.file.name);
            } else {
                console.log('Upload was cancelled:', fileItem.file.name);
            }
        } catch (error) {
            // Don't overwrite cancelled status with error
            if (fileItem.status !== 'cancelled') {
                console.error('Upload error:', error);
                fileItem.status = 'error';
                fileItem.error = error.message || 'Upload failed';
            }
        }
    },


    /**
     * Upload file using pipe (mega.js recommended approach for large files)
     */
    async uploadFileWithPipe(fileItem) {
        const file = fileItem.file;

        // Create upload stream for piping
        // IMPORTANT: Cannot use maxConnections with manual write/pipe
        const uploadStream = this.targetFolder.upload({
            name: file.name,
            size: file.size
        });

        fileItem.uploadStream = uploadStream;

        // Track progress
        uploadStream.on('progress', (info) => {
            const now = Date.now();
            fileItem.progress = Math.round((info.bytesUploaded / info.bytesTotal) * 100);

            // Calculate upload speed
            const timeSinceLastSpeedUpdate = now - fileItem.lastSpeedUpdate;
            if (timeSinceLastSpeedUpdate >= 500) {
                const bytesSinceLastUpdate = info.bytesUploaded - fileItem.lastBytesUploaded;
                const bytesPerSecond = (bytesSinceLastUpdate / timeSinceLastSpeedUpdate) * 1000;
                fileItem.uploadSpeed = bytesPerSecond;
                fileItem.lastSpeedUpdate = now;
                fileItem.lastBytesUploaded = info.bytesUploaded;
            }
        });

        try {
            // Get browser's ReadableStream
            const readableStream = file.stream();
            const reader = readableStream.getReader();

            // Pipe data from file to upload stream
            // This mimics Node.js pipe() behavior in the browser
            async function pipeData() {
                while (true) {
                    if (fileItem.status === 'cancelled') {
                        await reader.cancel();
                        uploadStream.destroy();
                        return;
                    }

                    const { done, value } = await reader.read();
                    if (done) {
                        uploadStream.end();
                        break;
                    }

                    // Let mega.js handle the data with its internal optimizations
                    if (!uploadStream.write(value)) {
                        // Handle backpressure - wait for drain event
                        await new Promise(resolve => uploadStream.once('drain', resolve));
                    }
                }
            }

            await pipeData();
            await uploadStream.complete;

        } catch (error) {
            if (fileItem.status === 'cancelled') {
                console.log('Upload cancelled:', fileItem.file.name);
                return;
            }
            throw error;
        }
    },

    /**
     * Upload file using browser-compatible streaming
     */
    async uploadFileWithChunks(fileItem) {
        const file = fileItem.file;

        // Create upload stream with optimized settings for performance
        const uploadStream = this.targetFolder.upload({
            name: file.name,
            size: file.size,
            allowUploadBuffering: true,
            // Custom retry handler with shorter intervals for faster recovery
            handleRetries: (retryCount, error, cb) => {
                if (retryCount > 8) {
                    // Give up after 8 retries
                    return cb(error);
                }
                // Shorter retry intervals: 1s, 2s, 4s, 8s, etc. (instead of default longer waits)
                const timeout = Math.min(1000 * Math.pow(2, retryCount - 1), 30000);
                console.log(`Upload retry ${retryCount} in ${timeout}ms...`);
                setTimeout(() => cb(), timeout);
            }
        });

        fileItem.uploadStream = uploadStream;

        // Track progress with throttling to reduce UI lag
        let lastProgressUpdate = 0;
        const progressThrottle = 100; // Update UI max every 100ms

        uploadStream.on('progress', (info) => {
            const now = Date.now();

            // Update progress display
            if (now - lastProgressUpdate >= progressThrottle) {
                fileItem.progress = Math.round((info.bytesUploaded / info.bytesTotal) * 100);
                lastProgressUpdate = now;
            }

            // Calculate upload speed (update every 500ms for smooth readings)
            const timeSinceLastSpeedUpdate = now - fileItem.lastSpeedUpdate;
            if (timeSinceLastSpeedUpdate >= 500) {
                const bytesSinceLastUpdate = info.bytesUploaded - fileItem.lastBytesUploaded;
                const bytesPerSecond = (bytesSinceLastUpdate / timeSinceLastSpeedUpdate) * 1000;

                fileItem.uploadSpeed = bytesPerSecond;
                fileItem.lastSpeedUpdate = now;
                fileItem.lastBytesUploaded = info.bytesUploaded;
            }
        });

        // Try using browser's native stream if available, otherwise use manual chunking
        if (file.stream && typeof file.stream === 'function') {
            // Modern browsers support file.stream() which returns a ReadableStream
            try {
                const stream = file.stream();
                const reader = stream.getReader();

                // Read and write chunks from the browser's ReadableStream
                const pump = async () => {
                    try {
                        while (fileItem.status === 'uploading') {
                            const { done, value } = await reader.read();
                            if (done) break;

                            // Check again before writing in case cancelled during read
                            if (fileItem.status !== 'uploading') {
                                break;
                            }

                            // Write the chunk to upload stream
                            uploadStream.write(value);
                        }

                        // Check if cancelled
                        if (fileItem.status === 'cancelled') {
                            // Cancel the reader first to stop reading
                            try {
                                await reader.cancel();
                            } catch (e) {
                                console.log('Reader cancel error:', e.message);
                            }
                            uploadStream.destroy();
                            return;
                        }

                        // Finish the upload
                        uploadStream.end();
                    } catch (error) {
                        if (fileItem.status !== 'cancelled') {
                            throw error;
                        }
                    }
                };

                await pump();
            } catch (error) {
                console.error('Stream error, falling back to manual chunking:', error);
                // Fall back to manual chunking if stream fails
                await this.uploadWithManualChunking(fileItem, file, uploadStream);
            }
        } else {
            // Fallback for older browsers: manual chunking
            await this.uploadWithManualChunking(fileItem, file, uploadStream);
        }

        // Wait for completion
        if (fileItem.status !== 'cancelled') {
            await uploadStream.complete;
        }

        fileItem.uploadStream = null;
    },

    /**
     * Manual chunking fallback for older browsers
     */
    async uploadWithManualChunking(fileItem, file, uploadStream) {
        // Larger chunks when buffering is enabled for better performance
        const chunkSize = 4 * 1024 * 1024; // 4MB chunks
        let offset = 0;

        while (offset < file.size && fileItem.status === 'uploading') {
            const end = Math.min(offset + chunkSize, file.size);
            const slice = file.slice(offset, end);

            try {
                const arrayBuffer = await slice.arrayBuffer();
                const chunk = new Uint8Array(arrayBuffer);
                uploadStream.write(chunk);
                offset = end;
            } catch (error) {
                if (fileItem.status !== 'cancelled') {
                    throw error;
                }
                break;
            }
        }

        if (fileItem.status === 'cancelled') {
            uploadStream.destroy();
            return;
        }

        uploadStream.end();
    },

    /**
     * Cancel an upload
     */
    cancelUpload(fileId) {
        const fileItem = this.fileQueue.find(f => f.id === fileId);

        if (fileItem) {
            const wasUploading = fileItem.status === 'uploading';

            // Set status first so upload loop sees it immediately
            fileItem.status = 'cancelled';
            fileItem.error = 'Upload cancelled by user';

            // DO NOT destroy the stream here - let the upload method handle cleanup
            // This prevents "data size mismatch" errors from destroying mid-stream

            // If this was actively uploading, decrement the counter immediately
            // so the next file can start uploading
            if (wasUploading && this.activeUploadCount > 0) {
                this.activeUploadCount--;
                console.log('Decremented activeUploadCount due to cancellation, now:', this.activeUploadCount);
            }

            console.log('Upload cancelled:', fileItem.name);

            // Wait longer before removing so user can see the cancelled state
            setTimeout(() => {
                this.removeFromQueue(fileId);
                console.log('Removed cancelled file from queue:', fileItem.name);
            }, 2000);
        }

        // Log current upload status
        const stillQueued = this.fileQueue.some(f => f.status === 'queued');

        if (this.activeUploadCount === 0 && !stillQueued) {
            console.log('All uploads finished or cancelled');
        } else {
            console.log(`Continuing with ${this.activeUploadCount} active uploads, ${stillQueued ? 'files still queued' : 'no files queued'}...`);
        }
    },

    /**
     * Remove file from queue
     */
    removeFromQueue(fileId) {
        const index = this.fileQueue.findIndex(f => f.id === fileId);
        if (index !== -1) {
            this.fileQueue.splice(index, 1);
        }
    },

    /**
     * Clear completed files
     */
    clearCompleted() {
        this.fileQueue = this.fileQueue.filter(f => f.status !== 'completed');
    },

    /**
     * Clear all files
     */
    clearAll() {
        if (this.activeUploadCount > 0) {
            return;
        }

        this.fileQueue = [];
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
     * Get overall progress percentage
     */
    getOverallProgress() {
        if (this.fileQueue.length === 0) {
            return 0;
        }

        let totalProgress = 0;
        for (const file of this.fileQueue) {
            if (file.status === 'completed') {
                totalProgress += 100;
            } else if (file.status === 'uploading') {
                totalProgress += file.progress;
            }
            // queued, error, cancelled files contribute 0
        }

        return Math.round(totalProgress / this.fileQueue.length);
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
     * Format upload speed for display
     */
    formatSpeed(bytesPerSecond) {
        if (!bytesPerSecond || bytesPerSecond === 0) return '0 MB/s';

        const mbps = bytesPerSecond / (1024 * 1024);
        return mbps.toFixed(1) + ' MB/s';
    },

    /**
     * Get estimated time remaining for all uploads
     */
    getTimeRemaining() {
        // Only show if we have an active upload session
        if (this.uploadSessionStartTime === null || this.activeUploadCount === 0) {
            return '';
        }

        // Calculate current progress across entire session
        let currentBytesUploaded = 0;
        let uploadingFiles = [];

        for (const file of this.fileQueue) {
            if (file.status === 'completed') {
                currentBytesUploaded += file.size;
            } else if (file.status === 'uploading') {
                const bytesUploaded = (file.progress / 100) * file.size;
                currentBytesUploaded += bytesUploaded;
                uploadingFiles.push(file);
            }
        }

        // Calculate elapsed time
        const elapsedSeconds = (Date.now() - this.uploadSessionStartTime) / 1000;

        // Need at least 3 seconds of data for accurate estimate
        if (elapsedSeconds < 3) {
            return '';
        }

        // Use actual current combined speed from actively uploading files
        let currentCombinedSpeed = 0;
        for (const file of uploadingFiles) {
            if (file.uploadSpeed > 0) {
                currentCombinedSpeed += file.uploadSpeed;
            }
        }

        // Calculate instantaneous speed
        let instantSpeed;
        if (currentCombinedSpeed > 0) {
            instantSpeed = currentCombinedSpeed;
        } else {
            // Fall back to session average
            instantSpeed = currentBytesUploaded / elapsedSeconds;
        }

        // Apply exponential moving average for smoothing (reduces jitter)
        const now = Date.now();
        const alpha = 0.3; // Smoothing factor (lower = smoother but slower to adapt)

        if (this.smoothedSpeed === 0) {
            // Initialize smoothed speed
            this.smoothedSpeed = instantSpeed;
        } else if (this.lastSpeedUpdate !== null) {
            // Only update if at least 500ms have passed to avoid too frequent updates
            const timeSinceUpdate = now - this.lastSpeedUpdate;
            if (timeSinceUpdate >= 500) {
                this.smoothedSpeed = (alpha * instantSpeed) + ((1 - alpha) * this.smoothedSpeed);
                this.lastSpeedUpdate = now;
            }
        } else {
            this.lastSpeedUpdate = now;
        }

        const estimatedSpeed = this.smoothedSpeed;

        // If speed is too low, don't show estimate
        if (estimatedSpeed < 1024) {
            return '';
        }

        // Calculate remaining bytes and time
        const bytesRemaining = this.uploadSessionTotalBytes - currentBytesUploaded;
        const secondsRemaining = bytesRemaining / estimatedSpeed;

        // Don't show unrealistic times
        if (secondsRemaining < 1) {
            return 'A few seconds remaining';
        }

        // Format time
        if (secondsRemaining < 60) {
            // Round to nearest 5 seconds for stability
            const rounded = Math.max(5, Math.ceil(secondsRemaining / 5) * 5);
            return 'About ' + rounded + ' seconds remaining';
        } else if (secondsRemaining < 3600) {
            // Show minutes
            const minutes = Math.ceil(secondsRemaining / 60);
            return 'About ' + minutes + ' minute' + (minutes !== 1 ? 's' : '') + ' remaining';
        } else {
            // Show hours and minutes
            const hours = Math.floor(secondsRemaining / 3600);
            const minutes = Math.round((secondsRemaining % 3600) / 60);
            let timeStr = 'About ' + hours + ' hour' + (hours !== 1 ? 's' : '');
            if (minutes > 0) {
                timeStr += ' ' + minutes + ' min';
            }
            return timeStr + ' remaining';
        }
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
    }
}