# NexusSpace TODO

Working backlog — 13 November 2026. Based on the timeline and source review;
unchecked audit items are work to verify, not confirmed vulnerabilities.

## 1. Security — before public hosting

- [ ] Audit authorization on every action and JSON endpoint: users must only edit their own profiles, posts, messages, backgrounds, and connections.
- [ ] Test public/friends/private audiences and blocking across feeds, comments, search, messages, notifications, previews, and activity endpoints.
- [x] Blocking now severs friendship and pending requests, removes pair notifications, and requires a new request after unblock.
- [x] Recheck friendship and block state in the message insert itself; hide unread polling data from non-friends.
- [x] Recheck post visibility and block state atomically when adding comments and replies.
- [x] Recheck block state under account locks before friend-request actions.
- [x] Add CSRF protection to login and registration; change logout and integration connection starts to protected POST forms.
- [x] Audit the remaining state-changing routes for CSRF coverage; protect dashboard status updates with the shared session token.
- [x] Hide custom statuses marked “No one” from Messages friend previews.
- [x] Require a session CSRF token for activity heartbeat updates.
- [x] Require a session CSRF token for profile customization preview drafts.
- [x] Review Spotify and Steam callback security: bind linking to the initiating user/browser, enforce expiration and single use, handle rejection, and verify Steam identity assertions.
- [x] Bind Spotify and Steam callback state to the initiating browser session and account.
- [x] Require Steam's signed identity fields to match and reject stale OpenID nonces.
- [x] Centralize session settings: HttpOnly, SameSite=Lax, Secure on HTTPS, cookie-only sessions, strict session mode, no URL-based session IDs, and ID regeneration after login/registration.
- [x] Give NexusSpace a dedicated session-cookie name and scope its path to the app directory.
- [x] Set session-backed PHP responses to `Cache-Control: no-store` so private pages and session tokens are not retained in browser or proxy caches.
- [ ] Verify authenticated session expiry, logout invalidation, and production HTTPS/proxy configuration end to end.
- [x] Enforce a 30-minute authenticated idle timeout and a 12-hour absolute session lifetime; active-user heartbeats extend only the idle timeout.
- [x] Allow `NEXUSSPACE_FORCE_SECURE_COOKIE=1` for deployments where HTTPS terminates at a trusted reverse proxy.
- [x] Add server-side rate limits for login, registration, messages, friend requests, comments, uploads, and Spotify/Steam connection starts and activity requests.
- [x] Add database-backed limits for login by IP/account and registration by IP.
- [x] Add rate limits for messages, friend requests, comments, post media uploads, and Spotify song requests.
- [x] Limit profile picture and user background image uploads per account.
- [x] Add per-account rate limits for Spotify and Steam activity refresh endpoints.
- [x] Apply the pending 20261104–20261112 schema migrations to the local database; browser behavior still needs verification.
- [ ] Audit custom HTML/CSS sanitization for stored XSS, unsafe URLs, SVG/MathML, attributes, CSS escapes, and styles escaping the profile. Decide whether to replace the custom sanitizer with a maintained library; the original plan names HTML Purifier.
- [x] Block parser-sensitive raw-text and embedded elements from profile templates to reduce browser/parser interpretation differences.
- [x] Validate profile-template resource URLs and remove unparsed `srcset` and link-ping attributes.
- [x] Block non-static CSS positioning, including values supplied through CSS variables, to keep custom styles inside the profile layout.
- [x] Block custom SVG/MathML markup, namespaced attributes, unsafe URL schemes, and CSS escape obfuscation.
- [ ] Audit output escaping and SQL queries throughout the app; retain prepared statements for user-controlled values.
- [x] Bind post/comment/media ID lists and comment viewer IDs in prepared statements instead of interpolating integers into SQL.
- [x] Restrict Spotify image URLs to HTTPS and clickable Spotify destinations to `open.spotify.com`.
- [x] Bind profile author, pagination cursor, viewer, friendship, and block filters in the post refresh endpoint.
- [x] Audit upload MIME/content checks, size and count limits, ownership, filenames, and deletion paths; cap avatar image dimensions and block script execution in upload directories.
- [x] Serve post media through post visibility and block checks while preserving byte-range video requests.
- [x] Add an initial report-only CSP in Apache for same-origin scripts/styles/API requests, external HTTPS images, and the Spotify embed.
- [ ] Review browser CSP reports on the dashboard, profile, and Messages pages. Source review already found inline style attributes and generated profile style blocks; account for those and any other required resources before enforcing the policy. Account for Spotify embeds, media, custom profiles, and inline scripts/styles.
- [x] Set content-type sniffing, framing, and referrer protections in Apache.
- [x] Confirm the security headers work in Laragon.
- [x] Add Apache rules to disable directory listing and block hidden files and internal directories.
- [x] Deny web access to script and HTML files inside uploads.
- [x] Confirm Apache blocks hidden files, internal directories, and executable upload paths in Laragon; directory indexes are disabled.
- [ ] Confirm equivalent protections on the production web server.
- [x] Move local database credentials to ignored `.env` configuration and use a schema-scoped application account with CRUD privileges.
- [ ] Configure and verify the production database account and secret outside the source tree.
- [ ] Use HTTPS and production callback URLs when hosted. Preserve certificate verification for outbound requests.
- [ ] Show safe errors to users and log useful diagnostics without tokens, API keys, authorization codes, or passwords. Disable public PHP stack traces in production.
- [ ] Add focused regression tests for authorization, CSRF, audiences, blocking, sanitizer bypasses, and upload validation.
- [x] Enforce a 64-character maximum for registration passwords in server validation and both registration fields.

