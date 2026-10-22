<?php

declare(strict_types=1);

namespace Larena\Queue\Contracts;

/**
 * A job descriptor that bounds how many times its handler may answer «not yet»
 * ({@see \Larena\Queue\Data\QueueJobResult::retryLater()}). A descriptor without it gets
 * {@see DEFAULT_MAX_DEFERRALS}. Deferrals do not spend the job's attempts.
 */
interface DeferrableJobDescriptor extends JobDescriptor
{
    /** About a day of waiting at five minutes a deferral. */
    public const int DEFAULT_MAX_DEFERRALS = 288;

    /** The most deferrals the job may make before it fails with job_deferral_limit_reached (0 to 100 000). */
    public function maxDeferrals(): int;
}
