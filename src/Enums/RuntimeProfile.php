<?php

declare(strict_types=1);

namespace Larena\Queue\Enums;

enum RuntimeProfile: string
{
    case LaravelWorker = 'laravel_worker';
    case LarenaDispatcher = 'larena_dispatcher';
    case ArtisanTick = 'artisan_tick';
    case ScriptTick = 'script_tick';
    case SignedWebTick = 'signed_web_tick';

    public function isEmergencyOnly(): bool
    {
        return $this === self::SignedWebTick;
    }
}
