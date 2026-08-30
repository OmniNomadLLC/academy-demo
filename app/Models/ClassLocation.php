<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClassLocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'address_line_1',
        'address_line_2',
        'city',
        'postcode',
        'country',
        'region',
        'is_virtual',
        'virtual_meeting_url',
        'virtual_meeting_room',
        'notes',
    ];

    protected $casts = [
        'is_virtual' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (ClassLocation $location): void {
            $location->slug = Str::slug($location->slug ?: $location->name ?: Str::random(8));
            if (! $location->name) {
                $location->name = Str::title(str_replace('-', ' ', $location->slug));
            }
        });

        static::updating(function (ClassLocation $location): void {
            if ($location->isDirty('slug')) {
                $location->slug = Str::slug((string) $location->slug);
            }
        });
    }

    public static function for(string $rawLocationOrCalendar): ?self
    {
        $slug = Str::slug(trim($rawLocationOrCalendar));

        if ($slug === '') {
            return null;
        }

        return static::query()->where('slug', $slug)->first()
            ?? ClassLocationCalendar::query()->where('calendar_slug', $slug)->first()?->location;
    }

    public static function forCalendar(?string $calendarName): ?self
    {
        if (! $calendarName) {
            return null;
        }

        return static::for($calendarName);
    }

    public function calendars(): HasMany
    {
        return $this->hasMany(ClassLocationCalendar::class);
    }

    public function formattedAddress(string $separator = "\n"): string
    {
        $parts = array_filter([
            $this->address_line_1,
            $this->address_line_2,
            trim(collect([$this->city, $this->postcode])->filter()->implode(' ')) ?: null,
            $this->country,
        ]);

        return implode($separator, $parts);
    }

    public function applyToSession(ClassSession $session): void
    {
        $session->class_location_id = $this->getKey();
        $session->venue_name = $this->name;
        $address = $this->is_virtual ? null : trim($this->formattedAddress(PHP_EOL));
        $session->venue_address = $address !== '' ? $address : null;
        $session->is_virtual = (bool) ($this->is_virtual ?? false);
        $session->virtual_meeting_url = $session->is_virtual ? ($this->virtual_meeting_url ?: null) : null;
        $session->virtual_meeting_room = $session->is_virtual ? ($this->virtual_meeting_room ?: null) : null;
    }

    public function syncFutureSessions(?\DateTimeInterface $from = null): int
    {
        $from = $from ? Carbon::parse($from) : now();

        $calendarSlugs = $this->calendars()->pluck('calendar_slug')->filter()->map(fn ($slug) => Str::slug($slug))->values();
        if ($this->slug) {
            $calendarSlugs->push(Str::slug($this->slug));
        }
        $calendarSlugs = $calendarSlugs->unique()->values();

        $calendarNames = $this->calendars()->pluck('calendar_name')->filter()->map(fn ($name) => strtolower(trim($name)))->unique()->values();

        if ($calendarSlugs->isEmpty() && $calendarNames->isEmpty()) {
            return 0;
        }

        $query = ClassSession::query()
            ->whereDate('session_date', '>=', $from->toDateString())
            ->when($calendarSlugs->isNotEmpty() || $calendarNames->isNotEmpty(), function ($builder) use ($calendarSlugs, $calendarNames) {
                $builder->where(function ($inner) use ($calendarSlugs, $calendarNames) {
                    $added = false;
                    if ($calendarSlugs->isNotEmpty()) {
                        $inner->whereIn('calendar_norm', $calendarSlugs);
                        $added = true;
                    }

                    if ($calendarNames->isNotEmpty()) {
                        $method = $added ? 'orWhereIn' : 'whereIn';
                        $inner->{$method}(DB::raw('LOWER(calendar_name)'), $calendarNames);
                    }
                });
            });

        $updated = 0;
        $query->chunkById(200, function ($sessions) use (&$updated) {
            foreach ($sessions as $session) {
                if (! $session instanceof ClassSession) {
                    $session = ClassSession::find($session->id);
                    if (! $session) {
                        continue;
                    }
                }

                $this->applyToSession($session);
                if ($session->isDirty()) {
                    $session->save();
                    $updated++;
                }
            }
        });

        return $updated;
    }
}
