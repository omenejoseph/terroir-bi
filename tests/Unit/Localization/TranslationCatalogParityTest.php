<?php

declare(strict_types=1);

namespace Tests\Unit\Localization;

use PHPUnit\Framework\TestCase;

/**
 * lang/en.json and lang/hr.json are keyed by the English source string; a key
 * in one and not the other silently shows English to Croatian readers (or a
 * stale string to English ones), so the two must always carry the same keys.
 */
class TranslationCatalogParityTest extends TestCase
{
    public function test_en_and_hr_have_the_same_keys(): void
    {
        $en = $this->catalog('en');
        $hr = $this->catalog('hr');

        $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($hr))), 'keys missing from hr.json');
        $this->assertSame([], array_values(array_diff(array_keys($hr), array_keys($en))), 'keys missing from en.json');
    }

    /**
     * @return array<string, string>
     */
    private function catalog(string $locale): array
    {
        $json = file_get_contents(dirname(__DIR__, 3)."/lang/{$locale}.json");
        $this->assertIsString($json);
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);

        /** @var array<string, string> $data */
        return $data;
    }
}
