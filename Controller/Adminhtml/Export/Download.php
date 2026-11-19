<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Controller\Adminhtml\Export;

use LoyaltyEngage\LoyaltyShop\Model\Enrollment\{CsvResponseFactory, ExportAccess, ExportCsv};
use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class Download extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = ExportAccess::RESOURCE;

    public function __construct(
        Action\Context $context,
        private ExportAccess $access,
        private Validator $formKey,
        private StoreManagerInterface $stores,
        private ExportCsv $export,
        private CsvResponseFactory $responses,
        private LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    protected function _isAllowed()
    {
        return $this->access->isAllowed();
    }

    public function execute()
    {
        $stream = null;
        try {
            if (!$this->formKey->validate($this->getRequest())) {
                throw new LocalizedException(__('Invalid form key. Refresh the page and try again.'));
            }
            $selected = $this->getRequest()->getParam('store_id');
            $id = is_string($selected) && ctype_digit($selected) ? (int) $selected : 0;
            $store = $id > 0 ? ($this->stores->getStores()[$id] ?? null) : null;
            if (!$store) {
                throw new LocalizedException(__('Choose a store view.'));
            }
            $stream = tmpfile();
            if ($stream === false) {
                throw new \RuntimeException('Unable to open temporary CSV stream.');
            }
            $this->export->write($stream, $store);
            $response = $this->responses->create()->setDownload($stream, $id);
            $stream = null; // The response closes the private temporary file after sending it.
            return $response;
        } catch (LocalizedException $exception) {
            $this->messageManager->addErrorMessage($exception->getMessage());
        } catch (\Throwable $exception) {
            $this->logger->error('Unable to generate the loyalty CSV export.', ['exception' => $exception]);
            $this->messageManager->addErrorMessage(__('The loyalty CSV could not be generated.'));
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        return $this->resultRedirectFactory->create()->setPath('*/*/index');
    }
}
