{**
 * plugins/generic/articleTimeline/templates/timelineMain.tpl
 *
 * Copyright (c) 2026 Abdul Hadi Alaidi
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Horizontal editorial timeline, injected via Templates::Article::Main
 * (after the abstract in the main column).
 *}

<div class="article-timeline article-timeline-main">
	{foreach from=$articleTimelineEntries item=entry}
		<span class="article-timeline-entry">
			<span class="article-timeline-label">{translate key=$entry.labelKey}</span>
			<span class="article-timeline-date">{$entry.date|date_format:$articleTimelineFormat}</span>
		</span>
	{/foreach}
</div>
