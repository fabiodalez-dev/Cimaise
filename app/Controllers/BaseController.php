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
        return $basePath === '/' ? '' : $basePath;
    }

    protected function redirect(string $path): string
    {
        return $this->basePath . $path;
    }
}