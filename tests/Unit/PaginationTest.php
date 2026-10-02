<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Pagination;
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase
{
    public function test_normalize_page_clamps_values_below_one_to_one(): void
    {
        $this->assertSame(1, Pagination::normalizePage('0'));
        $this->assertSame(1, Pagination::normalizePage('-5'));
        $this->assertSame(1, Pagination::normalizePage(null));
        $this->assertSame(1, Pagination::normalizePage('not-a-number'));
    }

    public function test_normalize_page_passes_through_valid_pages(): void
    {
        $this->assertSame(3, Pagination::normalizePage('3'));
    }

    public function test_total_pages_and_navigation_flags(): void
    {
        $pagination = new Pagination(items: ['a', 'b'], total: 25, page: 2, perPage: 10);

        $this->assertSame(3, $pagination->totalPages());
        $this->assertTrue($pagination->hasPrevious());
        $this->assertTrue($pagination->hasNext());
    }

    public function test_last_page_has_no_next(): void
    {
        $pagination = new Pagination(items: [], total: 25, page: 3, perPage: 10);

        $this->assertFalse($pagination->hasNext());
        $this->assertTrue($pagination->hasPrevious());
    }

    public function test_normalize_per_page_accepts_only_allowed_values(): void
    {
        $this->assertSame(25, Pagination::normalizePerPage('25'));
        $this->assertSame(50, Pagination::normalizePerPage('50'));
        $this->assertSame(10, Pagination::normalizePerPage('999'));
        $this->assertSame(10, Pagination::normalizePerPage(null));
        $this->assertSame(10, Pagination::normalizePerPage('not-a-number'));
    }
}
