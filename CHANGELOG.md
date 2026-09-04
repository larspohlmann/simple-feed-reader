# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
Version sections below are generated automatically when a release tag is pushed;
see [docs/releasing.md](docs/releasing.md). History before the first release
lives in the git log and the merged pull requests.

## [Unreleased]

## [v1.0.1] - 2026-09-04

## What's Changed
* fix(#814): never let a blocked cache upgrade hold the reader hostage by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/815
* perf(#456, #455): batch the restore's feed lookup and post-insert id read-back by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/817
* fix(#816): quiet the search indexer when no engine is configured by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/818
* fix(#819): read media and density when picking a <picture> source by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/820
* fix(#821): size the saved-search pill to its term, not to fixed bounds by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/822
* fix(#823): use the selected layout for saved searches by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/824
* refactor(#826): trim verbose comments and compress docblock prose (backend) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/829
* refactor(#827): trim verbose comments and docblock prose (frontend) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/828
* fix(#830): stop the mobile search-scroll spec from racing its own scroll events by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/831
* feat(#832): sidebar brightness control with automatic contrast by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/833


**Full Changelog**: https://github.com/larspohlmann/simple-feed-reader/compare/v1.0.0...v1.0.1

## [v1.0.0] - 2026-09-03

### Highlights

**Manage feeds together.** The new Organise page brings feeds and tags into one
place. Select several feeds to change tags or unsubscribe in one action. Choose
which feeds appear in All items and For You, individually or in bulk.

**Improved support for embedded audio and video.** Play more embedded audio and
video directly in the reader, including streaming video.

**More control over your reading layout.** Choose between boxed cards and the
new airy magazine style. Resize the desktop split view by dragging the divider.
Search results now support reading articles alongside the results list.

**Email digests.** Receive new unread articles from selected saved searches in a
daily or weekly email.

**Passkey sign-in.** Sign in with a passkey on supported devices when your
administrator enables the feature.

**Better search and saved views.** Use quotation marks to search for an exact
phrase. Open all saved searches as one combined view, with duplicate matches
shown only once.

## What's Changed
* fix(#634): lead the GitHub Release body with the Unreleased Highlights by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/635
* fix(#638): phase-weighted recommendation ETA from run-log history by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/640
* fix(#641): lift the scroll-to-top button clear of the run toast on mobile by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/642
* fix(#643): reset fetch interval on new items, cap grow-on-empty at 2h by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/644
* fix(#645): drop the saved-search unread badge when a matching entry is read by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/646
* fix(#647): flatten a picture to its image so no source overrides it by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/648
* Desktop: show search results in split view by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/649
* fix(#639): show the feed hero only when the article has no image by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/650
* fix(#653): suppress the article hero when the body repeats it at another size by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/655
* fix(#654): fall back to the feed body when extraction misses the article by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/656
* fix(#657): suppress the reader hero whenever the body carries any image by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/658
* fix: parse blob backup errors by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/660
* refactor(#483): rename EntryState isRead to isHidden by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/661
* fix(#573): classify authorization smoke failures by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/662
* fix(#615): stabilize testing inside git worktrees by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/663
* feat(#666): reset recommendation expert defaults by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/667
* fix(#668): use time-based recommendation progress by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/669
* fix(#670): break mobile search title after prefix by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/672
* Show recommendation expert bounds and validation by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/673
* fix(#664): refresh sidebar counts on selection change by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/674
* fix(admin): make catalog import progress visible by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/676
* fix(#677): reset subscriptions on identity change by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/678
* fix: add borders to mobile list-header actions by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/680
* Add a reading focus setting by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/683
* fix(#681): restore the article lead image into the reader body by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/685
* fix(#686): unwrap proxy-CDN image URLs before fingerprinting by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/687
* feat(#636): email digest of saved-search matches by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/689
* fix(#690): pick the widest <picture> rendition, not the LQIP fallback by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/692
* fix(#691): keep the reader off /discover when the subscriptions load fails by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/693
* feat(#688): exclude a feed from All items and from For You by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/694
* refactor(#695): extract SubscriptionController tag-sync into a collaborator by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/696
* refactor(#684): restore reader lead image on the parsed document by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/697
* fix(#698): spin the refresh glyph, not the bordered icon box by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/699
* fix(#700): a toast must not block page scroll by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/701
* feat(#702): quoted queries search the exact phrase by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/703
* fix(#704): make magazine card hover pointer-only by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/705
* fix(#706): read a srcset by the HTML rules, not by splitting on commas by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/707
* feat(#709): show the list count in the tab title and the heading by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/711
* feat(#659): manage feeds in bulk on a new Organise settings page by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/712
* feat(#710): give the For you header the actions every other list has by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/713
* test(#659): raise the mutation score on the bulk-subscription code by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/715
* feat(#708): refresh the sidebar counts on their own every 30 seconds by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/716
* chore(#714): remove /settings/tags, Organise replaced it by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/717
* fix(#718): stub saved searches in the shape the store reads by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/719
* fix(#721): give the refresh run its own progress by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/722
* Passkey (WebAuthn) sign-in, admin-configured and off by default by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/728
* fix(#721): keep a refresh legible under prefers-reduced-motion by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/729
* feat(#723): a boxed or airy magazine entry design by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/731
* fix(#732): give the airy magazine its own side gutter by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/733
* fix(#721): drop the refresh sheen for a solid bar by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/734
* Sidebar poll: static change marker + counts-only endpoint (#720) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/735
* Airy magazine: tint only the content column (#736) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/737
* Serve the sidebar change marker as readable last-updated JSON (#720) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/738
* Fix exact sign-in locators and stabilize E2E tests by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/739
* feat(#725): memoise the instance-settings row per request by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/740
* fix(#724): count only unread picks in the For-You badge by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/741
* feat(#726): HTML digest email by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/742
* fix(reader): strip site-header/nav chrome readability keeps by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/743
* Reader cleanup audit: measure the pipeline across every subscribed feed by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/745
* Reader cleanup audit: stop reporting chrome that is not there by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/747
* fix(#627): reader chrome cleaners — strip share bars, menus, gated-video chrome by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/753
* fix(#752): distinguish article prefixes from image assets by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/754
* Reader: recover the media the extraction drops, host-agnostically (#748, #750, #755) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/757
* feat(#758): brighter reading surface, translucent chrome, iOS header slide by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/759
* fix(#758): keep reader-view under the 8kB component-style budget by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/760
* fix(#766): stop MeControllerTest trial test from expiring in the past by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/767
* fix(#763): constrain empty feed intro to magazine width by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/765
* fix(#761): improve airy grouped-entry rhythm by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/762
* fix(#764): show public base URL default in placeholder by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/768
* feat(#769): a combined saved-searches view by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/772
* fix(#771): let reader images use their natural width by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/773
* fix(#770): remove reader engagement chrome and stop nested promos carding the body by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/774
* fix(#748): place recovered media after the prose block it followed by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/776
* fix(#770): keep leading media when the engagement sweep runs by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/778
* fix(#748): skip page chrome when discovering an article's media by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/780
* fix(#779): drop a trailing teaser carousel the edge trimmer let through by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/781
* fix(#783): re-measure the sweep's failed articles alone before they count by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/784
* feat(#785): detect a paywalled article and mark the free preview by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/790
* fix(#788): merge media across sources so a declared embed hides no page embed by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/791
* fix(#789): keep the photos of a lazy/custom-element/media-classed immersive gallery by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/792
* fix(#782): render a player for HLS-only and Brightcove-only video pages by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/793
* fix(#787): use the WordPress site name for subscriptions by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/794
* fix(#782): stream playback follow-up — hls.js-first, stream landing URL, one player per node by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/797
* fix(#795): find a YouTube video declared only as a data-video-id by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/798
* fix(#796): a broadcast video with no og:image takes the still beside its player by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/799
* fix(#800): recover the videos a page names only by a sibling id (derive then verify) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/801
* fix(#786): Substack audio post keeps the subscribe card, the player clock labels and a share link by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/802
* Reader media layers: pin the priority order, delete the dead LinkedFile layer (#756) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/803
* fix(#775): repair the airy light-mode gutter and un-rot two e2e guards by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/804
* fix(#805): project the add-category button into the settings-group header (NG8011) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/806
* build(#807): clear the fixable frontend CI warnings by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/808
* perf(#584): read every saved-search badge's unread ids in one scan by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/809
* feat(#810): drag the split-pane divider to resize both panes by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/811
* docs(#812): prepare v1.0.0 release highlights by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/813


**Full Changelog**: https://github.com/larspohlmann/simple-feed-reader/compare/v0.7.0...v1.0.0

## [v0.7.0] - 2026-08-25

### Highlights

**Saved searches.** Name a search and keep it as a saved view in the sidebar,
next to your tags and feeds. Each one carries a live count that climbs as fresh
articles match it, so you can tell at a glance when new content is waiting.

**Smarter For You recommendations.** Ranking is now a two-step pass: your
reading history is distilled once into a durable taste profile, then each run
scores unread entries against that profile in a single consolidation pass. The
provider prompt cache is warmed with one batch before the concurrent fan-out,
which cuts both latency and cost on every run.

**WordPress REST API support.** When a site exposes the WordPress REST API,
discovery now offers it as a feed source, so you get full articles and images
from WordPress publishers instead of the site's bare RSS feed.

**Optional egress proxy.** Route all outbound fetches through a SOCKS5 or HTTP
proxy, behind a single master switch, with a direct-connection fallback when the
proxy is off.

## What's Changed
* Group top-level Service classes into cohesive subdirectories by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/512
* refactor(reader): migrate normalization to \Dom\HTMLDocument, drop the round-trip by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/521
* refactor(recommendation): split dedup and finalize out of RecommendationRunAdvancer (#338) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/522
* fix(reader): one reload authority for a refresh (#502) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/523
* fix(#520): suppress hero when the body leads with an image by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/524
* feat(parser): find card images in Atom summary and custom <image> elements (#513) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/525
* feat(#515): one-line dek in the magazine compact block by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/526
* fix(#516): route image-less entries with a summary to a dek-showing block by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/527
* fix(#488): fold entry-row actions onto shared app-entry-actions by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/529
* feat(#516): collapse summary-less entries out of the kicker block by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/528
* fix(#484): dedupe ingest on stable URL to defeat volatile GUIDs by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/530
* feat(#493): two-step recommendations — distill history into a profile, consolidate in one pass by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/531
* fix(#493): source the connection batch-cap default from the backend by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/532
* fix(#497): document-wide no-referrer policy for body images by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/533
* feat(#495): warm the provider prompt cache with one batch before the concurrent fan-out by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/534
* feat(#518): offer the WordPress REST API as a richer feed alternative during discovery by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/535
* test(#536): skip body-limit agreement check where docker/ isn't mounted by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/537
* fix(#411): translate the reader list heading and add per-view icons by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/538
* feat(#539): show update-available badge in the sidebar version display by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/540
* fix(#542): restore the previous view when a search is closed by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/544
* fix(#543): fall back to Atom <id> for the entry URL by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/545
* AI settings redesign: grouped design system + reason/debug decouple (#541) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/546
* feat(#519): render the add-feed preview like the reader by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/548
* fix(#549): title every page from its route by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/551
* fix(#550): let the search field's own ✕ close the mobile bar by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/552
* feat(#490): optional SOCKS5/HTTP egress proxy by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/553
* fix(#554): clear a settings-saved toast after three seconds by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/555
* feat: expand the onboarding catalog to 226 feeds by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/558
* feat: correct the catalog admission rule, add 54 feeds and two categories by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/560
* Roll the settings design system across settings + admin (#547, #454) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/563
* fix: make every category reachable in the picker, on both layouts by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/564
* Guard the backup format against silent schema drift by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/565
* feat: add Mixmag to the Electronic Music catalog by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/567
* Show a feed's image, description and website at the top of its entry list by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/570
* fix(#489): clear the e2e rot in both suites by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/571
* fix(#574): let the mobile search ✕ empty the box before it leaves by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/575
* fix(#576): let one switch carry the whole explanation by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/577
* fix(#576): shorten the German score label to "Punkte" by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/578
* fix(#579): let a closed search return to the list it covered by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/580
* feat(#581): saved searches by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/583
* Strip share-widget bars and edge boilerplate from reader articles by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/585
* Saved searches follow-ups: save toast, remove confirm, mobile header polish by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/587
* fix(#588): mute the excerpt colour in search-result rows by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/589
* fix(#590): match a photo's identity by path, not just basename by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/591
* fix(#594): read tick does not flip on an open entry by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/595
* refactor(#592): consolidate the hero duplicate-image rule into the backend by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/597
* fix(#593): mount docs read-only into the php container by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/599
* feat(#596): rotate dev and test logs daily, keep 3 files by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/600
* refactor(#598): one DeclaredImage value object, not ParsedImage + HeroImage by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/601
* fix(#602): fill the unread dot, empty it once read by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/603
* fix(#602): unread dot fill + read filter as a single switch, default all by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/604
* fix(#605): show the persisted image in list and search rows by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/606
* fix(#608, #610): collapse CDN size-variant image identities (taz path segment, deutschlandfunk basename suffix) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/609
* feat(#613): add --help to the operator scripts by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/614
* feat(#612): make the sidebar Tags and Feeds sections collapsible by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/616
* fix(#617): unify the list-header action controls by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/618
* fix(#619): dedupe zdfheute tilde-separated image size variants by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/620
* refactor(#586): share one Dom document across reader title-removal and trim by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/621
* feat(#622): reveal toggle for password fields by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/623
* fix(#625): dedupe Substack hero by the origin behind the CDN fetch proxy by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/626
* fix(#628): fall back to the content lead image for wp-json feeds by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/629
* fix(#630): app bar stays hidden after switching lists from the drawer by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/631
* feat(#632): promote the Unreleased Highlights block into the tagged version section by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/633


**Full Changelog**: https://github.com/larspohlmann/simple-feed-reader/compare/v0.6.1...v0.7.0

## [v0.6.1] - 2026-08-20

## What's Changed
* docs: v0.6.0 changelog highlights by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/491
* feat: autodiscover Substack profile URLs by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/503
* fix: stamp the release version into the Docker install (#500) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/507
* fix: resolve Substack profiles via the public-profile API (#504) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/508
* fix: keep the article hero when the body shows a different image by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/509


**Full Changelog**: https://github.com/larspohlmann/simple-feed-reader/compare/v0.6.0...v0.6.1

## [v0.6.0] - 2026-08-19

### Highlights

**AI recommendations (For You).** A per-account "For You" feed that ranks your
unread entries with an AI provider of your choice. Save one or more provider
profiles and switch between them, run a ranking on demand or on a per-user
schedule, and follow it with a live run view, an ETA, and cost bounds. A
background worker and a detached drainer carry long runs to completion, and
results are scored so the strongest articles surface first.

**Full-text search.** Search across your entries, backed by Meilisearch with a
permanent database fallback so search keeps working even when the index is
unavailable. You choose whether to include Meilisearch at install time — the
database fallback covers the rest. Whole-word matching is supported, and results
reload in place without blanking the list.

**Account backup and restore.** Export a whole account — feeds, tags, read
state, and your kept and favorited articles — and restore it into another
instance. A self-hosted install accepts a real account backup, so you can move
between instances or keep an off-site copy.

**Simplified install and update.** The production installer sets up the full
stack from a single package question and survives a second run, and
`scripts/update.sh` moves an existing install to the latest release. Both
resolve the latest release straight from git, with no dependency on the GitHub
Release object.

## What's Changed
* Per-account AI provider configuration (groundwork) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/306
* Record actively opened entries as viewed (#307) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/313
* feat(#308): AI-powered For you recommendation feed by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/314
* feat(#311): background worker container for recommendation runs, feed refresh and housekeeping by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/315
* Score-based recommendation ranking (#316) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/317
* Stream provider responses in the AI client for early stall detection by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/318
* Realtime debug view for recommendation runs (#309) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/319
* Stop charging reasoning tokens to the answer's budget (#320) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/322
* For-you polish: layout, controls, cost bounds (#321) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/324
* Move the For You run trigger into the list title bar by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/326
* fix(#327): reasoning models fail ranking — max_tokens starves the answer by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/328
* fix(#329): LM Studio json_schema, real transport errors, batch degrade, resume-or-fresh choice by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/330
* For You: show the recommendation strip in every reading layout (#331) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/332
* feat(#333): scheduled auto-generation of For You (per-user cadence + external-cron endpoint) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/335
* Save multiple AI provider configurations and switch the active one (#334) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/337
* fix(#323): recover the recommendation answer from reasoning_content when content is empty by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/339
* For-You run: ETA + anticipatory progress bar (#336) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/340
* feat(#323): per-config toggle to ask the provider not to reason by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/341
* fix(#342): mobile layout regressions + settings polish by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/343
* Parallel recommendation batch calls + recommendation-quality tuning (#344) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/345
* feat(#346): single /maintenance/tick endpoint (refresh + recommendations) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/349
* feat: duplicate an AI provider configuration + collapsible AI settings UI (#347) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/350
* For You: run-boundary headers between recommendation runs (#348) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/351
* refactor: collapsible settings-card composes app-disclosure (#352) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/353
* fix(#354): pin every resolved IP so the fetch client can fall back across families by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/355
* fix(#356): cross-family fetch failover on a dead-route reset by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/357
* fix(#358): cross-family failover on a route-specific error status (taz.de) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/359
* fix(#360): force a fresh connection on a failover retry (taz.de) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/361
* fix(#362): exclude the worker from the e2e stack boot by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/363
* fix(#364): tighten adaptive refresh bounds (floor 5 min, ceiling 6 h) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/365
* feat(#366): sort entry list by refresh run, not article publication time by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/367
* fix(#368): read Atom <dc:date> so tagesschau & NDR entries get a published date by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/369
* fix(e2e): let the seeded admin accept a scraped candidate by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/377
* refactor: give the page URL a home in the scraper and discovery by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/375
* build: gate the backend on phptramp by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/379
* build: run the gate against phptramp's latest develop by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/381
* fix(security): php_codesniffer 3.13.6 closes CVE-2026-67434 by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/383
* fix(#388): follow phptramp to Packagist by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/389
* fix(#384): sort on a clamped effective date, purge by fetch date by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/387
* feat(#386): recommendation look-back window (1-7 days, default 2) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/391
* Spawn a detached CLI drainer to drive recommendation runs to completion (#371) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/392
* feat(#372): in-page documentation for the AI settings by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/395
* fix(#396): reject a dedup reply that names most of the list by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/397
* fix(#399): stop the batch prompt asking for candidates to be left out by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/400
* feat(#401): keep the debug log of the last ten runs by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/402
* feat(#403): score on 0-1000, and ask for exact values by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/404
* feat(#406): dedup lines carry the description, not the reason by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/407
* Search entries by title and summary by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/413
* Show the entry actions on every magazine card by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/418
* fix(#415): report a rejected AI configuration where it happened, with the server's reason by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/420
* feat(#398): show For-You progress in the app-wide pill, not only the reader header by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/421
* fix(#423): parse feeds that start after the XML declaration by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/425
* fix(#424): report a bot gate as a refusal, not as a missing feed by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/426
* docs(#428): target the README at end users, polish the operator guides by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/429
* feat(#430): print the setup information last, and default to port 3333 by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/431
* feat(#409): record what each recommendation run costs, and show a month-paged run history by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/427
* Per-connection AI timeout profile, and the info tip that opened in the wrong place by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/434
* Design polish sweep by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/436
* fix(#437): bound a runaway completion where the runaway is by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/438
* fix(#441): clean up the #437 runaway bounds, and close what review found by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/442
* Meilisearch-backed entry search with a permanent database fallback (#432) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/446
* fix(#448): let a search reload the list without dimming or veiling it by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/449
* fix(#450): carry the whole-word mode into the search index query by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/451
* Bound a stranded recommendation run to minutes, split slow_model, move the drainer spawn by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/452
* docs(#432): move Meilisearch wire-format evidence out of scratch by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/447
* Back up and restore a whole account between instances (#412) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/457
* fix(#458): let a self-hosted install accept a real account backup by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/459
* fix(#462): fade a list's rows as soon as they render by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/463
* feat(#453): one package question, with the memory each one needs by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/464
* fix(#465): fit the run-history row on a phone by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/466
* fix(#467): keep the image when a page lazy-loads it by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/469
* Reader header: kept/favorite on mobile, per-article refresh, shared loading overlay (#470) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/471
* Box publisher inserts as cards, strip orphaned icon glyphs (#472) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/474
* fix(#468): halve the article reading-focus change from #435 by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/477
* feat(#478): add "Recently read" saved view by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/479
* fix(#476): reader extracts the wrong block — extract both ways, keep the richer by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/481
* feat(#482): tick=viewed, circle=read; enforce viewed⊆read by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/485
* Visual refinements: AI settings hint, mobile search, sidebar chevrons, entry rows (#486) by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/487


**Full Changelog**: https://github.com/larspohlmann/simple-feed-reader/compare/v0.5.6...v0.6.0

## [v0.5.6] - 2026-08-06

## What's Changed
* fix(#302): stop the refresh poll loop on a rationed feed by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/303


**Full Changelog**: https://github.com/larspohlmann/simple-feed-reader/compare/v0.5.5...v0.5.6

## [v0.5.5] - 2026-08-06

## What's Changed
* fix(#274): measure the magazine kicker after the layout settles by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/279
* fix(#280): never gate boot on the i18n dictionary fetch by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/281
* fix(#282): boot watchdog reveals the error surface when nothing renders by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/284
* fix(#285): a navigation watchdog for stalls the router cannot report by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/287
* fix(#286): show a clicked list from the top by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/288
* fix(#283): find the feed a page hides — conventional paths and feed-shaped links by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/289
* fix(#290): fill a new feed from the document discovery already read, and stop treating 429 as a failure by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/291
* fix(#292): let an unbreakable word wrap, once, for every surface by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/293
* feat(#294): show the tag's glyph and colour in the list header by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/295
* fix(#296): trim the blank tail a feed leaves on an article by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/297
* Add Infection mutation testing to the quality pipeline by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/299
* refactor(#300): drop DatabaseClock, the timezone pin from #154 covers it by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/301


**Full Changelog**: https://github.com/larspohlmann/simple-feed-reader/compare/v0.5.4...v0.5.5

## [v0.5.4] - 2026-08-04

## What's Changed
* fix(#275): stop docker compose from eating a piped installer by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/276
* feat(#277): let the installer choose SQLite instead of MySQL by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/278


**Full Changelog**: https://github.com/larspohlmann/simple-feed-reader/compare/v0.5.3...v0.5.4

## [v0.5.3] - 2026-08-04

## What's Changed
* fix(#250, #260): clear the PhpStorm ERROR findings in backend/src and backend/tests by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/268
* fix(#267): give each list its own scroll position on a view switch by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/269
* feat(#270): keep the article named while it scrolls by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/271
* feat(#272): a production installer that survives a second install, and sets up the catalog it ships by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/273


**Full Changelog**: https://github.com/larspohlmann/simple-feed-reader/compare/v0.5.2...v0.5.3

## [v0.5.2] - 2026-08-03

## What's Changed
* feat(#237): make website scraping an opt-in experimental preference by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/239
* feat(#238): a reading-progress bar for the article view by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/240
* feat(#241): an All Items pill in the mobile tag row by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/242
* fix(#141): cache-bust the Transloco dictionaries with the release version by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/243
* fix(#119): tell the user when a refresh accomplished nothing by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/244
* fix(#245): materialize entry.effective_date and index the list sort by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/248
* feat(#246): delete a user account with all of its content, and reclaim orphaned feeds by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/249
* fix(#247): show the account status after an OAuth sign-in by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/251
* feat(#252): split the installer's public-URL question into three by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/253
* Run both e2e suites weekly in CI by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/258
* Cover frontend/e2e with the Prettier check by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/259
* fix(#255): drop the host from the outbound User-Agent by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/261
* fix(#254): compress responses and keep the list rendered while it reloads by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/262
* fix(#263): void per-user caches when the signed-in identity changes by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/264
* chore: back-merge main into develop before cutting v0.5.2 by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/265


**Full Changelog**: https://github.com/larspohlmann/simple-feed-reader/compare/v0.5.1...v0.5.2

### Changed

- The production installer asks how users reach the instance, under which
  hostname, and on which port, instead of one "Public URL" question that
  carried all three. The new first question offers plain HTTP, a certificate
  this stack serves, or a reverse proxy in front — and the proxy answer now
  writes the loopback bind address and the moved TLS port itself, instead of
  leaving them as a hand edit ([#252](https://github.com/larspohlmann/simple-feed-reader/issues/252)).

### Fixed

- A port equal to the scheme's default no longer reaches `PUBLIC_URL`. The
  resulting `https://host:443/...` did not match an OAuth redirect URI
  registered as `https://host/...`, which providers compare exactly
  ([#252](https://github.com/larspohlmann/simple-feed-reader/issues/252)).
- Answering the public-origin question with a malformed value asks again
  instead of aborting the installer after the clone
  ([#252](https://github.com/larspohlmann/simple-feed-reader/issues/252)).

## [v0.5.1] - 2026-08-02

## What's Changed
* feat(#230,#224): mailless-capable instance + registration-gate toggles by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/231
* fix(#213): stronger reading focus, no scroll lag by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/232
* fix(#212): keep long tag names inside the mobile viewport by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/233
* fix(#212): keep settings/tags rows on one line on mobile by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/234
* Reader extraction fixes and typographic variety by @larspohlmann in https://github.com/larspohlmann/simple-feed-reader/pull/236


**Full Changelog**: https://github.com/larspohlmann/simple-feed-reader/compare/v0.5.0...v0.5.1
