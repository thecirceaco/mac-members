# MAC Members

MAC Members is a company standard WordPress plugin for reviewing member accounts: it moves users between a pending, approved, inactive and denied role.

It provides a small admin settings page, a protected members table shortcode with a filter for each status, authenticated status changes, and hardcoded notification emails. Access to members-only content stays with the site's membership rules, for example SureMembers rules by role; MAC Members only assigns the roles.

## Requirements

- WordPress 6.x
- PHP 8.3+
- Automatic.css 4 on the front end, with the status colors (success, danger, warning and info) and the neutral color turned on: every color, border and radius in the members table is an ACSS token, and its buttons are ACSS buttons (`btn--success` to approve, `btn--danger` to deny, `btn--danger btn--outline` to deactivate, `btn--success btn--outline` to reactivate, and `btn--neutral`, `btn--outline` and `btn--s` for the filters and page links), so it follows each site's ACSS settings. Spacing inside the table is in `em` and `ch`, because the ACSS space tokens are too large at this size

## Installation

Install MAC Members from the GitHub release ZIP, then activate the plugin in WordPress.

Activation creates the four member roles if they don't exist yet, each with only the `read` capability:

| Role | Name |
| --- | --- |
| `mac_members_pending` | Member (Pending) |
| `mac_members_approved` | Member |
| `mac_members_inactive` | Member (Inactive) |
| `mac_members_denied` | Member (Denied) |

A role that already exists keeps its name and capabilities. A site that updates the plugin without reactivating it gets the missing roles once, on the next request.

MAC Members always uses these four roles. New registrations need `mac_members_pending`, for example from the registration form's user action, and membership rules, for example in SureMembers, use `mac_members_approved`. Earlier versions, up to 0.2.0, let a site choose other roles for the statuses; a site whose members hold other roles moves them to these once, for example with WP-CLI.

## Settings

The settings page is under `Settings > MAC Members`. Turn on "Show MAC Members as a top-level admin menu item" to give it its own menu item with the MAC icon instead; it's off by default. Saving sends you back to the page, at its new address when this setting changed.

The settings page includes:

- roles hidden from the members table, none checked by default (see Members Table below)
- admin notification email
- from email
- member and admin approval email toggles
- member and admin denial email toggles
- member and admin deactivation email toggles
- members table size: Medium by default, or Small (see Members Table below)
- dates in the members table: Relative by default, or Date (see Members Table below)
- member details fields, empty by default (see Members Table below)
- delete plugin data on uninstall, off by default (see Review capability below)

All email notification toggles are enabled by default.

## Members Table

Add the members table to a protected admin or internal page with:

```text
[mac_members_table]
```

The shortcode shows the table to logged-in users who have the `mac_members_review` capability. Other users see no output.

The table has a filter for each status (Pending, Approved, Inactive, Denied) and one for All, each with its number of members, and opens on Pending. The view is kept in the `mac_members_status` query argument. To show a single view without the filters, set the `status` attribute to `pending`, `approved`, `inactive`, `denied` or `all`:

```text
[mac_members_table status="pending"]
```

Every view lists the newest registrations first. The columns are User ID, Email, First Name, Last Name, Username, Registered, Last Login, Details, Status, Roles and Actions. Roles lists the member's roles other than the status roles, for example Officer or Trustee, and the Actions column has only the status changes the member's status allows.

"View details" in the Details column opens a dialog with the row's fields, in the table's order, followed by the fields the "Member details fields" setting lists. Admins use the same dialog; the table doesn't link to the WordPress profile screen. Close, Escape or a click outside the dialog closes it. While it is open, only the dialog scrolls: the page gets `overflow: hidden`, and `scrollbar-gutter: stable` keeps it from shifting where its scrollbar was. Each row carries its details in a `<template>`, escaped on the server, and the script only copies that template into the dialog, so opening it sends no request and a value can't add markup.

The dialog's footer has the row's status buttons, the full width of the dialog. A change made there closes the dialog, and the table shows the result as it does for the row's own buttons; an error shows in the footer, since the table's notices are behind the dialog.

The "Member details fields" setting lists the extra fields, one per line or comma-separated, each a user meta key, a colon and a label:

```text
phone : Phone
local_number : Local #
user_url : Website
```

The dialog shows them in that order. Without a label, a field shows its ACF field label when ACF knows the key, or else the key made readable, so `local_number` reads "Local number". A label can't contain a comma, because a comma starts the next field. A key can also be one of the user's own fields: `user_url`, `user_login`, `user_email`, `user_registered`, `display_name` or `user_nicename`. When ACF is active, the values are read with `get_field()`, so dates, choices, posts, terms and users come out formatted; otherwise with `get_user_meta()`. An empty value shows "Not set". Keys that start with an underscore, passwords, activation keys, sessions, capabilities and user levels never show, even when listed. Saving keeps up to 50 fields, drops repeated keys and cuts labels to 100 characters.

