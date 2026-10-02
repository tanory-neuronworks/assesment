<?php

declare(strict_types=1);

namespace App\Service;

use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Pagination;
use App\Entity\Product;
use App\Repository\Contracts\CategoryRepositoryInterface;
use App\Repository\Contracts\ProductRepositoryInterface;
use App\Repository\Contracts\ProductStockRepositoryInterface;

final class ProductService
{
    public function __construct(
        private readonly ProductRepositoryInterface $products,
        private readonly CategoryRepositoryInterface $categories,
        private readonly ProductStockRepositoryInterface $stocks,
        private readonly ?ImageUploader $imageUploader = null,
    ) {
    }

    /**
     * @return Product[]
     */
    public function list(bool $onlyActive = false): array
    {
        return $this->products->all($onlyActive);
    }

    /**
     * @param 'low'|'normal'|null $stockStatus
     */
    public function paginate(?string $search, ?int $categoryId, ?string $stockStatus, int $page, int $perPage = 10): Pagination
    {
        return $this->products->paginate($search, $categoryId, $stockStatus, $page, $perPage);
    }

    public function find(int $id): Product
    {
        $product = $this->products->findById($id);
        if ($product === null) {
            throw new NotFoundException("Product #{$id} not found");
        }

        return $product;
    }

    public function totalStock(int $productId): int
    {
        return $this->stocks->totalForProduct($productId);
    }

    public function stockBreakdown(int $productId): array
    {
        return $this->stocks->findByProduct($productId);
    }

    /**
     * @param array<string,mixed> $data
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int}|null $file
     */
    public function create(array $data, ?array $file = null): Product
    {
        $errors = $this->validate($data, null);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $image = null;
        if ($file !== null && $this->imageUploader !== null) {
            $image = $this->imageUploader->store($file);
        }

        $product = new Product(
            id: null,
            sku: strtoupper(trim((string) $data['sku'])),
            name: trim((string) $data['name']),
            categoryId: (int) $data['category_id'],
            unit: trim((string) $data['unit']),
            costPrice: (float) $data['cost_price'],
            sellPrice: (float) $data['sell_price'],
            reorderPoint: (int) $data['reorder_point'],
            image: $image,
            isActive: true,
        );

        $this->products->create($product);

        return $product;
    }

    /**
     * @param array<string,mixed> $data
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int}|null $file
     */
    public function update(int $id, array $data, ?array $file = null): Product
    {
        $product = $this->find($id);

        $errors = $this->validate($data, $id);
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        if ($file !== null && $this->imageUploader !== null) {
            $product->image = $this->imageUploader->store($file);
        }

        $product->sku = strtoupper(trim((string) $data['sku']));
        $product->name = trim((string) $data['name']);
        $product->categoryId = (int) $data['category_id'];
        $product->unit = trim((string) $data['unit']);
        $product->costPrice = (float) $data['cost_price'];
        $product->sellPrice = (float) $data['sell_price'];
        $product->reorderPoint = (int) $data['reorder_point'];

        $this->products->update($product);

        return $product;
    }

    /**
     * Replaces just a product's image - used by the table/detail-page
     * "Ganti Gambar" popup, which only ever sends the new file (not the
     * rest of the product form), so it can't go through update()'s full
     * validate().
     *
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     */
    public function updateImage(int $id, array $file): Product
    {
        $product = $this->find($id);

        if ($this->imageUploader === null) {
            throw new \InvalidArgumentException('Upload gambar tidak diaktifkan.');
        }

        $product->image = $this->imageUploader->store($file);
        $this->products->update($product);

        return $product;
    }

    public function setActive(int $id, bool $active): void
    {
        $this->find($id);
        $this->products->setActive($id, $active);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    private function validate(array $data, ?int $excludeId): array
    {
        $errors = [];

        $sku = strtoupper(trim((string) ($data['sku'] ?? '')));
        if ($sku === '') {
            $errors['sku'] = 'SKU wajib diisi.';
        } elseif ($this->products->skuExists($sku, $excludeId)) {
            $errors['sku'] = 'SKU sudah digunakan.';
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'Nama produk wajib diisi.';
        }

        $categoryId = (string) ($data['category_id'] ?? '');
        if ($categoryId === '' || !ctype_digit($categoryId) || $this->categories->findById((int) $categoryId) === null) {
            $errors['category_id'] = 'Kategori tidak valid.';
        }

        $unit = trim((string) ($data['unit'] ?? ''));
        if ($unit === '') {
            $errors['unit'] = 'Unit wajib diisi.';
        }

        $costPrice = (string) ($data['cost_price'] ?? '');
        if (!is_numeric($costPrice) || (float) $costPrice < 0) {
            $errors['cost_price'] = 'Harga beli harus angka >= 0.';
        }

        $sellPrice = (string) ($data['sell_price'] ?? '');
        if (!is_numeric($sellPrice) || (float) $sellPrice < 0) {
            $errors['sell_price'] = 'Harga jual harus angka >= 0.';
        }

        $reorderPoint = (string) ($data['reorder_point'] ?? '');
        if (!ctype_digit($reorderPoint)) {
            $errors['reorder_point'] = 'Reorder point harus bilangan bulat >= 0.';
        }

        return $errors;
    }
}
