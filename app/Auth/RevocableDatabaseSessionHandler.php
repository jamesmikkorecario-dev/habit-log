<?php

namespace App\Auth;

use App\Actions\Accounts\SessionSecurity;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Str;

class RevocableDatabaseSessionHandler extends DatabaseSessionHandler
{
    public function write($sessionId, $data): bool
    {
        $request = $this->container->make('request');
        $user = $request->user();
        if (! SessionSecurity::supported() || ! $user) {
            return parent::write($sessionId, $data);
        }

        return $this->connection->transaction(function () use ($sessionId, $data, $request, $user) {
            // Serialize the final write with revocation. A late response must not
            // recreate a revoked ID, migrate its origin, or mint a stale login.
            $origin = $request->session()->get('revocation_origin', hash('sha256', $sessionId));
            if (! app(SessionSecurity::class)->admitted($user, $request)
                || SessionSecurity::revoked(hash('sha256', $sessionId), $origin)) {
                return true;
            }

            return parent::write($sessionId, $data);
        });
    }

    protected function getDefaultPayload($data)
    {
        $payload = parent::getDefaultPayload($data);
        if (SessionSecurity::supported()) {
            $request = $this->container->make('request');
            $payload['origin_hash'] = $request->session()->get('revocation_origin', hash('sha256', $request->session()->getId()));
        }

        return $payload;
    }

    protected function performInsert($sessionId, $payload)
    {
        if (SessionSecurity::supported()) {
            $payload['management_id'] = (string) Str::uuid();
        }

        return parent::performInsert($sessionId, $payload);
    }
}
