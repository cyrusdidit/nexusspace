<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/db_connect.php';

session_start();

function postTimestamp(string $value): string
{
    $date = new DateTimeImmutable($value);
    $today = new DateTimeImmutable('today');
    if ($date->format('Y-m-d') === $today->format('Y-m-d')) {
        return 'Today at ' . $date->format('H:i');
    }
    if ($date->format('Y-m-d') === $today->modify('-1 day')->format('Y-m-d')) {
        return 'Yesterday at ' . $date->format('H:i');
    }
    return $date->format('M j, Y') . ' at ' . $date->format('H:i');
}

function postAvatarPath(?string $path): string
{
    $path = trim($path ?? '');
    // Only use local image paths, as in the dashboard user search.
    if ($path === '' || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $path)) {
        return '';
    }
    return htmlspecialchars($path, ENT_QUOTES, 'UTF-8');
}

function renderPostAuthor(array $post): void
{
    $name = htmlspecialchars($post['username'], ENT_QUOTES, 'UTF-8');
    $initial = htmlspecialchars(mb_strtoupper(mb_substr($post['username'], 0, 1)), ENT_QUOTES, 'UTF-8');
    $avatar = postAvatarPath($post['avatar_path']);
    $background = preg_match('/^#[0-9a-f]{6}$/i', $post['profile_background_color'] ?? '') ? $post['profile_background_color'] : '#ffffff';
    $color = preg_match('/^#[0-9a-f]{6}$/i', $post['profile_text_color'] ?? '') ? $post['profile_text_color'] : '#163b5c';
    ?>
    <span class="post-author-preview" data-profile-preview>
        <a class="post-author" href="pages/profile.php?id=<?= (int) $post['user_id'] ?>">
            <span class="post-avatar" aria-hidden="true"><span><?= $initial ?></span><?php if ($avatar): ?><img src="<?= $avatar ?>" alt="" loading="lazy"><?php endif; ?></span>
            <span><?= $name ?></span>
        </a>
        <aside class="profile-preview" data-profile-preview-panel hidden aria-label="<?= $name ?> profile preview" style="--preview-background: <?= $background ?>; --preview-color: <?= $color ?>">
            <div class="profile-preview-banner"></div>
            <div class="profile-preview-body">
                <span class="post-avatar profile-preview-avatar" aria-hidden="true"><span><?= $initial ?></span><?php if ($avatar): ?><img src="<?= $avatar ?>" alt="" loading="lazy"><?php endif; ?></span>
                <a class="profile-preview-name" href="pages/profile.php?id=<?= (int) $post['user_id'] ?>"><?= $name ?></a>
                <?php if (trim($post['bio'] ?? '') !== ''): ?><p><?= htmlspecialchars(mb_substr($post['bio'], 0, 300), ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                <small>Member since <?= htmlspecialchars(date('F Y', strtotime($post['registration_date'])), ENT_QUOTES, 'UTF-8') ?></small>
            </div>
        </aside>
    </span>
    <?php
}

$basicStatus = '';
$statusError = '';
$postError = '';
$postContent = '';
$postVisibility = 'friends';
$posts = [];
$_SESSION['posts_csrf'] ??= bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['create_post', 'delete_post'], true)) {
    if (!isset($_SESSION['user_id'])) {
        header('Location: pages/login.php');
        exit;
    }
    $postContent = is_string($_POST['content'] ?? null) ? trim($_POST['content']) : '';
    $postVisibility = is_string($_POST['visibility'] ?? null) ? $_POST['visibility'] : 'friends';
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['posts_csrf'], $token)) {
        $postError = 'Your session changed. Please try again.';
    } elseif ($_POST['action'] === 'create_post') {
        if (!in_array($postVisibility, ['friends', 'public'], true)) {
            $postError = 'Choose Friends Only or Public.';
        } elseif ($postContent === '' || mb_strlen($postContent, 'UTF-8') > 2000) {
            $postError = 'Write a post using 1 to 2,000 characters.';
        } else {
            $authorId = (int) $_SESSION['user_id'];
            $statement = mysqli_prepare($conn, 'INSERT INTO posts (user_id, content, visibility) VALUES (?, ?, ?)');
            mysqli_stmt_bind_param($statement, 'iss', $authorId, $postContent, $postVisibility);
            mysqli_stmt_execute($statement);
            mysqli_stmt_close($statement);
            header('Location: index.php');
            exit;
        }
    } else {
        $postId = filter_var($_POST['post_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$postId) {
            $postError = 'Choose a valid post.';
        } else {
            $authorId = (int) $_SESSION['user_id'];
            $statement = mysqli_prepare($conn, 'DELETE FROM posts WHERE id = ? AND user_id = ?');
            mysqli_stmt_bind_param($statement, 'ii', $postId, $authorId);
            mysqli_stmt_execute($statement);
            mysqli_stmt_close($statement);
            header('Location: index.php');
            exit;
        }
    }
}

