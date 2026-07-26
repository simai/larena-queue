<?php

declare(strict_types=1);

namespace Larena\Queue\Storage;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Larena\Queue\Contracts\JobDescriptor;
use Larena\Queue\Data\DispatchRequest;
use Larena\Queue\Data\DispatchResult;
use Larena\Queue\Data\QueueJobResult;
use Larena\Queue\Data\QueueJobSnapshot;
use Larena\Queue\Data\QueueLease;
use Larena\Queue\Enums\JobStatus;
use Larena\Queue\Enums\QueuePriority;
use Larena\Queue\Exceptions\QueueOperationFailed;
use Throwable;

final readonly class DatabaseQueueStore
{
    private const DATE_FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(private ConnectionInterface $database)
    {
    }

    public function dispatch(
        JobDescriptor $descriptor,
        DispatchRequest $request,
        DateTimeImmutable $now,
    ): DispatchResult {
        $payloadJson = $this->canonicalJson($request->payload);
        $jobId = bin2hex(random_bytes(16));
        $timestamp = $this->date($now);

        return $this->database->transaction(function () use (
            $descriptor,
            $request,
            $payloadJson,
            $jobId,
            $timestamp,
            $now,
        ): DispatchResult {
            try {
                $inserted = $this->database->table('larena_queue_jobs')->insertOrIgnore([
                    'id' => $jobId,
                    'job_type' => $descriptor->jobType(),
                    'handler_ref' => $descriptor->handlerRef(),
                    'payload_json' => $payloadJson,
                    'payload_sha256' => hash('sha256', $payloadJson),
                    'idempotency_key' => $request->idempotencyKey,
                    'correlation_id' => $request->correlationId,
                    'priority' => $descriptor->priority()->weight(),
                    'status' => JobStatus::Queued->value,
                    'attempts' => 0,
                    'max_attempts' => $descriptor->maxAttempts(),
                    'timeout_seconds' => $descriptor->timeoutSeconds(),
                    'retry_delay_seconds' => $descriptor->retryDelaySeconds(),
                    'lease_seconds' => $descriptor->leaseSeconds(),
                    'available_at' => $this->date($request->availableAt ?? $now),
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
            } catch (Throwable) {
                $inserted = 0;
            }

            if ($inserted === 1) {
                return new DispatchResult($jobId, false);
            }

            $existing = $this->database->table('larena_queue_jobs')
                ->where('job_type', $descriptor->jobType())
                ->where('idempotency_key', $request->idempotencyKey)
                ->first();
            if ($existing === null) {
                throw new QueueOperationFailed('dispatch_persistence_failed');
            }
            if (
                !hash_equals((string) $existing->payload_sha256, hash('sha256', $payloadJson))
                || (string) $existing->correlation_id !== $request->correlationId
            ) {
                throw new QueueOperationFailed('idempotency_conflict');
            }

            return new DispatchResult((string) $existing->id, true);
        });
    }

    public function find(string $jobId): ?QueueJobSnapshot
    {
        $row = $this->database->table('larena_queue_jobs')->where('id', $jobId)->first();

        return $row === null ? null : $this->snapshot($row);
    }

    public function require(string $jobId): QueueJobSnapshot
    {
        return $this->find($jobId) ?? throw new QueueOperationFailed('job_not_found');
    }

    public function claim(string $workerRef, DateTimeImmutable $now): ?QueueLease
    {
        if ($workerRef === '' || strlen($workerRef) > 128) {
            throw new QueueOperationFailed('worker_ref_invalid');
        }

        return $this->database->transaction(function () use ($workerRef, $now): ?QueueLease {
            $this->reclaimExpiredInsideTransaction($now);
            $row = $this->database->table('larena_queue_jobs')
                ->whereIn('status', [JobStatus::Queued->value, JobStatus::Retrying->value])
                ->whereNull('cancel_requested_at')
                ->where('available_at', '<=', $this->date($now))
                ->orderByDesc('priority')
                ->orderBy('available_at')
                ->orderBy('created_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return null;
            }

            $token = bin2hex(random_bytes(32));
            $expiresAt = $now->add(new DateInterval('PT'.(int) $row->lease_seconds.'S'));
            $attempt = (int) $row->attempts + 1;
            $updated = $this->database->table('larena_queue_jobs')
                ->where('id', (string) $row->id)
                ->where('status', (string) $row->status)
                ->whereNull('lease_token_hash')
                ->update([
                    'status' => JobStatus::Running->value,
                    'attempts' => $attempt,
                    'lease_owner_hash' => hash('sha256', $workerRef),
                    'lease_token_hash' => hash('sha256', $token),
                    'lease_expires_at' => $this->date($expiresAt),
                    'heartbeat_at' => $this->date($now),
                    'failure_reason' => null,
                    'updated_at' => $this->date($now),
                ]);
            if ($updated !== 1) {
                return null;
            }

            $this->database->table('larena_queue_attempts')->insert([
                'job_id' => (string) $row->id,
                'attempt_number' => $attempt,
                'status' => JobStatus::Running->value,
                'started_at' => $this->date($now),
            ]);

            return new QueueLease(
                $this->require((string) $row->id),
                $token,
                $expiresAt,
            );
        });
    }

    public function heartbeat(
        QueueLease $lease,
        DateTimeImmutable $now,
        DateTimeImmutable $expiresAt,
    ): void
    {
        $updated = $this->leaseQuery($lease)
            ->whereNull('cancel_requested_at')
            ->where('lease_expires_at', '>', $this->date($now))
            ->update([
                'heartbeat_at' => $this->date($now),
                'lease_expires_at' => $this->date($expiresAt),
                'updated_at' => $this->date($now),
            ]);
        if ($updated === 1) {
            return;
        }
        if ($updated === 0 && $this->leaseQuery($lease)
            ->whereNull('cancel_requested_at')
            ->where('lease_expires_at', '>', $this->date($now))
            ->exists()) {
            return;
        }
        throw new QueueOperationFailed('lease_heartbeat_rejected');
    }

    public function cancellationRequested(QueueLease $lease): bool
    {
        $row = $this->leaseQuery($lease)->first(['cancel_requested_at']);
        if ($row === null) {
            throw new QueueOperationFailed('lease_not_owned');
        }

        return $row->cancel_requested_at !== null;
    }

    public function complete(QueueLease $lease, QueueJobResult $result, DateTimeImmutable $now): void
    {
        if (!$result->succeeded) {
            throw new QueueOperationFailed('completion_result_invalid');
        }
        $this->finishLease(
            $lease,
            JobStatus::Completed,
            null,
            $result->metadata,
            $now,
        );
    }

    public function fail(
        QueueLease $lease,
        string $reasonCode,
        bool $retryable,
        bool $timedOut,
        array $metadata,
        DateTimeImmutable $now,
    ): void {
        $job = $this->require($lease->job->id);
        if ($retryable && $job->attempts < $job->maxAttempts) {
            $this->database->transaction(function () use ($lease, $reasonCode, $metadata, $now, $job): void {
                $this->finishAttempt($lease, JobStatus::Retrying, $reasonCode, $now);
                $updated = $this->leaseQuery($lease)->update([
                    'status' => JobStatus::Retrying->value,
                    'available_at' => $this->date(
                        $now->add(new DateInterval('PT'.$job->retryDelaySeconds.'S')),
                    ),
                    'lease_owner_hash' => null,
                    'lease_token_hash' => null,
                    'lease_expires_at' => null,
                    'heartbeat_at' => null,
                    'failure_reason' => $this->reason($reasonCode),
                    'result_json' => $this->resultJson($metadata),
                    'updated_at' => $this->date($now),
                ]);
                if ($updated !== 1) {
                    throw new QueueOperationFailed('lease_not_owned');
                }
            });

            return;
        }

        $this->finishLease(
            $lease,
            $timedOut ? JobStatus::TimedOut : JobStatus::Failed,
            $reasonCode,
            $metadata,
            $now,
        );
    }

    public function cancelLease(QueueLease $lease, DateTimeImmutable $now): void
    {
        $this->finishLease($lease, JobStatus::Cancelled, 'cancel_requested', [], $now);
    }

    public function requestCancellation(string $jobId, DateTimeImmutable $now): QueueJobSnapshot
    {
        return $this->database->transaction(function () use ($jobId, $now): QueueJobSnapshot {
            $job = $this->require($jobId);
            if ($job->status->isTerminal()) {
                return $job;
            }
            $values = [
                'cancel_requested_at' => $this->date($now),
                'updated_at' => $this->date($now),
            ];
            if (in_array($job->status, [JobStatus::Queued, JobStatus::Retrying], true)) {
                $values += [
                    'status' => JobStatus::Cancelled->value,
                    'failure_reason' => 'cancel_requested',
                    'completed_at' => $this->date($now),
                ];
            }
            $this->database->table('larena_queue_jobs')->where('id', $jobId)->update($values);

            return $this->require($jobId);
        });
    }

    public function retry(string $jobId, DateTimeImmutable $now): QueueJobSnapshot
    {
        return $this->database->transaction(function () use ($jobId, $now): QueueJobSnapshot {
            $job = $this->require($jobId);
            if (!in_array($job->status, [JobStatus::Failed, JobStatus::TimedOut, JobStatus::Cancelled], true)) {
                throw new QueueOperationFailed('job_not_retryable');
            }
            $this->database->table('larena_queue_attempts')->where('job_id', $jobId)->delete();
            $this->database->table('larena_queue_jobs')->where('id', $jobId)->update([
                'status' => JobStatus::Queued->value,
                'attempts' => 0,
                'available_at' => $this->date($now),
                'lease_owner_hash' => null,
                'lease_token_hash' => null,
                'lease_expires_at' => null,
                'heartbeat_at' => null,
                'cancel_requested_at' => null,
                'failure_reason' => null,
                'result_json' => null,
                'completed_at' => null,
                'updated_at' => $this->date($now),
            ]);

            return $this->require($jobId);
        });
    }

    public function delete(string $jobId): void
    {
        $job = $this->require($jobId);
        if (!$job->isTerminal()) {
            throw new QueueOperationFailed('job_not_terminal');
        }
        $this->database->table('larena_queue_jobs')->where('id', $jobId)->delete();
    }

    public function reclaimExpired(DateTimeImmutable $now): int
    {
        return $this->database->transaction(
            fn (): int => $this->reclaimExpiredInsideTransaction($now),
        );
    }

    private function reclaimExpiredInsideTransaction(DateTimeImmutable $now): int
    {
        $rows = $this->database->table('larena_queue_jobs')
            ->where('status', JobStatus::Running->value)
            ->whereNotNull('lease_expires_at')
            ->where('lease_expires_at', '<=', $this->date($now))
            ->lockForUpdate()
            ->get();
        $count = 0;
        foreach ($rows as $row) {
            $cancelled = $row->cancel_requested_at !== null;
            $exhausted = (int) $row->attempts >= (int) $row->max_attempts;
            $status = $cancelled
                ? JobStatus::Cancelled
                : ($exhausted ? JobStatus::Failed : JobStatus::Retrying);
            $reason = $cancelled
                ? 'cancel_requested'
                : ($exhausted ? 'lease_expired_attempts_exhausted' : 'lease_expired');

            $this->database->table('larena_queue_attempts')
                ->where('job_id', (string) $row->id)
                ->where('attempt_number', (int) $row->attempts)
                ->update([
                    'status' => $status->value,
                    'finished_at' => $this->date($now),
                    'failure_reason' => $reason,
                ]);
            $this->database->table('larena_queue_jobs')
                ->where('id', (string) $row->id)
                ->where('status', JobStatus::Running->value)
                ->update([
                    'status' => $status->value,
                    'available_at' => $this->date($now),
                    'lease_owner_hash' => null,
                    'lease_token_hash' => null,
                    'lease_expires_at' => null,
                    'heartbeat_at' => null,
                    'failure_reason' => $reason,
                    'completed_at' => $status->isTerminal() ? $this->date($now) : null,
                    'updated_at' => $this->date($now),
                ]);
            $count++;
        }

        return $count;
    }

    /**
     * @param array<string, scalar|null> $metadata
     */
    private function finishLease(
        QueueLease $lease,
        JobStatus $status,
        ?string $reason,
        array $metadata,
        DateTimeImmutable $now,
    ): void {
        $this->database->transaction(function () use ($lease, $status, $reason, $metadata, $now): void {
            $this->finishAttempt($lease, $status, $reason, $now);
            $updated = $this->leaseQuery($lease)->update([
                'status' => $status->value,
                'lease_owner_hash' => null,
                'lease_token_hash' => null,
                'lease_expires_at' => null,
                'heartbeat_at' => null,
                'failure_reason' => $reason === null ? null : $this->reason($reason),
                'result_json' => $this->resultJson($metadata),
                'completed_at' => $this->date($now),
                'updated_at' => $this->date($now),
            ]);
            if ($updated !== 1) {
                throw new QueueOperationFailed('lease_not_owned');
            }
        });
    }

    private function finishAttempt(
        QueueLease $lease,
        JobStatus $status,
        ?string $reason,
        DateTimeImmutable $now,
    ): void {
        $this->database->table('larena_queue_attempts')
            ->where('job_id', $lease->job->id)
            ->where('attempt_number', $lease->job->attempts)
            ->update([
                'status' => $status->value,
                'finished_at' => $this->date($now),
                'failure_reason' => $reason === null ? null : $this->reason($reason),
            ]);
    }

    private function leaseQuery(QueueLease $lease): \Illuminate\Database\Query\Builder
    {
        return $this->database->table('larena_queue_jobs')
            ->where('id', $lease->job->id)
            ->where('status', JobStatus::Running->value)
            ->where('lease_token_hash', hash('sha256', $lease->token));
    }

    private function snapshot(object $row): QueueJobSnapshot
    {
        $payload = json_decode((string) $row->payload_json, true, 512, JSON_THROW_ON_ERROR);
        $result = $row->result_json === null
            ? []
            : json_decode((string) $row->result_json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || !is_array($result)) {
            throw new QueueOperationFailed('persisted_job_shape_invalid');
        }

        return new QueueJobSnapshot(
            id: (string) $row->id,
            jobType: (string) $row->job_type,
            handlerRef: (string) $row->handler_ref,
            payload: $payload,
            payloadSha256: (string) $row->payload_sha256,
            idempotencyKey: (string) $row->idempotency_key,
            correlationId: (string) $row->correlation_id,
            priority: QueuePriority::fromWeight((int) $row->priority),
            status: JobStatus::from((string) $row->status),
            attempts: (int) $row->attempts,
            maxAttempts: (int) $row->max_attempts,
            timeoutSeconds: (int) $row->timeout_seconds,
            retryDelaySeconds: (int) $row->retry_delay_seconds,
            leaseSeconds: (int) $row->lease_seconds,
            availableAt: $this->parseDate((string) $row->available_at),
            leaseExpiresAt: $this->nullableDate($row->lease_expires_at),
            heartbeatAt: $this->nullableDate($row->heartbeat_at),
            cancelRequestedAt: $this->nullableDate($row->cancel_requested_at),
            failureReason: $row->failure_reason === null ? null : (string) $row->failure_reason,
            result: $result,
            createdAt: $this->parseDate((string) $row->created_at),
            updatedAt: $this->parseDate((string) $row->updated_at),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function canonicalJson(array $payload): string
    {
        $normalize = function (mixed $value) use (&$normalize): mixed {
            if (!is_array($value)) {
                if (!is_scalar($value) && $value !== null) {
                    throw new QueueOperationFailed('payload_value_invalid');
                }

                return $value;
            }
            if (array_is_list($value)) {
                return array_map($normalize, $value);
            }
            ksort($value, SORT_STRING);
            foreach ($value as $key => $item) {
                if (!is_string($key)) {
                    throw new QueueOperationFailed('payload_key_invalid');
                }
                $value[$key] = $normalize($item);
            }

            return $value;
        };

        return json_encode($normalize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, scalar|null> $metadata
     */
    private function resultJson(array $metadata): ?string
    {
        if ($metadata === []) {
            return null;
        }
        ksort($metadata, SORT_STRING);

        return json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function reason(string $reason): string
    {
        if (preg_match('/\A[a-z][a-z0-9_.-]{0,99}\z/D', $reason) !== 1) {
            return 'operation_failed';
        }

        return $reason;
    }

    private function date(DateTimeInterface $value): string
    {
        return DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(self::DATE_FORMAT);
    }

    private function parseDate(string $value): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat(
            '!'.self::DATE_FORMAT,
            $value,
            new DateTimeZone('UTC'),
        );
        if (!$parsed instanceof DateTimeImmutable) {
            throw new QueueOperationFailed('persisted_date_invalid');
        }

        return $parsed;
    }

    private function nullableDate(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : $this->parseDate((string) $value);
    }
}
