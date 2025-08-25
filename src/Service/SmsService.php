<?php declare(strict_types=1);

namespace Vhdev\AdminSmsOrderNotificationFree\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class SmsService
{
    /** @var SystemConfigService */
    private $systemConfigService;
    
    /** @var LoggerInterface */
    private $logger;

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
        $httpClient = HttpClient::create([
            'auth_basic' => [$twilioConfig['sid'], $twilioConfig['authToken']],
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ]);

        foreach ($phoneNumbers as $phoneNumber) {
            $phoneNumber = trim($phoneNumber);
            if (empty($phoneNumber)) {
                continue;
            }

            try {
                $response = $httpClient->request('POST', 'https://api.twilio.com/2010-04-01/Accounts/'.$twilioConfig['sid'].'/Messages.json', [
                    'body' => http_build_query([
                        'To' => $phoneNumber,
                        'From' => $twilioConfig['fromNumber'],
                        'Body' => $message
                    ])
                ]);

                $statusCode = $response->getStatusCode();
                $responseData = json_decode($response->getContent(false), true);

                if ($statusCode >= 200 && $statusCode < 300) {
                    $this->logger->info('SMS Order Notification sent successfully', [
                        'phone' => $phoneNumber,
                        'orderNumber' => $orderData['orderNumber'] ?? 'N/A',
                        'messageSid' => $responseData['sid'] ?? null
                    ]);
                } else {
                    $this->logger->error('Failed to send SMS notification', [
                        'phone' => $phoneNumber,
                        'statusCode' => $statusCode,
                        'response' => $responseData,
                        'orderNumber' => $orderData['orderNumber'] ?? 'N/A'
                    ]);
                }
            } catch (TransportExceptionInterface $e) {
                $this->logger->error('Failed to send SMS notification - Transport error', [
                    'phone' => $phoneNumber,
                    'error' => $e->getMessage(),
                    'orderNumber' => $orderData['orderNumber'] ?? 'N/A'
                ]);
            } catch (\Exception $e) {
                $this->logger->error('Failed to send SMS notification', [
                    'phone' => $phoneNumber,
                    'error' => $e->getMessage(),
                    'orderNumber' => $orderData['orderNumber'] ?? 'N/A'
                ]);
            }
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
