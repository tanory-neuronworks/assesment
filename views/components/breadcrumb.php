<?php
/** @var array<int,array{label:string,url?:string}> $items */

use App\Core\View;

$items = $items ?? [];
$lastIndex = array_key_last($items);
?>
<nav class="breadcrumb" aria-label="Breadcrumb">
    <ol>
        <li><a href="/">Dashboard</a></li>
        <?php foreach ($items as $i => $item): ?>
            <li>
                <?php if ($i !== $lastIndex && isset($item['url'])): ?>
                    <a href="<?= View::e($item['url']) ?>"><?= View::e($item['label']) ?></a>
                <?php else: ?>
                    <span aria-current="page"><?= View::e($item['label']) ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
</nav>
