=== MLSImport: IDX Plugin & MLS Plugin for Real Estate Listings ===
Contributors: mlsimport
Tags: idx, idx-plugin, mls, real-estate, wordpress-idx
Requires at least: 5.2
Tested up to: 7.0.2
Requires PHP: 7.4
Stable tag: 7.2.1
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

IDX and MLS plugin for WordPress. Import and sync real estate listings from 800+ MLS via the RESO Web API, on any theme.

== Description ==

[youtube https://www.youtube.com/watch?v=3WSb8BDA07c]

Connect your real estate website with <a href="https://mlsimport.com/" target="_blank">MLSImport.com plugin</a> to effortlessly sync, import, and manage MLS property listings, providing your users with up-to-date and accurate real estate information directly on your site.

MlsImport is an IDX and MLS plugin for WordPress that downloads and synchronizes real estate listings from 800+ MLS (Multiple Listing Services) using the modern RESO Web API. It works by calling the MLSimport API and your MLS API, reading the data from your Multiple Listing Association, and transforming it into a WordPress-like format. You will need a valid access token from your MLS to use this plugin and an MLSImport account. The plugin now runs on ANY WordPress theme through its built-in standalone mode, and additionally offers deep, native integration with <a href="https://wpresidence.net/" target="_blank">WPResidence</a>, Houzez, Real Homes, and <a href="https://wpestate.org/" target="_blank">WpEstate</a>.

<h3>Works with any WordPress theme (IDX for Gutenberg &amp; Elementor)</h3>

MLS Import now includes a built-in standalone mode that renders its own property search, IDX listing cards, half-map search, single-property pages and agent profiles on ANY WordPress theme — no dedicated real estate theme required. Place listings anywhere on your site with shortcodes, Gutenberg blocks or Elementor widgets.

<h3>Deep integration with the top real estate themes</h3>

Prefer a dedicated real estate theme? MLS Import also offers deep, native integration with <a href="https://wpresidence.net/" target="_blank">WPResidence</a>, <a href="https://wpestate.org/" target="_blank">WpEstate</a>, Houzez and Real Homes, importing MLS listings directly into each theme's own property format so they behave exactly like listings you added by hand.

If you are the owner of a real estate theme and want to be integrated with MLSimport, feel free to contact us.

<h3>A modern WordPress IDX plugin built on the RESO Web API</h3>

MLS Import is a modern WordPress IDX plugin that links your website to the MLS through the RESO Web API. Instead of embedding a slow third-party IDX iframe or relying on the old RETS protocol, it imports and synchronizes real MLS listings directly into your own WordPress site — for faster pages, full control of your content, and better SEO because the listings live on your own domain.

<a href="https://mlsimport.com/reso-standard-explained-in-detail/" target="_blank">RESO Standard Explained in Detail</a> 


<h3>Direct Integration</h3>

Our MLS solution smoothly brings your chosen properties directly into your WordPress website. Additionally, it optimizes performance by serving images from a content delivery network (CDN).

<h3>Sync Listings Automatically</h3>

MLS Import regularly updates your published property listings to match the most recent changes in MLS listings, ensuring that your website always displays accurate and up-to-date information.

<h3>800+ MLS Markets Coverage</h3>

We can establish connections with more than 800 MLS markets in the United States and Canada. If you don't see your specific MLS listed, please get in touch with us, and we'll gladly assist you.




== CONNECT WITH US ==
<a href="https://www.youtube.com/@mlsimport3513" target="_blank">YouTube</a>
<a href="https://www.facebook.com/MLSImport" target="_blank">Facebook</a>
<a href="https://twitter.com/MLSImport" target="_blank">Twitter</a>
<a href="https://www.linkedin.com/company/mlsimport/" target="_blank">LinkedIn</a>


== Screenshots ==


== Frequently Asked Questions ==

=What themes can I use with the MLS Import plugin?=
Any WordPress theme. MLS Import's built-in standalone mode renders its own property search, listings, maps, single-property pages and agent profiles on any theme, placed with shortcodes, Gutenberg blocks or Elementor widgets. For a deeper, native integration it also imports directly into the best real estate themes — <a href="https://wpresidence.net/" target="_blank">WpResidence</a>, <a href="https://wpestate.org/" target="_blank">WpEstate</a>, Houzez, and Real Homes.

=Is MLSImport a WordPress IDX plugin?=
Yes. MLSImport is a modern WordPress IDX plugin and MLS plugin: it connects to your MLS through the RESO Web API and imports IDX/MLS listings directly into WordPress as native content — unlike legacy IDX plugins that embed a third-party iframe. That means faster pages, full control over your listing content, and better SEO, because the listings live on your own domain.

=What is the difference between an IDX plugin and MLSImport?=
Traditional IDX plugins display MLS data inside an embedded frame hosted elsewhere, so the listings never really belong to your site. MLSImport imports and synchronizes the MLS listings into your own WordPress database, then keeps them up to date automatically — giving you IDX-style property search and listing pages that are fully yours.

=Will you import thousands of images?=
No, we do not import thousands of images (or any image). We ensure images are served from your MLS, so your server is not overcrowded with photos.

=Will my local MLS work with MLS Import?=
MLS Import plugin can connect and import from all RESO-ready MLS. Check this <a href="https://certification.reso.org/" target="_blank">supported list</a>. If your MLS needs to be added to the list but you would like to know if it's possible to use it, contact us to learn more.

As a member, the MLS must use his API access to use the MLS import plugin. That is because, as a solution, the MLS Import plugin imports/replicates the properties directly into the MLS member domain. 
=Is MLS Import SEO friendly?=
With MLS import, you can effectively manage your real estate website's content, which helps you create a website optimized for search engines. Additionally, you can conveniently handle and monitor all generated leads.

=How many listings can I import?=
You control how many listings you want to import and have special filters available to select which properties.


== Installation ==
1. Review our video tutorials here https://www.youtube.com/@mlsimport3513
2. Install and enable the IDX plugin by searching for 'MlsImport' in your WordPress plugin install page
3. Signup with <a href="https://mlsimport.com/">MLSImport.com</a> and get your credentials
4. Make sure you have valid MLS access credentials
5. Under your WordPress admin's "MLS Import Settings" section, use the MLSImport.com credentials, select your MLS from the list, and add your MLS access credentials.
6. If your MLS requires "activation via ticket," open a ticket in your MLSimport.com dashboard, and we will activate it.
7. Go to the "Select Import Fields" tab and select what fields you want to import 
8. Create and publish a new 'MLS Import Task". Pick your property type, status, counties, or cities, and click start import when ready.

== Use of 3rd party services ==
This IDX plugin will connect via REST API to mlsimport.com and https://requests.mlsimport.com to get real estate data from your MLS. It will send your connection details and reply with real estate prepared to be imported into your website. 
Automated daily diagnostic report (telemetry): once per day, installations connected to a valid MLSImport account send an automated diagnostic "heartbeat" to the MLSImport service (https://requests.mlsimport.com). It helps us monitor the health of your installation and lets our support team detect problems — such as a stopped cron job, a failing MLS connection, or imports that produce no listings — before they affect your site. The report contains: an anonymous installation ID, your MLSImport account name and site URL, plugin / PHP / WordPress versions and the active theme, counts of listings imported / updated / deleted over the last 7 days, sync and MLS-feed health, the number of active listings and basic data-completeness percentages, your import-task settings, the dates of recent MLSImport admin-page activity, and onboarding progress. It does not collect any information about your site's visitors. Installations that are not connected to an MLSImport account send no telemetry.

Read the terms and conditions of using MLSimport APIs here : https://mlsimport.com/terms-conditions/


== For developers: Standalone hooks ==
In standalone (theme-agnostic) mode, the plugin renders its own search, cards, half-map, single-property page and agents on any theme. That front-end exposes a WooCommerce-style layer of action and filter hooks so themes and add-ons can extend, reorder, restyle, and react to MLSImport without editing plugin core: one `add_filter`/`add_action` applies on every surface (shortcode, block, Elementor, PHP template), because they all route through one section dispatcher and one query executor.

See `docs/standalone-hooks-guide.md` for the detailed guide (a worked example for every hook), `docs/standalone-hooks.md` for the at-a-glance signature tables, `includes/standalone/mlsimport-hooks.php` for the canonical machine-readable list, and `docs/adr/0006-standalone-hook-conventions.md` for the naming/argument conventions. Themes override templates by copying them into a `mlsimport/` sub-directory (the WooCommerce pattern).

== Changelog ==
= 7.2.1 =
* The "Data last updated" date on a property page now shows the hour and minute too (for example "March 4, 2026 1:03 am"), converted to your site's timezone. The "Updated" date in the property title bar and overview shows the same time.
* Fixed the Connections tab still showing your old MLS limit after a plan change - the number of MLS connections your plan allows is now re-checked with your MLSImport account every time you open the tab, so an upgrade on mlsimport.com unlocks "+ Add MLS" right away without signing in again.

= 7.2 =
* Multiple MLS connections on one site - you are no longer limited to a single MLS. A new Connections tab lists every MLS you have connected with its live status, when it was last tested, and how many listings it brought in; you can add another MLS from the same screen, test or disconnect one at a time, and drag the rows to set which MLS takes precedence. How many connections you can add is set by your MLSImport plan.
* Adding a second MLS does not disturb the first - each connection keeps its own credentials, its own field mapping and its own sync settings, and your existing MLS is carried over automatically on update as your first connection, with its current mapping and settings intact. Nothing needs to be re-entered or re-imported.
* Each import task belongs to one MLS - when you create an import task you pick which connection it pulls from, and its search filters come from that MLS's own values. Existing tasks keep working exactly as before.
* One broken MLS no longer stops the others - if a connection's credentials expire or its provider goes down, only that connection's tasks pause (with the reason recorded); tasks on your other MLSs keep importing on the same hourly run. Daily reconciliation is likewise done per connection, so a feed that cannot be read is skipped instead of putting your listings at risk.
* The same property from two MLSs is shown once - when overlapping feeds carry the same address twice, the copy from your higher-priority connection is displayed and the other is hidden from search, sliders, maps and feeds. Hidden never means deleted: if the shown copy is removed or unpublished, the other one is brought back automatically.

= 7.1.2 =
* Fixed feature links on a listing all pointing to one empty page - when an MLS sends a multi-value field (lot features, appliances, views...) as one comma-glued text instead of a list, each value now becomes its own term with its own working archive page. A one-time automatic repair also splits the glued terms that earlier imports already created, so existing sites are fixed without re-importing.
* Updated the bundled Action Scheduler library to 3.9.3 - removes the PHP 8.4 deprecation notices it produced on every admin page (which could quickly fill debug.log on sites with logging enabled) and slims the plugin package.

= 7.1.1 =
* Fixed featured properties losing their images - saving a property in wp-admin could blank its internal ListingKey (WordPress treats the meta keys ListingKey and the theme custom field listingkey as the same database row), so the next import could not find the listing and imported it again as a duplicate; the new post got the photos while the original featured post kept dead image references. The listing identity is now stored in protected meta (_mlsimport_listing_key) that theme edit screens cannot touch, theme field projection no longer writes the identity as an editable custom field, the importer refuses to create a listing without an identity, and a one-time automatic migration copies the identity for all existing listings so they keep updating in place.
* Fixed listing deletion removing a property's images before the property itself - when the final post deletion failed, the site was left with a published listing whose gallery pointed at deleted images. Deletion now removes the property post first and deletes its photos only after that succeeds, on both the reconciliation path and the admin delete tool; a failed deletion now leaves the listing completely untouched (and the delete tool no longer records a deletion that did not happen).

= 7.1 =
* Import task health at a glance - new status column in the Import Tasks list showing each task's live state (synced, importing, sync overdue, stuck, failed, auto-sync off, never imported).
* Sturdier imports - manual imports are now resumable, chunked runs with a watchdog; the hourly sync picks tasks in starvation order so no task is left behind; a completed manual import now seeds the sync watermark so new tasks start hourly-syncing right away.
* Automatic incident alerts and import-health heartbeats to the MLSImport service, plus automatic token / activation recovery - so a stopped cron or failing import can be detected before it affects your site.
* Import fields are gathered automatically - as soon as your MLSImport account and MLS connection are both confirmed, the plugin fetches your MLS's field metadata silently in the background instead of making you wait on the "Stand By" page.
* Setup wizard fixes - an unrecognised theme no longer fails the system-requirements check (standalone mode works with any theme), the theme selector now pre-selects the site's own detected theme instead of defaulting to the first option, and the PHP memory requirement label was corrected to 256M.
* Redesigned admin - the whole plugin admin was restyled to match the mlsimport.com portal design: unified field sizing and focus states, one active-state colour, and a cleaned-up Tools tab.
* Provider adapters - dedicated Bright MLS and Centris (Quebec) adapter classes, and provider credential fields now only appear for the selected MLS's provider family.
* Internal rebuild of the import engine - import task execution, daily reconciliation, stored-listing writing and field-mapping persistence were consolidated into dedicated, unit- and integration-tested modules.

= 7.0.7 =
* Fixed the "search your MLS" field in the setup wizard not offering any suggestions — the wizard's scripts were not being loaded on the page, so the MLS autocomplete never started.

= 7.0.6 =
* Fixed a fatal error on plugin activation ("Failed opening required ... myclabs/deep-copy") caused by a development autoloader being included in the release package.

= 7.0.4 =
* Standalone (theme-agnostic) mode — the plugin can now render its own real-estate front-end on ANY WordPress theme, not only WpResidence, WpEstate, Houzez and Real Homes. It ships its own property search, listing cards, half-map search, single-property page, saved-properties view and agent / team profiles.
* Page-building blocks — Property List, Property Slider, Featured Property, Map with Listings, Half Map, List Properties by ID, Team Directory, Category Slider and Display Categories, each available as a shortcode, a Gutenberg block and an Elementor widget, all sharing the same rendering, filters and card-style controls.
* Category & taxonomy widgets — display any listing taxonomy (city, type, status, …) as image tiles, using a new per-term featured image and per-term HTML description.
* Developer hooks — a WooCommerce-style layer of action / filter hooks lets themes and add-ons extend, reorder and restyle every standalone surface without editing plugin core; templates can be overridden by copying them into a theme's `mlsimport/` folder. See the standalone hooks guide under `docs/`.
* PropTx / AMPRE (TRREB) MLS provider support.
* Reconciliation & sync hardening — fixed cases where reconciliation could delete live or standalone listings when a status taxonomy was remapped, and stopped the add / delete / add cycling of in-filter listings during sync.
* Import fidelity — fixed the gallery being wiped on update when the media was unchanged, routed hidden-from-public fields to the correct metabox and corrected the RealEstateListing JSON-LD (provider and datePosted).
* Numerous front-end polish fixes across the property page, maps, mortgage calculator, search form, contact forms and accent-colour theming.

= 6.3.8 =
* PropTx / AMPRE (TRREB) MLS provider wiring; featured-image / gallery fix.
* Activity banner now counts unique properties.

= 6.3.7 =
* Maintenance release.

= 6.3.6 =
* Trestle / Cotality readable enum labels — status and category values from Trestle can now come through as human-readable text ("Active Under Contract" instead of "ActiveUnderContract", "Commercial Lease" instead of "CommercialLease"). Status matching, status protection and the daily reconciliation now treat the spaced and non-spaced forms as equal, so existing import tasks keep working and live listings are not wrongly skipped or deleted during the switch. Requires the matching MLSImport backend update to take effect.

= 6.3.1 =
* Import History: added search by Listing ID or ListingKey.
* Activity banner: now always visible to administrators (not only when there was activity in the last 24 hours); clearer "property/properties" wording and a "Task Name:" label in the per-task breakdown.
* Bright MLS support — added Bright MLS as a dedicated provider  with its own Client ID / Client Secret credential fields, correct credential gating in the admin and onboarding "Account" step, and a per-MLS field-correlation map that aliases RESO field names to Bright's own names (e.g. CountyOrParish to County) when building the request URL.
* Multi-select location filters — new searchable, type-to-filter dropdown component; City, County and Postal Code (ZIP) can now take multiple values (sent to the backend as RESO "or" groups), including multi-ZIP support in import tasks.
* Daily telemetry / diagnostic heartbeat — connected installs send one structured health heartbeat per UTC day to the SaaS user-activity endpoint. Payload covers an anonymous install ID, account/site, plugin/PHP/WordPress versions and active theme, 7-day import/update/delete counts, sync and feed health, active-listing count and data-completeness %, task settings, recent admin activity dates, and onboarding progress. No visitor data is collected, and nothing is sent if the site is not connected to an MLSImport account. Privacy disclosure added to the 3rd-party services section.
* Activity log & History view — new wp_mlsimport_activity database table (with a versioned dbDelta upgrade path) logging every listing import / update / delete with listing ID, key, title, URL and source import task; new History admin page with a list-table view of recent activity.
* Dashboard top banner — new banner on the admin pages surfacing import counts / status at a glance.
* Deactivation survey — on plugin deactivation, a survey modal collects feedback on why the user is leaving.
* Fixes & maintenance — ThemeImport image/gallery handling fix for WPResidence 5.0 (#532, later partly reverted); Composer autoload / installed.json regeneration cleanup; Tested up to WordPress 7.0.

= 6.1.4 =
Fix for ABOR on stale delete

= 6.1.2 =
Fix for Realtor.ca on property room parsing

= 6.1.1 =
* Added BuyerAgentMlsId import option
* Updated labels from MLSimport item to Import task
* Improved formatting for MLS fields using comma separation
* Enhanced messaging for starting/stopping imports
* Improved error handling


=  5.8.6 =
* Fix on importing into Houzez theme
* fix with keep true if mlsImportItemStatusDelete meta is empty
* fixed caps vs non caps on custom post meta

=  5.7 =
* Release on Wordpress plugin directory
