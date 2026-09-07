<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/db_connect.php';

session_start();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>NexusSpace</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <script src="assets/js/dashboard.js" defer></script>
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
                    <span class="mini-avatar" aria-hidden="true"><?= $initial ?></span>
                    <span><?= $username ?></span>
                </a>

                <section class="status-panel" aria-labelledby="status-heading">
                    <div class="status-item">
                        <h2 id="status-heading">your status???</h2>
                    </div>
                    <div class="status-item">
                        <p>currently music</p>
                    </div>
                    <div class="status-item">
                        <p>current game</p>
                    </div>
                </section>
                <button class="status-edit" type="button" disabled>Update/edit status</button>

                <section class="friends-panel" aria-labelledby="friends-heading">
                    <div class="panel-heading">
                        <h2 id="friends-heading">Friends</h2>
                        <button class="friends-edit" type="button" disabled aria-label="Rearrange Top 8 friends">&#9998;</button>
                    </div>
                    <ul class="friends-list">
                        <?php for ($friendNumber = 1; $friendNumber <= 8; $friendNumber++): ?>
                            <li>
                                <span class="mini-avatar" aria-hidden="true">P</span>
                                <span>username</span>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </section>

                <nav class="sidebar-actions" aria-label="Account actions">
                    <button type="button" disabled><span aria-hidden="true">&#9881;</span> settings</button>
                    <a href="logout.php">log out</a>
                </nav>
                <small class="copyright">mini copyright</small>
            </aside>

            <section class="dashboard-feed" aria-label="Post feed">
                <form class="user-search" role="search">
                    <label class="sr-only" for="user-search">Search for users</label>
                    <input id="user-search" type="search" placeholder="Search to find users" disabled>
                    <button type="submit" disabled>SEARCH BAR THINGY</button>
                </form>

                <?php for ($postNumber = 1; $postNumber <= 2; $postNumber++): ?>
                    <article class="post-card">
                        <header>profile pic + username</header>
                        <div class="post-image-placeholder" aria-label="Post image placeholder"></div>
                        <div class="post-copy">
                            <p>Caption</p>
                            <p>top comment</p>
                        </div>
                    </article>
                <?php endfor; ?>
            </section>

            <aside class="dashboard-right-rail">
                <section class="notifications-panel" aria-labelledby="notifications-heading">
                    <div class="notifications-heading">
                        <span class="notification-badge">9+</span>
                        <h2 id="notifications-heading">notifs</h2>
                        <button type="button" disabled aria-label="Open notifications">v</button>
                    </div>
                    <p>user [liked ur .../commented]</p>
                </section>

                <section class="chat-preview" aria-labelledby="chat-heading">
                    <div class="chat-heading">
                        <h2 id="chat-heading">pic + username of friend ur texting</h2>
                        <button type="button" disabled aria-label="Close chat preview">x</button>
                    </div>
                    <div class="chat-messages" aria-label="Placeholder chat messages">
                        <p class="message received"><span class="chat-avatar"></span><span></span></p>
                        <p class="message sent"><span></span><span class="chat-avatar"></span></p>
                        <p class="message received"><span class="chat-avatar"></span><span></span></p>
                    </div>
                    <p class="typing-placeholder">mini animation of ... when user is typing here in corner</p>
                    <form class="chat-input-placeholder" data-chat-form>
                        <label class="sr-only" for="chat-message">Type a chat message</label>
                        <input id="chat-message" name="message" type="text" placeholder="See what ur typing here" autocomplete="off">
                        <button type="submit" aria-label="Send message">&gt;</button>
                    </form>
                </section>
            </aside>
        </main>
    <?php else: ?>
        <main>
            <h1>NexusSpace</h1>
            <p>A place to make your space your own.</p>
            <p>
                <a class="button" href="pages/register.php">Create an account</a>
                <a class="button button-secondary" href="pages/login.php">Log in</a>
            </p>
        </main>
    <?php endif; ?>
</body>
</html>
