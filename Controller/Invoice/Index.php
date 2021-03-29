<?php

declare(strict_types=1);

namespace Itemless\Invoice\Controller\Invoice;

use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\DB\Transaction;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order\Email\Sender\InvoiceSender;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Service\InvoiceService;

class Index implements HttpGetActionInterface
{
    /**
     * @var Http
     */
    protected Http $request;

    /**
     * @var InvoiceService
     */
    protected InvoiceService $invoiceService;

    /**
     * @var Transaction
     */
    protected Transaction $transaction;

    /**
     * @var OrderRepositoryInterface
     */
    protected OrderRepositoryInterface $orderRepository;

    /**
     * @var OrderFactory
     */
    private OrderFactory $orderFactory;

    /**
     * @var InvoiceSender
     */
    private InvoiceSender $invoiceSender;

    public function __construct(
        Http $request,
        OrderRepositoryInterface $orderRepository,
        OrderFactory $orderFactory,
        InvoiceService $invoiceService,
        InvoiceSender $invoiceSender,
        Transaction $transaction
    ) {
        $this->request = $request;
        $this->orderRepository = $orderRepository;
        $this->orderFactory = $orderFactory;
        $this->invoiceService = $invoiceService;
        $this->invoiceSender = $invoiceSender;
        $this->transaction = $transaction;

    }

    public function execute()
    {
        // Get info
        $orderId = $this->request->getParam('order_id');
        $amount = $this->request->getParam('amount');

        // Get order
        $order = $this->orderRepository->get($orderId);
        $incrementId = $order->getIncrementId();

        $itemsArray = [];

        if ($order->canInvoice()) {

            // Collect itemsArray
            foreach ($order->getAllItems() as $orderItem) {
                $orderItemId = $orderItem->getOrderId();
                $orderItemRowTotal = $orderItem->getRowTotal();
                if ($orderItemRowTotal < $amount) {
                    $itemsArray[$orderItemId] = $orderItem->getQtyOrdered();
                    $amount = $amount - $orderItemRowTotal;
                }
            }

            if (!empty($itemsArray)) {
                $orderModel = $this->orderFactory->create();
                $orderModel->loadByIncrementId($incrementId);
                $invoice = $this->invoiceService->prepareInvoice($orderModel, $itemsArray);

                $invoice->register();
                $transactionSave = $this->transaction->addObject(
                    $invoice
                )->addObject(
                    $invoice->getOrder()
                );
                $transactionSave->save();
                $this->invoiceSender->send($invoice);

                //send notification code
                $order->addStatusHistoryComment(
                    __('Notified customer about invoice #%1.', $invoice->getId())
                )
                    ->setIsCustomerNotified(true)
                    ->save();
            }
        }
    }
}
