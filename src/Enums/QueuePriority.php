<?php

declare(strict_types=1);

namespace Larena\Queue\Enums;

enum QueuePriority: string
{
    case Critical = 'critical';
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';
    case Maintenance = 'maintenance';

    public function weight(): int
    {
        return match ($this) {
            self::Critical => 500,
            self::High => 400,
            self::Normal => 300,
            self::Low => 200,
            self::Maintenance => 100,
        };
    }

    public static function fromWeight(int $weight): self
    {
        return match ($weight) {
            500 => self::Critical,
            400 => self::High,
            300 => self::Normal,
            200 => self::Low,
            100 => self::Maintenance,
            default => throw new \UnexpectedValueException('Unknown Queue priority weight.'),
        };
    }
}
