<?php

namespace App\Support\Reporting;

use App\Support\Assessments\SkillCategory;
use App\Support\Reporting\Pdf\SimplePdfDocument;
use Carbon\Carbon;
use Illuminate\Support\Arr;

class UkReportPdfBuilder
{
    private const DEFAULT_SECTIONS = [
        'kpis',
        'skill_development',
        'attendance_trend',
        'sessions_per_day',
        'delivery_mode',
        'teacher_breakdown',
        'appointment_types',
        'calendar_overview',
        'attention',
    ];

    private const FALLBACK_LOGO_JPEG_BASE64 = '/9j/4AAQSkZJRgABAQABLAEsAAD/4QDSRXhpZgAATU0AKgAAAAgABwESAAMAAAABAAEAAAEaAAUAAAABAAAAYgEbAAUAAAABAAAAagEoAAMAAAABAAIAAAExAAIAAAAnAAAAcgE7AAIAAAAFAAAAmodpAAQAAAABAAAAoAAAAAAAAAEsAAAAAQAAASwAAAABQ2FudmEgZG9jPURBR1UzWmhrWUFBIHVzZXI9VUFGdEdndWY0WjQAAExFVCsAAAADoAEAAwAAAAEAAQAAoAIABAAAAAEAAAUCoAMABAAAAAEAAAUCAAAAAAP/hCnZodHRwOi8vbnMuYWRvYmUuY29tL3hhcC8xLjAvADw/eHBhY2tldCBiZWdpbj0i77u/IiBpZD0iVzVNME1wQ2VoaUh6cmVTek5UY3prYzlkIj8+IDx4OnhtcG1ldGEgeG1sbnM6eD0iYWRvYmU6bnM6bWV0YS8iIHg6eG1wdGs9IlhNUCBDb3JlIDYuMC4wIj4gPHJkZjpSREYgeG1sbnM6cmRmPSJodHRwOi8vd3d3LnczLm9yZy8xOTk5LzAyLzIyLXJkZi1zeW50YXgtbnMjIj4gPHJkZjpEZXNjcmlwdGlvbiByZGY6YWJvdXQ9IiIgeG1sbnM6ZGM9Imh0dHA6Ly9wdXJsLm9yZy9kYy9lbGVtZW50cy8xLjEvIiB4bWxuczp4bXA9Imh0dHA6Ly9ucy5hZG9iZS5jb20veGFwLzEuMC8iIHhtcDpDcmVhdG9yVG9vbD0iQ2FudmEgZG9jPURBR1UzWmhrWUFBIHVzZXI9VUFGdEdndWY0WjQiPiA8ZGM6Y3JlYXRvcj4gPHJkZjpTZXE+IDxyZGY6bGk+TEVUKzwvcmRmOmxpPiA8L3JkZjpTZXE+IDwvZGM6Y3JlYXRvcj4gPGRjOnRpdGxlPiA8cmRmOkFsdD4gPHJkZjpsaSB4bWw6bGFuZz0ieC1kZWZhdWx0Ij5MT0dPICg/KSAtIExPR08gRklOQUwgS2Fyb2xsPC9yZGY6bGk+IDwvcmRmOkFsdD4gPC9kYzp0aXRsZT4gPC9yZGY6RGVzY3JpcHRpb24+IDwvcmRmOlJERj4gPC94OnhtcG1ldGE+ICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgPD94cGFja2V0IGVuZD0idyI/PgD/7QByUGhvdG9zaG9wIDMuMAA4QklNBAQAAAAAADkcAVoAAxslRxwCAAACAAIcAgUAHExPR08gKD8pIC0gTE9HTyBGSU5BTCBLYXJvbGwcAlAABExFVCsAOEJJTQQlAAAAAAAQ9xQJsiYS1cjN1q4GqZrtpv/AABEIAUABQAMBIgACEQEDEQH/xAAfAAABBQEBAQEBAQAAAAAAAAAAAQIDBAUGBwgJCgv/xAC1EAACAQMDAgQDBQUEBAAAAX0BAgMABBEFEiExQQYTUWEHInEUMoGRoQgjQrHBFVLR8CQzYnKCCQoWFxgZGiUmJygpKjQ1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4eLj5OXm5+jp6vHy8/T19vf4+fr/xAAfAQADAQEBAQEBAQEBAAAAAAAAAQIDBAUGBwgJCgv/xAC1EQACAQIEBAMEBwUEBAABAncAAQIDEQQFITEGEkFRB2FxEyIygQgUQpGhscEJIzNS8BVictEKFiQ04SXxFxgZGiYnKCkqNTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqCg4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2dri4+Tl5ufo6ery8/T19vf4+fr/2wBDAAICAgICAgMCAgMFAwMDBQYFBQUFBggGBgYGBggKCAgICAgICgoKCgoKCgoMDAwMDAwODg4ODg8PDw8PDw8PDw//2wBDAQICAgQEBAcEBAcQCwkLEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBD/3QAEABT/2gAMAwEAAhEDEQA/AP38ooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACiiigAooooAKKKKACi';


    protected array $palette = [
        'primary' => [198 / 255, 11 / 255, 11 / 255],
        'textDark' => [30 / 255, 41 / 255, 59 / 255],
        'textMuted' => [107 / 255, 114 / 255, 128 / 255],
        'cardBg' => [248 / 255, 249 / 255, 251 / 255],
        'cardBorder' => [220 / 255, 224 / 255, 230 / 255],
        'chartBg' => [241 / 255, 245 / 255, 249 / 255],
        'grid' => [0.85, 0.85, 0.85],
        'barAlt' => [150 / 255, 8 / 255, 8 / 255],
        'barSecondary' => [16 / 255, 185 / 255, 129 / 255],
    ];

    protected array $sections;
    protected ?array $logoImage = null;
    protected string $logoImageName = 'ImLogo';
    protected int $pageNumber = 0;
    protected string $rangeLabel = '';
    protected string $filterSummary = '';
    protected string $generatedAt = '';

    public function __construct(array $sections = [])
    {
        $this->sections = ! empty($sections) ? array_values($sections) : self::DEFAULT_SECTIONS;
    }

