# NexusSpace TODO

Working backlog — 4 November 2026. Based on the timeline and source review;
unchecked audit items are work to verify, not confirmed vulnerabilities.

## 1. Security — before public hosting

- [ ] Audit authorization on every action and JSON endpoint: users must only edit their own profiles, posts, messages, backgrounds, and connections.
- [ ] Test public/friends/private audiences and blocking across feeds, comments, search, messages, notifications, previews, and activity endpoints.
- [x] Blocking now severs friendship and pending requests, removes pair notifications, and requires a new request after unblock.
- [x] Recheck friendship and block state in the message insert itself; hide unread polling data from non-friends.
- [x] Recheck block state under account locks before friend-request actions.
- [x] Add CSRF protection to login and registration; change logout and integration connection starts to protected POST forms.
- [x] Audit the remaining state-changing routes for CSRF coverage; protect dashboard status updates with the shared session token.
- [x] Hide custom statuses marked “No one” from Messages friend previews.
- [x] Require a session CSRF token for activity heartbeat updates.
- [x] Require a session CSRF token for profile customization preview drafts.
- [ ] Review Spotify and Steam callback security: bind linking to the initiating user/browser, enforce expiration and single use, handle rejection, and verify Steam identity assertions.
- [x] Bind Spotify and Steam callback state to the initiating browser session and account.
- [x] Require Steam's signed identity fields to match and reject stale OpenID nonces.
- [x] Centralize session settings: HttpOnly, SameSite=Lax, Secure on HTTPS, cookie-only sessions, strict session mode, and ID regeneration after login/registration.
- [ ] Verify authenticated session expiry, logout invalidation, and production HTTPS/proxy configuration end to end.
- [ ] Add server-side rate limits for login, registration, messages, friend requests, comments, uploads, and integration requests.
- [x] Add database-backed limits for login by IP/account and registration by IP.
- [x] Add rate limits for messages, friend requests, comments, post media uploads, and Spotify song requests.
- [ ] Apply `database/20261105_create_security_rate_limits.sql` to existing installs and verify behavior.
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
- [ ] Move database credentials out of source and use a dedicated database user with limited privileges for deployment.
- [ ] Use HTTPS and production callback URLs when hosted. Preserve certificate verification for outbound requests.
- [ ] Show safe errors to users and log useful diagnostics without tokens, API keys, authorization codes, or passwords. Disable public PHP stack traces in production.
- [ ] Add focused regression tests for authorization, CSRF, audiences, blocking, sanitizer bypasses, and upload validation.

## 2. Account connections

- [x] Remove the unused legacy Spotify playback endpoint that returned the account token.
- [ ] Verify Spotify connect, reconnect, disconnect, token refresh, current activity, song search, and playback through the browser.
- [ ] Verify Steam connect, reconnect, disconnect, game activity, and one Steam account shared by multiple NexusSpace accounts.
- [ ] Test status visibility independently for custom status, Spotify, and Steam, as owner/friend/stranger/blocked user.
- [ ] Make failed connection messages useful and distinguish configuration, provider denial, expired requests, and network failures.

## 3. Finish the planned social features

- [ ] Pin up to three posts on a profile, with predictable ordering and owner-only controls.
- [ ] Order dashboard friends by latest interaction, including messages, comments, and likes.
- [ ] Add up to three dashboard friend pins, ordered by pin time and independent of profile Top 8.
- [ ] Add up to three Messages friend pins, independent of dashboard pins and Top 8.
- [ ] Add a real typing indicator with expiry and cleanup when a conversation closes.
- [ ] Cap the displayed notification count at `9+` while preserving the real count.
- [ ] Verify notification creation, read/unread state, destinations, deduplication, and deleted or inaccessible content.

## 4. Profile customization and visual polish

- [ ] Check the condensed Top 8 and lowered position on desktop, short windows, narrow sidebars, and mobile.
- [ ] Verify empty bio prompt appears only to the owner; visitors get a blank box. Test saving and clearing a bio.
- [ ] Complete customization previews for activity, profile songs, post media, comments, and owner controls.
- [ ] Verify each simple-editor target changes the intended element and preserves working controls.
- [ ] Expose saved revision history and restore/recovery controls; session undo/redo already exists.
- [ ] Test malformed HTML/CSS recovery and draft autosave across reloads.
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
- [ ] Run an end-to-end pass with two accounts: register, log in, friend request, accept, message, post, comment, reply, like, block, unblock, and log out.
- [ ] Prepare demonstration accounts/content and a short final demo flow.

## Suggested next task

Start with authorization/privacy and CSRF coverage, then session hardening and
customization sanitization. Finish the remaining social features after those
checks, followed by browser/mobile QA and deployment preparation.
