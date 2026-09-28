<?php
/**
 * Outreach. The no-call plan for finding Soft Appeals clients, built
 * 2026-09-27: prospects, the sequence, the video, the scripts, the evidence,
 * the rehearsals and the prospect pipeline, in one place behind the login.
 *
 * Read-only. Nothing here writes to the database. The prospect list is a
 * JSON file beside this template (templates/ is deny-all on the server), and
 * the video stage is served by sa-desk.php as its own page plus a script,
 * because the Desk's CSP allows no inline script.
 */

use SoftAppeals\Views\Desk;

$e = static fn (?string $value): string => Desk::e($value);

$tabs = [
    'next'      => 'Next moves',
    'prospects' => 'Prospects',
    'sequence'  => 'The sequence',
    'video'     => 'Video',
    'scripts'   => 'Scripts',
    'research'  => 'Evidence',
    'tests'     => 'Rehearsals',
    'pipeline'  => 'Finding more',
];
$tab = (string) ($_GET['tab'] ?? 'next');
if (!array_key_exists($tab, $tabs)) {
    $tab = 'next';
}

$prospects = [];
$raw = @file_get_contents(__DIR__ . '/outreach/prospects.json');
if (is_string($raw)) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $prospects = $decoded;
    }
}
$countBh = count(array_filter($prospects, static fn ($p) => ($p['type'] ?? '') === 'bh'));
$stageUrl = '/sa-desk.php?view=outreach&tab=stage';
$href = static fn (string $t): string => '/sa-desk.php?view=outreach&tab=' . $t;
?>

<section aria-labelledby="desk-outreach">
  <p class="sa-label" id="desk-outreach">Outreach &middot; no phone calls &middot; updated 27 September 2026</p>
  <nav class="sa-seg" aria-label="Outreach sections" style="display:flex;flex-wrap:wrap;gap:6px;margin:0 0 16px">
    <?php foreach ($tabs as $key => $label): ?>
      <a class="sa-btn is-sm<?= $tab === $key ? ' is-action' : '' ?>" href="<?= $e($href($key)) ?>"
         <?= $tab === $key ? 'aria-current="page"' : '' ?>><?= $e($label) ?></a>
    <?php endforeach; ?>
    <a class="sa-btn is-sm" href="<?= $e($stageUrl) ?>">Open the video stage &#8594;</a>
  </nav>