    public function build(array $report): string
    {
        $pdf = new SimplePdfDocument();

        $margins = [
            'top' => 48.0,
            'right' => 40.0,
            'bottom' => 48.0,
            'left' => 40.0,
        ];
        $usableWidth = $pdf->getWidth() - $margins['left'] - $margins['right'];
        $cursorY = $margins['top'];

        $filters = $report['filters'];
        $this->rangeLabel = $this->buildRangeLabel($filters);
        $this->filterSummary = $this->summariseFilters($report);
        $this->generatedAt = Carbon::now(config('app.timezone', 'UTC'))->format('d M Y H:i');

        $this->registerLogoImage($pdf);
        $this->startPage($pdf, $cursorY, $margins, $usableWidth);

        if ($this->includes('kpis')) {
            $cursorY = $this->renderKpis($pdf, $report['kpis'], $margins, $cursorY, $usableWidth);
        }

        if ($this->includes('skill_development')) {
            $cursorY = $this->renderSkillDevelopment($pdf, $report['skillStats'] ?? [], $margins, $cursorY, $usableWidth);
        }

        if ($this->includes('attendance_trend')) {
            $cursorY = $this->renderAttendanceTrend(
                $pdf,
                $report['seriesAttendanceOverTime'],
                $margins,
                $cursorY,
                $usableWidth
            );
        }

        if ($this->includes('sessions_per_day')) {
            $cursorY = $this->renderSessionsPerDay(
                $pdf,
                $report['seriesSessionsOverTime'],
                $margins,
                $cursorY,
                $usableWidth
            );
        }

        if ($this->includes('delivery_mode')) {
            $cursorY = $this->renderDeliveryBreakdown($pdf, Arr::get($report, 'breakdowns.byDeliveryMode', []), $margins, $cursorY, $usableWidth);
        }

        if ($this->includes('teacher_breakdown')) {
            $cursorY = $this->renderTeacherBreakdown($pdf, Arr::get($report, 'breakdowns.byTeacher', []), $margins, $cursorY, $usableWidth);
        }

        if ($this->includes('appointment_types')) {
            $cursorY = $this->renderAppointmentTypes($pdf, Arr::get($report, 'breakdowns.byAppointmentType', []), $margins, $cursorY, $usableWidth);
        }

        if ($this->includes('calendar_overview')) {
            $cursorY = $this->renderCalendarTable($pdf, Arr::get($report, 'breakdowns.byCalendar', []), $margins, $cursorY, $usableWidth);
        }

        if ($this->includes('attention')) {
            $cursorY = $this->renderAttentionSection(
                $pdf,
                $report['sessionsNeedingAttention'] ?? null,
                $report['pendingFollowUps'] ?? [],
                $margins,
                $cursorY,
                $usableWidth
            );
        }

        return $pdf->output();
    }

    protected function renderKpis(SimplePdfDocument $pdf, array $kpis, array $margins, float $cursorY, float $usableWidth): float
    {
        $cards = [
            ['label' => 'Total sessions', 'value' => number_format((int) ($kpis['total_sessions'] ?? 0)), 'icon' => 'calendar'],
            ['label' => 'Avg attendance', 'value' => $this->formatPercent($kpis['average_attendance'] ?? null), 'icon' => 'trend'],
            ['label' => 'Unique students', 'value' => number_format((int) ($kpis['unique_students'] ?? 0)), 'icon' => 'users'],
            ['label' => 'New students', 'value' => number_format((int) ($kpis['new_students'] ?? 0)), 'icon' => 'user-plus'],
        ];

        $count = count($cards);
        $gap = 12;
        $cardWidth = ($usableWidth - ($gap * ($count - 1))) / $count;
        $cardHeight = 120;
        $neededHeight = $cardHeight + 16;

        $this->ensureSpace($pdf, $cursorY, $neededHeight, $margins, $usableWidth);

        foreach ($cards as $index => $card) {
            $x = $margins['left'] + $index * ($cardWidth + $gap);
            $y = $cursorY;

            $pdf->filledRect($x, $y, $cardWidth, $cardHeight, $this->color('cardBg'));
            $pdf->rect($x, $y, $cardWidth, $cardHeight, $this->color('cardBorder'), 0.6);
            $pdf->filledRect($x, $y, $cardWidth, 3, $this->color('primary'));

            $centerX = $x + ($cardWidth / 2);
            $iconSize = 28;
            $iconGap = 18;
            $valueGap = 14;
            $labelFontSize = 8.5;
            $valueFontSize = 18;

            $labelLines = $this->wrapTextToWidth(strtoupper($card['label']), 'bold', $labelFontSize, $cardWidth - 24);
            $labelLineHeight = 10;
            $labelHeight = max(1, count($labelLines)) * $labelLineHeight;

            $blockHeight = $iconSize + $iconGap + $labelHeight + $valueGap + $valueFontSize;
            $startY = $y + ($cardHeight / 2) - ($blockHeight / 2);

            $iconX = $centerX - ($iconSize / 2);
            $iconY = $startY;
            $this->drawKpiIcon($pdf, $card['icon'], $iconX, $iconY, $iconSize);

            $labelStartY = $iconY + $iconSize + $iconGap;
            foreach ($labelLines as $idx => $line) {
                $lineY = $labelStartY + ($idx * $labelLineHeight);
                $this->drawCenteredText($pdf, $centerX, $lineY, $line, 'bold', $labelFontSize, $this->color('textMuted'));
            }

            $valueY = $labelStartY + $labelHeight + $valueGap;
            $this->drawCenteredText($pdf, $centerX, $valueY, $card['value'], 'bold', $valueFontSize, $this->color('primary'));
        }

        return $cursorY + $neededHeight;
    }

    protected function renderSkillDevelopment(SimplePdfDocument $pdf, array $stats, array $margins, float $cursorY, float $usableWidth): float
    {
        $cards = [
            ['label' => 'Overall Skill Avg', 'value' => $stats['overall'] ?? null],
            ['label' => 'Speaking & Listening', 'value' => $stats[SkillCategory::SPEAKING_LISTENING] ?? null],
            ['label' => 'Reading & Writing', 'value' => $stats[SkillCategory::READING_WRITING] ?? null],
            ['label' => 'Motivation to Learn', 'value' => $stats[SkillCategory::TO_LEARN] ?? null],
            ['label' => 'Work Readiness', 'value' => $stats[SkillCategory::WORK_READINESS] ?? null],
            ['label' => 'IT Skills', 'value' => $stats[SkillCategory::IT_SKILLS] ?? null],
        ];

        $studentCount = (int) ($stats['student_count'] ?? 0);
        $cardHeight = 120;
        $headerAdvance = 40;
        $sectionHeight = $headerAdvance + $cardHeight + 16;

        $this->ensureSpace($pdf, $cursorY, $sectionHeight, $margins, $usableWidth);

        $pdf->text($margins['left'], $cursorY + 12, 'Skill development averages', 'bold', 12, $this->color('textDark'));
        $subtitle = $studentCount > 0
            ? sprintf('Most recent FINAL assessment per UK student in the selected range. %d students included.', $studentCount)
            : 'Most recent FINAL assessment per UK student in the selected range. No students with assessments in this window.';
        $pdf->text($margins['left'], $cursorY + 28, $subtitle, 'regular', 9, $this->color('textMuted'));
        $cursorY += $headerAdvance;

        $count = count($cards);
        $gap = 10;
        $cardWidth = ($usableWidth - ($gap * ($count - 1))) / $count;

        foreach ($cards as $index => $card) {
            $x = $margins['left'] + $index * ($cardWidth + $gap);
            $y = $cursorY;

            $pdf->filledRect($x, $y, $cardWidth, $cardHeight, $this->color('cardBg'));
            $pdf->rect($x, $y, $cardWidth, $cardHeight, $this->color('cardBorder'), 0.6);
            $pdf->filledRect($x, $y, $cardWidth, 3, $this->color('primary'));

            $centerX = $x + ($cardWidth / 2);
            $labelFontSize = 8.0;
            $valueFontSize = 22;
            $valueGap = 14;
            $labelLineHeight = 11;
            $maxLabelLines = 2;
            $innerWidth = $cardWidth - 12;

            $labelLines = $this->wrapTextToWidth(strtoupper($card['label']), 'bold', $labelFontSize, $innerWidth);
            if (count($labelLines) > $maxLabelLines) {
                $labelLines = array_slice($labelLines, 0, $maxLabelLines);
                $lastIdx = $maxLabelLines - 1;
                $labelLines[$lastIdx] = $this->truncateTextToWidth($labelLines[$lastIdx], 'bold', $labelFontSize, $innerWidth);
            }
            $labelHeight = max(1, count($labelLines)) * $labelLineHeight;

            $blockHeight = $labelHeight + $valueGap + $valueFontSize;
            $startY = $y + ($cardHeight / 2) - ($blockHeight / 2);

            foreach ($labelLines as $idx => $line) {
                $lineY = $startY + ($idx * $labelLineHeight);
                $this->drawCenteredText($pdf, $centerX, $lineY, $line, 'bold', $labelFontSize, $this->color('textMuted'));
            }

            $value = $card['value'];
            $isEmpty = is_null($value);
            $valueText = $isEmpty ? 'N/A' : number_format((float) $value, 1);
            $valueColor = $isEmpty ? $this->color('textMuted') : $this->color('primary');
            $valueY = $startY + $labelHeight + $valueGap + $valueFontSize - 4;
            $this->drawCenteredText($pdf, $centerX, $valueY, $valueText, 'bold', $valueFontSize, $valueColor);
        }

        return $cursorY + $cardHeight + 16;
    }

