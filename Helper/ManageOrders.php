<?php

namespace Knawat\Dropshipping\Helper;

use Knawat\MP;

use Zend\Log\Writer\Stream;
use Zend\Log\Logger;

/**
 * Class manageOrders
 * @package Knawat\Dropshipping\Helper
 */
class ManageOrders extends \Magento\Framework\App\Helper\AbstractHelper
{
    /**
     * @var \Magento\Sales\Model\OrderFactory
     */
    protected $orderFactory;

    /**
     * @var \Magento\Catalog\Model\ProductFactory
     */
    protected $productFactory;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var
     */
    protected $mpApi;

    /**
     * @var
     */
    protected $mp_api;

    /**
     * @var \Magento\Framework\Message\ManagerInterface
     */
    protected $messageManager;

    public $knawat_order_errors;

    protected $mpFactory;

    /**
     *constant for knawat configuration
     * used to retrive knawat configuration data
     */
    const PATH_KNAWAT_DEFAULT = 'knawat/store/';
    /**
     * Data constructor.
     * @param \Magento\Framework\App\Helper\Context $context
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Customer\Model\Session $customerSession
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Catalog\Model\CategoryFactory $catalogCategoryFactory
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     */
    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\Message\ManagerInterface $messageManager,
        \Knawat\MPFactory $mpFactory
    ) {
        parent::__construct($context);
        $this->orderFactory = $orderFactory;
        $this->productFactory = $productFactory;
        $this->scopeConfig = $scopeConfig;
        $this->messageManager = $messageManager;
        $this->mpFactory = $mpFactory;
    }

    /**
     * @param $orderId
     */
    public function setIsKnawat($orderId)
    {
        $order = $this->getOrderObject($orderId);
        $items =$order->getAllItems();
        foreach ($items as $item) {
            $isKnawat = $this->isProductKnawat($item);
            if ($isKnawat == 1) {
                $logger = $this->getOrderLogger();
                $logger->info($isKnawat);
                $order->setIsKnawat(1);
                $order->save();
                break;
            }
        }
    }

    /**
     * @param $orderId
     * @return mixed
     */
    public function getIsKnawat($orderId)
    {
        $order = $this->getOrderObject($orderId);
        return $isKnawat = $order->getIsKnawat();
    }

    /**
     * @param $orderId
     */
    public function knawatOrderCreatedUpdated($orderId)
    {
        $logger = $this->getOrderLogger();
        $order = $this->getOrderObject($orderId);
        $korder_id = $this->getKnawatOrderId($orderId);
        $order_status = $this->getOrderStatus($orderId);
        if ($korder_id && ($korder_id != null)) {
            try {
                $whilelisted_status = [ 'pending', 'processing', 'canceled' ];
                if (!in_array($order_status, $whilelisted_status)) {
                    // Return as order status is not allowed to push order
                    $order->setKnawatSyncFailed(1);
                    $order->save();
                    return;
                }
                $update_order_json = $this->formatKnawatOrder($orderId);
                if ($update_order_json) {
                    $this->mp_api = $this->createMP();
                    $result = $this->mp_api->put('orders/' . $korder_id, $update_order_json);
                    if (isset($result->status) && 'success' === $result->status) {
                        $korder_id = $result->data->id;
                        $order->setKnawatSyncFailed(0);
                        $order->save();
                    } else {
                        $order_sync_error = 'Knawat-Dropshipping Order synchronize fail for order '.$orderId;
                        if (isset($result->message)) {
                            $order_sync_error .= ' REASON: ';
                            $order_sync_error .= isset($result->name) ? $result->name . ':' . $result->message : $result->message;
                            $order_sync_error .= isset($result->code) ? '('.$result->code . ')' : '';
                        }
                        $knawat_order_errors['order_sync'] = $order_sync_error;
                        $logger->info($knawat_order_errors);
                        $order->setKnawatSyncFailed(1);
                        $order->save();
                    }
                }
            } catch (\Exception $e) {
                $this->messageManager->addExceptionMessage($e, __('Something went wrong ') . ' ' . $e->getMessage());
            }
        } else {
            return $this->createKnawatOrder($orderId);
        }
    }

    /**
     * @param $orderId
     */
    public function createKnawatOrder($orderId)
    {
        $logger = $this->getOrderLogger();
        $order = $this->getOrderObject($orderId);
        if (! empty($order)) {
            try {
                $push_status = 'processing';
                $order_status = $this->getOrderStatus($orderId);
                if ($push_status != $order_status) {
                    $order->setKnawatSyncFailed(1);
                    $order->save();
                    return;
                }
                $new_order_json = $this->formatKnawatOrder($orderId);
                if ($new_order_json) {
                    $this->mp_api = $this->createMP();
                    $result = $this->mp_api->post('orders', $new_order_json);
                    if (isset($result->status) && 'success' === $result->status) {
                        $korder_id = $result->data->id;
                        $order->setKnawatOrderId($korder_id);
                        $order->setKnawatSyncFailed(0);
                        $order->save();
                    } else {
                        $order_sync_error = 'Knawat-Dropshipping Order synchronize fail for order '.$orderId;
                        if (isset($result->message)) {
                            $order_sync_error .= ' REASON: ';
                            $order_sync_error .= isset($result->name) ? $result->name . ':' . $result->message : $result->message;
                            $order_sync_error .= isset($result->code) ? '('.$result->code . ')' : '';
                        }
                        $knawat_order_errors['order_sync'] = $order_sync_error;
                        $logger->info($knawat_order_errors);
                        $order->setKnawatSyncFailed(1);
                        $order->save();
                    }
                }
            } catch (\Exception $e) {
                $this->messageManager->addExceptionMessage($e, __('Something went wrong ') . ' ' . $e->getMessage());
            }
        }
    }

    /**
     * @return MP
     */
    public function createMP()
    {
        $consumer_key = $this->getConfigData('consumer_key');
        $consumer_secret = $this->getConfigData('consumer_secret');
        if ($this->mpApi == null) {
            $mp = $this->mpFactory->create([
                'consumer_key' => $consumer_key,
                'consumer_secret' => $consumer_secret,
            ]);

            return $this->mpApi = $mp;
        } else {
            return $this->mpApi;
        }
    }

    /**
     * @param $orderId
     * @return mixed
     */
    public function getKnawatOrderId($orderId)
    {
        $order = $this->getOrderObject($orderId);
        return $KnawatOrderId  = $order->getknawatOrderId();
    }

    /**
     * @param $orderId
     * @return mixed
     */
    public function getOrderStatus($orderId)
    {
        $order = $this->getOrderObject($orderId);
        return $order->getStatus();
    }

    /**
     * @param $orderId
     * @return array
     */
    public function formatKnawatOrder($orderId)
    {

        if (isset($orderId)) {
            $order= $this->getOrderObject($orderId);
            $newOrder = [];
            $newOrder['id'] = $order->getId();
            if ($order->getStatus() == 'canceled') {
                $orderStatus = 'cancelled';
                $newOrder['status'] = $orderStatus;
            } else {
                $newOrder['status'] = $order->getStatus();
            }
            $newOrder['items'] = [];
            foreach ($order->getAllItems() as $item) {
                $isKnawat = $this->isProductKnawat($item);
                if ($isKnawat == 1) {
                    $items['quantity'] = $item->getQtyOrdered();
                    $items['sku'] = $item->getSku();
                }
            }
            $newOrder['items'][] = (object)$items;
            /*get billing address*/
            $newOrder['billing']['first_name'] = $order->getBillingAddress()->getFirstname();
            $newOrder['billing']['last_name'] = $order->getBillingAddress()->getLastname();
            $newOrder['billing']['company'] = $order->getBillingAddress()->getCompany();

            $billingAddress = $order->getBillingAddress()->getStreet();
            if (array_key_exists(0, $billingAddress)) {
                $newOrder['billing']['address_1'] = $billingAddress[0];
            } else {
                $newOrder['billing']['address_1'] = '';
            }
            if (array_key_exists(1, $billingAddress)) {
                $newOrder['billing']['address_2'] = $billingAddress[1];
            } else {
                $newOrder['billing']['address_2'] = '';
            }
            $newOrder['billing']['city'] = $order->getBillingAddress()->getCity();
            $newOrder['billing']['state'] = $order->getBillingAddress()->getRegion();
            $newOrder['billing']['postcode'] = $order->getBillingAddress()->getPostcode();
            $newOrder['billing']['country'] = $order->getBillingAddress()->getCountryId();
            $newOrder['billing']['email'] = $order->getBillingAddress()->getEmail();
            $newOrder['billing']['phone'] = $order->getBillingAddress()->getTelephone();
            /*get shipping address*/
            $newOrder['shipping']['first_name'] = $order->getBillingAddress()->getFirstname();
            $newOrder['shipping']['last_name'] = $order->getShippingAddress()->getLastname();
            $newOrder['shipping']['company'] = $order->getShippingAddress()->getCompany();
            $address = $order->getShippingAddress()->getStreet();
            if (array_key_exists(0, $address)) {
                $newOrder['shipping']['address_1'] = $address[0];
            } else {
                $newOrder['shipping']['address_1'] = '';
            }
            if (array_key_exists(1, $address)) {
                $newOrder['shipping']['address_2'] = $address[1];
            } else {
                $newOrder['shipping']['address_2'] = '';
            }

            $newOrder['shipping']['city'] = $order->getShippingAddress()->getCity();
            $newOrder['shipping']['state'] = $order->getShippingAddress()->getRegion();
            $newOrder['shipping']['postcode'] = $order->getShippingAddress()->getPostcode();
            $newOrder['shipping']['country'] = $order->getShippingAddress()->getCountryId();
            $newOrder['shipping']['email'] = $order->getShippingAddress()->getEmail();
            $newOrder['shipping']['phone'] = $order->getShippingAddress()->getTelephone();
            $newOrder['invoice_url'] = 'http://knawat.com/invoices/pdf/example.pdf';
            $method = $order->getPayment()->getMethod();
            $additionalInformation = $order->getPayment()->getAdditionalInformation();
            if (($method != '') && array_key_exists('method_title', $additionalInformation)) {
                $newOrder['payment_method'] = $method." (".$additionalInformation['method_title'].")";
            } else {
                $newOrder['payment_method'] = "Default (Knawat Magento Method)";
            }

            $newOrder['billing'] = (object) $newOrder['billing'];
            $newOrder['shipping'] = (object) $newOrder['shipping'];
            $newOrder = (object) $newOrder;
            return $newOrder;
        }
    }

    /**
     * @param $item
     * @return mixed
     */
    public function isProductKnawat($item)
    {
        $productId = $item->getProductId();
        $product = $this->productFactory->create()->load($productId);
        return $isKnawat= $product->getData('is_knawat');
    }

    /**
     * @param $path
     * @return mixed
     */
    public function getConfigData($path)
    {
        $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
        return $this->scopeConfig->getValue(self::PATH_KNAWAT_DEFAULT.$path, $storeScope);
    }

    /**
     * @param $orderId
     * @return mixed
     */
    public function getOrderObject($orderId)
    {
        $order = $this->orderFactory->create()->load($orderId);
        return $order;
    }

    /**
     * @return Logger
     */
    public function getOrderLogger()
    {
        $writer = new \Zend\Log\Writer\Stream(BP . '/var/log/knawat_order.log');
        $logger = new \Zend\Log\Logger();
        $logger->addWriter($writer);
        return $logger;
    }
}