<?php if ($tab === 'next'): ?>

  <div class="sa-metrics">
    <div class="sa-metric is-lead"><p class="sa-metric-k">Most urgent</p><p class="sa-metric-v">Record video one</p><p class="sa-metric-c">Anchored Hope Therapy, Annapolis. About ten minutes.</p></div>
    <div class="sa-metric"><p class="sa-metric-k">Prospects</p><p class="sa-metric-v"><?= count($prospects) ?></p><p class="sa-metric-c"><?= $countBh ?> behavioral health, <?= count($prospects) - $countBh ?> therapy and rehab.</p></div>
    <div class="sa-metric"><p class="sa-metric-k">Free step</p><p class="sa-metric-v">Five columns</p><p class="sa-metric-c">Live on the site. No patient information.</p></div>
    <div class="sa-metric"><p class="sa-metric-k">Phone calls</p><p class="sa-metric-v">None</p><p class="sa-metric-c">Your word, 27 September.</p></div>
  </div>

  <p class="sa-label" style="margin-top:22px">Where each piece stands</p>
  <div class="sa-panel"><div class="sa-tablewrap"><table class="sa-table">
    <thead><tr><th>Piece</th><th>Status</th><th>Next move</th></tr></thead>
    <tbody>
      <tr><td>Five-column sheet</td><td><span class="sa-pill is-ok">Live</span></td><td>Nothing. It is at frimpomaasync.com/soft-appeals-sheet, Excel and CSV.</td></tr>
      <tr><td>Video template</td><td><span class="sa-pill is-ok">Built</span></td><td>Record the first one. <a href="<?= $e($href('video')) ?>">Video tab</a>.</td></tr>
      <tr><td>Email 2, the video email</td><td><span class="sa-pill is-ok">Written</span></td><td>The stage writes it per practice. Fill in the video link and your mailing address.</td></tr>
      <tr><td>Emails 1, 3, 4 and 5</td><td><span class="sa-pill is-wait">Not written</span></td><td>The shape is on <a href="<?= $e($href('sequence')) ?>">The sequence</a>. Say "write the emails".</td></tr>
      <tr><td>Sample teardown page</td><td><span class="sa-pill is-wait">Not built</span></td><td>The link in email 3, the back of the letter and the LinkedIn message. Build third.</td></tr>
      <tr><td>The letter</td><td><span class="sa-pill is-wait">Not built</span></td><td>Needs the teardown page first.</td></tr>
      <tr><td>Your mailing address</td><td><span class="sa-pill is-action">Needed</span></td><td>Every sales email carries one by law. A PO box is fine.</td></tr>
      <tr><td>Prospect lists</td><td><span class="sa-pill is-ok">109 practices</span></td><td>Pick the first ten. <a href="<?= $e($href('prospects')) ?>">Prospects</a>.</td></tr>
    </tbody>
  </table></div></div>

  <p class="sa-label" style="margin-top:22px">What changed today</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
    <ul style="margin:0;padding-left:18px;line-height:1.6">
      <li><b>The free review runs on five columns.</b> Payer, procedure code, denial reason code, billed amount, age of denial. No names, no dates, so nothing needs signing before it. The agreement and secure intake now belong to recovery work only. Changed on the main page, the intake page, the thanks page, the confirmation email, three FAQ answers and llms.txt. Commit 90d3b6d.</li>
      <li><b>No phone calls.</b> Outreach is email, video, letter and LinkedIn. The call lists stay as contact lists.</li>
      <li><b>Two lines added to your scripts:</b> the front desk line, and the answer to "our billing runs fine, why change it".</li>
      <li><b>109 prospects</b> across three lists, every phone checked against the practice's own site where the list says so.</li>
      <li><b>A video template</b> that fills in each practice's name, insurance companies and the right Maryland numbers.</li>
    </ul>
  </div></div>

  <p class="sa-label" style="margin-top:22px">Decisions waiting on you</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
    <ol style="margin:0;padding-left:18px;line-height:1.7">
      <li>Record the first video.</li>
      <li>Your mailing address for the email footer.</li>
      <li>The first ten practices for the sequence.</li>
      <li>Whether to write emails 1, 3, 4 and 5 now.</li>
    </ol>
  </div></div>

<?php elseif ($tab === 'prospects'): ?>
<?php
    $type = (string) ($_GET['type'] ?? '');
    $list = (string) ($_GET['list'] ?? '');
    $q = trim((string) ($_GET['q'] ?? ''));
    $lists = array_values(array_unique(array_map(static fn ($p) => (string) ($p['list'] ?? ''), $prospects)));
    $shown = array_values(array_filter($prospects, static function ($p) use ($type, $list, $q): bool {
        if ($type !== '' && ($p['type'] ?? '') !== $type) {
            return false;
        }
        if ($list !== '' && ($p['list'] ?? '') !== $list) {
            return false;
        }
        if ($q !== '') {
            $hay = strtolower(($p['name'] ?? '') . ' ' . ($p['meta'] ?? '') . ' ' . implode(' ', $p['payers'] ?? []));
            if (strpos($hay, strtolower($q)) === false) {
                return false;
            }
        }
        return true;
    }));