Right above the table, under the role and search form, a checkbox for each column shows or hides it. All start checked except Last Login. While User ID is hidden, the first visible column is the one that stays at the left. The stylesheet hides a column while its checkbox is unchecked, and the script keeps the viewer's changes in the `mac_members_hidden_columns` cookie for a year: the columns they hid, and, with a `+`, the columns that start hidden and that they showed. The next pages and later visits in the same browser keep them.

Under the table, the page links sit on the left: Previous, the first and last page, the current page with one page on each side, gaps for the pages in between, and Next. On the right, "Per page" chooses 24, 48, 96 or 192 members per page, 24 by default, and the range shows which members the page lists, such as "1-24 of 2,353". The page and the page size are kept in the `mac_members_page` and `mac_members_per_page` query arguments. A page past the end, for example after a larger page size, shows the last page. The page size select belongs to the role and search form, so choosing a size keeps the role and the search and starts again at page 1.

A form above the table narrows any view. It has no buttons:

- **Role** lists the other roles the shown members hold, for example Officer or Trustee, without the four status roles and the hidden roles. It shows only when members hold such a role, and choosing a role reloads the table.
- **Search** fills the rest of the row and matches members whose first name, last name, email, username or display name contains every word. A number also matches the user ID. Enter runs the search, and an empty search shows everyone again.

The role and the search are kept in the `mac_members_role` and `mac_members_search` query arguments. The status filters, their counts and the page links keep them.

ACSS keeps its `btn--` classes in a cascade layer, while its reset `input, button, textarea, select { font: inherit; }` is outside any layer, so on a `<button>` the reset wins and the button loses the ACSS button font. The table's buttons use `revert-layer` for their background, border, color and font, so the ACSS button styles apply, hover included.

After a change, a row that no longer belongs in a filtered view disappears, and in the All view the row shows its new status and buttons. The filter counts and the range follow the changes. When the last row of a page goes and members are left on other pages, the page loads again to show them.

