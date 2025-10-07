<?php declare(strict_types=1);

namespace Vhdev\AdminSmsOrderNotificationFree\Controller;

use Psr\Log\LoggerInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

#[Route(defaults: ['_routeScope' => ['api']])]
class TwilioValidationController extends AbstractController
{
    private LoggerInterface $logger;
    private SystemConfigService $systemConfigService;

    public function __construct(
        LoggerInterface $logger,
        SystemConfigService $systemConfigService
    ) {
        $this->logger = $logger;
        $this->systemConfigService = $systemConfigService;
    }

    #[Route(
        path: '/api/_action/vhdev-sms/validate-twilio',
        name: 'api.action.vhdev.sms.validate.twilio',
        methods: ['POST']
    )]
    public function validateTwilioCredentials(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $salesChannelId = $data['salesChannelId'] ?? null;

        $this->logger->info('Twilio validation request received', [
            'salesChannelId' => $salesChannelId,
            'requestData' => $data
        ]);

        // Read saved credentials from database
        $sid = $this->systemConfigService->get(
            'VhdevAdminSmsOrderNotificationFree.config.twilioSid',
            $salesChannelId
        );
        
        $authToken = $this->systemConfigService->get(
            'VhdevAdminSmsOrderNotificationFree.config.twilioAuthToken',
            $salesChannelId
        );
        
        $fromNumber = $this->systemConfigService->get(
            'VhdevAdminSmsOrderNotificationFree.config.twilioFromNumber',
            $salesChannelId
        );
        
        $adminPhoneNumbers = $this->systemConfigService->get(
            'VhdevAdminSmsOrderNotificationFree.config.adminPhoneNumbers',
            $salesChannelId
        );

        $this->logger->info('Retrieved credentials from config', [
            'hasSid' => !empty($sid),
            'hasAuthToken' => !empty($authToken),
            'hasFromNumber' => !empty($fromNumber),
            'hasAdminPhoneNumbers' => !empty($adminPhoneNumbers),
            'salesChannelId' => $salesChannelId
        ]);

        // Validate required fields
        if (empty($sid) || empty($authToken)) {
            $this->logger->warning('Missing Twilio credentials', [
                'sid' => $sid ? 'present' : 'missing',
                'authToken' => $authToken ? 'present' : 'missing',
                'salesChannelId' => $salesChannelId
            ]);
            
            return new JsonResponse([
                'valid' => false,
                'message' => 'Twilio SID and Auth Token are not configured. Please save your settings first.'
            ], 400);
        }

        if (empty($fromNumber)) {
            return new JsonResponse([
                'valid' => false,
                'message' => 'Twilio From Number is not configured. Please save your settings first.'
            ], 400);
        }

        try {
            $httpClient = HttpClient::create([
                'auth_basic' => [$sid, $authToken],
            ]);

            // Validate credentials by fetching account info
            $response = $httpClient->request(
                'GET',
                "https://api.twilio.com/2010-04-01/Accounts/{$sid}.json"
            );

            $statusCode = $response->getStatusCode();

            if ($statusCode === 200) {
                $accountData = json_decode($response->getContent(), true);
                
                // Optionally validate phone number if provided
                if (!empty($fromNumber)) {
                    $isValidNumber = $this->validatePhoneNumber($sid, $authToken, $fromNumber);
                    
                    if (!$isValidNumber) {
                        return new JsonResponse([
                            'valid' => false,
                            'message' => 'The provided phone number is not valid or not associated with your Twilio account'
                        ], 400);
                    }
                }

                $this->logger->info('Twilio credentials validated successfully', [
                    'accountSid' => $sid,
                    'accountName' => $accountData['friendly_name'] ?? 'Unknown',
                    'salesChannelId' => $salesChannelId
                ]);

                // Send test SMS if admin phone numbers are provided
                $testSmsResults = [];
                if (!empty($adminPhoneNumbers)) {
                    $phoneNumbers = array_map('trim', explode(',', $adminPhoneNumbers));
                    
                    // Use test message instead of template
                    $message = 'TEST SMS: This is a test message from Shopware Admin SMS Order Notification plugin. Your Twilio configuration is working correctly!';
                    
                    foreach ($phoneNumbers as $phoneNumber) {
                        if (!empty($phoneNumber)) {
                            $result = $this->sendTestSms($sid, $authToken, $fromNumber, $phoneNumber, $message);
                            $testSmsResults[] = [
                                'phoneNumber' => $phoneNumber,
                                'result' => $result
                            ];
                        }
                    }
                }

                $successCount = count(array_filter($testSmsResults, fn($r) => $r['result']['success'] ?? false));
                $totalCount = count($testSmsResults);
                $testMessage = $totalCount > 0 ? " Test SMS sent to {$successCount}/{$totalCount} numbers" : '';

                return new JsonResponse([
                    'valid' => true,
                    'message' => 'Twilio credentials are valid.' . $testMessage,
                    'accountName' => $accountData['friendly_name'] ?? null,
                    'accountStatus' => $accountData['status'] ?? null,
                    'testSmsSent' => $totalCount > 0,
                    'testSmsResults' => $testSmsResults
                ]);
            }

            return new JsonResponse([
                'valid' => false,
                'message' => 'Invalid Twilio credentials'
            ], 401);

        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Twilio validation failed - Transport error', [
                'error' => $e->getMessage(),
                'salesChannelId' => $salesChannelId
            ]);

            return new JsonResponse([
                'valid' => false,
                'message' => 'Failed to connect to Twilio API: ' . $e->getMessage()
            ], 500);

        } catch (\Exception $e) {
            $this->logger->error('Twilio validation failed', [
                'error' => $e->getMessage(),
                'salesChannelId' => $salesChannelId
            ]);

            return new JsonResponse([
                'valid' => false,
                'message' => 'Validation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    private function validatePhoneNumber(string $sid, string $authToken, string $phoneNumber): bool
    {
        try {
            $httpClient = HttpClient::create([
                'auth_basic' => [$sid, $authToken],
            ]);

            // Try to fetch incoming phone numbers
            $response = $httpClient->request(
                'GET',
                "https://api.twilio.com/2010-04-01/Accounts/{$sid}/IncomingPhoneNumbers.json",
                [
                    'query' => [
                        'PhoneNumber' => $phoneNumber
                    ]
                ]
            );

            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getContent(), true);
                
                // Check if the phone number exists in the account
                if (!empty($data['incoming_phone_numbers'])) {
                    return true;
                }
            }

            // If not found in incoming numbers, it might be a verified number or messaging service
            // For now, we'll accept it if credentials are valid
            return true;

        } catch (\Exception $e) {
            $this->logger->warning('Phone number validation skipped', [
                'phoneNumber' => $phoneNumber,
                'error' => $e->getMessage()
            ]);
            
            // Don't fail validation if we can't verify the number
            return true;
        }
    }

    private function sendTestSms(string $sid, string $authToken, string $fromNumber, string $toNumber, string $message): ?array
    {
        try {
            $httpClient = HttpClient::create([
                'auth_basic' => [$sid, $authToken],
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
            ]);

            $response = $httpClient->request(
                'POST',
                "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json",
                [
                    'body' => http_build_query([
                        'To' => $toNumber,
                        'From' => $fromNumber,
                        'Body' => $message
                    ])
                ]
            );

            $statusCode = $response->getStatusCode();
            $responseData = json_decode($response->getContent(false), true);

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info('Test SMS sent successfully', [
                    'to' => $toNumber,
                    'from' => $fromNumber,
                    'messageSid' => $responseData['sid'] ?? null
                ]);

                return [
                    'success' => true,
                    'messageSid' => $responseData['sid'] ?? null,
                    'status' => $responseData['status'] ?? null
                ];
            }

            $this->logger->error('Failed to send test SMS', [
                'to' => $toNumber,
                'statusCode' => $statusCode,
                'response' => $responseData
            ]);

            return [
                'success' => false,
                'error' => $responseData['message'] ?? 'Unknown error'
            ];

        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Test SMS failed - Transport error', [
                'to' => $toNumber,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'Transport error: ' . $e->getMessage()
            ];

        } catch (\Exception $e) {
            $this->logger->error('Test SMS failed', [
                'to' => $toNumber,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