?>
  <form method="get" action="/sa-desk.php" class="sa-filters" style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;margin:0 0 12px">
    <input type="hidden" name="view" value="outreach">
    <input type="hidden" name="tab" value="prospects">
    <label class="sa-field">Search
      <input class="sa-input" type="search" name="q" value="<?= $e($q) ?>" placeholder="name, town or payer">
    </label>
    <label class="sa-field">Type
      <select class="sa-select" name="type">
        <option value="">All</option>
        <option value="bh" <?= $type === 'bh' ? 'selected' : '' ?>>Behavioral health</option>
        <option value="rehab" <?= $type === 'rehab' ? 'selected' : '' ?>>Therapy and rehab</option>
      </select>
    </label>
    <label class="sa-field">List
      <select class="sa-select" name="list">
        <option value="">All three</option>
        <?php foreach ($lists as $l): ?>
          <option value="<?= $e($l) ?>" <?= $list === $l ? 'selected' : '' ?>><?= $e($l) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button type="submit" class="sa-btn is-sm is-action">Show</button>
    <a class="sa-btn is-sm" href="<?= $e($href('prospects')) ?>">Clear</a>
  </form>
  <p class="sa-desk-note"><?= count($shown) ?> of <?= count($prospects) ?> practices. List 2 was checked by hand today. List 3 came from the pipeline, and a flag means check before you send. Phones are kept for reference only. No calls.</p>

  <div class="sa-panel"><div class="sa-tablewrap"><table class="sa-table">
    <thead><tr><th>Practice</th><th>Where and what</th><th>Why them</th><th>Contact</th><th></th></tr></thead>
    <tbody>
    <?php if ($shown === []): ?>
      <tr><td colspan="5">Nothing matches. Clear the filters.</td></tr>
    <?php endif; ?>
    <?php foreach ($shown as $p): ?>
      <tr>
        <td><b><?= $e((string) $p['name']) ?></b><br>
          <span class="sa-pill"><?= ($p['type'] ?? '') === 'bh' ? 'Behavioral health' : 'Therapy and rehab' ?></span>
          <span class="sa-desk-quiet" style="font-size:12px"><?= $e((string) ($p['list'] ?? '')) ?></span></td>
        <td style="max-width:260px"><?= $e((string) ($p['meta'] ?? '')) ?></td>
        <td style="max-width:320px"><?= $e((string) ($p['hook'] ?? '')) ?>
          <?php foreach (($p['flags'] ?? []) as $flag): ?><br><span class="sa-pill is-wait"><?= $e((string) $flag) ?></span><?php endforeach; ?></td>
        <td class="sa-desk-mono" style="white-space:nowrap">
          <?php if (($p['site'] ?? '') !== ''): ?><a href="<?= $e((string) $p['site']) ?>" target="_blank" rel="noopener noreferrer">Website</a><br><?php endif; ?>
          <?= $e((string) ($p['phone'] ?? '')) ?></td>
        <td><a class="sa-btn is-sm" href="<?= $e($stageUrl . '#p=' . rawurlencode((string) $p['name'])) ?>">Record video</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div></div>

