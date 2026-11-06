<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/session.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Accept-Ranges: bytes');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

$filename = is_string($_GET['file'] ?? null) ? basename($_GET['file']) : '';
if (!preg_match('/^post-[0-9]+-[a-f0-9]{24}\.(jpg|png|webp|gif|mp4|webm)$/i', $filename, $matches)) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/includes/db_connect.php';

$viewerId = (int) ($_SESSION['user_id'] ?? 0);
$mediaPath = 'uploads/posts/' . $filename;
$statement = mysqli_prepare(
    $conn,
    "SELECT p.id, p.user_id, p.visibility FROM posts p LEFT JOIN post_media pm ON pm.post_id = p.id AND pm.media_path = ? WHERE (p.media_path = ? OR pm.id IS NOT NULL) AND (p.visibility = 'public' OR p.user_id = ? OR EXISTS (SELECT 1 FROM friends f WHERE (f.user_id = ? AND f.friend_id = p.user_id) OR (f.friend_id = ? AND f.user_id = p.user_id))) AND NOT EXISTS (SELECT 1 FROM user_blocks b WHERE (b.blocker_id = ? AND b.blocked_id = p.user_id) OR (b.blocker_id = p.user_id AND b.blocked_id = ?)) LIMIT 1"
);
mysqli_stmt_bind_param($statement, 'ssiiiii', $mediaPath, $mediaPath, $viewerId, $viewerId, $viewerId, $viewerId, $viewerId);
mysqli_stmt_execute($statement);
$post = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
mysqli_stmt_close($statement);

if (!$post) {
    http_response_code(404);
    exit;
}

$path = __DIR__ . '/uploads/posts/' . $filename;
$size = is_file($path) ? filesize($path) : false;
$handle = $size === false ? false : fopen($path, 'rb');
if ($size === false || $size < 1 || $handle === false) {
    http_response_code(404);
    exit;
}

$mimeTypes = [
    'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif',
    'mp4' => 'video/mp4', 'webm' => 'video/webm',
];
$extension = strtolower($matches[1]);
$start = 0;
$end = $size - 1;
$status = 200;
$range = trim((string) ($_SERVER['HTTP_RANGE'] ?? ''));

if ($range !== '') {
    if (!preg_match('/^bytes=(\d*)-(\d*)$/', $range, $rangeMatch)
        || ($rangeMatch[1] === '' && $rangeMatch[2] === '')) {
        fclose($handle);
        header('Content-Range: bytes */' . $size);
        http_response_code(416);
        exit;
    }

    if ($rangeMatch[1] === '') {
        $suffixLength = (int) $rangeMatch[2];
        if ($suffixLength < 1) {
            fclose($handle);
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            exit;
        }
        $start = max(0, $size - $suffixLength);
    } else {
        $start = (int) $rangeMatch[1];
        $end = $rangeMatch[2] === '' ? $size - 1 : (int) $rangeMatch[2];
    }

    if ($start >= $size || $end < $start) {
        fclose($handle);
        header('Content-Range: bytes */' . $size);
        http_response_code(416);
        exit;
    }

    $end = min($end, $size - 1);
    $status = 206;
}

if (fseek($handle, $start) !== 0) {
    fclose($handle);
    http_response_code(500);
    exit;
}

http_response_code($status);
header('Content-Type: ' . $mimeTypes[$extension]);
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . ($end - $start + 1));
if ($status === 206) {
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
}

if ($method === 'GET') {
    $remaining = $end - $start + 1;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = fread($handle, min(1024 * 1024, $remaining));
        if ($chunk === false || $chunk === '') break;
        echo $chunk;
        $remaining -= strlen($chunk);
    }
}

fclose($handle);
