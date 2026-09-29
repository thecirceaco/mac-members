# SureCart licensing SDK (vendored)

Bundled copy of the SureCart WordPress SDK. MAC Members uses it for license activation and update checks. `src/Licensing/LicensingService.php` loads `Client.php` on `init`.

- Upstream: <https://github.com/surecart/wordpress-sdk>
- Version: `v1.2.1` (tagged 2026-09-16)
- Commit: `c24515df17bc184686ca3c86761ce0541f60d7b5`
- Files: upstream `src/Activation.php`, `src/Client.php`, `src/License.php`, `src/Settings.php`, `src/Updater.php`
- License: MIT, as declared in the upstream `composer.json`
- Copied from MAC Core's copy (mac-core `61cc5a8`), with the global-name prefix MAC Core added in mac-core `57a52d1`. The two copies have the same local changes; only the namespace and the prefix differ.

## Local changes

These are the only differences from upstream `src/`:

1. **Namespace.** Each file declares `namespace MacMembers\Vendor\SureCart\Licensing;` instead of `namespace SureCart\Licensing;`. MAC Core's copy uses `MacCore\Vendor\SureCart\Licensing`, so the two copies, or another plugin's, never clash on one site. Only the `namespace` line changes. Docblocks still mention `SureCart\Licensing`.
2. **`register_menu` in `Settings::add_page()`** (`Settings.php`, lines 68-75). New argument, default `true`. MAC Members passes `false` because it shows the license form in the License tab of its settings page, so the SDK must not add a menu page. The same patch gives `deactivated_redirect` a `null` default.

```diff
 				'activated_redirect' => null,
+				'deactivated_redirect' => null,
 				'parent_slug'        => '',
+				'register_menu'      => true,
 			)
 		);
-		add_action( 'admin_menu', array( $this, 'admin_menu' ), 99 );
+		if ( ! empty( $this->menu_args['register_menu'] ) ) {
+			add_action( 'admin_menu', array( $this, 'admin_menu' ), 99 );
+		}
```

3. **Prefixed global names.** The four global names the SDK reads get the MAC Members prefix: `MAC_MEMBERS_` on the constant, `mac_members_` on the filters. Upstream, every bundled copy of the SDK reads the same names, so a value set in `wp-config.php` or a filter for another plugin's copy, MAC Core's included, would also change MAC Members' licensing, and the other way around. Only the names change. MAC Core's copy does the same with `MAC_CORE_` and `mac_core_`.

| Upstream name | MAC Members name | Kind | Where | Effect |
| --- | --- | --- | --- | --- |
| `SURECART_LICENSING_ENDPOINT` | `MAC_MEMBERS_SURECART_LICENSING_ENDPOINT` | constant | `Client::endpoint()`, `Client.php:212-213` | Replaces the API base URL for every licensing request. |
| `surecart_licensing_endpoint` | `mac_members_surecart_licensing_endpoint` | filter | `Client::endpoint()`, `Client.php:217` | Same, when the constant isn't defined. Default `https://api.surecart.com`. |
| `surecart_client_license_form_action` | `mac_members_surecart_client_license_form_action` | filter | `Settings::form_action_url()`, `Settings.php:82` | Sets the license form's `action` URL (`Settings.php:226`). |
| `surecart_licensing_is_local` | `mac_members_surecart_licensing_is_local` | filter | `Client::is_local_server()`, `Client.php:332` | None today. Nothing in the SDK or MAC Members calls `is_local_server()`. |

MAC Members itself doesn't define or hook any of them. The option and transient keys the SDK builds from the product name and the plugin slug, like `macmembers_license_options` and `surecart_<md5 of the slug>_version_info`, stay as they are: they already differ per plugin, and new names would lose what's stored under the old ones.

## Updating

Keep this copy in step with MAC Core's: update both to the same SDK version, with the same local changes.

1. Clone the upstream repository and check out the new tag.
2. Copy its `src/*.php` over the files here and reapply the local changes above.
3. Run `diff -u <upstream>/src/<File>.php <File>.php` for each file. Only the local changes should show.
4. Update the version and commit in this file.
5. Run `composer test`. `tests/Unit/BundledSureCartSdkTest.php` checks the namespace and the `register_menu` change, and fails if a hook the SDK fires or a constant it reads has no MAC Members prefix, which also catches a global name that a new SDK version adds.