if (isset($_SESSION['user_id'])) {
    $userId = (int) $_SESSION['user_id'];
    $feedStatement = mysqli_prepare($conn, "SELECT p.id, p.user_id, p.content, p.visibility, p.created_at, u.username, u.avatar_path, u.bio, u.profile_background_color, u.profile_text_color, u.registration_date FROM posts p JOIN users u ON u.id = p.user_id WHERE p.visibility = 'public' OR p.user_id = ? OR EXISTS (SELECT 1 FROM friends f WHERE (f.user_id = ? AND f.friend_id = p.user_id) OR (f.friend_id = ? AND f.user_id = p.user_id)) ORDER BY p.created_at DESC, p.id DESC LIMIT 50");
    mysqli_stmt_bind_param($feedStatement, 'iii', $userId, $userId, $userId);
    mysqli_stmt_execute($feedStatement);
    $posts = mysqli_fetch_all(mysqli_stmt_get_result($feedStatement), MYSQLI_ASSOC);
    mysqli_stmt_close($feedStatement);
    $topStatement = mysqli_prepare($conn, 'SELECT u.id, u.username FROM friends f JOIN users u ON u.id = f.friend_id WHERE f.user_id = ? AND f.top_eight_position BETWEEN 1 AND 8 ORDER BY f.top_eight_position, u.id LIMIT 8');
    mysqli_stmt_bind_param($topStatement, 'i', $userId);
    mysqli_stmt_execute($topStatement);
    $topFriends = mysqli_fetch_all(mysqli_stmt_get_result($topStatement), MYSQLI_ASSOC);
    mysqli_stmt_close($topStatement);

    $activityStatement = mysqli_prepare($conn, "UPDATE users SET activity_state = 'online', last_active_at = NOW() WHERE id = ?");
    mysqli_stmt_bind_param($activityStatement, 'i', $userId);
    mysqli_stmt_execute($activityStatement);
    mysqli_stmt_close($activityStatement);

    $statement = mysqli_prepare($conn, 'SELECT status_text FROM users WHERE id = ? LIMIT 1');
    mysqli_stmt_bind_param($statement, 'i', $userId);
    mysqli_stmt_execute($statement);
    $result = mysqli_stmt_get_result($statement);
    $user = mysqli_fetch_assoc($result);
    mysqli_stmt_close($statement);

    if ($user) {
        $basicStatus = (string) ($user['status_text'] ?? '');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
        $basicStatus = trim($_POST['status'] ?? '');

        if (strlen($basicStatus) > 160) {
            $statusError = 'Use 160 characters or fewer.';
        } else {
            $updateStatement = mysqli_prepare($conn, 'UPDATE users SET status_text = ? WHERE id = ?');
            mysqli_stmt_bind_param($updateStatement, 'si', $basicStatus, $userId);
            mysqli_stmt_execute($updateStatement);
            mysqli_stmt_close($updateStatement);

            header('Location: index.php');
            exit;
        }
    }
} else {
    $publicResult = mysqli_query($conn, "SELECT p.user_id, p.content, p.created_at, u.username, u.avatar_path, u.bio, u.profile_background_color, u.profile_text_color, u.registration_date FROM posts p JOIN users u ON u.id = p.user_id WHERE p.visibility = 'public' ORDER BY p.created_at DESC, p.id DESC LIMIT 50");
    $posts = mysqli_fetch_all($publicResult, MYSQLI_ASSOC);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexusSpace</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <script src="assets/js/dashboard.js?v=<?= filemtime(__DIR__ . '/assets/js/dashboard.js') ?>" defer></script>
    <script src="assets/js/profile-preview.js?v=<?= filemtime(__DIR__ . '/assets/js/profile-preview.js') ?>" defer></script>
    <script src="assets/js/message-updates.js?v=<?= filemtime(__DIR__ . '/assets/js/message-updates.js') ?>" defer></script>
    <script src="assets/js/mini-chat.js?v=<?= filemtime(__DIR__ . '/assets/js/mini-chat.js') ?>" defer></script>
    <script src="assets/js/mini-chat-resize.js?v=<?= filemtime(__DIR__ . '/assets/js/mini-chat-resize.js') ?>" defer></script>
    <script src="assets/js/message-popup.js?v=<?= filemtime(__DIR__ . '/assets/js/message-popup.js') ?>" defer></script>
    <script src="assets/js/friend-badges.js?v=<?= filemtime(__DIR__ . '/assets/js/friend-badges.js') ?>" defer></script>
</head>
<body<?= isset($_SESSION['user_id']) ? ' class="dashboard-page"' : '' ?>>
    <?php if (isset($_SESSION['user_id'])): ?>
        <?php
        $username = htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8');
        $initial = htmlspecialchars(strtoupper(substr($_SESSION['username'], 0, 1)), ENT_QUOTES, 'UTF-8');
        ?>
        <main class="dashboard" aria-label="NexusSpace dashboard">
            <aside class="dashboard-sidebar">
                <a class="dashboard-user" href="pages/profile.php">
                    <span class="dashboard-avatar" aria-hidden="true">
                        <span class="mini-avatar"><?= $initial ?></span>
                        <span class="activity-diamond" data-activity-indicator data-state="online"></span>
                    </span>
                    <span><?= $username ?></span>
                </a>

                <form class="status-form" method="post">
                    <input type="hidden" name="action" value="update_status">
                    <section class="status-panel" aria-label="Status information">
                        <div class="status-item">
                            <label class="sr-only" for="basic-status">Basic status</label>
                            <input id="basic-status" name="status" type="text" value="<?= htmlspecialchars($basicStatus, ENT_QUOTES, 'UTF-8') ?>" maxlength="160" autocomplete="off" placeholder="your status???" data-status-input>
                        </div>
                        <div class="status-item">
                            <p>currently music</p>
                        </div>
                        <div class="status-item">
                            <p>current game</p>
                        </div>
                    </section>
                    <?php if ($statusError): ?>
                        <p class="status-editor-error" role="alert"><?= htmlspecialchars($statusError, ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                </form>

                <section class="friends-panel" aria-labelledby="friends-heading">
                    <div class="panel-heading">
                        <a class="friends-link" id="friends-heading" href="pages/messages.php">Friends <span class="friends-unread-total" data-unread-total hidden></span></a>
                        <a class="friends-reorder" href="pages/profile.php#profile-top-eight-heading" aria-label="Edit Top 8 friends">
                            <img src="assets/images/arrows.png" alt="">
                        </a>
                    </div>
                    <ul class="friends-list">
                        <?php foreach ($topFriends as $friend): ?>
                            <li>
                                <a class="top-friend-link" data-top-friend-chat="<?= (int) $friend['id'] ?>" href="index.php?chat=<?= (int) $friend['id'] ?>">
                                    <span class="mini-avatar" aria-hidden="true"><?= htmlspecialchars(strtoupper(substr($friend['username'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>
                                    <span><?= htmlspecialchars($friend['username'], ENT_QUOTES, 'UTF-8') ?></span>
                                </a>
                                <a class="friend-unread-diamond" data-friend-unread="<?= (int) $friend['id'] ?>" data-friend-name="<?= htmlspecialchars($friend['username'], ENT_QUOTES, 'UTF-8') ?>" href="index.php?chat=<?= (int) $friend['id'] ?>" hidden></a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if (!$topFriends): ?><p class="top-eight-empty"><a href="pages/profile.php#profile-top-eight-heading">Choose your Top 8</a></p><?php endif; ?>
                </section>

                <nav class="sidebar-actions" aria-label="Account actions">
                    <a class="settings-link" href="pages/coming-soon.php?feature=settings"><span aria-hidden="true">&#9881;</span> settings</a>
                    <a href="logout.php">log out</a>
                </nav>
                <small class="copyright">mini copyright</small>
            </aside>

            <section class="dashboard-feed" aria-label="Post feed">
                <form class="user-search" role="search" action="pages/search.php" method="get" data-live-user-search>
                    <label class="sr-only" for="user-search">Search for users</label>
                    <input id="user-search" name="q" type="search" placeholder="Find users" maxlength="50" autocomplete="off" aria-controls="live-user-results" required>
                    <button type="submit">Search</button>
                    <div class="live-user-results" id="live-user-results" hidden>
                        <p data-search-status role="status" aria-live="polite"></p>
                        <ul class="search-results" data-search-matches></ul>
                    </div>
                </form>

                <form class="post-composer" method="post">
                    <input type="hidden" name="action" value="create_post">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['posts_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                    <label class="sr-only" for="post-content">Write a post</label>
                    <textarea id="post-content" name="content" rows="4" maxlength="2000" placeholder="What's on your mind?" required><?= htmlspecialchars($postContent, ENT_QUOTES, 'UTF-8') ?></textarea>
                    <div class="post-composer-actions">
                        <label for="post-visibility">Audience</label>
                        <select id="post-visibility" name="visibility">
                            <option value="friends"<?= $postVisibility === 'friends' ? ' selected' : '' ?>>Friends Only</option>
                            <option value="public"<?= $postVisibility === 'public' ? ' selected' : '' ?>>Public</option>
                        </select>
                        <button type="submit">Post</button>
                    </div>
                    <?php if ($postError): ?><p class="post-error" role="alert"><?= htmlspecialchars($postError, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
                </form>
                <?php if (!$posts): ?><p class="post-empty">No posts yet.</p><?php endif; ?>
                <?php foreach ($posts as $post): ?>
                    <article class="post-card">
                        <header class="post-header">
                            <?php renderPostAuthor($post); ?>
                            <div class="post-meta"><time datetime="<?= htmlspecialchars(str_replace(' ', 'T', $post['created_at']), ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($post['created_at'], ENT_QUOTES, 'UTF-8') ?>"><?= postTimestamp($post['created_at']) ?></time><?php if ((int) $post['user_id'] === $userId): ?> &middot; <?= $post['visibility'] === 'public' ? 'Public' : 'Friends Only' ?><?php endif; ?></div>
                        </header>
                        <p class="post-content"><?= htmlspecialchars($post['content'], ENT_QUOTES, 'UTF-8') ?></p>
                        <?php if ((int) $post['user_id'] === $userId): ?>
                            <form method="post" class="post-delete-form">
                                <input type="hidden" name="action" value="delete_post">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['posts_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="post_id" value="<?= (int) $post['id'] ?>">
                                <button type="submit">Delete</button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </section>

            <aside class="dashboard-right-rail">
                <section class="notifications-panel is-collapsed" data-notifications>
                    <div class="notifications-heading">
                        <span class="notification-badge" data-notification-badge hidden aria-label="Unread notifications"></span>
                        <button class="notifications-toggle" type="button" aria-expanded="false" aria-controls="notifications-list">
                            <span>notifications</span>
                            <span aria-hidden="true" data-notification-chevron>v</span>
                        </button>
                    </div>
                    <div class="notifications-list" id="notifications-list" data-notifications-list tabindex="0" role="region" aria-label="Notifications">
                        <div data-friend-notifications></div>
                    </div>
                    <button class="notifications-read-all" type="button" data-read-all-notifications>Read all</button>
                    <div class="message-popup" data-message-popup hidden>
                        <button type="button" data-message-popup-open aria-label="Open message"></button>
                        <button type="button" data-message-popup-dismiss aria-label="Dismiss message popup">×</button>
                        <span class="sr-only" data-message-popup-announcement role="status" aria-live="polite"></span>
                    </div>
                </section>

                <section class="chat-preview mini-chat" aria-labelledby="chat-heading" data-mini-chat hidden>
                    <div class="chat-heading">
                        <button type="button" class="mini-chat-resize" data-mini-resize aria-label="Resize chat" title="Drag to resize. Arrow keys resize; double-click resets.">⤢</button>
                        <span class="mini-avatar" data-mini-avatar aria-hidden="true"></span>
                        <h2 id="chat-heading">Chat</h2>
                        <button type="button" data-mini-close aria-label="Close chat">×</button>
                    </div>
                    <div class="chat-messages" data-mini-messages role="log" aria-label="Conversation" tabindex="0"></div>
                    <p class="mini-chat-status" data-mini-status role="status"></p>
                    <form class="chat-input-placeholder" data-chat-form>
                        <label class="sr-only" for="chat-message">Type a chat message</label>
                        <input id="chat-message" name="message" type="text" placeholder="Type a message" autocomplete="off" maxlength="2000" required>
                        <button type="submit" aria-label="Send message">&gt;</button>
                    </form>
                </section>
            </aside>
        </main>
        <aside class="zoom-layout-warning" role="status" data-zoom-warning>
            <span>This layout works best at 200% zoom or lower. Please zoom out for the full experience.</span>
            <button type="button" data-dismiss-zoom-warning>Okay</button>
        </aside>
    <?php else: ?>
        <main>
            <h1>NexusSpace</h1>
            <p>A place to make your space your own.</p>
            <p>
                <a class="button" href="pages/register.php">Create an account</a>
                <a class="button button-secondary" href="pages/login.php">Log in</a>
            </p>
            <h2>Public Posts</h2>
            <?php if (!$posts): ?><p>No public posts yet.</p><?php endif; ?>
            <?php foreach ($posts as $post): ?>
                <article class="post-card">
                    <header class="post-header">
                        <?php renderPostAuthor($post); ?>
                        <div class="post-meta"><time datetime="<?= htmlspecialchars(str_replace(' ', 'T', $post['created_at']), ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars($post['created_at'], ENT_QUOTES, 'UTF-8') ?>"><?= postTimestamp($post['created_at']) ?></time></div>
                    </header>
                    <p class="post-content"><?= htmlspecialchars($post['content'], ENT_QUOTES, 'UTF-8') ?></p>
                </article>
            <?php endforeach; ?>
        </main>
    <?php endif; ?>
</body>
</html>
