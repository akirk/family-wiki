# Family Wiki

- Contributors: akirk
- Tags: family, wiki
- Tested up to: 6.8
- License: [GPLv2 or later](http://www.gnu.org/licenses/gpl-2.0.html)
- Stable tag: 1.1.9

Keep your family history in a wiki hosted on WordPress.

## Description

This plugin transforms your WordPress install into a Wiki for keeping your family history.

### Recommended setup

In wp-admin go to *Settings* → *Reading* and set a static homepage. The plugin also adds an option *I would like my site to be private, visible only to myself and users I choose* which will usually be an option you'll want to use since only registered users should be allowed to edit.

Create new users with *Wiki User* (can edit pages) or *Wiki Editor* (can also delete pages). Unfortunately otherwise only the roles *Editor* or *Administrator* will allow them to edit pages.

If you created a calendar page, set the option `family_wiki_calendar_page`, for example with the cli command `wp option add family_wiki_calendar_page /Calendar`, then the dates will be linked to that page.

### Advanced Custom Fields

The plugin has switched to using Advanced Custom Fields for wiki page metadata. Please install that plugin in version 6.2 or up. The fields should be automatically restored using the provided JSON file in the `acf-json/` directory.

For each wiki page, you can enter data like birth or death date as well as mother/father/children relationships. This data is used for the calendar page but also for automatically generating a short bio using the shortcode `[name_with_bio]`. You'd put this as the first thing in a wiki page.

### Gutenberg Blocks

The *Family Calendar* block will show all dates from the wiki.

The *Birthday Calendar* block will show all dates of living people (determined by `showage`, see above) from the wiki.

### Performance

For displaying the red missing links or green external links, all pages are evaluated on page load. This works for small sites but won't work well if you have thousands of pages.

**Development of this plugin is done [on GitHub](https://github.com/akirk/family-wiki). Pull requests welcome. Please see [issues](https://github.com/akirk/family-wiki/issues) reported there before going to the [plugin forum](https://wordpress.org/support/plugin/family-wiki).**


## Screenshots

![1. A homepage showing a missing wiki page.](/assets/screenshot-1.png)
![2. A person page with a missing wiki page.](/assets/screenshot-2.png)
![3. Gutenberg view of a person page with the shortcodes.](/assets/screenshot-3.png)
![4. A 404 page exposing the "Create Page" link in the header.](/assets/screenshot-4.png)
![5. A person page with external links.](/assets/screenshot-5.png)
![6. A calendar page.](/assets/screenshot-6.png)
![7. Inserting a birthday calendar block.](/assets/screenshot-7.png)

## Changelog

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
