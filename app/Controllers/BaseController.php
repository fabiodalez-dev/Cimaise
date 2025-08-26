<?php
declare(strict_types=1);

namespace App\Controllers;

abstract class BaseController
{
    protected string $basePath;

    public function __construct()
    {
        $this->basePath = $this->getBasePath();
    }

    protected function getBasePath(): string
    {
        $basePath = dirname($_SERVER['SCRIPT_NAME']);
        $basePath = $basePath === '/' ? '' : $basePath;
        
        // Remove /public from the path if present (since document root should be public/)
        if (str_ends_with($basePath, '/public')) {
            $basePath = substr($basePath, 0, -7); // Remove '/public'
        }
        
        return $basePath;
    }

    protected function redirect(string $path): string
    {
        return $this->basePath . $path;
    }
}