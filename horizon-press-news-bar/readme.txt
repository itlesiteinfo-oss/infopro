=== Horizon Press News Bar ===
Contributors: horizonpress
Tags: news, ticker, breaking news, bar, headlines
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 2.4.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A fixed bottom news bar showing the posts published inside a sliding time window, filtered by categories, tags and exclusions.

== Description ==

Horizon Press News Bar displays a fixed bar at the bottom of the site with the posts published inside a sliding time window (the last 24 hours by default), filtered by categories, tags and exclusions.

**Business rule:** a post is shown when it is published **and** inside the time window **and** matches the content filters. Never "since midnight", never the modification date, never a random order. When no post matches, no visible bar is rendered at all.

Main features:

* sliding window in minutes, hours or days, computed in UTC on `post_date_gmt`;
* included / excluded categories, tags, excluded posts, maximum number of items, chronological order;
* colours, font size, height, z-index, customisable label at the start or the end of the line;
* "reserve" layout (page content is pushed up) or "overlay" layout;
* server cache built on transients with epoch-based invalidation, TTL between 30 and 600 seconds;
* hybrid mode: server rendering plus a conditional REST refresh that survives page caches;
* public endpoint `GET /wp-json/hprnb/v1/items` with ETag and 304 support;
* `[hprnb_news_bar]` shortcode with a single-render guarantee;
* visibility rules per context and per post or page ID;
* optional features, disabled by default: relative time, thumbnails, separator, ticker (marquee, rotate, manual), pause on hover, close button, remembered dismissal;
* mandatory Pause / Play button for any automatic animation, `prefers-reduced-motion` support, 44×44 targets, named region, `aria-live="off"`;
* live preview in the settings page, JSON import / export, reset;
* RTL support and internationalisation (French translation bundled);
* no external dependency, no outbound network request, no telemetry, no jQuery, no custom table, no cron.

== Installation ==

1. Upload the `horizon-press-news-bar` folder to `/wp-content/plugins/`, or install the ZIP from Plugins → Add New.
2. Activate the plugin.
3. Open Settings → News Bar.

The plugin requires PHP 8.0 (8.3 or newer recommended) and WordPress 6.6; below these versions it refuses to activate with a clear message.

== Frequently Asked Questions ==

= The bar does not show up =

Check that at least one post is published inside the configured time window and matches the filters. The preview on the settings page says "No post matches these criteria" in that case. Also check the scope (Visibility section) and the ID exclusions.

= A page cache serves an outdated bar =

The hybrid mode (default) compares the age of the server-rendered HTML with the stale threshold and performs at most one REST request per page load to refresh the bar. No cache-plugin specific setting is needed.

= The bar covers an element of my theme =

Use the "reserve" layout (default). In "overlay" mode the bar may cover a fixed element of a theme or of another plugin.

= Can I add custom CSS? =

Not from the settings page (there is deliberately no free CSS field). Every class starts with `hprnb-` and colours / sizes are CSS variables: use your theme stylesheet, or override the templates in `{theme}/horizon-press-news-bar/`.

= Where is the data stored? =

A single `hprnb_settings` option, two technical options (`hprnb_cache_epoch`, `hprnb_schema_version`) and short-lived transients. No personal data, no cookie; the remembered dismissal uses `localStorage`.

== Changelog ==

= 2.4.2 =
* "Discover" design: shorter card — the pill takes the first line beside the picture and the headline starts on the second; the height follows the picture (116px instead of 152px by default).
* Collapsed, it is the same strip as the flowing card: the pulsing red dot and the first line of the headline.
* Fixed the card's picture jumping on every rotation (the entrance animation made the item a containing block).

= 2.4.1 =
* Fixed: saving the settings hid the bar on the whole site (the per-profile page types were posted under the wrong name). Existing installs are repaired at upgrade.
* "Discover" design: the image now starts the line under the pill, the headline is two sizes up on as many lines as the image holds, and the close button stands alone in a tab above the corner of the card.