<?php elseif ($tab === 'sequence'): ?>

  <p class="sa-desk-note">Fourteen days, one practice. Ten practices a day, Tuesday to Thursday. Never more than 50 emails a day from your mailbox. Two contacts per practice: the office manager by email, the owner on LinkedIn.</p>
  <div class="sa-panel"><div class="sa-tablewrap"><table class="sa-table">
    <thead><tr><th>Day</th><th>What</th><th>What it carries</th><th>Status</th></tr></thead>
    <tbody>
      <tr><td>0</td><td>Twenty minutes of research</td><td>Their payer page, specialty, owner name, office manager name, the Maryland number for their category.</td><td><span class="sa-pill is-ok">Ready</span></td></tr>
      <tr><td>1, Tue morning</td><td>Email 1, plain text</td><td>Under 80 words, no link, no attachment. Subject names their specialty and one Maryland number. The five-column offer. Footer: mailing address and "reply no and I stop".</td><td><span class="sa-pill is-wait">To write</span></td></tr>
      <tr><td>3, Thu morning</td><td>Email 2, same thread</td><td>One link, the 75-second video. A picture of their own site above it.</td><td><span class="sa-pill is-ok">Written</span></td></tr>
      <tr><td>4</td><td>LinkedIn request to the owner</td><td>No note, or one line naming the practice.</td><td><span class="sa-pill is-ok">Ready</span></td></tr>
      <tr><td>5</td><td>Post the letter</td><td>One page, hand addressed to the manager by name, real stamp. The back is the sample teardown.</td><td><span class="sa-pill is-wait">To build</span></td></tr>
      <tr><td>8, Tue</td><td>Email 3</td><td>"This is what the one-page report looks like." One link to the sample teardown page.</td><td><span class="sa-pill is-wait">To write</span></td></tr>
      <tr><td>10, Thu</td><td>LinkedIn message if connected</td><td>Two lines: the appeal rate for their category, and the offer.</td><td><span class="sa-pill is-ok">Ready</span></td></tr>
      <tr><td>12, Mon</td><td>Email 4</td><td>The five-column sheet link. Sixty words.</td><td><span class="sa-pill is-wait">To write</span></td></tr>
      <tr><td>14, Wed</td><td>Email 5, the last one</td><td>"I'll stop here. If denials come up next quarter, this thread is where to find me."</td><td><span class="sa-pill is-wait">To write</span></td></tr>
      <tr><td>Every 90 days</td><td>One line</td><td>One Maryland or payer fact. No ask.</td><td></td></tr>
    </tbody>
  </table></div></div>

  <p class="sa-label" style="margin-top:22px">Rules on every email</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
    <ul style="margin:0;padding-left:18px;line-height:1.7">
      <li>Your mailing address in the footer, an honest subject, and a way to say no that you honor within ten business days. The law has no exemption for business email.</li>
      <li>No patient data in any email, ever. The five-column sheet is the reason.</li>
      <li>SPF and DKIM on for the sending domain, DMARC at p=none to start. Twenty a day stays under every limit.</li>
      <li>Never fax cold. It is illegal, $500 a fax.</li>
      <li>Referral fees to billing companies: flat or tied to your fee, never to Medicare or Medicaid volume. A Maryland health lawyer reads it before the first payout.</li>
    </ul>
  </div></div>

