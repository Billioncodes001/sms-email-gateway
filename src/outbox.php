<?php
declare(strict_types=1);
require_once __DIR__ . '/provider.php';

function initialize_test_outbox(): void {
    database()->exec("CREATE TABLE IF NOT EXISTS test_people (
        id INTEGER PRIMARY KEY, name TEXT NOT NULL, suppressed INTEGER NOT NULL DEFAULT 0,
        version INTEGER NOT NULL DEFAULT 1, created TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE IF NOT EXISTS test_permissions (
        person_id INTEGER NOT NULL REFERENCES test_people(id), channel TEXT NOT NULL CHECK(channel IN ('Email','SMS')),
        destination TEXT NOT NULL COLLATE NOCASE, opted_in INTEGER NOT NULL DEFAULT 0,
        suppressed INTEGER NOT NULL DEFAULT 0, evidence TEXT NOT NULL DEFAULT '',
        PRIMARY KEY(person_id,channel), UNIQUE(channel,destination));
        CREATE TABLE IF NOT EXISTS test_messages (
        id INTEGER PRIMARY KEY, person_id INTEGER NOT NULL REFERENCES test_people(id), channel TEXT NOT NULL,
        destination TEXT NOT NULL, title TEXT NOT NULL, body TEXT NOT NULL, scenario TEXT NOT NULL,
        state TEXT NOT NULL DEFAULT 'draft', version INTEGER NOT NULL DEFAULT 1,
        created TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE IF NOT EXISTS test_attempts (
        id TEXT PRIMARY KEY, message_id INTEGER NOT NULL REFERENCES test_messages(id), number INTEGER NOT NULL,
        state TEXT NOT NULL DEFAULT 'prepared', sequence INTEGER NOT NULL DEFAULT 0,
        created TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(message_id,number));
        CREATE TABLE IF NOT EXISTS test_events (
        event_id TEXT PRIMARY KEY, attempt_id TEXT NOT NULL REFERENCES test_attempts(id), digest TEXT NOT NULL,
        state TEXT NOT NULL, sequence INTEGER NOT NULL, disposition TEXT NOT NULL,
        created TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE IF NOT EXISTS test_audit (
        id INTEGER PRIMARY KEY, person_id INTEGER NOT NULL REFERENCES test_people(id), channel TEXT,
        action TEXT NOT NULL, evidence TEXT NOT NULL, created TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE IF NOT EXISTS test_operations (
        id TEXT PRIMARY KEY, digest TEXT NOT NULL, result TEXT NOT NULL, created TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
        CREATE INDEX IF NOT EXISTS test_attempt_message ON test_attempts(message_id,number);
        CREATE INDEX IF NOT EXISTS test_message_person ON test_messages(person_id,channel,state);");
}

function transaction(callable $work): mixed {
    $db = database(); $db->exec('BEGIN IMMEDIATE');
    try { $result = $work(); $db->exec('COMMIT'); return $result; }
    catch (Throwable $error) { $db->exec('ROLLBACK'); throw $error; }
}

function operation(string $id, string $action, array $input, callable $work): mixed {
    if (!preg_match('/^[a-f0-9]{32}$/D', $id)) throw new InvalidArgumentException('Invalid operation key. Reload the form.');
    unset($input['csrf'], $input['operation']); ksort($input);
    $digest = hash('sha256', json_encode([$action, $input], JSON_THROW_ON_ERROR));
    return transaction(function () use ($id, $digest, $work) {
        $saved = run('SELECT * FROM test_operations WHERE id=?', [$id])->fetch();
        if ($saved) {
            if (!hash_equals($saved['digest'], $digest)) throw new InvalidArgumentException('This operation key was already used for different input. Reload before changing it.');
            return json_decode($saved['result'], true, 512, JSON_THROW_ON_ERROR);
        }
        if ((int)run('SELECT COUNT(*) FROM test_operations')->fetchColumn() >= 10000) throw new InvalidArgumentException('Local operation limit reached. Retain a backup; start a separate test database.');
        $result = $work();
        run('INSERT INTO test_operations(id,digest,result) VALUES(?,?,?)', [$id, $digest, json_encode($result, JSON_THROW_ON_ERROR)]);
        return $result;
    });
}

function positive_id(array $input, string $key): int {
    $value = $input[$key] ?? '';
    if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]{0,8}$/D', (string)$value)) throw new InvalidArgumentException('Invalid ' . $key . '.');
    return (int)$value;
}

function test_channel(array $input): string {
    $channel = input_text($input, 'channel');
    if (!in_array($channel, ['Email', 'SMS'], true)) throw new InvalidArgumentException('Choose Email or SMS.');
    return $channel;
}

function test_destination(string $channel, string $value): string {
    $value = strtolower(trim($value));
    if ($channel === 'Email' && filter_var($value, FILTER_VALIDATE_EMAIL) && preg_match('/@(example\.com|example\.net|example\.org|example\.test)$/D', $value)) return $value;
    if ($channel === 'SMS' && preg_match('/^\+120255501[0-9]{2}$/D', $value)) return $value;
    throw new InvalidArgumentException($channel === 'Email'
        ? 'Use a synthetic email at example.com, example.net, example.org or example.test.'
        : 'Use a fictional test number from +12025550100 to +12025550199.');
}

function required_text(array $input, string $key, int $limit = 160): string {
    $value = input_text($input, $key, $limit);
    if ($value === '' || !mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) throw new InvalidArgumentException('Enter valid ' . str_replace('_', ' ', $key) . '.');
    return $value;
}

function create_test_person(array $input): int {
    return operation(input_text($input, 'operation'), 'person', $input, function () use ($input): int {
        if ((int)run('SELECT COUNT(*) FROM test_people')->fetchColumn() >= 200) throw new InvalidArgumentException('This local pilot is limited to 200 synthetic recipients.');
        $name = required_text($input, 'name');
        $channels = [];
        foreach (['Email' => 'email', 'SMS' => 'sms'] as $channel => $key) {
            $destination = input_text($input, $key);
            if ($destination === '') {
                if (($input[$key . '_consent'] ?? '') === 'yes') throw new InvalidArgumentException('Add a ' . $channel . ' destination before recording consent.');
                continue;
            }
            $opted = ($input[$key . '_consent'] ?? '') === 'yes';
            $channels[] = [$channel, test_destination($channel, $destination), $opted ? 1 : 0, $opted ? required_text($input, $key . '_evidence', 500) : ''];
        }
        if (!$channels) throw new InvalidArgumentException('Add at least one synthetic destination.');
        run('INSERT INTO test_people(name) VALUES(?)', [$name]);
        $id = (int)database()->lastInsertId();
        foreach ($channels as [$channel, $destination, $opted, $evidence]) {
            if (run('SELECT 1 FROM test_permissions WHERE channel=? AND destination=?', [$channel, $destination])->fetchColumn()) throw new InvalidArgumentException('This destination already belongs to a test recipient, including suppressed recipients.');
            run('INSERT INTO test_permissions VALUES(?,?,?,?,?,?)', [$id, $channel, $destination, $opted, 0, $evidence]);
            run('INSERT INTO test_audit(person_id,channel,action,evidence) VALUES(?,?,?,?)', [$id, $channel, $opted ? 'explicit_opt_in' : 'not_subscribed', $evidence]);
        }
        return $id;
    });
}

function change_permission(array $input): int {
    return operation(input_text($input, 'operation'), 'permission', $input, function () use ($input): int {
        $id = positive_id($input, 'id'); $version = positive_id($input, 'version');
        $person = run('SELECT * FROM test_people WHERE id=?', [$id])->fetch();
        if (!$person || (int)$person['version'] !== $version) throw new InvalidArgumentException('Recipient changed in another page. Reload and review before changing consent.');
        $mode = input_text($input, 'mode');
        if (!in_array($mode, ['global', 'unsubscribe', 'optin'], true)) throw new InvalidArgumentException('Invalid permission action.');
        $channel = $mode === 'global' ? null : test_channel($input);
        $evidence = required_text($input, 'evidence', 500);
        if ($mode === 'global') {
            run('UPDATE test_people SET suppressed=1 WHERE id=?', [$id]);
        } else {
            $permission = run('SELECT * FROM test_permissions WHERE person_id=? AND channel=?', [$id, $channel])->fetch();
            if (!$permission) throw new InvalidArgumentException('No destination for that channel.');
            if ($mode === 'optin') {
                if ($person['suppressed'] || $permission['suppressed']) throw new InvalidArgumentException('Suppression is permanent in this test pilot. It cannot be bypassed by re-opting in.');
                if (($input['explicit'] ?? '') !== 'yes') throw new InvalidArgumentException('Explicit channel opt-in must be checked.');
                run('UPDATE test_permissions SET opted_in=1,evidence=? WHERE person_id=? AND channel=?', [$evidence, $id, $channel]);
            } else {
                run('UPDATE test_permissions SET opted_in=0,suppressed=1 WHERE person_id=? AND channel=?', [$id, $channel]);
            }
        }
        run('UPDATE test_people SET version=version+1 WHERE id=?', [$id]);
        run('INSERT INTO test_audit(person_id,channel,action,evidence) VALUES(?,?,?,?)', [$id, $channel, $mode, $evidence]);
        if ($mode !== 'optin') {
            $sql = "UPDATE test_messages SET state='suppressed',version=version+1,updated=CURRENT_TIMESTAMP WHERE person_id=? AND state IN ('queued','submitted','failed')";
            run($sql . ($channel ? ' AND channel=?' : ''), $channel ? [$id, $channel] : [$id]);
        }
        return $id;
    });
}

function permission_allows(array $message): bool {
    return (bool)run('SELECT 1 FROM test_people p JOIN test_permissions c ON c.person_id=p.id WHERE p.id=? AND c.channel=? AND c.destination=? AND p.suppressed=0 AND c.suppressed=0 AND c.opted_in=1', [$message['person_id'], $message['channel'], $message['destination']])->fetchColumn();
}

function create_test_message(array $input): int {
    return operation(input_text($input, 'operation'), 'draft', $input, function () use ($input): int {
        if ((int)run('SELECT COUNT(*) FROM test_messages')->fetchColumn() >= 500) throw new InvalidArgumentException('This pilot is limited to 500 single-recipient messages.');
        $id = positive_id($input, 'person_id'); $channel = test_channel($input);
        $permission = run('SELECT * FROM test_permissions WHERE person_id=? AND channel=?', [$id, $channel])->fetch();
        if (!$permission) throw new InvalidArgumentException('This recipient has no destination for that channel.');
        $scenario = input_text($input, 'scenario');
        if (!in_array($scenario, ['success', 'fail_once', 'always_fail', 'hold'], true)) throw new InvalidArgumentException('Choose a local test scenario.');
        $title = required_text($input, 'title'); $body = required_text($input, 'body', $channel === 'SMS' ? 480 : 4000);
        run('INSERT INTO test_messages(person_id,channel,destination,title,body,scenario) VALUES(?,?,?,?,?,?)', [$id, $channel, $permission['destination'], $title, $body, $scenario]);
        return (int)database()->lastInsertId();
    });
}

function queue_test_message(array $input, bool $retry = false): int {
    return operation(input_text($input, 'operation'), $retry ? 'retry' : 'queue', $input, function () use ($input, $retry): int {
        $id = positive_id($input, 'id');
        $message = run('SELECT * FROM test_messages WHERE id=?', [$id])->fetch();
        if (!$message || (int)$message['version'] !== positive_id($input, 'version')) throw new InvalidArgumentException('Message changed. Reload to review the current state.');
        if ($message['state'] !== ($retry ? 'failed' : 'draft')) throw new InvalidArgumentException('This message cannot be queued from its current state.');
        if (!permission_allows($message)) throw new InvalidArgumentException('Not queued: explicit channel consent is missing or the recipient is suppressed.');
        if ((int)run('SELECT COUNT(*) FROM test_attempts WHERE message_id=?', [$id])->fetchColumn() >= 5) throw new InvalidArgumentException('Five-attempt local limit reached. No further retries.');
        run("UPDATE test_messages SET state='queued',version=version+1,updated=CURRENT_TIMESTAMP WHERE id=?", [$id]);
        return $id;
    });
}

function prepare_test_attempt(int $id): ?array {
    return transaction(fn() => reserve_test_attempt($id));
}

function reserve_test_attempt(int $id): ?array {
    $message = run('SELECT * FROM test_messages WHERE id=?', [$id])->fetch();
    if (!$message) throw new InvalidArgumentException('Message not found.');
    if (!in_array($message['state'], ['queued', 'submitted'], true)) return null;
    // This check and attempt reservation share the write lock with consent changes.
    if (!permission_allows($message)) {
        run("UPDATE test_messages SET state='suppressed',version=version+1,updated=CURRENT_TIMESTAMP WHERE id=?", [$id]);
        return null;
    }
    test_destination($message['channel'], $message['destination']);
    if ($message['state'] === 'submitted') return run('SELECT * FROM test_attempts WHERE message_id=? ORDER BY number DESC LIMIT 1', [$id])->fetch() ?: null;
    $number = 1 + (int)run('SELECT COUNT(*) FROM test_attempts WHERE message_id=?', [$id])->fetchColumn();
    if ($number > 5) throw new InvalidArgumentException('Five-attempt local limit reached.');
    $attemptId = bin2hex(random_bytes(16));
    run('INSERT INTO test_attempts(id,message_id,number) VALUES(?,?,?)', [$attemptId, $id, $number]);
    run("UPDATE test_messages SET state='submitted',version=version+1,updated=CURRENT_TIMESTAMP WHERE id=?", [$id]);
    return run('SELECT * FROM test_attempts WHERE id=?', [$attemptId])->fetch();
}

function dispatch_test_message(int $id, bool $complete = false): void {
    test_provider(); callback_secret();
    $attempt = prepare_test_attempt($id);
    if ($attempt) process_test_attempt($id, $attempt, $complete);
}

function dispatch_test_request(array $input, bool $complete = false): void {
    test_provider(); callback_secret();
    $id = positive_id($input, 'id');
    $attemptId = operation(input_text($input, 'operation'), $complete ? 'complete' : 'dispatch', $input, function () use ($input, $id) {
        $message = run('SELECT * FROM test_messages WHERE id=?', [$id])->fetch();
        if (!$message || (int)$message['version'] !== positive_id($input, 'version')) throw new InvalidArgumentException('Message changed. Reload before dispatching the current attempt.');
        return reserve_test_attempt($id)['id'] ?? null;
    });
    // A replay resolves the original attempt, never a later queued retry.
    if ($attemptId) process_test_attempt($id, run('SELECT * FROM test_attempts WHERE id=?', [$attemptId])->fetch(), $complete);
}

function process_test_attempt(int $id, array $attempt, bool $complete): void {
    $provider = test_provider();
    $message = run('SELECT * FROM test_messages WHERE id=?', [$id])->fetch();
    if (!permission_allows($message)) return;
    // No HTTP, SMTP, SMS, queue broker or delivery SDK exists at this boundary.
    foreach ($provider->events($message, $attempt, $complete) as $event) receive_test_callback(...signed_local_event($event));
}

function receive_test_callback(string $raw, string $timestamp, string $signature, ?int $now = null): string {
    if (strlen($raw) > 2048 || !preg_match('/^[0-9]{10}$/D', $timestamp) || abs(($now ?? time()) - (int)$timestamp) > 300 || !preg_match('/^[a-f0-9]{64}$/D', $signature) || !hash_equals(hash_hmac('sha256', $timestamp . '.' . $raw, callback_secret()), $signature)) {
        throw new InvalidArgumentException('Invalid or expired local callback signature.');
    }
    try { $event = json_decode($raw, true, 8, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new InvalidArgumentException('Invalid callback JSON.'); }
    if (!is_array($event) || count($event) !== 4 || !is_string($event['event_id'] ?? null) || !preg_match('/^[a-zA-Z0-9-]{1,100}$/D', $event['event_id']) || !is_string($event['attempt_id'] ?? null) || !preg_match('/^[a-f0-9]{32}$/D', $event['attempt_id']) || !is_int($event['sequence'] ?? null) || !in_array($event['state'] ?? null, ['accepted', 'simulated', 'failed'], true) || $event['sequence'] !== ($event['state'] === 'accepted' ? 1 : 2)) throw new InvalidArgumentException('Invalid callback event.');
    return transaction(function () use ($event, $raw): string {
        $digest = hash('sha256', $raw);
        $old = run('SELECT * FROM test_events WHERE event_id=?', [$event['event_id']])->fetch();
        if ($old) {
            if (!hash_equals($old['digest'], $digest)) throw new InvalidArgumentException('Callback event ID conflict.');
            return 'duplicate';
        }
        $attempt = run('SELECT * FROM test_attempts WHERE id=?', [$event['attempt_id']])->fetch();
        if (!$attempt) throw new InvalidArgumentException('Unknown callback attempt.');
        if ((int)run('SELECT COUNT(*) FROM test_events WHERE attempt_id=?', [$attempt['id']])->fetchColumn() >= 20) throw new InvalidArgumentException('Callback audit limit reached for this attempt.');
        $message = run('SELECT * FROM test_messages WHERE id=?', [$attempt['message_id']])->fetch();
        $latest = run('SELECT id FROM test_attempts WHERE message_id=? ORDER BY number DESC LIMIT 1', [$message['id']])->fetchColumn();
        $disposition = 'applied';
        if ($attempt['id'] !== $latest) $disposition = 'ignored_old_attempt';
        elseif ($event['sequence'] <= (int)$attempt['sequence'] || in_array($attempt['state'], ['simulated', 'failed'], true)) $disposition = 'ignored_out_of_order';
        if ($disposition === 'applied') {
            run('UPDATE test_attempts SET state=?,sequence=?,updated=CURRENT_TIMESTAMP WHERE id=?', [$event['state'], $event['sequence'], $attempt['id']]);
            if ($message['state'] === 'suppressed' || !permission_allows($message)) {
                $state = 'suppressed'; $disposition = 'recorded_but_suppressed';
            } else { $state = $event['state'] === 'accepted' ? 'submitted' : $event['state']; }
            run('UPDATE test_messages SET state=?,version=version+1,updated=CURRENT_TIMESTAMP WHERE id=?', [$state, $message['id']]);
        }
        run('INSERT INTO test_events(event_id,attempt_id,digest,state,sequence,disposition) VALUES(?,?,?,?,?,?)', [$event['event_id'], $attempt['id'], $digest, $event['state'], $event['sequence'], $disposition]);
        return $disposition;
    });
}
