<?php

/**
 * @file plugins/generic/articleTimeline/ArticleTimelinePlugin.php
 *
 * Copyright (c) 2026 Abdul Hadi Alaidi
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
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
        $entries = $this->getTimelineEntries($article);
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
            $moved = false;
            $templateMgr->registerFilter('output', function (string $source) use ($html, &$moved): string {
                if ($moved) {
                    return $source;
                }
                $result = self::moveAboveAbstract($source, $html);
                $moved = $result !== null;
                return $result ?? $source;
            });
        }

        return false;
    }

    /**
     * Whether a date format shows a date: a non-empty string with at least one
     * unescaped day-of-month, month or year character (time-only formats like "H:i" fail).
     */
    public static function isValidDateFormat(mixed $format): bool
    {
        return is_string($format) && preg_match('/(?<!\\\\)[djFmMnoXxYycrU]/', $format) === 1;
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
     * individualizeTheme: "article-section-abstract"), in any attribute order and quote style.
     *
     * A regex on the opening tag, not a DOM parser: re-serialising the whole page would alter its markup.
     *
     * @return ?string the new page html, or null when no abstract block was found
     */
    public static function insertBeforeAbstract(string $source, string $html): ?string
    {
        $result = preg_replace_callback(
            '/<(?:section|div)(?=\s)[^>]*?\sclass\s*=\s*(["\'])[^"\'>]*?(?<![\w-])(?:[\w-]+-)?abstract(?![\w-])[^"\'>]*\1[^>]*>/i',
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
     * @return array<int, array{labelKey: string, date: string}> ordered entries
     */
    protected function getTimelineEntries(PKPSubmission $article): array
    {
        $authorIds = StageAssignment::withSubmissionIds([$article->getId()])
            ->withRoleIds([Role::ROLE_ID_AUTHOR])
            ->pluck('stage_assignments.user_id')
            ->all();

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

        return self::timelineEntries($article, $revisionFiles, $acceptDecisions);
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
     * Dates that are unavailable are omitted.
     *
     * @param iterable<SubmissionFile> $revisionFiles
     * @param iterable<Decision> $acceptDecisions
     *
     * @return array<int, array{labelKey: string, date: string}> ordered entries
     */
    public static function timelineEntries(PKPSubmission $article, iterable $revisionFiles, iterable $acceptDecisions): array
    {
        $dates = fn (iterable $objects, string $key) => array_filter(
            array_map(fn ($object) => $object->getData($key), is_array($objects) ? $objects : iterator_to_array($objects, false)),
            fn ($date) => is_string($date) && $date !== ''
        );

        $accepted = max($dates($acceptDecisions, 'dateDecided') ?: [null]);
        $revisions = $dates($revisionFiles, 'createdAt');
        $revised = max(($accepted ? array_filter($revisions, fn ($date) => $date <= $accepted) : $revisions) ?: [null]);
        $online = min($dates($article->getPublishedPublications(), 'datePublished') ?: [null]);

        $entries = [];
        foreach ([
            'received' => $article->getData('dateSubmitted'),
            'revised' => $revised,
            'accepted' => $accepted,
            'online' => $online,
        ] as $label => $date) {
            if ($date) {
                $entries[] = ['labelKey' => 'plugins.generic.articleTimeline.' . $label, 'date' => $date];
            }
        }
        return $entries;
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
}