<?php elseif ($tab === 'video'): ?>

  <div class="sa-desk-card">
    <div class="sa-desk-card-t"><b>The video stage</b> Pick a practice and the stage fills in their name, insurance companies and the right Maryland numbers. The script box shows each line. It also writes email 2.</div>
    <div class="sa-desk-card-a"><a class="sa-btn is-action is-sm" href="<?= $e($stageUrl) ?>">Open the stage &#8594;</a></div>
  </div>

  <p class="sa-label" style="margin-top:22px">Direction</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
    <ul style="margin:0;padding-left:18px;line-height:1.7">
      <li><b>Their own website in the first ten seconds.</b> In the rehearsal, managers kept watching only when they saw their own site or their own numbers first.</li>
      <li><b>A screen recording with your face in the corner</b> beat a talking head alone.</li>
      <li><b>Under 75 seconds.</b> Videos under a minute keep about two in three people watching to the end.</li>
      <li><b>A link, never an attachment.</b> Paste a picture of the thumbnail above the link.</li>
      <li><b>One take is fine.</b> Warm and plain beats polished.</li>
    </ul>
  </div></div>

  <p class="sa-label" style="margin-top:22px">The script, about 120 words</p>
  <div class="sa-panel"><div class="sa-tablewrap"><table class="sa-table">
    <thead><tr><th>Scene</th><th>On screen</th><th>You say</th></tr></thead>
    <tbody>
      <tr><td>1, 10 seconds</td><td>Their website, scrolled to the insurance page</td><td>Hi, I'm Nana Frimpongmaa. This is your website. I was on your insurance page. [first three payers]. That's a lot of rules for one billing desk.</td></tr>
      <tr><td>2, 25 seconds, therapy</td><td>Maryland's numbers for therapy</td><td>This is Maryland's own report from last year. Therapy denials went up to 4,565. That's almost 26% more in one year. And only 2 in 100 got appealed. When one does get appealed, the carrier reverses about half.</td></tr>
      <tr><td>2, 25 seconds, behavioral health</td><td>Maryland's numbers for mental health</td><td>This is Maryland's own report from last year. Mental health denials went from 652 to 1,627. That's up 149% in one year. Only about 1 in 8 got appealed. When one does get appealed, the carrier reverses about half.</td></tr>
      <tr><td>3, 20 seconds</td><td>The five columns, then the one-page result</td><td>So here's what I do. You fill five columns for your last twenty denials. Payer, code, reason, amount, how old. No patient names. I send back one page. What's still winnable, and what dies first.</td></tr>
      <tr><td>4, 10 seconds</td><td>The ask and the sheet link</td><td>It's free, and the page is yours either way. The sheet is linked under this video. Just reply with it.</td></tr>
    </tbody>
  </table></div></div>

  <p class="sa-label" style="margin-top:22px">Recording one, about ten minutes</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
    <ol style="margin:0;padding-left:18px;line-height:1.7">
      <li>Open the stage and pick the practice. Add the contact's first name if you have it.</li>
      <li>Press "Open their website in a new tab" and scroll to their insurance page.</li>
      <li>Start your screen recorder on that tab with your camera on. Read scene 1.</li>
      <li>Switch to the stage, press Record view, and read scenes 2 to 4. The right arrow moves scenes. Esc brings the buttons back.</li>
      <li>Stop after the last word.</li>
      <li>Go to the Thumbnail scene and take a screenshot of it.</li>
      <li>Copy email 2 from the stage. Paste the video link and your mailing address where it says.</li>
    </ol>
  </div></div>

  <p class="sa-label" style="margin-top:22px">The numbers and where they come from</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
    <p style="margin:0 0 8px">Maryland Insurance Administration, Health Care Appeals and Grievances Law report, 2024 data, published December 2025.</p>
    <ul style="margin:0;padding-left:18px;line-height:1.7">
      <li>PT, OT and speech denials: 3,630 in 2023, 4,565 in 2024, up 25.8%. The category includes inpatient rehab.</li>
      <li>Mental health denials: 652 in 2023, 1,627 in 2024, up 149.5%.</li>
      <li>Share ever appealed: therapy 2.3%, the lowest of any category. Mental health 11.9%.</li>
      <li>Carriers reversed 49.9% of appealed denials in 2024. That rate covers all categories. Never say it is one specialty's rate.</li>
      <li>All carriers together: 111,426 denials in 2024, up 2.1%.</li>
    </ul>
  </div></div>

<?php elseif ($tab === 'scripts'): ?>

  <p class="sa-label">Email 2, the video email</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
    <p class="sa-desk-email" style="white-space:pre-wrap;margin:0">Hi [first name],

I made you a 75-second video. It starts on your own insurance page.

[video link]

The short version: Maryland therapy offices appealed 2 in 100 denials last year. About half of the appealed ones got reversed.

If you want your own page, the five-column sheet is here: frimpomaasync.com/soft-appeals-sheet. No patient names on it.

