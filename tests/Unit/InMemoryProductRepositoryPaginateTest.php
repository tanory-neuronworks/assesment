<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Entity\Product;
use App\Repository\InMemory\InMemoryProductRepository;
use PHPUnit\Framework\TestCase;

final class InMemoryProductRepositoryPaginateTest extends TestCase
{
    private InMemoryProductRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new InMemoryProductRepository();
        // id => [sku, name, categoryId, categoryName, reorderPoint, stock, active]
        $this->add('SKU-001', 'Kabel USB', 1, 'Elektronik', 10, 3, true);      // low
        $this->add('SKU-002', 'Mouse Wireless', 1, 'Elektronik', 5, 37, true); // normal
        $this->add('KRS-003', 'Kursi Kantor', 2, 'Furnitur', 4, 4, true);      // normal (== reorder point)
        $this->add('KRS-004', 'Meja Lipat', 2, 'Furnitur', 8, 0, false);       // low, inactive
    }

    private function add(string $sku, string $name, int $categoryId, string $categoryName, int $reorder, int $stock, bool $active): void
    {
        $id = $this->repo->create(new Product(null, $sku, $name, $categoryId, 'pcs', 100.0, 250.0, $reorder, null, $active, $categoryName));
        $this->repo->seedStock($id, $stock);
    }

    /** @return string[] */
    private function skus(\App\Core\Pagination $p): array
    {
        return array_map(static fn (Product $x) => $x->sku, $p->items);
    }

    public function test_no_filters_returns_everything(): void
    {
        $p = $this->repo->paginate(null, null, null, 1, 10);

        $this->assertSame(4, $p->total);
        $this->assertSame(['SKU-001', 'SKU-002', 'KRS-003', 'KRS-004'], $this->skus($p));
    }

    public function test_empty_string_search_is_treated_as_no_search(): void
    {
        $this->assertSame(4, $this->repo->paginate('', null, null, 1, 10)->total);
    }

    public function test_search_matches_name_case_insensitively(): void
    {
        $p = $this->repo->paginate('kAbEl', null, null, 1, 10);

        $this->assertSame(['SKU-001'], $this->skus($p));
    }

    public function test_search_matches_sku_case_insensitively(): void
    {
        $p = $this->repo->paginate('krs-', null, null, 1, 10);

        $this->assertSame(['KRS-003', 'KRS-004'], $this->skus($p));
    }

    public function test_search_matches_category_name(): void
    {
        $this->assertSame(['SKU-001', 'SKU-002'], $this->skus($this->repo->paginate('elektronik', null, null, 1, 10)));
    }

    public function test_search_matches_active_status_label(): void
    {
        $this->assertSame(['KRS-004'], $this->skus($this->repo->paginate('nonaktif', null, null, 1, 10)));
    }

    public function test_search_matches_stock_quantity_and_sell_price(): void
    {
        $this->assertSame(['SKU-002'], $this->skus($this->repo->paginate('37', null, null, 1, 10)));
        $this->assertSame(4, $this->repo->paginate('250', null, null, 1, 10)->total);
    }

    public function test_search_without_match_returns_empty_page(): void
    {
        $p = $this->repo->paginate('tidak-ada-produk-ini', null, null, 1, 10);

        $this->assertSame(0, $p->total);
        $this->assertSame([], $p->items);
        $this->assertSame(1, $p->totalPages());
    }

    public function test_category_filter(): void
    {
        $this->assertSame(['KRS-003', 'KRS-004'], $this->skus($this->repo->paginate(null, 2, null, 1, 10)));
        $this->assertSame(0, $this->repo->paginate(null, 99, null, 1, 10)->total);
    }

    public function test_stock_status_low_means_stock_below_reorder_point(): void
    {
        $this->assertSame(['SKU-001', 'KRS-004'], $this->skus($this->repo->paginate(null, null, 'low', 1, 10)));
    }

    public function test_stock_status_normal_includes_stock_equal_to_reorder_point(): void
    {
        $this->assertSame(['SKU-002', 'KRS-003'], $this->skus($this->repo->paginate(null, null, 'normal', 1, 10)));
    }

    public function test_unknown_stock_status_is_ignored(): void
    {
        $this->assertSame(4, $this->repo->paginate(null, null, 'bogus', 1, 10)->total);
    }

    public function test_unseeded_stock_defaults_to_zero(): void
    {
        $repo = new InMemoryProductRepository();
        $repo->create(new Product(null, 'NOSTOCK', 'Tanpa Stok', 1, 'pcs', 1.0, 2.0, 1));

        $this->assertSame(1, $repo->paginate(null, null, 'low', 1, 10)->total);
        $this->assertSame(0, $repo->paginate(null, null, 'normal', 1, 10)->total);
    }

    public function test_filters_are_combined_with_and_semantics(): void
    {
        $p = $this->repo->paginate('kantor', 2, 'normal', 1, 10);
        $this->assertSame(['KRS-003'], $this->skus($p));

        $this->assertSame(0, $this->repo->paginate('kantor', 1, 'normal', 1, 10)->total);
        $this->assertSame(0, $this->repo->paginate('kabel', 1, 'normal', 1, 10)->total);
        $this->assertSame(['SKU-001'], $this->skus($this->repo->paginate('kabel', 1, 'low', 1, 10)));
    }

    public function test_pagination_slices_pages_and_reports_totals(): void
    {
        $page1 = $this->repo->paginate(null, null, null, 1, 3);
        $page2 = $this->repo->paginate(null, null, null, 2, 3);

        $this->assertSame(['SKU-001', 'SKU-002', 'KRS-003'], $this->skus($page1));
        $this->assertSame(['KRS-004'], $this->skus($page2));
        $this->assertSame(4, $page2->total);
        $this->assertSame(2, $page2->totalPages());
        $this->assertSame(3, $page2->perPage);
        $this->assertFalse($page2->hasNext());
        $this->assertTrue($page2->hasPrevious());
    }

    public function test_page_beyond_last_is_empty_but_keeps_total(): void
    {
        $p = $this->repo->paginate(null, null, null, 5, 3);

        $this->assertSame([], $p->items);
        $this->assertSame(4, $p->total);
        $this->assertSame(5, $p->page);
    }

    public function test_page_below_one_is_clamped_to_first_page(): void
    {
        $p = $this->repo->paginate(null, null, null, -3, 2);

        $this->assertSame(1, $p->page);
        $this->assertSame(['SKU-001', 'SKU-002'], $this->skus($p));
    }

    public function test_exact_multiple_of_per_page_has_no_extra_page(): void
    {
        $p = $this->repo->paginate(null, null, null, 2, 2);

        $this->assertSame(['KRS-003', 'KRS-004'], $this->skus($p));
        $this->assertSame(2, $p->totalPages());
    }
}
