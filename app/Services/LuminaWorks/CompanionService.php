<?php

namespace App\Services\LuminaWorks;

use Anthropic\Client;
use App\Models\LuminaWorksCompanionPack;
use App\Models\LuminaWorksJob;
use App\Models\Student;
use Illuminate\Support\Facades\Log;

/**
 * AI job coach for participants with limited English.
 *
 * PII boundary (DWP AI policy): the ONLY inputs ever sent to the LLM are the
 * vacancy text (title, employer, description — public job-board data) and the
 * coarse English band. Never the student's name, contact details, notes, or
 * any special-category data. Falls back to a deterministic template when no
 * API key is configured or the call fails, so the feature never breaks a demo.
 */
class CompanionService
{
    public function __construct(private EnglishBandResolver $bands)
    {
    }

    public function getOrCreatePack(Student $student, LuminaWorksJob $job): LuminaWorksCompanionPack
    {
        $band = $this->bands->resolve($student);

        $existing = LuminaWorksCompanionPack::where('student_id', $student->id)
            ->where('lumina_works_job_id', $job->id)
            ->first();

        if ($existing && $existing->english_band === $band) {
            return $existing;
        }

        [$content, $source] = $this->buildContent($job, $band);

        return LuminaWorksCompanionPack::updateOrCreate(
            ['student_id' => $student->id, 'lumina_works_job_id' => $job->id],
            ['english_band' => $band, 'source' => $source, 'content' => $content]
        );
    }

    /** @return array{0: array, 1: string} [content, source] */
    private function buildContent(LuminaWorksJob $job, string $band): array
    {
        if (config('services.anthropic.api_key')) {
            try {
                return [$this->buildWithLlm($job, $band), 'llm'];
            } catch (\Throwable $e) {
                Log::warning('Lumina Works companion LLM call failed, using fallback', [
                    'job_id' => $job->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [$this->buildFallback($job), 'fallback'];
    }

    private function buildWithLlm(LuminaWorksJob $job, string $band): array
    {
        $client = new Client(apiKey: (string) config('services.anthropic.api_key'));

        $bandDescription = match ($band) {
            EnglishBandResolver::BAND_LOW => 'very little English (CEFR pre-A1/A1); use only the simplest words and very short sentences',
            EnglishBandResolver::BAND_BASIC => 'basic English (CEFR A2); use simple, common words and short sentences',
            default => 'working English (CEFR B1); keep language clear and practical',
        };

        $jobText = mb_substr($job->title . "\nEmployer: " . ($job->employer_name ?? 'unknown') . "\n" . $job->description, 0, 4000);

        $message = $client->messages->create(
            model: (string) config('services.anthropic.model'),
            maxTokens: 4000,
            system: 'You are a job coach helping adult migrants in the UK with limited English keep and succeed in entry-level jobs. '
                . 'The learner has ' . $bandDescription . '. '
                . 'Respond ONLY with valid JSON, no markdown fences, using exactly this shape: '
                . '{"vocabulary":[{"term":"","meaning":""}],"phrases":[{"situation":"","phrase":""}],"first_day_tips":[""],"rights_note":""} '
                . 'vocabulary: 8-10 workplace words for THIS role with a one-line plain-English meaning. '
                . 'phrases: 6 ready-to-say sentences for real situations (greeting the supervisor, asking to repeat an instruction, reporting a problem, calling in sick, asking about the rota, asking for help). '
                . 'first_day_tips: 4 short practical tips for the first day in THIS role. '
                . 'rights_note: 2 sentences in simple English about UK basics: minimum wage, payslips, and asking questions is allowed.',
            messages: [[
                'role' => 'user',
                'content' => "Create the job coach pack for this vacancy:\n\n" . $jobText,
            ]],
        );

        // Take the last text block (skips any thinking blocks) and extract the
        // outermost JSON object, tolerating fences or preamble.
        $text = '';
        foreach ($message->content as $block) {
            if (($block->type ?? null) === 'text' && trim($block->text ?? '') !== '') {
                $text = trim($block->text);
            }
        }

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $decoded = ($start !== false && $end !== false && $end > $start)
            ? json_decode(substr($text, $start, $end - $start + 1), true)
            : null;

        if (!is_array($decoded) || !isset($decoded['vocabulary'], $decoded['phrases'])) {
            throw new \RuntimeException('Companion LLM returned unparseable content (stop: ' . ($message->stopReason ?? '?') . ')');
        }

        return $decoded;
    }

    private function buildFallback(LuminaWorksJob $job): array
    {
        return [
            'vocabulary' => [
                ['term' => 'shift', 'meaning' => 'The hours you work, for example 9:00 to 17:00.'],
                ['term' => 'rota', 'meaning' => 'The plan that shows when you work.'],
                ['term' => 'supervisor', 'meaning' => 'The person who tells you what to do at work.'],
                ['term' => 'break', 'meaning' => 'A short time to rest, eat or drink.'],
                ['term' => 'payslip', 'meaning' => 'The paper or email that shows your pay.'],
                ['term' => 'health and safety', 'meaning' => 'Rules that keep you safe at work.'],
                ['term' => 'clock in / clock out', 'meaning' => 'Recording when you start and stop work.'],
                ['term' => 'overtime', 'meaning' => 'Extra hours you work, often for extra pay.'],
            ],
            'phrases' => [
                ['situation' => 'Meeting your supervisor', 'phrase' => 'Hello, my name is ... . It is my first day.'],
                ['situation' => 'You did not understand', 'phrase' => 'Sorry, can you say that again, slowly please?'],
                ['situation' => 'Reporting a problem', 'phrase' => 'Excuse me, there is a problem with ... . Can you help?'],
                ['situation' => 'Calling in sick', 'phrase' => 'Hello, this is ... . I am sick today and cannot come to work.'],
                ['situation' => 'Asking about the rota', 'phrase' => 'When do I work next week, please?'],
                ['situation' => 'Asking for help', 'phrase' => 'I am not sure how to do this. Can you show me?'],
            ],
            'first_day_tips' => [
                'Arrive 10 minutes early.',
                'Bring your ID and bank details.',
                'Ask questions - it is normal and good.',
                'Write down the name of your supervisor.',
            ],
            'rights_note' => 'In the UK you must be paid at least the minimum wage, and you get a payslip. It is always OK to ask questions about your pay or your hours.',
        ];
    }
}
