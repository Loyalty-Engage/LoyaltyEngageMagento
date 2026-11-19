<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Model\Enrollment;

use Magento\Framework\App\Response\Http;

class CsvResponse extends Http
{
    /** @var resource|null */
    private $stream;

    /** @param resource $stream */
    public function setDownload($stream, int $storeId): self
    {
        $stat = fstat($stream);
        if ($stat === false || !rewind($stream)) {
            throw new \RuntimeException('Unable to prepare CSV download.');
        }
        $filename = 'loyalty-enrollment-store-' . $storeId . '-' . gmdate('Ymd-His') . '.csv';
        $this->setHeader('Content-Type', 'text/csv; charset=UTF-8', true)
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"', true)
            ->setHeader('Content-Length', (string) $stat['size'], true)
            ->setHeader('Cache-Control', 'private, no-store, max-age=0', true)
            ->setHeader('Pragma', 'no-cache', true)
            ->setHeader('X-Content-Type-Options', 'nosniff', true);
        $this->stream = $stream;
        return $this;
    }

    public function sendResponse()
    {
        if (!is_resource($this->stream)) {
            parent::sendResponse();
            return;
        }
        try {
            $this->sendHeaders();
            fpassthru($this->stream);
        } finally {
            fclose($this->stream);
            $this->stream = null;
        }
    }
}
