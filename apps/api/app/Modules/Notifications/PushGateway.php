<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class PushGateway
{
    public function send(string $provider, string $token, string $type, string $eventId): void
    {
        if ($provider === 'fcm') {
            $this->fcm($token, $type, $eventId);

            return;
        }
        if ($provider === 'apns') {
            $this->apns($token, $type, $eventId);

            return;
        }
        throw new \RuntimeException('PUSH_PROVIDER_UNSUPPORTED');
    }

    private function fcm(string $token, string $type, string $eventId): void
    {
        $path = config('push.fcm.credentials_path');
        if (! $path || ! is_readable($path)) {
            throw new \RuntimeException('FCM_NOT_CONFIGURED');
        }
        $credentials = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! isset($credentials['project_id'], $credentials['private_key'], $credentials['client_email']) || ! preg_match('/^[a-z0-9-]+$/', $credentials['project_id'])) {
            throw new \RuntimeException('FCM_CREDENTIALS_INVALID');
        }
        $access = Cache::remember('push:oauth:'.hash('sha256', $credentials['client_email']), 3000, function () use ($credentials): string {
            $encode = fn (string $value) => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
            $header = $encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claims = $encode(json_encode(['iss' => $credentials['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token', 'iat' => time(), 'exp' => time() + 3600]));
            if (! openssl_sign($header.'.'.$claims, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new \RuntimeException('FCM_CREDENTIALS_INVALID');
            }
            $response = Http::timeout(15)->asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $header.'.'.$claims.'.'.$encode($signature)]);
            if (! $response->successful() || ! is_string($response->json('access_token'))) {
                throw new \RuntimeException('FCM_AUTH_FAILED');
            }

            return $response->json('access_token');
        });
        $response = Http::timeout(15)->withToken($access)->post('https://fcm.googleapis.com/v1/projects/'.$credentials['project_id'].'/messages:send', [
            'message' => ['token' => $token, 'notification' => ['title' => 'KIRH GEO', 'body' => 'Новое событие в приложении'],
                'data' => ['type' => $type, 'event_id' => $eventId], 'android' => ['priority' => 'high', 'collapse_key' => $eventId]]]);
        if (! $response->successful()) {
            throw new \RuntimeException('FCM_SEND_FAILED');
        }
    }

    private function apns(string $token, string $type, string $eventId): void
    {
        $settings = config('push.apns');
        if (! $settings['certificate_path'] || ! $settings['key_path'] || ! $settings['topic']
            || ! is_readable($settings['certificate_path']) || ! is_readable($settings['key_path'])) {
            throw new \RuntimeException('APNS_NOT_CONFIGURED');
        }
        if (! preg_match('/^[a-f0-9]{64,200}$/i', $token)) {
            throw new \RuntimeException('APNS_TOKEN_INVALID');
        }
        $host = $settings['sandbox'] ? 'https://api.sandbox.push.apple.com' : 'https://api.push.apple.com';
        $response = Http::timeout(15)->withOptions(['version' => '2.0', 'cert' => $settings['certificate_path'],
            'ssl_key' => [$settings['key_path'], $settings['key_password']]])
            ->withHeaders(['apns-topic' => $settings['topic'], 'apns-push-type' => 'alert', 'apns-priority' => '10', 'apns-id' => $eventId, 'apns-collapse-id' => $eventId])
            ->post($host.'/3/device/'.$token, ['aps' => ['alert' => ['title' => 'KIRH GEO', 'body' => 'Новое событие в приложении']], 'type' => $type, 'event_id' => $eventId]);
        if (! $response->successful()) {
            throw new \RuntimeException('APNS_SEND_FAILED');
        }
    }
}