    protected function renderAttendanceTrend(SimplePdfDocument $pdf, array $series, array $margins, float $cursorY, float $usableWidth): float
    {
        $chartHeight = 200;
        $sectionHeight = $chartHeight + 140;
        $this->ensureSpace($pdf, $cursorY, $sectionHeight, $margins, $usableWidth);

        $pdf->text($margins['left'], $cursorY + 12, 'Attendance trend', 'bold', 12, $this->color('textDark'));
        $pdf->text($margins['left'], $cursorY + 28, 'Daily attendance across the selected window', 'regular', 9, $this->color('textMuted'));
        $cursorY += 40;

        $chartX = $margins['left'];
        $chartY = $cursorY;
        $pdf->filledRect($chartX, $chartY, $usableWidth, $chartHeight, $this->color('chartBg'));
        $pdf->rect($chartX, $chartY, $usableWidth, $chartHeight, $this->color('cardBorder'), 0.5);

        $minPct = 30.0;
        $maxPct = 105.0;
        $rangePct = $maxPct - $minPct;
        $mapToY = function (float $value) use ($chartY, $chartHeight, $minPct, $maxPct, $rangePct): float {
            $clamped = max($minPct, min($maxPct, $value));
            $ratio = ($clamped - $minPct) / $rangePct;
            return $chartY + $chartHeight - ($ratio * $chartHeight);
        };

        for ($tick = 30; $tick <= 100; $tick += 10) {
            $y = $mapToY((float) $tick);
            $pdf->line($chartX, $y, $chartX + $usableWidth, $y, $this->color('grid'), 0.3);
            $pdf->text($chartX + $usableWidth + 4, $y + 4, $tick . '%', 'regular', 8, $this->color('textMuted'));
        }

        for ($tick = 35; $tick <= 95; $tick += 10) {
            $y = $mapToY((float) $tick);
            $this->drawDottedLine($pdf, $chartX, $chartX + $usableWidth, $y, $this->color('grid'), 0.3);
        }

        $yTop = $mapToY($maxPct);
        $yHundred = $mapToY(100.0);
        $shadeHeight = max(2.0, $yHundred - $yTop);
        $pdf->filledRect($chartX, $yTop, $usableWidth, $shadeHeight, [241 / 255, 245 / 255, 249 / 255]);
        $pdf->line($chartX, $yHundred, $chartX + $usableWidth, $yHundred, $this->color('grid'), 0.5);
        $pdf->line($chartX, $mapToY($minPct), $chartX + $usableWidth, $mapToY($minPct), $this->color('cardBorder'), 0.8);

        $window = $series;
        $maxPoints = 370;
        if (count($window) > $maxPoints) {
            $window = array_slice($window, -$maxPoints);
        }
        $count = count($window);
        if ($count >= 2) {
            $step = $count > 1 ? $usableWidth / ($count - 1) : $usableWidth;
            $points = [];
            foreach ($window as $index => $point) {
                if (is_null($point['attendance_pct'])) {
                    continue;
                }
                $pct = max($minPct, min($maxPct, (float) $point['attendance_pct']));
                $x = $chartX + ($index * $step);
                $y = $mapToY($pct);
                $points[] = [$x, $y];
            }

            if (count($points) >= 2) {
                $pdf->polyline($points, $this->color('primary'), 1.4);
            }

            foreach ($points as [$px, $py]) {
                $pdf->filledRect($px - 1.5, $py - 1.5, 3, 3, $this->color('primary'));
            }

            $labelCount = min(6, max(2, $count >= 30 ? 6 : ($count >= 10 ? 5 : 3)));
            $indexes = [];
            if ($labelCount === 1) {
                $indexes[] = 0;
            } else {
                for ($i = 0; $i < $labelCount; $i++) {
                    $ratio = $labelCount > 1 ? $i / ($labelCount - 1) : 0;
                    $indexes[] = (int) round($ratio * ($count - 1));
                }
            }
            $indexes = array_values(array_unique($indexes));
            foreach ($indexes as $idx) {
                $point = $window[$idx];
                $x = $chartX + ($idx * $step);
                $label = Carbon::parse($point['date'])->format('d M');
                $pdf->text($x - 12, $chartY + $chartHeight + 18, $label, 'regular', 8, $this->color('textMuted'));
            }
        } else {
            $pdf->text($chartX + 10, $chartY + $chartHeight - 10, 'Not enough attendance data to plot the trend.', 'regular', 9, $this->color('textMuted'));
        }

        $stats = $this->summariseAttendanceStats($series);
        if ($stats['hasData']) {
            $summaryParts = [
                sprintf('Avg %s', $stats['average']),
                sprintf('Peak %s (%s)', $stats['max']['value'], $stats['max']['date']),
                sprintf('Low %s (%s)', $stats['min']['value'], $stats['min']['date']),
                sprintf('%d days tracked', $stats['days']),
            ];
            $summary = implode(' | ', $summaryParts);
            $pdf->text($margins['left'], $chartY + $chartHeight + 40, $summary, 'regular', 9, $this->color('textDark'));
        }

        return $chartY + $chartHeight + 60;
    }

