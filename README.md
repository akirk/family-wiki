# Familypedia

- Contributors: akirk
- Tags: family, wiki, genealogy
- Requires at least: 6.0
- Requires PHP: 7.4
- Tested up to: 7.1
- License: [GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html)
- Stable tag: 2.0.0

Keep your family history in a private wiki app hosted on WordPress.

## Description

Familypedia brings the former Family Wiki plugin a dedicated interface, with people, stories, photos, family trees, calendars and GEDCOM import/export. Open the app at `/familypedia/`. It requires an Administrator, Editor, Wiki User or Wiki Editor account. Advanced Custom Fields is no longer required.

Create family accounts with the Wiki User role (can create and edit) or Wiki Editor role (can also delete). The existing whole-site privacy setting in Settings → Reading remains supported; app content requires wiki access even on a public site.

### Upgrading from Family Wiki 1.x

Back up your database, then open Tools → Upgrade Familypedia. Review and select your people and related pages. Pages with family facts are selected initially; include relatives and child pages together. Migration validates these dependencies before moving any posts.

Selected pages become app posts with the same IDs, content, featured images and hierarchy. Relationship IDs remain intact, ACF dates are normalized, settings are copied, and old person URLs redirect to the app. The original homepage remains a WordPress page; its content is also copied into the app homepage. Unselected ordinary site pages stay in WordPress. The migration can be run again for remaining pages.

Existing bio shortcodes and Family Wiki tree/calendar blocks continue to work. Existing ACF metadata is retained. Converting posts back to ordinary pages is not an automatic downgrade path; restore your backup to revert the migration.

### Optional member homepage

In Settings → Familypedia, enable “Show the wiki at the homepage for signed-in wiki members.” Authorized members then see the app homepage at `/`. Person pages and tools remain under `/familypedia/`. Anonymous visitors and accounts without wiki access keep the normal WordPress homepage, subject to the site's privacy setting. The option defaults off.

### Development

Development happens on `main`, with runtime dependencies committed in `vendor/`. To rebuild them, run `composer install --no-dev`. For development checks, run `composer install` followed by `composer test`. The migration tests use an in-memory fixture and do not connect to WordPress or its database.

Every push to `main` builds the installable `dist/main` branch with production dependencies and appends the source commit to the plugin version. The build can also be run manually for `main` from GitHub Actions. Use `dist/main` for installations and WordPress Playground; PR previews continue to use their source branches.

### Temporary save diagnostics

Enable timings for one hour with `wp --url=your-wiki.example transient set family_wiki_save_debug 1 3600`. Stop early with `wp --url=your-wiki.example transient delete family_wiki_save_debug`.

Save requests log callback timings, database query counts, REST response statuses, external HTTP hosts and fatal-error locations to `family-wiki-save-BLOG_ID.log` in PHP’s temporary directory. Logs exclude page content, SQL text and credentials. Timing entries share a request identifier. The web-server account owns the log; its group can read it.

## Changelog

### 2.0.0
- Adopt the Familypedia app interface without an ACF dependency.
- Add reviewed migration of existing wiki pages, settings and legacy URLs.
- Add an optional wiki homepage for signed-in members.
- Preserve whole-site privacy, existing bio shortcodes and saved wiki blocks.

### 1.2.0
- Add automatic infoboxes with structured marriages data.
- Add cross-wiki settings, allowing infoboxes to link to the same person or page on peer wikis.
- Add a Family Wiki menu and virtual calendar pages.
- Add citizenships and cache family wiki data for performance.
- Add wedding anniversaries to the family calendar.
- Add a Family Tree block.
- Add GEDCOM import and export support, including a review step for large files.
- Add a content export/import alongside GEDCOM, carrying related child pages along with a person.
- Fix a cross-wiki privacy leak.

### 1.1.9
- Translated ACF fields to German.
- Lots of translation fixes.
- Fix translation loading to translate.wordpress.org.
- Redirect to the front page after logging in.
- Improve output escaping.

### 1.1.0
- Switch to using Advanced Custom Fields for metadata.

### 1.0.0
- Initial version
