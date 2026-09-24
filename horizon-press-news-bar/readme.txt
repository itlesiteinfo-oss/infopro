=== Horizon Press News Bar ===
Contributors: horizonpress
Tags: news, ticker, breaking news, bar, headlines
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 2.17.0
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

= 2.17.0 =
* A new design for the URGENT bar, "Breaking News", now the default (Urgent tab → Design of the URGENT bar; the 2.16 design stays available as "Chyron"): a flat red band, a still white cartouche with the label in bold red, the whole headline in bold white, the close button in the band.
* Each headline is revealed letter by letter without a cursor, stays at least 5 seconds (longer for a long one), then the next one types in; a single headline is typed once and stays. The bar keeps the height of the longest headline from the first paint; screen readers get the whole headline; reduced motion shows it at once.
* New checkbox "Never show the article the reader is on" (on by default): the article being read is left out of the URGENT bar, also after an in-page navigation; with nothing left the bar hides without keeping any space.

= 2.16.0 =
* One switch per bar and one per device (Content → Bars shown): enable the URGENT bar, then desktop and mobile; enable the initial (news) bar, then desktop and mobile. Sub-choices are hidden while their bar is off; the URGENT box on the edit screen only appears while the URGENT bar is on for a device.
* The URGENT bar in a news channel's design: a plate in the band's colours swapped, a bold 17px headline one at a time, 48px on desktop with pause and close at the end, 76px on phones; headline sizes of its own in the Urgent tab.
* "Font of both bars" (Colours tab): the system news face by default, also for the news bar, or the theme's font as before.
* Fixes: no empty reserved band where no bar shows, device switches measured like the reserved space, closing the news bar no longer hides the URGENT bar of the other device, the settings preview follows the device switches.
* Hybrid mode behind a page cache: an unchanged refresh keeps the running bars, a changed one keeps the news bar's wait and applies the device switches; a news bar hidden by the stylesheet no longer runs.
* Short phone screens, the article placement and the gap between 768px and the scrollbar keep the right space for the URGENT bar.

= 2.15.0 =
* The URGENT checkbox has its own box, first at the top of the side column of the edit screen, above Publish, shown by default.
* The URGENT bar has its own page types (front page included by default), independent of the news bar's: breaking news reaches the front page even where the news bar stays away.
* A second desktop design for the URGENT bar: the same as on phones (two lines, close button in the tab above the corner).

= 2.14.0 =
* URGENT bar: tick "Urgent article" on an article and publish or update it; for the configured minutes a red bar with that headline replaces the news bar on phones and desktops, several urgent articles take turns newest first, each leaves on time and the news bar returns by itself without a reload. Closing it is remembered until a newer flag.
* A new Urgent tab: switch, duration, label, colours, live preview.

= 2.13.0 =
* New folding mode "up", used by Continuous reading: once the bar has appeared, every scroll down opens it and every scroll up folds it, wherever the reader is.
* A long headline fades out at the end of its last line instead of ending with three dots.
* The ZIP file is named after the version.

= 2.12.0 =
* Existing sites switch once to the bar with the article picture, as delivered (two lines, pill with its dot, cross alone in the tab, picture kept in the folded strip); behaviour, colours and content are kept.
* Mobile design picker: two boxes, each with a drawing of the bar open and folded.

= 2.11.0 =
* Two mobile designs left: the bar with the article picture (default) and the "Explore More" card. Sites on a removed design move to the bar with the picture.
* New mobile defaults: two lines, pause hidden, Continuous reading, picture kept in the folded strip. Existing sites keep their settings.
* Premium folding: the picture shrinks into the strip, the pill closes onto its dot, the tab button turns into the other one; reduced motion honoured.
* Folded, the unfold button sits in the tab above the corner; Jannah's corner elements clear the tab.
* Simple settings page by default, with an "Advanced settings" switch for everything else.

= 2.10.0 =
* New mobile design "Flowing bar with the article picture": the label opens the headline on two lines, the article picture sits at the end of the line where the buttons were, and the close button moves to a tab above the corner, as on the card.

= 2.9.0 =
* One "Behaviour" choice per device at the top of the Mobile and Desktop tabs: Continuous reading, Visible and folds while scrolling, Always visible, or Custom (the detailed blocks only show for Custom).
* Continuous reading: hidden at first, in full at the chosen paragraph before the end, folds on any scroll back up, opens again when reading on, open once the article is over, and gone in the next article of a continuous-loading theme.
* New "Next article" option per device: the bar slides out and releases its space once the reader moves on to the next article loaded below, and comes back on the way up.
* Fixed: while waiting, the close tab of the mobile card showed at the bottom of the screen.
* Existing sites keep their behaviour (schema 6: named after the behaviour it matches, otherwise Custom).

= 2.8.0 =
* When the bar appears is now decided per device (mobile_reveal_* / desktop_reveal_*), migrated from the single setting; imports of older exports are migrated too.
* Settings page: one tab per device — design, headlines, when it appears, folding, buttons.
* The mobile card follows the client's reference and is the default: label row, 16:9 132×74 picture, 18px headline on three lines, edge to edge, close button in a 44px tab above the corner. Floating is an option.
* Fixed: the admin preview was empty or cut off whenever the bar waited for the reader.
* Fixed: the two device switches were rendered twice on the settings page.

= 2.7.0 =
* New reveal mode "Before the end of the article": the bar appears as soon as the Nth paragraph from the end comes into view (2 = second-to-last, configurable 1–30). Measures the article body, not the page.
* New folding mode "Follows the reading" on each device: the bar first appears in full, folds on any scroll back up inside the article, stays folded until the trigger point is reached again, and stays open once the article is over.
* A restored position or a deep link beyond the paragraph shows the bar at once.

= 2.6.0 =
* New: a "News Bar" box on every post and page edit screen — keep one article out of the bar, or keep the bar off one page. Two independent switches.
* Fixed: the "Discover" mobile card ignored the headline line count. It now honours it, up to three lines (116px instead of 99px).
* Fixed: the desktop font size was declared but listed on no tab, so it was unreachable in the admin and reset on every save.
* Fixed: a bar waiting for the reader snapped into place instead of sliding whenever folding was off (the desktop default).
* Fixed: prefers-reduced-motion did not cover the desktop bar.
* Settings page reorganised into six tabs named after the question they answer, with worked examples; one single place decides the page types; the folding switch reads as a switch.

= 2.5.0 =
* Fixed: a bar with a single headline no longer draws a separator behind it, in every ticker mode and both directions.
* Fixed: the hybrid session copy stored a fake headline count, which would have stripped every separator on replay.
* Mobile "Discover" card: the close button moves inside the card, the card floats clear of the edges, and it is down to 99px from 152px.
* New "Smart" reveal mode: the bar waits for the end of the article, a real scroll back up, or a reader who got deep into it — measured on the article body, fired once.
* New analytics events (hprnb_impression / hprnb_click / hprnb_close) with the trigger reason, on dataLayer and on document.

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

= 2.17.0 =
The URGENT bar switches to the new "Breaking News" design. To keep the previous one, choose "Chyron" in Urgent → Design of the URGENT bar. Purge your page cache after updating.

= 2.16.0 =
Both bars now use the system news face by default. To keep your theme's font on the news bar, choose "Theme font" in Colours → Font of both bars. Purge your page cache after updating: pages cached before 2.16 keep the previous font and URGENT bar height until they are regenerated.

= 1.1.0 =
Separator rendering moved to CSS; theme template overrides of item.php no longer need a separator element.

= 1.0.0 =
Initial release.
