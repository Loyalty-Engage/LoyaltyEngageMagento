<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Enrollment;

use Magento\Store\Api\Data\StoreInterface;

class ExportCsv
{
    public const COLUMNS = [
        'store_id', 'store_code', 'customer_id', 'email', 'opt_in_status', 'enrollment_source',
        'wallet_available_eur', 'wallet_reserved_eur',
    ];

    public function __construct(private ExportRows $rows) {}

    /** @param resource $stream */
    public function write($stream, StoreInterface $store): void
    {
        if (fwrite($stream, "\xEF\xBB\xBF") !== 3) {
            throw new \RuntimeException('Unable to write CSV header.');
        }
        $this->writeRow($stream, self::COLUMNS);
        foreach ($this->rows->get($store) as $row) {
            $this->writeRow($stream, array_map([self::class, 'safeCell'], $row));
        }
    }

    public static function safeCell(int|string $value): int|string
    {
        // CSV quoting does not stop spreadsheet applications from evaluating a formula.
        return is_string($value) && preg_match('/\A(?:[\t\r\n]|[[:space:]]*[=+@-])/', $value)
            ? "'" . $value : $value;
    }

    /** @param resource $stream */
    private function writeRow($stream, array $row): void
    {
        if (fputcsv($stream, $row, ',', '"', '', "\r\n") === false) {
            throw new \RuntimeException('Unable to write CSV row.');
        }
    }
}
