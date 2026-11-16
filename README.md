# Article Timeline

An OJS 3.5 plugin that shows the editorial history of an article on its landing
page — when it was received, revised, accepted and published online:

> **Received** Dec.5, 2024 · **Revised** Mar.14, 2025 · **Accepted** May.11, 2025 · **Online** Jun.1, 2025

![The timeline above the abstract on an article page](https://raw.githubusercontent.com/alaidi/articleTimeline/main/docs/screenshot.png)

*The default position, above the abstract (OJS article page, sample content).*

## How dates are derived

All four come from the OJS API (submission, submission files, editorial decisions,
publications); the plugin runs no SQL of its own.

| Label | Source |
|---|---|
| Received | The submission's date submitted |
| Revised | The latest review-revision file **uploaded by one of the submission's authors**, not later than the acceptance (or, without an acceptance, than the day it went online) |
| Accepted | The date of the last **Accept** or **Accept and Skip Review** decision |
| Online | The earliest publication date among the article's **published** versions |

Dates that are not available for an article are left out; when none is
available, nothing is shown. Dates are shown as stored, in the server's time
zone, without conversion.

### Limitations of the Revised date

OJS does not record when an author "resubmitted"; the plugin takes the upload
time of the files in the review stage's *Revisions* area:

- An author is a user who takes part in the submission in a user group with the
  Author role (any such group, whatever stages it is allowed to see: OJS 3.5
  assigns participants to the whole submission). Contributors listed in the
  metadata without a user account cannot upload, and an author who was **removed
  from the participants** no longer counts, so their uploads are ignored too.
- A file an editor or administrator uploads there for the author (for example a
  revision that arrived by email) is **not** counted, because OJS cannot tell it
  apart from an editor's own file. Such an article shows no Revised date unless
  the author also uploaded one. To count such files, tick **Also count revision
  files uploaded by editors** in the settings (see below).
- A file uploaded after the acceptance is ignored, so Revised never follows Accepted.
  Without an acceptance (for example an article imported as published), a file
  uploaded after the day of the first publication is ignored; one uploaded on that
  day counts, as the publication date has no time.
- When an author replaces a revision file with a new version of the same file,
  the date stays that of the first upload.
- With several review rounds, the latest author revision is used.

## Settings

Per journal, under Settings → Website → Plugins → Article Timeline → Settings.

**Display position**

| Option | Where it appears |
|---|---|
| Main column (above the abstract) | Between the keywords and the abstract. **Default.** |
| Main column (under the abstract) | Right after the abstract |
| Sidebar (details column) | As a block in the article's details column |

"Above the abstract" looks for the first `<section>` or `<div>` whose class is
`abstract` or ends in `-abstract` (default theme and Journal Pulse: `item abstract`,
individualizeTheme: `article-section-abstract`), whatever the attribute order,
with the class in double, single or no quotes. The rest of the page is left
byte for byte as the theme wrote it. On a page without such a block (an article
without an abstract, or a theme that marks it up differently), the timeline stays
under the abstract instead.

**Date format** — a PHP date format string that shows a day, month or year.
Default `M.j, Y` → "Dec.5, 2024"; `d/m/Y` → "05/12/2024", `F j, Y` → "December 5,
2024". Month names follow the reader's language. Refused: formats without a day,
month or year (`H:i`, `l`), the Unix timestamp `U`, and letters that are not
[format characters](https://www.php.net/manual/en/datetime.format.php) unless
escaped with a backslash (`\o\f`), since PHP would print them as they are. A word
made only of format characters (such as "Today") is still a valid format, and
shows a date rather than the word.

**Hide dates** — tick Received, Revised, Accepted or Online to leave that date off
the article page (none by default). Hiding only affects the display: a hidden
Accepted date still keeps Revised from coming after it. With every date hidden,
no timeline is shown.

**Revised date: also count revision files uploaded by editors** — off by default.
When editors upload revisions for authors, tick it: every file in the review
stage's *Revisions* area then counts, whoever uploaded it (an editor's own file
there counts too). The other rules (not after the acceptance, latest round) stay.

Interface strings are available in English and Arabic; the timeline follows the
page direction (RTL in Arabic). Each date is a `<time datetime="YYYY-MM-DD">`
element, and the main-column timeline is announced as a list named "Article History".
Its CSS classes start with `article-timeline` for styling from a theme or the
journal stylesheet.

## Installation

1. Download `articleTimeline-<version>.tar.gz` from the
   [latest release](https://github.com/alaidi/articleTimeline/releases/latest).
2. In OJS, go to Settings → Website → Plugins → **Upload a New Plugin** and
   upload the file. To upgrade, use **Upgrade** on the plugin's row instead.
3. Enable **Article Timeline** in the list of generic plugins.

Or copy the `articleTimeline` folder into `plugins/generic/` and run
`php tools/upgrade.php upgrade`.

**Upgrading** keeps the saved position and date format: the setting names and
stored values (`main`, `mainBelow`, `sidebar`) are unchanged since 1.0.

Requires OJS 3.5.0 or later. Tested on OJS 3.5.0-3 (PHP 8.5, MySQL 8). PKP's CI
runs the tests on OJS `stable-3_5_0` with PHP 8.2/8.3 and MySQL/PostgreSQL.

## Development

The tests follow PKP's plugin layout and run in
[PKP's GitHub Actions](https://github.com/pkp/pkp-github-actions) on every push
(OJS `stable-3_5_0`, PHP 8.2/8.3, MySQL/PostgreSQL):

- `tests/ArticleTimelinePluginTest.php` — how each of the four dates is chosen
  (missing dates, several revisions or decisions, revisions after the acceptance or
  publication, draft versions), placement of the timeline for different abstract
  markup and themes, the output filter, stored settings and date-format validation.
- `tests/AuthorRevisionsTest.php` — which uploads count as author revisions, against
  the installation's database (several authors, author groups, editors' and other
  users' uploads); the rows it adds are rolled back.
- `tests/LocaleFilesTest.php`, `tests/TemplateSafetyTest.php`, `tests/PluginTest.php`
  — locale files in sync, template safety (CSRF, escaping, headers), and class
  compatibility with the installed PKP version.
- `cypress/tests/functional/` — the three positions in the settings and on an article
  page, refusal of a date format without a date, and the layout on a 320px screen.

To run them in an OJS development checkout (with `composer install` dev dependencies),
from the OJS root:

```sh
php lib/pkp/lib/vendor/bin/phpunit --configuration lib/pkp/tests/phpunit.xml --no-coverage plugins/generic/articleTimeline/tests
npx cypress run --config '{"specPattern":["plugins/generic/articleTimeline/cypress/tests/functional/*.cy.js"]}' --env contextPath=<journal>,adminUser=<user>,adminPassword=<password>
```

See [CONTRIBUTING.md](https://github.com/alaidi/articleTimeline/blob/main/CONTRIBUTING.md) for releasing.

## Version history

- **1.3.0.0** — "Hide dates" setting: leave any of the four dates off the article page;
  optional setting to count revision files uploaded by editors for the Revised date.
- **1.2.1.0** — Stricter date format check (letters that are not format characters
  must be escaped; `U` refused); without an acceptance, Revised is not later than
  the day of publication; abstract detection accepts unquoted classes and `>` in
  other attribute values.
- **1.2.0.0** — Accepted also counts "Accept and Skip Review"; Revised counts only
  author uploads, not after the acceptance; Online ignores unpublished draft versions;
  date format must show a date, with an error message in the settings; the date is
  HTML-escaped; abstract detection accepts any attribute order and quotes, and `<div>`
  blocks; the timeline is rendered once per page; `<time>` elements and list semantics;
  entries wrap instead of being cut off on narrow screens.
- **1.1.0.1** — Own spacing for themes that don't pad `.item`.
- **1.1.0.0** — "Under the abstract" position; tests and CI.
- **1.0.2.0** — Compact date format, clean separators when the row wraps.
- **1.0.1.0** — Timeline above the abstract.
- **1.0.0.0** — First release.
