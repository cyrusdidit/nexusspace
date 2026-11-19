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
- [x] Centralize session settings: HttpOnly, SameSite=Lax, Secure on HTTPS, cookie-only sessions, strict session mode, and ID regeneration after login/registration.
- [ ] Verify authenticated session expiry, logout invalidation, and production HTTPS/proxy configuration end to end.
- [x] Enforce a 30-minute authenticated idle timeout and a 12-hour absolute session lifetime; active-user heartbeats extend only the idle timeout.
- [x] Allow `NEXUSSPACE_FORCE_SECURE_COOKIE=1` for deployments where HTTPS terminates at a trusted reverse proxy.
- [ ] Add server-side rate limits for login, registration, messages, friend requests, comments, uploads, and integration requests.
- [x] Add database-backed limits for login by IP/account and registration by IP.
- [x] Add rate limits for messages, friend requests, comments, post media uploads, and Spotify song requests.
- [x] Limit profile picture and user background image uploads per account.
- [x] Add per-account rate limits for Spotify and Steam activity refresh endpoints.
- [x] Apply the pending 20261104–20261112 schema migrations to the local database; browser behavior still needs verification.
- [ ] Audit custom HTML/CSS sanitization for stored XSS, unsafe URLs, SVG/MathML, attributes, CSS escapes, and styles escaping the profile. Decide whether to replace the custom sanitizer with a maintained library; the original plan names HTML Purifier.
- [x] Block custom SVG/MathML markup, namespaced attributes, unsafe URL schemes, and CSS escape obfuscation.
- [ ] Audit output escaping and SQL queries throughout the app; retain prepared statements for user-controlled values.
- [x] Audit upload MIME/content checks, size and count limits, ownership, filenames, and deletion paths; cap avatar image dimensions and block script execution in upload directories.
- [x] Serve post media through post visibility and block checks while preserving byte-range video requests.
- [ ] Introduce CSP in report-only mode, resolve violations, then enforce it. Account for Spotify playback, media, custom profiles, and inline styles/scripts.
- [x] Set content-type sniffing, framing, and referrer protections in Apache.
- [ ] Confirm the headers work in Laragon and add a CSP in report-only mode.
- [x] Add Apache rules to disable directory listing and block hidden files and internal directories.
- [x] Deny web access to script and HTML files inside uploads.
- [ ] Confirm the Apache rules work in Laragon and also secure the production web server.
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
- [ ] Apply `database/20261117_add_messages_friend_pins.sql` to existing databases before deploying.
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
- [ ] Finish the Messages custom CSS panel or remove its unfinished entry point until ready.
- [ ] Check keyboard navigation, focus indicators, accessible labels, contrast, and reduced-motion behavior.
- [ ] Review dashboard, profile, Messages, and Settings for consistent spacing and retro styling.

## 5. Reliability, setup, and performance

- [ ] Add a README with Laragon setup, database import, environment settings, integration setup, and common startup errors.
- [ ] Reconcile the fresh schema and migration documentation, including the shared-Steam-account migration. Fresh installs must not rerun historical migrations.
- [ ] Test fresh installation and upgrades separately, including MySQL/MariaDB compatibility.
- [ ] Profile polling frequency and queries; pause unnecessary background polling and prevent overlapping requests.
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

## Suggested next task

Finish the authorization, output-escaping, and custom HTML/CSS audits, then add
a report-only Content Security Policy. Verify security behavior in the browser
before deployment preparation and the remaining social features.
