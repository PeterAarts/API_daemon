<?php
// FILE: scheduler/auth/AuthFactory.php
// PURPOSE: Creates the correct authentication handler based on the task's auth type.

namespace ApiDaemon\Auth;

use Exception;

class AuthFactory {
    /**
     * Creates and returns an instance of an authentication handler.
     *
     * @param string $authType The type of authentication (e.g., 'appkey', 'scania_oauth2').
     * @param int $apiId The ID of the API credentials in the database.
     * @return AuthInterface The authentication handler instance.
     * @throws Exception If the auth type is unknown.
     */
    public static function create(string $authType, int $apiId): AuthInterface {
        switch (strtolower($authType)) {
            case 'client_credentials': 
                return new ClientCredentialsAuth($apiId);
            case 'appkey':
            case 'rdw_api_key':
                return new ClientCredentialsAuth($apiId);

            case 'scania_oauth2':
                return new ScaniaAuth($apiId);

            case 'basic':
                throw new Exception("Auth type 'basic' not fully implemented in factory yet.");

            case 'oauth2': // This can be an alias for client_credentials
                 return new ClientCredentialsAuth($apiId);

            default:
                throw new Exception("Unknown authentication type requested: {$authType}");
        }
    }
}
