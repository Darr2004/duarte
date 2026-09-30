<?php
/**
 * DuaRTE — Item image upload helper.
 * Keeps the validation/storage logic in one place for item_add.php
 * and item_edit.php.
 */

define('ITEM_UPLOAD_DIR', __DIR__ . '/../uploads/items/');
define('ITEM_UPLOAD_URL', BASE_URL . '/uploads/items/');

const ITEM_IMAGE_MAX_BYTES = 3 * 1024 * 1024; // 3MB
const ITEM_IMAGE_ALLOWED   = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

/**
 * Validates and stores an uploaded item (or item option) image.
 * Returns the stored filename on success, null if no file was submitted,
 * or throws a RuntimeException with a user-facing message on failure.
 *
 * @param string $prefix Filename prefix — 'item_' for the item's own
 *                        photo, 'variant_' for a per-option photo. Both
 *                        live in the same uploads/items/ directory since
 *                        an option's photo is just a closer look at the
 *                        same catalog entry.
 */
function handle_item_image_upload(array $file, string $prefix = 'item_'): ?string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed. Please try again.');
    }
    if ($file['size'] > ITEM_IMAGE_MAX_BYTES) {
        throw new RuntimeException('Image must be 3MB or smaller.');
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!isset(ITEM_IMAGE_ALLOWED[$ext])) {
        throw new RuntimeException('Image must be a JPG, PNG, or WEBP file.');
    }

    // Verify the file is actually an image (not just renamed).
    $mime = mime_content_type($file['tmp_name']);
    if ($mime !== ITEM_IMAGE_ALLOWED[$ext]) {
        throw new RuntimeException('That file does not look like a valid image.');
    }

    if (!is_dir(ITEM_UPLOAD_DIR)) {
        mkdir(ITEM_UPLOAD_DIR, 0755, true);
    }

    $filename = $prefix . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], ITEM_UPLOAD_DIR . $filename)) {
        throw new RuntimeException('Could not save the uploaded image.');
    }

    return $filename;
}

function delete_item_image(?string $filename): void
{
    if ($filename && is_file(ITEM_UPLOAD_DIR . $filename)) {
        unlink(ITEM_UPLOAD_DIR . $filename);
    }
}

/**
 * DuaRTE — Profile picture upload helper.
 * Mirrors handle_item_image_upload()/delete_item_image() above, but
 * stores into its own uploads/profile/ directory so account photos
 * stay separate from catalog item images.
 */

define('PROFILE_UPLOAD_DIR', __DIR__ . '/../uploads/profile/');
define('PROFILE_UPLOAD_URL', BASE_URL . '/uploads/profile/');

const PROFILE_IMAGE_MAX_BYTES = 2 * 1024 * 1024; // 2MB
const PROFILE_IMAGE_ALLOWED   = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

/**
 * Validates and stores an uploaded profile picture.
 * Returns the stored filename on success, null if no file was submitted,
 * or throws a RuntimeException with a user-facing message on failure.
 */
function handle_profile_image_upload(array $file): ?string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Photo upload failed. Please try again.');
    }
    if ($file['size'] > PROFILE_IMAGE_MAX_BYTES) {
        throw new RuntimeException('Photo must be 2MB or smaller.');
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!isset(PROFILE_IMAGE_ALLOWED[$ext])) {
        throw new RuntimeException('Photo must be a JPG, PNG, or WEBP file.');
    }

    // Verify the file is actually an image (not just renamed).
    $mime = mime_content_type($file['tmp_name']);
    if ($mime !== PROFILE_IMAGE_ALLOWED[$ext]) {
        throw new RuntimeException('That file does not look like a valid image.');
    }

    if (!is_dir(PROFILE_UPLOAD_DIR)) {
        mkdir(PROFILE_UPLOAD_DIR, 0755, true);
    }

    $filename = 'profile_' . bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], PROFILE_UPLOAD_DIR . $filename)) {
        throw new RuntimeException('Could not save the uploaded photo.');
    }

    return $filename;
}

function delete_profile_image(?string $filename): void
{
    if ($filename && is_file(PROFILE_UPLOAD_DIR . $filename)) {
        unlink(PROFILE_UPLOAD_DIR . $filename);
    }
}
