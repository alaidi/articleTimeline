<?php

/**
 * @file plugins/generic/articleTimeline/ArticleTimelineSettingsForm.php
 *
 * Copyright (c) 2026 Abdul Hadi Alaidi
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ArticleTimelineSettingsForm
 * @ingroup plugins_generic_articleTimeline
 *
 * @brief Settings form for the articleTimeline plugin: display position
 *   and date format.
 */

namespace APP\plugins\generic\articleTimeline;

use APP\core\Application;
use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorPost;

class ArticleTimelineSettingsForm extends Form
{
    public ArticleTimelinePlugin $plugin;

    public function __construct(ArticleTimelinePlugin $plugin)
    {
        $this->plugin = $plugin;

        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
        $this->addCheck(new FormValidatorCustom(
            $this,
            $this->plugin::SETTING_POSITION,
            'required',
            'validator.required',
            fn ($position) => in_array($position, $this->plugin::POSITIONS, true)
        ));
        $this->addCheck(new FormValidatorCustom(
            $this,
            $this->plugin::SETTING_DATE_FORMAT,
            'required',
            'validator.required',
            fn ($format) => is_string($format) && $format !== ''
        ));
    }

    public function initData()
    {
        $context = Application::get()->getRequest()->getContext();
        if (!$context) {
            return;
        }

        $position = $this->plugin->getSetting($context->getId(), $this->plugin::SETTING_POSITION);
        $dateFormat = $this->plugin->getSetting($context->getId(), $this->plugin::SETTING_DATE_FORMAT);

        $this->setData($this->plugin::SETTING_POSITION, $position ?: $this->plugin::DEFAULT_POSITION);
        $this->setData($this->plugin::SETTING_DATE_FORMAT, $dateFormat ?: $this->plugin::DEFAULT_DATE_FORMAT);
    }

    public function readInputData()
    {
        $this->readUserVars([
            $this->plugin::SETTING_POSITION,
            $this->plugin::SETTING_DATE_FORMAT,
        ]);
    }

    public function fetch($request, $template = null, $display = false)
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'positions' => [
                $this->plugin::POSITION_MAIN => __('plugins.generic.articleTimeline.settings.position.main'),
                $this->plugin::POSITION_MAIN_BELOW => __('plugins.generic.articleTimeline.settings.position.mainBelow'),
                $this->plugin::POSITION_SIDEBAR => __('plugins.generic.articleTimeline.settings.position.sidebar'),
            ],
            'pluginName' => $this->plugin->getName(),
            'settingPosition' => $this->plugin::SETTING_POSITION,
            'settingDateFormat' => $this->plugin::SETTING_DATE_FORMAT,
            'defaultPosition' => $this->plugin::DEFAULT_POSITION,
            'defaultDateFormat' => $this->plugin::DEFAULT_DATE_FORMAT,
        ]);
        return parent::fetch($request, $template, $display);
    }

    public function execute(...$functionArgs)
    {
        $context = Application::get()->getRequest()->getContext();
        if (!$context) {
            return;
        }

        $this->plugin->updateSetting(
            $context->getId(),
            $this->plugin::SETTING_POSITION,
            $this->getData($this->plugin::SETTING_POSITION)
        );
        $this->plugin->updateSetting(
            $context->getId(),
            $this->plugin::SETTING_DATE_FORMAT,
            $this->getData($this->plugin::SETTING_DATE_FORMAT)
        );

        parent::execute(...$functionArgs);
    }
}
