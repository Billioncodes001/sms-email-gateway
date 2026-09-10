<?php
declare(strict_types=1);

interface TestProvider {
    /** Pure local computation. The attempt key must survive retries and restarts. */
    public function events(array $message, array $attempt, bool $complete = false): array;
}

final class LocalTestProvider implements TestProvider {
    public function events(array $message, array $attempt, bool $complete = false): array {
        $events = [['sequence' => 1, 'state' => 'accepted']];
        if ($message['scenario'] !== 'hold' || $complete) {
            $failed = $message['scenario'] === 'always_fail' || ($message['scenario'] === 'fail_once' && (int)$attempt['number'] === 1);
            $events[] = ['sequence' => 2, 'state' => $failed ? 'failed' : 'simulated'];
        }
        return array_map(fn(array $event): array => [
            'event_id' => 'local-' . $attempt['id'] . '-' . $event['sequence'],
            'attempt_id' => $attempt['id'],
            ...$event,
        ], $events);
    }
}

function test_provider(): TestProvider {
    if ((getenv('POSTROOM_PROVIDER') ?: 'local-test') !== 'local-test') {
        throw new InvalidArgumentException('Real-provider adapters are disabled. Only local-test is supported.');
    }
    return new LocalTestProvider();
}

function callback_secret(): string {
    $secret = (string)getenv('LOCAL_CALLBACK_SECRET');
    if (strlen($secret) < 32) throw new InvalidArgumentException('Set LOCAL_CALLBACK_SECRET to at least 32 characters to dispatch local tests.');
    return $secret;
}

function signed_local_event(array $event, ?int $timestamp = null): array {
    $raw = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $timestamp ??= time();
    return [$raw, (string)$timestamp, hash_hmac('sha256', $timestamp . '.' . $raw, callback_secret())];
}
