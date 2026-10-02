<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\Role;

final class ProductControllerTest extends ControllerTestCase
{
    private const CSRF_MESSAGE = 'Sesi tidak valid, silakan coba lagi.';

    private function makeCategory(): Category
    {
        return $this->categoryService->create(['name' => 'PCat ' . uniqid(), 'description' => 'test']);
    }

    private function makeProduct(Category $category, ?string $name = null): Product
    {
        $tag = strtoupper(uniqid());

        return $this->productService->create([
            'sku' => 'SKU-' . $tag,
            'name' => $name ?? 'Produk ' . $tag,
            'category_id' => (string) $category->id,
            'unit' => 'pcs',
            'cost_price' => '1000',
            'sell_price' => '2500',
            'reorder_point' => '5',
        ]);
    }

    /**
     * @return array<string,string>
     */
    private function validForm(Category $category, string $sku): array
    {
        return [
            'sku' => $sku,
            'name' => 'Nama ' . $sku,
            'category_id' => (string) $category->id,
            'unit' => 'box',
            'cost_price' => '1500',
            'sell_price' => '3000',
            'reorder_point' => '7',
        ];
    }

    private function countBySku(string $sku): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM products WHERE sku = ?');
        $stmt->execute([$sku]);

        return (int) $stmt->fetchColumn();
    }

    // ---------------------------------------------------------------- index

    public function test_index_requires_login(): void
    {
        $this->assertRedirectTo('/login', $this->get('/products'));
    }

    public function test_index_renders_full_page_with_table_row_for_any_role(): void
    {
        $product = $this->makeProduct($this->makeCategory());
        $this->loginAs(Role::Sales);

        $result = $this->get('/products', ['q' => $product->sku, 'view' => 'table']);

        $this->assertSame(200, $result->status);
        $this->assertStringContainsString('<html', $result->body);
        $this->assertStringContainsString($product->sku, $result->body);
        $this->assertStringContainsString($product->name, $result->body);
        $this->assertStringNotContainsString('/products/create', $result->body, 'Sales must not see the add-product button.');
    }

    public function test_index_shows_add_button_to_admin(): void
    {
        $this->loginAs(Role::Admin);

        $this->assertStringContainsString('/products/create', $this->get('/products')->body);
    }

    public function test_index_ajax_without_ajax_view_returns_table_fragment_only(): void
    {
        $product = $this->makeProduct($this->makeCategory());
        $this->loginAs(Role::Admin);

        $result = $this->get('/products', ['q' => $product->sku], true);

        $this->assertSame(200, $result->status);
        $this->assertStringContainsString($product->sku, $result->body);
        $this->assertStringNotContainsString('<html', $result->body);
    }

    public function test_index_search_with_no_match_shows_empty_message(): void
    {
        $this->loginAs(Role::Admin);

        $result = $this->get('/products', ['q' => 'zz_none_' . uniqid()], true);

        $this->assertStringContainsString('Belum ada produk.', $result->body);
    }

    public function test_index_filters_by_category(): void
    {
        $catA = $this->makeCategory();
        $catB = $this->makeCategory();
        $inA = $this->makeProduct($catA);
        $inB = $this->makeProduct($catB);
        $this->loginAs(Role::Admin);

        $result = $this->get('/products', ['category' => (string) $catA->id], true);

        $this->assertStringContainsString($inA->sku, $result->body);
        $this->assertStringNotContainsString($inB->sku, $result->body);
    }

    public function test_index_stock_filter_low_includes_product_with_zero_stock_and_normal_excludes_it(): void
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category); // no stock rows, reorder point 5 => low
        $this->loginAs(Role::Admin);

        $low = $this->get('/products', ['category' => (string) $category->id, 'stock' => 'low'], true);
        $normal = $this->get('/products', ['category' => (string) $category->id, 'stock' => 'normal'], true);

        $this->assertStringContainsString($product->sku, $low->body);
        $this->assertStringNotContainsString($product->sku, $normal->body);
    }

    public function test_index_page_defaults_to_gallery_tab_and_honours_view_table(): void
    {
        $this->loginAs(Role::Admin);

        $gallery = $this->get('/products');
        $table = $this->get('/products', ['view' => 'table']);

        $this->assertMatchesRegularExpression('/data-view-panel="table"\s+hidden/', $gallery->body);
        $this->assertDoesNotMatchRegularExpression('/data-view-panel="table"\s+hidden/', $table->body);
    }

    // -------------------------------------------------------------- gallery

    public function test_gallery_ajax_returns_cards_grouped_by_category_and_respects_limit(): void
    {
        $category = $this->makeCategory();
        $products = [];
        for ($i = 1; $i <= 3; $i++) {
            $products[] = $this->makeProduct($category, 'Galeri' . $i . '_' . uniqid());
        }
        $this->loginAs(Role::Admin);

        $result = $this->get('/products', ['ajax_view' => 'gallery', 'limit' => '2'], true);

        $this->assertSame(200, $result->status);
        $this->assertStringNotContainsString('<html', $result->body);
        $this->assertStringContainsString($category->name, $result->body);
        $shown = 0;
        foreach ($products as $p) {
            $shown += str_contains($result->body, $p->name) ? 1 : 0;
        }
        $this->assertSame(2, $shown, 'limit=2 must cap the initial cards for the category group.');
    }

    public function test_gallery_ajax_search_filters_by_name_or_sku(): void
    {
        $category = $this->makeCategory();
        $needle = 'Needle' . uniqid();
        $match = $this->makeProduct($category, $needle);
        $other = $this->makeProduct($category, 'Other' . uniqid());
        $this->loginAs(Role::Admin);

        $byName = $this->get('/products', ['ajax_view' => 'gallery', 'gallery_q' => strtolower($needle)], true);
        $bySku = $this->get('/products', ['ajax_view' => 'gallery', 'gallery_q' => $other->sku], true);

        $this->assertStringContainsString($match->name, $byName->body);
        $this->assertStringNotContainsString($other->name, $byName->body);
        $this->assertStringContainsString($other->name, $bySku->body);
        $this->assertStringNotContainsString($match->name, $bySku->body);
    }

    public function test_gallery_ajax_with_no_match_renders_empty_state(): void
    {
        $this->loginAs(Role::Admin);

        $result = $this->get('/products', ['ajax_view' => 'gallery', 'gallery_q' => 'zz_none_' . uniqid()], true);

        $this->assertStringContainsString('empty-state', $result->body);
    }

    public function test_gallery_ajax_view_is_ignored_for_non_ajax_requests(): void
    {
        $this->loginAs(Role::Admin);

        $result = $this->get('/products', ['ajax_view' => 'gallery']);

        $this->assertStringContainsString('<html', $result->body);
    }

    public function test_gallery_more_returns_json_page_with_paging_metadata(): void
    {
        $category = $this->makeCategory();
        for ($i = 0; $i < 3; $i++) {
            $this->makeProduct($category);
        }
        $this->loginAs(Role::Admin);
        $base = ['ajax_view' => 'gallery-more', 'category_id' => (string) $category->id, 'limit' => '2'];

        $first = $this->get('/products', $base + ['offset' => '0'], true);
        $second = $this->get('/products', ['offset' => '2'] + $base, true);

        $this->assertSame(200, $first->status);
        $this->assertSame('application/json', $first->headers['content-type']);
        $data = $first->json();
        $this->assertSame(3, $data['total']);
        $this->assertTrue($data['hasMore']);
        $this->assertSame(2, $data['nextOffset']);
        $this->assertSame(2, substr_count($data['html'], 'class="gallery-card"'));

        $data = $second->json();
        $this->assertSame(3, $data['total']);
        $this->assertFalse($data['hasMore']);
        $this->assertSame(3, $data['nextOffset']);
        $this->assertSame(1, substr_count($data['html'], 'class="gallery-card"'));
    }

    public function test_gallery_more_clamps_negative_offset_and_non_numeric_limit(): void
    {
        $category = $this->makeCategory();
        $this->makeProduct($category);
        $this->makeProduct($category);
        $this->loginAs(Role::Admin);

        $result = $this->get('/products', [
            'ajax_view' => 'gallery-more',
            'category_id' => (string) $category->id,
            'offset' => '-10',
            'limit' => 'abc',
        ], true);

        $data = $result->json();
        $this->assertSame(2, $data['total']);
        $this->assertSame(1, $data['nextOffset'], 'limit is clamped up to 1 and offset down to 0.');
        $this->assertTrue($data['hasMore']);
    }

    public function test_gallery_more_defaults_to_five_cards_and_clamps_limit_to_fifty(): void
    {
        $category = $this->makeCategory();
        for ($i = 0; $i < 7; $i++) {
            $this->makeProduct($category);
        }
        $this->loginAs(Role::Admin);
        $base = ['ajax_view' => 'gallery-more', 'category_id' => (string) $category->id];

        $default = $this->get('/products', $base, true)->json();
        $huge = $this->get('/products', $base + ['limit' => '9999'], true)->json();

        $this->assertSame(5, $default['nextOffset']);
        $this->assertTrue($default['hasMore']);
        $this->assertSame(7, $huge['nextOffset']);
        $this->assertFalse($huge['hasMore']);
    }

    public function test_gallery_more_applies_search_and_only_returns_requested_category(): void
    {
        $category = $this->makeCategory();
        $otherCategory = $this->makeCategory();
        $needle = 'Findme' . uniqid();
        $this->makeProduct($category, $needle);
        $this->makeProduct($category);
        $this->makeProduct($otherCategory, $needle . '_other');
        $this->loginAs(Role::Admin);

        $data = $this->get('/products', [
            'ajax_view' => 'gallery-more',
            'category_id' => (string) $category->id,
            'gallery_q' => $needle,
        ], true)->json();

        $this->assertSame(1, $data['total']);
        $this->assertStringContainsString($needle, $data['html']);
        $this->assertStringNotContainsString($needle . '_other', $data['html']);
    }

    public function test_gallery_more_for_empty_category_returns_zero_total(): void
    {
        $this->loginAs(Role::Admin);

        $data = $this->get('/products', ['ajax_view' => 'gallery-more', 'category_id' => '999999999'], true)->json();

        $this->assertSame(['html' => '', 'total' => 0, 'hasMore' => false, 'nextOffset' => 0], $data);
    }

    public function test_gallery_requires_login(): void
    {
        $this->assertRedirectTo('/login', $this->get('/products', ['ajax_view' => 'gallery'], true));
    }

    // ---------------------------------------------------------------- show

    public function test_show_renders_product_and_404s_for_unknown_id(): void
    {
        $product = $this->makeProduct($this->makeCategory());
        $this->loginAs(Role::WarehouseStaff);

        $ok = $this->get("/products/{$product->id}");
        $missing = $this->get('/products/999999999');

        $this->assertSame(200, $ok->status);
        $this->assertStringContainsString($product->name, $ok->body);
        $this->assertSame(404, $missing->status);
    }

    // --------------------------------------------------------------- create

    public function test_create_page_is_admin_only(): void
    {
        $this->loginAs(Role::Admin);
        $this->assertStringContainsString('Tambah Produk', $this->get('/products/create')->body);

        $this->logout();
        $this->loginAs(Role::Sales);
        $this->assertSame(403, $this->get('/products/create')->status);
    }

    public function test_store_creates_product_with_uppercased_sku_and_redirects_to_list(): void
    {
        $category = $this->makeCategory();
        $this->loginAs(Role::Admin);
        $sku = 'itest-' . uniqid();

        $result = $this->postWithCsrf('/products', $this->validForm($category, $sku));

        $this->assertRedirectTo('/products', $result);
        $this->assertFlash('success', 'Produk berhasil dibuat.');
        $this->assertSame(1, $this->countBySku(strtoupper($sku)));
        $row = $this->pdo->query("SELECT * FROM products WHERE sku = '" . strtoupper($sku) . "'")->fetch();
        $this->assertSame((int) $category->id, (int) $row['category_id']);
        $this->assertSame(7, (int) $row['reorder_point']);
        $this->assertNull($row['image']);
    }

    public function test_store_with_validation_errors_redirects_back_and_creates_nothing(): void
    {
        $this->loginAs(Role::Admin);
        $sku = 'itest-' . uniqid();
        $form = ['sku' => $sku, 'name' => '', 'category_id' => '999999999', 'unit' => '', 'cost_price' => 'abc', 'sell_price' => '-1', 'reorder_point' => 'x'];

        $result = $this->postWithCsrf('/products', $form);

        $this->assertRedirectTo('/products/create', $result);
        $errors = $this->sessionErrors();
        foreach (['name', 'category_id', 'unit', 'cost_price', 'sell_price', 'reorder_point'] as $field) {
            $this->assertArrayHasKey($field, $errors);
        }
        $this->assertSame($sku, $this->sessionOldInput()['sku'] ?? null);
        $this->assertSame(0, $this->countBySku(strtoupper($sku)));
    }

    public function test_store_rejects_duplicate_sku(): void
    {
        $category = $this->makeCategory();
        $existing = $this->makeProduct($category);
        $this->loginAs(Role::Admin);

        $result = $this->postWithCsrf('/products', $this->validForm($category, $existing->sku));

        $this->assertRedirectTo('/products/create', $result);
        $this->assertSame('SKU sudah digunakan.', $this->sessionErrors()['sku'] ?? null);
        $this->assertSame(1, $this->countBySku($existing->sku));
    }

    public function test_store_with_invalid_csrf_redirects_with_error_and_creates_nothing(): void
    {
        $category = $this->makeCategory();
        $this->loginAs(Role::Admin);
        $this->csrfToken();
        $sku = 'itest-' . uniqid();

        $result = $this->request('POST', '/products', ['_csrf' => 'forged'] + $this->validForm($category, $sku));

        $this->assertRedirectTo('/products/create', $result);
        $this->assertFlash('error', self::CSRF_MESSAGE);
        $this->assertSame(0, $this->countBySku(strtoupper($sku)));
    }

    public function test_store_with_unsupported_image_type_redirects_with_image_error_and_creates_nothing(): void
    {
        $category = $this->makeCategory();
        $this->loginAs(Role::Admin);
        $sku = 'itest-' . uniqid();

        $result = $this->postWithCsrf(
            '/products',
            $this->validForm($category, $sku),
            false,
            ['image' => $this->fakeUpload('this is plain text, not an image')],
        );

        $this->assertRedirectTo('/products/create', $result);
        $this->assertSame('Tipe gambar harus JPG, PNG, atau WEBP.', $this->sessionErrors()['image'] ?? null);
        $this->assertSame(0, $this->countBySku(strtoupper($sku)));
    }

    public function test_store_is_forbidden_for_sales_and_creates_nothing(): void
    {
        $category = $this->makeCategory();
        $this->loginAs(Role::Sales);
        $sku = 'itest-' . uniqid();

        $result = $this->postWithCsrf('/products', $this->validForm($category, $sku));

        $this->assertSame(403, $result->status);
        $this->assertSame(0, $this->countBySku(strtoupper($sku)));
    }

    // ----------------------------------------------------------------- edit

    public function test_edit_page_prefills_product_and_is_admin_only(): void
    {
        $product = $this->makeProduct($this->makeCategory());
        $this->loginAs(Role::Admin);

        $result = $this->get("/products/{$product->id}/edit");

        $this->assertSame(200, $result->status);
        $this->assertStringContainsString('Edit Produk', $result->body);
        $this->assertStringContainsString($product->sku, $result->body);

        $this->logout();
        $this->loginAs(Role::WarehouseStaff);
        $this->assertSame(403, $this->get("/products/{$product->id}/edit")->status);
    }

    public function test_update_changes_row_and_redirects_to_list(): void
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $this->loginAs(Role::Admin);
        $form = $this->validForm($category, $product->sku);
        $form['name'] = 'Diganti ' . uniqid();
        $form['sell_price'] = '9999';

        $result = $this->postWithCsrf("/products/{$product->id}", $form);

        $this->assertRedirectTo('/products', $result);
        $this->assertFlash('success', 'Produk berhasil diperbarui.');
        $reloaded = $this->productService->find((int) $product->id);
        $this->assertSame($form['name'], $reloaded->name);
        $this->assertSame(9999.0, $reloaded->sellPrice);
        $this->assertSame(7, $reloaded->reorderPoint);
    }

    public function test_update_with_validation_errors_redirects_to_edit_and_keeps_row(): void
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $this->loginAs(Role::Admin);
        $form = $this->validForm($category, $product->sku);
        $form['name'] = '';

        $result = $this->postWithCsrf("/products/{$product->id}", $form);

        $this->assertRedirectTo("/products/{$product->id}/edit", $result);
        $this->assertArrayHasKey('name', $this->sessionErrors());
        $this->assertSame($product->name, $this->productService->find((int) $product->id)->name);
    }

    public function test_update_with_invalid_csrf_does_not_change_row(): void
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $this->loginAs(Role::Admin);
        $this->csrfToken();

        $result = $this->request('POST', "/products/{$product->id}", ['_csrf' => 'forged'] + $this->validForm($category, $product->sku));

        $this->assertRedirectTo("/products/{$product->id}/edit", $result);
        $this->assertFlash('error', self::CSRF_MESSAGE);
        $this->assertSame($product->name, $this->productService->find((int) $product->id)->name);
    }

    public function test_update_with_unsupported_image_type_redirects_with_image_error_and_keeps_row(): void
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $this->loginAs(Role::Admin);
        $form = $this->validForm($category, $product->sku);
        $form['name'] = 'Should not persist';

        $result = $this->postWithCsrf("/products/{$product->id}", $form, false, ['image' => $this->fakeUpload('not an image')]);

        $this->assertRedirectTo("/products/{$product->id}/edit", $result);
        $this->assertSame('Tipe gambar harus JPG, PNG, atau WEBP.', $this->sessionErrors()['image'] ?? null);
        $this->assertSame($product->name, $this->productService->find((int) $product->id)->name);
    }

    public function test_update_unknown_product_is_404(): void
    {
        $category = $this->makeCategory();
        $this->loginAs(Role::Admin);

        $result = $this->postWithCsrf('/products/999999999', $this->validForm($category, 'ZZ-' . uniqid()));

        $this->assertSame(404, $result->status);
    }

    public function test_update_is_forbidden_for_sales(): void
    {
        $category = $this->makeCategory();
        $product = $this->makeProduct($category);
        $this->loginAs(Role::Sales);
        $form = $this->validForm($category, $product->sku);
        $form['name'] = 'Hijacked';

        $result = $this->postWithCsrf("/products/{$product->id}", $form);

        $this->assertSame(403, $result->status);
        $this->assertSame($product->name, $this->productService->find((int) $product->id)->name);
    }

    // --------------------------------------------------------- toggleActive

    public function test_toggle_active_deactivates_and_reactivates(): void
    {
        $product = $this->makeProduct($this->makeCategory());
        $this->loginAs(Role::Admin);

        $off = $this->postWithCsrf("/products/{$product->id}/toggle-active", ['active' => '0']);
        $this->assertRedirectTo('/products', $off);
        $this->assertFlash('success', 'Produk dinonaktifkan.');
        $this->assertFalse($this->productService->find((int) $product->id)->isActive);

        $on = $this->postWithCsrf("/products/{$product->id}/toggle-active", ['active' => '1']);
        $this->assertRedirectTo('/products', $on);
        $this->assertFlash('success', 'Produk diaktifkan.');
        $this->assertTrue($this->productService->find((int) $product->id)->isActive);
    }

    public function test_toggle_active_with_invalid_csrf_changes_nothing(): void
    {
        $product = $this->makeProduct($this->makeCategory());
        $this->loginAs(Role::Admin);
        $this->csrfToken();

        $result = $this->request('POST', "/products/{$product->id}/toggle-active", ['_csrf' => 'forged', 'active' => '0']);

        $this->assertRedirectTo('/products', $result);
        $this->assertFlash('error', self::CSRF_MESSAGE);
        $this->assertTrue($this->productService->find((int) $product->id)->isActive);
    }

    // ---------------------------------------------------------- updateImage

    public function test_update_image_returns_401_json_for_guest(): void
    {
        $product = $this->makeProduct($this->makeCategory());

        $result = $this->request('POST', "/products/{$product->id}/image", ['_csrf' => 'x'], [], false, ['image' => $this->fakeUpload('x')]);

        $this->assertSame(401, $result->status);
        $this->assertSame(['error' => 'Unauthorized'], $result->json());
        $this->assertSame('application/json', $result->headers['content-type']);
    }

    public function test_update_image_returns_403_json_for_non_admin(): void
    {
        $product = $this->makeProduct($this->makeCategory());
        $this->loginAs(Role::Sales);

        $result = $this->postWithCsrf("/products/{$product->id}/image", [], true, ['image' => $this->fakeUpload('x')]);

        $this->assertSame(403, $result->status);
        $this->assertSame(['error' => 'Forbidden'], $result->json());
    }

    public function test_update_image_returns_419_json_for_bad_csrf(): void
    {
        $product = $this->makeProduct($this->makeCategory());
        $this->loginAs(Role::Admin);
        $this->csrfToken();

        $result = $this->request('POST', "/products/{$product->id}/image", ['_csrf' => 'forged'], [], true, ['image' => $this->fakeUpload('x')]);

        $this->assertSame(419, $result->status);
        $this->assertSame(['error' => 'Sesi tidak valid, silakan muat ulang halaman.'], $result->json());
    }

    public function test_update_image_returns_422_json_when_no_file_was_sent(): void
    {
        $product = $this->makeProduct($this->makeCategory());
        $this->loginAs(Role::Admin);

        $result = $this->postWithCsrf("/products/{$product->id}/image", [], true);

        $this->assertSame(422, $result->status);
        $this->assertSame(['error' => 'Pilih gambar terlebih dahulu.'], $result->json());
    }

    public function test_update_image_treats_upload_err_no_file_as_missing_file(): void
    {
        $product = $this->makeProduct($this->makeCategory());
        $this->loginAs(Role::Admin);

        $result = $this->postWithCsrf("/products/{$product->id}/image", [], true, [
            'image' => $this->fakeUpload('', 'x.png', UPLOAD_ERR_NO_FILE),
        ]);

        $this->assertSame(422, $result->status);
        $this->assertSame(['error' => 'Pilih gambar terlebih dahulu.'], $result->json());
    }

    public function test_update_image_returns_422_for_wrong_mime_type_and_leaves_image_untouched(): void
    {
        $product = $this->makeProduct($this->makeCategory());
        $this->loginAs(Role::Admin);

        $result = $this->postWithCsrf("/products/{$product->id}/image", [], true, ['image' => $this->fakeUpload('plain text')]);

        $this->assertSame(422, $result->status);
        $this->assertSame(['error' => 'Tipe gambar harus JPG, PNG, atau WEBP.'], $result->json());
        $this->assertNull($this->productService->find((int) $product->id)->image);
    }

    public function test_update_image_returns_422_for_php_upload_size_error(): void
    {
        $product = $this->makeProduct($this->makeCategory());
        $this->loginAs(Role::Admin);

        $result = $this->postWithCsrf("/products/{$product->id}/image", [], true, [
            'image' => $this->fakeUpload('', 'big.png', UPLOAD_ERR_INI_SIZE),
        ]);

        $this->assertSame(422, $result->status);
        $this->assertSame(['error' => 'Ukuran gambar maksimal 2MB.'], $result->json());
    }

    public function test_update_image_returns_422_for_interrupted_upload(): void
    {
        $product = $this->makeProduct($this->makeCategory());
        $this->loginAs(Role::Admin);

        $result = $this->postWithCsrf("/products/{$product->id}/image", [], true, [
            'image' => $this->fakeUpload('', 'part.png', UPLOAD_ERR_PARTIAL),
        ]);

        $this->assertSame(422, $result->status);
        $this->assertSame(['error' => 'Upload gambar terputus, silakan coba lagi.'], $result->json());
    }

    public function test_update_image_returns_422_for_oversized_file(): void
    {
        $product = $this->makeProduct($this->makeCategory());
        $this->loginAs(Role::Admin);
        $upload = $this->fakeUpload('tiny', 'big.png');
        $upload['size'] = 3 * 1024 * 1024;

        $result = $this->postWithCsrf("/products/{$product->id}/image", [], true, ['image' => $upload]);

        $this->assertSame(422, $result->status);
        $this->assertSame(['error' => 'Ukuran gambar maksimal 2MB.'], $result->json());
    }

    public function test_update_image_returns_422_for_generic_upload_failure(): void
    {
        $product = $this->makeProduct($this->makeCategory());
        $this->loginAs(Role::Admin);

        $result = $this->postWithCsrf("/products/{$product->id}/image", [], true, [
            'image' => $this->fakeUpload('', 'x.png', UPLOAD_ERR_NO_TMP_DIR),
        ]);

        $this->assertSame(422, $result->status);
        $this->assertSame(['error' => 'Upload gagal diproses.'], $result->json());
    }

    public function test_update_image_returns_404_json_for_unknown_product(): void
    {
        $this->loginAs(Role::Admin);

        $result = $this->postWithCsrf('/products/999999999/image', [], true, ['image' => $this->fakeUpload('x')]);

        $this->assertSame(404, $result->status);
        $this->assertSame(['error' => 'Produk tidak ditemukan.'], $result->json());
    }
}
