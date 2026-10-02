<?php

declare(strict_types=1);

namespace App\Service;

final class ImageUploader
{
    private const ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const MAX_BYTES = 2 * 1024 * 1024;

    public function __construct(private readonly string $uploadDir, private readonly string $publicPrefix = '/uploads/')
    {
    }

    /**
     * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file
     * @return string filename stored (relative), to be persisted on the product
     */
    public function store(array $file): string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            // PHP itself rejects a file over upload_max_filesize/post_max_size
            // (php.ini) before our own MAX_BYTES check below ever runs, so
            // that case needs its own message here or the user just sees a
            // generic "gagal diproses" with no indication it was a size
            // problem.
            throw new \InvalidArgumentException(match ($file['error']) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Ukuran gambar maksimal 2MB.',
                UPLOAD_ERR_PARTIAL => 'Upload gambar terputus, silakan coba lagi.',
                UPLOAD_ERR_NO_FILE => 'Pilih gambar terlebih dahulu.',
                default => 'Upload gagal diproses.',
            });
        }

        if ($file['size'] > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Ukuran gambar maksimal 2MB.');
        }

        $mime = mime_content_type($file['tmp_name']);
        if ($mime === false || !isset(self::ALLOWED_MIME[$mime])) {
            throw new \InvalidArgumentException('Tipe gambar harus JPG, PNG, atau WEBP.');
        }

        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }

        $filename = bin2hex(random_bytes(16)) . '.' . self::ALLOWED_MIME[$mime];
        $destination = rtrim($this->uploadDir, '/') . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new \App\Core\Exceptions\OperationFailedException('Gagal menyimpan gambar.');
        }

        return $this->publicPrefix . $filename;
    }
}
