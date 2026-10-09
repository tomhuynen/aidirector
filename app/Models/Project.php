<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AspectRatio;
use App\Enums\CoverStatus;
use App\Enums\ElementType;
use App\Enums\ProjectPurpose;
use App\Enums\ProjectRuleStatus;
use App\Events\ProjectDeleting;
use App\Support\Intake\DocumentText;
use App\Support\Projects\ProjectSettings;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Models\ConversationMessage;
use RedExplosion\Sqids\Concerns\HasSqids;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media as BaseMedia;
use Spatie\Multitenancy\Models\Concerns\UsesTenantConnection;

class Project extends Model implements HasMedia
{
    /** @use HasFactory<\Database\Factories\ProjectFactory> */
    use HasFactory;
    use HasSqids;
    use InteractsWithMedia;
    use SoftDeletes;
    use UsesTenantConnection;

    /**
     * Photos of the real things that must be recognisable in the shots:
     * products, vessels, sites, people. Content, not style.
     */
    public const CONTENT_REFERENCES = 'content_references';

    /**
     * Images that show how the shots should look. Style, not content.
     */
    public const STYLE_REFERENCES = 'style_references';

    /**
     * The group picture of the cast and sets, drawn when setup finishes with
     * elements picked. It heads the project page.
     */
    public const COVER = 'cover';

    /** The cover, gently animated as a seamless loop for the header. */
    public const COVER_LOOP = 'cover_loop';

    /**
     * The company's logos, named by the brand they show, such as "Damen".
     * The only text an image may show, and only drawn from these pictures.
     */
    public const LOGOS = 'logos';

    /** How many logos are attached to one image at most, so the references stay few. */
    public const MAX_LOGOS_PER_IMAGE = 2;

    /**
     * The size every reference is sent to the models at, whatever arrived.
     */
    public const REFERENCE = 'reference';

    public const REFERENCE_MAX_EDGE = 1536;

    /**
     * The custom property holding a reference's one-line description.
     */
    public const CAPTION = 'caption';

    /** @var list<string> */
    public const REFERENCE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    protected $guarded = [];

    /**
     * Relations and files are removed by listeners, never by the database.
     */
    protected $dispatchesEvents = [
        'deleting' => ProjectDeleting::class,
    ];

    protected $attributes = [
        'purpose' => ProjectPurpose::EXPLAINER->value,
        'aspect_ratio' => AspectRatio::PORTRAIT->value,
        'style' => '[]',
        'default_duration' => 5,
    ];

