# MAC Members

MAC Members is a company standard WordPress plugin for reviewing pending member accounts and moving them into approved or denied roles.

It provides a small admin settings page, a protected pending-members table shortcode, authenticated approve/deny actions, and hardcoded notification emails for the first release line.

## Requirements

- WordPress 6.x
- PHP 8.3+

## Installation

Install MAC Members from the GitHub release ZIP, then activate the plugin in WordPress.

After activation, review the configured roles in `Settings > MAC Members`. The plugin expects the selected roles to already exist on the site.

## Settings

MAC Members stores its settings under `Settings > MAC Members`.

The role defaults are:

| Setting | Default role |
| --- | --- |
| Pending role | `member-pending` |
| Approved role | `member` |
| Denied role | `member-invalid` |

The pending, approved and denied roles must be three different roles, and the approved and denied roles must not grant administrative capabilities such as `manage_options`, `edit_users`, `promote_users`, `delete_users`, `unfiltered_html`, `edit_plugins`, `edit_themes`, `install_plugins` or `activate_plugins` (the full list is in `src/Security/Capabilities.php`). Settings that break these rules are not saved, and the settings page shows why. Approve and deny check the same rules again before they change a user, because a role can gain capabilities after the settings are saved.

The settings page also includes:

- admin notification email
- from email
- member approval email toggle
- member denial email toggle
- admin approval email toggle
- admin denial email toggle

All email notification toggles are enabled by default.

## Pending Members Table

Add the pending-members table to a protected admin or internal page with:

```text
[mac_members_pending_table]
```

The shortcode shows pending users to logged-in users who can `promote_users`. Users without that capability see no output.

The table lists pending users by the configured pending role and shows the oldest registrations first. Each row includes account details, a profile link, and approve/deny actions.

## Approval and Denial

Approving a pending user removes the configured pending and denied roles and adds the configured approved role.

Denying a pending user removes the configured pending and approved roles and adds the configured denied role. Denied users are not deleted.

Approve and deny exclude each other, so a user who is moved back to pending never keeps the outcome role of an earlier review.

Before any change, the plugin checks that the pending role and the role being added exist. After the change it reads the user's roles again, and if they are not what was expected it restores the roles the user had before and returns an error. When a configured role is missing, the pending-members table shows a warning as well as the settings page.

Existing unrelated roles are preserved in both flows.

## Email Notifications

MAC Members sends hardcoded HTML emails after successful approval or denial actions.

The current notification types are:

- member approval email
- member denial email
- admin approval email
- admin denial email

Each notification type can be toggled from the settings page. Email delivery failures do not roll back the role update; the action response includes a warning and the failure is logged with lightweight action and user ID context.

## Security

MAC Members uses authenticated WordPress AJAX actions for approval and denial. It checks the nonce with `check_ajax_referer()` and requires the acting user to have the `mac_members_review` capability and `promote_users`. For each target user it checks `current_user_can( 'promote_user', $user_id )` and that every role the action adds or removes is in `get_editable_roles()`. It verifies that the target user is still pending, blocks self-actions, and blocks actions against target users who hold any of the sensitive capabilities listed under Settings.

Every check that fails sends an error and ends the request, and so does the success response, so a failed check can never reach the role change, even when a `wp_die` handler does not exit.

The plugin does not expose public unauthenticated approval endpoints and does not provide a REST API in v0.2.0.

### Review capability

Activating the plugin gives the `administrator` role the `mac_members_review` capability. A site that updates the plugin without reactivating it gets the same step once, on the next request. To let another role review members, give it `mac_members_review` and `promote_users` with a role editor. Deleting the plugin from the Plugins screen removes `mac_members_review` from every role.

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
