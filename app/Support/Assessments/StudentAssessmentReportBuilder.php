<?php

namespace App\Support\Assessments;

use App\Models\StudentAssessment;
use App\Support\Reporting\Pdf\SimplePdfDocument;
use Carbon\CarbonInterface;

class StudentAssessmentReportBuilder
{
    protected SimplePdfDocument $pdf;

    protected float $leftMargin = 48.0;
    protected float $rightMargin = 48.0;
    protected float $topMargin = 60.0;
    protected float $bottomMargin = 48.0;
    protected float $sectionSpacing = 28.0;
    protected float $questionSpacing = 18.0;

    protected array $palette = [
        'primary' => [198 / 255, 11 / 255, 11 / 255],
        'textDark' => [31 / 255, 41 / 255, 55 / 255],
        'textMuted' => [107 / 255, 114 / 255, 128 / 255],
        'cardBg' => [248 / 255, 249 / 255, 251 / 255],
        'cardBorder' => [226 / 255, 232 / 255, 240 / 255],
        'questionBg' => [1, 1, 1],
    ];

    public function build(StudentAssessment $assessment): string
    {
        $assessment->loadMissing(['student', 'template', 'assessedBy', 'answers.question']);

        $this->pdf = new SimplePdfDocument();
        $this->pdf->addPage();

        $cursorY = $this->topMargin;

        $cursorY = $this->writeHeader($assessment, $cursorY);

        if ($assessment->overall_comments) {
            $cursorY += 16;
            $cursorY = $this->writeComments($assessment->overall_comments, $cursorY);
        }

        $cursorY += 20;
        $cursorY = $this->writeSections($assessment, $cursorY);

        return $this->pdf->output();
    }

    protected function writeHeader(StudentAssessment $assessment, float $cursorY): float
    {
        $this->ensureSpace($cursorY, 160.0);

        $studentName = trim((string) ($assessment->student?->full_name ?? 'Student'));
        $templateName = trim((string) ($assessment->template?->name ?? 'Template'));
        $assessedAt = $this->formatDate($assessment->assessed_at);
        $assessor = trim((string) ($assessment->assessedBy?->name ?? '—'));
        $average = $assessment->average_score !== null
            ? number_format((float) $assessment->average_score, 2)
            : '—';

        $this->pdf->text($this->leftMargin, $cursorY, 'Student Assessment Report', 'bold', 20, $this->palette['primary']);
        $cursorY += 34;

        $metadata = [
            ['label' => 'Student', 'value' => $studentName],
            ['label' => 'Template', 'value' => $templateName],
            ['label' => 'Assessed at', 'value' => $assessedAt],
            ['label' => 'Assessed by', 'value' => $assessor],
            ['label' => 'Average score', 'value' => sprintf('%s / 10', $average)],
        ];

        $boxPadding = 18.0;
        $rowHeight = 34.0;
        $boxWidth = $this->contentWidth();
        $boxHeight = ($boxPadding * 2) + (count($metadata) * $rowHeight);

        $this->pdf->filledRect($this->leftMargin, $cursorY, $boxWidth, $boxHeight, $this->color('cardBg'));
        $this->pdf->rect($this->leftMargin, $cursorY, $boxWidth, $boxHeight, $this->color('cardBorder'), 0.7);

        $textX = $this->leftMargin + $boxPadding;
        $textY = $cursorY + $boxPadding;

        foreach ($metadata as $row) {
            $this->pdf->text($textX, $textY, strtoupper($row['label']), 'bold', 9, $this->palette['textMuted']);
            $textY += 14;
            $this->pdf->text($textX, $textY, (string) $row['value'], 'regular', 12, $this->palette['textDark']);
            $textY += ($rowHeight - 14);
        }

        $cursorY += $boxHeight;

        return $cursorY + 24;
    }

    protected function writeComments(string $comments, float $cursorY): float
    {
        $lines = $this->wrapText($comments, 90);
        $rowHeight = 14;
        $textBlockHeight = max(1, count($lines)) * $rowHeight;
        $boxPadding = 14;
        $boxHeight = ($boxPadding * 2) + $textBlockHeight;

        $this->ensureSpace($cursorY, $boxHeight + 40);
        if ($cursorY === $this->topMargin) {
            $cursorY += 12;
        }

        $this->pdf->text($this->leftMargin, $cursorY, 'Overall comments', 'bold', 13, $this->palette['textDark']);
        $cursorY += 18;

        $this->pdf->filledRect($this->leftMargin, $cursorY, $this->contentWidth(), $boxHeight, $this->color('questionBg'));
        $this->pdf->rect($this->leftMargin, $cursorY, $this->contentWidth(), $boxHeight, $this->color('cardBorder'), 0.5);

        $textY = $cursorY + $boxPadding;
        foreach ($lines as $line) {
            $this->pdf->text($this->leftMargin + $boxPadding, $textY, $line, 'regular', 11, $this->palette['textDark']);
            $textY += $rowHeight;
        }

        $cursorY += $boxHeight;

        return $cursorY;
    }

