<?php
declare(strict_types=1);

namespace CustomTemplatesPro\Extensions;

use CustomTemplatesPro\Services\PluginTranslationService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension for plugin translations
 */
class PluginTranslationTwigExtension extends AbstractExtension
{
    private PluginTranslationService $translator;

    public function __construct(PluginTranslationService $translator)
    {
        $this->translator = $translator;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('plugin_trans', [$this, 'translate']),
        ];
    }

    public function translate(string $key, array $params = []): mixed
    {
        try {
            return $this->translator->get($key, $params);
        } catch (\Throwable $e) {
            return '[' . $key . ']';
        }
    }
}
