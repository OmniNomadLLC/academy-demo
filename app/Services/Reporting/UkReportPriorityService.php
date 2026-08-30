<?php

namespace App\Services\Reporting;

class UkReportPriorityService
{
    protected const SEVERITY_WEIGHTS = [
        'critical' => 3,
        'warning' => 2,
        'info' => 1,
    ];

    protected const URGENCY_WEIGHTS = [
        'immediate' => 3,
        'soon' => 2,
        'low' => 1,
    ];

    public function build(array $intelligence, array $context = []): array
    {
        $priorities = [];

        if (($trend = $intelligence['attendance_trend'] ?? null) && isset($trend['alert'])) {
            $priorities[] = $this->makePriority(
                title: $this->attendanceTitle($trend),
                type: 'attendance_drop',
                severity: $trend['alert']['severity'] ?? 'warning',
                impact: abs((int) ($trend['alert']['value'] ?? 0)),
                urgency: 'soon',
                url: $context['attendance_trend_url'] ?? '#',
                meta: ['window' => $trend['window'] ?? null]
            );
        }

        if ($studentRisks = $intelligence['student_risks'] ?? null) {
            $count = (int) ($studentRisks['count'] ?? 0);

            if ($count > 0) {
                $severity = $count >= 3 ? 'critical' : 'warning';
                $priorities[] = $this->makePriority(
                    title: $count . ' high-risk students need outreach',
                    type: 'high_risk_students',
                    severity: $severity,
                    impact: $count,
                    urgency: 'soon',
                    url: $context['student_risk_url'] ?? '#',
                    meta: ['students' => $studentRisks['students'] ?? []]
                );
            }
        }

        if ($classRisks = $intelligence['class_risks'] ?? null) {
            $count = (int) ($classRisks['count'] ?? 0);
            if ($count > 0) {
                $highestSeverity = $this->resolveClassRiskSeverity($classRisks['classes'] ?? []);

                $priorities[] = $this->makePriority(
                    title: $count . ' classes at risk in next 48h',
                    type: 'class_risk',
                    severity: $highestSeverity,
                    impact: $count,
                    urgency: 'immediate',
                    url: $context['class_risk_url'] ?? '#',
                    meta: ['classes' => $classRisks['classes'] ?? []]
                );
            }
        }

        usort($priorities, function ($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        return array_slice($priorities, 0, 5);
    }

    protected function makePriority(string $title, string $type, string $severity, int $impact, string $urgency, string $url, array $meta = []): array
    {
        $severityWeight = self::SEVERITY_WEIGHTS[$severity] ?? self::SEVERITY_WEIGHTS['info'];
        $urgencyWeight = self::URGENCY_WEIGHTS[$urgency] ?? self::URGENCY_WEIGHTS['low'];

        $score = ($severityWeight * 3) + $impact + ($urgencyWeight * 2);

        return [
            'title' => $title,
            'type' => $type,
            'severity' => $severity,
            'impact' => $impact,
            'urgency' => $urgency,
            'url' => $url,
            'score' => $score,
            'meta' => $meta,
        ];
    }

    protected function attendanceTitle(array $trend): string
    {
        $delta = isset($trend['delta']) ? round((float) $trend['delta'], 1) : null;

        if ($delta === null) {
            return 'Attendance change detected';
        }

        $direction = $delta < 0 ? 'drop' : 'increase';

        return sprintf('%s%% attendance %s vs last week', number_format(abs($delta), 1), $direction);
    }

    protected function resolveClassRiskSeverity(array $classes): string
    {
        foreach ($classes as $class) {
            if (($class['risk_level'] ?? 'warning') === 'critical') {
                return 'critical';
            }
        }

        return 'warning';
    }
}
