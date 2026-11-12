=== Repeater Rows for ACF ===
Contributors: tharindunishal
Tags: acf, repeater, custom fields, rows, advanced custom fields
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A free repeater field for Advanced Custom Fields, with collapsible cards on the edit screen and the standard ACF row functions in templates.

== Description ==

Repeater Rows for ACF adds a repeater field type to the free Advanced Custom Fields plugin. Editors add, reorder and duplicate rows of sub-fields. Developers read them with the ACF functions they already know.

This plugin is independent. It is not made by, or affiliated with, WP Engine or the ACF project.

= Made for editors =

* Rows are collapsible cards by default, with visible labels and room for large fields like WYSIWYG.
* Each card header shows a live title, so long lists are easy to scan.
* Drag to reorder, or use the move up and down buttons.
* Duplicate a row with its content, including editor content and nested rows.
* A collapsed row opens by itself when it has a validation error.
* A compact table layout is available for short fields.
* Works with a keyboard and screen readers, and at phone width.

= Made for developers =

* `have_rows()`, `get_sub_field()`, `get_field()`, `update_field()`, `add_row()`, `update_row()` and `delete_row()` all work.
* Sub-fields keep their own return formats, validation and filters.
* Rows can hold a Group or another Repeater Rows field.
* Child fields are included in ACF's Export As JSON, Generate PHP, Import JSON and Local JSON.
* Nothing loads on your site's front end. You control the output in your templates.

= Example =

`
<?php while ( have_rows( 'contact_faq' ) ) : the_row(); ?>
	<h3><?php echo esc_html( get_sub_field( 'question' ) ); ?></h3>
	<?php echo wp_kses_post( get_sub_field( 'answer' ) ); ?>
<?php endwhile; ?>
`

== Installation ==

1. Install and activate Advanced Custom Fields 6.8.11 or newer.
2. Install and activate Repeater Rows for ACF.
3. Edit a field group, add a field, and choose **Repeater Rows** from the **Layout** category.
4. Add sub-fields under **General**. Row limits are under **Validation**, and layout options are under **Presentation**.

== Frequently Asked Questions ==

= Does this need ACF Pro? =

No. It is built for the free version of ACF. Using it alongside ACF Pro has not been tested yet.

= ACF also lists a Repeater field marked PRO. Which one do I choose? =

Choose **Repeater Rows**. The one marked PRO is ACF's own field, and it needs ACF Pro.

= How do I show rows on my site? =

Loop through them with `have_rows()` and `get_sub_field()` in your theme, as in the example above. The plugin does not render rows by itself. Don't use `the_field()` on the repeater, because the value is a list of rows and it prints `Array` with a PHP warning.

= Can I export my field groups? =

Yes. Use ACF's Tools page, or Local JSON with an `acf-json` folder in your theme. Child fields are included. ACF exports field definitions, not row content, and the site receiving them needs this plugin active.

= Can I move to the ACF Pro Repeater later? =

Rows are stored in the same meta shape as ACF's documented repeater, so it should mean changing the field's type from `rrfa_repeater` to `repeater`. This has not been tested yet, so back up and try it on a copy first.

= Why does the field warn me about the size of the form? =

PHP ignores form inputs past its `max_input_vars` setting. If a form is cut off this way, this field's changes are not saved, and its saved rows are left as they were. Remove some rows, or ask your host to raise the limit.

= What happens to fields hidden by conditional logic? =

Their saved values are kept and stay with their row, as ACF does elsewhere. `get_field()` still returns them, so check the controlling field in your template if that matters.

= Does it work with page builders? =

There is no specific integration with page builders in this version.

= Does it work with the REST API? =

Rows can be read when the field group has **Show in REST API** turned on. They are read-only, and images, posts and users are returned as IDs.

== Screenshots ==

1. Rows as collapsible cards on the edit screen.
2. Compact table layout.
3. Field settings in the field group editor.

== Changelog ==

= 0.1.0 =
* First release.

== Upgrade Notice ==

= 0.1.0 =
First release.
