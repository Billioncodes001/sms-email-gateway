<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/support.php';
request_boundary();
if (PHP_SAPI === 'cli-server') {
    $asset = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($asset === '/style.css' || (is_string($asset) && preg_match('~^/fonts/[a-z]+\.woff2$~', $asset) && is_file(__DIR__ . $asset))) return false;
}
require dirname(__DIR__).'/src/gateway.php';
require dirname(__DIR__).'/src/outbox.php';
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/callbacks/local-test') {
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"error":"POST required"}'; exit; }
    if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') { http_response_code(415); echo '{"error":"JSON required"}'; exit; }
    if (strlen((string)getenv('APP_PASSWORD')) < 16 || strlen((string)getenv('LOCAL_CALLBACK_SECRET')) < 32) { http_response_code(503); echo '{"error":"Local workspace is not configured"}'; exit; }
    try {
        test_provider(); initialize_test_outbox();
        $raw = file_get_contents('php://input', false, null, 0, 2049);
        $result = receive_test_callback($raw, $_SERVER['HTTP_X_LOCAL_TIMESTAMP'] ?? '', $_SERVER['HTTP_X_LOCAL_SIGNATURE'] ?? '');
        echo json_encode(['result' => $result, 'simulation_only' => true], JSON_THROW_ON_ERROR);
    } catch (InvalidArgumentException $error) { http_response_code(400); echo json_encode(['error' => $error->getMessage()]); }
    catch (Throwable $error) { error_log($error->getMessage()); http_response_code(500); echo '{"error":"Local callback failed; retry the same event"}'; }
    exit;
}
if ($path !== '/') fail(404, 'Page not found.');
boot('Postroom', 'A thoughtful message. A willing audience.');
initialize_gateway(); initialize_test_outbox();
$tab = is_string($_GET['tab'] ?? null) && in_array($_GET['tab'], ['contacts','compose','outbox','legacy'], true) ? $_GET['tab'] : 'contacts';
$error = null; $submitted = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = input_text($_POST, 'action');
        if ($action === 'person') { create_test_person($_POST); notice('Synthetic recipient saved. Consent is independent for each channel.'); }
        elseif ($action === 'permission') { change_permission($_POST); notice('Permission recorded. Suppression also blocks pending tests and retries.'); }
        elseif ($action === 'draft') { $id = create_test_message($_POST); go('/?tab=compose&preview='.$id); }
        elseif ($action === 'queue' || $action === 'retry') {
            queue_test_message($_POST, $action === 'retry'); notice('Test queued durably. No delivery has occurred.'); go('/?tab=outbox');
        } elseif ($action === 'dispatch' || $action === 'complete') {
            dispatch_test_request($_POST, $action === 'complete');
            notice('Local provider processed this test. No SMS or email was sent.'); go('/?tab=outbox&message='.positive_id($_POST, 'id'));
        } else { throw new InvalidArgumentException('Unknown action.'); }
        go('/?tab='.$tab);
    } catch (InvalidArgumentException $failure) { http_response_code(400); $error = $failure->getMessage(); $submitted = $_POST; }
}
function form_start(string $action, array $hidden = []): void {
    echo '<form method="post">' . csrf_field() . operation_field() . '<input type="hidden" name="action" value="' . e($action) . '">';
    foreach ($hidden as $key => $value) echo '<input type="hidden" name="' . e($key) . '" value="' . e($value) . '">';
}
function state_label(string $state): string {
    return ['draft'=>'Draft', 'queued'=>'Queued test', 'submitted'=>'Awaiting local callback', 'simulated'=>'Simulated success / not delivered', 'failed'=>'Simulated failure', 'suppressed'=>'Suppressed / no further tests'][$state] ?? $state;
}
$people = run('SELECT * FROM test_people ORDER BY id DESC')->fetchAll();
$permissions = run('SELECT * FROM test_permissions ORDER BY person_id,channel')->fetchAll();
$channels = [];
foreach ($permissions as $permission) $channels[$permission['person_id']][] = $permission;
$opted = run('SELECT COUNT(*) FROM test_permissions c JOIN test_people p ON p.id=c.person_id WHERE c.opted_in=1 AND c.suppressed=0 AND p.suppressed=0')->fetchColumn();
$tests = run('SELECT COUNT(*) FROM test_messages WHERE state<>?', ['draft'])->fetchColumn();
page_start('Postroom','Private test desk / No live delivery');
?>
<section class="hero"><p class="eyebrow">A BETTER WAY TO KEEP IN TOUCH</p><h1>Good messages.<br>Good company.</h1><p>A private test desk for consent-aware email and SMS. Record permission, review a message, and follow its local test journey.</p><div class="stats"><p><strong><?=count($people)?></strong>Test recipients</p><p><strong><?=e($opted)?></strong>Channel opt-ins</p><p><strong><?=e($tests)?></strong>Outbox tests</p></div></section>
<p class="notice"><strong>LOCAL TEST / simulation, not delivery.</strong> Synthetic recipients only. No network provider, messages, billing or external accounts. Real-provider adapters are disabled.</p>
<?php if ($error): ?><p class="notice error" role="alert"><?=e($error)?> Draft fields remain below where applicable; review before resubmitting.</p><?php endif ?>
<?php if (strlen((string)getenv('LOCAL_CALLBACK_SECRET')) < 32): ?><p class="notice">Dispatch is locked until the operator sets LOCAL_CALLBACK_SECRET (32+ characters) and restarts. Drafts and consent records remain available.</p><?php endif ?>
<nav class="tabs" aria-label="Workspace"><?php foreach (['contacts'=>'Audience','compose'=>'Compose','outbox'=>'Test outbox','legacy'=>'Legacy history'] as $key=>$label): ?><a <?=$tab===$key?'aria-current="page"':''?> class="<?=$tab===$key?'active':''?>" href="/?tab=<?=e($key)?>"><?=e($label)?></a><?php endforeach ?></nav>
<?php if ($tab === 'contacts'): ?>
<div class="workspace"><form class="panel" method="post"><?=csrf_field().operation_field()?><input type="hidden" name="action" value="person"><p class="eyebrow">01 / THE AUDIENCE</p><h2>Start a connection.</h2><?php field('name','Synthetic recipient name','text',draft_value($submitted,'name')); ?>
<fieldset><legend>Email permission</legend><?php field('email','Test email','email',draft_value($submitted,'email'),false); ?><p class="hint">Only example.com, example.net, example.org or example.test.</p><label class="check"><input type="checkbox" name="email_consent" value="yes" <?=($submitted['email_consent']??'')==='yes'?'checked':''?>>Explicit opt-in for Email</label><?php field('email_evidence','Email opt-in evidence','text',draft_value($submitted,'email_evidence'),false); ?></fieldset>
<fieldset><legend>SMS permission</legend><?php field('sms','Fictional test number','tel',draft_value($submitted,'sms'),false); ?><p class="hint">+12025550100 through +12025550199 only.</p><label class="check"><input type="checkbox" name="sms_consent" value="yes" <?=($submitted['sms_consent']??'')==='yes'?'checked':''?>>Explicit opt-in for SMS</label><?php field('sms_evidence','SMS opt-in evidence','text',draft_value($submitted,'sms_evidence'),false); ?></fieldset>
<button>Add test recipient &rarr;</button><p class="hint">One person may have both channels. Opt-in evidence is an operator declaration, not verified consent. Do not enter personal data.</p></form>
<section><h2>Your test address book.</h2><div class="list"><?php foreach ($people as $person): ?><article class="person-card" data-person="<?=e($person['id'])?>"><div class="record"><div><h3><?=e($person['name'])?></h3><p>Recipient #<?=e($person['id'])?> / revision <?=e($person['version'])?></p></div><span class="badge"><?=$person['suppressed']?'Globally suppressed':'Synthetic recipient'?></span></div>
<?php foreach ($channels[$person['id']] ?? [] as $permission): ?><section class="channel-card"><h4><?=e($permission['channel'])?> <span class="badge"><?=$person['suppressed']||$permission['suppressed']?'Suppressed':($permission['opted_in']?'Explicit opt-in':'Not subscribed')?></span></h4><p><?=e($permission['destination'])?></p>
<?php if (!$person['suppressed'] && !$permission['suppressed']): ?>
<details><summary><?=$permission['opted_in']?'Unsubscribe '.$permission['channel']:'Record '.$permission['channel'].' opt-in'?></summary>
<?php form_start('permission', ['id'=>$person['id'],'version'=>$person['version'],'channel'=>$permission['channel'],'mode'=>$permission['opted_in']?'unsubscribe':'optin']); ?>
<label>Evidence / reason<input name="evidence" required maxlength="500"></label>
<?php if (!$permission['opted_in']): ?><label class="check"><input type="checkbox" name="explicit" value="yes" required>Explicit opt-in for <?=e($permission['channel'])?></label><?php endif ?>
<button class="quiet"><?=$permission['opted_in']?'Confirm channel unsubscribe':'Save channel opt-in'?></button></form></details><?php endif ?></section><?php endforeach ?>
<?php if (!$person['suppressed']): ?><details><summary>Suppress all channels</summary><?php form_start('permission', ['id'=>$person['id'],'version'=>$person['version'],'mode'=>'global']); ?><label>Reason for global suppression<input name="evidence" required maxlength="500"></label><p class="hint">Permanently blocks both channels for this recipient in this pilot. Existing history is retained.</p><button class="quiet danger">Confirm global suppression</button></form></details><?php endif ?>
<details><summary>Consent history</summary><ol class="timeline"><?php foreach (run('SELECT * FROM test_audit WHERE person_id=? ORDER BY id DESC',[$person['id']])->fetchAll() as $audit): ?><li><strong><?=e($audit['action'])?> / <?=e($audit['channel'] ?? 'All channels')?></strong><p><?=e($audit['evidence'] ?: 'No opt-in recorded.')?></p><small><?=e($audit['created'])?> UTC</small></li><?php endforeach ?></ol></details></article><?php endforeach ?>
<?php if (!$people): ?><div class="empty"><h3>Permission comes first.</h3><p>Add a synthetic recipient. Neither channel is opted in by default.</p></div><?php endif ?></div></section></div>
<?php elseif ($tab === 'compose'): $preview = isset($_GET['preview']) && is_scalar($_GET['preview']) ? run('SELECT * FROM test_messages WHERE id=?',[(int)$_GET['preview']])->fetch() : false; ?>
<div class="workspace"><form class="panel" method="post"><?=csrf_field().operation_field()?><input type="hidden" name="action" value="draft"><p class="eyebrow">02 / THE MESSAGE</p><h2>Make it meaningful.</h2><?php field('title','Test title','text',draft_value($submitted,'title')); ?><label for="person_id">Test recipient</label><select id="person_id" name="person_id" required><option value="">Choose one recipient</option><?php foreach ($people as $person): ?><option value="<?=e($person['id'])?>" <?=((string)$person['id']===($submitted['person_id']??''))?'selected':''?>><?=e($person['name'])?><?=$person['suppressed']?' (suppressed)':''?></option><?php endforeach ?></select><?php choice('channel','Channel',['Email','SMS'],draft_value($submitted,'channel')); ?>
<label for="body">Your test message</label><textarea id="body" name="body" maxlength="4000" required placeholder="A little news worth sharing..."><?=e(draft_value($submitted,'body'))?></textarea><p class="hint">Email: 4,000 UTF-8 bytes. SMS: 480 bytes. No segment/cost estimator.</p>
<label for="scenario">Deterministic LOCAL TEST scenario</label><select id="scenario" name="scenario"><?php foreach (['success'=>'Simulated success','fail_once'=>'Fail first attempt, then succeed','always_fail'=>'Always fail (max. 5 attempts)','hold'=>'Await manual signed callback'] as $key=>$label): ?><option value="<?=e($key)?>" <?=($submitted['scenario']??'')===$key?'selected':''?>><?=e($label)?></option><?php endforeach ?></select><button>Review test draft &rarr;</button><p class="hint">A single recipient, a single channel. Saved drafts are immutable. Create another draft to change the content.</p></form>
<section><?php if ($preview): ?><div class="panel review"><p class="eyebrow">REVIEW BEFORE QUEUING</p><h2><?=e($preview['title'])?></h2><dl><dt>Destination</dt><dd><?=e($preview['destination'])?></dd><dt>Channel</dt><dd><?=e($preview['channel'])?></dd><dt>Consent now</dt><dd><?=permission_allows($preview)?'Eligible for local test':'Blocked: no consent or suppressed'?></dd><dt>Scenario</dt><dd><?=e($preview['scenario'])?></dd><dt>Status</dt><dd><?=e(state_label($preview['state']))?></dd></dl><p class="prose"><?=e($preview['body'])?></p><?php if ($preview['state']==='draft'): form_start('queue',['id'=>$preview['id'],'version'=>$preview['version']]); ?><button>Queue local test</button></form><?php endif ?><p class="hint">Queueing and dispatch each recheck permission. Nothing is sent. Suppression can still stop a queued test.</p></div><?php else: ?><div class="empty"><h3>A little care goes a long way.</h3><p>Review a message and its channel permission before queuing it.</p></div><?php endif ?>
<h2>Saved test drafts.</h2><div class="list"><?php foreach (run('SELECT * FROM test_messages ORDER BY id DESC')->fetchAll() as $message): ?><article class="record"><div><h3><?=e($message['title'])?></h3><p><?=e($message['channel'])?> / <?=e(state_label($message['state']))?></p></div><a class="button quiet" href="/?tab=compose&preview=<?=e($message['id'])?>">Review</a></article><?php endforeach ?></div></section></div>
<?php elseif ($tab === 'outbox'): $rows = run("SELECT m.*,p.name FROM test_messages m JOIN test_people p ON p.id=m.person_id WHERE m.state<>'draft' ORDER BY m.id DESC")->fetchAll(); ?>
<section class="outbox-heading"><p class="eyebrow">03 / THE LOCAL JOURNEY</p><h2>Dispatch, without the delivery.</h2><p class="hint">Each action processes one test. Reloads are read-only. A prepared attempt survives a restart; resume it without creating another. Every status below is a simulation.</p></section>
<div class="list outbox-list"><?php foreach ($rows as $message): $attempts=run('SELECT * FROM test_attempts WHERE message_id=? ORDER BY number DESC',[$message['id']])->fetchAll(); ?><article class="outbox-card" data-message="<?=e($message['id'])?>"><div class="record"><div><p class="eyebrow">TEST #<?=e($message['id'])?> / <?=e($message['channel'])?></p><h3><?=e($message['title'])?></h3><p><?=e($message['name'])?> / <?=e($message['destination'])?></p><p><?=e($message['updated'])?> UTC / <?=count($attempts)?> of 5 attempts / <?=e($message['scenario'])?></p></div><span class="badge state-<?=e($message['state'])?>"><?=e(state_label($message['state']))?></span></div>
<div class="actions"><?php if (in_array($message['state'],['queued','submitted'],true)): form_start('dispatch',['id'=>$message['id'],'version'=>$message['version']]); ?><button><?=$message['state']==='queued'?'Dispatch local test':'Resume same attempt'?></button></form><?php if ($message['state']==='submitted' && $message['scenario']==='hold'): form_start('complete',['id'=>$message['id'],'version'=>$message['version']]); ?><button class="quiet">Generate signed local callback</button></form><?php endif ?><?php elseif ($message['state']==='failed' && count($attempts)<5): form_start('retry',['id'=>$message['id'],'version'=>$message['version']]); ?><button class="quiet">Queue retry</button></form><?php endif ?><a class="button quiet" href="/?tab=compose&preview=<?=e($message['id'])?>">View message</a></div>
<details <?=isset($_GET['message']) && is_scalar($_GET['message']) && (string)$_GET['message']===(string)$message['id']?'open':''?>><summary>Attempt &amp; callback history (<?=count($attempts)?>)</summary><?php if (!$attempts): ?><p class="hint">No provider attempt. Suppressed tests never start another attempt.</p><?php endif ?><ol class="timeline"><?php foreach ($attempts as $attempt): ?><li><strong>Attempt <?=e($attempt['number'])?> / <?=e($attempt['state'])?></strong><p class="mono">Stable key: <?=e($attempt['id'])?></p><p><?=e($attempt['created'])?> UTC</p><ul><?php foreach (run('SELECT * FROM test_events WHERE attempt_id=? ORDER BY rowid',[$attempt['id']])->fetchAll() as $event): ?><li><?=e($event['state'])?> / <?=e($event['disposition'])?> / sequence <?=e($event['sequence'])?></li><?php endforeach ?></ul></li><?php endforeach ?></ol></details></article><?php endforeach ?>
<?php if (!$rows): ?><div class="empty"><h3>Nothing has left the desk.</h3><p>Queue a reviewed draft to begin its local test journey.</p></div><?php endif ?></div><br>
<?php else: ?>
<section><h2>Before the durable test desk.</h2><p class="notice">Read-only legacy records, preserved unchanged. Historical consent has not been imported or treated as current permission. These records cannot enter the new test outbox.</p>
<details><summary>Legacy contacts (<?=e(run('SELECT COUNT(*) FROM contacts')->fetchColumn())?>)</summary><?php foreach (run('SELECT * FROM contacts ORDER BY id')->fetchAll() as $row): ?><article class="record"><div><h3><?=e($row['name'])?></h3><p><?=e($row['destination'])?> / <?=e($row['channel'])?> / <?=e($row['segment'])?></p><p>Historical consent flag: <?=e($row['consent'])?></p></div></article><?php endforeach ?></details>
<details><summary>Legacy campaigns (<?=e(run('SELECT COUNT(*) FROM campaigns')->fetchColumn())?>)</summary><?php foreach (run('SELECT * FROM campaigns ORDER BY id')->fetchAll() as $row): ?><article class="record"><div><h3><?=e($row['title'])?></h3><p><?=e($row['channel'])?> / <?=e($row['segment'])?> / <?=e($row['status'])?></p><p class="prose"><?=e($row['body'])?></p></div></article><?php endforeach ?></details>
<details><summary>Legacy simulation history (<?=e(run('SELECT COUNT(*) FROM outbox')->fetchColumn())?>)</summary><?php foreach (run('SELECT * FROM outbox ORDER BY id')->fetchAll() as $row): ?><article class="record"><div><p><?=e($row['destination'])?> / <?=e($row['status'])?> / <?=e($row['created'])?> UTC</p><p>Legacy campaign #<?=e($row['campaign_id'])?> / not delivered</p></div></article><?php endforeach ?></details></section><br>
<?php endif; page_end(); ?>
