<?php
namespace FreePay\Gateway\Model\Adapter;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Locale\ResolverInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Psr\Log\LoggerInterface;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Module\ResourceInterface;
use FreePay\FreePay;
use Zend_Locale;
use Magento\Sales\Model\ResourceModel\Order\Tax\Item;

/**
 * Class FreePayAdapter
 */
class FreePayAdapter
{
    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var \Magento\Framework\UrlInterface
     */
    protected $url;

    /**
     * @var ResolverInterface
     */
    protected $resolver;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var OrderRepositoryInterface
     */
    protected $orderRepository;

    /**
     * @var \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface
     */
    protected $transactionBuilder;

    /**
     * @var TransactionRepositoryInterface
     */
    protected $transactionRepository;

    /**
     * @var \Magento\Framework\App\Filesystem\DirectoryList
     */
    protected $dir;

    /**
     * @var \Magento\Framework\Module\ResourceInterface
     */
    protected $moduleResource;

    /**
     * @var Item
     */
    protected $taxItem;

    protected $client;

    /**
     * FreePayAdapter constructor.
     *
     * @param LoggerInterface $logger
     * @param UrlInterface $url
     * @param ScopeConfigInterface $scopeConfig
     * @param ResolverInterface $resolver
     */
    public function __construct(
        LoggerInterface $logger,
        UrlInterface $url,
        ScopeConfigInterface $scopeConfig,
        ResolverInterface $resolver,
        OrderRepositoryInterface $orderRepository,
        BuilderInterface $transactionBuilder,
        TransactionRepositoryInterface $transactionRepository,
        ResourceInterface $moduleResource,
        DirectoryList $dir,
        Item $taxItem,
        \FreePay\Gateway\Helper\FreePayCom $comHelper
    )
    {
        $this->logger = $logger;
        $this->url = $url;
        $this->scopeConfig = $scopeConfig;
        $this->resolver = $resolver;
        $this->orderRepository = $orderRepository;
        $this->transactionBuilder = $transactionBuilder;
        $this->transactionRepository = $transactionRepository;
        $this->moduleResource = $moduleResource;
        $this->dir = $dir;
        $this->taxItem = $taxItem;
        $this->client = $comHelper;

        $this->logger->pushHandler(new \Monolog\Handler\StreamHandler($this->dir->getRoot().'/var/log/freepay.log'));
    }

    /**
     * create payment link
     *
     * @param array $attributes
     * @return array|bool
     */
    public function CreatePaymentLink($order, $area = 'frontend')
    {
        try {
            $response = [];
            $this->logger->debug('CREATE PAYMENT!');

            $order_id = $order->getIncrementId();

            $billingAddress = $order->getBillingAddress();
            $shippingAddress = $order->getShippingAddress();

            $form = array(
                'OrderNumber'		=> $order_id,
                'CustomerAcceptUrl'	=> $this->url->getUrl('freepaygateway/payment/returns') . '?order_id=' . $order_id,
                'CustomerDeclineUrl'=> $this->url->getUrl('freepaygateway/payment/cancel') . '?order_id=' . $order_id,
                'Amount'			=> round($order->getTotalDue(), 2) * 100,
                'EnforceLanguage'   => $this->getLanguage(),
                'Currency'          => $order->getOrderCurrency()->ToString(),
                'ServerCallbackUrl'	=> $this->url->getUrl('freepaygateway/payment/callback') . '?order_id=' . $order_id,
                'SaveCard'          => false,
                'BillingAddress'  	=> array(
                    'AddressLine1'		=> $billingAddress->getStreetLine(1),
                    'AddressLine2'		=> $billingAddress->getStreetLine(2),
                    'City'				=> $billingAddress->getCity(),
                    'PostCode'			=> $billingAddress->getPostcode(),
                    'Country'			=> \FreePay\Gateway\Helper\FreePayCom::convertCountryAlphas2ToNumber($billingAddress->getCountryId()),
                ),
                'ShippingAddress' 	=> array(
                    'AddressLine1'		=> $shippingAddress->getStreetLine(1),
                    'AddressLine2'		=> $shippingAddress->getStreetLine(2),
                    'City'				=> $shippingAddress->getCity(),
                    'PostCode'			=> $shippingAddress->getPostcode(),
                    'Country'			=> \FreePay\Gateway\Helper\FreePayCom::convertCountryAlphas2ToNumber($shippingAddress->getCountryId()),
                ),
            );

            $linkResult = json_decode($this->client->link($form));
            $paymentLinkUrl = $linkResult->paymentWindowLink;

            $response['url'] = $paymentLinkUrl;

            return $response;
        } catch (\Exception $e) {

            $this->logger->critical($e->getMessage());
        }

        return true;
    }

