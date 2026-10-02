# Profile Customization Map

This document defines the editable surface of a NexusSpace profile. The simple
editor and advanced HTML/CSS editor must use the same targets and preserve the
same functional boundaries.

## Rules

- Interface wording and user-generated content are not editable. Their visual
  presentation is editable.
- Every visible profile target supports the relevant typography, color,
  background, border, spacing, sizing, alignment, effect, and animation groups.
- Dynamic content and states must retain their application-owned `data-*`
  hooks, form fields, destinations, accessibility text, and live-update roots.
- Owner-only controls may be styled, but customization cannot change their
  action, submitted values, security tokens, or validation limits.
- Custom styles are scoped to the profile and cannot affect application chrome,
  dialogs outside the profile, or another page.
- Desktop sidebar width is customizable. Mobile uses the stacked profile layout.

## Property Groups

| Group | Controls |
| --- | --- |
| Typography | Font family, size, weight, style, line height, letter spacing, alignment, transform, decoration |
| Text paint | Solid color, gradient, opacity, shadow, outline |
| Surface | Solid color, gradient, image where supported, opacity, blur |
| Box | Width, height, minimum/maximum size, margin, padding, gap |
| Border | Color, width, style, corner radius, shadow |
| Layout | Display mode, alignment, justification, direction, wrapping, column count |
| Effects | Blur, brightness, contrast, saturation, grayscale |
| Animation | Preset animation, duration, delay, easing, iteration count, hover-only mode |
| Visibility | Show or hide optional presentation regions without changing their stored content |

Gradient text is rendered with a background gradient clipped to the glyphs.
Simple-mode animations use a safe preset library. Advanced CSS may define
profile-scoped keyframes once the CSS parser supports safe `@keyframes` rules.

## Page And Layout Targets

| Target key | Current surface | Notes |
| --- | --- | --- |
| `profile.page` | `.profile-page` | Profile viewport and base text defaults |
| `profile.sheet` | `.profile-sheet` | Full profile canvas |
| `layout.root` | `.profile-layout` | Sidebar/content grid and overall gap |
| `layout.sidebar` | `.profile-sidebar` | Width, surface, divider, and overflow behavior |
| `layout.sidebar-handle` | New resize handle | Desktop-only drag and arrow control; planned range 220px to min(520px, 45vw) |
| `layout.content` | `.profile-posts` | Main content column and scroll region |
| `profile.copyright` | `.profile-page-copyright` | Footer copyright presentation |

The saved sidebar width applies to the profile owner and all visitors viewing
that profile. The mobile breakpoint ignores the saved width and stacks content.

## Sidebar Targets

| Group | Target keys |
| --- | --- |
| Cover | `cover.container`, `cover.surface`, `cover.image`, `cover.edit-control` |
| Avatar | `avatar.container`, `avatar.image`, `avatar.fallback`, `avatar.activity`, `avatar.edit-overlay` |
| Identity | `identity.container`, `identity.display-name`, `identity.handle-row`, `identity.handle`, `identity.copy-control` |
| Written status | `status.container`, `status.text`, `status.editor`, `status.edit-control`, `status.error` |
| Activity shell | `activity.container`, `activity.row`, `activity.art`, `activity.copy`, `activity.title`, `activity.detail`, `activity.feedback` |
| Spotify activity | `activity.spotify-row`, `activity.spotify-link`, `activity.spotify-add-control` |
| Steam activity | `activity.steam-row`, `activity.steam-duration` |
| Friendship actions | `social.actions`, `social.message-control`, `social.primary-control`, `social.secondary-control` |
| Bio | `bio.container`, `bio.text`, `bio.edit-control`, `bio.editor`, `bio.counter`, `bio.save-control`, `bio.error` |
| Top 8 shell | `top-eight.container`, `top-eight.header`, `top-eight.heading`, `top-eight.reorder-control` |
| Top 8 list | `top-eight.list`, `top-eight.item`, `top-eight.rank`, `top-eight.avatar`, `top-eight.name`, `top-eight.remove-control` |
| Top 8 picker | `top-eight.picker`, `top-eight.picker-header`, `top-eight.search-control`, `top-eight.search-field`, `top-eight.pool-item`, `top-eight.add-control`, `top-eight.save-controls` |
| Sidebar navigation | `sidebar-nav.container`, `sidebar-nav.settings-control`, `sidebar-nav.dashboard-control` |

## Main Content Targets

