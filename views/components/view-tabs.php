<?php
/**
 * Client-side tab switcher for toggling between alternate views of the same
 * page (e.g. a flat Tabel vs a Kanban/accordion Board) - view-tabs.js shows
 * whichever [data-view-panel="{key}"] matches the active tab and hides the
 * rest. $default always wins on a fresh page load (no "remember last
 * choice" persistence) - SO/PO force Board and Products forces Galeri
 * every visit.
 *
 * @var array<int,array{key:string,label:string,icon:string}> $tabs
 * @var string|null $default key of the tab active on load - defaults to the
 *      first tab
 */

use App\Core\Icon;
use App\Core\View;

$default = $default ?? $tabs[0]['key'];
?>
<div class="view-tabs" role="tablist" data-view-tabs>
    <?php foreach ($tabs as $tab): ?>
        <?php $isActive = $tab['key'] === $default; ?>
        <button
            type="button"
            class="view-tabs__btn<?= $isActive ? ' is-active' : '' ?>"
            data-view-tab="<?= View::e($tab['key']) ?>"
            role="tab"
            aria-selected="<?= $isActive ? 'true' : 'false' ?>"
        >
            <?= Icon::svg($tab['icon'], 14) ?> <?= View::e($tab['label']) ?>
        </button>
    <?php endforeach; ?>
</div>