    protected function renderDeliveryBreakdown(SimplePdfDocument $pdf, array $rows, array $margins, float $cursorY, float $usableWidth): float
    {
        $rowsCount = max(1, count($rows));
        $neededHeight = 20 + ($rowsCount * 36) + 8;
        $this->ensureSpace($pdf, $cursorY, $neededHeight, $margins, $usableWidth);
        $pdf->text($margins['left'], $cursorY + 12, 'Delivery mode split', 'bold', 12, $this->color('textDark'));
        $cursorY += 20;

        if (empty($rows)) {
            $pdf->text($margins['left'], $cursorY + 12, 'No delivery data available for this range.', 'regular', 10, $this->color('textMuted'));
            return $cursorY + 24;
        }

        $total = array_sum(array_map(fn ($row) => (int) $row['sessions'], $rows));
        $barWidth = $usableWidth - 150;

        foreach ($rows as $row) {
            $share = $total > 0 ? round(($row['sessions'] / $total) * 100) : 0;
            $pdf->text($margins['left'], $cursorY + 12, $row['mode_label'] ?? 'Mode', 'bold', 11, $this->color('textDark'));
            $pdf->text(
                $margins['left'],
                $cursorY + 26,
                sprintf('%d sessions · %d%% share', (int) $row['sessions'], $share),
                'regular',
                9,
                $this->color('textMuted')
            );

            $barX = $margins['left'] + 150;
            $barY = $cursorY + 10;
            $pdf->rect($barX, $barY, $barWidth, 10, $this->color('cardBorder'), 0.4);
            $width = max(4, ($barWidth * ($share / 100)));
            $pdf->filledRect($barX, $barY, $width, 10, $this->color('primary'));
            $cursorY += 36;
        }

        return $cursorY + 14;
    }

    protected function renderTeacherBreakdown(SimplePdfDocument $pdf, array $rows, array $margins, float $cursorY, float $usableWidth): float
    {
        $top = array_slice($rows, 0, 5);
        $rowsCount = max(1, count($top));
        $neededHeight = 18 + ($rowsCount * 34) + 8;
        $this->ensureSpace($pdf, $cursorY, $neededHeight, $margins, $usableWidth);
        $pdf->text($margins['left'], $cursorY + 12, 'Top teachers by session volume', 'bold', 12, $this->color('textDark'));
        $cursorY += 18;

        if (empty($top)) {
            $pdf->text($margins['left'], $cursorY + 12, 'No teacher data available.', 'regular', 10, $this->color('textMuted'));
            return $cursorY + 24;
        }

        $totalSessions = max(1, array_sum(array_map(fn ($row) => (int) $row['sessions'], $rows)));
        $maxSessions = max(array_map(fn ($row) => (int) $row['sessions'], $top));
        $barWidth = $usableWidth - 180;

        foreach ($top as $row) {
            $sessions = (int) ($row['sessions'] ?? 0);
            $share = $sessions / $totalSessions;

            $pdf->text($margins['left'], $cursorY + 12, $row['teacher_name'] ?? 'Teacher', 'bold', 11, $this->color('textDark'));
            $pdf->text(
                $margins['left'],
                $cursorY + 24,
                sprintf('%d sessions | %s', $sessions, $this->formatPercent($share * 100)),
                'regular',
                9,
                $this->color('textMuted')
            );

            $ratio = $totalSessions > 0 ? $sessions / $totalSessions : 0;
            $barX = $margins['left'] + 180;
            $barY = $cursorY + 8;
            $pdf->rect($barX, $barY, $barWidth, 10, $this->color('cardBorder'), 0.4);
            $pdf->filledRect($barX, $barY, max(4, $barWidth * $ratio), 10, $this->color('barAlt'));
            $cursorY += 34;
        }

        return $cursorY + 14;
    }

    protected function renderAppointmentTypes(SimplePdfDocument $pdf, array $rows, array $margins, float $cursorY, float $usableWidth): float
    {
        $top = array_slice($rows, 0, 6);
        $rowsCount = max(1, count($top));
        $neededHeight = 18 + ($rowsCount * 18) + 6;
        $this->ensureSpace($pdf, $cursorY, $neededHeight, $margins, $usableWidth);
        $pdf->text($margins['left'], $cursorY + 12, 'Appointment types (attendance)', 'bold', 12, $this->color('textDark'));
        $cursorY += 18;

        if (empty($top)) {
            $pdf->text($margins['left'], $cursorY + 12, 'No appointment type data captured.', 'regular', 10, $this->color('textMuted'));
            return $cursorY + 28;
        }

        $tableWidth = $usableWidth;
        $colType = $tableWidth * 0.55;
        $colAttendance = $tableWidth * 0.2;
        $colSessions = $tableWidth - $colType - $colAttendance;
        $rowHeight = 22;

        $pdf->filledRect($margins['left'], $cursorY, $tableWidth, $rowHeight, $this->color('chartBg'));
        $pdf->text($margins['left'] + 6, $cursorY + 14, 'Appointment type', 'bold', 10, $this->color('textDark'));
        $pdf->text($margins['left'] + $colType + 6, $cursorY + 14, 'Attendance', 'bold', 10, $this->color('textDark'));
        $pdf->text($margins['left'] + $colType + $colAttendance + 6, $cursorY + 14, 'Sessions', 'bold', 10, $this->color('textDark'));
        $cursorY += $rowHeight;

        foreach ($top as $row) {
            $pdf->rect($margins['left'], $cursorY, $tableWidth, $rowHeight, $this->color('cardBorder'), 0.4);
            $label = $this->truncateTextToWidth($row['name'] ?? 'Type', 'regular', 10, $colType - 18);
            $attendance = $this->formatPercent($row['attendance_pct'] ?? null);
            $pdf->text($margins['left'] + 6, $cursorY + 14, $label, 'regular', 10, $this->color('textDark'));
            $pdf->text($margins['left'] + $colType + 6, $cursorY + 14, $attendance, 'bold', 10, $this->color('barSecondary'));
            $pdf->text($margins['left'] + $colType + $colAttendance + 6, $cursorY + 14, number_format((int) ($row['sessions'] ?? 0)), 'regular', 10, $this->color('textDark'));
            $cursorY += $rowHeight;
        }

        return $cursorY + 12;
    }

