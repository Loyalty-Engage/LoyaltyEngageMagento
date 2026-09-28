<?php
declare(strict_types=1);

namespace LoyaltyEngage\LoyaltyShop\Controller;

use LoyaltyEngage\LoyaltyShop\Api\LoyaltyCartInterface;
use LoyaltyEngage\LoyaltyShop\Helper\Data;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey;

abstract class CustomerAction implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private RequestInterface $request, private JsonFactory $jsonFactory,
        private Session $session, protected LoyaltyCartInterface $cart,
        private Data $helper, private FormKey $formKey
    ) {
    }

    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();
        if (!$this->session->isLoggedIn()) {
            return $result->setHttpResponseCode(401)->setData(['success' => false, 'message' => 'Please log in first.']);
        }
        if (!$this->validateForCsrf($this->request)) {
            return $result->setHttpResponseCode(400)->setData(['success' => false, 'message' => 'Invalid form key. Refresh the page and retry.']);
        }
        $data = $this->data($this->request);
        if (!isset($data['sku']) || !is_string($data['sku']) || trim($data['sku']) === '') {
            return $result->setHttpResponseCode(400)->setData(['success' => false, 'message' => 'SKU is required.']);
        }
        try {
            $response = $this->perform((int) $this->session->getCustomerId(), $data['sku']);
            $status = $response->getSuccess() ? 200 : 400;
            if (!$response->getSuccess() && preg_match('/_(4\d\d|5\d\d)$/', (string) $response->getErrorType(), $matches)) {
                $status = (int) $matches[1];
            }
            return $result->setHttpResponseCode($status)->setData([
                'success' => $response->getSuccess(), 'message' => $response->getMessage(),
                'error_type' => $response->getErrorType(), 'bar_color' => $response->getBarColor(),
                'text_color' => $response->getTextColor(),
            ]);
        } catch (\Throwable $e) {
            $this->helper->log('error', 'Frontend', 'RequestFailed', 'Loyalty request failed.', ['error' => $e->getMessage()]);
            return $result->setHttpResponseCode(500)->setData(['success' => false, 'message' => 'Unable to complete this request.']);
        }
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        $key = $this->data($request)['form_key'] ?? $request->getParam('form_key');
        if (is_string($key) && hash_equals($this->formKey->getFormKey(), $key)) {
            return true;
        }
        // Preserve Magento's AJAX contract used by existing embedded storefront scripts.
        if (method_exists($request, 'isXmlHttpRequest') && $request->isXmlHttpRequest()) {
            $origin = $request->getHeader('Origin');
            $site = $request->getHeader('Sec-Fetch-Site');
            if ($site && !in_array($site, ['same-origin', 'none'], true)) {
                return false;
            }
            return !$origin || rtrim($origin, '/') === $request->getScheme() . '://' . $request->getHttpHost();
        }
        return false;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return new InvalidRequestException($this->jsonFactory->create()->setHttpResponseCode(400)->setData([
            'success' => false, 'message' => 'Invalid form key. Refresh the page and retry.',
        ]));
    }

    private function data(RequestInterface $request): array
    {
        $decoded = json_decode((string) $request->getContent(), true);
        return is_array($decoded) ? $decoded : $request->getParams();
    }

    abstract protected function perform(int $customerId, string $sku): \LoyaltyEngage\LoyaltyShop\Api\Data\LoyaltyCartResponseInterface;
}
