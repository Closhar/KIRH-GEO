<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BroadcastAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    public function test_only_authenticated_owner_can_subscribe_to_private_user_channel(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => 'test-id']);
        $session = $this->postJson('/api/v1/auth/register', ['name' => 'Viewer', 'email' => Str::uuid().'@example.test',
            'password' => 'strong-password-1234', 'platform' => 'android', 'installation_id' => (string) Str::uuid()])->assertCreated()->json('data');
        $channel = 'private-users.'.$session['user']['id'];
        $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel])->assertUnauthorized();
        $this->withToken($session['access_token'])->postJson('/api/v1/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel])
            ->assertOk()->assertJsonPath('data.auth', 'test-key:'.hash_hmac('sha256', '123.456:'.$channel, 'test-secret'));
        $this->withToken($session['access_token'])->postJson('/api/v1/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-users.'.Str::uuid()])->assertForbidden();
        $this->withToken($session['access_token'])->postJson('/api/v1/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'public-locations'])->assertForbidden();
    }
}
