<?php
declare(strict_types=1);

namespace App\Extensions;

use App\Support\Hooks;
use App\Support\PluginManager;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig Extension for Plugin Hooks
 *
 * Provides functions to execute hooks directly in Twig templates,
 * enabling plugins to inject content at strategic points.
 */
class HooksTwigExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            // Action hooks - execute callbacks, return nothing
            new TwigFunction('do_action', [$this, 'doAction'], ['is_safe' => ['html']]),

            // Filter hooks - pass value through callbacks, return modified value
            new TwigFunction('apply_filter', [$this, 'applyFilter'], ['is_safe' => ['html']]),

            // Check if hook has callbacks
            new TwigFunction('has_hook', [$this, 'hasHook']),

            // Shorthand for common hook patterns
            new TwigFunction('hook', [$this, 'renderHook'], ['is_safe' => ['html']]),
        ];
    }

    /**
     * Execute an action hook
     * Actions are for side effects (output HTML, modify state)
     */
    public function doAction(string $hookName, ...$args): string
    {
        ob_start();
        Hooks::doAction($hookName, ...$args);
        return ob_get_clean() ?: '';
    }

    /**
     * Apply a filter hook
     * Filters transform data and return the result
     */
    public function applyFilter(string $filterName, $value, ...$args): mixed
    {
        return Hooks::applyFilter($filterName, $value, ...$args);
    }

    /**
     * Check if a hook has any registered callbacks
     */
    public function hasHook(string $hookName): bool
    {
        return Hooks::hasHook($hookName);
    }

    /**
     * Render a hook point - captures any output from action callbacks
     * This is a convenience method combining do_action with output buffering
     */
    public function renderHook(string $hookName, array $context = []): string
    {
        ob_start();
        Hooks::doAction($hookName, $context);
        return ob_get_clean() ?: '';
    }
}
