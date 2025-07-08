<?php
// FILE: scheduler/auth/ScaniaAuth.php
// PURPOSE: Handles the Scania OAuth2 Challenge-Response flow.

namespace ApiDaemon\Auth;

use ApiDaemon\DB;
use ApiDaemon\Log;
use Exception;

class ScaniaAuth implements AuthInterface {
    private $db;
    private $apiCredentials;

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
        $tokenData = $this->db->query("SELECT * FROM api_collector_tokens WHERE collector_id = ?", [$this->apiCredentials->id])->first();
        
        // 1. Check for valid access token
        if ($tokenData && isset($tokenData->tokenDT) && strtotime($tokenData->tokenDT) > time()) {
            Log::info("Using valid Scania token from DB.", ['api_id' => $this->apiCredentials->id]);
            return $tokenData->token;
        }

        // 2. Check for valid refresh token
        if ($tokenData && isset($tokenData->refreshTokenDT) && strtotime($tokenData->refreshTokenDT) > time()) {
            Log::info("Scania token expired, attempting refresh.", ['api_id' => $this->apiCredentials->id]);
            try {
                return $this->refreshToken($tokenData);
            } catch (Exception $e) {
                Log::warning("Scania token refresh failed, will attempt full re-auth.", ['error' => $e->getMessage()]);
            }
        }

        // 3. Perform full re-authentication
        Log::info("Performing full Scania challenge-response auth.", ['api_id' => $this->apiCredentials->id]);
        return $this->fetchNewToken($tokenData->id ?? null);
    }

    private function fetchNewToken(?int $tokenId): string {
        // Step 1: Get Challenge
        $challenge = $this->getChallenge();

        // Step 2: Create Response
        $challengeResponse = $this->createChallengeResponse($this->apiCredentials->client_secret, $challenge);

        // Step 3: Get Token
        $tokenUrl = $this->apiCredentials->url_token;
        $postFields = 'clientId=' . $this->apiCredentials->client_id . '&Response=' . $challengeResponse;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $tokenUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        $responseJson = curl_exec($ch);
        curl_close($ch);

        $response = json_decode($responseJson, true);
        if (!isset($response['token'], $response['refreshToken'])) {
            throw new Exception("Failed to get Scania token after challenge.");
        }

        // Step 4: Store Token
        $this->storeToken($response, $tokenId);
        return $response['token'];
    }
    
    private function refreshToken($tokenData): string {
        // Refresh token logic here
        throw new Exception("Scania refresh token logic not yet fully implemented.");
    }

    private function getChallenge(): string {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->apiCredentials->url_authorize);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'clientId=' . $this->apiCredentials->client_id);
        $responseJson = curl_exec($ch);
        curl_close($ch);

        $response = json_decode($responseJson, true);
        if (!isset($response['challenge'])) {
            throw new Exception("Failed to get Scania challenge.");
        }
        return $response['challenge'];
    }

    private function createChallengeResponse($secretKey, $challenge): string {
        $secretKeyArr = base64_decode(strtr($secretKey, '-_', '+/'));
        $challengeArr = base64_decode(strtr($challenge, '-_', '+/'));
        $challengeResponse = hash_hmac('sha256', $challengeArr, $secretKeyArr, true);
        return rtrim(strtr(base64_encode($challengeResponse), '+/', '-_'), '=');
    }

    private function storeToken(array $tokenResponse, ?int $tokenId): void {
        $fields = [
            'token' => $tokenResponse['token'],
            'refreshToken' => $tokenResponse['refreshToken'],
            'tokenDT' => date("Y-m-d H:i:s", time() + 3600), // Typically 1 hour
            'refreshTokenDT' => date("Y-m-d H:i:s", time() + 86400) // Typically 24 hours
        ];
        if ($tokenId) {
            $this->db->update('api_collector_tokens', $tokenId, $fields);
        } else {
            // This case needs handling if a token record doesn't exist yet
        }
    }
}
