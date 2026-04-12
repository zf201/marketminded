# TODO

## AI Operations cleanup

Rewrite the AI Operations page as a simple cost dashboard. The old ai_tasks/ai_task_steps system is dead code — brand intelligence now runs through the chat.

### Remove
- `app/Jobs/GenerateBrandIntelligenceJob.php`
- `app/Services/Agents/PositioningAgent.php`
- `app/Services/Agents/PersonaAgent.php`
- `app/Services/Agents/VoiceProfileAgent.php`
- `app/Models/AiTask.php`
- `app/Models/AiTaskStep.php`
- `app/Livewire/AiTaskIndicator.php` (or wherever the indicator component lives)
- References to these in Team model, sidebar, header

### Rewrite AI Operations page
Pull cost data from `messages` table instead of `ai_tasks`:
- Total spend (30d)
- Total tokens (30d)
- Conversation count
- Table of recent conversations with total cost/tokens per conversation
