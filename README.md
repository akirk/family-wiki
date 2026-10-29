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

Familypedia brings the former Family Wiki plugin a dedicated interface, with people, stories, photos, family trees, calendars and GEDCOM import/export. Open the app at `/familypedia/`. It requires an Administrator or Editor account. Advanced Custom Fields is no longer required.

Assign family contributors the standard WordPress Editor role to create, edit and delete wiki content and upload media. Administrators also manage settings. Other standard roles do not have wiki access. Editor permissions apply across the WordPress site, including deletion. The existing whole-site privacy setting in Settings → Reading remains supported; app content requires wiki access even on a public site.

### Upgrading from Family Wiki 1.x

Back up your database, then open Tools → Upgrade Familypedia. Review and select your people and related pages. Pages with family facts are selected initially; include relatives and child pages together. Migration validates these dependencies before moving any posts.

Selected pages become app posts with the same IDs, content, featured images and hierarchy. Relationship IDs remain intact, ACF dates are normalized, settings are copied, and old person URLs redirect to the app. The original homepage remains a WordPress page; its content is also copied into the app homepage. Unselected ordinary site pages stay in WordPress. The migration can be run again for remaining pages.

Existing bio shortcodes and Family Wiki tree/calendar blocks continue to work. Existing ACF metadata is retained. Converting posts back to ordinary pages is not an automatic downgrade path; restore your backup to revert the migration.

### Optional member homepage

In Settings → Familypedia, enable “Show the wiki at the homepage for signed-in wiki members.” Authorized members then see the app homepage at `/`. Person pages and tools remain under `/familypedia/`. Anonymous visitors and accounts without wiki access keep the normal WordPress homepage, subject to the site's privacy setting. The option defaults off.

### Family interviews

Choose **Add → Interview** on a person's page to start a conversation or resume saved notes. Record the speaker and interview date, and choose whether they are describing their own life or someone else's. Six topics offer optional questions and follow-ups; notes save automatically and can also be saved with the Save interview button. Without JavaScript, all questions appear together and saving is manual.

Each interview is a separate draft related page containing the source notes. The fact review keeps the values recorded when the interview began, the speaker's account and any uncertainty. Applying a proposed fact is a separate action; year-only dates retain their approximate precision, and changed facts require review on the person's facts page.

Use **Customize questions** to edit your personal library and import or export JSON question sets. Untouched starter questions, topics and hints use WordPress translations; edited fields and custom questions keep your wording. Exports retain starter IDs and explicit overrides so untouched fields translate when imported elsewhere. Existing text-only libraries remain custom text. Saved interviews retain the wording used when they were started.

Record audio directly in an interview over HTTPS after allowing microphone access. Ask the speaker before recording. Pause/resume as needed, then stop, review and save the clip. Recording continues across question changes. If saving fails, retry or download the local copy before closing the page. Clips stop automatically around 20 MB; server upload limits may be lower. External audio-file uploads and transcription are not included.

Recordings require PHP OpenSSL and Fileinfo. Audio is encrypted in `uploads/familypedia-recordings` and served through interview permission checks, rather than exposed as public Media Library files. Back up that directory and the WordPress database together; the database holds the recording encryption key. Playback currently loads complete clips rather than supporting byte-range streaming.

Select saved interviews to create an attributed biography outline. Edit and save the draft, then explicitly review it before adding its text after the existing biography. Interview sources remain linked and unchanged. This version collects typed notes and creates outlines without an external text-generation service.

### Development

Development happens on `main`. Dependencies in `vendor/` are ignored by Git; run `composer install --no-dev` before using a source checkout. For development checks, run `composer install` followed by `composer test`. The migration tests use an in-memory fixture and do not connect to WordPress or its database.

Every push to a source branch builds an installable `dist/<branch>` branch with production dependencies and appends the source commit to the plugin version. The build can also be run manually for a source branch from GitHub Actions. Use `dist/main` for installations and WordPress Playground; PR previews use their corresponding distribution branches and become available once the build finishes.

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
