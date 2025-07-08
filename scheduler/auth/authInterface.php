<?php
// FILE: scheduler/auth/AuthInterface.php
// PURPOSE: Defines the contract for all authentication handler classes.

namespace ApiDaemon\Auth;

interface AuthInterface {
    /**
     * Gets a valid access token.
     *
     * @return string The access token.
     * @throws \Exception If fetching the token fails.
     */
    public function getToken(): string;
}
