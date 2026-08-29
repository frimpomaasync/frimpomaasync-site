<?php
declare(strict_types=1);

/**
 * Test fixtures. Fictional organizations only.
 *
 * CLI only, and it refuses to run when SA_APP_ENV is production. Seeding
 * invented practices into the live database would put fake rows in front of
 * real ones on the Desk, and the first time that happens during a real call is
 * the last time she trusts the screen.
 *
 *   php database/seeds/fixtures.php
 *   php database/seeds/fixtures.php --dsn=sqlite:/tmp/sa-test.sqlite
 *   php database/seeds/fixtures.php --admin-password=...
 *
 * Every organization below is invented. The names are ordinary enough to read
 * as real on screen, which is the point of a fixture, and none of them is a
 * Maryland practice that exists. The states and types match her actual fit
 * criteria so the Desk can be judged against the work it will really do.
 *
 * There is no patient, no claim number, no date of service and no dollar figure
 * anywhere in this file, because there is no column in the Phase 1 schema that
 * could hold one.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not here.');
}

require_once __DIR__ . '/../../src/SoftAppeals/Bootstrap.php';

use SoftAppeals\Auth\AuthService;
use SoftAppeals\Bootstrap;
use SoftAppeals\Database;
use SoftAppeals\Domain\Role;
use SoftAppeals\Repositories\MembershipRepository;
use SoftAppeals\Repositories\OrganizationRepository;
use SoftAppeals\Repositories\UserRepository;
use SoftAppeals\Support\Clock;
use SoftAppeals\Support\Uuid;

$flags = array_slice($_SERVER['argv'] ?? [], 1);
$option = static function (string $name) use ($flags): ?string {
    foreach ($flags as $flag) {
        if (str_starts_with($flag, '--' . $name . '=')) {
            return substr($flag, strlen($name) + 3);
        }
    }
    return null;
};

$app = Bootstrap::boot(null, false);

if ($app->config()->isProduction() && $option('force') === null) {
    fwrite(STDERR, "\n  Refusing to seed fixtures into production.\n"
        . "  Set SA_APP_ENV=staging, or pass --force=yes if you truly mean it.\n\n");
    exit(2);
}

$dsn = $option('dsn');
$db = $dsn !== null
    ? Database::connect($dsn, $option('user') ?? '', $option('password') ?? '')
    : Database::fromConfig($app->config());

$clock = new Clock($app->config()->string('SA_BUSINESS_TIMEZONE'));
$organizations = new OrganizationRepository($db, $clock);
$users = new UserRepository($db, $clock);
$memberships = new MembershipRepository($db, $clock);

/**
 * Fictional practices, matched to her real fit criteria from /soft-appeals:
 * behavioral health is best fit, outpatient PT and OT is strong fit, dental is
 * strong fit priced differently, and one out-of-state row so the Desk can be
 * seen handling the case her own copy says it will be straight about.
 */