    protected function renderCalendarTable(SimplePdfDocument $pdf, array $rows, array $margins, float $cursorY, float $usableWidth): float
    {
        $top = array_slice($rows, 0, 4);
        $rowHeight = 20;
        $rowsCount = max(1, count($top));
        $neededHeight = 16 + 20 + ($rowsCount * $rowHeight) + 10;
        $this->ensureSpace($pdf, $cursorY, $neededHeight, $margins, $usableWidth);
        $pdf->text($margins['left'], $cursorY + 12, 'Calendar overview', 'bold', 12, $this->color('textDark'));
        $cursorY += 16;

        if (empty($top)) {
            $pdf->text($margins['left'], $cursorY + 12, 'No calendar data for this date range.', 'regular', 10, $this->color('textMuted'));
            return $cursorY + 24;
        }

        $tableWidth = $usableWidth;
        $colCalendar = $tableWidth * 0.5;
        $colSessions = $tableWidth * 0.2;
        $colAttendance = $tableWidth * 0.3;

        $headerY = $cursorY;
        $pdf->filledRect($margins['left'], $headerY, $tableWidth, $rowHeight, $this->color('chartBg'));
        $pdf->text($margins['left'] + 6, $headerY + 14, 'Calendar', 'bold', 10, $this->color('textDark'));
        $pdf->text($margins['left'] + $colCalendar + 6, $headerY + 14, 'Sessions', 'bold', 10, $this->color('textDark'));
        $pdf->text($margins['left'] + $colCalendar + $colSessions + 6, $headerY + 14, 'Avg attendance', 'bold', 10, $this->color('textDark'));
        $cursorY += $rowHeight;

        foreach ($top as $row) {
            $pdf->rect($margins['left'], $cursorY, $tableWidth, $rowHeight, $this->color('cardBorder'), 0.4);
            $pdf->text($margins['left'] + 6, $cursorY + 14, $row['calendar_name'] ?? 'Calendar', 'regular', 10, $this->color('textDark'));
            $pdf->text(
                $margins['left'] + $colCalendar + 6,
                $cursorY + 14,
                number_format((int) $row['sessions']),
                'regular',
                10,
                $this->color('textDark')
            );
            $pdf->text(
                $margins['left'] + $colCalendar + $colSessions + 6,
                $cursorY + 14,
                $this->formatPercent($row['attendance_pct'] ?? null),
                'regular',
                10,
                $this->color('textDark')
            );
            $cursorY += $rowHeight;
        }

        return $cursorY + 14;
    }

    protected function renderAttentionSection(SimplePdfDocument $pdf, ?array $lowest, array $pending, array $margins, float $cursorY, float $usableWidth): float
    {
        $pendingCount = max(1, count($pending));
        $blocks = ($lowest ? 1 : 0) + $pendingCount;
        $neededHeight = 18 + ($blocks * 22) + 16;
        $this->ensureSpace($pdf, $cursorY, $neededHeight, $margins, $usableWidth);
        $pdf->text($margins['left'], $cursorY + 12, 'Sessions needing attention', 'bold', 12, $this->color('textDark'));
        $cursorY += 18;

        if ($lowest) {
            $label = sprintf(
                '%s · %s · %s',
                Carbon::parse($lowest['session_date'])->format('d M Y'),
                $lowest['calendar_label'] ?? 'Calendar',
                $lowest['appointment_type'] ?? 'Appointment'
            );
            $pdf->text($margins['left'], $cursorY + 12, $label, 'regular', 10, $this->color('textDark'));
            $pdf->text(
                $margins['left'] + $usableWidth - 80,
                $cursorY + 12,
                $this->formatPercent($lowest['attendance_rate'] ?? null),
                'bold',
                10,
                $this->color('primary')
            );
            $cursorY += 20;
        } else {
            $pdf->text($margins['left'], $cursorY + 12, 'No low-attendance sessions detected.', 'regular', 10, $this->color('textMuted'));
            $cursorY += 20;
        }

        if (! empty($pending)) {
            $pdf->text($margins['left'], $cursorY + 12, 'Pending absence follow-ups', 'bold', 11, $this->color('textDark'));
            $cursorY += 16;
            foreach ($pending as $row) {
                $this->ensureSpace($pdf, $cursorY, 20, $margins, $usableWidth);
                $label = sprintf(
                    '%s · %s',
                    Carbon::parse($row['session_date'])->format('d M Y'),
                    $row['teacher']['name'] ?? 'Teacher'
                );
                $pdf->text($margins['left'], $cursorY + 12, $label, 'regular', 10, $this->color('textDark'));
                $pdf->text(
                    $margins['left'] + $usableWidth - 60,
                    $cursorY + 12,
                    sprintf('%dx pending', (int) $row['pending_absence_count']),
                    'regular',
                    10,
                    $this->color('textMuted')
                );
                $cursorY += 18;
            }
        } else {
            $pdf->text($margins['left'], $cursorY + 12, 'No pending absence follow-ups on this range.', 'regular', 10, $this->color('textMuted'));
            $cursorY += 20;
        }

        return $cursorY + 14;
    }

    protected function buildRangeLabel(array $filters): string
    {
        $from = Carbon::parse($filters['from_date'] ?? Carbon::now());
        $to = Carbon::parse($filters['to_date'] ?? Carbon::now());

        return sprintf('%s - %s', $from->format('d M Y'), $to->format('d M Y'));
    }

    protected function summariseFilters(array $report): string
    {
        $filters = $report['filters'];
        $calendarLabel = $this->summariseMulti($filters['calendar_ids'] ?? []);
        $appointmentLabel = $this->summariseMulti($filters['appointment_type_ids'] ?? []);
        $teacherId = $filters['teacher_id'] ?? null;
        $teacherName = null;
        if ($teacherId && isset($report['teacherOptions'][(string) $teacherId])) {
            $teacherName = $report['teacherOptions'][(string) $teacherId];
        }

        $parts = [];
        $parts[] = 'Delivery: ' . ($filters['delivery_mode'] === 'all' ? 'All modes' : str_replace('_', ' ', ucfirst($filters['delivery_mode'])));
        $parts[] = 'Calendars: ' . ($calendarLabel ?: 'All');
        $parts[] = 'Appointments: ' . ($appointmentLabel ?: 'All');
        $parts[] = 'Teacher: ' . ($teacherName ?: 'All');

        return implode(' · ', $parts);
    }

    protected function summariseMulti($value): string
    {
        $values = array_filter(array_map('trim', (array) $value));
        if (empty($values)) {
            return '';
        }

        if (count($values) === 1) {
            return $values[0];
        }

        return sprintf('%s +%d', $values[0], count($values) - 1);
    }

    protected function ensureSpace(SimplePdfDocument $pdf, float &$cursorY, float $needed, array $margins, float $usableWidth): void
    {
        $limit = $pdf->getHeight() - $margins['bottom'];
        if ($cursorY + $needed > $limit) {
            $this->startPage($pdf, $cursorY, $margins, $usableWidth);
        }
    }

    protected function color(string $key): array
    {
        return $this->palette[$key] ?? [0.0, 0.0, 0.0];
    }

    protected function formatPercent(?float $value): string
    {
        return is_null($value) ? '—' : number_format($value, 1) . '%';
    }

    protected function includes(string $key): bool
    {
        return in_array($key, $this->sections, true);
    }

    protected function startPage(SimplePdfDocument $pdf, float &$cursorY, array $margins, float $usableWidth): void
    {
        $pdf->addPage();
        $this->pageNumber++;
        $cursorY = $margins['top'];
        $cursorY = $this->renderPageHeader($pdf, $cursorY, $margins, $usableWidth);
        $this->renderPageFooter($pdf, $margins, $usableWidth);
    }

