# Family Wiki

Keep your family history in a wiki hosted on WordPress.

**Contributors:** akirk
**Tags:** family, wiki
**Requires at least:** 5.0
**Tested up to:** 6.0.2
**Requires PHP:** 5.2.4
**License:** [GPLv2 or later](http://www.gnu.org/licenses/gpl-2.0.html)
**Stable tag:** 1.0.0

## Description

This plugin transforms your WordPress install into a Wiki for keeping your family history. 

### Recommended setup

In wp-admin go to *Settings* → *Reading* and set a static homepage. The plugin also adds an option *I would like my site to be private, visible only to myself and users I choose* which will usually be an option you'll want to use since only registered users should be allowed to edit.

Create new users with *Wiki User* (can edit pages) or *Wiki Editor* (can also delete pages). Unfortunately otherwise only the roles *Editor* or *Administrator* will allow them to edit pages.

If you created a calendar page, set the option `family_wiki_calendar_page`, for example with the cli command `wp option add family_wiki_calendar_page /Calendar`, then the dates will be linked to that page.

### Shortcodes
To populate the calendars, use these shortcodes for 

`[born date="1910-01-01"]`

Notes: 
- You can also use a textual date: `[born date="January 1, 1910"]`
- For living people, add a `showage`, like this: `[born date="January 1, 1910" showage]`. It will then be displayed in the birthday calendar.

For deceased relatives, specify the date when they died like this:

`[died date="2000-01-01" birth="1910-01-01"]`

You can also use a textual date: `[died date="January 1, 2000" birth="January 1, 1910"]`

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

### 1.0.0
- Initial version