    protected function writeSections(StudentAssessment $assessment, float $cursorY): float
    {
        $sections = $assessment->answers
            ->groupBy(function ($answer) {
                $question = $answer->question;
                $category = $question?->skill_category ?? null;

                if (! $category && $question?->section) {
                    $category = SkillCategory::fromSection($question->section);
                }

                return $category ?? SkillCategory::default();
            })
            ->map(fn ($answers, $category) => [
                'title' => SkillCategory::label($category ?? SkillCategory::default()),
                'items' => $answers->map(fn ($answer) => [
                    'question' => $answer->question?->question_text ?? 'Question',
                    'score' => $answer->score,
                    'notes' => $answer->notes,
                ])->values()->all(),
            ])
            ->values();

        foreach ($sections as $section) {
            $this->ensureSpace($cursorY, 80.0);
            $cursorY += ($cursorY === $this->topMargin) ? 14 : 10;

            $this->pdf->text($this->leftMargin, $cursorY, $section['title'], 'bold', 15, $this->palette['primary']);
            $cursorY += 10;
            $this->pdf->line(
                $this->leftMargin,
                $cursorY,
                $this->leftMargin + $this->contentWidth(),
                $cursorY,
                $this->color('cardBorder'),
                0.8,
            );
            $cursorY += 20;

            foreach ($section['items'] as $item) {
                $cursorY = $this->writeQuestion($item['question'], $item['score'], $item['notes'], $cursorY);
                $cursorY += $this->questionSpacing;
            }

            $cursorY += $this->sectionSpacing;
        }

        return $cursorY;
    }

    protected function writeQuestion(string $question, ?int $score, ?string $notes, float $cursorY): float
    {
        $questionLines = $this->wrapText($question, 85);
        $normalizedNotes = trim((string) ($notes ?? ''));
        $noteLines = $normalizedNotes !== '' ? $this->wrapText($normalizedNotes, 90) : [];

        $questionLineHeight = 18;
        $notesLineHeight = 14;
        $paddingY = 20;
        $paddingX = 18;
        $scoreHeight = 18;
        $notesLabelHeight = $noteLines ? 12 : 0;
        $questionToScoreGap = 10;
        $scoreToNotesGap = $noteLines ? 10 : 0;
        $notesLabelToTextGap = $noteLines ? 6 : 0;

        $blockHeight = ($paddingY * 2)
            + max(1, count($questionLines)) * $questionLineHeight
            + $questionToScoreGap
            + $scoreHeight
            + $scoreToNotesGap
            + $notesLabelHeight
            + $notesLabelToTextGap
            + (count($noteLines) * $notesLineHeight);

        $this->ensureSpace($cursorY, $blockHeight + 16);
        if ($cursorY === $this->topMargin) {
            $cursorY += 12;
        }

        $boxWidth = $this->contentWidth();
        $this->pdf->filledRect($this->leftMargin, $cursorY, $boxWidth, $blockHeight, $this->color('questionBg'));
        $this->pdf->rect($this->leftMargin, $cursorY, $boxWidth, $blockHeight, $this->color('cardBorder'), 0.6);

        $textX = $this->leftMargin + $paddingX;
        $textY = $cursorY + $paddingY;

        foreach ($questionLines as $line) {
            $this->pdf->text($textX, $textY, $line, 'bold', 12, $this->palette['textDark']);
            $textY += $questionLineHeight;
        }

        $textY += $questionToScoreGap;
        $scoreText = $score !== null ? sprintf('Score: %d / 10', $score) : 'Score: —';
        $this->pdf->text($textX, $textY, $scoreText, 'bold', 11, $this->palette['primary']);
        $textY += $scoreHeight;
        $textY += $scoreToNotesGap;

        if ($noteLines) {
            $this->pdf->text($textX, $textY, 'Notes', 'bold', 10, $this->palette['textMuted']);
            $textY += $notesLabelHeight;
            $textY += $notesLabelToTextGap;
            foreach ($noteLines as $line) {
                $this->pdf->text($textX + 10, $textY, $line, 'regular', 10, $this->palette['textDark']);
                $textY += $notesLineHeight;
            }
        }

        return $cursorY + $blockHeight;
    }

    /**
     * @return array<int, string>
     */
    protected function wrapText(string $text, int $width = 80): array
    {
        $clean = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($clean === '') {
            return [];
        }

        $wrapped = wordwrap($clean, $width, "\n", true);

        return explode("\n", $wrapped);
    }

    protected function ensureSpace(float &$cursorY, float $needed): void
    {
        $limit = $this->pdf->getHeight() - $this->bottomMargin;

        if ($cursorY + $needed <= $limit) {
            return;
        }

        $this->pdf->addPage();
        $cursorY = $this->topMargin;
    }

    protected function contentWidth(): float
    {
        return $this->pdf->getWidth() - $this->leftMargin - $this->rightMargin;
    }

    protected function color(string $key): array
    {
        return $this->palette[$key] ?? [0.0, 0.0, 0.0];
    }

    protected function formatDate(?CarbonInterface $date): string
    {
        if (! $date) {
            return '—';
        }

        $tzDate = $date->copy()->timezone(config('app.timezone'));

        return $tzDate->format('d M Y H:i');
    }
}