$practices = [
    [
        'legal_name' => 'Tidewater Behavioral Health Associates LLC',
        'display'    => 'Tidewater Behavioral Health',
        'type'       => 'behavioral_health',
        'state'      => 'MD',
        'status'     => OrganizationRepository::STATUS_ACTIVE,
        'contacts'   => [
            ['Rosalind Achebe', 'practice administrator', 'rosalind.achebe@example.org'],
            ['Dev Ramanathan', 'billing lead', 'dev.ramanathan@example.org'],
        ],
    ],
    [
        'legal_name' => 'Patapsco Therapy Group, P.A.',
        'display'    => 'Patapsco Therapy Group',
        'type'       => 'behavioral_health',
        'state'      => 'MD',
        'status'     => OrganizationRepository::STATUS_ACTIVE,
        'contacts'   => [
            ['Ingrid Sollberger', 'owner', 'ingrid.sollberger@example.org'],
        ],
    ],
    [
        'legal_name' => 'Catoctin Physical and Occupational Therapy LLC',
        'display'    => 'Catoctin PT and OT',
        'type'       => 'pt_ot',
        'state'      => 'MD',
        'status'     => OrganizationRepository::STATUS_PROSPECT,
        'contacts'   => [
            ['Marcus Whitlow', 'clinic director', 'marcus.whitlow@example.org'],
        ],
    ],
    [
        'legal_name' => 'Chesapeake Family Dental Partners LLC',
        'display'    => 'Chesapeake Family Dental',
        'type'       => 'dental',
        'state'      => 'MD',
        'status'     => OrganizationRepository::STATUS_PROSPECT,
        'contacts'   => [
            ['Yewande Okonkwo', 'office manager', 'yewande.okonkwo@example.org'],
        ],
    ],
    [
        // Out of state on purpose. Her own page says the reply will be straight
        // about whether it is worth their time, and the Desk has to show that
        // case rather than pretend every inquiry is a Maryland practice.
        'legal_name' => 'Allegheny Counseling Collective Inc.',
        'display'    => 'Allegheny Counseling Collective',
        'type'       => 'behavioral_health',
        'state'      => 'PA',
        'status'     => OrganizationRepository::STATUS_PROSPECT,
        'contacts'   => [
            ['Teodora Mihalache', 'executive director', 'teodora.mihalache@example.org'],
        ],
    ],
    [
        'legal_name' => 'Monocacy Speech and Language Services LLC',
        'display'    => 'Monocacy Speech and Language',
        'type'       => 'other',
        'state'      => 'MD',
        'status'     => OrganizationRepository::STATUS_CLOSED,
        'contacts'   => [
            ['Bartholomew Nkemdirim', 'practice owner', 'bart.nkemdirim@example.org'],
        ],
    ],
];

$db->transaction(function () use ($db, $organizations, $users, $memberships, $clock, $practices, $option): void {
    $now = $clock->nowUtc();

    // ------------------------------------------------------------------
    // The owner admin. In Version 1 this is the only staff account, and the
    // only account with a password at all.
    //
    // The password is either supplied or generated. A generated one is printed
    // once, here, and never stored anywhere but as its own hash, so there is no
    // second copy to leak.
    // ------------------------------------------------------------------
    $ownerEmail = 'nanafrimpgskc@gmail.com';
    $existing = $users->findByEmail($ownerEmail);

    if ($existing === null) {
        $password = $option('admin-password') ?? bin2hex(random_bytes(12));
        $ownerId = $users->create($ownerEmail, AuthService::hashPassword($password), null, true);
        $memberships->grant($ownerId, Role::OWNER_ADMIN, null);

        fwrite(STDOUT, "\n  Owner admin created\n");
        fwrite(STDOUT, '    email     ' . $ownerEmail . "\n");
        if ($option('admin-password') === null) {
            fwrite(STDOUT, '    password  ' . $password . "\n");
            fwrite(STDOUT, "    This is printed once and is not stored. Save it now.\n");
        }
    } else {
        $ownerId = (string) $existing['id'];
        $memberships->grant($ownerId, Role::OWNER_ADMIN, null);
        fwrite(STDOUT, "\n  Owner admin already present, left as it is.\n");
    }

    // ------------------------------------------------------------------
    // The fictional practices and their contacts.
    // ------------------------------------------------------------------
    $orgCount = 0;
    $contactCount = 0;

    foreach ($practices as $practice) {
        $organizationId = $organizations->create(
            $practice['legal_name'],
            $practice['display'],
            $practice['type'],
            $practice['state'],
            $practice['status']
        );
        $orgCount++;

        foreach ($practice['contacts'] as [$name, $title, $email]) {
            $db->insert('sa_contacts', [
                'id'              => Uuid::v4(),
                'organization_id' => $organizationId,
                'name'            => $name,
                'work_email'      => strtolower($email),
                'role_title'      => $title,
                'phone'           => null,
                'active'          => 1,
                'created_at'      => $now,
            ]);
            $contactCount++;
        }
    }

    fwrite(STDOUT, sprintf(
        "\n  %d fictional organizations, %d contacts.\n"
        . "  Every one is invented. No real practice, no patient, no claim.\n\n",
        $orgCount,
        $contactCount
    ));
});

exit(0);
