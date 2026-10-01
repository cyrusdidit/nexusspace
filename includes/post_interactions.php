<?php

declare(strict_types=1);

function postInteractionTimestamp(string $value): string
{
    $date = new DateTimeImmutable($value);
    $today = new DateTimeImmutable('today');
    if ($date->format('Y-m-d') === $today->format('Y-m-d')) return 'Today at ' . $date->format('H:i');
    if ($date->format('Y-m-d') === $today->modify('-1 day')->format('Y-m-d')) return 'Yesterday at ' . $date->format('H:i');
    return $date->format('M j, Y') . ' at ' . $date->format('H:i');
}

function loadPostInteractions(mysqli $conn, array $postIds, int $viewerId): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $postIds), static fn (int $id): bool => $id > 0)));
    if (!$ids) return [];
    $idList = implode(',', $ids);
    $result = [];
    foreach ($ids as $id) $result[$id] = ['like_count' => 0, 'liked' => false, 'comments' => []];

    $likes = mysqli_query($conn, "SELECT post_id, COUNT(*) AS like_count, MAX(user_id = {$viewerId}) AS liked FROM post_likes WHERE post_id IN ({$idList}) GROUP BY post_id");
    while ($row = mysqli_fetch_assoc($likes)) {
        $postId = (int) $row['post_id'];
        $result[$postId]['like_count'] = (int) $row['like_count'];
        $result[$postId]['liked'] = (bool) $row['liked'];
    }

    $comments = mysqli_query($conn, "SELECT c.id, c.post_id, c.user_id, c.parent_id, c.content, c.created_at, c.edited_at, c.deleted_at, c.pinned_at, u.username, u.avatar_path FROM post_comments c JOIN users u ON u.id = c.user_id WHERE c.post_id IN ({$idList}) AND NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.blocker_id = {$viewerId} AND b.blocked_id = c.user_id) OR (b.blocker_id = c.user_id AND b.blocked_id = {$viewerId})) ORDER BY c.created_at, c.id");
    $byPost = [];
    while ($comment = mysqli_fetch_assoc($comments)) $byPost[(int) $comment['post_id']][] = $comment;
    foreach ($byPost as $postId => $commentsForPost) {
        $topLevel = [];
        $replies = [];
        foreach ($commentsForPost as $comment) {
            if ($comment['parent_id'] === null) $topLevel[] = $comment;
            else $replies[(int) $comment['parent_id']][] = $comment;
        }
        usort($topLevel, static function (array $a, array $b): int {
            if ($a['pinned_at'] && $b['pinned_at']) return strcmp($a['pinned_at'], $b['pinned_at']);
            if ($a['pinned_at']) return -1;
            if ($b['pinned_at']) return 1;
            return ((int) $a['id']) <=> ((int) $b['id']);
        });
        foreach ($topLevel as &$comment) $comment['replies'] = $replies[(int) $comment['id']] ?? [];
        unset($comment);
        $result[$postId]['comments'] = $topLevel;
    }
    return $result;
}

