<?php

namespace App\Services;

use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushTransport implements PushTransport
{
    public function send(array $subscription, array $payload): string
    {
        $push = new WebPush(['VAPID' => ['subject' => config('sushi.push.subject'), 'publicKey' => config('sushi.push.public_key'), 'privateKey' => config('sushi.push.private_key')]], ['TTL' => 3600], 10);
        $report = $push->sendOneNotification(Subscription::create($subscription), json_encode($payload));

        return $report->isSuccess() ? 'sent' : ($report->isSubscriptionExpired() ? 'expired' : 'failed');
    }
}
