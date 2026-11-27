<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/admin.php';

$viewerId = (int) $_SESSION['user_id'];
$isAdmin = isNexusSpaceAdmin($viewerId);
$_SESSION['post_moderation_csrf'] ??= bin2hex(random_bytes(32));
$csrfToken = $_SESSION['post_moderation_csrf'];
$error = '';
$notice = '';
$escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($csrfToken, $token)) {
        http_response_code(403);
        $error = 'Your session changed. Refresh the page and try again.';
    } else {
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        $postId = filter_var($_POST['post_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $reason = is_string($_POST['reason'] ?? null) ? trim($_POST['reason']) : '';
        $adminResponse = is_string($_POST['admin_response'] ?? null) ? trim($_POST['admin_response']) : '';

        try {
            if (!$postId) {
                http_response_code(422);
                $error = 'Choose a valid post.';
            } elseif ($action === 'remove_post' && $isAdmin) {
                if ($reason === '' || mb_strlen($reason, 'UTF-8') > 500) {
                    http_response_code(422);
                    $error = 'Enter a removal reason of no more than 500 characters.';
                } else {
                    $statement = mysqli_prepare($conn, 'UPDATE posts SET admin_removed_at = UTC_TIMESTAMP(), admin_removed_by = ?, admin_removal_reason = ? WHERE id = ? AND admin_removed_at IS NULL');
                    mysqli_stmt_bind_param($statement, 'isi', $viewerId, $reason, $postId);
                    mysqli_stmt_execute($statement);
                    $changed = mysqli_stmt_affected_rows($statement) === 1;
                    mysqli_stmt_close($statement);
                    if (!$changed) {
                        http_response_code(409);
                        $error = 'That post is already removed or no longer exists.';
                    } else {
                        header('Location: post-moderation.php?removed=1');
                        exit;
                    }
                }
            } elseif ($action === 'appeal' && !$isAdmin) {
                if (mb_strlen($reason, 'UTF-8') < 10 || mb_strlen($reason, 'UTF-8') > 2000) {
                    http_response_code(422);
                    $error = 'Explain your appeal in 10 to 2,000 characters.';
                } else {
                    $statement = mysqli_prepare($conn, 'SELECT id FROM posts WHERE id = ? AND user_id = ? AND admin_removed_at IS NOT NULL AND admin_removed_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY LIMIT 1');
                    mysqli_stmt_bind_param($statement, 'ii', $postId, $viewerId);
                    mysqli_stmt_execute($statement);
                    $eligible = mysqli_fetch_assoc(mysqli_stmt_get_result($statement)) !== null;
                    mysqli_stmt_close($statement);
                    if (!$eligible) {
                        http_response_code(404);
                        $error = 'That removed post is not available to appeal.';
                    } else {
                        $statement = mysqli_prepare($conn, 'INSERT INTO post_removal_appeals (post_id, user_id, reason) VALUES (?, ?, ?)');
                        mysqli_stmt_bind_param($statement, 'iis', $postId, $viewerId, $reason);
                        mysqli_stmt_execute($statement);
                        mysqli_stmt_close($statement);
                        header('Location: post-moderation.php?appealed=1');
                        exit;
                    }
                }
            } elseif ($action === 'restore_post' && $isAdmin) {
                if (mb_strlen($adminResponse, 'UTF-8') > 1000) {
                    http_response_code(422);
                    $error = 'Keep the response under 1,000 characters.';
                } else {
                    mysqli_begin_transaction($conn);
                    $statement = mysqli_prepare($conn, 'UPDATE posts SET admin_removed_at = NULL WHERE id = ? AND admin_removed_at IS NOT NULL');
                    mysqli_stmt_bind_param($statement, 'i', $postId);
                    mysqli_stmt_execute($statement);
                    $restored = mysqli_stmt_affected_rows($statement) === 1;
                    mysqli_stmt_close($statement);
                    if ($restored) {
                        $statement = mysqli_prepare($conn, "UPDATE post_removal_appeals SET status = 'restored', admin_response = ?, reviewed_at = UTC_TIMESTAMP(), reviewed_by = ? WHERE post_id = ?");
                        $resolution = $adminResponse !== '' ? $adminResponse : 'The post has been restored.';
                        mysqli_stmt_bind_param($statement, 'sii', $resolution, $viewerId, $postId);
                        mysqli_stmt_execute($statement);
                        mysqli_stmt_close($statement);
                    }
                    mysqli_commit($conn);
                    header('Location: post-moderation.php?restored=' . ($restored ? '1' : '0'));
                    exit;
                }
            } elseif ($action === 'uphold_appeal' && $isAdmin) {
                $response = $adminResponse !== '' ? $adminResponse : 'The removal decision is upheld.';
                if (mb_strlen($response, 'UTF-8') > 1000) {
                    http_response_code(422);
                    $error = 'Keep the response under 1,000 characters.';
                } else {
                    $statement = mysqli_prepare($conn, "UPDATE post_removal_appeals a JOIN posts p ON p.id = a.post_id SET a.status = 'upheld', a.admin_response = ?, a.reviewed_at = UTC_TIMESTAMP(), a.reviewed_by = ? WHERE a.post_id = ? AND a.status = 'pending' AND p.admin_removed_at IS NOT NULL");
                    mysqli_stmt_bind_param($statement, 'sii', $response, $viewerId, $postId);
                    mysqli_stmt_execute($statement);
                    $reviewed = mysqli_stmt_affected_rows($statement) === 1;
                    mysqli_stmt_close($statement);
                    if (!$reviewed) {
                        http_response_code(409);
                        $error = 'That appeal is no longer pending.';
                    } else {
                        header('Location: post-moderation.php?reviewed=1');
                        exit;
                    }
                }
            } else {
                http_response_code(403);
                $error = 'You cannot perform that moderation action.';
            }
        } catch (mysqli_sql_exception $exception) {
            error_log('NexusSpace post moderation failed with database error code ' . (int) $exception->getCode() . '.');
            $error = $action === 'appeal' ? 'Your appeal could not be saved. It may already be under review.' : 'The moderation action could not be completed.';
        }
    }
}

if (isset($_GET['removed'])) $notice = 'Post removed. It is hidden from users and will be permanently deleted after 30 days unless restored.';
elseif (isset($_GET['appealed'])) $notice = 'Your appeal was sent for review.';
elseif (isset($_GET['restored'])) $notice = $_GET['restored'] === '1' ? 'Post restored.' : 'That post was already restored or purged.';
elseif (isset($_GET['reviewed'])) $notice = 'Appeal decision saved.';

$activePosts = [];
$removedPosts = [];
$userRemovedPosts = [];
$pendingAppeals = [];
if ($isAdmin) {
    $result = mysqli_query($conn, "SELECT p.id, p.user_id, p.title, p.content, p.created_at, p.visibility, u.username FROM posts p JOIN users u ON u.id = p.user_id WHERE p.admin_removed_at IS NULL ORDER BY p.created_at DESC, p.id DESC LIMIT 100");
    $activePosts = mysqli_fetch_all($result, MYSQLI_ASSOC);
    $result = mysqli_query($conn, "SELECT p.id, p.user_id, p.title, p.content, p.created_at, p.admin_removed_at, p.admin_removal_reason, u.username, a.status AS appeal_status FROM posts p JOIN users u ON u.id = p.user_id LEFT JOIN post_removal_appeals a ON a.post_id = p.id WHERE p.admin_removed_at IS NOT NULL ORDER BY a.status = 'pending' DESC, p.admin_removed_at DESC LIMIT 200");
    $removedPosts = mysqli_fetch_all($result, MYSQLI_ASSOC);
    $result = mysqli_query($conn, "SELECT a.post_id, a.reason, a.created_at, p.title, p.content, p.admin_removal_reason, owner.username FROM post_removal_appeals a JOIN posts p ON p.id = a.post_id JOIN users owner ON owner.id = a.user_id WHERE a.status = 'pending' AND p.admin_removed_at IS NOT NULL ORDER BY a.created_at ASC LIMIT 200");
    $pendingAppeals = mysqli_fetch_all($result, MYSQLI_ASSOC);
} else {
    $statement = mysqli_prepare($conn, 'SELECT p.id, p.title, p.content, p.created_at, p.admin_removed_at, p.admin_removal_reason, a.reason AS appeal_reason, a.status AS appeal_status, a.admin_response AS appeal_response, a.created_at AS appeal_created_at FROM posts p LEFT JOIN post_removal_appeals a ON a.post_id = p.id WHERE p.user_id = ? AND p.admin_removed_at IS NOT NULL AND (p.admin_removed_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY OR a.status = \'pending\') ORDER BY p.admin_removed_at DESC LIMIT 100');
    mysqli_stmt_bind_param($statement, 'i', $viewerId);
    mysqli_stmt_execute($statement);
    $userRemovedPosts = mysqli_fetch_all(mysqli_stmt_get_result($statement), MYSQLI_ASSOC);
    mysqli_stmt_close($statement);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $isAdmin ? 'Post moderation' : 'Removed posts' ?> | NexusSpace</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= filemtime(__DIR__ . '/../assets/css/style.css') ?>">
</head>
<body class="post-moderation-page">
    <main class="post-moderation-main">
        <header><a href="../index.php">NexusSpace</a><h1><?= $isAdmin ? 'Post moderation' : 'Removed posts' ?></h1></header>
        <?php if ($notice !== ''): ?><p class="settings-notice" role="status"><?= $escape($notice) ?></p><?php endif; ?>
        <?php if ($error !== ''): ?><p class="error-box" role="alert"><?= $escape($error) ?></p><?php endif; ?>

        <?php if ($isAdmin): ?>
            <p>Removed posts stay hidden for 30 days. Owners can appeal once during that period. Review appeals within 7 days. A pending appeal pauses permanent deletion; after 30 days, posts without a pending appeal are purged by the scheduled cleanup. Restore a post to return it to feeds and profiles.</p>
            <section aria-labelledby="appeals-heading">
                <h2 id="appeals-heading">Appeals awaiting review</h2>
                <?php if (!$pendingAppeals): ?><p>No pending appeals.</p><?php endif; ?>
                <?php foreach ($pendingAppeals as $appeal): ?>
                    <article class="moderation-card">
                        <h3><?= $escape($appeal['title'] !== '' ? $appeal['title'] : 'Untitled post') ?> by <?= $escape($appeal['username']) ?></h3>
                        <p><?= nl2br($escape($appeal['content'])) ?></p>
                        <p><strong>Removal reason:</strong> <?= $escape($appeal['admin_removal_reason']) ?></p>
                        <p><strong>Appeal:</strong> <?= nl2br($escape($appeal['reason'])) ?></p>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="post_id" value="<?= (int) $appeal['post_id'] ?>">
                            <label>Decision note <textarea name="admin_response" maxlength="1000"></textarea></label>
                            <button name="action" value="restore_post">Restore post</button><button name="action" value="uphold_appeal">Uphold removal</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </section>
            <section aria-labelledby="active-posts-heading">
                <h2 id="active-posts-heading">Recent posts</h2>
                <?php foreach ($activePosts as $post): ?>
                    <article class="moderation-card">
                        <h3><?= $escape($post['title'] !== '' ? $post['title'] : 'Untitled post') ?> by <?= $escape($post['username']) ?> · <?= $escape($post['visibility']) ?></h3>
                        <p><?= nl2br($escape($post['content'])) ?></p>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="post_id" value="<?= (int) $post['id'] ?>">
                            <label>Removal reason <textarea name="reason" maxlength="500" required></textarea></label>
                            <button name="action" value="remove_post">Remove post</button>
                        </form>
                    </article>
                <?php endforeach; ?>
                <?php if (!$activePosts): ?><p>No active posts.</p><?php endif; ?>
            </section>
            <section aria-labelledby="removed-posts-heading">
                <h2 id="removed-posts-heading">Removed posts</h2>
                <?php foreach ($removedPosts as $post): ?>
                    <article class="moderation-card">
                        <h3><?= $escape($post['title'] !== '' ? $post['title'] : 'Untitled post') ?> by <?= $escape($post['username']) ?></h3>
                        <p><?= nl2br($escape($post['content'])) ?></p>
                        <p>Removed <?= $escape($post['admin_removed_at']) ?> · appeal: <?= $escape($post['appeal_status'] ?? 'not submitted') ?></p>
                        <p><?= $escape($post['admin_removal_reason']) ?></p>
                        <form method="post"><input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="post_id" value="<?= (int) $post['id'] ?>"><button name="action" value="restore_post">Restore post</button></form>
                    </article>
                <?php endforeach; ?>
                <?php if (!$removedPosts): ?><p>No removed posts.</p><?php endif; ?>
            </section>
        <?php else: ?>
            <p>Posts removed by moderation are hidden from everyone else. You can appeal once within 30 days. Posts not restored are permanently deleted after 30 days; a pending appeal keeps the post while it is reviewed.</p>
            <?php foreach ($userRemovedPosts as $post): ?>
                <article class="moderation-card">
                    <h2><?= $escape($post['title'] !== '' ? $post['title'] : 'Untitled post') ?></h2>
                    <p><?= nl2br($escape($post['content'])) ?></p>
                    <p>Removed <?= $escape($post['admin_removed_at']) ?> · Reason: <?= $escape($post['admin_removal_reason']) ?></p>
                    <?php if (!$post['appeal_status']): ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="post_id" value="<?= (int) $post['id'] ?>"><input type="hidden" name="action" value="appeal">
                            <label>Appeal this decision (10–2,000 characters)<textarea name="reason" minlength="10" maxlength="2000" required></textarea></label>
                            <button type="submit">Submit appeal</button>
                        </form>
                    <?php else: ?>
                        <p>Appeal status: <?= $escape($post['appeal_status']) ?><?= $post['appeal_response'] ? ' · ' . $escape($post['appeal_response']) : '' ?></p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
            <?php if (!$userRemovedPosts): ?><p>No posts have been removed from your account.</p><?php endif; ?>
        <?php endif; ?>
    </main>
</body>
</html>
