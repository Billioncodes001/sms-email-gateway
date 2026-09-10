<?php
declare(strict_types=1);
$directory = sys_get_temp_dir() . '/postroom-core-' . bin2hex(random_bytes(8));
mkdir($directory, 0700); putenv('DATABASE=' . $directory . '/test.sqlite');
putenv('LOCAL_CALLBACK_SECRET=' . bin2hex(random_bytes(32)));
putenv('POSTROOM_PROVIDER=local-test');
require dirname(__DIR__) . '/src/support.php';
require dirname(__DIR__) . '/src/gateway.php';
require dirname(__DIR__) . '/src/outbox.php';
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejects(callable $work, string $contains = ''): void {
    try { $work(); } catch (InvalidArgumentException $error) { check(str_contains($error->getMessage(), $contains), $error->getMessage()); return; }
    throw new RuntimeException('Expected rejection: ' . $contains);
}
function op(array $input): array { return ['operation'=>bin2hex(random_bytes(16)), ...$input]; }
function person(array $override = []): int {
    static $number = 0; $number++;
    return create_test_person(op(['name'=>'Synthetic ' . $number, 'email'=>'test' . $number . '@example.test', 'email_consent'=>'yes', 'email_evidence'=>'Synthetic Email opt-in fixture', ...$override]));
}
function message(int $person, string $scenario = 'success', string $channel = 'Email'): int {
    return create_test_message(op(['person_id'=>$person, 'channel'=>$channel, 'title'=>'Synthetic test', 'body'=>'Local test, never sent.', 'scenario'=>$scenario]));
}
function row(int $id): array { return run('SELECT * FROM test_messages WHERE id=?', [$id])->fetch(); }
function queue(int $id, bool $retry = false): array {
    $input = op(['id'=>$id, 'version'=>row($id)['version']]); queue_test_message($input, $retry); return $input;
}
function permission(int $id, string $mode, string $channel = 'Email', array $override = []): array {
    $input = op(['id'=>$id, 'version'=>run('SELECT version FROM test_people WHERE id=?', [$id])->fetchColumn(), 'mode'=>$mode, 'channel'=>$channel, 'evidence'=>'Synthetic permission change', 'explicit'=>'yes', ...$override]);
    change_permission($input); return $input;
}
function event(array $attempt, string $state, ?string $id = null): array {
    return ['event_id'=>$id ?? bin2hex(random_bytes(12)), 'attempt_id'=>$attempt['id'], 'sequence'=>$state==='accepted'?1:2, 'state'=>$state];
}
$passed = 0;
function test(string $name, callable $work): void { global $passed; $work(); $passed++; echo "PASS $name\n"; }
try {
    initialize_gateway(); initialize_test_outbox();
    test('legacy records remain byte-for-byte unchanged and are not adopted', function () {
        add_contact(['name'=>'Legacy fixture','destination'=>'legacy@example.com','channel'=>'Email','consent'=>'yes','segment'=>'Community']);
        $id = create_campaign(['title'=>'Old simulation','channel'=>'Email','segment'=>'Community','body'=>'Legacy fixture']); simulate($id);
        $before = [];
        foreach (['contacts','campaigns','outbox'] as $table) $before[$table] = run('SELECT * FROM ' . $table)->fetchAll();
        initialize_gateway(); initialize_test_outbox();
        foreach ($before as $table=>$data) check($data === run('SELECT * FROM ' . $table)->fetchAll(), 'Legacy changed');
        check((int)run('SELECT COUNT(*) FROM test_people')->fetchColumn() === 0, 'No implicit migration of consent');
    });
    test('independent channel opt-in, required evidence and strict synthetic destination validation', function () {
        rejects(fn()=>person(['email'=>'someone@gmail.com']), 'synthetic');
        rejects(fn()=>person(['email_evidence'=>'']), 'evidence');
        rejects(fn()=>person(['sms'=>'+447700900123']), 'fictional');
        rejects(fn()=>person(['sms'=>'','sms_consent'=>'yes']), 'destination');
        $id = person(['sms'=>'+12025550100']);
        $email = message($id); $sms = message($id, 'success', 'SMS');
        check(permission_allows(row($email)) && !permission_allows(row($sms)), 'Channel consent leaked');
        rejects(fn()=>queue($sms), 'consent');
        permission($id, 'optin', 'SMS'); queue($sms); dispatch_test_message($sms);
        check(row($sms)['state'] === 'simulated', 'SMS local flow');
    });
    test('atomic recipient rollback, destination deduplication and durable operation receipts', function () {
        $input = op(['name'=>'Two-channel fixture','email'=>'receipt@example.test','sms'=>'+12025550101']);
        $id = create_test_person($input); check(create_test_person($input) === $id, 'Create replay');
        rejects(fn()=>create_test_person([...$input,'name'=>'Changed']), 'different input');
        $count = run('SELECT COUNT(*) FROM test_people')->fetchColumn();
        rejects(fn()=>person(['email'=>'unique@example.test','sms'=>'+12025550101']), 'already belongs');
        check($count === run('SELECT COUNT(*) FROM test_people')->fetchColumn(), 'Partial recipient write');
        check(!run('SELECT 1 FROM test_permissions WHERE destination=?', ['unique@example.test'])->fetchColumn(), 'Partial permission write');
        rejects(fn()=>person(['email'=>'RECEIPT@example.test']), 'already belongs');
    });
    test('validation rejects bad IDs, control text, oversized UTF-8 SMS and unknown scenarios', function () {
        $id = person();
        $input = op(['person_id'=>$id,'channel'=>'SMS','title'=>'Fixture','body'=>str_repeat('é',241),'scenario'=>'success']);
        rejects(fn()=>create_test_message($input), 'no destination');
        $id = person(['sms'=>'+12025550102','sms_consent'=>'yes','sms_evidence'=>'Synthetic evidence']);
        rejects(fn()=>create_test_message([...$input,'person_id'=>$id]), 'body');
        rejects(fn()=>create_test_message([...$input,'person_id'=>'1x']), 'person_id');
        rejects(fn()=>create_test_message([...$input,'person_id'=>$id,'scenario'=>'real']), 'scenario');
        rejects(fn()=>person(['name'=>"bad\0name"]), 'name');
        rejects(fn()=>create_test_person(['operation'=>str_repeat('a',31)]), 'operation key');
    });
    test('draft and queue replay cannot duplicate a message or dispatch it twice', function () {
        $person = person(); $input = op(['person_id'=>$person,'channel'=>'Email','title'=>'Replay','body'=>'Fixture','scenario'=>'success']);
        $id = create_test_message($input); check(create_test_message($input)===$id, 'Draft receipt');
        $queued = queue($id); queue_test_message($queued); dispatch_test_message($id); dispatch_test_message($id);
        queue_test_message($queued);
        check(row($id)['state']==='simulated', 'Queue receipt must not rewind');
        check((int)run('SELECT COUNT(*) FROM test_attempts WHERE message_id=?',[$id])->fetchColumn()===1, 'Duplicate dispatch');
    });
    test('unsubscribe after queue prevents attempts and cannot be bypassed by re-add', function () {
        $person = person(['email'=>'revoked@example.test','sms'=>'+12025550103','sms_consent'=>'yes','sms_evidence'=>'Synthetic opt-in']);
        $email = message($person); $sms = message($person,'success','SMS'); queue($email); queue($sms);
        permission($person,'unsubscribe'); dispatch_test_message($email); dispatch_test_message($sms);
        check(row($email)['state']==='suppressed' && row($sms)['state']==='simulated','Channel scope');
        check((int)run('SELECT COUNT(*) FROM test_attempts WHERE message_id=?',[$email])->fetchColumn()===0,'Suppressed attempt');
        rejects(fn()=>person(['email'=>'revoked@example.test']),'already belongs');
        rejects(fn()=>permission($person,'optin'),'permanent');
    });
    test('dispatch itself rechecks permission even if a queued row predates revocation', function () {
        $person=person(); $id=message($person); queue($id);
        run('UPDATE test_permissions SET opted_in=0 WHERE person_id=?',[$person]);
        dispatch_test_message($id); check(row($id)['state']==='suppressed','Dispatch check');
        check((int)run('SELECT COUNT(*) FROM test_attempts WHERE message_id=?',[$id])->fetchColumn()===0,'No attempt');
    });
    test('global suppression covers both channels and rejects stale consent grants', function () {
        $person=person(['sms'=>'+12025550104','sms_consent'=>'yes','sms_evidence'=>'Synthetic opt-in']);
        $email=message($person); $sms=message($person,'success','SMS'); queue($email); queue($sms);
        $stale=op(['id'=>$person,'version'=>1,'mode'=>'optin','channel'=>'Email','evidence'=>'Stale','explicit'=>'yes']);
        $input=permission($person,'global'); change_permission($input);
        rejects(fn()=>change_permission($stale),'changed');
        check(row($email)['state']==='suppressed' && row($sms)['state']==='suppressed','Global scope');
        rejects(fn()=>permission($person,'optin','SMS'),'permanent');
    });
    test('spoofed, expired, future, malformed and unknown-attempt callbacks never mutate history', function () {
        $id=message(person(),'hold'); queue($id); $attempt=prepare_test_attempt($id); $event=event($attempt,'simulated');
        [$raw,$ts,$signature]=signed_local_event($event);
        rejects(fn()=>receive_test_callback($raw,$ts,str_repeat('0',64)),'signature');
        rejects(fn()=>receive_test_callback($raw.' ',$ts,$signature),'signature');
        rejects(fn()=>receive_test_callback(...signed_local_event($event,time()-301)),'expired');
        rejects(fn()=>receive_test_callback(...signed_local_event($event,time()+301)),'expired');
        rejects(fn()=>receive_test_callback(...signed_local_event([...$event,'state'=>'delivered'])),'event');
        rejects(fn()=>receive_test_callback(...signed_local_event([...$event,'attempt_id'=>str_repeat('f',32)])),'Unknown');
        check(row($id)['state']==='submitted','Spoof mutated state');
        check((int)run('SELECT COUNT(*) FROM test_events WHERE attempt_id=?',[$attempt['id']])->fetchColumn()===0,'Spoof stored');
    });
    test('signed replay, conflicting event IDs and out-of-order terminal states are safe', function () {
        $id=message(person(),'hold'); queue($id); $attempt=prepare_test_attempt($id);
        $success=event($attempt,'simulated'); $signed=signed_local_event($success);
        check(receive_test_callback(...$signed)==='applied','Signed event');
        check(receive_test_callback(...$signed)==='duplicate','Replay');
        rejects(fn()=>receive_test_callback(...signed_local_event([...$success,'state'=>'failed'])),'conflict');
        check(receive_test_callback(...signed_local_event(event($attempt,'accepted')))==='ignored_out_of_order','Older state');
        check(receive_test_callback(...signed_local_event(event($attempt,'failed')))==='ignored_out_of_order','Conflicting terminal');
        check(row($id)['state']==='simulated','Terminal changed');
    });
    test('deterministic failure, retry receipts and callbacks from old attempts', function () {
        $id=message(person(),'fail_once'); queue($id); dispatch_test_message($id); check(row($id)['state']==='failed','First failure');
        $old=run('SELECT * FROM test_attempts WHERE message_id=?',[$id])->fetch();
        $retry=queue($id,true); queue_test_message($retry,true); dispatch_test_message($id); queue_test_message($retry,true);
        check(row($id)['state']==='simulated','Retry success');
        check(receive_test_callback(...signed_local_event(event($old,'simulated')))==='ignored_old_attempt','Old attempt');
        check((int)run('SELECT COUNT(*) FROM test_attempts WHERE message_id=?',[$id])->fetchColumn()===2,'Duplicate retry');
    });
    test('suppression after provider acceptance stays suppressed on signed terminal callback', function () {
        $person=person(); $id=message($person,'hold'); queue($id); dispatch_test_message($id);
        $attempt=run('SELECT * FROM test_attempts WHERE message_id=?',[$id])->fetch(); permission($person,'global');
        check(receive_test_callback(...signed_local_event(event($attempt,'simulated')))==='recorded_but_suppressed','Suppressed callback');
        check(row($id)['state']==='suppressed','Callback revived suppression');
        check(run('SELECT state FROM test_attempts WHERE id=?',[$attempt['id']])->fetchColumn()==='simulated','Retain provider report');
    });
    test('delayed HTTP dispatch receipt never consumes a newer queued retry', function () {
        $id=message(person(),'fail_once'); queue($id);
        $request=op(['id'=>$id,'version'=>row($id)['version']]); dispatch_test_request($request);
        check(row($id)['state']==='failed','First request'); queue($id,true);
        dispatch_test_request($request);
        check(row($id)['state']==='queued','Delayed dispatch consumed newer retry');
        check((int)run('SELECT COUNT(*) FROM test_attempts WHERE message_id=?',[$id])->fetchColumn()===1,'Delayed duplicate');
        rejects(fn()=>dispatch_test_request([...$request,'operation'=>bin2hex(random_bytes(16))]),'changed');
        dispatch_test_request(op(['id'=>$id,'version'=>row($id)['version']]));
        check(row($id)['state']==='simulated','Fresh retry dispatch');
    });
    test('persistent attempt recovery through two concurrent independent PHP workers', function () {
        $id=message(person()); queue($id); $attempt=prepare_test_attempt($id);
        $workers=[];
        for($i=0;$i<2;$i++) {
            $pipes=[]; $process=proc_open([PHP_BINARY,dirname(__DIR__).'/worker.php',(string)$id],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
            $workers[]=[$process,$pipes];
        }
        foreach($workers as [$process,$pipes]) { $output=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]);fclose($pipes[2]); check(proc_close($process)===0,$output.$error); }
        check(row($id)['state']==='simulated','Persistent recovery');
        check(run('SELECT id FROM test_attempts WHERE message_id=?',[$id])->fetchColumn()===$attempt['id'],'Attempt key changed');
        check((int)run('SELECT COUNT(*) FROM test_events WHERE attempt_id=?',[$attempt['id']])->fetchColumn()===2,'Concurrent callbacks duplicated');
    });
    test('attempt and callback limits bound repeated failures', function () {
        $id=message(person(),'always_fail'); queue($id);
        for($i=0;$i<5;$i++) {dispatch_test_message($id); if($i<4) queue($id,true);}
        rejects(fn()=>queue($id,true),'Five-attempt');
        $attempt=run('SELECT * FROM test_attempts WHERE message_id=? ORDER BY number DESC LIMIT 1',[$id])->fetch();
        for($i=0;$i<18;$i++) receive_test_callback(...signed_local_event(event($attempt,'accepted')));
        rejects(fn()=>receive_test_callback(...signed_local_event(event($attempt,'accepted'))),'audit limit');
        check((int)run('SELECT COUNT(*) FROM test_attempts WHERE message_id=?',[$id])->fetchColumn()===5,'Attempt limit');
    });
    test('real providers and absent signing config fail closed before attempts', function () {
        $id=message(person()); queue($id); putenv('POSTROOM_PROVIDER=twilio');
        rejects(fn()=>dispatch_test_message($id),'disabled'); putenv('POSTROOM_PROVIDER=local-test');
        $secret=getenv('LOCAL_CALLBACK_SECRET');putenv('LOCAL_CALLBACK_SECRET=');
        rejects(fn()=>dispatch_test_message($id),'LOCAL_CALLBACK_SECRET');putenv('LOCAL_CALLBACK_SECRET='.$secret);
        check(row($id)['state']==='queued','Config failure changed state');
        check((int)run('SELECT COUNT(*) FROM test_attempts WHERE message_id=?',[$id])->fetchColumn()===0,'Config failure created attempt');
    });
    test('exact session expiry and password rotation invalidate authorization', function () {
        $session=['authenticated'=>true,'expires'=>1000,'credential'=>'fingerprint'];
        check(valid_session($session,999,'fingerprint'),'Premature expiry');
        check(!valid_session($session,1000,'fingerprint'),'Exact expiry');
        check(!valid_session($session,999,'new fingerprint'),'Credential rotation');
        check(!valid_session([],999,'fingerprint'),'Empty session');
    });
    test('actual SQLite write failure rolls back consent and operation receipt together', function () {
        $person=person(); $id=message($person); queue($id);
        run("CREATE TRIGGER synthetic_failure BEFORE INSERT ON test_audit BEGIN SELECT RAISE(ABORT, 'synthetic disk failure'); END");
        try { permission($person,'global'); throw new RuntimeException('Expected database failure'); }
        catch(PDOException $error) { check(str_contains($error->getMessage(),'synthetic disk failure'),'Unexpected failure'); }
        run('DROP TRIGGER synthetic_failure');
        check(permission_allows(row($id)) && row($id)['state']==='queued','Partial consent write');
        check((int)run('SELECT version FROM test_people WHERE id=?',[$person])->fetchColumn()===1,'Version not rolled back');
    });
    echo "PASS: $passed durable-outbox test groups.\n";
} finally {
    foreach (glob($directory.'/*') as $file) unlink($file);
    rmdir($directory);
}
