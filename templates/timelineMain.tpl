{**
 * plugins/generic/articleTimeline/templates/timelineMain.tpl
 *
 * Copyright (c) 2026 Abdul Hadi Alaidi
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * Horizontal editorial timeline in the main column, rendered via
 * Templates::Article::Main (under the abstract) and, for the "above"
 * position, moved before the abstract section when the page has one.
 *}

<div class="item article-timeline article-timeline-main">
	<div class="article-timeline-entries">
		{foreach from=$articleTimelineEntries item=entry}
			<span class="article-timeline-entry">
				<span class="article-timeline-label">{translate key=$entry.labelKey}</span>
				<span class="article-timeline-date">{$entry.date|date_format:$articleTimelineFormat}</span>
			</span>
		{/foreach}
	</div>
</div>