## 2. Account connections

- [x] Require an explicit click on the embedded Spotify player to start a profile song; unrelated page clicks no longer start playback.
- [x] Remove the unused legacy Spotify playback endpoint that returned the account token.
- [ ] Verify Spotify connect, reconnect, disconnect, token refresh, current activity, song search, and playback through the browser.
- [ ] Verify Steam connect, reconnect, disconnect, game activity, and one Steam account shared by multiple NexusSpace accounts.
- [ ] Test status visibility independently for custom status, Spotify, and Steam, as owner/friend/stranger/blocked user.
- [x] Make failed connection messages useful and distinguish configuration, provider denial, expired requests, and network failures.

## 3. Finish the planned social features

- [x] Remove post likes from the UI, endpoint behavior, notifications, profile customization targets, and fresh-install schema.
- [x] Add and apply `database/20261109_remove_post_likes.sql` locally; run it on other existing installations to delete old post-like notifications and drop `post_likes`.
- [x] Pin up to three posts on a profile, ordered by pin time with owner-only controls.
- [x] Add and apply `database/20261112_add_profile_post_pins.sql` locally; apply it to existing databases before deploying.
- [x] Order unpinned dashboard friends by latest messages or comments on each other's posts; keep dashboard pins and Top 8 as separate ordering rules.
- [x] Add up to three dashboard friend pins, ordered by pin time and independent of profile Top 8.
- [x] Add up to three Messages friend pins, independent of dashboard pins and Top 8.
- [x] Add `messages_pinned_at` to the fresh schema and add its upgrade migration.
- [x] Apply `database/20261117_add_messages_friend_pins.sql` to the local database; apply it to other existing databases before deploying.
- [x] Add a real typing indicator with expiry, conversation-close cleanup, and animated dots in Messages and mini-chat.
- [x] Cap the displayed notification count at `99+` while preserving the real count for accessibility and updates.
- [ ] Consider a privacy-respecting profile visitor history as a replacement for profile-view notifications.
- [ ] Verify notification creation, read/unread state, destinations, deduplication, and deleted or inaccessible content.

## 4. Profile customization and visual polish

- [x] Review the condensed Top 8 and lowered position across desktop, short windows, narrow sidebars, and mobile; truncate long friend names on narrow rows.
- [x] Show the empty bio prompt only to the owner; keep the visitor's empty bio box blank.
- [x] Test saving and clearing a bio; verify the handler redirects after save and shows a blank-bio prompt only to the owner.
- [x] Verify customization previews for activity, profile songs, post media, comments, and owner controls; confirm target markers are present in preview markup or shared renderers.
- [x] Verify simple-editor targets against live profile markup and generated controls; remove stale profile-song volume targets that had no live controls.
- [x] Expose saved revision history with preview and restore controls; preserve an unsaved draft as a recoverable revision before restore.
- [x] Test malformed HTML/CSS recovery; preserve raw drafts, warn and sanitize previews, and allow corrected content to replace them.
- [x] Test draft autosave, recovery after reloads, and cleanup after publishing.
- [ ] Update `docs/profile-customization-map.md` where it no longer matches the implementation.
- [x] Remove the Messages custom CSS entry point until a working editor is ready.
- [ ] Check keyboard navigation, focus indicators, accessible labels, contrast, and reduced-motion behavior.
- [ ] Review dashboard, profile, Messages, and Settings for consistent spacing and retro styling.