| Group | Target keys |
| --- | --- |
| Wallpaper | `content.wallpaper`, `content.wallpaper-image`, `content.wallpaper-edit-control` |
| Profile song shell | `profile-song.container`, `profile-song.label`, `profile-song.title`, `profile-song.artist` |
| Profile song controls | `profile-song.volume-control`, `profile-song.volume-slider`, `profile-song.edit-control`, `profile-song.close-control` |
| Profile song picker | `profile-song.picker`, `profile-song.picker-header`, `profile-song.picker-heading`, `profile-song.picker-actions`, `profile-song.picker-close-control`, `profile-song.search-form`, `profile-song.search-label`, `profile-song.search-field`, `profile-song.search-control`, `profile-song.search-status`, `profile-song.search-results`, `profile-song.result-item`, `profile-song.result-image`, `profile-song.result-copy`, `profile-song.result-title`, `profile-song.result-artist`, `profile-song.result-select-control`, `profile-song.remove-control` |
| Posts section | `posts.container`, `posts.heading`, `posts.empty-state`, `posts.list`, `posts.load-more-control` |

## Post Targets

| Group | Target keys |
| --- | --- |
| Card | `post.card`, `post.header`, `post.author`, `post.avatar`, `post.metadata` |
| Content | `post.title`, `post.body` |
| Media | `post.media-grid`, `post.media-item`, `post.image`, `post.video`, `post.video-speed` |
| Owner media tools | `post.media-remove-control`, `post.media-reorder-controls`, `post.media-move-control`, `post.media-replace-control`, `post.media-add-control` |
| Owner post tools | `post.owner-actions`, `post.edit-control`, `post.delete-control`, `post.edit-form`, `post.edit-title-field`, `post.edit-body-field`, `post.edit-actions`, `post.save-control`, `post.cancel-control`, `post.upload-progress` |
| Reactions | `post.action-bar`, `post.heart-control`, `post.comment-control` |
| Comment composer | `comment.composer`, `comment.composer-field`, `comment.composer-submit`, `comment.status` |
| Comment | `comment.card`, `comment.header`, `comment.avatar`, `comment.author`, `comment.timestamp`, `comment.body` |
| Comment states | `comment.pinned`, `comment.deleted`, `comment.reply`, `comment.replies-thread` |
| Comment tools | `comment.actions`, `comment.reply-control`, `comment.edit-control`, `comment.delete-control`, `comment.pin-control`, `comment.purge-control` |
| Comment editors | `comment.edit-form`, `comment.edit-field`, `comment.reply-form`, `comment.reply-field`, `comment.save-control`, `comment.cancel-control`, `comment.reply-submit` |

Repeated targets apply one style to every matching post, media item, comment, or
Top 8 friend. A later per-instance override can be added without changing these
base keys.

## Dynamic States To Preserve

- Owner and visitor views.
- Empty and populated status, bio, activity, profile-song, Top 8, and post states.
- Online, idle, do-not-disturb, and offline avatar indicators.
- Spotify and Steam visibility settings.
- Friend request, friendship, blocked, and unfriend controls.
- Top 8 view, reorder, search, drag, save, and cancel states.
- Profile-song playback, muted volume, picker, search, and removal states.
- Text-only posts and every one-to-five-media arrangement.
- Post editing, media replacement, upload progress, likes, comments, replies,
  pinned comments, deleted comments, and live updates.
- Desktop, narrow desktop, and mobile layouts.

## Protected Behavior

The following remain application-owned even when their visual presentation is
customized:

- Element `data-*` hooks used by JavaScript.
- Form methods, actions, hidden fields, CSRF tokens, and input limits.
- Link destinations and external-link security attributes.
- Usernames, statuses, biographies, activity names, post content, comment
  content, timestamps, counters, and fixed interface wording.
- `hidden`, disabled, loading, error, and live-region semantics.
- Native media playback behavior and accessible labels.
- Minimum usable size and hit area for required controls.

## Existing System Gaps

- The compact preview still omits some live activity, comment, media, and owner
  states that are available on the real profile page.
- Draft autosave and published revision snapshots exist, but the interface does
  not yet expose undo, redo, revision history, or recovery controls.
- Raw HTML structural changes update after saving; CSS and simple-mode changes
  preview directly on the profile.

## Next Implementation Boundary

The next step is preview completeness and cross-viewport QA:

- include live status, activity, profile-song, media, comment, and owner-control
  examples in the compact revision preview
- verify every simple-editor target has a visible preview state
- verify desktop, narrow desktop, and mobile layouts
- test recovery from malformed HTML and CSS using revision restore
