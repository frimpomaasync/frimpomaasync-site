<?php
declare(strict_types=1);

namespace SoftAppeals\Services;

use SoftAppeals\Database;
use SoftAppeals\Repositories\MembershipRepository;
use SoftAppeals\Repositories\OrganizationRepository;
use SoftAppeals\Repositories\UserRepository;
use SoftAppeals\Support\Clock;
use SoftAppeals\Support\Uuid;

/**
 * The fictional practices, planted on a staging database that has none.
 *
 * Same list as database/seeds/fixtures.php, reachable without a command line,
 * because there is no command line. The CLI seeder still works and is still the
 * right tool where a shell exists.
 *
 * Two guards, and they are the whole safety story:
 *
 *   1. It only runs when the organizations table is completely empty. One real
 *      practice in the database and this never fires again.
 *   2. The caller only invokes it when SA_AUTO_SEED allows, which is false in
 *      production. Invented practices appearing on the live Desk during a real
 *      call is the failure that would cost her the most.
 *
 * Every name below is invented. There is no patient, no claim number, no date
 * of service and no dollar figure, because the Phase 1 schema has no column
 * that could hold one.
 */
final class SeedService
{
    /**
     * Matched to her real fit criteria from /soft-appeals: behavioral health is
     * best fit, outpatient PT and OT is a strong fit, dental is a strong fit
     * priced differently. One row is out of state on purpose, because her own
     * page promises to be straight about whether it is worth their time, and
     * the Desk has to show that case rather than pretend it never arrives.
     */
    private const PRACTICES = [
        [
            'legal'  => 'Tidewater Behavioral Health Associates LLC',
            'name'   => 'Tidewater Behavioral Health',
            'type'   => 'behavioral_health',
            'state'  => 'MD',
            'status' => OrganizationRepository::STATUS_ACTIVE,
            'people' => [
                ['Rosalind Achebe', 'practice administrator', 'rosalind.achebe@example.org'],
                ['Dev Ramanathan', 'billing lead', 'dev.ramanathan@example.org'],
            ],
        ],
        [
            'legal'  => 'Patapsco Therapy Group, P.A.',
            'name'   => 'Patapsco Therapy Group',
            'type'   => 'behavioral_health',
            'state'  => 'MD',
            'status' => OrganizationRepository::STATUS_ACTIVE,
            'people' => [['Ingrid Sollberger', 'owner', 'ingrid.sollberger@example.org']],
        ],
        [
            'legal'  => 'Catoctin Physical and Occupational Therapy LLC',
            'name'   => 'Catoctin PT and OT',
            'type'   => 'pt_ot',
            'state'  => 'MD',
            'status' => OrganizationRepository::STATUS_PROSPECT,
            'people' => [['Marcus Whitlow', 'clinic director', 'marcus.whitlow@example.org']],
        ],
        [
            'legal'  => 'Chesapeake Family Dental Partners LLC',
            'name'   => 'Chesapeake Family Dental',
            'type'   => 'dental',
            'state'  => 'MD',
            'status' => OrganizationRepository::STATUS_PROSPECT,
            'people' => [['Yewande Okonkwo', 'office manager', 'yewande.okonkwo@example.org']],
        ],
        [
            'legal'  => 'Allegheny Counseling Collective Inc.',
            'name'   => 'Allegheny Counseling Collective',
            'type'   => 'behavioral_health',
            'state'  => 'PA',
            'status' => OrganizationRepository::STATUS_PROSPECT,
            'people' => [['Teodora Mihalache', 'executive director', 'teodora.mihalache@example.org']],
        ],
        [
            'legal'  => 'Monocacy Speech and Language Services LLC',
            'name'   => 'Monocacy Speech and Language',
            'type'   => 'other',
            'state'  => 'MD',
            'status' => OrganizationRepository::STATUS_CLOSED,
            'people' => [['Bartholomew Nkemdirim', 'practice owner', 'bart.nkemdirim@example.org']],
        ],
    ];

    private Database $db;
    private Clock $clock;
    private OrganizationRepository $organizations;
    private UserRepository $users;
    private MembershipRepository $memberships;

    public function __construct(
        Database $db,
        Clock $clock,
        OrganizationRepository $organizations,
        UserRepository $users,
        MembershipRepository $memberships
    ) {
        $this->db = $db;
        $this->clock = $clock;
        $this->organizations = $organizations;
        $this->users = $users;
        $this->memberships = $memberships;
    }

    /** Returns the number of organizations created. Zero if any already exist. */
    public function seedIfEmpty(?AuditService $audit = null): int
    {
        if (!$this->db->tableExists('sa_organizations')) {
            return 0;
        }
        if ((int) $this->db->value('SELECT COUNT(*) FROM sa_organizations') > 0) {
            return 0;
        }

        $created = 0;
        $this->db->transaction(function () use (&$created): void {
            $now = $this->clock->nowUtc();
            foreach (self::PRACTICES as $practice) {
                $organizationId = $this->organizations->create(
                    $practice['legal'],
                    $practice['name'],
                    $practice['type'],
                    $practice['state'],
                    $practice['status']
                );
                foreach ($practice['people'] as [$name, $title, $email]) {
                    $this->db->insert('sa_contacts', [
                        'id'              => Uuid::v4(),
                        'organization_id' => $organizationId,
                        'name'            => $name,
                        'work_email'      => strtolower($email),
                        'role_title'      => $title,
                        'phone'           => null,
                        'active'          => 1,
                        'created_at'      => $now,
                    ]);
                }
                $created++;
            }
        });

        $audit?->record('schema.seed', 'success', 'organization', null, [
            'count'       => $created,
            'environment' => 'non-production',
        ]);

        return $created;
    }

    /** True when nobody can sign in yet, which is what opens the setup page. */
    public function needsOwner(): bool
    {
        if (!$this->db->tableExists('sa_memberships')) {
            return true;
        }
        return (int) $this->db->value(
            "SELECT COUNT(*) FROM sa_memberships WHERE role = 'owner_admin'"
        ) === 0;
    }
}
