<?php

namespace App\Services;

interface PushTransport
{
    /** Return sent, expired, or failed. Tests replace only this external boundary. */
    public function send(array $subscription, array $payload): string;
}
