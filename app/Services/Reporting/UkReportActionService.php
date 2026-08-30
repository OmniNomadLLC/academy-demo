<?php

namespace App\Services\Reporting;

class UkReportActionService
{
    public function attach(array $priorities, array $context = []): array
    {
        return array_map(function (array $priority) use ($context) {
            $priority['actions'] = $this->actionsForPriority($priority, $context);

            return $priority;
        }, $priorities);
    }

    protected function actionsForPriority(array $priority, array $context): array
    {
        return match ($priority['type'] ?? null) {
            'high_risk_students' => $this->studentRiskActions($priority, $context),
            'class_risk' => $this->classRiskActions($priority, $context),
            'attendance_drop' => $this->attendanceDropActions($priority, $context),
            default => [],
        };
    }

    protected function studentRiskActions(array $priority, array $context): array
    {
        $actions = [];
        $actions[] = [
            'label' => 'View students',
            'url' => $priority['url'] ?? ($context['student_risk_url'] ?? '#'),
        ];

        if (! empty($context['student_blast_url'])) {
            $actions[] = [
                'label' => 'Send student blast',
                'url' => $context['student_blast_url'],
            ];
        }

        return $actions;
    }

    protected function classRiskActions(array $priority, array $context): array
    {
        $actions = [];
        $actions[] = [
            'label' => 'View upcoming classes',
            'url' => $priority['url'] ?? ($context['class_risk_url'] ?? '#'),
        ];

        $firstClass = $priority['meta']['classes'][0] ?? null;
        $recordId = $firstClass['class_id'] ?? null;
        if ($recordId) {
            $actions[] = [
                'label' => 'Manage class',
                'url' => route('filament.admin.resources.u-k-upcomings.manage', ['record' => $recordId]),
            ];
        }

        return $actions;
    }

    protected function attendanceDropActions(array $priority, array $context): array
    {
        $actions = [];
        $actions[] = [
            'label' => 'View low attendance students',
            'url' => $context['low_attendance_url'] ?? ($priority['url'] ?? '#'),
        ];

        if (! empty($context['attendance_export_url'])) {
            $actions[] = [
                'label' => 'Export list',
                'url' => $context['attendance_export_url'],
            ];
        }

        return $actions;
    }
}
