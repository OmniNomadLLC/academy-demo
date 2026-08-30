<?php

namespace App\Console\Commands;

use App\Services\AcuityService;
use App\Models\Student;
use App\Models\AcuitySyncLog;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SyncAcuityClients extends Command
{
    protected $signature = 'acuity:sync-clients 
                            {--limit=0 : Maximum number of clients to sync (0 = all)}
                            {--page-size=200 : Page size per API call}
                            {--force : Force sync even if recently synced}';
    
    protected $description = 'Sync clients from Acuity Scheduling to Students table';

    public function handle()
    {
        // Set generous timeouts for CLI execution
        ini_set('max_execution_time', 300);  // 5 minutes
        ini_set('memory_limit', '512M');
        set_time_limit(300); // 5 minutes instead of 30 seconds
        
        $this->info('🔄 Starting Acuity clients sync...');
        
        // Create sync log
        $syncLog = AcuitySyncLog::create([
            'sync_type' => 'clients',
            'started_at' => now(),
            'status' => 'running',
        ]);

        try {
            $acuity = new AcuityService();
            $emailNormGenerated = $this->emailNormIsGenerated();
            $limit = (int) $this->option('limit');
            $perPage = (int) $this->option('page-size');
            if ($perPage < 25) $perPage = 25; if ($perPage > 500) $perPage = 500;

            $this->info("📥 Fetching clients from Acuity (page-size={$perPage}, limit=".($limit ?: 'all').")...");

            $totalFetched = 0;
            $created = 0; $updated = 0; $errors = 0; $skipped = 0;
            $page = 1; $count = 0;
            $maxRetries = 3;

            do {
                $retry = 0; $clients = [];
                do {
                    try {
                        $clients = $acuity->getClients(['max' => $perPage, 'page' => $page]) ?? [];
                        break;
                    } catch (\Throwable $e) {
                        $retry++;
                        if ($retry > $maxRetries) { throw $e; }
                        $this->warn("⚠️ clients page {$page} attempt {$retry}/{$maxRetries} failed: ".substr($e->getMessage(),0,120));
                        usleep((int) (250 * (2 ** ($retry - 1)) * 1000));
                    }
                } while (true);

                $count = is_array($clients) ? count($clients) : 0;
                $totalFetched += $count;
                $this->info(sprintf('page %d | got %d | total=%d', $page, $count, $totalFetched));

                foreach ($clients as $index => $clientData) {
                    try {
                        // Tolerant ID/email extraction across payload variants
                        $acuityId = $clientData['id']
                            ?? $clientData['clientID']
                            ?? $clientData['clientId']
                            ?? $clientData['ClientID']
                            ?? $clientData['ClientId']
                            ?? null;
                        $email = $clientData['email']
                            ?? data_get($clientData, 'client.email')
                            ?? data_get($clientData, 'Client.email')
                            ?? null;
                        $emailNorm = $email ? strtolower(trim($email)) : null;

                        $payload = [
                            'first_name' => $clientData['firstName'] ?? '',
                            'last_name'  => $clientData['lastName']  ?? '',
                            'email'      => $email,
                            'phone'      => $clientData['phone']     ?? null,
                            'notes'      => $clientData['notes']     ?? null,
                            'is_active'  => true,
                        ];

                        if (!$emailNormGenerated && $emailNorm) {
                            $payload['email_norm'] = $emailNorm;
                        }

                        $student = null;
                        $justCreated = false;

                        try {
                            if (!empty($acuityId)) {
                                $payload['acuity_client_id'] = (string) $acuityId;
                                $student = Student::updateOrCreate(
                                    ['acuity_client_id' => (string) $acuityId],
                                    $payload
                                );
                            } elseif (!empty($emailNorm)) {
                                if ($emailNormGenerated) {
                                    $student = Student::where('email_norm', $emailNorm)->first();
                                    if ($student) {
                                        $student->fill($payload);
                                        $student->save();
                                    } else {
                                        $student = Student::create($payload + ['acuity_client_id' => null]);
                                        $justCreated = true;
                                    }
                                } else {
                                    $student = Student::updateOrCreate(
                                        ['email_norm' => $emailNorm],
                                        $payload
                                    );
                                }
                            } else {
                                $skipped++;
                                continue;
                            }
                        } catch (QueryException $qe) {
                            $msg = $qe->getMessage();
                            if (stripos($msg, 'unique') !== false || str_contains($msg, '23000')) {
                                // Re-query existing record
                                if (!empty($acuityId)) {
                                    $student = Student::where('acuity_client_id', (string) $acuityId)->first();
                                }
                                if (!$student && !empty($emailNorm)) {
                                    $student = Student::where('email_norm', $emailNorm)->first();
                                }
                                if (!$student) { $errors++; continue; }
                            } else {
                                throw $qe;
                            }
                        }

                        if ($student) {
                            if ($justCreated || ($student->wasRecentlyCreated ?? false)) {
                                $created++;
                            } else {
                                $updated++;
                            }
                        }
                    } catch (\Throwable $e) {
                        $errors++;
                        $this->warn('Client row error: '.substr($e->getMessage(),0,160));
                    }
                }

                $page++;
                if ($limit > 0 && $totalFetched >= $limit) { break; }
            } while ($count === $perPage);

            $this->info("📊 Processed {$totalFetched} client rows: created={$created} updated={$updated} skipped={$skipped} errors={$errors}");
            
            // Update sync log
            $syncLog->update([
                'status' => $errors > 0 ? 'failed' : 'completed',
                'completed_at' => now(),
                'records_processed' => $totalFetched,
                'records_created' => $created,
                'records_updated' => $updated,
                'error_message' => $errors > 0 ? "{$errors} client(s) had processing errors; skipped={$skipped}" : null,
            ]);

            $this->info("✅ Sync completed successfully!");
            $this->info("📈 Created: {$created} students");
            $this->info("🔄 Updated: {$updated} students");
            
            if ($errors > 0) { $this->warn("⚠️  Errors: {$errors} client(s) could not be processed"); }
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $syncLog->update([
                'status' => 'failed',
                'completed_at' => now(),
                'error_message' => $e->getMessage(),
            ]);
            
            $this->error('❌ Sync failed: ' . $e->getMessage());
            
            // Provide helpful troubleshooting info
            if (str_contains($e->getMessage(), 'cURL error 28')) {
                $this->error('💡 This appears to be a timeout issue. Try:');
                $this->error('   1. Reduce --limit parameter (e.g., --limit=50)');
                $this->error('   2. Check your internet connection');
                $this->error('   3. Verify Acuity API status');
            }
            
            return Command::FAILURE;
        }
    }

    private function emailNormIsGenerated(): bool
    {
        try {
            $driver = DB::getDriverName();
            if (!in_array($driver, ['mysql', 'mariadb'])) {
                return false;
            }

            $columns = DB::select("SHOW COLUMNS FROM students WHERE Field = 'email_norm'");
            if (empty($columns)) {
                return false;
            }

            $extra = $columns[0]->Extra ?? '';
            return stripos($extra, 'generated') !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
