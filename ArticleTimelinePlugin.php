<?php

/**
 * @file plugins/generic/articleTimeline/ArticleTimelinePlugin.php
 *
 * Copyright (c) 2026 Abdul Hadi Alaidi
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class ArticleTimelinePlugin
 * @ingroup plugins_generic_articleTimeline
 *
 * @brief Show the editorial timeline (Received / Revised / Accepted / Online)
 *   on the reader-facing article page.
 */

namespace APP\plugins\generic\articleTimeline;

use APP\core\Application;
use APP\facades\Repo;
use APP\template\TemplateManager;
use PKP\core\JSONMessage;
use PKP\decision\Decision;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\security\Role;
use PKP\stageAssignment\StageAssignment;
use PKP\submission\PKPSubmission;
use PKP\submissionFile\SubmissionFile;

class ArticleTimelinePlugin extends GenericPlugin
{
    public const SETTING_POSITION = 'position';
    public const SETTING_DATE_FORMAT = 'dateFormat';
    public const SETTING_HIDDEN_DATES = 'hiddenDates';

    /** The timeline dates, in display order (also the locale key suffixes) */
    public const DATES = ['received', 'revised', 'accepted', 'online'];

    // 'main' (above the abstract) keeps its stored value from 1.0.x so saved settings don't change
    public const POSITION_MAIN = 'main';
    public const POSITION_MAIN_BELOW = 'mainBelow';
    public const POSITION_SIDEBAR = 'sidebar';
    public const POSITIONS = [self::POSITION_MAIN, self::POSITION_MAIN_BELOW, self::POSITION_SIDEBAR];

    public const DEFAULT_POSITION = self::POSITION_MAIN;
    public const DEFAULT_DATE_FORMAT = 'M.j, Y';

    /** The timeline is shown once per page, whatever hooks a theme calls. */
    private bool $rendered = false;

    /**
     * @copydoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        if (!parent::register($category, $path, $mainContextId)) {
            return false;
        }

        if ($this->getEnabled($mainContextId)) {
            Hook::add('Templates::Article::Main', [$this, 'addToArticle']);
            Hook::add('Templates::Article::Details', [$this, 'addToArticle']);

            // Only emitted on article pages (context frontend-article)
            $request = Application::get()->getRequest();
            $templateMgr = TemplateManager::getManager($request);
            $templateMgr->addStyleSheet(
                'articleTimeline',
                rtrim($request->getBaseUrl(), '/') . '/' . rtrim($this->getPluginPath(), '/') . '/styles/timeline.css',
                [
                    'contexts' => ['frontend-article'],
                ]
            );
        }

        return true;
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.generic.articleTimeline.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.generic.articleTimeline.description');
    }

    /**
     * Handle requests for the settings form
     */
    public function manage($args, $request)
    {
        switch ($request->getUserVar('verb')) {
            case 'settings':
                $form = new ArticleTimelineSettingsForm($this);
                $form->initData();
                return new JSONMessage(true, $form->fetch($request));
            case 'save':
                $form = new ArticleTimelineSettingsForm($this);
                $form->readInputData();
                if ($form->validate()) {
                    $form->execute();
                    return new JSONMessage(true, __('plugins.generic.articleTimeline.settings.saved'));
                }
                // the form again, with its errors; nothing is saved
                return new JSONMessage(true, $form->fetch($request));
        }
        return parent::manage($args, $request);
    }

    /**
     * Add a settings action to the plugin's entry in the plugin list
     *
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $verb): array
    {
        $actions = parent::getActions($request, $verb);
        if (!$this->getEnabled()) {
            return $actions;
        }

        $router = $request->getRouter();
        $actions[] = new LinkAction(
            'settings',
            new AjaxModal(
                $router->url($request, null, null, 'manage', null, [
                    'verb' => 'settings',
                    'plugin' => $this->getName(),
                    'category' => 'generic',
                ]),
                $this->getDisplayName()
            ),
            __('manager.plugins.settings')
        );

        return $actions;
    }

    /**
     * Inject the timeline into the article page.
     *
     * Registered against both Templates::Article::Main and
     * Templates::Article::Details; renders only on the hook matching
     * the configured position setting, and at most once per request.
     */
    public function addToArticle(string $hookName, array $args): bool
    {
        $output =& $args[2];

        if ($this->rendered) {
            return false;
        }

        $request = Application::get()->getRequest();
        $templateMgr = TemplateManager::getManager($request);
        $article = $templateMgr->getTemplateVars('article');
        $context = $request->getContext();

        if (!$article instanceof PKPSubmission || !$context) {
            return false;
        }

        $position = $this->getPosition($context->getId());
        if (($position === self::POSITION_SIDEBAR) !== ($hookName === 'Templates::Article::Details')) {
            return false;
        }

        $this->rendered = true;
        $entries = $this->getTimelineEntries($article, $this->getHiddenDates($context->getId()));
        if ($entries === []) {
            return false;
        }

        $templateMgr->assign([
            'articleTimelineEntries' => $entries,
            'articleTimelineFormat' => $this->getDateFormat($context->getId()),
        ]);

        $html = $templateMgr->fetch($this->getTemplateResource(
            $position === self::POSITION_SIDEBAR ? 'timelineSidebar.tpl' : 'timelineMain.tpl'
        ));

        // Templates::Article::Main sits right under the abstract
        $output .= $html;

        // No theme hook sits above the abstract, so move the timeline there in the final page output.
        // Pages without an abstract section keep it under, where the hook put it.
        if ($position === self::POSITION_MAIN) {
            $templateMgr->registerFilter('output', self::aboveAbstractFilter($html));
        }

        return false;
    }

