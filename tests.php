<?php
declare(strict_types=1);
$path=tempnam(sys_get_temp_dir(),'postroom-test-'); putenv('DATABASE='.$path);
require __DIR__.'/src/support.php'; require __DIR__.'/src/gateway.php';
function check(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
function rejects(callable $fn): void { try {$fn();} catch (InvalidArgumentException) {return;} throw new RuntimeException('Expected validation failure'); }
try {
    initialize_gateway();
    $contact=['name'=>'Ada Example','channel'=>'Email','destination'=>'ada@example.com','consent'=>'yes','segment'=>'Community'];
    add_contact($contact); rejects(fn()=>add_contact($contact));
    add_contact([...$contact,'destination'=>'no-consent@example.com','consent'=>'no']);
    add_contact([...$contact,'channel'=>'SMS','destination'=>'+12025550123']);
    add_contact([...$contact,'destination'=>'team@example.com','segment'=>'Team']);
    rejects(fn()=>add_contact([...$contact,'destination'=>'bad']));
    rejects(fn()=>add_contact([...$contact,'channel'=>'SMS','destination'=>'123']));
    $draft=['title'=>'Welcome','channel'=>'Email','segment'=>'Community','body'=>'Thanks for opting in.'];
    $id=create_campaign($draft); check(count(recipients(run('SELECT * FROM campaigns WHERE id=?',[$id])->fetch()))===1,'Consent and audience filtering');
    check(simulate($id)===1,'Simulation'); check(simulate($id)===0,'Idempotency');
    check(run('SELECT status FROM outbox')->fetchColumn()==='simulated','Not a real delivery');
    $id=create_campaign($draft); run('UPDATE contacts SET consent=0 WHERE destination=?',['ada@example.com']);
    rejects(fn()=>simulate($id)); check(run('SELECT status FROM campaigns WHERE id=?',[$id])->fetchColumn()==='draft','Rollback on no audience');
    rejects(fn()=>create_campaign([...$draft,'channel'=>'SMS','body'=>str_repeat('a',481)]));
    rejects(fn()=>simulate(999)); check((int)run('SELECT COUNT(*) FROM outbox')->fetchColumn()===1,'No accidental deliveries');
    echo "PASS: contacts, validation, consent, segmentation, draft persistence, simulation, retry idempotency and rollback.\n";
} finally { foreach ([$path, $path.'-wal', $path.'-shm'] as $file) if (is_file($file)) unlink($file); }