Nana Frimpongmaa
frimpomaasync.com/soft-appeals
[your mailing address]
Reply "no" and I'll stop.</p>
    <p class="sa-desk-note" style="margin:10px 0 0">Behavioral health version: "Maryland mental health denials went from 652 to 1,627 last year, and about 1 in 8 got appealed. About half of the appealed ones got reversed." The stage switches it for you.</p>
  </div></div>

  <p class="sa-label" style="margin-top:22px">The offer, in one line</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
    <p style="margin:0">"Fill five columns for twenty of them, no patient names, and I will show you which ones are still winnable and which ones die first, on one page, free. The report is yours either way. If you want me to go and get the money after that, I only get paid out of what actually comes back to you."</p>
  </div></div>

  <p class="sa-label" style="margin-top:22px">When they write back with a doubt</p>
  <div class="sa-panel"><div class="sa-tablewrap"><table class="sa-table">
    <thead><tr><th>They say</th><th>You say</th></tr></thead>
    <tbody>
      <tr><td>"We already have a billing company."</td><td>"Good, keep them. They work live claims. I work the ones already written off, which is the pile nobody is paid to chase."</td></tr>
      <tr><td>"Our billing runs fine. Why change it?"</td><td>"Don't change it. Keep everything you're doing. I only take the claims that already got written off, the ones nobody is paid to chase. If that pile is empty, you don't need me, and I'll tell you so."</td></tr>
      <tr><td>"How much do you charge?"</td><td>"A share of what actually comes back, and nothing if nothing does. Recovered money lands in your account first, from your payer, and then I invoice."</td></tr>
      <tr><td>"How do I know you are careful with patient data?"</td><td>"The free review never touches it. Five columns, no names, no dates. If you continue, we sign a privacy agreement before anything moves."</td></tr>
      <tr><td>"How much will you recover?"</td><td>"I will not guess, and anyone who promises you a number is guessing. What I will do is show you the pile, sorted, with the deadlines. Then you decide."</td></tr>
      <tr><td>"Send me some information."</td><td>"It's one page, and it's this: frimpomaasync.com/soft-appeals-sheet. Can I ask first, roughly how much do you think is sitting in denied claims right now?"</td></tr>
      <tr><td>The biller sounds defensive</td><td>"I only touch claims your team already wrote off. Everything you are actively working stays yours. I am extra hands on the dead pile, not a replacement."</td></tr>
    </tbody>
  </table></div></div>

  <p class="sa-label" style="margin-top:22px">The three answers that mean stop</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
    <ul style="margin:0;padding-left:18px;line-height:1.7">
      <li>"We do not bill insurance." Thank them and go.</li>
      <li>"Our billing company handles all of that." Ask for the company's name. Small billing companies are a channel.</li>
      <li>"Can you guarantee how much you will recover?" and they will not let it go. Nobody can. Walk.</li>
    </ul>
  </div></div>

<?php elseif ($tab === 'research'): ?>

  <p class="sa-desk-note">From a sourced research pass on 27 September. Every cold email benchmark is vendor data and none isolates healthcare office managers, so treat the numbers as planning figures.</p>
  <div class="sa-panel"><div class="sa-tablewrap"><table class="sa-table">
    <thead><tr><th>Rank</th><th>Method</th><th>What the evidence says</th><th>Cost</th></tr></thead>
    <tbody>
      <tr><td>1</td><td>Plain-text email, five touches over 14 days</td><td>3 to 6% reply on small hand-picked lists. Follow-ups bring about four in ten of all replies.</td><td>$0</td></tr>
      <tr><td>2</td><td>75-second video in email 2</td><td>The only method with any published lift. Under a minute keeps about two in three watching.</td><td>$0</td></tr>
      <tr><td>3</td><td>One-page sample teardown, linked once</td><td>Attachments hurt delivery from unknown senders. Link it, never attach it.</td><td>Your time</td></tr>
      <tr><td>4</td><td>Printed letter, hand addressed</td><td>About 11% on small samples, not proven for clinics.</td><td>$1.50 to $3</td></tr>
      <tr><td>5</td><td>LinkedIn to the owner</td><td>4 to 5 replies per 100 requests.</td><td>$0</td></tr>
      <tr><td>6</td><td>Through their billing company</td><td>Industry norm is 5 to 10% referral. No reply data.</td><td>Part of your fee</td></tr>
      <tr><td>7</td><td>Free tools on the site only</td><td>Needs traffic you do not have yet.</td><td>Your time</td></tr>
      <tr><td>Never</td><td>Fax</td><td>Illegal cold. $500 a fax, $1,500 if willful.</td><td></td></tr>
    </tbody>
  </table></div></div>

