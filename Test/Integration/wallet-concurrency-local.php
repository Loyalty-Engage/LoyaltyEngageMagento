<?php
declare(strict_types=1);

use LoyaltyEngage\LoyaltyShop\Model\Wallet\Config;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\DatabaseLocks;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Ledger;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Money;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Policy;
use LoyaltyEngage\LoyaltyShop\Model\Wallet\Selection;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;

if (getenv('LOYALTY_WALLET_LOCAL_TEST') !== '1') { throw new RuntimeException('Local opt-in required.'); }
require dirname(__DIR__, 6) . '/app/bootstrap.php';
$om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
if (parse_url((string) $om->get(ScopeConfigInterface::class)->getValue('web/secure/base_url'), PHP_URL_HOST) !== 'magento.test') {
    throw new RuntimeException('Restricted to magento.test.');
}
$resource = $om->get(ResourceConnection::class);
$db = $resource->getConnection();
$money = new Money();
$ledger = new Ledger($resource, $money);
$scope = new class implements ScopeConfigInterface {
    public function getValue($path, $scopeType = 'default', $scopeCode = null) { return null; }
    public function isSetFlag($path, $scopeType = 'default', $scopeCode = null) { return true; }
};
$selection = new Selection($resource, $ledger, new Config($scope, new Policy($money)), $money);
$locks = new DatabaseLocks($resource);
$quoteModel = static function (int $id, int $customerId, int $storeId) use ($om) {
    return $om->create(\Magento\Quote\Model\Quote::class)->addData([
        'entity_id' => $id, 'customer_id' => $customerId, 'customer_is_guest' => 0, 'store_id' => $storeId,
        'is_active' => 1, 'base_currency_code' => 'EUR', 'quote_currency_code' => 'EUR',
        'reserved_order_id' => 'WALLET-RACE-' . $id,
    ]);
};
if (($argv[1] ?? '') === 'worker') {
    [$customerId, $quoteId, $storeId] = array_map('intval', array_slice($argv, 2, 3));
    $nonce = $argv[5] ?? '';
    if (!preg_match('/\A[a-f0-9]{16}\z/D', $nonce)) { throw new RuntimeException('Invalid fixture nonce.'); }
    if ($locks->lock('le-wallet-race-' . $nonce, 0)) {
        $locks->unlock('le-wallet-race-' . $nonce);
        throw new RuntimeException('The parent lock did not protect a separate DB connection.');
    }
    echo "LOCK_BLOCKED\nREADY\n";
    fflush(STDOUT);
    $barrier = BP . '/var/wallet-race-' . $nonce;
    $deadline = microtime(true) + 20;
    while (!file_exists($barrier)) {
        if (microtime(true) > $deadline) { throw new RuntimeException('Fixture barrier timeout.'); }
        usleep(10000);
    }
    $quote = $quoteModel($quoteId, $customerId, $storeId);
    try {
        $selection->reserve($quote, 6000, 10000);
        echo "RESERVED\n";
    } catch (\Magento\Framework\Exception\LocalizedException) {
        echo "REJECTED\n";
    }
    exit(0);
}
$nonce = bin2hex(random_bytes(8));
$barrier = BP . '/var/wallet-race-' . $nonce;
$customerId = 0;
$quotes = [];
$processes = [];
$email = 'wallet-race-' . $nonce . '@example.com';
$store = $om->get(\Magento\Store\Model\StoreManagerInterface::class)->getStore('default');
$storeId = (int) $store->getId();
try {
    $db->insert($resource->getTableName('customer_entity'), ['website_id' => (int) $store->getWebsiteId(),
        'store_id' => $storeId, 'email' => $email, 'firstname' => 'Wallet', 'lastname' => 'Concurrency Fixture']);
    $customerId = (int) $db->lastInsertId();
    $db->insert($ledger->table('balance'), ['customer_id' => $customerId, 'store_id' => $storeId,
        'balance_cents' => 10000, 'reserved_cents' => 0, 'funded_cents' => 10000]);
    foreach ([1, 2] as $i) {
        $db->insert($resource->getTableName('quote'), ['customer_id' => $customerId, 'store_id' => $storeId,
            'customer_is_guest' => 0, 'is_active' => 1, 'base_currency_code' => 'EUR', 'quote_currency_code' => 'EUR']);
        $id = (int) $db->lastInsertId();
        $quotes[] = $id;
        $selection->choose($quoteModel($id, $customerId, $storeId), 6000);
    }
    if (!$locks->lock('le-wallet-race-' . $nonce, 0)) { throw new RuntimeException('Unable to obtain parent test lock.'); }
    foreach ($quotes as $id) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', (string) $customerId, (string) $id, (string) $storeId, $nonce],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('Unable to start independent worker.'); }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $processes[] = ['process' => $process, 'out' => $pipes[1], 'err' => $pipes[2], 'text' => '', 'errors' => ''];
    }
    $deadline = microtime(true) + 30;
    do {
        $ready = 0;
        foreach ($processes as &$process) {
            $process['text'] .= stream_get_contents($process['out']);
            $process['errors'] .= stream_get_contents($process['err']);
            $ready += str_contains($process['text'], 'READY') ? 1 : 0;
        }
        unset($process);
        if (microtime(true) > $deadline) { throw new RuntimeException('Workers did not become ready.'); }
        usleep(10000);
    } while ($ready !== 2);
    file_put_contents($barrier, 'go');
    do {
        $running = false;
        foreach ($processes as &$process) {
            $process['text'] .= stream_get_contents($process['out']);
            $process['errors'] .= stream_get_contents($process['err']);
            $running = $running || proc_get_status($process['process'])['running'];
        }
        unset($process);
        if (microtime(true) > $deadline) { throw new RuntimeException('Concurrent reservation timeout.'); }
        usleep(10000);
    } while ($running);
    $output = implode("\n", array_column($processes, 'text'));
    $errors = implode("\n", array_column($processes, 'errors'));
    $funded = $db->fetchRow($db->select()->from($ledger->table('balance'), ['funded_cents', 'reserved_funded'])
        ->where('customer_id = ?', $customerId)->where('store_id = ?', $storeId));
    if (substr_count($output, 'RESERVED') !== 1 || substr_count($output, 'REJECTED') !== 1
        || substr_count($output, 'LOCK_BLOCKED') !== 2 || trim($errors) !== ''
        || $ledger->available($customerId, $storeId) !== 4000 || $ledger->balance($customerId, $storeId) !== 10000
        || (int) $funded['funded_cents'] !== 10000 || (int) $funded['reserved_funded'] !== 6000) {
        throw new RuntimeException('Concurrent reservation invariant failed: ' . $output . $errors);
    }
    echo "Two independent PHP/DB connections: one reservation, one rejection; EUR 40 available, no debit; cross-process locks verified.\n";
} finally {
    foreach ($processes as $process) {
        if (proc_get_status($process['process'])['running']) { proc_terminate($process['process']); }
        fclose($process['out']); fclose($process['err']); proc_close($process['process']);
    }
    $locks->unlock('le-wallet-race-' . $nonce);
    if (file_exists($barrier)) { unlink($barrier); }
    if ($quotes) {
        $db->delete($ledger->table('quote'), ['quote_id IN (?)' => $quotes]);
        $db->delete($resource->getTableName('quote'), ['entity_id IN (?)' => $quotes, 'customer_id = ?' => $customerId]);
    }
    if ($customerId) {
        $db->delete($resource->getTableName('customer_entity'), ['entity_id = ?' => $customerId, 'email = ?' => $email]);
    }
}
echo "All concurrency fixtures removed.\n";
