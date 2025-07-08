<?php
// FILE: classes/ApiClient.php
// PURPOSE: A generic client for making authenticated API calls.

namespace ApiDaemon;

use ApiDaemon\Auth\AuthInterface;
use Exception;

class ApiClient {
    private $authenticator;

    /**
     * @param AuthInterface $authenticator An object that can provide an authentication token.
     */
    public function __construct(AuthInterface $authenticator) {
        $this->authenticator = $authenticator;
    }

    /**
     * Performs a GET request to a specified URL.
     *
     * @param string $url The full URL of the API endpoint.
     * @return array The result containing httpcode, data, and error.
     * @throws Exception if authentication fails.
     */
    public function get(string $url): array {
        $token = $this->authenticator->getToken();

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            Log::error("cURL request failed.", ['url' => $url, 'error' => $error]);
        }

        return ['httpcode' => $httpCode, 'data' => $response, 'error' => $error];
    }
}
