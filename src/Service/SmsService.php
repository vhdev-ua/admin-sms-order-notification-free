<?php declare(strict_types=1);

namespace VhdevAdminSmsOrderNotificationFree\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Twilio\Rest\Client;
use Twilio\Exceptions\TwilioException;

class SmsService
{
    private SystemConfigService $systemConfigService;
    private LoggerInterface $logger;

    public function __construct(
        SystemConfigService $systemConfigService,
        LoggerInterface $logger
    ) {
        $this->systemConfigService = $systemConfigService;
        $this->logger = $logger;
    }

    public function sendOrderNotification(array $orderData): void
    {

        
        if (!$this->isEnabled()) {
            $this->logger->info('SMS Order Notification: Service is disabled');
            return;
        }

        $twilioConfig = $this->getTwilioConfig();

        
        if (!$this->validateTwilioConfig($twilioConfig)) {
            $this->logger->error('SMS Order Notification: Invalid Twilio configuration');
            return;
        }

        $phoneNumbers = $this->getAdminPhoneNumbers();

        
        if (empty($phoneNumbers)) {
            $this->logger->warning('SMS Order Notification: No admin phone numbers configured');
            return;
        }

        $message = $this->buildSmsMessage($orderData);

        
        try {
            $client = new Client($twilioConfig['sid'], $twilioConfig['authToken']);
            
            foreach ($phoneNumbers as $phoneNumber) {
                $phoneNumber = trim($phoneNumber);
                if (empty($phoneNumber)) {
                    continue;
                }

                try {
                    $client->messages->create(
                        $phoneNumber,
                        [
                            'from' => $twilioConfig['fromNumber'],
                            'body' => $message
                        ]
                    );
                    
                    $this->logger->info('SMS Order Notification sent successfully', [
                        'phone' => $phoneNumber,
                        'orderNumber' => $orderData['orderNumber']
                    ]);
                } catch (TwilioException $e) {
                    $this->logger->error('Failed to send SMS notification', [
                        'phone' => $phoneNumber,
                        'error' => $e->getMessage(),
                        'orderNumber' => $orderData['orderNumber']
                    ]);
                }
            }
        } catch (TwilioException $e) {
            $this->logger->error('Twilio client initialization failed', [
                'error' => $e->getMessage()
            ]);
        }
    }

    private function isEnabled(): bool
    {
        return (bool) $this->systemConfigService->get('VhdevAdminSmsOrderNotificationFree.config.enabled');
    }

    private function getTwilioConfig(): array
    {
        return [
            'sid' => $this->systemConfigService->get('VhdevAdminSmsOrderNotificationFree.config.twilioSid'),
            'authToken' => $this->systemConfigService->get('VhdevAdminSmsOrderNotificationFree.config.twilioAuthToken'),
            'fromNumber' => $this->systemConfigService->get('VhdevAdminSmsOrderNotificationFree.config.twilioFromNumber'),
        ];
    }

    private function validateTwilioConfig(array $config): bool
    {
        return !empty($config['sid']) && 
               !empty($config['authToken']) && 
               !empty($config['fromNumber']);
    }

    private function getAdminPhoneNumbers(): array
    {
        $phoneNumbers = $this->systemConfigService->get('VhdevAdminSmsOrderNotificationFree.config.adminPhoneNumbers');
        
        if (empty($phoneNumbers)) {
            return [];
        }

        return array_map('trim', explode(',', $phoneNumbers));
    }

    private function buildSmsMessage(array $orderData): string
    {
        $template = $this->systemConfigService->get('VhdevAdminSmsOrderNotificationFree.config.smsTemplate');
        
        if (empty($template)) {
            $template = 'New order #{orderNumber} placed with total amount {amountTotal} {currency} by {customerName}.';
        }

        $replacements = [
            '{orderNumber}' => $orderData['orderNumber'] ?? 'N/A',
            '{amountTotal}' => $orderData['amountTotal'] ?? 'N/A',
            '{customerName}' => $orderData['customerName'] ?? 'N/A',
            '{currency}' => $orderData['currency'] ?? '',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }
}
