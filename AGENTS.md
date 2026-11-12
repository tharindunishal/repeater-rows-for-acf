# Repeater Rows for ACF: guide for developers and AI agents

A free repeater field type (`rrfa_repeater`) for Advanced Custom Fields. Read this before changing code. User-facing documentation is in `README.md` and `readme.txt`.

If `AGENTS.local.md` exists, read it next. It holds notes about one machine's setup, such as paths, containers and test accounts. It is not committed. Keep that kind of detail there, not in this file.

## Scope

In scope: one field type, its edit screen, and full support for ACF's data functions, validation, and export and import.

Out of scope unless the maintainer decides otherwise: gallery fields, page builder integrations, WP All Import, front-end layouts, shortcodes, a settings page, and row pagination.

## Code map

| Path | Responsibility |
|---|---|
| `repeater-rows-for-acf.php` | Plugin header, constants, loads the plugin on `plugins_loaded` |
| `includes/class-rrfa-plugin.php` | ACF version check, missing-ACF notice, field registration, REST output guard |
| `includes/class-rrfa-field-repeater.php` | `acf_field` subclass: settings, field lifecycle, export and import, validation, formatting, REST |
| `includes/class-rrfa-row-store.php` | Row IDs, per-row sub-field names, loading, saving, deleting |
| `includes/class-rrfa-row-renderer.php` | Card and table markup, and the new-row template |
| `assets/js/repeater-rows.js` | Edit screen behaviour, as one ACF field model per repeater |
| `assets/js/field-settings.js` | Keeps Row Title Field choices current in the field group editor |
| `assets/css/repeater-rows.css` | Edit screen styles, no build step |
| `tests/integration/run.php` | Self-cleaning checks against a real WordPress and ACF |

## Rules that must not break

Each of these prevents a specific bug. Keep the behaviour, and update the integration checks if you change how it works.

**Storage**

1. The format is one meta row per cell: `{field}` holds the row count, `{field}_{row}_{sub}` holds a value, and each has a `_`-prefixed key reference. Changing this breaks existing sites and the path to Pro's Repeater, so it would need a migration.
2. Every cell goes through `acf_get_value()`, `acf_update_value()` or `acf_delete_value()`, never direct meta calls. That is what runs each sub-field type's behaviour, handles non-post objects such as `user_1` and `options`, and makes nesting work by recursion.
3. Name a sub-field per row before any value call, with `RRFA_Row_Store::sub_field_for_row()`. ACF caches values by post ID and field name, so a shared name makes every row return the first row's value.
4. A sub-field missing from a saved row keeps its value and stays with that row. Conditional logic and `acf/prepare_field` both keep inputs off the form, and deleting those values would lose data every time someone without access saved the post. Row IDs (`rrfarow-{key}-{position}`) identify where a row was saved. Moved rows copy missing values across before anything is overwritten, at every depth, including Groups.
   - A row with no saved position (new, duplicated, or from `update_field()`) gets nothing, and anything at its position is deleted. Saved-looking IDs nested inside such a row are ignored, because they refer to the row it was copied from.
   - A Group ignores missing sub-fields, so `clear_missing_group_cells()` blanks them in new rows.
   - Each saved position is claimed once, through `claim_origin()`, and only by the exact ID `row_id()` prints. A repeated or zero-padded ID is a new row, so a forged request can't copy a row's kept-off values.
   - While ACF saves submitted values, `drop_hidden_rows()` removes values for sub-fields `acf/prepare_field` hides, at every depth, before anything else. Those values can only come from a forged request, and are then treated as kept off the form. It runs once, on the outermost repeater, because completing moved rows later fills hidden values back in. A Group left with nothing is removed, because a Group deletes its values when given an empty one. `update_field()` and other code are not checked.
5. Validation and saving must agree on the row count. Blank rows are kept, and `usable_rows()` is the only filter on both paths.
6. `delete_value` relies on the row count still existing. ACF fires that action before removing the meta.
7. PHP drops inputs past `max_input_vars`, which would look like deleted rows. A hidden `rrfa_complete` input after the rows proves the form arrived whole. Without it, `is_cut_off_submission()` keeps the saved rows untouched. Only the outermost repeater checks, and only for large forms, so code supplying its own values still works.
8. Sub-fields with no name (tabs, accordions) store nothing. Data code uses `value_sub_fields()`, and only rendering uses `sub_fields()`.

**Field definitions**

9. Keep the export and import filters working at every depth. Child fields must appear in JSON export, PHP export, JSON import and Local JSON.
10. `load_field` finds sub-fields through `acf_get_fields()`, which works for database, PHP and Local JSON fields. Those last two have no database ID, so `delete_field` must not assume one.
11. `duplicate_field` saves the parent first. The copy has no ID when the filter runs, and sub-fields need one to attach to.

**Edit screen**

