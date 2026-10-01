<?php

declare(strict_types=1);

const POST_IMAGE_MAX_BYTES = 10 * 1024 * 1024;
const POST_VIDEO_MAX_BYTES = 50 * 1024 * 1024;
const POST_MEDIA_MAX_FILES = 5;

function normalizePostMediaUploads(array $upload): array
{
    if (!is_array($upload['name'] ?? null)) return [
        'name' => $upload['name'] ?? '', 'type' => $upload['type'] ?? '',
        'tmp_name' => $upload['tmp_name'] ?? '', 'error' => $upload['error'] ?? UPLOAD_ERR_NO_FILE,
        'size' => $upload['size'] ?? 0,
    ];
    $uploads = [];
    foreach ($upload['name'] as $index => $name) {
        $uploads[] = [
            'name' => $name, 'type' => $upload['type'][$index] ?? '',
            'tmp_name' => $upload['tmp_name'][$index] ?? '', 'error' => $upload['error'][$index] ?? UPLOAD_ERR_NO_FILE,
            'size' => $upload['size'][$index] ?? 0,
        ];
    }
    return $uploads;
}

function postMediaUploadCount(array $upload): int
{
    return count(array_filter(normalizePostMediaUploads($upload), static fn(array $file): bool => ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
}

function storePostMedia(array $upload, int $userId): ?array
{
    $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) return null;
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException(match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'That media file is larger than the server allows.',
            default => 'The media upload failed. Please try again.',
        });
    }
    $temporaryPath = is_string($upload['tmp_name'] ?? null) ? $upload['tmp_name'] : '';
    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) throw new InvalidArgumentException('The uploaded media could not be verified.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
    $formats = [
        'image/jpeg' => ['jpg', 'image'], 'image/png' => ['png', 'image'],
        'image/webp' => ['webp', 'image'], 'image/gif' => ['gif', 'image'],
        'video/mp4' => ['mp4', 'video'], 'video/webm' => ['webm', 'video'],
    ];
    if (!isset($formats[$mime])) throw new InvalidArgumentException('Upload a JPG, PNG, WebP, GIF, MP4, or WebM file.');
    [$extension, $type] = $formats[$mime];
    $size = (int) ($upload['size'] ?? 0);
    $limit = $type === 'image' ? POST_IMAGE_MAX_BYTES : POST_VIDEO_MAX_BYTES;
    if ($size < 1 || $size > $limit) throw new InvalidArgumentException($type === 'image' ? 'Use an image smaller than 10 MB.' : 'Use a video smaller than 50 MB.');
    if ($type === 'image') {
        $dimensions = @getimagesize($temporaryPath);
        if ($dimensions === false || $dimensions[0] > 10000 || $dimensions[1] > 10000 || $dimensions[0] * $dimensions[1] > 60000000) {
            throw new InvalidArgumentException('Use an image under 60 megapixels and 10000 pixels per side.');
        }
    }
    $directory = __DIR__ . '/../uploads/posts';
    if ((!is_dir($directory) && !mkdir($directory, 0755, true)) || !is_writable($directory)) throw new RuntimeException('The post media could not be saved.');
    $filename = 'post-' . $userId . '-' . bin2hex(random_bytes(12)) . '.' . $extension;
    if (!move_uploaded_file($temporaryPath, $directory . '/' . $filename)) throw new RuntimeException('The post media could not be saved.');
    return ['path' => 'uploads/posts/' . $filename, 'type' => $type];
}

function storePostMediaBatch(array $upload, int $userId): array
{
    $uploads = array_values(array_filter(normalizePostMediaUploads($upload), static fn(array $file): bool => ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE));
    if (count($uploads) > POST_MEDIA_MAX_FILES) throw new InvalidArgumentException('Attach up to five media files per post.');
    $stored = [];
    try {
        foreach ($uploads as $file) {
            $media = storePostMedia($file, $userId);
            if ($media) $stored[] = $media;
        }
    } catch (Throwable $exception) {
        foreach ($stored as $media) deletePostMedia($media['path'], $userId);
        throw $exception;
    }
    return $stored;
}

function loadPostMedia(mysqli $conn, array $postIds): array
{
    $postIds = array_values(array_unique(array_filter(array_map('intval', $postIds), static fn(int $id): bool => $id > 0)));
    if (!$postIds) return [];
    $result = mysqli_query($conn, 'SELECT id, post_id, media_path, media_type, sort_order FROM post_media WHERE post_id IN (' . implode(',', $postIds) . ') ORDER BY post_id, sort_order, id');
    $media = [];
    while ($item = mysqli_fetch_assoc($result)) $media[(int) $item['post_id']][] = $item;
    return $media;
}

function attachPostMedia(array &$posts, array $mediaByPost): void
{
    foreach ($posts as &$post) $post['media'] = $mediaByPost[(int) $post['id']] ?? [];
    unset($post);
}

function deletePostMedia(?string $path, int $userId): void
{
    $filename = basename($path ?? '');
    if (!preg_match('/^post-' . preg_quote((string) $userId, '/') . '-[a-f0-9]{24}\.(?:jpg|png|webp|gif|mp4|webm)$/', $filename)) return;
    $absolutePath = __DIR__ . '/../uploads/posts/' . $filename;
    if (is_file($absolutePath)) @unlink($absolutePath);
}

function renderPostMedia(array $post, string $assetPrefix = '', bool $owned = false): void
{
    $items = array_key_exists('media', $post) ? $post['media'] : [];
    if (!array_key_exists('media', $post) && !empty($post['media_path'])) $items[] = ['id' => 0, 'media_path' => $post['media_path'], 'media_type' => $post['media_type'] ?? ''];
    $items = array_values(array_filter($items, static function (array $item): bool {
        return is_string($item['media_path'] ?? null)
            && preg_match('~^uploads/posts/post-[0-9]+-[a-f0-9]{24}\.(?:jpg|png|webp|gif|mp4|webm)$~', $item['media_path'])
            && in_array($item['media_type'] ?? null, ['image', 'video'], true);
    }));
    if (!$items) return;
    $mediaKey = implode('|', array_map(static fn(array $item): string => (string) ($item['id'] ?? 0) . ':' . $item['media_path'], $items));
    ?>
    <div class="post-media post-media-count-<?= count($items) ?>" data-post-media data-media-key="<?= htmlspecialchars($mediaKey, ENT_QUOTES, 'UTF-8') ?>">
        <?php foreach ($items as $index => $item): $url = $assetPrefix . $item['media_path']; ?>
            <figure class="post-media-item">
                <?php if ($item['media_type'] === 'image'): ?><img src="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" alt="Post attachment <?= $index + 1 ?>" loading="lazy"><?php else: ?><video src="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" controls preload="metadata"></video><?php endif; ?>
                <?php if ($owned): ?><button type="button" data-post-media-remove data-media-id="<?= (int) ($item['id'] ?? 0) ?>" aria-label="Remove media <?= $index + 1 ?>">&times;</button><?php endif; ?>
            </figure>
        <?php endforeach; ?>
    </div>
    <?php
}
