<?php

namespace Ace;

use Exception;

class UploadService
{
    /**
     * Ensure a directory exists with correct permissions for production.
     * Auto-creates the directory and all parents if missing.
     * Tries 0755 first, then 0775 as fallback for shared hosting.
     *
     * @param string $dir Absolute path to the directory
     * @return bool True if directory exists and is writable
     */
    public static function ensureDirectory(string $dir): bool
    {
        if (is_dir($dir) && is_writable($dir)) {
            return true;
        }

        // Try creating with 0755 (standard production permission)
        if (!is_dir($dir)) {
            $created = @mkdir($dir, 0755, true);

            // Fallback: try 0775 for shared hosting environments
            if (!$created) {
                $created = @mkdir($dir, 0775, true);
            }

            if (!$created) {
                Logger::error("UploadService: Failed to create directory: {$dir}");
                return false;
            }
        }

        // Ensure the directory is writable
        if (!is_writable($dir)) {
            // Try to fix permissions
            @chmod($dir, 0755);
            if (!is_writable($dir)) {
                @chmod($dir, 0775);
            }
            if (!is_writable($dir)) {
                Logger::error("UploadService: Directory exists but is not writable: {$dir}");
                return false;
            }
        }

        // Place a protective .htaccess to block PHP execution in upload dirs
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "# Prevent PHP execution in uploads\n<FilesMatch \"\\.(php|phtml|php3|php4|php5|php7|phps)$\">\n    Deny from all\n</FilesMatch>\n");
        }

        return true;
    }

    /**
     * Handle safe file uploading with optional automatic image downscaling and compression.
     * 
     * @param array $file $_FILES['input_name'] array
     * @param string $destinationDir Folder path to store upload
     * @param array $allowedExtensions File extensions to restrict (e.g. ['jpg', 'png'])
     * @param int $maxSize Size limit in bytes (default 5MB)
     * @param int $maxWidth Max width to downscale images to (0 for default 1920px)
     * @param int $maxHeight Max height to downscale images to (0 for default 1080px)
     * @param int $quality Quality factor (1-100, default 85)
     * @return array Contains 'success' (bool) and either 'filepath'/'filename' or 'error' message
     */
    public function uploadFile(
        array $file,
        string $destinationDir,
        array $allowedExtensions = [],
        int $maxSize = 5242880,
        int $maxWidth = 0,
        int $maxHeight = 0,
        int $quality = 85
    ): array {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return [
                'success' => false,
                'error' => $this->getUploadErrorMessage($file['error'])
            ];
        }

        if ($file['size'] > $maxSize) {
            return [
                'success' => false,
                'error' => 'File size exceeds limit of ' . ($maxSize / 1024 / 1024) . 'MB.'
            ];
        }

        $filename = $file['name'];

        // 1. Block null bytes and path traversal in original filename
        if (str_contains($filename, "\0") || str_contains($filename, '/') || str_contains($filename, '\\')) {
            return [
                'success' => false,
                'error' => 'Security violation: Malicious characters detected in filename.'
            ];
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // 2. Strict blacklist of dangerous executable and script extensions
        $forbiddenExtensions = [
            'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phps', 'phar',
            'exe', 'bat', 'cmd', 'sh', 'bash', 'bin', 'pl', 'py', 'cgi',
            'js', 'vbs', 'html', 'htm', 'shtml', 'htaccess', 'htpasswd',
            'ini', 'env', 'config', 'asp', 'aspx', 'jsp', 'dll', 'so'
        ];

        // Check primary extension
        if (in_array($extension, $forbiddenExtensions, true)) {
            return [
                'success' => false,
                'error' => 'Security violation: Executable file types are strictly prohibited.'
            ];
        }

        // Check for double extension attacks (e.g. payload.php.jpg)
        $nameParts = explode('.', strtolower($filename));
        foreach ($nameParts as $part) {
            if (in_array(trim($part), $forbiddenExtensions, true)) {
                return [
                    'success' => false,
                    'error' => 'Security violation: Suspicious multiple file extensions detected.'
                ];
            }
        }

        // 3. Check allowed extensions whitelist if specified
        if (!empty($allowedExtensions) && !in_array($extension, $allowedExtensions, true)) {
            return [
                'success' => false,
                'error' => 'Invalid file type. Allowed: ' . implode(', ', $allowedExtensions)
            ];
        }

        // 4. MIME sniffing verification using fileinfo magic bytes
        if (function_exists('finfo_open') && file_exists($file['tmp_name'])) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detectedMime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            $imageMimes = [
                'jpg'  => ['image/jpeg', 'image/pjpeg'],
                'jpeg' => ['image/jpeg', 'image/pjpeg'],
                'png'  => ['image/png'],
                'gif'  => ['image/gif'],
                'webp' => ['image/webp'],
            ];

            if (isset($imageMimes[$extension])) {
                if (!in_array($detectedMime, $imageMimes[$extension], true)) {
                    return [
                        'success' => false,
                        'error' => 'File content does not match the file extension (MIME spoofing detected).'
                    ];
                }

                // Verify genuine image structure
                if (!@getimagesize($file['tmp_name'])) {
                    return [
                        'success' => false,
                        'error' => 'Corrupted or forged image file detected.'
                    ];
                }
            }
        }

        // 5. Scan upload for embedded PHP tags / webshell signatures
        if (file_exists($file['tmp_name'])) {
            $sampleHeader = file_get_contents($file['tmp_name'], false, null, 0, 4096);
            if ($sampleHeader !== false && preg_match('/<\?(php|=)/i', $sampleHeader)) {
                return [
                    'success' => false,
                    'error' => 'Security violation: Embedded executable code detected in upload.'
                ];
            }
        }

        // Generate unique cryptographically secure name (user filename is never kept on disk)
        $uniqueName = bin2hex(random_bytes(16)) . '_' . time() . '.' . $extension;

        // Auto-create upload directory with correct permissions
        if (!self::ensureDirectory($destinationDir)) {
            return [
                'success' => false,
                'error' => 'Upload directory could not be created or is not writable. Please check folder permissions on: ' . basename($destinationDir)
            ];
        }

        $destinationPath = rtrim($destinationDir, '/\\') . '/' . $uniqueName;

        if (move_uploaded_file($file['tmp_name'], $destinationPath)) {
            // Ensure the uploaded file has readable permissions
            @chmod($destinationPath, 0644);

            // Automatically optimize & reduce file size for raster images
            if (in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) {
                try {
                    $targetW = $maxWidth > 0 ? $maxWidth : 1920;
                    $targetH = $maxHeight > 0 ? $maxHeight : 1080;
                    $this->optimizeImage($destinationPath, $targetW, $targetH, $quality);
                } catch (\Throwable $e) {
                    Logger::error("UploadService: Image optimization failed for {$uniqueName}: " . $e->getMessage());
                }
            }

            return [
                'success' => true,
                'filename' => $uniqueName,
                'filepath' => $destinationPath
            ];
        }

        Logger::error("UploadService: move_uploaded_file failed for {$file['name']} -> {$destinationPath}");
        return [
            'success' => false,
            'error' => 'Failed to save uploaded file to disk. Please check server write permissions.'
        ];
    }

    /**
     * Optimize an image file in-place: resize if larger than bounds, re-encode with high compression, preserve transparency.
     */
    public function optimizeImage(string $filePath, int $maxWidth = 1920, int $maxHeight = 1080, int $quality = 85): bool
    {
        if (!file_exists($filePath) || !extension_loaded('gd')) {
            return false;
        }

        $imageInfo = @getimagesize($filePath);
        if (!$imageInfo) {
            return false;
        }

        list($originalWidth, $originalHeight, $imageType) = $imageInfo;

        // Only process supported types
        if (!in_array($imageType, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF])) {
            return false;
        }

        // Calculate new dimensions preserving aspect ratio
        $newWidth = $originalWidth;
        $newHeight = $originalHeight;

        if ($originalWidth > $maxWidth || $originalHeight > $maxHeight) {
            $aspectRatio = $originalWidth / $originalHeight;
            if ($originalWidth / $maxWidth > $originalHeight / $maxHeight) {
                $newWidth = $maxWidth;
                $newHeight = (int)round($maxWidth / $aspectRatio);
            } else {
                $newHeight = $maxHeight;
                $newWidth = (int)round($maxHeight * $aspectRatio);
            }
        }

        return $this->resizeImage($filePath, $filePath, $newWidth, $newHeight, false);
    }

    /**
     * Resize image maintaining aspect ratio and alpha transparency using GD Library
     */
    public function resizeImage(string $sourcePath, string $destinationPath, int $targetWidth, int $targetHeight, bool $keepAspectRatio = true): bool
    {
        if (!file_exists($sourcePath)) {
            return false;
        }

        $imageInfo = getimagesize($sourcePath);
        if (!$imageInfo) {
            return false;
        }

        list($originalWidth, $originalHeight, $imageType) = $imageInfo;

        // Load image base on MIME type
        switch ($imageType) {
            case IMAGETYPE_JPEG:
                $sourceImage = @imagecreatefromjpeg($sourcePath);
                break;
            case IMAGETYPE_PNG:
                $sourceImage = @imagecreatefrompng($sourcePath);
                break;
            case IMAGETYPE_GIF:
                $sourceImage = @imagecreatefromgif($sourcePath);
                break;
            case IMAGETYPE_WEBP:
                $sourceImage = @imagecreatefromwebp($sourcePath);
                break;
            default:
                return false; // Type not supported
        }

        if (!$sourceImage) {
            return false;
        }

        $newWidth = $targetWidth;
        $newHeight = $targetHeight;

        // Recalculate dimensions to fit aspect ratios
        if ($keepAspectRatio) {
            $aspectRatio = $originalWidth / $originalHeight;
            if ($newWidth / $newHeight > $aspectRatio) {
                $newWidth = (int)round($newHeight * $aspectRatio);
            } else {
                $newHeight = (int)round($newWidth / $aspectRatio);
            }
        }

        // Create empty true color canvas
        $resizedImage = imagecreatetruecolor($newWidth, $newHeight);

        // Maintain alpha transparency layers for PNG, WEBP, and GIF
        if ($imageType === IMAGETYPE_PNG || $imageType === IMAGETYPE_WEBP) {
            imagealphablending($resizedImage, false);
            imagesavealpha($resizedImage, true);
        } elseif ($imageType === IMAGETYPE_GIF) {
            $transparentIndex = @imagecolortransparent($sourceImage);
            if ($transparentIndex >= 0) {
                $transparentColor = @imagecolorsforindex($sourceImage, $transparentIndex);
                if ($transparentColor) {
                    $transparency = imagecolorallocatealpha(
                        $resizedImage,
                        $transparentColor['red'],
                        $transparentColor['green'],
                        $transparentColor['blue'],
                        127
                    );
                    imagefill($resizedImage, 0, 0, $transparency);
                    imagecolortransparent($resizedImage, $transparency);
                }
            }
        }

        // Resample copying original to destination size
        @imagecopyresampled(
            $resizedImage,
            $sourceImage,
            0, 0, 0, 0,
            $newWidth, $newHeight,
            $originalWidth, $originalHeight
        );

        // Ensure output folder exists with correct permissions
        $destDir = dirname($destinationPath);
        self::ensureDirectory($destDir);

        // Save image to output file path
        $result = false;
        switch ($imageType) {
            case IMAGETYPE_JPEG:
                $result = @imagejpeg($resizedImage, $destinationPath, 85);
                break;
            case IMAGETYPE_PNG:
                $result = @imagepng($resizedImage, $destinationPath, 6);
                break;
            case IMAGETYPE_GIF:
                $result = @imagegif($resizedImage, $destinationPath);
                break;
            case IMAGETYPE_WEBP:
                $result = @imagewebp($resizedImage, $destinationPath, 80);
                break;
        }

        // Release references from memory
        imagedestroy($sourceImage);
        imagedestroy($resizedImage);

        return $result;
    }

    /**
     * Map PHP upload errors to user readable string logs
     */
    private function getUploadErrorMessage(int $errorCode): string
    {
        switch ($errorCode) {
            case UPLOAD_ERR_INI_SIZE:
                return 'The uploaded file exceeds the upload_max_filesize directive in php.ini.';
            case UPLOAD_ERR_FORM_SIZE:
                return 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form.';
            case UPLOAD_ERR_PARTIAL:
                return 'The file was only partially uploaded.';
            case UPLOAD_ERR_NO_FILE:
                return 'No file was uploaded.';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Missing a temporary folder.';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Failed to write file to disk.';
            case UPLOAD_ERR_EXTENSION:
                return 'A PHP extension stopped the file upload.';
            default:
                return 'An unknown upload error occurred.';
        }
    }
}

