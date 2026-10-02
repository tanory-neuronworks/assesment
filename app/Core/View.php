<?php

declare(strict_types=1);

namespace App\Core;

use App\Entity\User;

final class View
{
    private static string $basePath = '';

    public static function init(string $basePath): void
    {
        self::$basePath = rtrim($basePath, '/');
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function render(string $template, array $data = [], bool $withLayout = true): string
    {
        $content = self::renderFile($template, $data);

        if (!$withLayout) {
            return $content;
        }

        $layoutData = array_merge($data, [
            'content' => $content,
            'title' => $data['title'] ?? 'Inventory & Order Management',
            'flashes' => Session::pullFlashes(),
            'user' => $data['user'] ?? null,
        ]);

        return self::renderFile('layout', $layoutData);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function renderFile(string $template, array $data = []): string
    {
        $path = self::$basePath . '/' . str_replace('.', '/', $template) . '.php';

        if (!is_file($path)) {
            throw new \App\Core\Exceptions\OperationFailedException("View not found: {$template}");
        }

        extract($data, EXTR_SKIP);
        ob_start();
        // Sengaja `require` (bukan require_once): template yang sama dirender berulang (mis. row.php per baris).
        require $path; // NOSONAR

        return (string) ob_get_clean();
    }

    public static function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }

    public static function isCurrentUser(?User $user, string $role): bool
    {
        return $user !== null && $user->role->value === $role;
    }

    /**
     * Abbreviated Rupiah for dense displays (Kanban/accordion board, stat
     * tiles) where "Rp 6.775.500" takes more room than the layout can
     * spare - "Rp 6.78 Jt" instead. Two decimals, trailing zeros trimmed
     * (2.50 -> 2.5, 2.00 -> 2).
     */
    public static function rupiahShort(float $amount): string
    {
        $sign = $amount < 0 ? '-' : '';
        $abs = abs($amount);

        [$value, $suffix] = match (true) {
            $abs >= 1_000_000_000_000 => [$abs / 1_000_000_000_000, ' T'],
            $abs >= 1_000_000_000 => [$abs / 1_000_000_000, ' M'],
            $abs >= 1_000_000 => [$abs / 1_000_000, ' Jt'],
            $abs >= 1_000 => [$abs / 1_000, ' Rb'],
            default => [$abs, ''],
        };

        $formatted = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return "{$sign}Rp {$formatted}{$suffix}";
    }

    private const MONTHS_ID = [
        '01' => 'Jan', '02' => 'Feb', '03' => 'Mar', '04' => 'Apr',
        '05' => 'Mei', '06' => 'Jun', '07' => 'Jul', '08' => 'Agu',
        '09' => 'Sep', '10' => 'Okt', '11' => 'Nov', '12' => 'Des',
    ];

    /**
     * "2026-08-15" -> "15 Agu 2026" - compact date for dense displays
     * (Kanban board cards) where the full ISO date reads as noise.
     */
    public static function dateShort(string $ymd): string
    {
        $parts = explode('-', $ymd);
        if (count($parts) !== 3) {
            return $ymd;
        }

        [$year, $month, $day] = $parts;

        return sprintf('%d %s %d', (int) $day, self::MONTHS_ID[$month] ?? $month, (int) $year);
    }
}
