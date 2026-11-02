<?php

/**
 * @file plugins/generic/articleTimeline/tests/ArticleTimelinePluginTest.php
 *
 * Copyright (c) 2026 Abdul Hadi Alaidi
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief Placement of the timeline above the abstract, and the default date format.
 */

use APP\plugins\generic\articleTimeline\ArticleTimelinePlugin;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

final class ArticleTimelinePluginTest extends TestCase
{
    private const TIMELINE = '<div class="item article-timeline article-timeline-main">T</div>';

    public function testInsertsBeforeDefaultThemeAbstract(): void
    {
        $page = '<section class="item keywords">K</section><section class="item abstract">A</section>';

        $this->assertSame(
            '<section class="item keywords">K</section>' . self::TIMELINE . '<section class="item abstract">A</section>',
            ArticleTimelinePlugin::insertBeforeAbstract($page, self::TIMELINE)
        );
    }

    public function testInsertsBeforeIndividualizeThemeAbstract(): void
    {
        $page = '<div class="article-sections"><section class="article-section article-section-abstract">A</section></div>';

        $this->assertSame(
            '<div class="article-sections">' . self::TIMELINE . '<section class="article-section article-section-abstract">A</section></div>',
            ArticleTimelinePlugin::insertBeforeAbstract($page, self::TIMELINE)
        );
    }

    public function testReturnsNullWithoutAbstract(): void
    {
        $this->assertNull(ArticleTimelinePlugin::insertBeforeAbstract('<section class="item references">R</section>', self::TIMELINE));
    }

    public function testInsertsOnlyOnce(): void
    {
        $page = '<section class="item abstract">A</section><section class="item abstract">B</section>';

        $this->assertSame(1, substr_count(ArticleTimelinePlugin::insertBeforeAbstract($page, self::TIMELINE), self::TIMELINE));
    }

    public function testKeepsDollarSequencesInTimelineLiteral(): void
    {
        $html = '<div>$0 $1 \\1</div>';

        $this->assertSame(
            $html . '<section class="item abstract">A</section>',
            ArticleTimelinePlugin::insertBeforeAbstract('<section class="item abstract">A</section>', $html)
        );
    }

    /**
     * Same call the template's date_format modifier makes (PKPTemplateManager::smartyDateFormat).
     */
    public function testDefaultDateFormatMatchesRequestedStyle(): void
    {
        $format = fn (string $date) => (new Carbon($date))->locale('en')->translatedFormat(ArticleTimelinePlugin::DEFAULT_DATE_FORMAT);

        $this->assertSame('Dec.5, 2024', $format('2024-12-05 10:00:00'));
        $this->assertSame('Mar.14, 2025', $format('2025-03-14'));
        $this->assertSame('May.11, 2025', $format('2025-05-11'));
        $this->assertSame('Jun.1, 2025', $format('2025-06-01'));
    }
}
