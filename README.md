# Repeater Rows for ACF

A free, open source repeater field for [Advanced Custom Fields](https://wordpress.org/plugins/advanced-custom-fields/) (ACF). Editors add, reorder and duplicate rows of sub-fields, and developers read them with the ACF functions they already know.

This plugin is independent. It is not made by, or affiliated with, WP Engine or the ACF project.

## Features

- **Collapsible cards by default.** Sub-fields stack with visible labels, so large fields like WYSIWYG have room. Each card header shows a live title. ACF's own per-field **Width** setting places short fields side by side.
- **Compact table layout** for short fields. It stacks into labelled rows in narrow spaces.
- **Full row controls.** Add, delete with confirmation, duplicate, drag to reorder, move up and down, expand and collapse all.
- **Nesting.** Rows can hold a Group or another Repeater Rows field.
- **Validation.** Required, email and other sub-field rules work inside rows, and a collapsed row opens when it has an error.
- **Keyboard and screen reader support**, right-to-left layouts, and phone-width layouts.
- **Standard ACF functions.** `have_rows()`, `get_sub_field()`, `get_field()`, `update_field()`, `add_row()`, `update_row()` and `delete_row()` all work, with no helper functions to learn.
- **ACF export and import.** Child fields are included in Export As JSON, Generate PHP, Import JSON and Local JSON.
- **No front-end assets.** Nothing is added to your site's pages. You control the output in your templates.

## Requirements

- WordPress 6.2 or newer
- PHP 7.4 or newer
- Advanced Custom Fields 6.8.11 or newer

Tested with WordPress 7.1, ACF 6.8.11, and PHP 7.4 and 8.3. Using it alongside ACF Pro has not been tested yet.

## Installation

1. Copy this folder to `wp-content/plugins/repeater-rows-for-acf`.
2. Activate **Repeater Rows for ACF** under **Plugins**, with ACF active.
3. Edit a field group, add a field, and choose **Repeater Rows** from the **Layout** category.
4. Add sub-fields under **General**. Row limits are under **Validation**, and layout options are under **Presentation**.

ACF's field type list also shows a **Repeater** card marked PRO. That is ACF's own field, and it needs ACF Pro. Choose **Repeater Rows** instead.

## Using the data

Loop through rows:

```php
<?php if ( have_rows( 'contact_faq' ) ) : ?>
	<dl>
		<?php while ( have_rows( 'contact_faq' ) ) : the_row(); ?>
			<dt><?php echo esc_html( get_sub_field( 'question' ) ); ?></dt>
			<dd><?php echo wp_kses_post( get_sub_field( 'answer' ) ); ?></dd>
		<?php endwhile; ?>
	</dl>
<?php endif; ?>
```

Or read every row at once. Rows are keyed by sub-field name, and values use each sub-field's return format. With no rows it returns `false`, or `null` if the field has never been saved.

```php
$rows = get_field( 'contact_faq' );
```

Change rows from code:

```php
add_row( 'contact_faq', array( 'question' => 'Do you offer refunds?' ) );
update_row( 'contact_faq', 1, array( 'question' => 'New text', 'answer' => 'New answer' ) );
delete_row( 'contact_faq', 2 );
```

Row numbers start at 1. `update_row()` replaces the row, so include every value you want to keep.

The plugin does not render rows by itself. Don't use `the_field()` on the repeater: ACF joins the value into text, so it prints `Array` and raises a PHP warning. Use it on sub-fields inside a loop instead. The `[acf]` shortcode prints nothing for this field type.

## Field settings

| Setting | Tab | Stored as | Notes |
|---|---|---|---|
| Sub Fields | General | `sub_fields` | The fields in each row |
| Minimum Rows | Validation | `min` | 0 means no minimum |
| Maximum Rows | Validation | `max` | 0 means no maximum |
| Layout | Presentation | `layout` | `block` (Cards) or `table` |
| Row Title Field | Presentation | `collapsed` | Sub-field key for the card title. Text, textarea, WYSIWYG, email, URL, number, select, radio, button group and date or time pickers qualify. Empty uses the first of these |
| Start Collapsed | Presentation | `start_collapsed` | Saved rows show as titles until opened |
| Add Row Button Label | Presentation | `button_label` | |

## How data is stored

Rows use one meta row per value, in this shape:

```
contact_faq               2            row count
_contact_faq              field_abc    field reference
contact_faq_0_question    "..."
_contact_faq_0_question   field_def    field reference
contact_faq_1_question    "..."
```

ACF's field export uses the type name `rrfa_repeater`.

### Values that aren't on the form

A saved row can reach the server without some of its sub-fields, because conditional logic hid them, or because an `acf/prepare_field` filter hides them from this user. Those values are kept and stay with their row, including when the row moves or is nested. This matches how ACF treats these fields elsewhere, so a value hidden by conditional logic is still returned by `get_field()`. Check the controlling field in your template if that matters.

Duplicating a row does not copy values the current user can't see. When a form is saved, values sent for sub-fields that `acf/prepare_field` hides from this user are ignored, so a crafted request can't change them. Code that saves with `update_field()` is not limited this way. The check runs your `acf/prepare_field` filters during the save as well as on the edit screen, so they must not assume a screen is being displayed.

From code, `update_row()` and `update_field()` replace rows, so a sub-field you leave out is cleared.

## Export and import

Use ACF's **Tools** page, or Local JSON with an `acf-json` folder in your theme. Keep these limits in mind:

- ACF exports field **definitions**, not row content. Content moves with your database.
- The site receiving the fields needs this plugin active to use them as a repeater.
- The meta shape and setting names follow ACF's documented repeater, so moving to ACF Pro's Repeater should mean changing `rrfa_repeater` to `repeater` in the field's type. This has not been tested yet, so back up and try it on a copy first.

## REST API

When a field group has **Show in REST API** turned on, rows are available to read, keyed by sub-field name.

- **Read-only.** A write to this field is refused, and if the field is inside a Group, the write is ignored.
- **IDs instead of objects.** Images, posts and users stay as IDs, even with `?acf_format=standard`, and this applies to any field that contains this one. ACF's own privacy filtering only recognises its built-in container types, so expanded objects could otherwise expose private data.

## Known limits

- Row pagination is not available.
- **Very large forms.** PHP ignores form inputs past its `max_input_vars` setting, 1000 by default on many hosts. The repeater warns when a form gets close to that limit. If a form is still cut off, this field's changes are not saved, and its saved rows are left as they were. ACF also stops validation on such a form with a message about a missing nonce. The fix is fewer rows, or a higher limit from your host.
- Which rows are open is remembered by position, per post, in that browser. Nested repeaters don't remember it.
- Renaming or removing a sub-field leaves its old values in the database, as with other ACF fields.

## Contributing

There is no build step. CSS and JavaScript are edited directly.

```sh
composer install
composer lint
```

Run the integration checks on a WordPress site with ACF and this plugin active. They create temporary content and remove it afterward. Use a development site. The script finds WordPress in standard and Bedrock (`wp/`) layouts.

```sh
php wp-content/plugins/repeater-rows-for-acf/tests/integration/run.php
```

See [AGENTS.md](AGENTS.md) for the architecture, rules that must not break, and the manual test and release checklists.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