= 2.4.0 =
* Page types per profile: desktop and mobile each narrow where the bar may appear.
* The bar can sit inside the article — before a paragraph, after one, or before the last N — with its own paragraph number per profile.
* Collapsing from 768px, with the same three moments as on mobile; the bar leaves a small tab to bring it back.
* Second mobile design, "Discover": a heading row, then the headline beside a large landscape image.

= 2.3.0 =
* When the bar appears: right away, after a scroll distance (recommended on articles), after a share of the page, or near its end. No space is reserved until then.
* When the mobile strip collapses: on the way down past a threshold, at the threshold for good, or always collapsed.
* Pause and Close can float just above the bar, and each can be hidden on mobile.
* Accent edge along the top of the bar, filled by the rotation progress.
* Fixed the Pause button staying stuck on touch screens (an emulated hover kept the rotation paused).

= 2.2.0 =
* Mobile card: close above pause in a single column (option), the headline gains 40 px.
* The featured image can stay in the collapsed strip, resized to a single line (option).
* The red pill keeps its text on the open card and pulses like a button; the pulse is configurable (always / collapsed only / never).

= 2.1.0 =
* Featured image: a switch, a position (before / after the headline) and a size per profile, desktop and mobile.
* Mobile card: the image gets its own column out of the text flow, clamped to the headline block; the pill shrinks to its red dot so the headline keeps the width.
* Collapsed strip: the pill becomes a 24px pulsing dot against the gutter, giving the headline ~90px more.

= 2.0.1 =
* Mobile card: clipped headlines end with an ellipsis; the collapsed pill pulses like a button; the live dot really blinks.
* Fixed the pill padding (12 / 14 px) and the thumbnail in the mobile card (24 px square under the buttons, hidden when collapsed).

= 2.0.0 =
* "En continu" v2: dark bar (#1b1c20) on both devices, theme-red pill (#ce3029) aligned on the site container (1230 px, 15 px gutter), 40 px desktop bar at 30 px/s with edge fade and 40 px buttons, close button (24 h memory) on by default.
* New mobile card (76 px): the pill floats at the head of the headline, which runs on two lines under it; the first line is the 40 px collapsed strip with a chevron; landing mid-page starts collapsed; the bar slides away while a form field is active; 44 px single line in landscape.
* Contract with the theme and other plugins: --hprnb-offset on body, body.hprnb-is-collapsed / body.hprnb-kbd, the hprnb:state event and window.hprnbBar.state(); Jannah go-to-top, "Check also" and reading indicator moved above the bar (option).
* Tabbed settings page (Content, Display, Colours, Closing, Theme, Advanced) with module cards and Enable switches, colour presets with computed contrast, "Reset this tab", live preview.
* Existing 1.x installs receive the v2 presentation preset once (schema 2); every value stays editable.

= 1.3.0 =
* Two presentation profiles with the same options, Desktop and Mobile: label in front of the headline or on its own row, block / pill / hidden label with an optional live dot, counter, 1 to 4 headline lines (the bar height follows), progress line along the top edge. The stacked mobile design is now available on desktop.
* Multi-line headlines outside the rotation become horizontally scrolling cards.
* Mobile separator is opt-in; the inline mobile label always precedes the headline.
* `bar_height` is now a minimum; `mobile_bar_height` replaced by `mobile_lines`.

= 1.2.0 =
* Dedicated mobile presentation under 768 px: label pill with a live dot, counter, full-width two-line headline, rotate by default with a progress line and swipe gestures, collapse on scroll, dedicated mobile palette and font size. Fifteen new `mobile_*` settings in a new "Mobile" section; two-tab (desktop / mobile) animated preview in the admin.

= 1.1.0 =
* New setting "Separator after the last post" (`separator_after_last`, on by default): the separator is now a CSS pseudo-element driven by classes on the root container, so the last → first junction (marquee wrap) matches every other junction. Separator settings no longer fragment the server cache. No separator is shown in rotate mode.

= 1.0.0 =
* Initial release: sliding-window news bar, transient cache, hybrid mode, REST endpoint, shortcode, settings page with live preview, import / export, accessible optional ticker, RTL and i18n.

== Upgrade Notice ==

= 1.1.0 =
Separator rendering moved to CSS; theme template overrides of item.php no longer need a separator element.

= 1.0.0 =
Initial release.
