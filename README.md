# MAC Members

MAC Members is a company standard WordPress plugin for reviewing member accounts: it moves users between a pending, approved, inactive and denied role.

It provides a small admin settings page, a protected members table shortcode with a filter for each status, authenticated status changes, and hardcoded notification emails. Access to members-only content stays with the site's membership rules, for example SureMembers rules by role; MAC Members only assigns the roles.

## Requirements

- WordPress 6.x
- PHP 8.3+
- Automatic.css 4 on the front end, with the status colors (success, danger, warning and info) and the neutral color turned on: every color, border and radius in the members table is an ACSS token, and its buttons are ACSS buttons (`btn--success`, `btn--danger`, `btn--neutral`, `btn--outline`, `btn--s`), so it follows each site's ACSS settings. Spacing inside the table is in `em` and `ch`, because the ACSS space tokens are too large at this size

## Installation

Install MAC Members from the GitHub release ZIP, then activate the plugin in WordPress.

Activation creates the four member roles if they don't exist yet, each with only the `read` capability:

| Role | Name |
| --- | --- |
| `mac_members_pending` | Member (Pending) |
| `mac_members_approved` | Member |
| `mac_members_inactive` | Member (Inactive) |
| `mac_members_denied` | Member (Denied) |

A role that already exists keeps its name and capabilities. A site that updates the plugin without reactivating it gets the missing roles once, on the next request. New registrations need the pending role, for example from the registration form's user action.

## Settings

The settings page is under `Settings > MAC Members`. Turn on "Show MAC Members as a top-level admin menu item" to give it its own menu item with the MAC icon instead; it's off by default. Saving sends you back to the page, at its new address when this setting changed.

| Setting | Default role |
| --- | --- |
| Pending role | `mac_members_pending` |
| Approved role | `mac_members_approved` |
| Inactive role | `mac_members_inactive` |
| Denied role | `mac_members_denied` |

The four roles must be different roles, and the approved, inactive and denied roles must not grant administrative capabilities such as `manage_options`, `edit_users`, `promote_users`, `delete_users`, `unfiltered_html`, `edit_plugins`, `edit_themes`, `install_plugins` or `activate_plugins` (the full list is in `src/Security/Capabilities.php`). Settings that break these rules are not saved, and the settings page shows why. Every status change checks the same rules again before it changes a user, because a role can gain capabilities after the settings are saved.

The settings page also includes:

- roles left out of the role filter, `administrator` by default
- admin notification email
- from email
- member and admin approval email toggles
- member and admin denial email toggles
- member and admin deactivation email toggles

All email notification toggles are enabled by default.

## Members Table

Add the members table to a protected admin or internal page with:

```text
[mac_members_table]
```

The shortcode shows the table to logged-in users who have the `mac_members_review` capability and can `promote_users`. Other users see no output.

The table has a filter for each status (Pending, Approved, Inactive, Denied) and one for All, each with its number of members, and opens on Pending. The view is kept in the `mac_members_status` query argument. To show a single view without the filters, set the `status` attribute to `pending`, `approved`, `inactive`, `denied` or `all`:

```text
[mac_members_table status="pending"]
```

Pending requests are listed oldest first, like a queue; the other views show the newest registrations first. The columns are User ID, Email, First Name, Last Name, Username, Registered, Profile, Status and Actions; the Actions column has only the status changes the member's status allows.

Under the table, the page links sit on the left: Previous, the first and last page, the current page with one page on each side, gaps for the pages in between, and Next. On the right, "Per page" chooses 24, 48, 96 or 192 members per page, 24 by default, and the range shows which members the page lists, such as "1-24 of 2,353". The page and the page size are kept in the `mac_members_page` and `mac_members_per_page` query arguments. A page past the end, for example after a larger page size, shows the last page. The page size select belongs to the role and search form, so choosing a size keeps the role and the search and starts again at page 1.

A form above the table narrows any view. It has no buttons:

- **Role** lists the other roles members hold, for example Officer or Trustee. It leaves out the four status roles, and the roles that "Roles left out of the role filter" names by slug or name, or that have a capability it names. It shows only when members hold such a role, and choosing a role reloads the table.
- **Search** fills the rest of the row and matches members whose first name, last name, email, username or display name contains every word. A number also matches the user ID. Enter runs the search, and an empty search shows everyone again.

The role and the search are kept in the `mac_members_role` and `mac_members_search` query arguments. The status filters, their counts and the page links keep them.

ACSS keeps its `btn--` classes in a cascade layer, while its reset `input, button, textarea, select { font: inherit; }` is outside any layer, so on a `<button>` the reset wins and the button loses the ACSS button font. The table's buttons use `revert-layer` for their background, border, color and font, so the ACSS button styles apply, hover included.

After a change, a row that no longer belongs in a filtered view disappears, and in the All view the row shows its new status and buttons. The filter counts and the range follow the changes. When the last row of a page goes and members are left on other pages, the page loads again to show them.

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

Before any change, the plugin checks that the role being added exists, and that the user still has a status the change applies to. After the change it reads the user's roles again, and if they are not what was expected it restores the roles the user had before and returns an error. When a configured role is missing, the members table shows a warning as well as the settings page.

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

Member emails link to the site's home page rather than `wp-login.php`, because many sites have their own login, registration and password pages. Admin emails list the member's details with the User ID first, then first name, last name, username, email address and profile link.

Each notification type can be toggled from the settings page. Email delivery failures do not roll back the role update; the action response includes a warning and the failure is logged with lightweight action and user ID context.

Values the applicant controls (first name, last name, display name, username and email address) are cleaned before they go into a template: they pass through `sanitize_text_field()`, line breaks are removed, and each is cut to 100 characters. The rendered body is still escaped as a whole.

## Security

MAC Members uses authenticated WordPress AJAX actions for the four status changes (`mac_members_approve_user`, `mac_members_deny_user`, `mac_members_deactivate_user` and `mac_members_reactivate_user`). It checks the nonce with `check_ajax_referer()` and requires the acting user to have the `mac_members_review` capability and `promote_users`. For each target user it checks `current_user_can( 'promote_user', $user_id )` and that every role the change adds or removes is in `get_editable_roles()`. It verifies that the target user still has a status the change applies to, blocks self-actions, and blocks actions against target users who hold any of the sensitive capabilities listed under Settings.

Every check that fails sends an error and ends the request, and so does the success response, so a failed check can never reach the role change, even when a `wp_die` handler does not exit.

The plugin does not expose public unauthenticated endpoints and does not provide a REST API.

### Review capability

Activating the plugin gives the `administrator` role the `mac_members_review` capability. A site that updates the plugin without reactivating it gets the same step once, on the next request. To let another role review members, give it `mac_members_review` and `promote_users` with a role editor.

Deleting the plugin from the Plugins screen removes `mac_members_review` from every role, and removes the four member roles that no user holds. Roles that users still hold stay, so nobody is left without a role.

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