    protected function renderSessionsPerDay(SimplePdfDocument $pdf, array $series, array $margins, float $cursorY, float $usableWidth): float
    {
        if (empty($series)) {
            $this->ensureSpace($pdf, $cursorY, 40, $margins, $usableWidth);
            $pdf->text($margins['left'], $cursorY + 12, 'Sessions per day', 'bold', 12, $this->color('textDark'));
            $pdf->text($margins['left'], $cursorY + 28, 'No session activity recorded for this window.', 'regular', 9, $this->color('textMuted'));
            return $cursorY + 44;
        }

        $chartHeight = 160;
        $titleAdvance = 36;
        $axisLabelArea = 14;
        $trailing = 14;
        $sectionHeight = $titleAdvance + $chartHeight + $axisLabelArea + $trailing;

        $this->ensureSpace($pdf, $cursorY, $sectionHeight, $margins, $usableWidth);

        $pdf->text($margins['left'], $cursorY + 12, 'Sessions per day', 'bold', 12, $this->color('textDark'));
        $pdf->text($margins['left'], $cursorY + 26, 'Daily session volume across the selected window', 'regular', 9, $this->color('textMuted'));
        $cursorY += $titleAdvance;

        $chartX = $margins['left'];
        $chartY = $cursorY;
        $pdf->filledRect($chartX, $chartY, $usableWidth, $chartHeight, $this->color('chartBg'));
        $pdf->rect($chartX, $chartY, $usableWidth, $chartHeight, $this->color('cardBorder'), 0.5);

        $count = count($series);
        $maxSessions = max(array_map(fn ($point) => max(0, (int) ($point['sessions'] ?? 0)), $series));

        if ($maxSessions > 0) {
            $barSpacing = $usableWidth / max($count, 1);
            $barWidth = max(1.0, $barSpacing * 0.8);

            foreach ($series as $index => $point) {
                $sessions = (int) ($point['sessions'] ?? 0);
                if ($sessions <= 0) {
                    continue;
                }
                $height = ($sessions / $maxSessions) * ($chartHeight - 30);
                $x = $chartX + ($index * $barSpacing) + (($barSpacing - $barWidth) / 2);
                $y = $chartY + ($chartHeight - $height) - 20;
                $pdf->filledRect($x, $y, $barWidth, $height, $this->color('barAlt'));
            }

            $yTickCount = (int) min(5, $maxSessions);
            $yTickCount = max(1, $yTickCount);
            for ($i = 0; $i <= $yTickCount; $i++) {
                $value = (int) round(($i / $yTickCount) * $maxSessions);
                $ratio = $maxSessions > 0 ? $value / $maxSessions : 0;
                $y = $chartY + $chartHeight - 20 - (($chartHeight - 30) * $ratio);
                $pdf->line($chartX, $y, $chartX + $usableWidth, $y, $this->color('grid'), 0.3);
                $pdf->text($chartX - 6, $y + 4, (string) $value, 'regular', 8, $this->color('textMuted'));
            }

            $xLabelCount = min(8, max(2, $count >= 60 ? 8 : ($count >= 14 ? 6 : 3)));
            $indexes = [];
            for ($i = 0; $i < $xLabelCount; $i++) {
                $ratio = $xLabelCount > 1 ? $i / ($xLabelCount - 1) : 0;
                $indexes[] = (int) round($ratio * ($count - 1));
            }
            $indexes = array_values(array_unique($indexes));
            foreach ($indexes as $idx) {
                $point = $series[$idx];
                $x = $chartX + ($idx * $barSpacing) + ($barSpacing / 2);
                $text = Carbon::parse($point['date'])->format('d M');
                $pdf->text($x - 12, $chartY + $chartHeight + 8, $text, 'regular', 8, $this->color('textMuted'));
            }
        } else {
            $pdf->text($chartX + 10, $chartY + $chartHeight - 10, 'Not enough session data to plot.', 'regular', 9, $this->color('textMuted'));
        }

        return $chartY + $chartHeight + $axisLabelArea + $trailing;
    }

    protected function renderPageHeader(SimplePdfDocument $pdf, float $cursorY, array $margins, float $usableWidth): float
    {
        $x = $margins['left'];
        $y = $cursorY;
        $logoWidthPt = 0.0;
        $logoHeightPt = 0.0;

        if ($pdf->hasImage($this->logoImageName)) {
            $pxToPt = 0.75;
            $logoWidthPt = $this->logoImage['width'] * $pxToPt;
            $logoHeightPt = $this->logoImage['height'] * $pxToPt;
            $maxWidth = 108.0;
            if ($logoWidthPt > $maxWidth) {
                $scale = $maxWidth / $logoWidthPt;
                $logoWidthPt *= $scale;
                $logoHeightPt *= $scale;
            }

            $logoWidthPt *= 0.75;
            $logoHeightPt *= 0.75;
        }

        $headerHeight = max(78.0, $logoHeightPt + 28.0);

        $pdf->filledRect($x, $y, $usableWidth, $headerHeight, $this->color('cardBg'));
        $pdf->rect($x, $y, $usableWidth, $headerHeight, $this->color('cardBorder'), 0.8);

        if ($pdf->hasImage($this->logoImageName)) {
            $pdf->image($this->logoImageName, $x + 18, $y + 16, $logoWidthPt, $logoHeightPt);
        } else {
            $pdf->filledRect($x + 14, $y + 16, 54, 26, $this->color('primary'));
            $pdf->text($x + 22, $y + 34, 'Lumina', 'bold', 16, [1, 1, 1]);
        }

        $rightX = $x + $usableWidth - 20;
        $lines = [
            ['text' => 'Language Academy  |  UK Attendance Report', 'font' => 'bold', 'size' => 11, 'color' => $this->color('textDark')],
            ['text' => $this->rangeLabel, 'font' => 'bold', 'size' => 10, 'color' => $this->color('textDark')],
            ['text' => $this->filterSummary, 'font' => 'regular', 'size' => 9, 'color' => $this->color('textMuted')],
        ];

        $lineSpacing = 16.0;
        $blockHeight = $lineSpacing * (count($lines) - 1);
        $startY = $y + ($headerHeight / 2) - ($blockHeight / 2);

        foreach ($lines as $index => $line) {
            $lineY = $startY + ($index * $lineSpacing);
            $this->drawRightAlignedText(
                $pdf,
                $rightX,
                $lineY,
                $line['text'],
                $line['font'],
                $line['size'],
                $line['color']
            );
        }
        $pdf->line($x, $y + $headerHeight, $x + $usableWidth, $y + $headerHeight, $this->color('primary'), 1.2);

        return $y + $headerHeight + 12;
    }