12. The form always posts an empty value for the field, through a hidden input before the rows. ACF only validates submitted keys, so without it a repeater with no rows would skip `min` and `required` checks.
13. The new-row template is an inert `<template>` element, which never submits and can safely hold any sub-field markup, including `</script>`.
14. Template placeholders and saved row IDs include the field key, so a nested repeater's IDs are never confused with its parent's.
15. Use `replaceRowId()` for row ID changes. Its boundary check keeps `...-1` from matching `...-10`.
16. Nested `<template>` content is not part of the document, so ACF's rename can't reach it. `renameNestedTemplates()` handles that after a duplicate.
17. Look up rows through `$rows()`, `$rowActions()` and `$rowToggle()`, which use direct children. A plain `.find()` also matches nested repeaters.
18. Fire `sortstart` and `sortstop` around drags, and `unmount` and `remount` around any other DOM move. TinyMCE breaks if its iframe moves while mounted.
19. Use `acf.duplicate()` to copy rows, with `unmount` first so editor content reaches its textarea.
20. Row titles use `.text()` and `DOMParser`. Never parse field values with `.html()` or `$()`, which can run code in the content.
21. Row titles only read types in `TITLE_FIELD_TYPES`, which `field-settings.js` mirrors. Passwords must never qualify, and `title_field()` skips anything `acf/prepare_field` hides, because the title would reveal the value in the page source.
22. Call `markChanged()` after any change to rows. ACF only notices `change` events, and uses them for the block editor's Update button, the unsaved changes warning, and revisions.
23. Buttons carry their own styles, because WordPress only styles `.button` classes inside the admin. Check `acf_form()` on the front end after changing them.

**REST**

24. REST output stays in the light format, and writes stay blocked. ACF's user and post privacy filtering lists its container types by name and doesn't recognise this one, so expanded output could expose private data.

## Code rules

- Write new code. This plugin must not copy code, functions, classes, CSS or JavaScript from any other plugin. Build from ACF's APIs and this document.
- Prefix global PHP names with `rrfa` or `RRFA_`. CSS classes and JS names use `rrfa`. Don't use the `acf_` prefix for this plugin's own names.
- Follow WordPress Coding Standards, and support PHP 7.4.
- All user-facing strings are translatable with the `repeater-rows-for-acf` text domain, in PHP and JS.
- Escape on output. The only unescaped output is from `acf_esc_attrs()`, with a phpcs comment.
- Don't add front-end assets, or a build step without a clear need.
- CSS uses logical properties for right-to-left support, and translucent greys for light and dark admin themes.

## Commands

```sh
composer install                # Coding standards tools
composer lint                   # WordPress Coding Standards and PHP 7.4 compatibility
composer lint:fix               # Automatic fixes

# Integration checks. They create temporary content and remove it. Exit code 0 means all passed.
php wp-content/plugins/repeater-rows-for-acf/tests/integration/run.php
```

Run the checks with ACF and this plugin active. The script finds `wp-load.php` in a parent folder or a parent's `wp/` folder, so standard and Bedrock layouts both work from any working directory. In a Docker setup, run them inside the WordPress container. Use a development site, because the checks write to its database. Use a throwaway administrator for browser testing, because WordPress and ACF remember editor mode and open tabs per account.

`*.php` files must also pass `php -l`, and the JS files must pass `node --check`.

Plugin Check, skipping development files that `.distignore` keeps out of the release:

```sh
wp plugin check repeater-rows-for-acf --exclude-directories=vendor,tests --exclude-files=AGENTS.md,CLAUDE.md,AGENTS.local.md,CLAUDE.local.md,README.md,composer.json,composer.lock,phpcs.xml.dist
```

Plugin Check still reports the dotfiles (`.gitattributes`, `.gitignore`, `.distignore`). They are excluded from the release zip, so that is expected. WP-CLI on PHP 8.5 may print deprecation notices from its own bundled dependencies. They are not from this plugin.

## Manual test checklist

The integration checks don't cover browser behaviour. After changing the edit screen, check these on a test field group with text, select, true/false, date picker, WYSIWYG and a nested repeater, on the classic screen and in the block editor:

1. Add, duplicate, delete, drag and move rows. Editors must keep working and keep their content.
2. Duplicate a row and confirm select values, editor content, dates and nested rows carry over. The date picker in the copy must change only the copy.
3. Add a nested row inside a duplicated row, save, and confirm it saves under the new parent.
4. Conditional logic only affects its own row.
5. Save with a required field empty in a collapsed row, using **Publish** because ACF skips validation on **Save Draft**. The row must open and show the error.
6. Min and max: controls disable with a reason, and the status text is correct.
7. Keyboard only: expand, reorder and delete. Focus must not jump into an editor after a move. Escape cancels the delete prompt.
8. Table layout, phone width, right-to-left, and a dark admin theme.
9. The browser console shows no errors.
10. **Tools > Export As JSON**, **Generate PHP** and **Import JSON** keep all child fields.
11. With ACF deactivated, a notice appears and nothing fatal happens.
12. On a published post in the block editor, delete a row and confirm Update becomes available.
13. Save and reorder with a sub-field hidden by an `acf/prepare_field` filter. Its value must stay with its row, including in nested rows.
14. Add rows on the user profile, term edit and front-end `acf_form()` screens, and confirm they save.

## Release checklist

1. Update the version in `repeater-rows-for-acf.php` (header and `RRFA_VERSION`) and `readme.txt` (`Stable tag`), and add a changelog entry.
2. Update `Tested up to` and the tested versions in `README.md` after testing.
3. Run the lint, the integration checks, the manual checklist, and Plugin Check.
4. Regenerate `languages/repeater-rows-for-acf.pot` with `wp i18n make-pot`.
5. Build the zip with only what `.distignore` allows, and test installing it on a clean site.
6. Publishing to GitHub and WordPress.org is a maintainer decision. Add WordPress.org screenshots before submission, because `readme.txt` lists three.

## Not yet tested

Report these as untested rather than assuming they work: use alongside ACF Pro, moving data to Pro's Repeater, translated right-to-left languages (the right-to-left admin layout was tested with English text), and browsers other than Chromium.
