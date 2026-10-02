<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Exceptions\OperationFailedException;
use App\Service\ImageUploader;
use PHPUnit\Framework\TestCase;

final class ImageUploaderTest extends TestCase
{
    /** 1x1 transparent PNG. */
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private string $workDir;

    /** @var string[] */
    private array $cleanup = [];

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/uploader_test_' . bin2hex(random_bytes(6));
        mkdir($this->workDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanup) as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
        foreach (glob($this->workDir . '/*') ?: [] as $f) {
            is_dir($f) ? @rmdir($f) : @unlink($f);
        }
        @rmdir($this->workDir);
    }

    private function tmpFile(string $contents): string
    {
        $path = $this->workDir . '/' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($path, $contents);
        $this->cleanup[] = $path;

        return $path;
    }

    /** @return array{name:string,type:string,tmp_name:string,error:int,size:int} */
    private function file(string $tmp, int $error = UPLOAD_ERR_OK, ?int $size = null): array
    {
        return [
            'name' => 'foto.png',
            'type' => 'image/png',
            'tmp_name' => $tmp,
            'error' => $error,
            'size' => $size ?? (is_file($tmp) ? (int) filesize($tmp) : 0),
        ];
    }

    private function assertRejected(array $file, string $message): void
    {
        $uploader = new ImageUploader($this->workDir . '/uploads');

        try {
            $uploader->store($file);
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame($message, $e->getMessage());
        }
        $this->assertDirectoryDoesNotExist($this->workDir . '/uploads', 'rejected upload must not create the target dir');
    }

    /**
     * @dataProvider uploadErrors
     */
    public function test_php_upload_errors_map_to_specific_messages(int $code, string $message): void
    {
        $this->assertRejected($this->file('/nonexistent', $code, 0), $message);
    }

    public static function uploadErrors(): array
    {
        return [
            'ini size' => [UPLOAD_ERR_INI_SIZE, 'Ukuran gambar maksimal 2MB.'],
            'form size' => [UPLOAD_ERR_FORM_SIZE, 'Ukuran gambar maksimal 2MB.'],
            'partial' => [UPLOAD_ERR_PARTIAL, 'Upload gambar terputus, silakan coba lagi.'],
            'no file' => [UPLOAD_ERR_NO_FILE, 'Pilih gambar terlebih dahulu.'],
            'no tmp dir' => [UPLOAD_ERR_NO_TMP_DIR, 'Upload gagal diproses.'],
            'cant write' => [UPLOAD_ERR_CANT_WRITE, 'Upload gagal diproses.'],
        ];
    }

    public function test_file_over_two_megabytes_is_rejected(): void
    {
        $tmp = $this->tmpFile(base64_decode(self::PNG_BASE64));

        $this->assertRejected($this->file($tmp, UPLOAD_ERR_OK, 2 * 1024 * 1024 + 1), 'Ukuran gambar maksimal 2MB.');
    }

    public function test_non_image_mime_is_rejected(): void
    {
        $tmp = $this->tmpFile('just some plain text, not an image');

        $this->assertRejected($this->file($tmp), 'Tipe gambar harus JPG, PNG, atau WEBP.');
    }

    public function test_unreadable_tmp_file_is_rejected_as_invalid_type(): void
    {
        set_error_handler(static fn () => true); // mime_content_type warns on a missing file
        try {
            $this->assertRejected($this->file($this->workDir . '/missing.tmp', UPLOAD_ERR_OK, 10), 'Tipe gambar harus JPG, PNG, atau WEBP.');
        } finally {
            restore_error_handler();
        }
    }

    public function test_valid_png_not_from_http_upload_fails_to_save(): void
    {
        // move_uploaded_file() only accepts files PHP received via HTTP POST,
        // so a hand-made temp file passes validation but cannot be moved.
        $tmp = $this->tmpFile((string) base64_decode(self::PNG_BASE64));
        $uploadDir = $this->workDir . '/uploads';
        $uploader = new ImageUploader($uploadDir, '/uploads/');

        try {
            $uploader->store($this->file($tmp));
            $this->fail('Expected OperationFailedException');
        } catch (OperationFailedException $e) {
            $this->assertSame('Gagal menyimpan gambar.', $e->getMessage());
        }

        $this->assertDirectoryExists($uploadDir, 'target dir is created before the move is attempted');
        $this->assertSame([], glob($uploadDir . '/*'), 'no file may be left behind after a failed move');
        $this->cleanup[] = $uploadDir;
        $this->assertFileExists($tmp, 'the source must be untouched');
    }
}
