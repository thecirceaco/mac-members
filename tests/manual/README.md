# Manual test members

WP-CLI scripts that fill a test site with members for the members table, and remove them afterwards. They aren't part of the unit suite or the release ZIP. They were used on test.circea.dev on 2026-09-29.

- `add-test-members.php` adds 250 members, `mmtest-0001` to `mmtest-0250`, spread over the four statuses. About half also get an Officer, Trustee or Shop Steward role, which the script creates when it's missing. The addresses end in `@example.org`, which receives no mail, and emails are off while the script runs.
- `add-test-last-logins.php` gives about four in five of them a made-up last login in MAC Core's `mac_core_last_login`, for the Last Login column.
- `remove-test-members.php` deletes every user the first script marked with the `mac_members_test_user` meta, and the union roles it added once nobody holds them.

Run them through WP-CLI from stdin, so nothing is written on the server. On a Rocket.net site, from this folder, with the SSH key in the 1Password agent:

```bash
cat add-test-members.php | C:/Windows/System32/OpenSSH/ssh.exe -i '~/.ssh/mac.pub' -o IdentitiesOnly=yes <user>@<ip> 'cd ~/public_html && /opt/alt/php84/usr/bin/php /usr/local/bin/wp eval-file -'
```

The scripts touch only the users they create. Real members on the site stay as they are.
