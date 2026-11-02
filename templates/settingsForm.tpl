{**
 * plugins/generic/articleTimeline/templates/settingsForm.tpl
 *
 * Copyright (c) 2026 Abdul Hadi Alaidi
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *}

<script type="text/javascript">
	$(function() {ldelim}
		// Attach the form handler.
		$('#articleTimeline-settings-form').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form
	class="pkp_form"
	id="articleTimeline-settings-form"
	method="post" action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="save"}"
>
	{csrf}

	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="articleTimelineSettingsFormNotification"}

	{fbvFormArea id="articleTimeline-settings-form-area"}
		{fbvFormSection
			for="position"
			id="articleTimeline-position-section"
			label="plugins.generic.articleTimeline.settings.position"
			description="plugins.generic.articleTimeline.settings.position.desc"
		}
			{fbvElement
				type="select"
				id="position"
				name=$settingPosition
				from=$positions
				selected=$position|default:$defaultPosition
				translate=false
				size=$fbvStyles.size.SMALL
			}
		{/fbvFormSection}
		{fbvFormSection
			for="dateFormat"
			id="articleTimeline-dateFormat-section"
			label="plugins.generic.articleTimeline.settings.dateFormat"
			description="plugins.generic.articleTimeline.settings.dateFormat.desc"
		}
			{fbvElement
				type="text"
				id="dateFormat"
				name=$settingDateFormat
				value=$dateFormat|default:$defaultDateFormat
				size=$fbvStyles.size.MEDIUM
			}
		{/fbvFormSection}
	{/fbvFormArea}
	{fbvFormButtons submitText="common.save" hideCancel=true}
</form>
