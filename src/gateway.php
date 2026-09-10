<?php
declare(strict_types=1);
function initialize_gateway(): void {
    database()->exec("CREATE TABLE IF NOT EXISTS contacts (
        id INTEGER PRIMARY KEY, name TEXT NOT NULL, channel TEXT NOT NULL,
        destination TEXT NOT NULL COLLATE NOCASE, consent INTEGER NOT NULL, segment TEXT NOT NULL,
        UNIQUE(channel,destination));
        CREATE TABLE IF NOT EXISTS campaigns (
        id INTEGER PRIMARY KEY, title TEXT NOT NULL, channel TEXT NOT NULL, segment TEXT NOT NULL,
        body TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'draft', created TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE IF NOT EXISTS outbox (
        id INTEGER PRIMARY KEY, campaign_id INTEGER NOT NULL REFERENCES campaigns(id), destination TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'simulated', created TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(campaign_id,destination))");
}
function add_contact(array $input): void {
    $name = input_text($input,'name'); $destination = input_text($input,'destination');
    $channel = input_text($input,'channel'); $segment = input_text($input,'segment');
    if (!$name || !$destination) throw new InvalidArgumentException('Enter a name and destination.');
    if (!in_array($channel,['Email','SMS'],true)) throw new InvalidArgumentException('Choose Email or SMS.');
    if ($channel === 'Email' && !filter_var($destination,FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid email address.');
    if ($channel === 'SMS' && !preg_match('/^\+[1-9][0-9]{7,14}$/',$destination)) throw new InvalidArgumentException('Use an international phone number, for example +12025550123.');
    if (!in_array($segment,['Community','Customers','Team'],true)) throw new InvalidArgumentException('Choose a valid segment.');
    $consent = ($input['consent'] ?? '') === 'yes' ? 1 : 0;
    try { run('INSERT INTO contacts(name,channel,destination,consent,segment) VALUES(?,?,?,?,?)',[$name,$channel,$destination,$consent,$segment]); }
    catch (PDOException $error) {
        if (str_contains($error->getMessage(),'UNIQUE')) throw new InvalidArgumentException('That destination is already in this channel.');
        throw $error;
    }
}
function create_campaign(array $input): int {
    $title = input_text($input,'title'); $body = input_text($input,'body',4000);
    $channel = input_text($input,'channel'); $segment = input_text($input,'segment');
    if (!$title || !$body) throw new InvalidArgumentException('Add a campaign title and message.');
    if (!in_array($channel,['Email','SMS'],true) || !in_array($segment,['Community','Customers','Team'],true)) throw new InvalidArgumentException('Choose a valid channel and audience.');
    if ($channel === 'SMS' && strlen($body) > 480) throw new InvalidArgumentException('SMS previews are limited to 480 UTF-8 bytes.');
    run('INSERT INTO campaigns(title,channel,segment,body) VALUES(?,?,?,?)',[$title,$channel,$segment,$body]);
    return (int)database()->lastInsertId();
}
function recipients(array $campaign): array {
    return run('SELECT * FROM contacts WHERE channel=? AND segment=? AND consent=1 ORDER BY name',[$campaign['channel'],$campaign['segment']])->fetchAll();
}
function simulate(int $id): int {
    $db = database(); $db->exec('BEGIN IMMEDIATE');
    try {
        $campaign = run('SELECT * FROM campaigns WHERE id=?',[$id])->fetch();
        if (!$campaign) throw new InvalidArgumentException('That draft does not exist.');
        if ($campaign['status'] !== 'draft') { $db->exec('COMMIT'); return 0; }
        $contacts = recipients($campaign);
        if (!$contacts) throw new InvalidArgumentException('There are no opted-in contacts in this audience.');
        foreach ($contacts as $contact) run('INSERT INTO outbox(campaign_id,destination) VALUES(?,?)',[$id,$contact['destination']]);
        run("UPDATE campaigns SET status='simulated' WHERE id=?",[$id]); $db->exec('COMMIT');
        return count($contacts);
    } catch (Throwable $error) { $db->exec('ROLLBACK'); throw $error; }
}
