# Publishing on WordPress.org

GitHub publication does not automatically submit or publish a plugin on WordPress.org.

## Before submitting

1. Read the [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/).
2. The display name is Pledgly Donations. The requested slug, package folder, and text domain are all `pledgly-donations`.
3. Confirm that `Contributors` in `readme.txt` contains your actual WordPress.org username; a matching GitHub username alone is not sufficient.
4. Install the built ZIP on a clean WordPress staging site. Test supported PHP versions, payment flows, subscription renewal/cancellation, delayed payments, refunds, duplicate webhooks, donor access, and coexistence with another theme/plugin stack.
5. Run the official [Plugin Check](https://wordpress.org/plugins/plugin-check/) from **Tools → Plugin Check** and resolve findings. Automated project tests are not a substitute for this check or manual review.
6. Add `Tested up to` to `readme.txt` only after testing that WordPress version. Keep the main PHP version, version constant, stable tag, and changelog aligned.
7. Validate `readme.txt` with the [official readme validator](https://wordpress.org/plugins/developers/readme-validator/). Keep Stripe service disclosures and links to its terms and privacy policy.
8. Build with `python3 scripts/build.py`. Inspect the ZIP: one `pledgly-donations/` root, only distributable files, no Git metadata, test fixtures, credentials, or donor data.

## Submit for review

1. Sign in to your WordPress.org account.
2. Open [Add your plugin](https://wordpress.org/plugins/developers/add/).
3. Upload the corrected ZIP to the existing submission.
4. Reply to the review email and request the slug `pledgly-donations`.

## After approval

The Dutch catalog is kept in `languages/pledgly-donations-nl_NL.po` for contribution to the plugin’s project on [translate.wordpress.org](https://translate.wordpress.org/). Submit the Dutch strings once the project is available. WordPress distributes approved translations as language packs.

WordPress.org supplies an SVN repository for releases. Keep using GitHub for development; publish complete release files to SVN `trunk/` and a matching version under `tags/`. Keep `Stable tag` aligned. Consult [Using Subversion](https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/) before the first release.

Directory icons, banners, and screenshots belong in the directory's SVN `assets/` folder. They are separate from this plugin's runtime `assets/` folder. Only list screenshots that actually exist.

Automated deployment from GitHub can be added after approval and after release credentials are configured as repository secrets. Never commit SVN passwords or Stripe keys.
