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
                return new JSONMessage(false);
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
     * the configured position setting.
     */
    public function addToArticle(string $hookName, array $args): bool
    {
        $output =& $args[2];

        $request = Application::get()->getRequest();
        $templateMgr = TemplateManager::getManager($request);
        $article = $templateMgr->getTemplateVars('article');

        if (!$article instanceof PKPSubmission) {
            return false;
        }

        $context = $request->getContext();
        $position = $this->getPosition($context->getId());
        if (($position === self::POSITION_SIDEBAR) !== ($hookName === 'Templates::Article::Details')) {
            return false;
        }

        $entries = $this->getTimelineEntries($templateMgr, $article);
        if ($entries === []) {
            return false;
        }

        $dateFormat = $this->getSetting($context->getId(), self::SETTING_DATE_FORMAT);
        $templateMgr->assign([
            'articleTimelineEntries' => $entries,
            'articleTimelineFormat' => is_string($dateFormat) && $dateFormat !== ''
                ? $dateFormat
                : self::DEFAULT_DATE_FORMAT,
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
     * Insert $html right before the first abstract section of $source.
     *
     * shortcut: matches the first <section> whose class contains "abstract" (default + individualizeTheme); a theme with other markup needs its own pattern
     *
     * @return ?string the new page html, or null when no abstract section was found
     */
    public static function insertBeforeAbstract(string $source, string $html): ?string
    {
        $result = preg_replace_callback(
            '/<section class="[^"]*\babstract\b[^"]*">/',
            fn (array $match) => $html . $match[0],
            $source,
            1,
            $count
        );
        return $count > 0 ? $result : null;
    }

    /**
     * Derive the timeline dates for a submission.
     *
     * - Received: submissions.date_submitted
     * - Revised: latest upload of a review-revision file (stages 15/20)
     * - Accepted: date_decided of the last Accept decision
     * - Online: date_published of the first publication
     *
     * Dates that are unavailable are omitted.
     *
     * @return array<int, array{labelKey: string, date: string}> ordered entries
     */
    protected function getTimelineEntries(TemplateManager $templateMgr, PKPSubmission $article): array
    {
        $entries = [];

        $received = $article->getData('dateSubmitted');
        if ($received) {
            $entries[] = ['labelKey' => 'plugins.generic.articleTimeline.received', 'date' => $received];
        }

        $revised = null;
        $revisionFiles = Repo::submissionFile()->getCollector()
            ->filterBySubmissionIds([$article->getId()])
            ->filterByFileStages([
                SubmissionFile::SUBMISSION_FILE_REVIEW_REVISION,
                SubmissionFile::SUBMISSION_FILE_INTERNAL_REVIEW_REVISION,
            ])
            ->getMany();
        foreach ($revisionFiles as $revisionFile) {
            $createdAt = $revisionFile->getData('createdAt');
            if ($createdAt && (!$revised || $createdAt > $revised)) {
                $revised = $createdAt;
            }
        }
        if ($revised) {
            $entries[] = ['labelKey' => 'plugins.generic.articleTimeline.revised', 'date' => $revised];
        }

        $accepted = null;
        $decisions = Repo::decision()->getCollector()
            ->filterBySubmissionIds([$article->getId()])
            ->filterByDecisionTypes([Decision::ACCEPT])
            ->getMany();
        foreach ($decisions as $decision) {
            $dateDecided = $decision->getData('dateDecided');
            if ($dateDecided && (!$accepted || $dateDecided > $accepted)) {
                $accepted = $dateDecided;
            }
        }
        if ($accepted) {
            $entries[] = ['labelKey' => 'plugins.generic.articleTimeline.accepted', 'date' => $accepted];
        }

        $firstPublication = $templateMgr->getTemplateVars('firstPublication');
        $online = $firstPublication ? $firstPublication->getData('datePublished') : null;
        if ($online) {
            $entries[] = ['labelKey' => 'plugins.generic.articleTimeline.online', 'date' => $online];
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
}
