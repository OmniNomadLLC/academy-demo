<?php

namespace App\Services\LuminaWorks;

use App\Models\LuminaWorksActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EvidenceLogger
{
    /**
     * Append an event to the tamper-evident evidence trail. Each row's hash
     * chains to the previous row for the same student, so any later edit or
     * deletion breaks the chain (see luminaworks:verify-evidence).
     */
    public function record(
        int $studentId,
        string $eventType,
        string $description,
        ?Model $related = null,
        array $payload = [],
        ?\DateTimeInterface $occurredAt = null
    ): LuminaWorksActivityLog {
        return DB::transaction(function () use ($studentId, $eventType, $description, $related, $payload, $occurredAt) {
            $prev = LuminaWorksActivityLog::where('student_id', $studentId)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $user = auth()->user();
            $occurredAt = $occurredAt ?? now();

            $row = [
                'student_id' => $studentId,
                'event_type' => $eventType,
                'related_type' => $related?->getMorphClass(),
                'related_id' => $related?->getKey(),
                'description' => $description,
                'payload' => $payload,
                'actor_user_id' => $user?->id,
                'actor_role' => $user?->role,
                'occurred_at' => $occurredAt,
                'prev_hash' => $prev?->hash,
            ];

            $row['hash'] = self::hashRow($row);

            return LuminaWorksActivityLog::create($row);
        });
    }

    public static function hashRow(array $row): string
    {
        // MySQL JSON columns do not preserve key order, so canonicalise the
        // payload (recursive ksort) before hashing or verification would
        // fail on round-tripped rows.
        $payload = $row['payload'] ?? [];
        self::ksortRecursive($payload);

        return hash('sha256', json_encode([
            $row['student_id'],
            $row['event_type'],
            $row['related_type'] ?? null,
            $row['related_id'] ?? null,
            $row['description'],
            $payload,
            $row['actor_user_id'] ?? null,
            ($row['occurred_at'] instanceof \DateTimeInterface)
                ? $row['occurred_at']->format('Y-m-d H:i:s')
                : (string) $row['occurred_at'],
            $row['prev_hash'] ?? null,
        ], JSON_UNESCAPED_UNICODE));
    }

    private static function ksortRecursive(array &$array): void
    {
        ksort($array);
        foreach ($array as &$value) {
            if (is_array($value)) {
                self::ksortRecursive($value);
            }
        }
    }

    /** Recompute the chain for one student; returns ids of rows that fail. */
    public function verifyChain(int $studentId): array
    {
        $broken = [];
        $prevHash = null;

        LuminaWorksActivityLog::where('student_id', $studentId)
            ->orderBy('id')
            ->each(function (LuminaWorksActivityLog $log) use (&$broken, &$prevHash) {
                $expected = self::hashRow([
                    'student_id' => $log->student_id,
                    'event_type' => $log->event_type,
                    'related_type' => $log->related_type,
                    'related_id' => $log->related_id,
                    'description' => $log->description,
                    'payload' => $log->payload ?? [],
                    'actor_user_id' => $log->actor_user_id,
                    'occurred_at' => $log->occurred_at,
                    'prev_hash' => $log->prev_hash,
                ]);

                if ($log->hash !== $expected || $log->prev_hash !== $prevHash) {
                    $broken[] = $log->id;
                }

                $prevHash = $log->hash;
            });

        return $broken;
    }
}