## 5. Reliability, setup, and performance

- [x] Add a README with Laragon setup, database import, environment settings, integration setup, and common startup errors.
- [ ] Reconcile the fresh schema and migration documentation, including the shared-Steam-account migration. Fresh installs must not rerun historical migrations.
- [ ] Test fresh installation and upgrades separately, including MySQL/MariaDB compatibility.
- [ ] Profile polling frequency and queries; pause unnecessary background polling and prevent overlapping requests.
- [x] Skip redundant own-profile presence polls, refresh visitor presence every 15 seconds, and prevent overlapping requests.
- [ ] Check indexes and pagination with larger message, friend, post, comment, and notification datasets.
- [ ] Define upload/storage limits and cleanup for expired OAuth requests, drafts/revisions, and unused media.
- [ ] Add a database/upload backup and restore procedure and test restoration.
- [ ] Run an end-to-end pass with two accounts after the planned like removal: register, log in, friend request, accept, message, post, comment, reply, block, unblock, and log out.
- [ ] Prepare demonstration accounts/content and a short final demo flow.

## 6. Qualification documentation

- [ ] Complete the Latvian and English abstracts and generate the contents page from final headings and page numbers.
- [ ] Match the Myura World example's A4 page setup, cover layout and date placement, heading hierarchy, paragraph and table formatting, figure/table captions, page numbering, and numbered references.
- [ ] Reconcile requirements with the current NexusSpace implementation and planned changes; distinguish completed features from future work and resolve contradictions such as post likes and password length.
- [ ] Complete system modeling with architecture, database/entity descriptions, and relevant use-case, sequence, and component diagrams.
- [ ] Complete the user guide with verified installation/access steps and annotated screenshots of the main workflows.
- [ ] Complete test examples with scenarios, expected results, observed results, and evidence for core account, profile, friendship, post, comment, messaging, privacy, and integration features.
- [ ] Write conclusions based on the completed requirements and test results; complete the glossary, reference list, and appendices, including checking every in-text citation against the bibliography.
- [ ] Replace remaining instructional placeholders, correct figure descriptions and captions, and remove or redact configuration details in screenshots.

## 7. User feedback (translated)

- [x] Allow post visibility (public/friends) to be changed while editing, so a visibility mistake does not require deleting and recreating the post.
- [x] Prevent overlapping post actions: only one post draft or edit can be active at a time; minimizing keeps a draft locked, while Discard clears it.
- [x] Replace browser-native post deletion confirmations with an in-page confirmation dialog that supports keyboard cancel and an explicit Delete action.
- [x] Show current comment, reply, and comment-edit character counts; turn the counter red near the limit and highlight it strongly at the limit.
- [x] Show live current/max character counts for post title and body while editing; warn in red near the limits.
- [x] Make bio editing clearer with the shared pencil icon, a highlighted editor, and a labeled Save button.
- [x] Redesign the logged-out landing page with a public feed beside a welcome panel and clear registration/login links; stack the layout on smaller screens.
- [x] Truncate long public posts to 100 characters with a built-in Read more / Show less disclosure.
- [x] Add admin-configured post removal, author appeals, restore/review controls, a seven-day appeal review target, and a 30-day purge job that preserves posts with pending appeals.
- [x] Confirmed `posts.media_type` and `posts.media_path` are still used by legacy post queries and the media fallback; retain them until those paths are migrated.
- [x] Add a direct, CSRF-protected logout action to the dashboard sidebar.

## Suggested next task

Continue the authorization, output-escaping, and custom HTML/CSS audits, then
review the existing report-only Content Security Policy in a browser. Verify
session behavior and production server configuration before deployment.
