<?php

/**
 * @file plugins/generic/articleTimeline/tests/ArticleTimelinePluginTest.php
 *
 * Copyright (c) 2026 Abdul Hadi Alaidi
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ArticleTimelinePluginTest
 *
 * @brief Placement of the timeline above the abstract, the stored settings,
 *        the date format, and how the four dates are chosen.
 */

namespace APP\plugins\generic\articleTimeline\tests;

use APP\plugins\generic\articleTimeline\ArticleTimelinePlugin;
use APP\publication\Publication;
use APP\submission\Submission;
use Carbon\Carbon;
use Illuminate\Support\LazyCollection;
use PKP\decision\Decision;
use PKP\submissionFile\SubmissionFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PKP\tests\PKPTestCase;

#[CoversClass(ArticleTimelinePlugin::class)]
class ArticleTimelinePluginTest extends PKPTestCase
{
    private const TIMELINE = '<div class="item article-timeline article-timeline-main">T</div>';

    /** A plugin whose stored position (or date format) is $stored instead of the database's. */
    private function plugin(mixed $stored, string $setting = ArticleTimelinePlugin::SETTING_POSITION): ArticleTimelinePlugin
    {
        return new class ($stored, $setting) extends ArticleTimelinePlugin {
            public function __construct(private mixed $stored, private string $setting)
            {
                parent::__construct();
            }

            public function getSetting($contextId, $name)
            {
                return $name === $this->setting ? $this->stored : null;
            }

            public function position(): string
            {
                return $this->getPosition(1);
            }

            public function dateFormat(): string
            {
                return $this->getDateFormat(1);
            }
        };
    }

    /** @param array<int, array{0: int, 1: ?string}> $publications [status, datePublished] */
    private function article(?string $dateSubmitted, array $publications = []): Submission
    {
        $article = new Submission();
        $article->setData('dateSubmitted', $dateSubmitted);
        $article->setData('publications', LazyCollection::make(array_map(function (array $p) {
            $publication = new Publication();
            $publication->setData('status', $p[0]);
            $publication->setData('datePublished', $p[1]);
            return $publication;
        }, $publications)));
        return $article;
    }

    /** @return SubmissionFile[] */
    private function files(?string ...$createdAt): array
    {
        return array_map(function (?string $date) {
            $file = new SubmissionFile();
            $file->setData('createdAt', $date);
            return $file;
        }, $createdAt);
    }

    /** @return Decision[] */
    private function decisions(?string ...$dateDecided): array
    {
        return array_map(function (?string $date) {
            $decision = new Decision();
            $decision->setData('dateDecided', $date);
            return $decision;
        }, $dateDecided);
    }

    /** @return array<string, string> label => date */
    private function entries(Submission $article, array $files, array $decisions): array
    {
        $entries = [];
        foreach (ArticleTimelinePlugin::timelineEntries($article, $files, $decisions) as $entry) {
            $entries[substr($entry['labelKey'], strlen('plugins.generic.articleTimeline.'))] = $entry['date'];
        }
        return $entries;
    }

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

    public function testInsertsBeforeAbstractInOtherMarkup(): void
    {
        foreach ([
            "<section id='a' class='item abstract' data-x=\"1\">",
            '<section data-x="1" class="item abstract">',
            '<SECTION class = "abstract">',
            "<section\n\tclass=\"item abstract\">",
            '<div class="article-abstract">',
        ] as $tag) {
            $page = '<p>before</p>' . $tag . 'A';
            $this->assertSame('<p>before</p>' . self::TIMELINE . $tag . 'A', ArticleTimelinePlugin::insertBeforeAbstract($page, self::TIMELINE), $tag);
        }
    }