    protected function renderPageFooter(SimplePdfDocument $pdf, array $margins, float $usableWidth): void
    {
        $y = $pdf->getHeight() - $margins['bottom'] + 24;
        $pdf->line($margins['left'], $y - 12, $margins['left'] + $usableWidth, $y - 12, $this->color('cardBorder'), 0.6);

        $pdf->text($margins['left'], $y, 'Lumina Language Academy', 'bold', 8, $this->color('primary'));

        $centerX = $margins['left'] + ($usableWidth / 2);
        $this->drawCenteredText($pdf, $centerX, $y, 'Confidential', 'regular', 8, $this->color('textMuted'));

        $rightX = $margins['left'] + $usableWidth;
        $rightText = sprintf('Page %d | Generated %s', $this->pageNumber, $this->generatedAt);
        $this->drawRightAlignedText($pdf, $rightX, $y, $rightText, 'regular', 8, $this->color('textMuted'));
    }

    protected function registerLogoImage(SimplePdfDocument $pdf): void
    {
        if ($this->logoImage) {
            return;
        }

        $payload = $this->loadLogoImageFromPublic() ?? $this->decodeFallbackLogo();
        if (! $payload) {
            return;
        }

        $pdf->registerImage(
            $this->logoImageName,
            $payload['data'],
            $payload['width'],
            $payload['height'],
            $payload['colorSpace'],
            $payload['filter'],
            $payload['mask'] ?? null,
            $payload['bitsPerComponent'] ?? 8
        );

        $this->logoImage = [
            'name' => $this->logoImageName,
            'width' => $payload['width'],
            'height' => $payload['height'],
        ];
    }

    protected function loadLogoImageFromPublic(): ?array
    {
        $candidates = [
            'assest/logo-demo.svg',
            'assets/logo-demo.svg',
            'logo-demo.svg',
        ];

        foreach ($candidates as $relativePath) {
            $path = public_path($relativePath);
            if (! $path || ! is_readable($path)) {
                continue;
            }

            $binary = @file_get_contents($path);
            if ($binary === false) {
                continue;
            }

            $payload = $this->normaliseLogoBinary($binary);
            if ($payload) {
                return $payload;
            }
        }

        return null;
    }

    protected function normaliseLogoBinary(string $binary): ?array
    {
        $meta = @getimagesizefromstring($binary);
        if ($meta === false) {
            return null;
        }

        $width = (int) ($meta[0] ?? 0);
        $height = (int) ($meta[1] ?? 0);
        if ($width === 0 || $height === 0) {
            return null;
        }

        $mime = $meta['mime'] ?? '';
        if ($mime === 'image/jpeg') {
            return [
                'data' => $binary,
                'width' => $width,
                'height' => $height,
                'colorSpace' => '/DeviceRGB',
                'filter' => '/DCTDecode',
                'bitsPerComponent' => 8,
            ];
        }

        if ($mime === 'image/png') {
            return $this->rasterizePngToPdfPayload($binary, $width, $height);
        }

        return null;
    }

