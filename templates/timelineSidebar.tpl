{**
 * plugins/generic/articleTimeline/templates/timelineSidebar.tpl
 *
 * Copyright (c) 2026 Abdul Hadi Alaidi
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Editorial timeline as a sidebar item, injected via
 * Templates::Article::Details (follows the .item/.label/.value pattern
 * documented in templates/frontend/objects/article_details.tpl).
 *}

<div class="item article-timeline article-timeline-sidebar">
	<section class="sub_item">
		<h2 class="label">
			{translate key="plugins.generic.articleTimeline.timelineLabel"}
		</h2>
		<div class="value">
			<ul class="article-timeline-list">
				{foreach from=$articleTimelineEntries item=entry}
					<li class="article-timeline-entry">
						<span class="article-timeline-label">{translate key=$entry.labelKey}</span>
						<time class="article-timeline-date" datetime="{$entry.date|date_format:'Y-m-d'|escape}">{$entry.date|date_format:$articleTimelineFormat|escape}</time>
					</li>
				{/foreach}
			</ul>
		</div>
	</section>
</div>
