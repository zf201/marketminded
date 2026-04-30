<?php

use App\Models\Team;
use Livewire\Component;

new class extends Component
{
    public function mount(Team $current_team): void
    {
        $params = ['current_team' => $current_team];
        if ($type = request('type')) {
            $params['type'] = $type;
        }

        $this->redirectRoute('create.new', $params, navigate: true);
    }

    public function render()
    {
        return <<<'HTML'
        <div></div>
        HTML;
    }
}; ?>

<div></div>
