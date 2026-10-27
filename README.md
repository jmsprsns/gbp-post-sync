# Google Business Profile Post Sync

Publishes WordPress posts as Business Profile updates.

By [Content Powered](https://www.contentpowered.com/wordpress-plugins/).

## What it does

When a post is published, it's shared as a "What's new" update on the Google Business Profile locations you pick, with an optional button (Learn more, Book, Order online, Buy, Sign up, Call now) and the featured image. Edits are pushed to Google, and unpublishing or trashing a post can remove it there too. Everything lives under **Content Powered > Google Business Profile Post Sync**:

- **Connection**: Google account, locations, and the one-time Google Cloud setup steps.
- **Posting**: post types, automatic sharing, the text template, button, image, UTM tags, a live preview of the latest post, and update/removal sync.
- **Activity**: 30-day counts and a log of every share, update, removal and failure (with Google's own error message).

The editor sidebar gets a **Google Business Profile** box: a "share when published" checkbox, custom text for that post, where it's live on Google, **Share now** and **Remove from Google**.

## How sharing works

- Publishing queues a WP-Cron job 10 seconds out (`gbp_post_sync_share`), so the block editor's second request (the sidebar box) is saved before anything is sent. The editor box polls its status and runs a due job itself, so quiet sites don't wait for cron.
- Each location gets its own LocalPost (`POST mybusiness.googleapis.com/v4/{location}/localPosts`). What's on Google is stored in `_gbp_post_sync_posts` (location => post name, link, payload hash).
- Edits queue `gbp_post_sync_update`, which PATCHes only locations whose payload hash changed. Removals (`gbp_post_sync_remove`) carry the Google post names, so they still work after the post is deleted.
- A per-post lock (an `INSERT IGNORE` row in `wp_options`) stops two jobs from creating duplicates.
- Never auto-shared: imports (`WP_IMPORTING`), posts dated more than two days ago, password-protected and non-public posts. An explicit tick or Share now in the editor overrides the first two.
- `gbp_post_sync_payload` filters the LocalPost fields before they're sent.

## Security notes

- Each site uses its own Google Cloud OAuth client. The flow uses PKCE and a single-use `state` tied to the admin who started it (stored hashed, 15 minutes), so a crafted callback link can't attach someone else's Google account.
- The refresh token, cached access token and client secret are encrypted with libsodium secretbox (bundled with WordPress since 5.2) using a key derived from `AUTH_KEY`/`AUTH_SALT`. `GBP_POST_SYNC_CLIENT_ID` and `GBP_POST_SYNC_CLIENT_SECRET` in wp-config.php keep the client out of the database.
- Every request goes to a fixed Google host with redirects off. Location and post names are checked against strict patterns before they're put in a URL.
- Settings, connect, disconnect and log actions need `manage_options` and a nonce. Share now and Remove need `edit_post` on the post plus the post type's `publish_posts`, since they publish to the public profile.
- Google's error messages are stored as plain text and escaped on output. The settings page's preview data is printed with `JSON_HEX_TAG | JSON_HEX_AMP`.

## Automatic updates

Updates come from https://github.com/jmsprsns/gbp-post-sync through the bundled [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) (PUC). Every 12 hours each site checks the repo's latest GitHub release. When there's a newer version, it appears in Dashboard > Updates like any other plugin, with one-click updates and auto-updates.

The repo URL is the `Update URI` header in `gbp-post-sync.php`. That header also stops WordPress.org from offering a same-slug plugin as an "update" that would overwrite this one.

### Releasing an update

1. Bump `Version:` in the `gbp-post-sync.php` header. It's the only place the version lives.
2. Commit and push to `main`.
3. Publish a release whose tag matches that version:

   ```bash
   gh release create v1.0.1 --title 1.0.1 --generate-notes
   ```

Sites pick it up on their next check. To check right away, use "Check for updates" on the plugin's row in the Plugins screen, or the button on its settings page. The release notes become the changelog in the "View details" popup. The description, requirements and "Tested up to" come from `readme.txt`.

If the tag and the `Version:` header disagree, sites will keep being offered the same update, so bump the header before you tag.

If a repo has no releases, PUC falls back to the highest version tag, then to the `Version:` header on `main`.

### Development copies

Installing an update replaces the whole plugin folder, so update checks are off in any copy with a `.git` folder. That stops an update from wiping your working tree. To test updates in a checkout anyway, or to turn them off somewhere without git, add this to `wp-config.php`:

```php
define( 'GBP_POST_SYNC_DEV_MODE', false ); // false = check for updates, true = never check
```

### Private repos

Public repos need nothing extra. For a private repo, create a fine-grained GitHub token limited to that one repo with **Contents: Read-only** access, and add it to each site's `wp-config.php`:

```php
define( 'GBP_POST_SYNC_GITHUB_TOKEN', 'github_pat_...' );
```

Never put a token in the plugin's own code: every site that installs the plugin would get a copy. For plugins sold or shipped to customers, use a public repo, or serve updates from your own server the way HighGround does.

Unauthenticated GitHub API requests are limited to 60 an hour per server IP. Each check makes a few requests every 12 hours, so this only matters when many sites share one IP. A token raises the limit.

## Structure

```
gbp-post-sync.php                       Plugin header, constants, boot
uninstall.php                           Revokes the Google token and deletes the plugin's data
readme.txt                              WordPress readme; feeds the "View details" popup
includes/
  class-gbp-post-sync-plugin.php        Main singleton and settings access
  class-gbp-post-sync-admin.php         Settings page, OAuth connect/callback/disconnect, Plugins-screen link
  class-gbp-post-sync-editor.php        Editor sidebar box, Share now / Remove AJAX
  class-gbp-post-sync-sync.php          Publish/edit/trash hooks, cron jobs, payload builder, lock
  class-gbp-post-sync-google.php        OAuth tokens and the Business Profile API calls
  class-gbp-post-sync-crypto.php        Encryption for the client secret and tokens
  class-gbp-post-sync-log.php           Activity log
  class-gbp-post-sync-menu.php          Shared top-level "Content Powered" menu
  class-gbp-post-sync-feedback.php      Shared Submit Feedback and Report a Bug pages
  class-gbp-post-sync-updater.php       GitHub update checks (PUC wiring)
  class-gbp-post-sync-icons.php         Inline Lucide (and Simple Icons) helper
assets/
  admin.css, admin.js                   Loaded only on the settings page
  editor.css, editor.js                 Loaded only in the editor for switched-on post types
  icon.svg                              Icon on the update screens
  content-powered.svg                   Sumo logo for the Content Powered menu
icons/                                  Lucide icons used by the plugin (plus LICENSE)
plugin-update-checker/                  Plugin Update Checker 5.7 library
```

## The Content Powered menu

Every plugin made from this skeleton puts its settings page under one shared top-level **Content Powered** menu, so several installed plugins line up as Content Powered > Link Fixer, Content Powered > Fast Social Share Buttons, and so on.

Each plugin carries the same `class-gbp-post-sync-menu.php`. Whichever plugin loads first registers the menu (slug `content-powered`, sumo logo from `assets/content-powered.svg`); the others find it already there and only add their own page. The top-level link opens the first plugin's page. Keep the slug as it is, or plugins made from older copies would end up in a second menu.

The logo needs a single fill colour, because WordPress repaints menu icons to match each user's admin colour scheme.

## Feedback and bug reports

Every plugin also carries `class-gbp-post-sync-feedback.php`, which adds **Submit Feedback** and **Report a Bug** to the bottom of the Content Powered menu. The links appear once however many Content Powered plugins are active: each plugin adds itself to a shared list, and only the newest copy of the class (by its `VERSION` constant, the first loaded on a tie) adds the pages. With more than one plugin active, the form asks which plugin the message is about.

Messages go from the site's server to `https://www.contentpowered.com/wp-json/contentpowered/v1/plugin-feedback/` and show up on contentpowered.com under Our Plugins > Bug Reports and Our Plugins > Feedback. Each message carries a single-use secret, and contentpowered.com fetches its hash back from the site's `admin-ajax.php` to confirm the site really sent it. A site can send one message every 24 hours, and the page shows how long until the next one. The sidebar lists exactly what gets sent.

When you change the file, bump `VERSION` and copy it into the other plugins (with the names swapped), so the newest copy is the one that runs. To send messages to a local copy of contentpowered.com instead, add this to `wp-config.php`:

```php
define( 'CONTENT_POWERED_FEEDBACK_ENDPOINT', 'http://contentpowered/wp-json/contentpowered/v1/plugin-feedback/' );
```

## Update screen icon

To use your own icon on the update screens, replace `assets/icon.svg`. PNGs work too: delete the SVG and add `assets/icon-128x128.png` and `assets/icon-256x256.png`.

## Icons

The plugin bundles the [Lucide](https://lucide.dev/icons) icons it uses in `icons/`. Print one inline with:

```php
echo GBP_Post_Sync_Icons::get( 'package', 20 );           // name, size in px
echo GBP_Post_Sync_Icons::get( 'shield-check', 16, 'my-class' );
```

Icons use `currentColor`, so they take on the text colour around them. The name is the file name in `icons/` without `.svg`.

## Credits and licenses

This plugin uses these third-party assets under their own licenses:

- **[Lucide](https://lucide.dev) icons** (`icons/`): ISC License, Copyright (c) 2026 Lucide Icons and Contributors. Icons derived from [Feather](https://feathericons.com) are under the MIT License, Copyright (c) 2013-present Cole Bemis. Both licenses require their copyright and permission notice to ship with every copy, so keep `icons/LICENSE` alongside the icons.
- **[Simple Icons](https://simpleicons.org) brand icons** (`icons/simpleicons/`): CC0 1.0 (public domain). Brand names and logos are trademarks of their respective owners. Using one doesn't imply endorsement.
- **[Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)** (`plugin-update-checker/`): MIT License, Copyright (c) Janis Elsts. See `plugin-update-checker/license.txt`.

The plugin itself is licensed under GPL-2.0-or-later. See `LICENSE`.
