<?php

use App\Models\Conversation;
use App\Models\Team;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public function mount(Team $current_team): void
    {
        $type = request('type');

        $conversation = Conversation::create([
            'team_id' => $current_team->id,
            'user_id' => Auth::id(),
            'title' => __('New conversation'),
            'type' => $type ?: null,
        ]);

        $this->redirectRoute('create.chat', ['current_team' => $current_team, 'conversation' => $conversation], navigate: true);
    }

    public function render()
    {
        return <<<'HTML'
        <div></div>
        HTML;
    }
}; ?>

<div></div>
