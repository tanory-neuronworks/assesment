<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\Contracts\ProductRepositoryInterface;

/**
 * Validation shared by purchase and sales orders: active header references,
 * order date, and the item rows (product / qty / unit price). The two order
 * types differ only in field names and messages, which are passed in.
 */
final class OrderValidator
{
    public function __construct(private readonly ProductRepositoryInterface $products)
    {
    }

    /**
     * @param array<string,mixed> $data
     * @param array<int,array<string,mixed>> $itemRows
     * @param list<array{0:string,1:string,2:string,3:callable(int):(object|null)}> $refs
     *        [key, required message, invalid message, finder returning an object exposing isActive]
     * @param array{0:string,1:string} $qty [row field, error key]
     * @param array{0:string,1:string,2:string} $price [row field, error key, error message]
     * @return array{0: array<string,string>, 1: list<array{product_id:string,qty:string,price:string}>}
     *         errors, and the complete valid rows (raw strings, to be cast by the caller)
     */
    public function validate(array $data, array $itemRows, array $refs, array $qty, array $price): array
    {
        $errors = [];

        foreach ($refs as [$key, $requiredMsg, $invalidMsg, $find]) {
            $this->validateActiveRef($errors, $key, (string) ($data[$key] ?? ''), $requiredMsg, $invalidMsg, $find);
        }

        $dateError = $this->dateError((string) ($data['order_date'] ?? ''));
        if ($dateError !== null) {
            $errors['order_date'] = $dateError;
        }

        if ($itemRows === []) {
            $errors['items'] = 'Minimal 1 item produk wajib diisi.';
        }

        $rows = [];
        foreach ($itemRows as $index => $row) {
            $productId = (string) ($row['product_id'] ?? '');
            $qtyValue = (string) ($row[$qty[0]] ?? '');
            $priceValue = (string) ($row[$price[0]] ?? '');

            if ($productId === '' && $qtyValue === '' && $priceValue === '') {
                continue;
            }

            $rowError = $this->rowError($productId, $qtyValue, $priceValue, $qty[1], $price);
            if ($rowError !== null) {
                $errors["items.{$index}.{$rowError[0]}"] = $rowError[1];
                continue;
            }

            $rows[] = ['product_id' => $productId, 'qty' => $qtyValue, 'price' => $priceValue];
        }

        if ($rows === [] && !isset($errors['items'])) {
            $errors['items'] = 'Minimal 1 item produk yang lengkap wajib diisi.';
        }

        return [$errors, $rows];
    }

    /**
     * @param array<string,string> $errors
     * @param callable(int):(object|null) $find
     */
    private function validateActiveRef(array &$errors, string $key, string $raw, string $requiredMsg, string $invalidMsg, callable $find): void
    {
        if ($raw === '' || !ctype_digit($raw)) {
            $errors[$key] = $requiredMsg;
            return;
        }

        $entity = $find((int) $raw);
        if ($entity === null || !$entity->isActive) {
            $errors[$key] = $invalidMsg;
        }
    }

    private function dateError(string $orderDate): ?string
    {
        if ($orderDate === '' || \DateTime::createFromFormat('Y-m-d', $orderDate) === false) {
            return 'Tanggal order wajib diisi dengan format yang valid.';
        }

        return null;
    }

    /**
     * First failing field of a row, in the order product, qty, price.
     *
     * @param array{0:string,1:string,2:string} $price [row field, error key, error message]
     * @return array{0:string,1:string}|null [error key, message]
     */
    private function rowError(string $productId, string $qtyValue, string $priceValue, string $qtyKey, array $price): ?array
    {
        $key = 'product_id';
        $message = $this->productError($productId);

        if ($message === null && ($qtyValue === '' || !ctype_digit($qtyValue) || (int) $qtyValue <= 0)) {
            $key = $qtyKey;
            $message = 'Qty harus bilangan bulat > 0.';
        }

        if ($message === null && ($priceValue === '' || !is_numeric($priceValue) || (float) $priceValue < 0)) {
            $key = $price[1];
            $message = $price[2];
        }

        return $message === null ? null : [$key, $message];
    }

    private function productError(string $productId): ?string
    {
        if ($productId === '' || !ctype_digit($productId)) {
            return 'Produk wajib dipilih.';
        }

        $product = $this->products->findById((int) $productId);
        if ($product === null || !$product->isActive) {
            return 'Produk tidak valid atau nonaktif.';
        }

        return null;
    }
}
