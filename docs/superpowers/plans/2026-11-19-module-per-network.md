# A Module per Social Network Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Each network's account side lives in its own service module, and `Service/Social/Social` names no network module.

**Architecture:** A pure move. `JsonNodeModel` goes to a new `Foundation/Json` module; today's `Social/Bluesky` (the public post side) is renamed `Social/BlueskyPost`; three new modules `Social/Bluesky`, `Social/Mastodon` and `Social/Lemmy` take each network's client, keyed adapters, helpers and notification adapters. The interfaces, pickers and orchestrators stay in `Social/Social` and `Social/Notification`; the network modules depend on them, never the reverse.

**Tech Stack:** PHP 8.5, Symfony 8.1, PHPStan (`ServiceModuleCycleRule`, `ServiceRoleRule`), PHPMD, PHPUnit, Infection.

**Spec:** GitHub issue #2359, as amended by the decisions below (the issue text still says "a merged `Social/Bluesky`"; the ruling is two modules).

## Global Constraints

- No behaviour or API change: no class, method, property, tag, route, JSON key, log line or problem text changes. **No class is renamed**; only namespaces and folders change.
- Every file moves with `git mv`, so history follows. A move commit changes the `namespace` line, `use` lines and class names in strings, nothing else.
- Every existing test survives. A test file moves when the class it is named after moves (`tests/Service/<old path>Test.php` to `tests/Service/<new path>Test.php`); every other test and everything in `tests/Support` stays where it is and only changes imports.
- A class that moves out of a folder named after an interface (`PostLiker/`, `NotificationSource/`, `Crypto/`) lands in its network module's **root** (docs/architecture.md §10: an implementation in another module stays in its own module). `Model/`, `Support/`, `Pass/`, `Exception/` and `HiddenProfiles/` keep their folder name.
- Per task: `composer check`, `composer md`, `php bin/phpunit` green before the commit. Before the PR also `docker compose exec php composer test:parallel` and `composer infection:diff`.
- No `tests/PhpStan` edit, no `@phpstan-ignore`, no PHPMD suppression, no baseline. If the PHPMD commit hook holds a commit, stop: "approve classes" is Lars's own word in the implementing session.
- Commits `refactor(#2359): …`. No attribution lines.
- A file not in the lists below does not move. If a gate cannot go green without moving or changing one, stop and report to the planner.

