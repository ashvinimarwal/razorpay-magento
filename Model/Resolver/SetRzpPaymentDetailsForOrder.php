<?php

declare(strict_types=1);

namespace Razorpay\Magento\Model\Resolver;

use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Razorpay\Magento\Model\PaymentMethod;
use Razorpay\Magento\Model\Config;

/**
 * Mutation resolver for setting payment method for shopping cart
 */
class SetRzpPaymentDetailsForOrder implements ResolverInterface
{
    /**
     * @var \Magento\Sales\Api\Data\OrderInterface
     */
    protected $order;

    /**
     * @var \Razorpay\Magento\Model\Config
     */
    protected $config;

    /**
     * @var \Magento\Sales\Model\Service\InvoiceService
     */
    protected $invoiceService;

    /**
     * @var \Magento\Framework\DB\Transaction
     */
    protected $transaction;

    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $checkoutSession;

    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\InvoiceSender
     */
    protected $invoiceSender;

    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\OrderSender
     */
    protected $orderSender;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @var STATUS_PROCESSING
     */
    protected const STATUS_PROCESSING = 'processing';

    /**
     * @param PaymentMethod $paymentMethod
     * @param \Magento\Sales\Api\Data\OrderInterface $order
     * @param \Razorpay\Magento\Model\Config $config
     * @param \Magento\Sales\Model\Service\InvoiceService $invoiceService
     * @param \Magento\Framework\DB\Transaction $transaction
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender
     * @param \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender
     */
    public function __construct(
        PaymentMethod $paymentMethod,
        \Magento\Sales\Api\Data\OrderInterface $order,
        \Razorpay\Magento\Model\Config $config,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Framework\DB\Transaction $transaction,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Sales\Model\Order\Email\Sender\InvoiceSender $invoiceSender,
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender
    )
    {
        $this->rzp                        = $paymentMethod->rzp;
        $this->order                      = $order;
        $this->config                     = $config;
        $this->invoiceService             = $invoiceService;
        $this->transaction                = $transaction;
        $this->scopeConfig                = $scopeConfig;
        $this->checkoutSession            = $checkoutSession;
        $this->invoiceSender              = $invoiceSender;
        $this->orderSender                = $orderSender;
    }

    /**
     * @inheritdoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null)
    {
        if (empty($args['input']['order_id']))
        {
            throw new GraphQlInputException(__('Required parameter "order_id" is missing.'));
        }

        $order_id = $args['input']['order_id'];

        if (empty($args['input']['rzp_payment_id']))
        {
            throw new GraphQlInputException(__('Required parameter "rzp_payment_id" is missing.'));
        }

        $rzp_payment_id = $args['input']['rzp_payment_id'];

        if (empty($args['input']['rzp_signature']))
        {
            throw new GraphQlInputException(__('Required parameter "rzp_signature" is missing.'));
        }

        $rzp_signature = $args['input']['rzp_signature'];

        $rzp_order_id = '';
        try
        {
            $order = $this->order->load($order_id, $this->order::INCREMENT_ID);
            if ($order)
            {
                $rzp_order_id = $order->getRzpOrderId();
            }
        } catch (\Exception $e)
        {
            throw new GraphQlInputException(__('Error: %1.', $e->getMessage()));
        }

        $attributes = [
            'razorpay_payment_id' => $rzp_payment_id,
            'razorpay_order_id'   => $rzp_order_id,
            'razorpay_signature'  => $rzp_signature
        ];
        $this->rzp->utility->verifyPaymentSignature($attributes);

        try
        {
            $storeScope = \Magento\Store\Model\ScopeInterface::SCOPE_STORE;
            $payment_action  = $this->scopeConfig->getValue('payment/razorpay/rzp_payment_action', $storeScope);
            $payment_capture = 'Captured';
            if ($payment_action === 'authorize')
            {
                $payment_capture = 'Authorized';
            }

            //fetch order from API
            $rzp_order_data = $this->rzp->order->fetch($rzp_order_id);
            $receipt = isset($rzp_order_data->receipt) ? $rzp_order_data->receipt : null;
            if ($receipt !== $order_id)
            {
                throw new GraphQlInputException(__('Not a valid Razorpay orderID'));
            }
            $rzpOrderAmount = $rzp_order_data->amount;

            if ($order)
            {
                $amountPaid = number_format($rzpOrderAmount / 100, 2, ".", "");
                if ($order->getStatus() === 'pending')
                {
                    $order->setState(static::STATUS_PROCESSING)->setStatus(static::STATUS_PROCESSING);
                }

                $order->addStatusHistoryComment(
                    __(
                        '%1 amount of %2 online. Transaction ID: "' . $rzp_payment_id . '"',
                        $payment_capture,
                        $order->getBaseCurrency()->formatTxt($amountPaid)
                    )
                );

                if ($order->canInvoice() && $this->config->canAutoGenerateInvoice()
                    && $rzp_order_data->status === 'paid')
                {
                    $invoice = $this->invoiceService->prepareInvoice($order);
                    $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                    $invoice->setTransactionId($rzp_payment_id);
                    $invoice->register();
                    $invoice->save();

                    $transactionSave = $this->transaction
                        ->addObject($invoice)
                        ->addObject($invoice->getOrder());
                    $transactionSave->save();

                    $this->invoiceSender->send($invoice);

                    $order->addStatusHistoryComment(
                        __('Notified customer about invoice #%1.', $invoice->getId())
                    )->setIsCustomerNotified(true);
                    try {
                        $this->checkoutSession->setRazorpayMailSentOnSuccess(true);
                        $this->orderSender->send($order);
                        $this->checkoutSession->unsRazorpayMailSentOnSuccess();
                    } catch (\Magento\Framework\Exception\MailException $e) {
                        throw new GraphQlInputException(__('Razorpay Error: %1.', $e->getMessage()));
                    } catch (\Exception $e) {
                        throw new GraphQlInputException(__('Error: %1.', $e->getMessage()));
                    }
                }
                $order->save();
            }
        } catch (\Razorpay\Api\Errors\Error $e)
        {
            throw new GraphQlInputException(__('Razorpay Error: %1.', $e->getMessage()));
        } catch (\Exception $e)
        {
            throw new GraphQlInputException(__('Error: %1.', $e->getMessage()));
        }

        return [
            'order' => [
                'order_id' => $receipt
            ]
        ];
    }
}