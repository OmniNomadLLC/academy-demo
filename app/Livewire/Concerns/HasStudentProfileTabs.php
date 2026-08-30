<?php

namespace App\Livewire\Concerns;

trait HasStudentProfileTabs
{
    public string $activeTab = 'attendance';

    /**
     * Limit tab switches to the known set so embeds cannot call missing state.
     */
    public function setProfileTab(string $tab): void
    {
        if (! in_array($tab, $this->allowedProfileTabs(), true)) {
            return;
        }

        $this->activeTab = $tab;
    }

    /**
     * Allow child classes to override the tab list if they introduce new sections.
     */
    protected function allowedProfileTabs(): array
    {
        return [
            'employment',
            'assessments',
            'skills',
            'classes',
            'notes',
            'contact',
            'manager',
            'attendance',
        ];
    }
}
