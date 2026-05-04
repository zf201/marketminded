<?php

use App\Models\Conversation;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('conversation.{id}', function ($user, $id) {
    $conv = Conversation::find($id);
    if (! $conv) return false;
    return $conv->team->members()->where('user_id', $user->id)->exists();
});
