# SureCart licensing SDK (vendored)

Bundled copy of the SureCart WordPress SDK. MAC Members uses it for license activation and update checks. `src/Licensing/LicensingService.php` loads `Client.php` on `init`.

- Upstream: <https://github.com/surecart/wordpress-sdk>
- Version: `v1.2.1` (tagged 2026-09-16)
- Commit: `c24515df17bc184686ca3c86761ce0541f60d7b5`
- Files: upstream `src/Activation.php`, `src/Client.php`, `src/License.php`, `src/Settings.php`, `src/Updater.php`
- License: MIT, as declared in the upstream `composer.json`
- Copied from MAC Core's copy (mac-core `61cc5a8`), which has the same local changes; only the namespace differs.

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

## Updating

Keep this copy in step with MAC Core's: update both to the same SDK version, with the same local changes.

1. Clone the upstream repository and check out the new tag.
2. Copy its `src/*.php` over the files here and reapply the local changes above.
3. Run `diff -u <upstream>/src/<File>.php <File>.php` for each file. Only the local changes should show.
4. Update the version and commit in this file.
5. Run `composer test`. `tests/Unit/BundledSureCartSdkTest.php` checks the namespace and the `register_menu` change.

## Unprefixed global names

The SDK still reads these global names. Any plugin, theme or `wp-config.php` on the site can set them, and every bundled copy of the SDK reads the same names, so a value set for another product, MAC Core included, also applies to MAC Members. MAC Members itself doesn't define or hook any of them. Prefixing them is decided for MAC Core in Linear MAC-1524, and the same change then comes here.

| Name | Kind | Where | Effect |
| --- | --- | --- | --- |
| `SURECART_LICENSING_ENDPOINT` | constant | `Client::endpoint()`, `Client.php:212-213` | Replaces the API base URL for every licensing request. |
| `surecart_licensing_endpoint` | filter | `Client::endpoint()`, `Client.php:217` | Same, when the constant isn't defined. Default `https://api.surecart.com`. |
| `surecart_client_license_form_action` | filter | `Settings::form_action_url()`, `Settings.php:82` | Sets the license form's `action` URL (`Settings.php:226`). |
| `surecart_licensing_is_local` | filter | `Client::is_local_server()`, `Client.php:332` | None today. Nothing in the SDK or MAC Members calls `is_local_server()`. |