**Out of scope:** renaming any class or method (`…Of` names are #2360); `Completion`'s imports of `PostEnricher`, `EntryEmbedWriterInterface`, `FillOutcome`, `BlueskyPostView`; the Lemmy and Bluesky public-feed code in `Content`, `Ingestion`, `Foundation`, `Outbound`; `LinkPostChoicesModel::MIN/MAX_TITLE_CHARACTERS`; the unused `$account` of `ImportRuleInterface::findAccountOnlyFeeds()` and the rule `FollowMatcher::match()` takes twice; the phptramp warning on `$feedUrl`; `RefusedProfileSubscriptionRepository` and the entities; frontend.

## Decisions

- Two Bluesky modules: the existing lower module becomes `Social/BlueskyPost`; the account side is the new `Social/Bluesky` (Lars, 2026-11-19).
- `JsonNodeModel` moves to `Foundation/Json/Model` (issue #2359).
- Notification adapters and their helpers move into the network modules (issue #2359).
- `HiddenProfile*`, `HiddenImportFollows`, `TolerantHiddenProfiles`, `ProfileVisibility*`, `HiddenProfiles/` move to `Social/Bluesky` (issue #2359). With them go `RefusedProfileSubscriptionModel`, `RefusedFeedPositionModel` and `Pass/RefusedSubscriptionSelection`, which only the hidden-profile switch uses; left behind they would be the one import from `Social/Social` into a network module.
- A generic-named file only one network uses moves with it: `ResolvedCollectionsModel`, `SavedItemsModel`, `TextSpanModel` to Bluesky, `Support/LinkHeader` to Mastodon.

## Review Focus

- **Stale cache entries.** `ProfileVisibilityCache` and `HiddenProfileSwitchCursor` store objects (`ProfileVisibilityModel` with `HiddenProfileModel`, `RefusedFeedPositionModel`), so entries written before the move carry the old class names. They must read as a miss (visibility unknown, cursor at the start), never as an error. Task 6 Step 3 checks it.
- **Container wiring.** Every moved service is still registered and tagged: each keyed picker finds all its adapters, and the `HiddenProfilesInterface` alias and the test overrides in `config/services.yaml` and `config/services_test.yaml` name the new classes. `NetworkRulesCompleteTest` and `EveryApplicationServiceBuildsTest` pin it; both must run in every task.
- **Migrations.** `Version20261013150000` and `Version20261114160000` import moved helpers and no unit test executes a migration. Task 6 Step 2 migrates from empty on SQLite and MySQL.
- **Class names in strings.** YAML, `#[Autowire(service: …)]`, `infection.json5`, `phpstan.dist.neon` and docs name classes as strings no refactoring follows. Each task ends with the grep for the namespaces it emptied.
- **Lost implicit imports.** Classes that shared a namespace needed no `use`; after the move they do, docblock-only mentions (`@throws`, `@see`, `@var`) included. PHPStan reports the code ones; the docblock ones need the grep in each task's last step.

---

### Task 1: `JsonNodeModel` to `Foundation/Json`

**Files:**
- Move: `backend/src/Service/Social/Bluesky/Model/JsonNodeModel.php` to `backend/src/Service/Foundation/Json/Model/JsonNodeModel.php`
- Move: `backend/tests/Service/Social/Bluesky/Model/JsonNodeModelTest.php` to `backend/tests/Service/Foundation/Json/Model/JsonNodeModelTest.php`
- Modify: every importer in `backend/src` and `backend/tests` (about 120 files)
- Modify: `docs/architecture.md` §9 table, row `Foundation`: add `Json`

**Interfaces:**
- Produces: `App\Service\Foundation\Json\Model\JsonNodeModel`, API unchanged.

- [ ] **Step 1:** `git mv` both files, set the namespaces, rewrite every `use App\Service\Foundation\Json\Model\JsonNodeModel;`. Files in the old namespace that used it without an import get one.
- [ ] **Step 2:** The class docblock says "One object of an AppView answer"; it now reads `/** One JSON object, read field by field: a field of the wrong type reads as absent. */`.
- [ ] **Step 3:** Run `grep -rn 'Social\\Bluesky\\Model\\JsonNodeModel' backend docs`. Expected: no output.
- [ ] **Step 4:** Run `composer check && composer md && php bin/phpunit` from `backend/`. Expected: green, same test count as develop.
- [ ] **Step 5:** Commit `refactor(#2359): JsonNodeModel moves to Foundation/Json`.

### Task 2: `Social/Bluesky` becomes `Social/BlueskyPost`

**Files:**
```
Social/Bluesky/AppViewClient.php -> Social/BlueskyPost/AppViewClient.php
Social/Bluesky/BlueskyEntryIdentityUrl.php -> Social/BlueskyPost/BlueskyEntryIdentityUrl.php
Social/Bluesky/BlueskyEntryRule.php -> Social/BlueskyPost/BlueskyEntryRule.php
Social/Bluesky/BlueskyPendingEmbed.php -> Social/BlueskyPost/BlueskyPendingEmbed.php
Social/Bluesky/EntryEmbedWriter/EntryEmbedWriter.php -> Social/BlueskyPost/EntryEmbedWriter/EntryEmbedWriter.php
Social/Bluesky/EntryEmbedWriter/EntryEmbedWriterInterface.php -> Social/BlueskyPost/EntryEmbedWriter/EntryEmbedWriterInterface.php
Social/Bluesky/Exception/AppViewAnswerException.php -> Social/BlueskyPost/Exception/AppViewAnswerException.php
Social/Bluesky/Model/FillOutcome.php -> Social/BlueskyPost/Model/FillOutcome.php
Social/Bluesky/Model/RenderedEmbedModel.php -> Social/BlueskyPost/Model/RenderedEmbedModel.php
Social/Bluesky/PostEmbedRenderer.php -> Social/BlueskyPost/PostEmbedRenderer.php
Social/Bluesky/PostEnricher.php -> Social/BlueskyPost/PostEnricher.php
Social/Bluesky/Support/AtPostUri.php -> Social/BlueskyPost/Support/AtPostUri.php
Social/Bluesky/Support/BlueskyActorKey.php -> Social/BlueskyPost/Support/BlueskyActorKey.php
Social/Bluesky/Support/BlueskyEmbedMedia.php -> Social/BlueskyPost/Support/BlueskyEmbedMedia.php
Social/Bluesky/Support/BlueskyExternalCard.php -> Social/BlueskyPost/Support/BlueskyExternalCard.php
Social/Bluesky/Support/BlueskyPostView.php -> Social/BlueskyPost/Support/BlueskyPostView.php
Social/Bluesky/Support/BlueskyRichText.php -> Social/BlueskyPost/Support/BlueskyRichText.php
Social/Bluesky/Support/BlueskyWeb.php -> Social/BlueskyPost/Support/BlueskyWeb.php
Social/Bluesky/Support/QuotedViewRecord.php -> Social/BlueskyPost/Support/QuotedViewRecord.php
```

Paths are relative to `backend/src/Service/`. The tests under `backend/tests/Service/Social/Bluesky/` move to `backend/tests/Service/Social/BlueskyPost/` the same way.

**Interfaces:**
- Produces: namespace `App\Service\Social\BlueskyPost\…` for these 19 classes. `Social/Bluesky` is empty after this task and is created anew in Task 5.

- [ ] **Step 1:** `git mv backend/src/Service/Social/Bluesky backend/src/Service/Social/BlueskyPost` and the test folder; replace `App\Service\Social\Bluesky\` with `App\Service\Social\BlueskyPost\` in `backend/src`, `backend/tests`, `backend/config`, `backend/migrations`.
- [ ] **Step 2:** Run `grep -rn 'Service\\Social\\Bluesky\\' backend`. Expected: no output.
- [ ] **Step 3:** Run `composer check && composer md && php bin/phpunit`. Expected: green.
- [ ] **Step 4:** Commit `refactor(#2359): the Bluesky post side is Social/BlueskyPost`.

### Task 3: `Social/Mastodon`

**Files:**
```
Social/Notification/NotificationContextSource/MastodonNotificationContextSource.php -> Social/Mastodon/MastodonNotificationContextSource.php
Social/Notification/NotificationSource/MastodonNotificationSource.php -> Social/Mastodon/MastodonNotificationSource.php
Social/Notification/Support/MastodonIds.php -> Social/Mastodon/Support/MastodonIds.php
Social/Social/CandidateSource/MastodonCandidateSource.php -> Social/Mastodon/MastodonCandidateSource.php
Social/Social/ComposerDefaults/MastodonComposerDefaults.php -> Social/Mastodon/MastodonComposerDefaults.php
Social/Social/HomeServerCheck/MastodonHomeServerCheck.php -> Social/Mastodon/MastodonHomeServerCheck.php
Social/Social/ImportRule/MastodonImportRule.php -> Social/Mastodon/MastodonImportRule.php
Social/Social/MastodonAccountApi.php -> Social/Mastodon/MastodonAccountApi.php
Social/Social/MastodonApiClient.php -> Social/Mastodon/MastodonApiClient.php
Social/Social/MastodonContextReplies.php -> Social/Mastodon/MastodonContextReplies.php
Social/Social/MastodonPendingThread.php -> Social/Mastodon/MastodonPendingThread.php
Social/Social/Model/MastodonSessionModel.php -> Social/Mastodon/Model/MastodonSessionModel.php
Social/Social/Model/MastodonStatusModel.php -> Social/Mastodon/Model/MastodonStatusModel.php
Social/Social/NetworkVerifier/MastodonVerifier.php -> Social/Mastodon/MastodonVerifier.php
Social/Social/Pass/MastodonReplyThread.php -> Social/Mastodon/Pass/MastodonReplyThread.php
Social/Social/PostAddressRule/MastodonPostAddressRule.php -> Social/Mastodon/MastodonPostAddressRule.php
Social/Social/PostCountsSource/MastodonOriginCountsSource.php -> Social/Mastodon/MastodonOriginCountsSource.php
Social/Social/PostLiker/MastodonPostLiker.php -> Social/Mastodon/MastodonPostLiker.php
Social/Social/PostReposter/MastodonPostReposter.php -> Social/Mastodon/MastodonPostReposter.php
Social/Social/PostResolver/MastodonPostResolver.php -> Social/Mastodon/MastodonPostResolver.php
Social/Social/PostWriter/MastodonPostWriter.php -> Social/Mastodon/MastodonPostWriter.php
Social/Social/PrivateFeedRule/MastodonPrivateFeedRule.php -> Social/Mastodon/MastodonPrivateFeedRule.php
Social/Social/ProfileFeedRule/MastodonProfileFeedRule.php -> Social/Mastodon/MastodonProfileFeedRule.php
Social/Social/QuotedPostSource/MastodonQuotedPostSource.php -> Social/Mastodon/MastodonQuotedPostSource.php
Social/Social/ReplyThreadSource/MastodonReplyThreadSource.php -> Social/Mastodon/MastodonReplyThreadSource.php
Social/Social/SearchSource/MastodonSearchSource.php -> Social/Mastodon/MastodonSearchSource.php
Social/Social/Support/LinkHeader.php -> Social/Mastodon/Support/LinkHeader.php
Social/Social/Support/MastodonAccount.php -> Social/Mastodon/Support/MastodonAccount.php
Social/Social/Support/MastodonAttachments.php -> Social/Mastodon/Support/MastodonAttachments.php
Social/Social/Support/MastodonAuthor.php -> Social/Mastodon/Support/MastodonAuthor.php
Social/Social/Support/MastodonCard.php -> Social/Mastodon/Support/MastodonCard.php
Social/Social/Support/MastodonCommentPost.php -> Social/Mastodon/Support/MastodonCommentPost.php
Social/Social/Support/MastodonCredentials.php -> Social/Mastodon/Support/MastodonCredentials.php
Social/Social/Support/MastodonFeedIds.php -> Social/Mastodon/Support/MastodonFeedIds.php
Social/Social/Support/MastodonFollowedTag.php -> Social/Mastodon/Support/MastodonFollowedTag.php
Social/Social/Support/MastodonPostCounts.php -> Social/Mastodon/Support/MastodonPostCounts.php
Social/Social/Support/MastodonProfileFeed.php -> Social/Mastodon/Support/MastodonProfileFeed.php
Social/Social/Support/MastodonQuotedStatus.php -> Social/Mastodon/Support/MastodonQuotedStatus.php
Social/Social/Support/MastodonQuoting.php -> Social/Mastodon/Support/MastodonQuoting.php
Social/Social/Support/MastodonSearchPage.php -> Social/Mastodon/Support/MastodonSearchPage.php
Social/Social/Support/MastodonSearchPath.php -> Social/Mastodon/Support/MastodonSearchPath.php
Social/Social/Support/MastodonStatusAction.php -> Social/Mastodon/Support/MastodonStatusAction.php
Social/Social/Support/MastodonStatusAddress.php -> Social/Mastodon/Support/MastodonStatusAddress.php
Social/Social/Support/MastodonStatusUrl.php -> Social/Mastodon/Support/MastodonStatusUrl.php
Social/Social/Support/MastodonTimelinePage.php -> Social/Mastodon/Support/MastodonTimelinePage.php
Social/Social/Support/MastodonVisibility.php -> Social/Mastodon/Support/MastodonVisibility.php
Social/Social/TimelineSource/MastodonTimelineSource.php -> Social/Mastodon/MastodonTimelineSource.php
```

**Interfaces:**
- Consumes: the interfaces in `Social/Social/*/…Interface` and `Social/Notification/Notification(Context)Source/…Interface`, unchanged.
- Produces: namespace `App\Service\Social\Mastodon` (root), `…\Model`, `…\Support`, `…\Pass`.

- [ ] **Step 1:** `git mv` each file as listed, and each test named after a moved class. Set namespaces, rewrite imports in `backend/src`, `backend/tests`, `backend/migrations` (`MastodonProfileFeed` in both migrations) and `backend/src/Dto` (`MastodonCredentials`, `MastodonVisibility`). Add the `use` lines the moved classes now need for `Social/Social` and `Social/Notification` classes, and the ones `Social/Social` tests and `tests/Support` need for the moved classes.
- [ ] **Step 2:** Run `grep -rnE 'Social\\(Social|Notification)\\([A-Za-z]+\\)?(Mastodon[A-Za-z]*|LinkHeader)\b' backend docs`. Expected: no output.
- [ ] **Step 3:** Run `grep -rn 'Service\\Social\\Mastodon' backend/src/Service/Social/Social backend/src/Service/Social/Notification backend/src/Service/Social/Share backend/src/Service/Social/Completion backend/src/Service/Social/BlueskyPost`. Expected: no output.
- [ ] **Step 4:** Run `composer check && composer md && php bin/phpunit`. Expected: green; `NetworkRulesCompleteTest` and `EveryApplicationServiceBuildsTest` among the passing tests.
- [ ] **Step 5:** Commit `refactor(#2359): Mastodon's account side is Social/Mastodon`.

### Task 4: `Social/Lemmy`

**Files:**
```
Social/Notification/NotificationContextSource/LemmyNotificationContextSource.php -> Social/Lemmy/LemmyNotificationContextSource.php
Social/Notification/NotificationSource/LemmyNotificationSource.php -> Social/Lemmy/LemmyNotificationSource.php
Social/Notification/Support/LemmyCommentPath.php -> Social/Lemmy/Support/LemmyCommentPath.php
Social/Social/CandidateSource/LemmyCandidateSource.php -> Social/Lemmy/LemmyCandidateSource.php
Social/Social/ComposerDefaults/LemmyComposerDefaults.php -> Social/Lemmy/LemmyComposerDefaults.php
Social/Social/ImportRule/LemmyImportRule.php -> Social/Lemmy/LemmyImportRule.php
Social/Social/LemmyAccountApi.php -> Social/Lemmy/LemmyAccountApi.php
Social/Social/LemmyAccountThreads.php -> Social/Lemmy/LemmyAccountThreads.php
Social/Social/LemmyApiClient.php -> Social/Lemmy/LemmyApiClient.php
Social/Social/LemmyCommunities.php -> Social/Lemmy/LemmyCommunities.php
Social/Social/LemmyPostEntries.php -> Social/Lemmy/LemmyPostEntries.php
Social/Social/LemmyPosts.php -> Social/Lemmy/LemmyPosts.php
Social/Social/Model/LemmyListing.php -> Social/Lemmy/Model/LemmyListing.php
Social/Social/Model/LemmySessionModel.php -> Social/Lemmy/Model/LemmySessionModel.php
Social/Social/Model/LemmyTargetModel.php -> Social/Lemmy/Model/LemmyTargetModel.php
Social/Social/NetworkVerifier/LemmyVerifier.php -> Social/Lemmy/LemmyVerifier.php
Social/Social/PostAddressRule/LemmyPostAddressRule.php -> Social/Lemmy/LemmyPostAddressRule.php
Social/Social/PostResolver/LemmyPostResolver.php -> Social/Lemmy/LemmyPostResolver.php
Social/Social/PostVoter/LemmyPostVoter.php -> Social/Lemmy/LemmyPostVoter.php
Social/Social/PostWriter/LemmyPostWriter.php -> Social/Lemmy/LemmyPostWriter.php
Social/Social/PrivateFeedRule/LemmyPrivateFeedRule.php -> Social/Lemmy/LemmyPrivateFeedRule.php
Social/Social/ProfileFeedRule/LemmyProfileFeedRule.php -> Social/Lemmy/LemmyProfileFeedRule.php
Social/Social/ProfilePictureSource/LemmyProfilePictures.php -> Social/Lemmy/LemmyProfilePictures.php
Social/Social/SearchSource/LemmySearchSource.php -> Social/Lemmy/LemmySearchSource.php
Social/Social/Support/LemmyCommunity.php -> Social/Lemmy/Support/LemmyCommunity.php
Social/Social/Support/LemmyCredentials.php -> Social/Lemmy/Support/LemmyCredentials.php
Social/Social/Support/LemmyLanguageTag.php -> Social/Lemmy/Support/LemmyLanguageTag.php
Social/Social/Support/LemmyProfileFeed.php -> Social/Lemmy/Support/LemmyProfileFeed.php
Social/Social/Support/LemmySearchPath.php -> Social/Lemmy/Support/LemmySearchPath.php
Social/Social/Support/LemmySearchTerms.php -> Social/Lemmy/Support/LemmySearchTerms.php
Social/Social/Support/LemmySearchedPost.php -> Social/Lemmy/Support/LemmySearchedPost.php
Social/Social/Support/LemmyView.php -> Social/Lemmy/Support/LemmyView.php
Social/Social/TimelineSource/LemmyTimelineSource.php -> Social/Lemmy/LemmyTimelineSource.php
```

**Interfaces:**
- Produces: namespace `App\Service\Social\Lemmy` (root), `…\Model`, `…\Support`.

- [ ] **Step 1:** Move as in Task 3 Step 1. Besides `src` and `tests`: `LemmyProfileFeed` in both migrations and in `Library/EntryList`, `LemmyCredentials` in `backend/src/Dto`, and the `LemmyNotificationSource` override in `backend/config/services_test.yaml` (new id `App\Service\Social\Lemmy\LemmyNotificationSource`).
- [ ] **Step 2:** Run `grep -rnE 'Social\\(Social|Notification)\\([A-Za-z]+\\)?Lemmy[A-Za-z]*\b' backend docs`. Expected: no output.
- [ ] **Step 3:** Run the Task 3 Step 3 grep with `Lemmy`. Expected: no output.
- [ ] **Step 4:** Run `composer check && composer md && php bin/phpunit`. Expected: green.
- [ ] **Step 5:** Commit `refactor(#2359): Lemmy's account side is Social/Lemmy`.

### Task 5: `Social/Bluesky`

**Files:**
```
Social/Notification/BlueskyPostLookup.php -> Social/Bluesky/BlueskyPostLookup.php
Social/Notification/NotificationContextSource/BlueskyNotificationContextSource.php -> Social/Bluesky/BlueskyNotificationContextSource.php
Social/Notification/NotificationSource/BlueskyNotificationSource.php -> Social/Bluesky/BlueskyNotificationSource.php
Social/Notification/Support/BlueskyReplyReferences.php -> Social/Bluesky/Support/BlueskyReplyReferences.php
Social/Social/BlueskyApiClient.php -> Social/Bluesky/BlueskyApiClient.php
Social/Social/BlueskyExternalEmbeds.php -> Social/Bluesky/BlueskyExternalEmbeds.php
Social/Social/BlueskyFacets.php -> Social/Bluesky/BlueskyFacets.php
Social/Social/BlueskyIdentityResolver.php -> Social/Bluesky/BlueskyIdentityResolver.php
Social/Social/BlueskyPostKeys.php -> Social/Bluesky/BlueskyPostKeys.php
Social/Social/BlueskyRecords.php -> Social/Bluesky/BlueskyRecords.php
Social/Social/BlueskySavedCollections.php -> Social/Bluesky/BlueskySavedCollections.php
Social/Social/BlueskySessionCache.php -> Social/Bluesky/BlueskySessionCache.php
Social/Social/BlueskySessionForgetListener.php -> Social/Bluesky/BlueskySessionForgetListener.php
Social/Social/BlueskySessionOpener.php -> Social/Bluesky/BlueskySessionOpener.php
Social/Social/BlueskySessions.php -> Social/Bluesky/BlueskySessions.php
Social/Social/BlueskyThreadReplies.php -> Social/Bluesky/BlueskyThreadReplies.php
Social/Social/CandidateSource/BlueskyCandidateSource.php -> Social/Bluesky/BlueskyCandidateSource.php
Social/Social/ComposerDefaults/BlueskyComposerDefaults.php -> Social/Bluesky/BlueskyComposerDefaults.php
Social/Social/Crypto/BlueskySessionCipher.php -> Social/Bluesky/BlueskySessionCipher.php
Social/Social/Exception/BlueskyProfileHiddenException.php -> Social/Bluesky/Exception/BlueskyProfileHiddenException.php
Social/Social/HiddenImportFollows.php -> Social/Bluesky/HiddenImportFollows.php
Social/Social/HiddenProfileAccountOnlyFeed.php -> Social/Bluesky/HiddenProfileAccountOnlyFeed.php
Social/Social/HiddenProfileMove.php -> Social/Bluesky/HiddenProfileMove.php
Social/Social/HiddenProfileSubscribeRoute.php -> Social/Bluesky/HiddenProfileSubscribeRoute.php
Social/Social/HiddenProfileSwitchCursor.php -> Social/Bluesky/HiddenProfileSwitchCursor.php
Social/Social/HiddenProfileSwitchSweep.php -> Social/Bluesky/HiddenProfileSwitchSweep.php
Social/Social/HiddenProfiles/AppViewHiddenProfiles.php -> Social/Bluesky/HiddenProfiles/AppViewHiddenProfiles.php
Social/Social/HiddenProfiles/CachedHiddenProfiles.php -> Social/Bluesky/HiddenProfiles/CachedHiddenProfiles.php
Social/Social/HiddenProfiles/HiddenProfilesInterface.php -> Social/Bluesky/HiddenProfiles/HiddenProfilesInterface.php
Social/Social/HomeServerCheck/BlueskyHomeServerCheck.php -> Social/Bluesky/BlueskyHomeServerCheck.php
Social/Social/ImportRule/BlueskyImportRule.php -> Social/Bluesky/BlueskyImportRule.php
Social/Social/Model/BlueskyIdentityModel.php -> Social/Bluesky/Model/BlueskyIdentityModel.php
Social/Social/Model/BlueskyPostKeyModel.php -> Social/Bluesky/Model/BlueskyPostKeyModel.php
Social/Social/Model/BlueskySessionModel.php -> Social/Bluesky/Model/BlueskySessionModel.php
Social/Social/Model/HiddenProfileModel.php -> Social/Bluesky/Model/HiddenProfileModel.php
Social/Social/Model/HiddenProfileMoveOutcome.php -> Social/Bluesky/Model/HiddenProfileMoveOutcome.php
Social/Social/Model/HiddenProfileSwitchReportModel.php -> Social/Bluesky/Model/HiddenProfileSwitchReportModel.php
Social/Social/Model/ProfileVisibility.php -> Social/Bluesky/Model/ProfileVisibility.php
Social/Social/Model/ProfileVisibilityModel.php -> Social/Bluesky/Model/ProfileVisibilityModel.php
Social/Social/Model/RefusedFeedPositionModel.php -> Social/Bluesky/Model/RefusedFeedPositionModel.php
Social/Social/Model/RefusedProfileSubscriptionModel.php -> Social/Bluesky/Model/RefusedProfileSubscriptionModel.php
Social/Social/Model/ResolvedCollectionsModel.php -> Social/Bluesky/Model/ResolvedCollectionsModel.php
Social/Social/Model/SavedItemsModel.php -> Social/Bluesky/Model/SavedItemsModel.php
Social/Social/Model/SealedBlueskySessionModel.php -> Social/Bluesky/Model/SealedBlueskySessionModel.php
Social/Social/Model/TextSpanModel.php -> Social/Bluesky/Model/TextSpanModel.php
Social/Social/NetworkVerifier/BlueskyVerifier.php -> Social/Bluesky/BlueskyVerifier.php
Social/Social/Pass/RefusedSubscriptionSelection.php -> Social/Bluesky/Pass/RefusedSubscriptionSelection.php
Social/Social/PostAddressRule/BlueskyPostAddressRule.php -> Social/Bluesky/BlueskyPostAddressRule.php
Social/Social/PostCountsSource/BlueskyAppViewCountsSource.php -> Social/Bluesky/BlueskyAppViewCountsSource.php
Social/Social/PostLiker/BlueskyPostLiker.php -> Social/Bluesky/BlueskyPostLiker.php
Social/Social/PostReposter/BlueskyPostReposter.php -> Social/Bluesky/BlueskyPostReposter.php
Social/Social/PostResolver/BlueskyPostResolver.php -> Social/Bluesky/BlueskyPostResolver.php
Social/Social/PostWriter/BlueskyPostWriter.php -> Social/Bluesky/BlueskyPostWriter.php
Social/Social/PrivateFeedRule/BlueskyPrivateFeedRule.php -> Social/Bluesky/BlueskyPrivateFeedRule.php
Social/Social/ProfileFeedRule/BlueskyProfileFeedRule.php -> Social/Bluesky/BlueskyProfileFeedRule.php
Social/Social/ProfilePictureSource/BlueskyProfilePictures.php -> Social/Bluesky/BlueskyProfilePictures.php
Social/Social/ProfileVisibilityCache.php -> Social/Bluesky/ProfileVisibilityCache.php
Social/Social/ReplyThreadSource/BlueskyReplyThreadSource.php -> Social/Bluesky/BlueskyReplyThreadSource.php
Social/Social/SearchSource/BlueskySearchSource.php -> Social/Bluesky/BlueskySearchSource.php
Social/Social/Support/AppViewAnswer.php -> Social/Bluesky/Support/AppViewAnswer.php
Social/Social/Support/BlueskyActor.php -> Social/Bluesky/Support/BlueskyActor.php
Social/Social/Support/BlueskyAuthor.php -> Social/Bluesky/Support/BlueskyAuthor.php
Social/Social/Support/BlueskyCommentPost.php -> Social/Bluesky/Support/BlueskyCommentPost.php
Social/Social/Support/BlueskyCredentials.php -> Social/Bluesky/Support/BlueskyCredentials.php
Social/Social/Support/BlueskyPostCounts.php -> Social/Bluesky/Support/BlueskyPostCounts.php
Social/Social/Support/BlueskyProfileActor.php -> Social/Bluesky/Support/BlueskyProfileActor.php
Social/Social/Support/BlueskyProfileFeed.php -> Social/Bluesky/Support/BlueskyProfileFeed.php
Social/Social/Support/BlueskyProfileLabels.php -> Social/Bluesky/Support/BlueskyProfileLabels.php
Social/Social/Support/BlueskyRecordUri.php -> Social/Bluesky/Support/BlueskyRecordUri.php
Social/Social/Support/BlueskySearchPage.php -> Social/Bluesky/Support/BlueskySearchPage.php
Social/Social/Support/BlueskySearchPath.php -> Social/Bluesky/Support/BlueskySearchPath.php
Social/Social/Support/BlueskyStrongRef.php -> Social/Bluesky/Support/BlueskyStrongRef.php
Social/Social/Support/BlueskyTextSpans.php -> Social/Bluesky/Support/BlueskyTextSpans.php
Social/Social/Support/BlueskyTid.php -> Social/Bluesky/Support/BlueskyTid.php
Social/Social/Support/BlueskyTimelinePage.php -> Social/Bluesky/Support/BlueskyTimelinePage.php
Social/Social/Support/CuratedBlueskyList.php -> Social/Bluesky/Support/CuratedBlueskyList.php
Social/Social/TimelineSource/BlueskyTimelineSource.php -> Social/Bluesky/BlueskyTimelineSource.php
Social/Social/TolerantHiddenProfiles.php -> Social/Bluesky/TolerantHiddenProfiles.php
```

**Interfaces:**
- Consumes: `Social/BlueskyPost` from Task 2 (the new module may import it; `BlueskyPost` never imports `Social/Bluesky`).
- Produces: namespace `App\Service\Social\Bluesky` (root), `…\Model`, `…\Support`, `…\Pass`, `…\Exception`, `…\HiddenProfiles`.

- [ ] **Step 1:** Move as in Task 3 Step 1. Besides `src` and `tests`: `BlueskyProfileFeed` in both migrations; `BlueskyCredentials` in `backend/src/Dto`; `BlueskyProfileHiddenException` and `HiddenProfileSwitchReportModel` in `backend/src/Http`; `HiddenProfileSwitchSweep` and its report model in `Operations/Maintenance` and `Operations/Worker`; `RefusedFeedPositionModel` in `backend/src/Repository/RefusedProfileSubscriptionRepository.php`; in `backend/config/services.yaml` the alias `HiddenProfilesInterface: '@…AppViewHiddenProfiles'`, and in `backend/config/services_test.yaml` the `HiddenProfilesInterface` alias and the `CachedHiddenProfiles` override, all three under `App\Service\Social\Bluesky\HiddenProfiles\`.
- [ ] **Step 2:** Run `grep -rnE 'Social\\(Social|Notification)\\([A-Za-z]+\\)?(Bluesky|SealedBluesky|CuratedBluesky|HiddenProfile|HiddenImportFollows|TolerantHiddenProfiles|ProfileVisibility|AppView|Refused|ResolvedCollectionsModel|SavedItemsModel|TextSpanModel)' backend docs`. Expected: no output (`App\Repository\RefusedProfileSubscriptionRepository` is no match; it stays).
- [ ] **Step 3:** Run `grep -rnE 'Service\\Social\\(Bluesky|BlueskyPost|Mastodon|Lemmy)\\' backend/src/Service/Social/Social backend/src/Service/Social/Notification backend/src/Service/Social/Share`. Expected: no output. The same grep over `backend/src/Service/Social/Completion` shows only `BlueskyPost\` (`PostEnricher`, `EntryEmbedWriterInterface`, `FillOutcome`, `BlueskyPostView`), and over `backend/src/Service/Social/BlueskyPost` nothing but its own namespace.
- [ ] **Step 4:** Run `find backend/src/Service/Social/Social backend/src/Service/Social/Notification -name '*Bluesky*' -o -name '*Mastodon*' -o -name '*Lemmy*'`. Expected: no output. `backend/src/Service/Social/Social/Crypto` holds `SocialCredentialCipher.php` only; `HiddenProfiles/` is gone.
- [ ] **Step 5:** Run `composer check && composer md && php bin/phpunit`. Expected: green.
- [ ] **Step 6:** Commit `refactor(#2359): Bluesky's account side is Social/Bluesky`.

### Task 6: Documentation, cross-checks and PR

**Files:**
- Modify: `docs/architecture.md` §9 (table row `Social`: `Social, Notification, Completion, Share, BlueskyPost, Bluesky, Mastodon, Lemmy`; one bullet: a network's account side is its module, which depends on `Social/Social` and `Social/Notification` and is named by neither) and §10 (the #2358 bullet: the adapters sit in the network module's root, the helper example is `Social/Bluesky/Support/BlueskyProfileFeed`, the listener example keeps its name).

- [ ] **Step 1:** Update the two sections; search `docs/` and `CLAUDE.md` for any other path this plan moved and correct it.
- [ ] **Step 2:** Migrate from empty on both dialects and validate, as the CI migration leg does (`doctrine:migrations:migrate --no-interaction` on an empty SQLite file and an empty MySQL schema that is **not** the dev database, then `doctrine:schema:validate`). Expected: both succeed.
- [ ] **Step 3:** Stale cache: on the dev stack, before deploying the branch code, note whether the app cache holds a profile-visibility or switch-cursor entry; with the branch code running, trigger the hidden-profile switch sweep once and read today's `backend/var/log/dev-*.log`. Expected: at most a cache warning about a class not found and a normal sweep; no exception, no failed tick. If it errors, stop and report to the planner.
- [ ] **Step 4:** Run `docker compose exec php composer test:parallel` and `composer infection:diff`. Expected: green; the mutation gate has no executable changed line to score.
- [ ] **Step 5:** Run `/simplify` on the branch; a finding that renames or rewrites code is out of scope and goes into the PR text instead.
- [ ] **Step 6:** Push, open the PR into `develop` with `Closes #2359`, naming the out-of-scope items above as known leftovers. Merge when CI is green; verify #2359 closed.
