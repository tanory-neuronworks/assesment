<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Exceptions\OperationFailedException;
use App\Core\View;
use PHPUnit\Framework\TestCase;

final class ViewRenderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/view_test_' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/nested', 0777, true);
        file_put_contents($this->dir . '/hello.php', 'Halo <?= $name ?>!');
        file_put_contents($this->dir . '/nested/row.php', '[<?= $n ?>]');
        file_put_contents($this->dir . '/pair.php', '<?= $a ?>-<?= $b ?>');
        View::init($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (['/hello.php', '/nested/row.php', '/pair.php'] as $f) {
            @unlink($this->dir . $f);
        }
        @rmdir($this->dir . '/nested');
        @rmdir($this->dir);
    }

    public function test_render_file_outputs_template_with_extracted_data(): void
    {
        $this->assertSame('Halo Dunia!', View::renderFile('hello', ['name' => 'Dunia']));
    }

    public function test_dotted_template_name_maps_to_subdirectory(): void
    {
        $this->assertSame('[7]', View::renderFile('nested.row', ['n' => 7]));
    }

    public function test_same_template_can_be_rendered_repeatedly(): void
    {
        $this->assertSame('[1]', View::renderFile('nested.row', ['n' => 1]));
        $this->assertSame('[2]', View::renderFile('nested.row', ['n' => 2]));
    }

    public function test_render_without_layout_returns_bare_content(): void
    {
        $this->assertSame('1-2', View::render('pair', ['a' => 1, 'b' => 2], false));
    }

    public function test_missing_template_throws_operation_failed_with_template_name(): void
    {
        $this->expectException(OperationFailedException::class);
        $this->expectExceptionMessage('View not found: does.not.exist');

        View::renderFile('does.not.exist');
    }

    public function test_missing_template_via_render_also_throws(): void
    {
        $this->expectException(OperationFailedException::class);
        $this->expectExceptionMessage('View not found: ghost');

        View::render('ghost', [], false);
    }

    public function test_init_trims_trailing_slash_from_base_path(): void
    {
        View::init($this->dir . '/');

        $this->assertSame('Halo X!', View::renderFile('hello', ['name' => 'X']));
    }

    public function test_extract_does_not_overwrite_existing_local_variables(): void
    {
        // EXTR_SKIP: a data key named "path" must not clobber renderFile's own $path.
        $this->assertSame('Halo Y!', View::renderFile('hello', ['name' => 'Y', 'path' => '/etc/passwd']));
    }
}
