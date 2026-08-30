<?php

namespace App\Filament\Resources\AssessmentTemplateResource\RelationManagers;

use App\Models\AssessmentQuestion;
use App\Support\Assessments\SkillCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class QuestionsRelationManager extends RelationManager
{
    protected static string $relationship = 'questions';

    protected static ?string $title = 'Assessment Sections & Questions';

    protected static string $view = 'filament.resources.assessment-template-resource.relation-managers.questions';

    public ?string $activeSkillCategory = null;

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('skill_category')
                    ->label('Skill category')
                    ->options(SkillCategory::labels())
                    ->required()
                    ->default(fn () => $this->activeSkillCategory ?? SkillCategory::default()),
                Forms\Components\Textarea::make('question_text')
                    ->label('Question')
                    ->required()
                    ->rows(3),
                Forms\Components\Toggle::make('is_active')
                    ->label('Active')
                    ->default(true),
                Forms\Components\Hidden::make('sort_order'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable()
                    ->alignCenter(),
                Tables\Columns\BadgeColumn::make('skill_category')
                    ->label('Skill category')
                    ->sortable()
                    ->colors(['primary'])
                    ->formatStateUsing(fn (?string $state) => SkillCategory::label($state ?? SkillCategory::default())),
                Tables\Columns\TextColumn::make('question_text')
                    ->label('Question')
                    ->wrap()
                    ->searchable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('skill_category')
                    ->label('Skill category')
                    ->options(SkillCategory::labels())
                    ->searchable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(fn (array $data): array => $this->prepareFormData($data))
                    ->after(fn ($record) => $this->setActiveSkillCategory($record->skill_category ?? null)),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->mutateFormDataUsing(fn (array $data): array => $this->prepareFormData($data))
                    ->after(fn ($record) => $this->setActiveSkillCategory($record->skill_category ?? null)),
                Tables\Actions\DeleteAction::make()
                    ->action(function (AssessmentQuestion $record) {
                        $record->answers()->delete();

                        return (bool) $record->delete();
                    }),
            ])
            ->bulkActions([])
            ->reorderable('sort_order')
            ->modifyQueryUsing(fn (Builder $query) => $this->applyActiveSkillCategoryScope($query));
    }

    protected function prepareFormData(array $data): array
    {
        $category = $data['skill_category'] ?? null;
        $category = $category ?: SkillCategory::default();

        $data['skill_category'] = $category;
        $data['section'] = SkillCategory::label($category);
        $data['sort_order'] = $data['sort_order'] ?? $this->nextSortOrder($category);

        return $data;
    }

    protected function getTableQuery(): Builder
    {
        $query = parent::getTableQuery();

        if (! $query) {
            $query = $this->getRelationship()->getQuery();
        }

        return $query->orderByRaw('COALESCE(skill_category, ?) ASC', [SkillCategory::default()])
            ->orderBy('sort_order');
    }

    protected function nextSortOrder(string $category): int
    {
        $owner = $this->getOwnerRecord();

        if (! $owner || ! method_exists($owner, 'questions')) {
            return 1;
        }

        $max = $owner->questions()
            ->where(function ($query) use ($category) {
                $query->where('skill_category', $category);

                if ($category === SkillCategory::default()) {
                    $query->orWhereNull('skill_category');
                }
            })
            ->max('sort_order') ?? 0;

        return (int) $max + 1;
    }

    protected function applyActiveSkillCategoryScope(Builder $query): Builder
    {
        $category = $this->activeSkillCategory ?? $this->skillCategoryTabs->first()['value'];

        if ($category) {
            $query->where(function ($query) use ($category) {
                $query->where('skill_category', $category);

                if ($category === SkillCategory::default()) {
                    $query->orWhereNull('skill_category');
                }
            });
        }

        return $query;
    }

    protected function setActiveSkillCategory(?string $category): void
    {
        if (blank($category)) {
            return;
        }

        $this->activeSkillCategory = $category;
    }

    public function updatedActiveSkillCategory(): void
    {
        $this->resetTable();
    }

    public function getSkillCategoryTabsProperty(): Collection
    {
        $labels = collect(SkillCategory::labels());

        if (blank($this->activeSkillCategory) || ! $labels->keys()->contains($this->activeSkillCategory)) {
            $this->activeSkillCategory = $labels->keys()->first();
        }

        return $labels->map(fn ($label, $value) => [
            'value' => $value,
            'label' => $label,
        ])->values();
    }
}