function renderPostComment(array $comment, int $viewerId, int $postOwnerId, string $assetPrefix, bool $reply = false): void
{
    $deleted = $comment['deleted_at'] !== null;
    if ($deleted && $viewerId !== $postOwnerId) {
        if (!$reply && !empty($comment['replies'])) {
            ?><div class="post-comment-replies is-orphaned"><?php foreach ($comment['replies'] as $child) renderPostComment($child, $viewerId, $postOwnerId, $assetPrefix, true); ?></div><?php
        }
        return;
    }
    $avatarPath = trim((string) ($comment['avatar_path'] ?? ''));
    $avatar = $avatarPath !== '' && !preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $avatarPath) ? $assetPrefix . ltrim($avatarPath, '/') : '';
    $name = htmlspecialchars($comment['username'], ENT_QUOTES, 'UTF-8');
    ?>
    <article class="post-comment<?= $reply ? ' is-reply' : '' ?><?= $comment['pinned_at'] ? ' is-pinned' : '' ?><?= $deleted ? ' is-deleted' : '' ?>" data-comment-id="<?= (int) $comment['id'] ?>">
        <header>
            <span class="post-avatar" aria-hidden="true"><span><?= htmlspecialchars(mb_strtoupper(mb_substr($comment['username'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span><?php if ($avatar): ?><img src="<?= htmlspecialchars($avatar, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy"><?php endif; ?></span>
            <strong><?= $name ?></strong>
            <time datetime="<?= htmlspecialchars(str_replace(' ', 'T', $comment['created_at']), ENT_QUOTES, 'UTF-8') ?>"><?= postInteractionTimestamp($comment['created_at']) ?></time>
            <?php if ($comment['pinned_at']): ?><span class="post-comment-pinned">Pinned</span><?php endif; ?>
        </header>
        <p data-comment-content><?= $deleted ? '<em>Comment deleted</em>' : htmlspecialchars($comment['content'], ENT_QUOTES, 'UTF-8') ?><?php if (!$deleted && $comment['edited_at']): ?> <small>(edited)</small><?php endif; ?></p>
        <?php if ($deleted): ?>
            <div class="post-comment-actions"><button type="button" data-comment-purge>Remove</button></div>
        <?php else: ?>
            <div class="post-comment-actions">
                <?php if (!$reply): ?><button type="button" data-comment-reply>Reply</button><?php endif; ?>
                <?php if ((int) $comment['user_id'] === $viewerId): ?><button type="button" data-comment-edit>Edit</button><button type="button" data-comment-delete>Delete</button><?php endif; ?>
                <?php if (!$reply && $postOwnerId === $viewerId): ?><button type="button" data-comment-pin><?= $comment['pinned_at'] ? 'Unpin' : 'Pin' ?></button><?php endif; ?>
            </div>
            <form class="post-comment-edit" data-comment-edit-form hidden>
                <textarea maxlength="1000" required><?= htmlspecialchars($comment['content'], ENT_QUOTES, 'UTF-8') ?></textarea>
                <button type="submit">Save</button><button type="button" data-comment-edit-cancel>Cancel</button>
            </form>
            <?php if (!$reply): ?>
                <form class="post-reply-form" data-comment-reply-form hidden>
                    <input type="hidden" name="parent_id" value="<?= (int) $comment['id'] ?>">
                    <textarea name="content" maxlength="1000" placeholder="Write a reply..." required></textarea>
                    <button type="submit">Reply</button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (!$reply && !empty($comment['replies'])): ?><div class="post-comment-replies"><?php foreach ($comment['replies'] as $child) renderPostComment($child, $viewerId, $postOwnerId, $assetPrefix, true); ?></div><?php endif; ?>
    </article>
    <?php
}

function renderPostInteractions(array $post, array $interaction, int $viewerId, string $assetPrefix = ''): void
{
    $postId = (int) $post['id'];
    $ownerId = (int) $post['user_id'];
    $liked = (bool) ($interaction['liked'] ?? false);
    $comments = $interaction['comments'] ?? [];
    $commentCount = 0;
    foreach ($comments as $comment) {
        if ($comment['deleted_at'] === null || $ownerId === $viewerId) $commentCount++;
        foreach ($comment['replies'] ?? [] as $reply) {
            if ($reply['deleted_at'] === null || $ownerId === $viewerId) $commentCount++;
        }
    }
    $version = substr(hash('sha256', json_encode([$post['content'] ?? '', $post['edited_at'] ?? null, $interaction])), 0, 16);
    ?>
    <div class="post-interactions" data-post-interactions data-post-version="<?= $version ?>">
        <?php if ($ownerId === $viewerId): ?><div class="post-owner-actions"><button type="button" data-post-edit>Edit</button><button type="button" data-post-delete>Delete</button></div><?php endif; ?>
        <div class="post-action-bar">
            <button type="button" class="post-heart<?= $liked ? ' is-liked' : '' ?>" data-post-like aria-pressed="<?= $liked ? 'true' : 'false' ?>"><span aria-hidden="true"><?= $liked ? '&#9829;' : '&#9825;' ?></span> <span data-like-count><?= (int) ($interaction['like_count'] ?? 0) ?></span></button>
            <button type="button" data-comment-focus>Comment <span><?= $commentCount ?></span></button>
        </div>
        <form class="post-comment-form" data-post-comment-form>
            <textarea name="content" maxlength="1000" placeholder="Write a comment..." required></textarea>
            <button type="submit">Comment</button>
        </form>
        <p class="post-interaction-status" data-post-interaction-status role="status" hidden></p>
        <div class="post-comments" data-post-comments>
            <?php foreach ($comments as $comment) renderPostComment($comment, $viewerId, $ownerId, $assetPrefix); ?>
        </div>
    </div>
    <?php
}

function renderLivePostCard(array $post, array $interaction, int $viewerId, string $context): void
{
    $profileContext = $context === 'profile';
    $assetPrefix = $profileContext ? '../' : '';
    $profileLink = $profileContext ? 'profile.php?id=' : 'pages/profile.php?id=';
    $owned = (int) $post['user_id'] === $viewerId;
    $avatarPath = trim((string) ($post['avatar_path'] ?? ''));
    $avatar = $avatarPath !== '' && !preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $avatarPath) ? $assetPrefix . ltrim($avatarPath, '/') : '';
    ?>
    <article class="post-card<?= $owned ? ' is-owned' : '' ?>" data-post-card data-post-id="<?= (int) $post['id'] ?>">
        <header class="post-header">
            <a class="post-author" href="<?= $profileLink . (int) $post['user_id'] ?>">
                <span class="post-avatar" aria-hidden="true"><span><?= htmlspecialchars(mb_strtoupper(mb_substr($post['username'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span><?php if ($avatar): ?><img src="<?= htmlspecialchars($avatar, ENT_QUOTES, 'UTF-8') ?>" alt="" loading="lazy"><?php endif; ?></span>
                <span><?= htmlspecialchars($post['username'], ENT_QUOTES, 'UTF-8') ?></span>
            </a>
            <div class="post-meta"><time datetime="<?= htmlspecialchars(str_replace(' ', 'T', $post['created_at']), ENT_QUOTES, 'UTF-8') ?>"><?= postInteractionTimestamp($post['created_at']) ?></time><?php if (!empty($post['edited_at'])): ?> &middot; <span data-post-edited>Edited</span><?php endif; ?><?php if ($owned): ?> &middot; <?= $post['visibility'] === 'public' ? 'Public' : 'Friends Only' ?><?php endif; ?></div>
        </header>
        <p class="post-content" data-post-content><?= htmlspecialchars($post['content'], ENT_QUOTES, 'UTF-8') ?></p>
        <?php if ($owned): ?><form class="post-edit-form" data-post-edit-form hidden><textarea maxlength="2500" required><?= htmlspecialchars($post['content'], ENT_QUOTES, 'UTF-8') ?></textarea><div><button type="submit">Save</button><button type="button" data-post-edit-cancel>Cancel</button></div></form><?php endif; ?>
        <?php renderPostInteractions($post, $interaction, $viewerId, $assetPrefix); ?>
    </article>
    <?php
}
