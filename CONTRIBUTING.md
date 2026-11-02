# Contributing

Thanks for helping improve Article Timeline. It is released under the **GNU GPL v3**.

## Reporting issues

Open an issue with your **OJS version**, the **plugin version** (`version.xml`), the
theme in use, and steps to reproduce. Errors from `error_log` or the browser console help.

## Pull requests

1. Branch from `main`. The repository root **is** the plugin folder, so `version.xml`
   stays at the root.
2. Follow the [PKP coding conventions](https://docs.pkp.sfu.ca/dev/documentation/en/coding):
   namespaced classes (`APP\plugins\generic\articleTimeline`), hooks via `PKP\plugins\Hook`.
3. Keep `locale/en/locale.po` and `locale/ar/locale.po` in sync: the tests fail when a
   key is missing from either, or when the code uses a key neither defines.
4. Keep the copyright header on every PHP, template and CSS file.
5. For a user-visible change, bump `<release>` and `<date>` in `version.xml`.
6. Make sure the tests pass (see the README), and say which OJS version you tested on.

## Releasing

Push a tag that matches `<release>` in `version.xml`, prefixed with `v`:

```sh
git tag v1.1.0.0 && git push origin v1.1.0.0
```

The *Package plugin* workflow builds `articleTimeline-1.1.0.0.tar.gz` (tests and CI files
left out) and attaches it to the GitHub Release. It fails when the tag and `version.xml`
disagree.
