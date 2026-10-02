<?php
/**
 * Clickable uploaded-image thumbnail that opens the shared preview/upload
 * modal (image-preview.js) - used anywhere an uploaded image is shown, so
 * every one of them gets the same "view larger" / "Ganti Gambar" behaviour
 * instead of each caller hand-rolling the trigger markup.
 *
 * When there's no image yet ($src === ''), it still renders as a clickable
 * trigger (showing $emptyIcon instead of an <img>) as long as $uploadUrl is
 * set, so admins can add the first image right from wherever the thumbnail
 * appears - table row, gallery card, detail page. With no image AND no
 * upload permission, it falls back to a plain, non-interactive placeholder.
 *
 * @var string $src image URL, or '' when there's no image yet
 * @var string $alt
 * @var string $imgClass CSS class shared by the <img> and the empty-state
 *      placeholder, so both occupy the same size/shape
 * @var string|null $uploadUrl POST endpoint for replacing the image; omit/
 *      null to show preview only, without the "Ganti Gambar" button
 * @var string $emptyIcon icon name shown when $src is empty (default 'box')
 */

use App\Core\Icon;
use App\Core\View;

$uploadUrl = $uploadUrl ?? null;
$emptyIcon = $emptyIcon ?? 'box';

if ($src === '' && $uploadUrl === null) {
    // Nothing to preview and nothing the viewer can do about it - a plain,
    // non-interactive placeholder rather than a button that would do nothing.
    ?>
    <span class="<?= View::e($imgClass) ?> image-trigger__empty"><?= Icon::svg($emptyIcon, 20) ?></span>
    <?php
    return;
}
?>
<button
    type="button"
    class="image-trigger"
    data-image-preview
    data-image-src="<?= View::e($src) ?>"
    data-image-alt="<?= View::e($alt) ?>"
    data-img-class="<?= View::e($imgClass) ?>"
    <?= $uploadUrl !== null ? 'data-upload-url="' . View::e($uploadUrl) . '"' : '' ?>
    aria-label="<?= $src !== '' ? 'Lihat gambar ' . View::e($alt) : 'Tambah gambar ' . View::e($alt) ?>"
>
    <?php if ($src !== ''): ?>
        <img src="<?= View::e($src) ?>" alt="<?= View::e($alt) ?>" class="<?= View::e($imgClass) ?>">
    <?php else: ?>
        <span class="<?= View::e($imgClass) ?> image-trigger__empty"><?= Icon::svg($emptyIcon, 20) ?></span>
    <?php endif; ?>
</button>