When the table fits its frame, the header sticks to the page, under the admin bar (ACSS's `--admin-bar-height`). A site with a sticky header sets `--mac-members-sticky-offset` to that header's height; ACSS's `--header-height` is not used, because it is the header's height whether or not the header stays on screen. When it doesn't fit, for example on a narrow screen or with many columns, the table scrolls inside its frame, both ways, up to 80% of the screen height, and keeps its header and first visible column in view. The script checks the fit when the page loads and again when the frame or the table changes size.

The "Members table size" setting sets the text and button size: Medium, the default, puts the table, its controls and its buttons in `--text-m`, and Small in `--text-s`. The buttons keep ACSS's `btn--s` class, and Medium sets ACSS's `--btn-font-size`.

Users who hold a hidden role never show in the members table: not in its rows, its counts, its search or its role filter, so nobody can change their status there. The "Roles hidden from the members table" setting has a checkbox for each role except the four status roles, which cannot be hidden; none is checked by default. Roles with any of the sensitive capabilities listed under Security, like Administrator and Editor (which has `unfiltered_html`), are always hidden, because status changes refuse their users anyway; their checkboxes show checked and disabled. The table leaves those users out with `role__not_in` in every user query. Development builds kept this setting as comma-separated text; its role slugs and names carry over, and its capabilities are dropped.

The table and the dialog use only ACSS tokens and button classes, and ACSS 4 defines its colors with `light-dark()`, so they follow the color scheme of the page or section around them: the site's scheme, a `scheme--dark` or `scheme--light` section, or the visitor's device when ACSS's scheme is auto. The dialog's backdrop stays black in both. How ACSS's main colors look in the dark scheme, for example the neutral buttons, comes from the site's ACSS settings.

Last Login shows only while MAC Core is active and its "Show last login column" setting is on. MAC Core then records the time of each login through a login form in the `mac_core_last_login` user meta, and MAC Members only reads it; the cell is empty for a member without a recorded login. The column starts hidden, and its checkbox shows it. MAC Members detects MAC Core by its `MAC_CORE_VERSION` constant and reads the setting from the `mac_core_settings` option, so if that option changes shape, the column stops showing rather than failing. The table loads the user meta of every member on the page in one query.

The "Dates in the members table" setting applies to Registered and Last Login. Relative, the default, shows the time since, like "3 days ago"; Date shows the day in the site's date format, and Last Login also the time. Both show the full date and time on hover, in a `<time>` element.

The stylesheet and the script are versioned with the plugin version and the file's modification time, so a new build reaches browsers and CDNs that keep the old files for a year.

Each time the table is rendered it gets a token that lists the users it shows and is signed for the current user and login session. Status change requests must send that token, and the server only acts on users the token lists, so buttons or requests that did not come from a rendered table are refused. A token is valid for 12 hours; after that the table has to be reloaded.

On a page whose content contains the shortcode, the plugin defines `DONOTCACHEPAGE` and sends `Cache-Control: no-store` and `Content-Security-Policy: frame-ancestors 'self'` from `template_redirect`, before any output. When the shortcode is placed outside the post content, for example by a page builder or in a template, return `true` from the `mac_members_is_table_page` filter for that request. The shortcode also sends these headers when it renders, if output has not started yet.

## Status Changes

| Change | From | To | Emails |
| --- | --- | --- | --- |
| Approve | Pending or Denied | Approved | approval |
| Deny | Pending | Denied | denial |
| Deactivate | Approved | Inactive | deactivation |
| Reactivate | Inactive | Approved | approval |

Denied means a request that was refused; inactive means someone who was a member and no longer is. Approve on a denied member corrects a denial. Nobody is deleted.

The statuses exclude each other: each change adds the role of the new status and removes every other status role the user holds, so a user who goes back to pending never keeps the outcome of an earlier review. Existing unrelated roles, such as Subscriber, are preserved. WordPress's user edit screen has a single Role dropdown that replaces all of a user's roles, so change member statuses from the members table.

Before any change, the plugin checks that the role being added exists, and that the user still has a status the change applies to. After the change it reads the user's roles again, and if they are not what was expected it restores the roles the user had before and returns an error. When a member role is missing, for example after a role editor deleted it, the members table and the settings page show a warning, and deactivating and activating the plugin creates it again.

Only one status change can change a user at a time. The plugin takes a per-user lock, a `mac_members_lock_<user ID>` row in the options table created with an atomic `INSERT IGNORE`, reads the user's roles again, and releases the lock when the change is done. A second request for the same user gets a "being updated" error while the lock is held, and "status has changed" after that. A lock left behind by a request that died expires after 30 seconds.

## Email Notifications

MAC Members sends hardcoded HTML emails after successful status changes.

The current notification types are:

- member approval email, also sent on reactivation
- member denial email
- member deactivation email
- admin approval email, also sent on reactivation
- admin denial email
- admin deactivation email

Member emails link to the site's home page rather than `wp-login.php`, because many sites have their own login, registration and password pages. Admin emails list the member's details with the User ID first, then first name, last name, username and email address. They don't link to the WordPress profile screen; the member's full details are in the members table.

Each notification type can be toggled from the settings page. Email delivery failures do not roll back the role update; the action response includes a warning and the failure is logged with lightweight action and user ID context.

Values the applicant controls (first name, last name, display name, username and email address) are cleaned before they go into a template: they pass through `sanitize_text_field()`, line breaks are removed, and each is cut to 100 characters. The rendered body is still escaped as a whole.

## Security

MAC Members uses authenticated WordPress AJAX actions for the four status changes (`mac_members_approve_user`, `mac_members_deny_user`, `mac_members_deactivate_user` and `mac_members_reactivate_user`). It checks the nonce with `check_ajax_referer()` and requires the acting user to have the `mac_members_review` capability. For each target user it checks that every role the change adds or removes is in `get_editable_roles()`, so a site's role editor can still narrow them. It verifies that the target user still has a status the change applies to, and blocks self-actions, actions against target users who hold any of the sensitive capabilities, and actions against users with a hidden role.

The sensitive capabilities are the ones that can change the site, its code or other users, such as `manage_options`, `edit_users`, `promote_users`, `delete_users`, `unfiltered_html`, `edit_plugins`, `edit_themes`, `install_plugins` and `activate_plugins`; the full list is in `src/Security/Capabilities.php`. The member roles start with only `read`, and a status change also refuses to add a member role that has gained a sensitive capability, for example through a role editor.

Every check that fails sends an error and ends the request, and so does the success response, so a failed check can never reach the role change, even when a `wp_die` handler does not exit.

The plugin does not expose public unauthenticated endpoints and does not provide a REST API.

### Review capability

Activating the plugin gives the `administrator` role the `mac_members_review` capability. A site that updates the plugin without reactivating it gets the same step once, on the next request. To let another role review members, give it `mac_members_review` with a role editor, or with WP-CLI:

```bash
wp role create membership_manager "Membership Manager" --clone=subscriber
wp cap add membership_manager mac_members_review
```

`mac_members_review` works as a narrow `promote_users`: the plugin changes the roles itself, and only moves members between its four roles, and never adds one that grants a sensitive capability. A reviewer does not need `promote_users`, which in wp-admin would let them give any user any role, or `edit_users`, which would let them edit any user, administrators included.

Deleting the plugin from the Plugins screen keeps its data, like MAC Core, unless "Delete plugin data on uninstall" is on in the settings; it is off by default. With it on, deleting the plugin removes the settings, `mac_members_review` from every role, and the four member roles that no user holds. Roles that users still hold stay, so nobody is left without a role; the settings page shows how many users hold each member role next to the setting. The per-user lock rows always go, because they only exist while a status change runs.

## Development

```powershell
composer validate --strict
composer install --no-interaction --prefer-dist
composer lint
composer test
```

Build a local install-test ZIP from the current committed `HEAD`:

```powershell
pwsh -NoProfile -File .\bin\build-dev-zip.ps1
```

To build from a dirty working tree intentionally, pass `-AllowDirty`. The ZIP is written to `dist/`.

## License

GPL v3 or later. See the `LICENSE` file for details.
