<?php

namespace App\Services\Writer;

use App\Models\Team;

interface Agent
{
    public function execute(Brief $brief, Team $team): AgentResult;
}
