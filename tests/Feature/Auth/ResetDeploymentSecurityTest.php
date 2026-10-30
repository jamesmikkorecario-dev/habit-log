<?php

namespace Tests\Feature\Auth;

use App\Actions\Accounts\EmailChanges;
use App\Auth\CredentialUrls;
use App\Mail\ProductionMailFailure;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

class ResetDeploymentSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Request::setTrustedHosts([]);
        Request::setTrustedProxies([], -1);
    }

    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);
        Request::setTrustedProxies([], -1);
        parent::tearDown();
    }

    private function production(): void
    {
        config(['app.url' => 'https://localhost', 'app.debug' => false, 'mail.default' => 'smtp']);
        $this->app->instance('env', 'production');
    }

    private function logs(): TestHandler
    {
        $handler = new TestHandler;
        $logger = new Logger(new Monolog('security-test', [$handler]));
        Log::swap($logger);
        $this->app->instance(LoggerInterface::class, $logger);

        return $handler;
    }

    private function transport(bool $fail = false): TransportInterface
    {
        return new class($fail) implements TransportInterface
        {
            public int $sent = 0;

            public function __construct(private bool $fail) {}

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                $this->sent++;
                if ($this->fail) {
                    // Simulate a transport including the entire message and
                    // credentials in an exception; never print this diagnostic.
                    throw new \RuntimeException('smtp-credential-canary '.$message->toString());
                }

                return new SentMessage($message, $envelope ?? Envelope::create($message));
            }

            public function __toString(): string
            {
                return 'smtp-credential-canary';
            }
        };
    }

    public function test_reset_and_signed_email_links_ignore_host_forwarded_host_port_scheme_and_prefix(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->production();
        config(['app.url' => 'https://approved.example.test:8443']);
        $request = Request::create('http://attacker.example.test:8888/forgot-password', 'POST', [], [], [], [
            'REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_HOST' => 'forwarded-attacker.example.test',
            'HTTP_X_FORWARDED_PROTO' => 'http', 'HTTP_X_FORWARDED_PORT' => '9000',
            'HTTP_X_FORWARDED_PREFIX' => '/attacker', 'HTTP_FORWARDED' => 'host=standard-attacker.example.test;proto=http',
        ]);
        Request::setTrustedProxies(['192.0.2.10'], Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PREFIX);
        $this->app->instance('request', $request);
        URL::setRequest($request);
        // Initialize the shared route generator first to catch stale clone caches.
        URL::route('login');
        $reset = (new ResetPassword('synthetic-reset-canary'))->toMail($user)->actionUrl;
        $this->assertSame('approved.example.test', parse_url($reset, PHP_URL_HOST));
        $this->assertSame('https', parse_url($reset, PHP_URL_SCHEME));
        $this->assertSame(8443, parse_url($reset, PHP_URL_PORT));
        $this->assertSame('/reset-password/synthetic-reset-canary', parse_url($reset, PHP_URL_PATH));
        parse_str(parse_url($reset, PHP_URL_QUERY), $query);
        $this->assertSame($user->email, $query['email']);
        [, $email] = app(EmailChanges::class)->request($user, ['current_password' => 'password', 'new_email' => 'proposed@example.test', 'generation' => 'none']);
        $this->assertSame('approved.example.test', parse_url($email, PHP_URL_HOST));
        $this->assertSame('https', parse_url($email, PHP_URL_SCHEME));
        $this->assertSame(8443, parse_url($email, PHP_URL_PORT));
        $this->assertTrue(URL::hasValidSignature(Request::create($email)));
        $this->assertFalse(URL::hasValidSignature(Request::create(str_replace('approved.example.test', 'attacker.example.test', $email))));
        $this->assertSame($request, URL::getRequest());
    }

    public function test_local_and_test_reset_links_keep_configured_http_origin_and_port(): void
    {
        config(['app.url' => 'http://127.0.0.1:8017']);
        $url = (new ResetPassword('synthetic'))->toMail(User::factory()->make())->actionUrl;
        $this->assertSame('http://127.0.0.1:8017/reset-password/synthetic', strtok($url, '?'));
        $this->get('http://local-alias.test/login')->assertOk();
    }

    public function test_production_rejects_unknown_and_subdomain_hosts_but_ignores_untrusted_forwarded_headers(): void
    {
        $this->production();
        $this->get('https://attacker.example.test/login')->assertStatus(400);
        $this->get('https://child.localhost/login')->assertStatus(400);
        $this->withHeaders(['X-Forwarded-Host' => 'attacker.example.test', 'X-Forwarded-Proto' => 'http', 'Forwarded' => 'host=attacker.example.test;proto=http'])
            ->get('https://localhost/login')->assertOk();
        $this->assertSame([], Request::getTrustedProxies());
    }

    public function test_invalid_origin_fails_closed_without_echoing_configuration(): void
    {
        $this->production();
        foreach (['http://localhost', '//attacker.test', 'https://user:credential-canary@localhost', 'https://localhost?token=canary', 'https://localhost/path', ''] as $origin) {
            config(['app.url' => $origin]);
            try {
                CredentialUrls::generator();
                $this->fail('Unsafe origin was accepted.');
            } catch (ServiceUnavailableHttpException $exception) {
                $this->assertSame('The application origin is not configured securely.', $exception->getMessage());
            }
        }
    }

    public function test_unsafe_mail_configuration_fails_before_user_lookup_or_token_creation(): void
    {
        $user = User::factory()->create();
        $this->production();
        $handler = $this->logs();
        config([
            'mail.mailers.nested' => ['transport' => 'roundrobin', 'mailers' => ['smtp', 'failover']],
            'mail.mailers.cycle' => ['transport' => 'failover', 'mailers' => ['cycle']],
            'mail.mailers.empty' => ['transport' => 'failover', 'mailers' => []],
            'mail.mailers.url-log' => ['transport' => 'smtp', 'url' => 'log://localhost'],
            'mail.mailers.query-log' => ['transport' => 'smtp', 'url' => 'smtp://localhost?driver=log'],
            'mail.mailers.unknown' => ['transport' => 'unreviewed'],
        ]);
        foreach (['log', 'array', 'failover', 'nested', 'cycle', 'empty', 'url-log', 'query-log', 'unknown', 'missing'] as $mailer) {
            config(['mail.default' => $mailer]);
            $responses = [];
            foreach ([$user->email, 'unknown@example.test'] as $email) {
                $responses[] = $this->withSession(['_token' => 'csrf'])->postJson('/forgot-password', ['_token' => 'csrf', 'email' => $email])
                    ->assertStatus(503)->json();
            }
            $this->assertSame($responses[0], $responses[1]);
            $this->assertDatabaseCount('password_reset_tokens', 0);
        }
        $this->withSession(['_token' => 'csrf'])->postJson('/forgot-password', ['_token' => 'csrf', 'email' => $user->email])->assertStatus(429);
        foreach ($handler->getRecords() as $record) {
            $this->assertSame('Production security mail unavailable. Check transport configuration and delivery.', $record->message);
            $this->assertSame([], $record->context);
        }
    }

    public function test_known_proxy_can_supply_https_but_never_override_the_canonical_host(): void
    {
        $this->production();
        config(['trustedproxy.proxies' => ['192.0.2.10']]);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443', 'X-Forwarded-Host' => 'attacker.example.test'])
            ->get('http://localhost/login')->assertOk();
        $this->assertTrue(request()->isSecure());
        $this->assertSame('localhost', request()->getHost());
        $this->withHeaders(['X-Forwarded-Host' => 'localhost'])->get('http://attacker.example.test/login')->assertStatus(400);
    }

    public function test_signed_email_confirmation_works_through_an_explicit_tls_proxy(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->production();
        config(['trustedproxy.proxies' => ['192.0.2.10']]);
        $transport = $this->transport();
        app('mail.manager')->extend('smtp', fn () => $transport);
        [, $url] = app(EmailChanges::class)->request($user, ['current_password' => 'password', 'new_email' => 'proposed@example.test', 'generation' => 'none']);
        $internalUrl = preg_replace('/^https:/', 'http:', $url);
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443', 'X-Forwarded-Host' => 'ignored.example.test'])
            ->get($internalUrl)->assertOk()->assertViewHas('pendingEmail', fn ($pending) => $pending !== null);
        $this->withSession(['_token' => 'csrf'])->post($internalUrl, ['_token' => 'csrf', 'current_password' => 'password'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('proposed@example.test', $user->fresh()->email);
        $this->assertSame(1, $transport->sent);
    }

    public function test_local_log_and_testing_array_workflows_remain_available(): void
    {
        $handler = $this->logs();
        config(['mail.default' => 'log']);
        User::factory()->make()->sendPasswordResetNotification('synthetic-local-canary');
        // Only isolated local/test mail intentionally captures synthetic tokens.
        $this->assertTrue(str_contains(json_encode($handler->getRecords()), 'synthetic-local-canary'));
        config(['mail.default' => 'array']);
        User::factory()->make()->sendPasswordResetNotification('synthetic-test-canary');
        $this->assertCount(1, app('mail.manager')->mailer()->getSymfonyTransport()->messages());
    }

    public function test_safe_production_mail_creates_token_and_keeps_uniform_reset_feedback(): void
    {
        $user = User::factory()->create();
        $this->production();
        $transport = $this->transport();
        app('mail.manager')->extend('smtp', fn () => $transport);
        $responses = [];
        foreach ([$user->email, 'unknown@example.test', $user->email] as $email) {
            $responses[] = $this->withSession(['_token' => 'csrf'])->postJson('/forgot-password', ['_token' => 'csrf', 'email' => $email])->assertOk()->json();
        }
        $this->assertSame($responses[0], $responses[1]);
        $this->assertSame($responses[0], $responses[2]);
        $this->assertSame(1, $transport->sent);
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_all_failed_failover_transports_report_only_a_sanitized_terminal_failure(): void
    {
        $this->production();
        $handler = $this->logs();
        config(['mail.default' => 'safe', 'mail.mailers.safe' => ['transport' => 'failover', 'mailers' => ['smtp', 'sendmail']]]);
        app('mail.manager')->extend('smtp', fn () => $this->transport(fail: true));
        app('mail.manager')->extend('sendmail', fn () => $this->transport(fail: true));
        try {
            User::factory()->make()->sendPasswordResetNotification('synthetic-secret-canary');
            $this->fail('Delivery unexpectedly succeeded.');
        } catch (ProductionMailFailure $exception) {
            $this->assertNull($exception->getPrevious());
            $this->assertSame('', $exception->getDebug());
            $this->app->make(ExceptionHandler::class)->report($exception);
        }
        $this->assertCount(1, $handler->getRecords());
        $this->assertSame([], $handler->getRecords()[0]->context);
        $this->assertSame('Production security mail unavailable. Check transport configuration and delivery.', $handler->getRecords()[0]->message);
    }

    public function test_direct_reset_notification_cannot_use_a_production_log_mailer(): void
    {
        $this->production();
        config(['mail.default' => 'log']);
        $handler = $this->logs();
        try {
            User::factory()->make()->sendPasswordResetNotification('synthetic-secret-canary');
            $this->fail('Unsafe mail was sent.');
        } catch (ProductionMailFailure $exception) {
            $this->assertNull($exception->getPrevious());
            $this->app->make(ExceptionHandler::class)->report($exception);
        }
        $this->assertCount(1, $handler->getRecords());
        $this->assertFalse(str_contains(json_encode($handler->getRecords()), 'synthetic-secret-canary'));
    }

    public function test_transport_failure_diagnostics_never_include_message_token_url_or_credentials(): void
    {
        $this->production();
        $handler = $this->logs();
        $transport = $this->transport(fail: true);
        app('mail.manager')->extend('smtp', fn () => $transport);
        try {
            User::factory()->make()->sendPasswordResetNotification('synthetic-secret-canary');
            $this->fail('Delivery unexpectedly succeeded.');
        } catch (ProductionMailFailure $exception) {
            $this->assertNull($exception->getPrevious());
            $this->app->make(ExceptionHandler::class)->report($exception);
        }
        $this->assertSame(1, $transport->sent);
        $encoded = json_encode($handler->getRecords());
        foreach (['synthetic-secret-canary', 'smtp-credential-canary', '/reset-password/', 'https://localhost'] as $secret) {
            $this->assertFalse(str_contains($encoded, $secret));
        }
        $this->assertCount(1, $handler->getRecords());
    }

    public function test_safe_failover_can_deliver_without_logging_failed_transport_diagnostics(): void
    {
        $this->production();
        $handler = $this->logs();
        config(['mail.default' => 'safe', 'mail.mailers.safe' => ['transport' => 'failover', 'mailers' => ['smtp', 'sendmail']]]);
        $first = $this->transport(fail: true);
        $second = $this->transport();
        app('mail.manager')->extend('smtp', fn () => $first);
        app('mail.manager')->extend('sendmail', fn () => $second);
        User::factory()->make()->sendPasswordResetNotification('synthetic-secret-canary');
        $this->assertSame(1, $first->sent);
        $this->assertSame(1, $second->sent);
        $this->assertCount(0, $handler->getRecords());
    }
}
