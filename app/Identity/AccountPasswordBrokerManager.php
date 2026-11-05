<?php

namespace App\Identity;

use Illuminate\Auth\Passwords\PasswordBrokerManager;

final class AccountPasswordBrokerManager extends PasswordBrokerManager
{
    protected function createTokenRepository(array $config)
    {
        if (($config['driver'] ?? null) !== 'institution-account') {
            return parent::createTokenRepository($config);
        }
        $key = $this->app['config']['app.key'];
        $key = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7)) : $key;

        return new AccountTokenRepository($this->app['db']->connection(), $this->app['hash'],
            $config['table'], $key, $config['expire'] * 60, $config['throttle']);
    }
}
