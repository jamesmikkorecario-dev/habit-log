<?php

namespace App\Mail;

use Illuminate\Mail\MailManager;
use Psr\Log\NullLogger;

class SecureMailManager extends MailManager
{
    public function assertSafeMailer(?string $name = null, array $seen = []): void
    {
        if (! $this->app->isProduction()) {
            return;
        }

        try {
            $name ??= $this->getDefaultDriver();
            if (in_array($name, $seen, true) || $this->app['config']['mail.driver']) {
                throw new ProductionMailFailure;
            }
            // Match Laravel's MAIL_URL parsing, including query overrides.
            $config = $this->getConfig($name);
            $this->assertSafeConfig($config ?? [], [...$seen, $name]);
        } catch (\Throwable) {
            throw new ProductionMailFailure;
        }
    }

    private function assertSafeConfig(array $config, array $seen = []): void
    {
        $transport = $config['transport'] ?? null;
        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            if (empty($config['mailers']) || ! is_array($config['mailers'])) {
                throw new ProductionMailFailure;
            }
            foreach ($config['mailers'] as $child) {
                if (! is_string($child)) {
                    throw new ProductionMailFailure;
                }
                $this->assertSafeMailer($child, $seen);
            }
        } elseif (! in_array($transport, ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'resend', 'mailgun'], true)) {
            // Unknown/custom transports need an explicit security review.
            throw new ProductionMailFailure;
        }
    }

    public function mailer($name = null)
    {
        // Check even when a mailer was already cached by the manager.
        $this->assertSafeMailer($name);

        try {
            return parent::mailer($name);
        } catch (\Throwable $exception) {
            if ($this->app->isProduction()) {
                throw new ProductionMailFailure;
            }
            throw $exception;
        }
    }

    public function createSymfonyTransport(array $config)
    {
        if (! $this->app->isProduction()) {
            return parent::createSymfonyTransport($config);
        }

        try {
            $this->assertSafeConfig($config);

            return new PrivateTransport(parent::createSymfonyTransport($config));
        } catch (\Throwable) {
            throw new ProductionMailFailure;
        }
    }

    protected function createRoundrobinTransportOfClass(array $config, string $class)
    {
        if (! $this->app->isProduction()) {
            return parent::createRoundrobinTransportOfClass($config, $class);
        }
        $transports = array_map(fn ($name) => $this->createSymfonyTransport($this->getConfig($name)), $config['mailers']);

        // Symfony's default failover logger retains transport exceptions/debug.
        // Suppress those internals; terminal failure is reported as a fixed event.
        return new $class($transports, $config['retry_after'] ?? 60, new NullLogger);
    }
}
