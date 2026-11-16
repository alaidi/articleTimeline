{**
 * plugins/generic/articleTimeline/templates/timelineMain.tpl
 *
 * Copyright (c) 2026 Abdul Hadi Alaidi
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Horizontal editorial timeline in the main column, rendered via
 * Templates::Article::Main (under the abstract) and, for the "above"
 * position, moved before the abstract section when the page has one.
 *}

{* role=list on spans: list semantics without the theme's <ul>/<li> styling *}
<div class="item article-timeline article-timeline-main">
	<div class="article-timeline-entries" role="list" aria-label="{"plugins.generic.articleTimeline.timelineLabel"|translate|escape}">
		{foreach from=$articleTimelineEntries item=entry}
			<span class="article-timeline-entry" role="listitem">
				<span class="article-timeline-label">{translate key=$entry.labelKey}</span>
				<time class="article-timeline-date" datetime="{$entry.date|date_format:'Y-m-d'|escape}">{$entry.date|date_format:$articleTimelineFormat|escape}</time>
			</span>
		{/foreach}
	</div>
</div>