    /**
     * An output filter that moves $html above the abstract in the first output holding both,
     * and leaves every other output (other templates, later fetches) as it is.
     *
     * @return callable(string): string
     */
    public static function aboveAbstractFilter(string $html): callable
    {
        $moved = false;
        return function (string $source) use ($html, &$moved): string {
            if ($moved) {
                return $source;
            }
            $result = self::moveAboveAbstract($source, $html);
            $moved = $result !== null;
            return $result ?? $source;
        };
    }

    /** Letters PHP's date() reads as format characters (X and x since PHP 8.2) */
    public const DATE_FORMAT_CHARACTERS = 'dDjlNSwzWFmMntLoXxYyaABgGhHisuveIOPpTZcrU';

    /** Format characters that show a day of the month, a month or a year */
    public const CALENDAR_DATE_CHARACTERS = 'djFmMnoXxYycr';

    /**
     * Whether a date format shows a calendar date. Letters other than format characters must be
     * escaped with a backslash, as PHP would otherwise print them as-is; a format needs at least
     * one day, month or year character ("H:i" fails), and the Unix timestamp "U" is refused.
     */
    public static function isValidDateFormat(mixed $format): bool
    {
        // escaped characters (a backslash and the next character) are printed as they are
        $unescaped = is_string($format) ? preg_replace('/\\\\./su', '', $format) : null;
        if ($unescaped === null || str_ends_with($unescaped, '\\')) {
            return false;
        }
        preg_match_all('/[a-z]/i', $unescaped, $letters);
        $letters = $letters[0];
        return array_diff($letters, str_split(self::DATE_FORMAT_CHARACTERS)) === []
            && array_intersect($letters, str_split(self::CALENDAR_DATE_CHARACTERS)) !== []
            && !in_array('U', $letters, true);
    }

    /**
     * Move the first copy of $html in $source to right before the first abstract section.
     *
     * @return ?string the new page html, or null when $source lacks $html or an abstract section
     */
    public static function moveAboveAbstract(string $source, string $html): ?string
    {
        $at = strpos($source, $html);
        if ($at === false) {
            return null;
        }
        return self::insertBeforeAbstract(substr_replace($source, '', $at, strlen($html)), $html);
    }

    /**
     * Insert $html right before the opening tag of the first abstract block of $source:
     * a <section> or <div> with a class "abstract" or "…-abstract" (default theme: "item abstract",
     * individualizeTheme: "article-section-abstract"), in any attribute order, with the class
     * value in double, single or no quotes, and other attribute values that may hold ">".
     *
     * A regex on the opening tag, not a DOM parser: re-serialising the whole page would alter its markup.
     *
     * @return ?string the new page html, or null when no abstract block was found (or the regex failed)
     */
    public static function insertBeforeAbstract(string $source, string $html): ?string
    {
        $attributes = '(?:[^>"\']|"[^"]*"|\'[^\']*\')*?'; // a quoted value is skipped whole, ">" included
        $abstract = '(?:[\w-]+-)?abstract';
        $result = preg_replace_callback(
            '/<(?:section|div)(?=\s)' . $attributes . '\sclass\s*=\s*'
                . '(?:(["\'])(?:(?!\1).)*?(?<![\w-])' . $abstract . '(?![\w-])(?:(?!\1).)*\1|' . $abstract . '(?=[\s>]))'
                . $attributes . '>/is',
            fn (array $match) => $html . $match[0],
            $source,
            1,
            $count
        );
        return $count > 0 ? $result : null;
    }

