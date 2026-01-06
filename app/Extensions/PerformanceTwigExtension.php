<?php
declare(strict_types=1);

namespace App\Extensions;

use App\Services\PerformanceService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension for performance optimization helpers
 */
class PerformanceTwigExtension extends AbstractExtension
{
    public function __construct(
        private PerformanceService $performanceService
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('resource_hints', [$this, 'getResourceHints']),
            new TwigFunction('image_priority', [$this, 'getImagePriority']),
            new TwigFunction('image_sizes', [$this, 'getImageSizes']),
            new TwigFunction('script_loading', [$this, 'getScriptLoading']),
        ];
    }

    /**
     * Get resource hints for <head>
     */
    public function getResourceHints(): array
    {
        return $this->performanceService->getResourceHints();
    }

    /**
     * Get fetchpriority for image
     */
    public function getImagePriority(int $index, string $context = 'gallery'): string
    {
        return $this->performanceService->getImagePriority($index, $context);
    }

    /**
     * Get sizes attribute for image
     */
    public function getImageSizes(string $layout = 'default'): string
    {
        return $this->performanceService->getSizesAttribute($layout);
    }

    /**
     * Get script loading strategy
     */
    public function getScriptLoading(string $script): string
    {
        $strategies = $this->performanceService->getScriptLoadingStrategy();
        return $strategies[$script] ?? 'normal';
    }
}
