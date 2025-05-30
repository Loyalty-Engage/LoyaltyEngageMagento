<?php
namespace LoyaltyEngage\LoyaltyShop\Controller\Button;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\View\Result\PageFactory;
use Magento\Framework\View\LayoutFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\Action;

class Render extends Action implements HttpPostActionInterface
{
    /**
     * @var RawFactory
     */
    private $resultRawFactory;

    /**
     * @var PageFactory
     */
    private $resultPageFactory;

    /**
     * @var LayoutFactory
     */
    private $layoutFactory;

    /**
     * @var Json
     */
    private $json;

    /**
     * @var CustomerSession
     */
    private $customerSession;

    /**
     * @param Context $context
     * @param RawFactory $resultRawFactory
     * @param PageFactory $resultPageFactory
     * @param LayoutFactory $layoutFactory
     * @param Json $json
     * @param CustomerSession $customerSession
     */
    public function __construct(
        Context $context,
        RawFactory $resultRawFactory,
        PageFactory $resultPageFactory,
        LayoutFactory $layoutFactory,
        Json $json,
        CustomerSession $customerSession
    ) {
        $this->resultRawFactory = $resultRawFactory;
        $this->resultPageFactory = $resultPageFactory;
        $this->layoutFactory = $layoutFactory;
        $this->json = $json;
        $this->customerSession = $customerSession;
        parent::__construct($context);
    }

    /**
     * Execute action based on request and return result
     *
     * @return \Magento\Framework\Controller\ResultInterface
     */
    public function execute()
    {
        $resultRaw = $this->resultRawFactory->create();
        $resultRaw->setHeader('Content-Type', 'text/html');

        try {
            // Get the request content
            $content = $this->getRequest()->getContent();
            
            // Default values
            $sku = '';
            $type = '';
            $price = '';
            
            // Try to parse JSON data if available
            if ($content) {
                try {
                    $data = $this->json->unserialize($content);
                    $sku = $data['sku'] ?? '';
                    $type = $data['type'] ?? '';
                    $price = $data['price'] ?? '';
                } catch (\Exception $e) {
                    // Fallback to request parameters if JSON parsing fails
                }
            }
            
            // Fallback to request parameters
            if (empty($sku)) {
                $sku = $this->getRequest()->getParam('sku', '');
            }
            if (empty($type)) {
                $type = $this->getRequest()->getParam('type', '');
            }
            if (empty($price)) {
                $price = $this->getRequest()->getParam('price', '');
            }

            // Create layout and block
            $layout = $this->layoutFactory->create();
            $block = $layout->createBlock(
                \Magento\Framework\View\Element\Template::class,
                'loyalty.product.button'
            )->setTemplate('LoyaltyEngage_LoyaltyShop::hyva/loyalty-product-button.phtml');

            // Set data for the block
            $block->setData('product_sku', $sku);
            $block->setData('type', $type);
            $block->setData('price', $price);

            // Render the block
            $html = $block->toHtml();
            $resultRaw->setContents($html);
        } catch (\Exception $e) {
            $resultRaw->setContents('<div class="error">Error rendering button: ' . $e->getMessage() . '</div>');
        }

        return $resultRaw;
    }
}