    /**
     * Load what the timeline needs for a submission: its author-uploaded revision files
     * and its acceptance decisions (two to three queries, for the article being viewed only).
     *
     * @param string[] $hidden dates (values of DATES) not to show
     *
     * @return array<int, array{labelKey: string, date: string}> ordered entries
     */
    protected function getTimelineEntries(PKPSubmission $article, array $hidden = []): array
    {
        $authorIds = self::authorUserIds($article->getId());

        $revisionFiles = $authorIds === [] ? [] : Repo::submissionFile()->getCollector()
            ->filterBySubmissionIds([$article->getId()])
            ->filterByFileStages([
                SubmissionFile::SUBMISSION_FILE_REVIEW_REVISION,
                SubmissionFile::SUBMISSION_FILE_INTERNAL_REVIEW_REVISION, // OMP's internal review; never used by OJS
            ])
            ->filterByUploaderUserIds($authorIds)
            ->getMany();

        $acceptDecisions = Repo::decision()->getCollector()
            ->filterBySubmissionIds([$article->getId()])
            ->filterByDecisionTypes([Decision::ACCEPT, Decision::SKIP_EXTERNAL_REVIEW])
            ->getMany();

        return self::timelineEntries($article, $revisionFiles, $acceptDecisions, $hidden);
    }

    /**
     * The users taking part in a submission as authors: every participant in a user group with
     * the Author role. OJS 3.5 assigns a participant to the whole submission (stage_assignments
     * has no stage), so this does not depend on the stages the Author group can see.
     * Contributors without a user account, and authors removed from the participants, are
     * not included.
     *
     * @return int[]
     */
    public static function authorUserIds(int $submissionId): array
    {
        return StageAssignment::withSubmissionIds([$submissionId])
            ->withRoleIds([Role::ROLE_ID_AUTHOR])
            ->pluck('stage_assignments.user_id')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Derive the timeline dates (no database access).
     *
     * - Received: the submission's dateSubmitted
     * - Accepted: dateDecided of the last Accept or "Accept and Skip Review" decision
     * - Revised: latest upload of a review-revision file by an author, not after the acceptance
     *   (the caller passes only author-uploaded files)
     * - Online: earliest datePublished of the published versions (not core's firstPublication,
     *   which takes an unpublished draft version as the earliest)
     *
     * Dates that are unavailable are omitted, and so are the $hidden ones: hiding only affects
     * the display, so a hidden Accepted date still keeps Revised from coming after it.
     *
     * @param iterable<SubmissionFile> $revisionFiles
     * @param iterable<Decision> $acceptDecisions
     * @param string[] $hidden dates (values of DATES) not to show
     *
     * @return array<int, array{labelKey: string, date: string}> ordered entries
     */
    public static function timelineEntries(PKPSubmission $article, iterable $revisionFiles, iterable $acceptDecisions, array $hidden = []): array
    {
        $dates = fn (iterable $objects, string $key) => array_filter(
            array_map(fn ($object) => $object->getData($key), is_array($objects) ? $objects : iterator_to_array($objects, false)),
            fn ($date) => is_string($date) && $date !== ''
        );

        $accepted = max($dates($acceptDecisions, 'dateDecided') ?: [null]);
        $online = min($dates($article->getPublishedPublications(), 'datePublished') ?: [null]);
        $revised = self::latestRevision($dates($revisionFiles, 'createdAt'), $accepted ?? $online);

        $entries = [];
        foreach ([
            'received' => $article->getData('dateSubmitted'),
            'revised' => $revised,
            'accepted' => $accepted,
            'online' => $online,
        ] as $label => $date) {
            if ($date && !in_array($label, $hidden, true)) {
                $entries[] = ['labelKey' => 'plugins.generic.articleTimeline.' . $label, 'date' => $date];
            }
        }
        return $entries;
    }

    /**
     * The latest of the revision dates that is not after $limit (all of them without a limit).
     *
     * Dates are compared as "Y-m-d H:i:s" strings, a revision cut to the limit's length: against
     * a day without a time ("Y-m-d", as datePublished is stored), a revision of that day counts.
     *
     * @param string[] $revisions
     */
    public static function latestRevision(array $revisions, ?string $limit): ?string
    {
        if ($limit !== null) {
            $revisions = array_filter($revisions, fn (string $date) => substr($date, 0, strlen($limit)) <= $limit);
        }
        return $revisions === [] ? null : max($revisions);
    }

    /**
     * Get the configured display position, falling back to the default
     */
    protected function getPosition(int $contextId): string
    {
        $position = $this->getSetting($contextId, self::SETTING_POSITION);
        return is_string($position) && in_array($position, self::POSITIONS, true)
            ? $position
            : self::DEFAULT_POSITION;
    }

    /**
     * Get the configured date format, falling back to the default when it is missing or shows no date
     */
    protected function getDateFormat(int $contextId): string
    {
        $format = $this->getSetting($contextId, self::SETTING_DATE_FORMAT);
        return self::isValidDateFormat($format) ? $format : self::DEFAULT_DATE_FORMAT;
    }

    /**
     * Get the dates the journal hides; unknown stored values are ignored
     *
     * @return string[] values of DATES
     */
    protected function getHiddenDates(int $contextId): array
    {
        $hidden = $this->getSetting($contextId, self::SETTING_HIDDEN_DATES);
        return is_array($hidden) ? array_values(array_filter(self::DATES, fn ($date) => in_array($date, $hidden, true))) : [];
    }
}