<?php elseif ($tab === 'tests'): ?>

  <p class="sa-desk-note">Simulations run on 27 September with a swarm engine: pretend office managers, front desks, owners and billers reacting to your real words. The numbers they give are invented. The objections in their words are the useful part.</p>

  <p class="sa-label" style="margin-top:14px">No-call outreach test, 53 practices, seven methods</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
    <ul style="margin:0;padding-left:18px;line-height:1.7">
      <li><b>The video won.</b> Managers kept watching when the first seconds showed their own site.</li>
      <li><b>The sample teardown came second.</b> Managers wanted to see a real denial case and what happens to it.</li>
      <li><b>The letter reached the owner</b> when it looked personal and named a fix.</li>
      <li><b>Billers were the weakest route.</b> They felt threatened until told the live work stays theirs.</li>
      <li><b>The free tools alone brought nothing.</b></li>
      <li>Subject that opened: "About your denied claims". Subject that got deleted: "Please review our services".</li>
    </ul>
  </div></div>

  <p class="sa-label" style="margin-top:22px">The call rehearsal, 26 practices, before calls were dropped</p>
  <div class="sa-panel"><div class="sa-panel-b" style="padding:14px 18px">
    <ul style="margin:0;padding-left:18px;line-height:1.7">
      <li><b>The front desk was the wall.</b> Six of 26 never reached a manager.</li>
      <li><b>"We need more information" was the main stall,</b> from managers, billers and voicemail alike.</li>
      <li><b>New objection:</b> "Our billing runs fine, why change it?" The answer is now on Scripts.</li>
      <li>One trap: the engine had you cite success cases. You have none and the site says so. Never do it.</li>
    </ul>
  </div></div>

<?php elseif ($tab === 'pipeline'): ?>

  <p class="sa-desk-note">Three tools on your Mac, approved 27 September. Your rule: list repos and explain them, you approve before anything is installed.</p>
  <div class="sa-panel"><div class="sa-tablewrap"><table class="sa-table">
    <thead><tr><th>Tool</th><th>What it does</th><th>Where on the Mac</th></tr></thead>
    <tbody>
      <tr><td>NPI registry puller</td><td>The federal list of every practice that bills insurance, with the owner's name. Free, no install.</td><td class="sa-desk-mono">denial-recovery/06-outreach/npi/</td></tr>
      <tr><td>Google Maps scraper</td><td>Every listing for a search, with website and review count.</td><td class="sa-desk-mono">~/prospecting-tools/gmaps</td></tr>
      <tr><td>Website reader</td><td>Reads each practice's team and insurance pages.</td><td class="sa-desk-mono">~/prospecting-tools/enrich.py</td></tr>
    </tbody>
  </table></div></div>

  <p class="sa-label" style="margin-top:22px">What one run looks like</p>
  <div class="sa-panel"><div class="sa-tablewrap"><table class="sa-table">
    <thead><tr><th>Stage</th><th>Howard, Baltimore County, Montgomery</th></tr></thead>
    <tbody>
      <tr><td>Maps listings, 28 towns</td><td>1,306</td></tr>
      <tr><td>Past the chain filter</td><td>808</td></tr>
      <tr><td>Websites read</td><td>350</td></tr>
      <tr><td>Payers and clinicians on the site</td><td>99</td></tr>
      <tr><td>After removing hospitals, nursing homes, networks and solo practices</td><td>55</td></tr>
      <tr><td>Phone matched on the practice's own site</td><td>47</td></tr>
    </tbody>
  </table></div></div>
  <p class="sa-desk-note">One county takes about 45 minutes with the Mac working alone. Not yet covered: Prince George's, Frederick in depth, Carroll, Harford in depth, the Eastern Shore. Say "run" and a county name.</p>

<?php endif; ?>
</section>
