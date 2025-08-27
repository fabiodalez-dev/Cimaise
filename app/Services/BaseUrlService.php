<?php
declare(strict_types=1);

namespace App\Services;

class BaseUrlService
{
    /**
     * Get the current base URL automatically from request environment
     */
    public static function getCurrentBaseUrl(): string
    {
        // Try to get from environment first
        if (isset($_ENV['APP_URL']) && !empty($_ENV['APP_URL'])) {
            return rtrim($_ENV['APP_URL'], '/');
        }
        
        // Fallback to automatic detection
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
        $basePath = dirname($scriptPath);
        
        if ($basePath === '/' || $basePath === '\\') {
            $basePath = '';
        }
        
        // Remove /public from the path if present (since document root should be public/)
        if (str_ends_with($basePath, '/public')) {
            $basePath = substr($basePath, 0, -7); // Remove '/public'
        }
        
        return $protocol . '://' . $host . $basePath;
    }
    
    /**
     * Get base URL with fallback to APP_URL or manual URL
     */
    public static function getBaseUrl(?string $manualUrl = null): string
    {
        if (!empty($manualUrl)) {
            return rtrim($manualUrl, '/');
        }
        
        return self::getCurrentBaseUrl();
    }
    
    /**
     * Detect if we're in a subdirectory installation
     */
    public static function isSubdirectoryInstallation(): bool
    {
        $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
        $basePath = dirname($scriptPath);
        
        return !empty($basePath) && $basePath !== '/' && $basePath !== '\\';
    }
    
    /**
     * Get the installation path (for subdirectory installations)
     */
    public static function getInstallationPath(): string
    {
        // Try to get from APP_URL first (for CLI commands)
        if (isset($_ENV['APP_URL']) && !empty($_ENV['APP_URL'])) {
            $parsedUrl = parse_url($_ENV['APP_URL']);
            if (isset($parsedUrl['path']) && !empty($parsedUrl['path'])) {
                return rtrim($parsedUrl['path'], '/');
            }
            return '';
        }
        
        // Fallback to server variables for web requests
        $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
        $basePath = dirname($scriptPath);
        
        if ($basePath === '/' || $basePath === '\\') {
            return '';
        }
        
        // Remove /public from the path if present
        if (str_ends_with($basePath, '/public')) {
            $basePath = substr($basePath, 0, -7);
        }
        
        return $basePath;
    }
}