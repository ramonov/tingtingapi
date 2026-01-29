<?php

namespace TingTing\Laravel;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use TingTing\Laravel\Exceptions\TingTingApiException;

class TingTingClient
{
    protected Client $client;
    protected array $config;
    protected ?string $token = null;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->client = new Client([
            'base_uri' => $this->config['base_url'],
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /**
     * Set the Bearer token (JWT or API Token) for authentication.
     */
    public function setToken(string $token): self
    {
        $this->token = $token;
        return $this;
    }

    /**
     * Set the API Token for authentication (alias for setToken).
     */
    public function setApiToken(string $token): self
    {
        return $this->setToken($token);
    }

    /**
     * Get the current token or use api_token from config if available.
     */
    protected function getHeaders(): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        $token = $this->token ?? $this->config['api_token'] ?? null;

        if ($token) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        return $headers;
    }

    /**
     * Send a request to the API.
     */
    protected function request(string $method, string $uri, array $options = []): array
    {
        try {
            $options['headers'] = array_merge($this->getHeaders(), $options['headers'] ?? []);
            $response = $this->client->request($method, $uri, $options);
            return json_decode($response->getBody()->getContents(), true) ?? [];
        } catch (GuzzleException $e) {
            $message = $e->getMessage();
            $data = null;

            if ($e instanceof RequestException && $e->hasResponse()) {
                $responseBody = $e->getResponse()->getBody()->getContents();
                $data = json_decode($responseBody, true);
                if (isset($data['message'])) {
                    $message = $data['message'];
                }
            }

            throw new TingTingApiException($message, $e->getCode(), $data, $e);
        }
    }

    // --- Authentication Endpoints ---

    /**
     * Login to get access and refresh tokens.
     */
    public function login(string $email, string $password): array
    {
        return $this->request('POST', 'auths/login/', [
            'json' => [
                'email' => $email,
                'password' => $password,
            ],
        ]);
    }

    /**
     * Refresh the access token.
     */
    public function refreshToken(string $refresh): array
    {
        return $this->request('POST', 'auths/login/refresh/', [
            'json' => [
                'refresh' => $refresh,
            ],
        ]);
    }

    /**
     * Generate a new static API token.
     * 
     * When a new token is generated, any previous keys are automatically soft-deleted.
     * Returns: ['token' => '...', 'message' => '...']
     */
    public function generateApiKeys(): array
    {
        return $this->request('POST', 'auths/generate-api-keys/');
    }

    /**
     * Get the current static API token.
     * 
     * Returns: ['token' => '...', 'last_used' => '...', 'created_at' => '...']
     */
    public function getApiKeys(): array
    {
        return $this->request('GET', 'auths/get-api-keys/');
    }

    /**
     * Get user details.
     */
    public function userDetail(): array
    {
        return $this->request('GET', 'auths/user-profile/');
    }

    // --- Phone Numbers Endpoints ---

    /**
     * List all broker phone numbers.
     */
    public function activeBrokerPhones(): array
    {
        return $this->request('GET', 'active-broker-phone/');
    }

    /**
     * List all active phone numbers assigned to the user.
     */
    public function activeUserPhones(): array
    {
        return $this->request('GET', 'phone-number/active/');
    }

    // --- Campaign Endpoints ---

    /**
     * List campaigns.
     * 
     * @param array $filters Optional filters: limit, offset, status, etc.
     */
    public function listCampaigns(array $filters = []): array
    {
        return $this->request('GET', 'campaign/', [
            'query' => $filters,
        ]);
    }

    /**
     * Create a campaign.
     */
    public function createCampaign(array $data): array
    {
        return $this->request('POST', 'campaign/create/', [
            'json' => $data,
        ]);
    }

    /**
     * Update a campaign.
     */
    public function updateCampaign(int $campaignId, array $data): array
    {
        return $this->request('POST', "campaign/{$campaignId}/", [
            'json' => $data,
        ]);
    }

    /**
     * Delete a campaign.
     */
    public function deleteCampaign(int $campaignId): array
    {
        return $this->request('DELETE', "campaign/{$campaignId}/");
    }

    /**
     * Run a campaign.
     */
    public function runCampaign(int $campaignId): array
    {
        return $this->request('POST', "run-campaign/{$campaignId}/");
    }

    /**
     * Add voice assistance to a campaign.
     * URL: campaign/create/{campaignId}/message/
     */
    public function addVoiceAssistance(int $campaignId, array $data): array
    {
        return $this->request('PATCH', "campaign/create/{$campaignId}/message/", [
            'json' => $data,
        ]);
    }

    /**
     * Add an contact|contacts (array ) to a campaign.
     * URL: campaign/{campaignId}/add-contact/
     */
    public function addContact(int $campaignId, array $data): array
    {
        return $this->request('POST', "campaign/{$campaignId}/add-contact/", [
            'json' => $data,
        ]);
    }

    /**
     * Add bulk contacts to a campaign via file upload or data.
     * URL: campaign/create/{campaignId}/detail/
     * If $filePath is provided, it will be uploaded as 'bulk_file'.
     */
    public function addBulkContacts(int $campaignId, $bulkData): array
    {
        $options = [];
        
        if (is_string($bulkData) && file_exists($bulkData)) {
            $options['multipart'] = [
                [
                    'name'     => 'bulk_file',
                    'contents' => fopen($bulkData, 'r'),
                    'filename' => basename($bulkData)
                ]
            ];
        } else {
            $options['json'] = $bulkData;
        }

        return $this->request('POST', "campaign/create/{$campaignId}/detail/", $options);
    }

    /**
     * List all contacts for a specific campaign.
     */
    public function listContacts(int $campaignId, array $filters = []): array
    {
        return $this->request('GET', "campaign-detail/{$campaignId}/", [
            'query' => $filters,
        ]);
    }

    /**
     * Delete a contact from a campaign.
     */
    public function deleteContact(int $contactId): array
    {
        return $this->request('DELETE', "phone-number/delete/{$contactId}/");
    }

    /**
     * Get attributes for a specific contact.
     */
    public function getContactAttributes(int $contactId): array
    {
        return $this->request('GET', "campaign/{$contactId}/attributes/");
    }

    /**
     * Edit attributes for a specific contact.
     */
    public function editContactAttributes(int $contactId, array $attributes): array
    {
        return $this->request('PATCH', "campaign/{$contactId}/attributes/", [
            'json' => $attributes,
        ]);
    }

    /**
     * Update a contact's phone number.
     */
    public function updateContactNumber(int $contactId, string $number): array
    {
        return $this->request('PATCH', "phone-number/update/{$contactId}/", [
            'json' => ['number' => $number],
        ]);
    }

    // --- OTP Endpoints ---

    /**
     * Send OTP.
     */
    public function sendOtp(array $data): array
    {
        return $this->request('POST', 'auths/send/otp/', [
            'json' => $data,
        ]);
    }

    /**
     * List sent OTPs.
     */
    public function listSentOtps(array $filters = []): array
    {
        return $this->request('GET', 'auths/list/send-otps/', [
            'query' => $filters,
        ]);
    }
}