    public function testIgnoresElementsThatAreNotTheAbstract(): void
    {
        foreach ([
            '<section class="item abstracts">',
            '<section class="abstract-wrapper">',
            '<section data-class="abstract">',
            '<span class="abstract">',
            '<section class="item">abstract</section>',
            '<sectionfoo class="abstract">',
        ] as $tag) {
            $this->assertNull(ArticleTimelinePlugin::insertBeforeAbstract($tag . 'A', self::TIMELINE), $tag);
        }
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

    public function testMovesHookOutputAboveAbstract(): void
    {
        $page = '<section class="item abstract">A</section>' . self::TIMELINE . '<section class="item references">R</section>';

        $this->assertSame(
            self::TIMELINE . '<section class="item abstract">A</section><section class="item references">R</section>',
            ArticleTimelinePlugin::moveAboveAbstract($page, self::TIMELINE)
        );
    }

    public function testMoveLeavesPageAloneWithoutAbstractOrTimeline(): void
    {
        $this->assertNull(ArticleTimelinePlugin::moveAboveAbstract('<section class="item references">R</section>' . self::TIMELINE, self::TIMELINE));
        $this->assertNull(ArticleTimelinePlugin::moveAboveAbstract('<section class="item abstract">A</section>', self::TIMELINE));
    }

    public function testMovingIsIdempotent(): void
    {
        $page = '<section class="item abstract">A</section>' . self::TIMELINE;
        $once = ArticleTimelinePlugin::moveAboveAbstract($page, self::TIMELINE);

        $this->assertSame($once, ArticleTimelinePlugin::moveAboveAbstract($once, self::TIMELINE));
        $this->assertSame(1, substr_count($once, self::TIMELINE));
    }

    public function testStoredPositionsAreKept(): void
    {
        foreach (ArticleTimelinePlugin::POSITIONS as $position) {
            $this->assertSame($position, $this->plugin($position)->position());
        }
    }

    public function testUnknownOrMissingPositionFallsBackToTheDefault(): void
    {
        foreach ([null, '', 'top', 1] as $stored) {
            $this->assertSame(ArticleTimelinePlugin::DEFAULT_POSITION, $this->plugin($stored)->position());
        }
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

    public function testDateFormatValidation(): void
    {
        foreach (['M.j, Y', 'd/m/Y', 'F j, Y', 'Y', 'j F Y', 'Y-m-d H:i', 'l, \\t\\h\\e jS \\o\\f F'] as $format) {
            $this->assertTrue(ArticleTimelinePlugin::isValidDateFormat($format), $format);
        }
        foreach (['', '   ', 'H:i', 'D', 'l', '\\Y', '---', null, 12, ['Y']] as $format) {
            $this->assertFalse(ArticleTimelinePlugin::isValidDateFormat($format), var_export($format, true));
        }
    }

    public function testStoredDateFormatIsKeptOrFallsBackToTheDefault(): void
    {
        $this->assertSame('d/m/Y', $this->plugin('d/m/Y', ArticleTimelinePlugin::SETTING_DATE_FORMAT)->dateFormat());
        foreach ([null, '', 'H:i'] as $stored) {
            $this->assertSame(ArticleTimelinePlugin::DEFAULT_DATE_FORMAT, $this->plugin($stored, ArticleTimelinePlugin::SETTING_DATE_FORMAT)->dateFormat());
        }
    }

    public function testAllFourDatesInOrder(): void
    {
        $entries = ArticleTimelinePlugin::timelineEntries(
            $this->article('2024-12-05 10:00:00', [[Submission::STATUS_PUBLISHED, '2025-06-01']]),
            $this->files('2025-03-14 09:00:00'),
            $this->decisions('2025-05-11 12:00:00')
        );

        $this->assertSame([
            ['labelKey' => 'plugins.generic.articleTimeline.received', 'date' => '2024-12-05 10:00:00'],
            ['labelKey' => 'plugins.generic.articleTimeline.revised', 'date' => '2025-03-14 09:00:00'],
            ['labelKey' => 'plugins.generic.articleTimeline.accepted', 'date' => '2025-05-11 12:00:00'],
            ['labelKey' => 'plugins.generic.articleTimeline.online', 'date' => '2025-06-01'],
        ], $entries);
    }

    public function testMissingDatesAreOmitted(): void
    {
        $this->assertSame([], $this->entries($this->article(null), [], []));
        $this->assertSame(['received' => '2024-12-05 10:00:00'], $this->entries($this->article('2024-12-05 10:00:00', [[Submission::STATUS_QUEUED, null]]), $this->files(null, ''), $this->decisions(null, '')));
    }

    public function testLatestRevisionOfSeveralRounds(): void
    {
        $entries = $this->entries($this->article(null), $this->files('2025-01-10 08:00:00', '2025-03-14 09:00:00', null, '2025-02-01 00:00:00'), []);

        $this->assertSame(['revised' => '2025-03-14 09:00:00'], $entries);
    }

    public function testRevisionsUploadedAfterTheAcceptanceAreIgnored(): void
    {
        $this->assertSame(
            ['revised' => '2025-03-14 09:00:00', 'accepted' => '2025-05-11 12:00:00'],
            $this->entries($this->article(null), $this->files('2025-03-14 09:00:00', '2025-05-13 08:00:00'), $this->decisions('2025-05-11 12:00:00'))
        );
        $this->assertSame(
            ['accepted' => '2025-05-11 12:00:00'],
            $this->entries($this->article(null), $this->files('2025-05-13 08:00:00'), $this->decisions('2025-05-11 12:00:00'))
        );
    }

    public function testLastOfSeveralAcceptDecisions(): void
    {
        $entries = $this->entries($this->article(null), [], $this->decisions('2025-05-11 12:00:00', null, '2025-07-02 10:00:00', '2025-04-01 00:00:00'));

        $this->assertSame(['accepted' => '2025-07-02 10:00:00'], $entries);
    }

    public function testOnlineIsTheFirstPublishedVersionNotADraft(): void
    {
        // Core's firstPublication would take the draft: strtotime('') is false, and false < any timestamp.
        $article = $this->article(null, [
            [Submission::STATUS_PUBLISHED, '2025-09-01'],
            [Submission::STATUS_QUEUED, null],
            [Submission::STATUS_PUBLISHED, '2025-06-01'],
            [Submission::STATUS_SCHEDULED, '2025-01-01'],
        ]);

        $this->assertSame(['online' => '2025-06-01'], $this->entries($article, [], []));
        $this->assertSame([], $this->entries($this->article(null, [[Submission::STATUS_QUEUED, null]]), [], []));
    }
}
