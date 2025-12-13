<?php
declare(strict_types=1);

namespace App\Extensions;

use App\Services\TranslationService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class TranslationTwigExtension extends AbstractExtension
{
    public function __construct(private TranslationService $translationService) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('trans', [$this, 'translate']),
            new TwigFunction('__', [$this, 'translate']), // Alias
        ];
    }

    /**
     * Translate a text key
     * Usage: {{ trans('nav.home') }} or {{ trans('filter.results_count', {count: 10}) }}
     */
    public function translate(string $key, array $params = [], ?string $default = null): string
    {
        return $this->translationService->get($key, $params, $default);
    }
}
