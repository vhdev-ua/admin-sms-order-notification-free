<?php declare(strict_types=1);

namespace AdminSmsOrderNotificationFree\Subscriber;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Event\OrderStateMachineStateChangeEvent;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use AdminSmsOrderNotificationFree\Service\SmsService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;

class OrderPlacedSubscriber implements EventSubscriberInterface
{
    private SmsService $smsService;
    private SystemConfigService $systemConfigService;
    private LoggerInterface $logger;

    public function __construct(
        SmsService $smsService,
        SystemConfigService $systemConfigService,
        LoggerInterface $logger
    ) {
        $this->smsService = $smsService;
        $this->systemConfigService = $systemConfigService;
        $this->logger = $logger;

    }

    public static function getSubscribedEvents(): array
    {

        return [
            CheckoutOrderPlacedEvent::class => 'onOrderPlaced',
        ];
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        
        $this->logger->info('SMS Order Notification: onOrderPlaced event triggered');
        
        try {
            // Check if SMS notifications are enabled
            $isEnabled = $this->systemConfigService->get('AdminSmsOrderNotificationFree.config.enabled');
            $this->logger->info('SMS Order Notification: Plugin enabled status', ['enabled' => $isEnabled]);
            
            if (!$isEnabled) {
                $this->logger->info('SMS Order Notification: Plugin is disabled, skipping SMS');
                return;
            }

            $order = $event->getOrder();
            
            if (!$order instanceof OrderEntity) {
                $this->logger->warning('SMS Order Notification: Invalid order entity received');
                return;
            }

            $this->logger->info('SMS Order Notification: Processing order', ['orderNumber' => $order->getOrderNumber()]);
            
            $orderData = $this->extractOrderData($order);
            $this->smsService->sendOrderNotification($orderData);

        } catch (\Exception $e) {
            $this->logger->error('SMS Order Notification: Error processing order placed event', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

        private function extractOrderData(OrderEntity $order): array
    {
        $customer = $order->getOrderCustomer();
        $customerName = 'Unknown Customer';
        
        if ($customer) {
            $customerName = trim($customer->getFirstName() . ' ' . $customer->getLastName());
        }

                $currency = $order->getCurrency() ? $order->getCurrency()->getSymbol() : '';

        return [
            'orderNumber' => $order->getOrderNumber(),
            'amountTotal' => number_format($order->getAmountTotal(), 2),
            'customerName' => $customerName,
            'currency' => $currency,
        ];
    }
}
