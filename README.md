# Article Timeline

An OJS 3.5 generic plugin that shows the editorial timeline on the reader-facing
article landing page:

> **Received** Dec.5, 2024 · **Revised** Mar.14, 2025 · **Accepted** May.11, 2025 · **Online** Jun.1, 2025

## How dates are derived

| Label | Source |
|---|---|
| Received | `submissions.date_submitted` |
| Revised | Latest upload of a review-revision file (file stages 15/20) |
| Accepted | `date_decided` of the last **Accept** editorial decision |
| Online | `date_published` of the first publication |

Dates that are not available for a given article are omitted.

## Settings (per journal)

- **Display position** — main column (under the abstract) or the details sidebar
- **Date format** — PHP date format string, default `M.j, Y` → “Dec.5, 2024”

## Installation

1. Copy the `articleTimeline` directory into `plugins/generic/`.
2. Run `php tools/upgrade.php upgrade` to register the plugin version.
3. Enable it under Settings → Website → Plugins.

Requires OJS 3.5.0+ and PHP 8.1+.
