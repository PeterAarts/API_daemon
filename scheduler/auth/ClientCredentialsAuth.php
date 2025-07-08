<?php
// FILE: scheduler/auth/ClientCredentialsAuth.php
// PURPOSE: Handles OAuth 2.0 Client Credentials Grant Flow.

namespace ApiDaemon\Auth;

use ApiDaemon\DB;
use ApiDaemon\Log;
use Exception;

class ClientCredentialsAuth implements AuthInterface {
    private $db;
    private $apiCredentials;
    private static $tokenCache = [];

    public function __construct(int $apiId) {
        $this->db = DB::getInstance();
        $this->loadApiCredentials($apiId);
    }

    private function loadApiCredentials(int $apiId): void {
        $creds = $this->db->findById($apiId, 'settings_api');
        if (!$creds) {
            throw new Exception("API credentials not found for api_id: {$apiId}");
        }
        $this->apiCredentials = $creds;
    }

    public function getToken(): string {
        $cacheKey = $this->apiCredentials->id;

        // Check for a valid token in the database first (from a previous run)
        $tokenData = $this->db->query("SELECT * FROM api_collector_tokens WHERE collector_id = ?", [$cacheKey])->first();
        if ($tokenData && isset($tokenData->tokenDT) && strtotime($tokenData->tokenDT) > time()) {
             Log::info("Using valid token from DB.", ['api_id' => $cacheKey]);
             return $tokenData->token;
        }
        
        // If no valid DB token, check the static cache for this run
        if (isset(self::$tokenCache[$cacheKey]) && time() < self::$tokenCache[$cacheKey]['expires_at']) {
            Log::info("Using cached API token for this run.", ['api_id' => $cacheKey]);
            return self::$tokenCache[$cacheKey]['token'];
        }

        Log::info("No valid token. Fetching new Client Credentials token.", ['api_id' => $cacheKey]);
        return $this->fetchNewToken($cacheKey, $tokenData->id ?? null);
    }

    private function fetchNewToken(int $cacheKey, ?int $tokenId): string {
        $creds = $this->apiCredentials;
        
        // This flow can use Basic Auth for the token endpoint
        $header = ['Authorization: Basic ' . base64_encode($creds->username . ':' . $creds->password), 'Content-Type: application/x-www-form-urlencoded'];
        $postFields = http_build_query(['grant_type' => 'client_credentials']);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $creds->url_token);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);

        $responseJson = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Failed to fetch Client Credentials token. HTTP: {$httpCode}, Response: {$responseJson}");
        }

        $response = json_decode($responseJson, true);
        if (!isset($response['access_token'], $response['expires_in'])) {
            throw new Exception("Invalid Client Credentials token response: " . $responseJson);
        }

        $token = $response['access_token'];
        $expiresIn = (int)$response['expires_in'];
        $expiryDate = date("Y-m-d H:i:s", time() + $expiresIn);

        // Update token in the database
        if ($tokenId) {
            $this->db->update('api_collector_tokens', $tokenId, ['token' => $token, 'tokenLT' => $expiresIn, 'tokenDT' => $expiryDate]);
        }

        // Update static cache for this run
        self::$tokenCache[$cacheKey] = ['token' => $token, 'expires_at' => time() + $expiresIn - 60];

        Log::info("Successfully fetched new Client Credentials token.", ['api_id' => $cacheKey]);
        return $token;
    }
}