    protected function rasterizePngToPdfPayload(string $binary, int $width, int $height): ?array
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            return null;
        }

        $source = @imagecreatefromstring($binary);
        if (! $source) {
            return null;
        }

        $sourceWidth = imagesx($source) ?: $width;
        $sourceHeight = imagesy($source) ?: $height;

        $canvas = imagecreatetruecolor($sourceWidth, $sourceHeight);
        if (! $canvas) {
            imagedestroy($source);
            return null;
        }

        imagesavealpha($source, true);
        imagealphablending($source, false);

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
        imagealphablending($canvas, true);
        imagecopy($canvas, $source, 0, 0, 0, 0, $sourceWidth, $sourceHeight);

        $maskBytes = '';
        for ($y = 0; $y < $sourceHeight; $y++) {
            for ($x = 0; $x < $sourceWidth; $x++) {
                $rgba = imagecolorat($source, $x, $y);
                $alpha = ($rgba & 0x7F000000) >> 24;
                $opacity = (int) round((127 - $alpha) * 255 / 127);
                $maskBytes .= chr($opacity);
            }
        }

        if (function_exists('gzcompress')) {
            $compressed = @gzcompress($maskBytes);
            $maskData = $compressed !== false ? $compressed : $maskBytes;
        } else {
            $maskData = $maskBytes;
        }

        ob_start();
        imagejpeg($canvas, null, 92);
        $jpegData = ob_get_clean();

        imagedestroy($source);
        imagedestroy($canvas);

        if ($jpegData === false || $jpegData === null) {
            return null;
        }

        return [
            'data' => $jpegData,
            'width' => $sourceWidth,
            'height' => $sourceHeight,
            'colorSpace' => '/DeviceRGB',
            'filter' => '/DCTDecode',
            'mask' => [
                'data' => $maskData,
                'width' => $sourceWidth,
                'height' => $sourceHeight,
                'filter' => $maskData !== $maskBytes ? '/FlateDecode' : null,
                'bitsPerComponent' => 8,
            ],
        ];
    }

    protected function decodeFallbackLogo(): ?array
    {
        $data = base64_decode(self::FALLBACK_LOGO_JPEG_BASE64, true);
        if ($data === false) {
            return null;
        }

        $meta = @getimagesizefromstring($data);
        if ($meta === false) {
            return null;
        }

        $width = (int) ($meta[0] ?? 0);
        $height = (int) ($meta[1] ?? 0);
        if ($width === 0 || $height === 0) {
            return null;
        }

        return [
            'data' => $data,
            'width' => $width,
            'height' => $height,
            'colorSpace' => '/DeviceRGB',
            'filter' => '/DCTDecode',
            'bitsPerComponent' => 8,
        ];
    }

    protected function drawCenteredText(SimplePdfDocument $pdf, float $centerX, float $y, string $text, string $font, float $size, array $color): void
    {
        $width = $this->estimateTextWidth($text, $font, $size);
        $pdf->text($centerX - ($width / 2), $y, $text, $font, $size, $color);
    }

    /**
     * @return array<int, string>
     */
    protected function wrapTextToWidth(string $text, string $font, float $size, float $maxWidth): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        if (empty($words)) {
            return [''];
        }

        $lines = [''];
        foreach ($words as $word) {
            $candidate = trim($lines[array_key_last($lines)] . ' ' . $word);
            if ($candidate === '') {
                $lines[array_key_last($lines)] = $word;
                continue;
            }

            if ($this->estimateTextWidth($candidate, $font, $size) <= $maxWidth) {
                $lines[array_key_last($lines)] = $candidate;
            } else {
                $lines[] = $word;
            }
        }

        return array_map('trim', $lines);
    }

    protected function truncateTextToWidth(string $text, string $font, float $size, float $maxWidth): string
    {
        if ($this->estimateTextWidth($text, $font, $size) <= $maxWidth) {
            return $text;
        }

        $ellipsis = '...';
        $ellipsisWidth = $this->estimateTextWidth($ellipsis, $font, $size);
        $targetWidth = max(0, $maxWidth - $ellipsisWidth);
        $buffer = '';
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($chars as $char) {
            $candidate = $buffer . $char;
            if ($this->estimateTextWidth($candidate, $font, $size) > $targetWidth) {
                break;
            }
            $buffer = $candidate;
        }

        return rtrim($buffer) . $ellipsis;
    }

    protected function drawRightAlignedText(SimplePdfDocument $pdf, float $rightX, float $y, string $text, string $font, float $size, array $color): void
    {
        $width = $this->estimateTextWidth($text, $font, $size);
        $pdf->text($rightX - $width, $y, $text, $font, $size, $color);
    }

    protected function drawDottedLine(SimplePdfDocument $pdf, float $x1, float $x2, float $y, array $color, float $lineWidth = 0.3): void
    {
        $segment = 6.0;
        for ($x = $x1; $x < $x2; $x += $segment) {
            $end = min($x + 3.0, $x2);
            $pdf->line($x, $y, $end, $y, $color, $lineWidth);
        }
    }

    protected function estimateTextWidth(string $text, string $font, float $size): float
    {
        $base = $font === 'bold' ? 0.58 : 0.54;
        $overrides = [
            ' ' => 0.28,
            '.' => 0.28,
            ',' => 0.28,
            'i' => 0.28,
            'l' => 0.32,
            't' => 0.38,
            'f' => 0.38,
            'r' => 0.4,
            'I' => 0.32,
            '1' => 0.5,
            '0' => 0.56,
            'W' => 0.95,
            'M' => 0.92,
            'w' => 0.85,
            'm' => 0.83,
            'A' => 0.68,
            'V' => 0.75,
            'Y' => 0.73,
        ];

        $total = 0.0;
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($chars as $char) {
            if (isset($overrides[$char])) {
                $total += $overrides[$char];
                continue;
            }

            if (strlen($char) === 1 && ctype_upper($char)) {
                $total += $base + 0.05;
                continue;
            }

            if (strlen($char) === 1 && ctype_lower($char)) {
                $total += $base - 0.04;
                continue;
            }

            if (strlen($char) === 1 && ctype_digit($char)) {
                $total += $base;
                continue;
            }

            $total += $base;
        }

        return max($total * $size, 0.0);
    }

    protected function drawKpiIcon(SimplePdfDocument $pdf, string $icon, float $x, float $y, float $size): void
    {
        $stroke = $this->color('textDark');
        $muted = $this->color('textMuted');

        switch ($icon) {
            case 'calendar':
                $pdf->rect($x, $y, $size, $size, $stroke, 1);
                $pdf->line($x, $y + 7, $x + $size, $y + 7, $stroke, 1);
                $pdf->line($x + 4, $y + 2, $x + 4, $y + 10, $stroke, 1);
                $pdf->line($x + $size - 4, $y + 2, $x + $size - 4, $y + 10, $stroke, 1);
                $pdf->line($x + 6, $y + 14, $x + $size - 6, $y + 14, $muted, 0.8);
                $pdf->line($x + 6, $y + 20, $x + $size - 6, $y + 20, $muted, 0.8);
                $pdf->line($x + 6, $y + 26, $x + $size - 6, $y + 26, $muted, 0.8);
                break;
            case 'trend':
                $axisY = $y + $size - 4;
                $pdf->line($x + 2, $axisY, $x + $size - 2, $axisY, $muted, 0.8);
                $pdf->line($x + 2, $y + 4, $x + 2, $axisY, $muted, 0.8);
                $points = [
                    [$x + 2, $axisY - 2],
                    [$x + 8, $axisY - 10],
                    [$x + 16, $axisY - 6],
                    [$x + $size - 4, $axisY - 16],
                ];
                $pdf->polyline($points, $stroke, 1.4);
                $pdf->line($x + $size - 6, $axisY - 18, $x + $size - 2, $axisY - 14, $stroke, 1.4);
                break;
            case 'users':
                $headWidth = 8;
                $headHeight = 6;
                $center = $x + ($size / 2);
                $pdf->rect($center - ($headWidth / 2), $y + 4, $headWidth, $headHeight, $stroke, 1);
                $pdf->rect($center - 10, $y + 14, 20, 6, $stroke, 1);
                $pdf->line($center - 12, $y + 24, $center + 12, $y + 24, $stroke, 1.2);
                $pdf->line($center - 6, $y + 18, $center - 6, $y + 26, $stroke, 1);
                $pdf->line($center + 6, $y + 18, $center + 6, $y + 26, $stroke, 1);
                break;
            case 'envelope':
                $envX = $x + 2;
                $envY = $y + 6;
                $envW = $size - 4;
                $envH = $size - 10;
                $pdf->rect($envX, $envY, $envW, $envH, $stroke, 1);
                $pdf->line($envX, $envY, $envX + ($envW / 2), $envY + ($envH / 2), $stroke, 1);
                $pdf->line($envX + $envW, $envY, $envX + ($envW / 2), $envY + ($envH / 2), $stroke, 1);
                break;
            case 'user-plus':
                $headWidth = 7;
                $headHeight = 5;
                $figureCenter = $x + ($size / 2) - 5;
                $pdf->rect($figureCenter - ($headWidth / 2), $y + 5, $headWidth, $headHeight, $stroke, 1);
                $pdf->rect($figureCenter - 7, $y + 13, 14, 5, $stroke, 1);
                $pdf->line($figureCenter - 9, $y + 23, $figureCenter + 9, $y + 23, $stroke, 1.2);
                $pdf->line($figureCenter - 4, $y + 18, $figureCenter - 4, $y + 25, $stroke, 1);
                $pdf->line($figureCenter + 4, $y + 18, $figureCenter + 4, $y + 25, $stroke, 1);
                $plusX = $x + $size - 5;
                $plusY = $y + 7;
                $pdf->line($plusX - 3, $plusY, $plusX + 3, $plusY, $stroke, 1.4);
                $pdf->line($plusX, $plusY - 3, $plusX, $plusY + 3, $stroke, 1.4);
                break;
            default:
                $pdf->line($x, $y + ($size / 2), $x + $size, $y + ($size / 2), $stroke, 1);
                break;
        }
    }

    protected function summariseAttendanceStats(array $series): array
    {
        $points = [];
        foreach ($series as $point) {
            if (is_null($point['attendance_pct'] ?? null)) {
                continue;
            }
            $points[] = [
                'value' => max(0, min(100, (float) $point['attendance_pct'])),
                'date' => Carbon::parse($point['date'])->format('d M'),
            ];
        }

        if (empty($points)) {
            return [
                'hasData' => false,
                'average' => '—',
                'max' => ['value' => '—', 'date' => '—'],
                'min' => ['value' => '—', 'date' => '—'],
                'days' => 0,
            ];
        }

        $values = array_column($points, 'value');
        $average = array_sum($values) / count($values);
        $maxPoint = $points[array_search(max($values), $values, true)];
        $minPoint = $points[array_search(min($values), $values, true)];

        return [
            'hasData' => true,
            'average' => $this->formatPercent($average),
            'max' => [
                'value' => $this->formatPercent($maxPoint['value']),
                'date' => $maxPoint['date'],
            ],
            'min' => [
                'value' => $this->formatPercent($minPoint['value']),
                'date' => $minPoint['date'],
            ],
            'days' => count($series),
        ];
    }
}
