<?php

declare(strict_types=1);

const POST_IMAGE_MAX_BYTES = 10 * 1024 * 1024;
const POST_VIDEO_MAX_BYTES = 120 * 1024 * 1024;
const POST_MEDIA_MAX_FILES = 5;
const POST_VIDEO_MAX_SECONDS = 3600;

function postMediaUnsignedInteger(string $bytes): int|float
{
    $value = 0;
    for ($index = 0, $length = strlen($bytes); $index < $length; $index++) $value = ($value * 256) + ord($bytes[$index]);
    return $value;
}

function postMediaEbmlVint(string $data, int $offset): ?array
{
    if ($offset >= strlen($data)) return null;
    $first = ord($data[$offset]);
    $mask = 0x80;
    $length = 1;
    while ($length <= 8 && ($first & $mask) === 0) { $mask >>= 1; $length++; }
    if ($length > 8 || $offset + $length > strlen($data)) return null;
    $value = $first & ($mask - 1);
    for ($index = 1; $index < $length; $index++) $value = ($value * 256) + ord($data[$offset + $index]);
    return [$value, $length];
}

function postMediaFindSequence($handle, string $sequence, int $start = 0): ?int
{
    if (fseek($handle, $start) !== 0) return null;
    $overlap = '';
    $position = $start;
    while (!feof($handle)) {
        $chunk = fread($handle, 1024 * 1024);
        if ($chunk === false || $chunk === '') break;
        $search = $overlap . $chunk;
        $found = strpos($search, $sequence);
        if ($found !== false) return $position - strlen($overlap) + $found;
        $overlap = substr($search, -max(0, strlen($sequence) - 1));
        $position += strlen($chunk);
    }
    return null;
}

function postMediaReadAt($handle, int $offset, int $length): ?string
{
    if (fseek($handle, $offset) !== 0) return null;
    $bytes = fread($handle, $length);
    return $bytes !== false && strlen($bytes) === $length ? $bytes : null;
}

function postMediaVideoDuration(string $path, string $mime): ?float
{
    $handle = fopen($path, 'rb');
    if ($handle === false) return null;
    if ($mime === 'video/mp4') {
        $offset = 0;
        while (($offset = postMediaFindSequence($handle, 'mvhd', $offset)) !== null) {
            $payload = $offset + 4;
            $header = postMediaReadAt($handle, $payload, 32);
            if ($header === null) { fclose($handle); return null; }
            $version = ord($header[0]);
            $timescaleOffset = $payload + ($version === 1 ? 20 : 12);
            $durationOffset = $payload + ($version === 1 ? 24 : 16);
            $durationBytes = $version === 1 ? 8 : 4;
            $timescaleData = postMediaReadAt($handle, $timescaleOffset, 4);
            $durationData = postMediaReadAt($handle, $durationOffset, $durationBytes);
            if ($timescaleData === null || $durationData === null) { fclose($handle); return null; }
            $timescale = postMediaUnsignedInteger($timescaleData);
            $duration = postMediaUnsignedInteger($durationData);
            if ($timescale > 0 && $duration > 0) { fclose($handle); return $duration / $timescale; }
            $offset = $payload;
        }
        fclose($handle);
        return null;
    }
    if ($mime === 'video/webm') {
        $timecodeScale = 1000000;
        $scaleOffset = postMediaFindSequence($handle, "\x2A\xD7\xB1");
        $scaleHeader = $scaleOffset === null ? null : postMediaReadAt($handle, $scaleOffset + 3, 9);
        if ($scaleHeader !== null && ($size = postMediaEbmlVint($scaleHeader, 0))) {
            [$length, $sizeLength] = $size;
            if ($length > 0 && $length <= 8) $timecodeScale = postMediaUnsignedInteger(substr($scaleHeader, $sizeLength, $length));
        }
        $durationOffset = postMediaFindSequence($handle, "\x44\x89");
        $durationHeader = $durationOffset === null ? null : postMediaReadAt($handle, $durationOffset + 2, 9);
        if ($durationHeader === null || !($size = postMediaEbmlVint($durationHeader, 0))) { fclose($handle); return null; }
        [$length, $sizeLength] = $size;
        $bytes = substr($durationHeader, $sizeLength, $length);
        $duration = $length === 4 ? unpack('G', $bytes)[1] : ($length === 8 ? unpack('E', $bytes)[1] : null);
        fclose($handle);
        return is_float($duration) && $duration > 0 ? ($duration * $timecodeScale) / 1000000000 : null;
    }
    fclose($handle);
    return null;
}