    /**
     * Capture payment
     *
     * @param array $attributes
     * @return array|bool
     */
    public function capture($order, $transaction, $amount)
    {
        $this->logger->debug("Capture payment");

        $form = [
            'Amount' => round($amount, 2) * 100,
        ];

        $this->client->post($order->getPayment()->getLastTransId()."/capture", json_encode($form));

        return $this;
    }

    /**
     * Cancel payment
     *
     * @param array $attributes
     * @return array|bool
     */
    public function cancel($order, $transaction)
    {
        $this->client->delete($order->getPayment()->getLastTransId());
        
        return $this;
    }

    /**
     * Refund payment
     *
     * @param array $attributes
     * @return array|bool
     */
    public function refund($order, $transaction, $amount)
    {
        $this->logger->debug("Refund payment");

        $form = [
            'Amount' => (int)(round($amount, 2) * 100),
        ];

        $this->client->post(str_replace(['-capture', '-refund'], ['', ''], $order->getPayment()->getParentTransactionId())."/credit", json_encode($form));

        return $this;
    }

    /**
     * Get language code from locale
     *
     * @return mixed
     */
    private function getLanguage()
    {
        $locale = $this->resolver->getLocale();

        //Map both norwegian locales to no
        $map = [
            'nb' => 'no',
            'nn' => 'no',
        ];

        $language = explode('_', $locale)[0];

        if (isset($map[$language])) {
            return $map[$language];
        }

        return $language;
    }

    /**
     * @param null $order
     * @param $transactionId
     * @param $type
     */
    public function createTransaction($order = null, $transactionId, $type)
    {
        try {
            //get payment object from order object
            $payment = $order->getPayment();

            $formatedPrice = $order->getBaseCurrency()->formatTxt(
                $order->getGrandTotal()
            );

            $message = '';
            if($type == \Magento\Sales\Model\Order\Payment\Transaction::TYPE_AUTH){
                $message = __('The authorized amount is %1.', $formatedPrice);
            } elseif($type == \Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE) {
                $message = __('The captured amount is %1.', $formatedPrice);
            }

            if($payment->getLastTransId()){
                $parent_id = $payment->getLastTransId();
            } else {
                $parent_id = null;
            }

            $payment->setLastTransId($transactionId);
            $payment->setTransactionId($transactionId);
            /*$payment->setAdditionalInformation(
                [\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS => (array) $paymentData]
            );*/

            //get the object of builder class
            $trans = $this->transactionBuilder;
            $transaction = $trans->setPayment($payment)
                ->setOrder($order)
                ->setTransactionId($transactionId)
                ->setAdditionalInformation(
                    [\Magento\Sales\Model\Order\Payment\Transaction::RAW_DETAILS => (array)$payment->getAdditionalInformation()]
                )
                ->setFailSafe(true)
                //build method creates the transaction and returns the object
                ->build($type);
            $payment->addTransactionCommentsToOrder(
                $transaction,
                $message
            );
            $payment->setParentTransactionId($parent_id);

            // update totals
            $amount = $order->getGrandTotal();
            $amount = $payment->formatAmount($amount, true);
            $payment->setBaseAmountAuthorized($amount);

            $payment->save();
            $order->save();

        } catch (Exception $e) {
            //log errors here
        }
    }
}