    /**
     * @return array{
     *  purpose: 'App\Enums\ProjectPurpose',
     *  aspect_ratio: 'App\Enums\AspectRatio',
     *  style: 'array',
     *  cover_status: 'App\Enums\CoverStatus',
     *  settings: 'App\Support\Projects\ProjectSettings',
     *  setup_completed_at: 'datetime',
     *  archived_at: 'datetime',
     * }
     */
    protected function casts(): array
    {
        return [
            'purpose' => ProjectPurpose::class,
            'aspect_ratio' => AspectRatio::class,
            'style' => 'array',
            'cover_status' => CoverStatus::class,
            'settings' => ProjectSettings::class,
            'setup_completed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Director, $this> */
    public function director(): BelongsTo
    {
        return $this->belongsTo(Director::class);
    }

    /**
     * The intake conversation this project was created from, if any.
     *
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * The documents the director shared in the intake conversation, such as
     * the functional design, a brief or a script, as they were read into it.
     * Documents shared before the project existed are included.
     */
    public function sharedDocuments(): string
    {
        if ($this->conversation_id === null) {
            return '';
        }

        return ConversationMessage::query()
            ->where('conversation_id', $this->conversation_id)
            ->where('role', 'user')
            ->where('content', 'like', '%' . DocumentText::SHARED . '%')
            ->orderBy('created_at')
            ->pluck('content')
            ->map(fn(string $content) => trim(substr($content, (int) strpos($content, DocumentText::SHARED))))
            ->join("\n\n");
    }

    /**
     * The shots in the sequence. Shots merged into another are left out:
     * they live on as parts of the merged shot.
     *
     * @return HasMany<Shot, $this>
     */
    public function shots(): HasMany
    {
        return $this->hasMany(Shot::class)->whereNull('merged_into_id')->orderBy('position');
    }

    /**
     * Shots in routes are looked up among all shots, so the parts of a
     * merged shot keep their own pages.
     *
     * @param  string  $childType
     */
    protected function childRouteBindingRelationshipName($childType): string
    {
        return $childType === 'shot' ? 'allShots' : parent::childRouteBindingRelationshipName($childType);
    }

    /**
     * Every shot, including the parts of merged shots.
     *
     * @return HasMany<Shot, $this>
     */
    public function allShots(): HasMany
    {
        return $this->hasMany(Shot::class)->orderBy('position');
    }

    /**
     * The cast and sets: recurring people, places and objects.
     *
     * @return HasMany<Element, $this>
     */
    public function elements(): HasMany
    {
        return $this->hasMany(Element::class)->orderBy('type')->orderBy('name');
    }

    /**
     * The cast and sets as a list for the writers, or a note that there are none yet.
     */
    public function elementsBrief(): string
    {
        $elements = $this->elements()->get();

        if ($elements->isEmpty()) {
            return 'None yet.';
        }

        return $elements->map(fn(Element $element) => '- ' . $element->promptLine())->join("\n");
    }

    /** @return MorphMany<Generation, $this> */
    public function generations(): MorphMany
    {
        return $this->morphMany(Generation::class, 'generatable');
    }

    /** @return HasMany<Correction, $this> */
    public function corrections(): HasMany
    {
        return $this->hasMany(Correction::class);
    }

    /** @return HasMany<ProjectRule, $this> */
    public function rules(): HasMany
    {
        return $this->hasMany(ProjectRule::class)->orderBy('id');
    }

    /**
     * The confirmed rules as lines for the writers, image prompts and checks;
     * empty when there are none.
     */
    public function rulesBrief(): string
    {
        return $this->rules()
            ->where('status', ProjectRuleStatus::ACTIVE)
            ->pluck('text')
            ->map(fn(string $rule) => "- {$rule}")
            ->join("\n");
    }

    /** @return HasMany<ElementRound, $this> */
    public function elementRounds(): HasMany
    {
        return $this->hasMany(ElementRound::class)->orderBy('id');
    }

    /**
     * The cast and sets categories the intake chat has settled: picked from or skipped.
     *
     * @return list<ElementType>
     */
    public function settledElementTypes(): array
    {
        return $this->elementRounds()->get()
            ->filter(fn(ElementRound $round) => $round->status->settlesCategory())
            ->map(fn(ElementRound $round) => $round->type)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Whether the intake chat may finish: style pinned, every category
     * settled and no round left in the chat waiting for a pick.
     */
    public function canCompleteSetup(): bool
    {
        return $this->styleReference() !== null
            && count($this->settledElementTypes()) === count(ElementType::cases())
            && ! $this->elementRounds()->get()->contains(fn(ElementRound $round) => $round->isOpen());
    }

    /** @return HasMany<StyleOption, $this> */
    public function styleOptions(): HasMany
    {
        return $this->hasMany(StyleOption::class)->orderBy('round')->orderBy('position');
    }

    /**
     * The content photos a style sheet is composed from: the first few that
     * have a caption, since those are what the prompt can name.
     *
     * @return Collection<int, BaseMedia>
     */
    public function styleSheetSubjects(int $limit = 4): Collection
    {
        $photos = $this->getMedia(self::CONTENT_REFERENCES);

        return $photos
            ->sortByDesc(fn(BaseMedia $media) => filled($media->getCustomProperty(self::CAPTION)))
            ->take($limit)
            ->values();
    }

    /**
     * The pinned style sheet, which image generations attach so every render
     * matches the look the director chose.
     */
    public function styleReference(): ?BaseMedia
    {
        /** @var BaseMedia|null */
        return $this->getFirstMedia(self::STYLE_REFERENCES);
    }

    /**
     * A project started in the intake chat stays in setup until the chat has
     * finished: style pinned and every cast and sets category picked or
     * skipped. Opening it resumes the conversation.
     */
    public function needsSetup(): bool
    {
        return $this->conversation_id !== null && $this->setup_completed_at === null;
    }

    /**
     * The resolution the project's videos render at, or the pipeline default.
     */
    public function videoResolution(): string
    {
        return $this->video_resolution ?? Config::get('pipeline.video.resolution');
    }

    public function isOwnedBy(Director $director): bool
    {
        return $this->director_id === $director->id;
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::CONTENT_REFERENCES)->acceptsMimeTypes(self::REFERENCE_MIME_TYPES);
        $this->addMediaCollection(self::STYLE_REFERENCES)->acceptsMimeTypes(self::REFERENCE_MIME_TYPES);
        $this->addMediaCollection(self::COVER)->singleFile()->acceptsMimeTypes(['image/png', 'image/jpeg', 'image/webp']);
        $this->addMediaCollection(self::COVER_LOOP)->singleFile()->acceptsMimeTypes(['video/mp4', 'video/webm', 'video/quicktime']);
        $this->addMediaCollection(self::LOGOS)->acceptsMimeTypes(['image/png', 'image/jpeg', 'image/webp']);
    }

    /**
     * The logos an image of something should carry: those whose brand the
     * text names, or every logo when it only says "logo". None when the text
     * names no brand, so nothing gets a logo by default.
     *
     * @return Collection<int, BaseMedia>
     */
    public function logosFor(string $text): Collection
    {
        $logos = $this->getMedia(self::LOGOS);

        if ($logos->isEmpty() || trim($text) === '') {
            return collect();
        }

        $named = $logos->filter(fn(BaseMedia $logo) => trim($logo->name) !== '' && preg_match('/\b' . preg_quote(trim($logo->name), '/') . '\b/iu', $text) === 1);

        if ($named->isEmpty() && preg_match('/\b(logo|branding|branded)\b/iu', $text) === 1) {
            $named = $logos;
        }

        return $named->values()->take(self::MAX_LOGOS_PER_IMAGE);
    }

    /**
     * The brands the logos show, for the planners: the only text the images may carry.
     *
     * @return list<string>
     */
    public function brandNames(): array
    {
        return $this->getMedia(self::LOGOS)->pluck('name')->map(fn(string $name) => trim($name))->filter()->unique()->values()->all();
    }

    /**
     * The reference conversion is made on upload rather than queued, so the
     * models can look at it in the same request that added it.
     */
    public function registerMediaConversions(?BaseMedia $media = null): void
    {
        $this->addMediaConversion(self::REFERENCE)
            ->performOnCollections(self::CONTENT_REFERENCES, self::STYLE_REFERENCES)
            ->nonQueued()
            ->fit(Fit::Max, self::REFERENCE_MAX_EDGE, self::REFERENCE_MAX_EDGE)
            ->format('jpg')
            ->quality(80);
    }
}
