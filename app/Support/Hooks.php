<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Helper class per hooks - Fornisce funzioni globali comode per plugin
 */
class Hooks
{
    /**
     * Registra un'azione
     */
    public static function addAction(string $actionName, callable $callback, int $priority = 10, string $plugin = 'core'): void
    {
        PluginManager::getInstance()->addAction($actionName, $callback, $priority, $plugin);
    }

    /**
     * Esegue un'azione
     */
    public static function doAction(string $actionName, ...$args): void
    {
        PluginManager::getInstance()->doAction($actionName, ...$args);
    }

    /**
     * Registra un filtro
     */
    public static function addFilter(string $filterName, callable $callback, int $priority = 10, string $plugin = 'core'): void
    {
        PluginManager::getInstance()->addFilter($filterName, $callback, $priority, $plugin);
    }

    /**
     * Applica un filtro
     */
    public static function applyFilter(string $filterName, $value, ...$args): mixed
    {
        return PluginManager::getInstance()->applyFilter($filterName, $value, ...$args);
    }

    /**
     * Rimuove un hook
     */
    public static function removeHook(string $hookName, callable $callback): bool
    {
        return PluginManager::getInstance()->removeHook($hookName, $callback);
    }

    /**
     * Verifica se un hook esiste
     */
    public static function hasHook(string $hookName): bool
    {
        return PluginManager::getInstance()->hasHook($hookName);
    }
}
