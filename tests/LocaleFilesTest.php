<?php

/**
 * @file plugins/generic/articleTimeline/tests/LocaleFilesTest.php
 *
 * Copyright (c) 2026 Abdul Hadi Alaidi
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class LocaleFilesTest
 *
 * @brief Translations. A key missing from a locale is rendered as ##key##, so an
 *        incomplete file is worse than none.
 */

namespace APP\plugins\generic\articleTimeline\tests;

use PKP\tests\PKPTestCase;

class LocaleFilesTest extends PKPTestCase
{
    /** Locale codes shipped by the plugin, as OJS 3.5 names them. */
    public const LOCALES = ['ar', 'en'];

    public const MASTER = 'en';

    protected function localeDir(): string
    {
        return dirname(__DIR__) . '/locale';
    }

    /** @return array<string, PoFile> */
    protected function files(): array
    {
        $files = [];
        foreach (self::LOCALES as $locale) {
            $path = $this->localeDir() . "/{$locale}/locale.po";
            if (is_file($path)) {
                $files[$locale] = new PoFile($path);
            }
        }
        return $files;
    }

    public function testShipsExactlyTheExpectedLocaleCodes(): void
    {
        $dirs = array_map('basename', glob($this->localeDir() . '/*', GLOB_ONLYDIR) ?: []);
        sort($dirs);

        // A code from another OJS line (fr vs fr_FR, pt vs pt_PT) silently never loads.
        $this->assertSame(self::LOCALES, $dirs);
        $this->assertCount(count(self::LOCALES), $this->files());
    }

    public function testEveryLocaleHasExactlyTheKeysOfTheMaster(): void
    {
        $files = $this->files();
        $master = array_keys($files[self::MASTER]->entries);
        $this->assertNotEmpty($master);

        foreach ($files as $locale => $file) {
            $this->assertSame($master, array_keys($file->entries), "Keys of {$locale} differ from " . self::MASTER . '.');
        }
    }

    public function testEveryKeyTheCodeUsesExists(): void
    {
        $sources = '';
        $root = dirname(__DIR__);
        foreach (array_merge(glob($root . '/*.php'), glob($root . '/templates/*.tpl')) as $file) {
            $sources .= file_get_contents($file);
        }
        preg_match_all('/plugins\.generic\.articleTimeline\.[a-zA-Z0-9_.]*[a-zA-Z0-9_]/', $sources, $m);
        $this->assertNotEmpty($m[0]);
        $keys = array_keys($this->files()[self::MASTER]->entries);

        foreach (array_unique($m[0]) as $key) {
            $this->assertContains($key, $keys, "{$key} is used but not translated.");
        }
    }

    public function testNoTranslationIsEmpty(): void
    {
        foreach ($this->files() as $locale => $file) {
            foreach ($file->entries as $key => $value) {
                $this->assertNotEmpty(trim($value), "Empty translation for {$key} in {$locale}.");
            }
        }
    }

    public function testHeaderDeclaresTheLocale(): void
    {
        foreach ($this->files() as $locale => $file) {
            $this->assertStringContainsString("Language: {$locale}\n", $file->header, "Wrong Language header in {$locale}.");
        }
    }

    public function testNoTranslationUsesAPlaceholder(): void
    {
        // No string takes parameters; a {$x} left in one would print literally.
        foreach ($this->files() as $locale => $file) {
            foreach ($file->entries as $key => $value) {
                $this->assertDoesNotMatchRegularExpression('/\{\$[a-zA-Z0-9_]+\}/', $value, "Placeholder in {$key} ({$locale}).");
            }
        }
    }
}
