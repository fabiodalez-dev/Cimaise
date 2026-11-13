<?php

declare(strict_types=1);

namespace Tests\Security;

use CustomTemplatesPro\Services\TemplateValidationService;
use PHPUnit\Framework\TestCase;

/**
 * Regression for audit finding C1: uploaded Twig templates must not be able to
 * pass a PHP callable (e.g. 'system') to a callable-taking filter/operator.
 */
final class TemplateTwigCallableTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2) . '/plugins/custom-templates-pro/Services/TemplateValidationService.php';
    }

    /** @return array<string, array{string}> */
    public static function unsafeTemplates(): array
    {
        return [
            'find string callable' => ["{{ ['id']|find('system') }}"],
            'sort string callable' => ["{{ ['id','x']|sort('system')|join }}"],
            'sort via apply tag' => ["{% apply sort('system') %}id{% endapply %}"],
            'has some string' => ["{% if ['id'] has some 'system' %}x{% endif %}"],
            'has every string' => ["{% if ['id'] has every 'system' %}x{% endif %}"],
            'find variable callable' => ["{{ ['id']|find(fn) }}"],
            'named arrow arg' => ["{{ ['id']|find(arrow='system') }}"],
            'map string callable' => ["{{ ['id']|map('system') }}"],
            'template_from_string' => ["{{ include(template_from_string('x')) }}"],
            'spaced filter call' => ["{{ ['id'] | find ( 'passthru' ) }}"],
            'unparseable' => ["{% bogus_tag %}"],
            'underscored named arrow' => ["{{ ['id']|find(a_r_r_o_w='system') }}"],
            'uppercase named arrow' => ["{{ ['id']|find(ARROW='system') }}"],
            'unknown named argument' => ["{{ ['id']|sort(whatever='system') }}"],
            'reduce callable in named slot' => ["{{ ['id']|reduce(initial=0, arrow='system') }}"],
            'embedded template body' => ["{% embed 'base.twig' %}{% block body %}{{ ['id']|find('system') }}{% endblock %}{% endembed %}"],
            'nested embed' => ["{% embed 'a.twig' %}{% block b %}{% embed 'c.twig' %}{% block d %}{{ ['id']|sort('system') }}{% endblock %}{% endembed %}{% endblock %}{% endembed %}"],
        ];
    }

    /** @dataProvider unsafeTemplates */
    public function testUnsafeConstructIsRejected(string $twig): void
    {
        $validator = new TemplateValidationService();
        self::assertFalse($validator->validateTwigSyntax($twig), $twig);
    }

    /** @return array<string, array{string}> */
    public static function safeTemplates(): array
    {
        return [
            'sort without callable' => ["{{ images|sort|length }}"],
            'sort with arrow' => ["{{ images|sort((a, b) => a.id <=> b.id)|length }}"],
            'find with arrow' => ["{{ images|find(i => i.id == 3).id ?? '' }}"],
            'has some arrow' => ["{% if images has some i => i.is_cover %}x{% endif %}"],
            'safe embed' => ["{% embed 'base.twig' %}{% block body %}{{ images|sort((a, b) => a.id <=> b.id)|length }}{% endblock %}{% endembed %}"],
            'app filters and functions' => ["{{ trans('x')|safe_html }}{{ hook('y')|raw }}{{ image|url_variant('lg') }}"],
        ];
    }

    /** @dataProvider safeTemplates */
    public function testSafeConstructIsAccepted(string $twig): void
    {
        $validator = new TemplateValidationService();
        self::assertTrue($validator->validateTwigSyntax($twig), implode('; ', $validator->getErrors()));
    }

    public function testEveryBundledTemplatePassesValidation(): void
    {
        $root = dirname(__DIR__, 2) . '/plugins/custom-templates-pro';
        $files = array_merge(
            glob($root . '/examples/*/*.twig') ?: [],
            glob($root . '/uploads/*/*/*.twig') ?: []
        );
        self::assertNotEmpty($files);
        foreach ($files as $file) {
            $validator = new TemplateValidationService();
            self::assertTrue(
                $validator->validateTwigSyntax((string) file_get_contents($file)),
                $file . ': ' . implode('; ', $validator->getErrors())
            );
        }
    }
}
