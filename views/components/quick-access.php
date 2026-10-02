<?php
/**
 * Colorful "quick access" card grid for the dashboard - an icon tile on a
 * pastel background, a bold label, and a one-line description, rather than
 * the plain link-list every other card on this page uses. Reserved for this
 * one entry point since a whole page of these would fight the rest of the
 * app's flatter, denser table/form aesthetic.
 *
 * @var array<int,array{href:string,icon:string,label:string,description:string,color:string}> $items
 *      color is one of 'blue'|'yellow'|'pink'|'green'|'purple'
 */

use App\Core\Icon;
use App\Core\View;
?>
<ul class="quick-access">
    <?php foreach ($items as $item): ?>
        <li>
            <a class="quick-access__card quick-access__card--<?= View::e($item['color']) ?>" href="<?= View::e($item['href']) ?>">
                <span class="quick-access__icon"><?= Icon::svg($item['icon'], 26) ?></span>
                <strong class="quick-access__label"><?= View::e($item['label']) ?></strong>
                <span class="quick-access__description"><?= View::e($item['description']) ?></span>
            </a>
        </li>
    <?php endforeach; ?>
</ul>
