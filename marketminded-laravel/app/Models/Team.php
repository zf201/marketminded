<?php

namespace App\Models;

use App\Concerns\GeneratesUniqueTeamSlugs;
use App\Enums\TeamRole;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['name', 'slug', 'is_personal', 'openrouter_api_key', 'fast_model', 'powerful_model', 'homepage_url', 'blog_url', 'brand_description', 'product_urls', 'competitor_urls', 'style_reference_urls', 'target_audience', 'tone_keywords', 'content_language', 'intelligence_status', 'intelligence_error'])]
#[Hidden(['openrouter_api_key'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use GeneratesUniqueTeamSlugs, HasFactory, SoftDeletes;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'fast_model' => 'deepseek/deepseek-v3.2:nitro',
        'powerful_model' => 'deepseek/deepseek-v3.2:nitro',
        'product_urls' => '[]',
        'competitor_urls' => '[]',
        'style_reference_urls' => '[]',
        'content_language' => 'English',
    ];

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Team $team) {
            if (empty($team->slug)) {
                $team->slug = static::generateUniqueTeamSlug($team->name);
            }
        });

        static::updating(function (Team $team) {
            if ($team->isDirty('name')) {
                $team->slug = static::generateUniqueTeamSlug($team->name, $team->id);
            }
        });
    }

    /**
     * Get the team owner.
     */
    public function owner(): ?Model
    {
        return $this->members()
            ->wherePivot('role', TeamRole::Owner->value)
            ->first();
    }

    /**
     * Get all members of this team.
     *
     * @return BelongsToMany<Model, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members', 'team_id', 'user_id')
            ->using(Membership::class)
            ->withPivot(['role'])
            ->withTimestamps();
    }

    /**
     * Get all memberships for this team.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * Get all invitations for this team.
     *
     * @return HasMany<TeamInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    /**
     * Get the team's brand positioning.
     *
     * @return HasOne<BrandPositioning, $this>
     */
    public function brandPositioning(): HasOne
    {
        return $this->hasOne(BrandPositioning::class);
    }

    /**
     * Get all audience personas for this team.
     *
     * @return HasMany<AudiencePersona, $this>
     */
    public function audiencePersonas(): HasMany
    {
        return $this->hasMany(AudiencePersona::class)->orderBy('sort_order');
    }

    /**
     * Get the team's voice profile.
     *
     * @return HasOne<VoiceProfile, $this>
     */
    public function voiceProfile(): HasOne
    {
        return $this->hasOne(VoiceProfile::class);
    }

    /**
     * Get all AI tasks for this team.
     *
     * @return HasMany<AiTask, $this>
     */
    public function aiTasks(): HasMany
    {
        return $this->hasMany(AiTask::class)->orderByDesc('created_at');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_personal' => 'boolean',
            'openrouter_api_key' => 'encrypted',
            'product_urls' => 'array',
            'competitor_urls' => 'array',
            'style_reference_urls' => 'array',
        ];
    }

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
