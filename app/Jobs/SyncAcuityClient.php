<?php

namespace App\Jobs;

use App\Models\ClassSession;
use App\Models\Student;
use App\Services\AcuityService;
use App\Support\EmailNormalizer;
use App\Support\StudentEmailResolver;
use App\Traits\FlagsStudentDuplicates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\QueryException;

class SyncAcuityClient implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use FlagsStudentDuplicates;

    public int $tries = 1;
    public int $timeout = 300;
    public int $backoff = 1;

    protected $clientId;

    public function __construct($clientId)
    {
        $this->clientId = $clientId;
    }

    public function handle()
    {
        try {
            $acuity = new AcuityService();
            $client = $acuity->getClient($this->clientId);

            if (!$client) {
                Log::warning("Client {$this->clientId} not found in Acuity");
                return;
            }

            $this->syncClient($client);

            Log::info("Successfully synced client {$this->clientId}");

        } catch (\Exception $e) {
            Log::error("Failed to sync client {$this->clientId}: {$e->getMessage()}");
            throw $e;
        }
    }

    private function syncClient($clientData)
    {
        $emailRaw = null;
        if (array_key_exists('email', $clientData) && is_string($clientData['email'])) {
            $emailRaw = trim($clientData['email']);
        }
        $emailNorm = EmailNormalizer::normalize($emailRaw);

        if (! $emailNorm && isset($clientData['client']['email']) && is_string($clientData['client']['email'])) {
            $emailNorm = EmailNormalizer::normalize($clientData['client']['email']);
        }

        $student = app(StudentEmailResolver::class)->resolveOrSpawn($this->clientId, $emailNorm);

        if (! $student) {
            Log::warning('SyncAcuityClient: skipping client with no id and no email');
            return;
        }

        $payload = [
            'first_name' => $clientData['firstName'] ?? '',
            'last_name' => $clientData['lastName'] ?? '',
            'email' => $emailNorm ?? null,
            'phone' => $clientData['phone'] ?? null,
            'registration_date' => now()->toDateString(),
            'is_active' => true,
            'notes' => $clientData['notes'] ?? null,
        ];

        if (! Student::emailNormIsGenerated()) {
            $payload['email_norm'] = $emailNorm;
        }

        $student->fill(array_filter($payload, fn ($value) => $value !== null));

        // Skip acuity_client_id assignment when this is a respawn — the archived predecessor
        // still holds the unique slot. Future syncs resolve the spawn via email/session-history.
        if (! empty($this->clientId) && empty($student->acuity_client_id) && empty($student->previous_student_id)) {
            $student->acuity_client_id = (string) $this->clientId;
        }

        // Attempt to infer region/category from the client's most recent appointment
        $appointmentIds = [];

        try {
            $acuity = new AcuityService();
            // Fetch a reasonable window and select the most recent by datetime
            $appointments = $acuity->getAppointments([
                'clientID' => (string) $this->clientId,
                'max' => 100,
                // Optionally limit to last 180 days to reduce payload
                'minDate' => now()->subDays(180)->format('Y-m-d'),
            ]);
            if (is_array($appointments) && count($appointments) > 0) {
                usort($appointments, function ($a, $b) {
                    $da = isset($a['datetime']) ? strtotime($a['datetime']) : 0;
                    $db = isset($b['datetime']) ? strtotime($b['datetime']) : 0;
                    return $db <=> $da;
                });
                $latest = $appointments[0];
                $category = $latest['category'] ?? ($latest['Category'] ?? null);
                if ($student) {
                    $student->setAcuityCategoryAndLocation($category);
                }
                foreach ($appointments as $appt) {
                    if (! empty($appt['id'])) {
                        $appointmentIds[] = (string) $appt['id'];
                    }
                }
            } else {
                // No appointments found yet; leave category null and keep location as-is
                if ($student) {
                    $student->setAcuityCategoryAndLocation(null);
                }
            }
        } catch (\Throwable $e) {
            // On API failure, do not block client sync; leave as-is
            Log::warning('SyncAcuityClient: failed to infer region from appointments', [
                'client_id' => $this->clientId,
                'error' => $e->getMessage(),
            ]);
            if ($student) {
                $student->setAcuityCategoryAndLocation(null);
            }
        }
        try {
            $student->save();
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062 && $emailNorm) {
                $existing = Student::whereNull('archived_at')
                    ->whereRaw('LOWER(email) = ?', [$emailNorm])
                    ->first()
                    ?? Student::whereNull('archived_at')->where('email_norm', $emailNorm)->first();

                if ($existing) {
                    $existing->fill(array_filter($payload, fn ($value) => $value !== null));
                    if (! empty($this->clientId) && empty($existing->acuity_client_id)) {
                        $existing->acuity_client_id = (string) $this->clientId;
                    }
                    $existing->save();
                    $student = $existing;
                } else {
                    throw $e;
                }
            } else {
                throw $e;
            }
        }
        if ($student->wasRecentlyCreated) {
            if ($student->previous_student_id) {
                Log::warning(sprintf(
                    'Spawned new student #%d (%s) linked to archived previous_student_id #%d for second-chance flow.',
                    $student->id,
                    $student->email ?? '?',
                    $student->previous_student_id
                ));
            }
            $this->flagIfDuplicate($student);
        }

        $this->linkSessionsToStudent(
            $student,
            $emailNorm,
            $this->clientId ? (string) $this->clientId : null,
            $appointmentIds ?? []
        );

        Log::info('SyncAcuityClient synced student', [
            'student_id' => $student->id,
            'client_id' => $this->clientId,
            'email' => $emailRaw ?? $emailNorm,
            'created' => $student->wasRecentlyCreated,
        ]);
    }

    private function linkSessionsToStudent(Student $student, ?string $emailNorm, ?string $clientId = null, array $appointmentIds = []): void
    {
        try {
            if (! Schema::hasColumn('class_sessions', 'student_id')) {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }

        $updatedTotal = 0;

        $hasStudentEmail = false;
        $hasLinkStatus = false;
        try {
            $hasStudentEmail = Schema::hasColumn('class_sessions', 'student_email');
        } catch (\Throwable $e) {
            $hasStudentEmail = false;
        }
        try {
            $hasLinkStatus = Schema::hasColumn('class_sessions', 'link_status');
        } catch (\Throwable $e) {
            $hasLinkStatus = false;
        }

        if ($emailNorm) {
            $updatePayload = ['student_id' => $student->id];
            if ($hasLinkStatus) {
                $updatePayload['link_status'] = 'linked_by_email';
            }
            if ($hasStudentEmail) {
                $updatePayload['student_email'] = $emailNorm;
            }

            $updatedByEmail = ClassSession::query()
                ->whereNull('student_id')
                ->where(function ($query) use ($emailNorm) {
                    $query->whereRaw('LOWER(student_email) = ?', [$emailNorm])
                        ->orWhereRaw('LOWER(client_email) = ?', [$emailNorm]);
                })
                ->update($updatePayload);

            $updatedTotal += $updatedByEmail;
        }

        if ($clientId) {
            $clientUpdatePayload = ['student_id' => $student->id];
            if ($hasLinkStatus) {
                $clientUpdatePayload['link_status'] = 'linked_by_client';
            }

            $clientUpdated = ClassSession::query()
                ->whereNull('student_id')
                ->where(function ($query) use ($clientId) {
                    $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(acuity_data, '$.clientID')) = ?", [(string) $clientId])
                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(acuity_data, '$.clientId')) = ?", [(string) $clientId])
                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(acuity_data, '$.client.id')) = ?", [(string) $clientId])
                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(acuity_data, '$.ClientID')) = ?", [(string) $clientId])
                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(acuity_data, '$.ClientId')) = ?", [(string) $clientId])
                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(acuity_data, '$.Client.id')) = ?", [(string) $clientId]);
                })
                ->update($clientUpdatePayload);

            $updatedTotal += $clientUpdated;
        }

        if ($updatedTotal > 0) {
            Log::info('SyncAcuityClient linked sessions for student', [
                'student_id' => $student->id,
                'email_norm' => $emailNorm,
                'client_id' => $clientId,
                'updated_sessions' => $updatedTotal,
            ]);
        }

        if (! empty($appointmentIds)) {
            $appointmentIds = array_values(array_unique($appointmentIds));
            foreach ($appointmentIds as $appointmentId) {
                try {
                    $appointmentUpdate = ['student_id' => $student->id];
                    if ($hasLinkStatus) {
                        $appointmentUpdate['link_status'] = $emailNorm ? 'linked_by_email' : 'linked_by_client';
                    }
                    if ($hasStudentEmail && $emailNorm) {
                        $appointmentUpdate['student_email'] = $emailNorm;
                    }

                    ClassSession::query()
                        ->where('acuity_appointment_id', (string) $appointmentId)
                        ->where(function ($query) use ($student) {
                            $query->whereNull('student_id')
                                ->orWhere('student_id', '!=', $student->id);
                        })
                        ->update($appointmentUpdate);
                } catch (\Throwable $e) {
                    Log::debug('SyncAcuityClient linkSessionsToStudent appointment update failed', [
                        'appointment_id' => $appointmentId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}