function normalizePostMediaUploads(array $upload): array
{
    if (!is_array($upload['name'] ?? null)) return [[
        'name' => $upload['name'] ?? '', 'type' => $upload['type'] ?? '',
        'tmp_name' => $upload['tmp_name'] ?? '', 'error' => $upload['error'] ?? UPLOAD_ERR_NO_FILE,
        'size' => $upload['size'] ?? 0,
    ]];
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
            UPLOAD_ERR_PARTIAL => 'The media upload stopped before it finished. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server upload folder is unavailable.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not save the uploaded media.',
            UPLOAD_ERR_EXTENSION => 'The server rejected that media file.',
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
    if ($size < 1 || $size > $limit) throw new InvalidArgumentException($type === 'image' ? 'Use an image smaller than 10 MB.' : 'Use a video no larger than 120 MB.');
    if ($type === 'image') {
        $dimensions = @getimagesize($temporaryPath);
        if ($dimensions === false || $dimensions[0] > 10000 || $dimensions[1] > 10000 || $dimensions[0] * $dimensions[1] > 60000000) {
            throw new InvalidArgumentException('Use an image under 60 megapixels and 10000 pixels per side.');
        }
    } else {
        $duration = postMediaVideoDuration($temporaryPath, $mime);
        if ($duration === null) throw new InvalidArgumentException('The video duration could not be verified. Use a standard MP4 or WebM file.');
        if ($duration > POST_VIDEO_MAX_SECONDS) throw new InvalidArgumentException('Videos can be no longer than one hour.');
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
                <?php if ($item['media_type'] === 'image'): ?><img src="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" alt="Post attachment <?= $index + 1 ?>" loading="lazy"><?php else: ?><video src="<?= htmlspecialchars($url, ENT_QUOTES, 'UTF-8') ?>" controls preload="metadata"></video><label class="post-video-speed"><span class="sr-only">Playback speed</span><select data-video-speed><option value="1">1x</option><option value="2">2x</option></select></label><?php endif; ?>
                <?php if ($owned): ?>
                    <button type="button" data-post-media-remove data-media-id="<?= (int) ($item['id'] ?? 0) ?>" aria-label="Remove media <?= $index + 1 ?>">&times;</button>
                    <div class="post-media-edit-controls">
                        <button type="button" data-post-media-move="-1" data-media-id="<?= (int) ($item['id'] ?? 0) ?>" aria-label="Move media <?= $index + 1 ?> left"<?= $index === 0 ? ' disabled' : '' ?>>&#8592;</button>
                        <label title="Replace media <?= $index + 1 ?>"><span>Replace</span><input class="sr-only" type="file" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm" data-post-media-replace data-media-id="<?= (int) ($item['id'] ?? 0) ?>"></label>
                        <button type="button" data-post-media-move="1" data-media-id="<?= (int) ($item['id'] ?? 0) ?>" aria-label="Move media <?= $index + 1 ?> right"<?= $index === count($items) - 1 ? ' disabled' : '' ?>>&#8594;</button>
                    </div>
                <?php endif; ?>
            </figure>
        <?php endforeach; ?>
    </div>
    <?php
}

function renderPostEditForm(array $post): void
{
    ?>
    <form class="post-edit-form" data-post-edit-form hidden>
        <input class="post-edit-title" type="text" maxlength="100" placeholder="Post title" value="<?= htmlspecialchars((string) ($post['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" data-post-edit-title>
        <textarea maxlength="2500"><?= htmlspecialchars((string) ($post['content'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
        <div class="post-edit-media-tools">
            <label><span>Add media</span><input class="sr-only" type="file" accept="image/jpeg,image/png,image/webp,image/gif,video/mp4,video/webm" multiple data-post-media-add></label>
            <progress max="100" value="0" data-post-edit-upload-progress hidden></progress>
            <span data-post-edit-upload-status role="status"></span>
        </div>
        <div><button type="submit">Save</button><button type="button" data-post-edit-cancel>Cancel</button></div>
    </form>
    <?php
}
