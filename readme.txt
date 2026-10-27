=== Google Business Profile Post Sync ===
Contributors: contentpowered
Author: Content Powered
Author URI: https://www.contentpowered.com/wordpress-plugins/
Plugin URI: https://www.contentpowered.com
Tags: google business profile, google my business, local seo, gbp posts, auto post
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publishes WordPress posts as Business Profile updates.

== Description ==

Every time you publish a post, Google Business Profile Post Sync shares it as a "What's new" update on your Google Business Profile, so it shows up in Google Search and Maps next to your business.

* **Automatic sharing.** New posts go out when they're published, including scheduled posts. Authors can switch it off for a single post, or share an older post with one click.
* **Your words, your button.** A template builds the update from the title, excerpt, link and site name. Authors can write their own text per post. Add a Learn more, Book, Order online, Buy, Sign up or Call now button.
* **Featured images.** The post's featured image goes along with the update.
* **Several locations.** Share to one location or all of them.
* **Stays in step.** Edits update the post on Google. Optionally, unpublishing or trashing a post removes it from Google.
* **Live preview.** See how your latest post will look on Google as you change the template.
* **Activity log.** Every share, update and removal, with links to the live post on Google and Google's own error messages when something fails.
* **Private by design.** Your site connects through your own Google Cloud project. Tokens and the client secret are stored encrypted, and nothing passes through anyone else's server.

Find it under Content Powered > Google Business Profile Post Sync in the admin menu.

Found a bug or have an idea? Use Content Powered > Report a Bug or Content Powered > Submit Feedback to send it straight to us.

= Credits =

* Icons by [Lucide](https://lucide.dev), used under the ISC License. Icons derived from [Feather](https://feathericons.com) are under the MIT License. The full license text is in `icons/LICENSE`.
* Brand icons by [Simple Icons](https://simpleicons.org), CC0 1.0. Brand names and logos are trademarks of their respective owners.
* Updates delivered by [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker) by Janis Elsts, MIT License.

Google and Google Business Profile are trademarks of Google LLC. This plugin isn't made or endorsed by Google.

== Installation ==

1. Upload the `gbp-post-sync` folder to `/wp-content/plugins/`.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to Content Powered > Google Business Profile Post Sync and follow the setup steps on the Connection tab:
   1. Create a Google Cloud project and request Business Profile API access.
   2. Enable the My Business Account Management, My Business Business Information and Google My Business APIs.
   3. Publish the OAuth consent screen and create a "Web application" OAuth client with the redirect URI shown on the page.
   4. Paste the client ID and secret, save, and press Connect with Google.
4. Tick the locations to share to, then check the Posting tab.

Updates arrive through the normal WordPress Updates screen, and you can turn on auto-updates from the Plugins screen.

== Frequently Asked Questions ==

= Why do I need my own Google Cloud project? =

Google only lets approved projects use the Business Profile APIs. With your own project, your site talks to Google directly: no third-party server ever sees your login or tokens.

= Google says my quota is 0 =

New projects have no Business Profile API quota until Google approves the access request (step 2 of the setup). Approval can take a few days or longer.

= The connection stops working after a week =

Your OAuth consent screen is still in "Testing", where Google expires tokens after 7 days. Publish it to "In production" and connect again.

= Can I keep the client secret out of the database? =

Yes. Define `GBP_POST_SYNC_CLIENT_ID` and `GBP_POST_SYNC_CLIENT_SECRET` in wp-config.php.

= Will importing posts flood my profile? =

No. Imported posts are never shared, and neither are posts published with a date more than two days in the past, unless someone ticks the box or presses Share now in the editor.

= Can I change what's sent? =

Use the `gbp_post_sync_payload` filter. It receives the LocalPost fields (summary, callToAction, media, topicType, languageCode) and the WordPress post.

== Changelog ==

See https://github.com/jmsprsns/gbp-post-sync/releases for the full release history.
