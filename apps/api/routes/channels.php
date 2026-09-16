<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('users.{id}', function ($user, $id) {
    return hash_equals((string) $user->id, (string) $id) && $user->status === 'active';
});
