<?php

namespace Bangsamu\LibraryClay\Controllers;

use App\Http\Controllers\Controller;

use Bangsamu\Master\Models\DashboardSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

use Carbon\Carbon; 
Carbon::setLocale('id');

class LibraryClayMailController extends Controller
{
   /**
     * Diagnosa lengkap konfigurasi email:
     * - Cek .env config
     * - Cek DB config (dashboard_settings)
     * - Test koneksi SMTP
     * - Kirim test email
     */
    public function diagnose(Request $request)
    {
        $results = [];

        // ─── 1. CEK ENV/CONFIG (runtime) ─────────────────────
        $results['env_config'] = [
            'mail.default (driver aktif)' => config('mail.default'),
            'mail.mailers.smtp.host' => config('mail.mailers.smtp.host'),
            'mail.mailers.smtp.port' => config('mail.mailers.smtp.port'),
            'mail.mailers.smtp.username' => config('mail.mailers.smtp.username'),
            'mail.mailers.smtp.password' => config('mail.mailers.smtp.password') ? '***SET***' : '***KOSONG***',
            'mail.mailers.smtp.scheme' => config('mail.mailers.smtp.scheme'),
            'mail.from.address' => config('mail.from.address'),
            'mail.from.name' => config('mail.from.name'),
        ];

        // ─── 2. CEK DB CONFIG (dashboard_settings) ──────────
        try {
            $dbConfig = DashboardSettings::where('group', 'mail')->pluck('value', 'key')->toArray();
            $results['db_config'] = $dbConfig;

            // Cek apakah password ter-enkripsi
            if (! empty($dbConfig['mail.password'])) {
                try {
                    Crypt::decryptString($dbConfig['mail.password']);
                    $results['db_config']['mail.password'] = '***ENCRYPTED & VALID***';
                } catch (\Throwable $e) {
                    $results['db_config']['mail.password'] = '***DECRYPT FAILED: '.$e->getMessage().'***';
                }
            } else {
                $results['db_config']['mail.password'] = '***KOSONG/NULL***';
            }

            // ─── PERINGATAN ────────────────────────────────
            $warnings = [];
            if (($dbConfig['mail.driver'] ?? '') === 'log') {
                $warnings[] = '⚠️ DB config driver=log, email hanya ditulis ke log, TIDAK terkirim!';
            }
            if (empty($dbConfig['mail.username'])) {
                $warnings[] = '⚠️ DB config mail.username KOSONG — SMTP auth tidak bisa jalan!';
            }
            if (empty($dbConfig['mail.password'])) {
                $warnings[] = '⚠️ DB config mail.password KOSONG — SMTP auth tidak bisa jalan!';
            }
            if (($dbConfig['mail.host'] ?? '') === '127.0.0.1') {
                $warnings[] = '⚠️ DB config mail.host masih 127.0.0.1 (localhost) — harus diubah ke smtp server yang benar!';
            }
            if (config('mail.default') === 'log') {
                $warnings[] = '⚠️ ENV/Config mail.default=log, email TIDAK terkirim via SMTP! SendEmailJob set config runtime tapi TIDAK set mail.default!';
            }

            $results['warnings'] = $warnings;
        } catch (\Throwable $e) {
            $results['db_config_error'] = $e->getMessage();
        }

        // ─── 3. QUEUE STATUS ────────────────────────────────
        $results['queue'] = [
            'driver' => config('queue.default'),
            'note' => config('queue.default') === 'database'
                ? 'Email dikirim via queue database — pastikan php artisan queue:work JALAN!'
                : 'Queue driver: '.config('queue.default'),
        ];

        return response()->json($results, 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Resolve public IP address of the server for trace logging.
     */
    private function resolvePublicIp(): string
    {
        try {
            $ip = @file_get_contents('https://api.ipify.org?format=text', false,
                stream_context_create(['http' => ['timeout' => 5]]));

            return $ip ?: 'unknown';
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    /**
     * Test kirim email SYNCHRONOUS (tanpa queue) menggunakan config dari DB.
     * Ini mensimulasikan persis seperti SendEmailJob.
     */
    public function testSendSync(Request $request)
    {
        $to = $request->input('to', 'gita.samudra@meindo.com');
        $steps = [];
        $publicIp = $this->resolvePublicIp();
        $traceId = 'SYNC-' . now()->format('YmdHis') . '-' . substr(md5(uniqid()), 0, 6);

        Log::channel('email')->info("[$traceId] ====== TEST SEND-SYNC STARTED ======", [
            'to' => $to,
            'public_ip' => $publicIp,
            'server_ip' => request()->server('SERVER_ADDR', 'unknown'),
            'requested_by' => auth()->user()?->email ?? 'guest',
            'requested_from' => $request->ip(),
        ]);

        try {
            // ─── Step 1: Load DB config (sama seperti SendEmailJob) ──────
            $mailConfig = DashboardSettings::where('group', 'mail')->pluck('value', 'key')->toArray();
            $steps[] = '✅ Step 1: DB config loaded — host='
                .($mailConfig['mail.host'] ?? 'null')
                .', port='.($mailConfig['mail.port'] ?? 'null')
                .', username='.($mailConfig['mail.username'] ?? 'null');

            Log::channel('email')->info("[$traceId] Step 1: DB config loaded", [
                'host' => $mailConfig['mail.host'] ?? 'null',
                'port' => $mailConfig['mail.port'] ?? 'null',
                'username' => $mailConfig['mail.username'] ?? 'null',
                'driver' => $mailConfig['mail.driver'] ?? 'null',
                'encryption' => $mailConfig['mail.encryption'] ?? 'null',
                'from_address' => $mailConfig['mail.from.address'] ?? 'null',
            ]);

            // ─── Step 2: Override runtime config ─────────────────────────
            $password = null;
            if (! empty($mailConfig['mail.password'])) {
                try {
                    $password = Crypt::decryptString($mailConfig['mail.password']);
                    $steps[] = '✅ Step 2a: Password decrypted successfully';
                    Log::channel('email')->info("[$traceId] Step 2a: Password decrypted OK");
                } catch (\Throwable $e) {
                    $steps[] = '❌ Step 2a: Password decrypt FAILED — '.$e->getMessage();
                    Log::channel('email')->error("[$traceId] Step 2a: Password decrypt FAILED", [
                        'error' => $e->getMessage(),
                        'public_ip' => $publicIp,
                    ]);

                    return response()->json([
                        'status' => 'error',
                        'message' => 'Password decrypt failed',
                        'trace_id' => $traceId,
                        'public_ip' => $publicIp,
                        'steps' => $steps,
                    ], 500);
                }
            } else {
                $steps[] = '❌ Step 2a: Password KOSONG di DB — tidak bisa authenticate SMTP!';
                Log::channel('email')->error("[$traceId] Step 2a: Password KOSONG di DB", [
                    'public_ip' => $publicIp,
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'DB mail.password kosong. Update dahulu di Settings > Mail.',
                    'trace_id' => $traceId,
                    'public_ip' => $publicIp,
                    'steps' => $steps,
                ], 500);
            }

            // Set runtime config — TERMASUK mail.default!
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.transport' => 'smtp',
                'mail.mailers.smtp.host' => $mailConfig['mail.host'],
                'mail.mailers.smtp.port' => (int) $mailConfig['mail.port'],
                'mail.mailers.smtp.username' => $mailConfig['mail.username'],
                'mail.mailers.smtp.password' => $password,
                'mail.mailers.smtp.scheme' => $mailConfig['mail.encryption'] ?? 'tls',
                'mail.from.address' => $mailConfig['mail.from.address'],
                'mail.from.name' => $mailConfig['mail.from.name'],
            ]);

            // Purge cached mailer agar config baru digunakan
            Mail::purge('smtp');

            $steps[] = '✅ Step 2b: Runtime config set — default=smtp, host='
                .$mailConfig['mail.host'].', port='.$mailConfig['mail.port'];
            Log::channel('email')->info("[$traceId] Step 2b: Runtime config applied", [
                'host' => $mailConfig['mail.host'],
                'port' => $mailConfig['mail.port'],
            ]);

            // ─── Step 3: Test SMTP connection ────────────────────────────
            try {
                $transport = Mail::mailer('smtp')->getSymfonyTransport();
                $transport->start();
                $steps[] = '✅ Step 3: SMTP connection berhasil!';
                Log::channel('email')->info("[$traceId] Step 3: SMTP connection OK", [
                    'public_ip' => $publicIp,
                ]);
                $transport->stop();
            } catch (\Throwable $e) {
                $steps[] = '❌ Step 3: SMTP connection GAGAL — '.$e->getMessage();
                Log::channel('email')->error("[$traceId] Step 3: SMTP connection FAILED", [
                    'error' => $e->getMessage(),
                    'error_code' => $e->getCode(),
                    'public_ip' => $publicIp,
                    'smtp_host' => $mailConfig['mail.host'] ?? 'null',
                    'smtp_port' => $mailConfig['mail.port'] ?? 'null',
                    'trace' => $e->getTraceAsString(),
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'SMTP connection failed',
                    'trace_id' => $traceId,
                    'public_ip' => $publicIp,
                    'steps' => $steps,
                    'error' => $e->getMessage(),
                ], 500);
            }

            // ─── Step 4: Kirim test email ────────────────────────────────
            Mail::mailer('smtp')->raw(
                "Ini adalah test email dari Warehouse App.\n\nDikirim pada: ".now()->toDateTimeString()
                ."\nHost: ".$mailConfig['mail.host'].':'.$mailConfig['mail.port']
                ."\nPublic IP: ".$publicIp
                ."\nTrace ID: ".$traceId,
                function ($message) use ($to, $traceId) {
                    $message->to($to)
                        ->subject("[TEST] Mail Config Diagnosis [$traceId] - ".now()->format('H:i:s'));
                }
            );

            $steps[] = '✅ Step 4: Email terkirim ke '.$to;

            Log::channel('email')->info("[$traceId] ====== TEST SEND-SYNC SUCCESS ======", [
                'to' => $to,
                'host' => $mailConfig['mail.host'],
                'port' => $mailConfig['mail.port'],
                'public_ip' => $publicIp,
                'timestamp' => now()->toDateTimeString(),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Test email berhasil dikirim ke '.$to,
                'trace_id' => $traceId,
                'public_ip' => $publicIp,
                'steps' => $steps,
            ]);

        } catch (\Throwable $e) {
            $steps[] = '❌ EXCEPTION: '.$e->getMessage();
            Log::channel('email')->error("[$traceId] ====== TEST SEND-SYNC FAILED ======", [
                'to' => $to,
                'public_ip' => $publicIp,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile().':'.$e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'trace_id' => $traceId,
                'public_ip' => $publicIp,
                'steps' => $steps,
            ], 500);
        }
    }

    /**
     * Test kirim email langsung pakai config ENV (bypass DB).
     * Untuk membandingkan apakah masalah di DB config atau di .env config.
     */
    public function testSendEnv(Request $request)
    {
        $to = $request->input('to', 'gita.samudra@meindo.com');
        $steps = [];
        $publicIp = $this->resolvePublicIp();
        $traceId = 'ENV-' . now()->format('YmdHis') . '-' . substr(md5(uniqid()), 0, 6);

        Log::channel('email')->info("[$traceId] ====== TEST SEND-ENV STARTED ======", [
            'to' => $to,
            'public_ip' => $publicIp,
            'server_ip' => request()->server('SERVER_ADDR', 'unknown'),
            'requested_by' => auth()->user()?->email ?? 'guest',
            'requested_from' => $request->ip(),
            'env_config' => [
                'host' => config('mail.mailers.smtp.host'),
                'port' => config('mail.mailers.smtp.port'),
                'username' => config('mail.mailers.smtp.username'),
                'from' => config('mail.from.address'),
                'default_driver' => config('mail.default'),
            ],
        ]);

        try {
            // ─── Step 1: Force set mail.default ke smtp dari env ──────────
            config(['mail.default' => 'smtp']);
            Mail::purge('smtp');
            $steps[] = '✅ Step 1: mail.default set ke smtp, mailer purged';
            Log::channel('email')->info("[$traceId] Step 1: Config overridden to smtp");

            // ─── Step 2: Test SMTP connection ────────────────────────────
            try {
                $transport = Mail::mailer('smtp')->getSymfonyTransport();
                $transport->start();
                $steps[] = '✅ Step 2: SMTP connection berhasil!';
                Log::channel('email')->info("[$traceId] Step 2: SMTP connection OK", [
                    'public_ip' => $publicIp,
                ]);
                $transport->stop();
            } catch (\Throwable $e) {
                $steps[] = '❌ Step 2: SMTP connection GAGAL — '.$e->getMessage();
                Log::channel('email')->error("[$traceId] Step 2: SMTP connection FAILED", [
                    'error' => $e->getMessage(),
                    'error_code' => $e->getCode(),
                    'public_ip' => $publicIp,
                    'smtp_host' => config('mail.mailers.smtp.host'),
                    'smtp_port' => config('mail.mailers.smtp.port'),
                    'trace' => $e->getTraceAsString(),
                ]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'SMTP connection failed: '.$e->getMessage(),
                    'trace_id' => $traceId,
                    'public_ip' => $publicIp,
                    'steps' => $steps,
                    'config_used' => [
                        'host' => config('mail.mailers.smtp.host'),
                        'port' => config('mail.mailers.smtp.port'),
                        'username' => config('mail.mailers.smtp.username'),
                        'from' => config('mail.from.address'),
                    ],
                ], 500);
            }

            // ─── Step 3: Kirim test email ────────────────────────────────
            Mail::mailer('smtp')->raw(
                "Test email via ENV config.\n\nDikirim pada: ".now()->toDateTimeString()
                ."\nHost: ".config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port')
                ."\nUsername: ".config('mail.mailers.smtp.username')
                ."\nPublic IP: ".$publicIp
                ."\nTrace ID: ".$traceId,
                function ($message) use ($to, $traceId) {
                    $message->to($to)
                        ->subject("[TEST-ENV] Mail Config [$traceId] - ".now()->format('H:i:s'));
                }
            );

            $steps[] = '✅ Step 3: Email terkirim ke '.$to;

            Log::channel('email')->info("[$traceId] ====== TEST SEND-ENV SUCCESS ======", [
                'to' => $to,
                'public_ip' => $publicIp,
                'host' => config('mail.mailers.smtp.host'),
                'port' => config('mail.mailers.smtp.port'),
                'timestamp' => now()->toDateTimeString(),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Email terkirim via ENV config ke '.$to,
                'trace_id' => $traceId,
                'public_ip' => $publicIp,
                'steps' => $steps,
                'config_used' => [
                    'host' => config('mail.mailers.smtp.host'),
                    'port' => config('mail.mailers.smtp.port'),
                    'username' => config('mail.mailers.smtp.username'),
                    'from' => config('mail.from.address'),
                ],
            ]);
        } catch (\Throwable $e) {
            $steps[] = '❌ EXCEPTION: '.$e->getMessage();
            Log::channel('email')->error("[$traceId] ====== TEST SEND-ENV FAILED ======", [
                'to' => $to,
                'public_ip' => $publicIp,
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile().':'.$e->getLine(),
                'trace' => $e->getTraceAsString(),
                'config_used' => [
                    'host' => config('mail.mailers.smtp.host'),
                    'port' => config('mail.mailers.smtp.port'),
                    'username' => config('mail.mailers.smtp.username'),
                    'from' => config('mail.from.address'),
                ],
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'trace_id' => $traceId,
                'public_ip' => $publicIp,
                'steps' => $steps,
                'config_used' => [
                    'host' => config('mail.mailers.smtp.host'),
                    'port' => config('mail.mailers.smtp.port'),
                    'username' => config('mail.mailers.smtp.username'),
                    'from' => config('mail.from.address'),
                ],
            ], 500);
        }
    }

}
