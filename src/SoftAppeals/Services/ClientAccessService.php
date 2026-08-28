<?php
declare(strict_types=1);

namespace SoftAppeals\Services;

use SoftAppeals\Auth\SessionManager;
use SoftAppeals\Database;
use SoftAppeals\Domain\Role;
use SoftAppeals\Repositories\ContactRepository;
use SoftAppeals\Repositories\EngagementRepository;
use SoftAppeals\Repositories\InvitationRepository;
use SoftAppeals\Repositories\LoginCodeRepository;
use SoftAppeals\Repositories\MembershipRepository;
use SoftAppeals\Repositories\UserRepository;
use SoftAppeals\Security\Csrf;
use SoftAppeals\Security\Hmac;
use SoftAppeals\Security\RateLimiter;
use SoftAppeals\Support\Clock;

/**
 * How a practice gets in, and what it is allowed to be once it is in.
 *
 * Two doors, and neither of them is a password. Section 10.2.
 *
 *   The first visit arrives on a one-time invitation, emailed with the terms.
 *   Redeeming it is a single transaction: the link is burnt, the person becomes
 *   a contact and a user with one role, and a client session is established. The
 *   token then leaves the URL, because a Recovery Room link sitting in browser
 *   history is a Recovery Room somebody else can open.
 *
 *   Every visit afterwards arrives on a six-digit code sent to the same address.
 *   The code is good for ten minutes, for one use, and only for an address that
 *   already holds a client role. An address nobody has invited gets the same
 *   answer as one that has, because a login form that says "no such account" is
 *   a way to find out which practices she works with.
 *
 * The organization is derived from what was redeemed and pinned to the session.
 * It is never read from the request. Section 15.1 states that outright, and it
 * is the difference between a portal and a leak: these practices compete with
 * each other in the same state.
 */
final class ClientAccessService
{
    /** Session keys this service owns, on top of the ones SessionManager sets. */
    public const SESSION_ENGAGEMENT = 'sa_engagement_id';
    public const SESSION_CONTACT    = 'sa_contact_id';
    public const SESSION_EMAIL      = 'sa_client_email';

    public const CODE_TEMPLATE_KEY = 'client_login_code';
    public const CODE_SUBJECT      = 'Your Soft Appeals sign-in code';

    private Database $db;
    private Clock $clock;
    private SessionManager $session;
    private Csrf $csrf;
    private InvitationRepository $invitations;
    private LoginCodeRepository $codes;
    private ContactRepository $contacts;
    private UserRepository $users;
    private MembershipRepository $memberships;
    private EngagementRepository $engagements;
    private RateLimiter $limiter;
    private MailService $mail;
    private AuditService $audit;
    private Hmac $hmac;

    public function __construct(
        Database $db,
        Clock $clock,
        SessionManager $session,
        Csrf $csrf,
        InvitationRepository $invitations,
        LoginCodeRepository $codes,
        ContactRepository $contacts,
        UserRepository $users,
        MembershipRepository $memberships,
        EngagementRepository $engagements,
        RateLimiter $limiter,
        MailService $mail,
        AuditService $audit,
        Hmac $hmac
    ) {
        $this->db = $db;
        $this->clock = $clock;
        $this->session = $session;
        $this->csrf = $csrf;
        $this->invitations = $invitations;
        $this->codes = $codes;
        $this->contacts = $contacts;
        $this->users = $users;
        $this->memberships = $memberships;
        $this->engagements = $engagements;
        $this->limiter = $limiter;
        $this->mail = $mail;
        $this->audit = $audit;
        $this->hmac = $hmac;
    }

    // -----------------------------------------------------------------------
    // The invitation door.
    // -----------------------------------------------------------------------

    /**
     * Redeem a one-time invitation and become a client session.
     *
     * Returns null for a token that is unknown, used, revoked or expired. Those
     * four are one answer on purpose: a caller must not be able to tell them
     * apart, because that is how a burnt link is distinguished from a wrong one
     * and how a guess is confirmed.
     *
     * The whole exchange is one transaction. A half-redeemed invitation, marked
     * used with no membership behind it, would lock a practice out of a link
     * that cannot be re-sent without her noticing.
     *
     * @return array{
     *     organization_id:string,
     *     engagement_id:?string,
     *     contact_id:string,
     *     user_id:string,
     *     email:string
     * }|null
     */
    public function redeemInvitation(string $token, string $purpose): ?array
    {
        $token = trim($token);
        if ($token === '' || preg_match('/^[0-9a-f]{64,128}$/i', $token) !== 1) {
            // Not the shape a token has. Refused without touching the database,
            // so a scanner posting junk cannot make work for it.
            $this->audit->record('client.invitation_redeem', 'failure', 'invitation', null, [
                'reason' => 'malformed token',
            ]);
            return null;
        }

        $invitation = $this->invitations->redeemable($token, $purpose);
        if ($invitation === null) {
            $this->audit->record('client.invitation_redeem', 'failure', 'invitation', null, [
                'reason' => 'not redeemable',
            ]);
            return null;
        }

        $result = $this->db->transaction(function () use ($invitation): ?array {
            // Burn it first. If somebody else redeemed this token in the
            // millisecond since it was read, markUsed writes nothing and the
            // whole exchange stops here rather than creating a second session.
            if (!$this->invitations->markUsed((string) $invitation['id'])) {
                return null;
            }

            $organizationId = (string) $invitation['organization_id'];
            $email = strtolower(trim((string) $invitation['contact_email']));

            $contact = $this->contacts->upsert(
                $organizationId,
                $this->nameFromEngagement($invitation),
                $email
            );

            $user = $this->users->findByEmail($email);
            $userId = $user === null
                ? $this->users->create($email, null, $contact['id'])
                : (string) $user['id'];

            // The person who opens the terms link speaks for the practice on
            // this page: they choose the cadence, they name the signer. That is
            // the organization admin role and nothing wider. Signing, approving
            // and seeing money are separate roles, granted to whoever they name.
            $this->memberships->grant($userId, Role::ORG_ADMIN, $organizationId);

            return [
                'organization_id' => $organizationId,
                'engagement_id'   => $invitation['engagement_id'] === null
                    ? null
                    : (string) $invitation['engagement_id'],
                'contact_id'      => $contact['id'],
                'user_id'         => $userId,
                'email'           => $email,
            ];
        });

        if ($result === null) {
            $this->audit->record('client.invitation_redeem', 'failure', 'invitation', (string) $invitation['id'], [
                'reason' => 'already redeemed',
            ]);
            return null;
        }

        $this->establish($result);

        $this->audit->record(
            'client.invitation_redeem',
            'success',
            'invitation',
            (string) $invitation['id'],
            ['reason' => (string) $invitation['purpose']],
            $result['organization_id']
        );

        return $result;
    }

    // -----------------------------------------------------------------------
    // The code door.
    // -----------------------------------------------------------------------

    /**
     * Send a sign-in code, if that address is one we know.
     *
     * The return says nothing about whether it was. Every caller is told the
     * same thing and the page prints the same sentence, so this form cannot be
     * used to work out which practices exist.
     *
     * Throws RateLimitException when this caller or this address has asked too
     * often, which the page turns into a wait rather than a refusal.
     *
     * @return array{sent:bool,expires_at:?string}
     */
    public function requestLoginCode(string $email): array
    {
        $email = strtolower(trim($email));

        $this->limiter->hit('client.code.request', 'email:' . $email);
        $this->limiter->hit('client.code.request', 'ip:' . $this->hmac->ipDigest('client_login'));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->audit->record('client.code_request', 'failure', 'user', null, [
                'reason' => 'not an address',
            ]);
            return ['sent' => false, 'expires_at' => null];
        }

        $user = $this->users->findByEmail($email);
        if ($user === null || (int) $user['active'] !== 1) {
            $this->audit->record('client.code_request', 'failure', 'user', null, [
                'reason' => 'no such client',
            ]);
            return ['sent' => false, 'expires_at' => null];
        }

        $userId = (string) $user['id'];
        $organizationId = $this->clientOrganizationFor($userId);
        if ($organizationId === null) {
            // A staff account, or a user with no client role. Neither gets in
            // through this door: the Desk has its own, with a password.
            $this->audit->record('client.code_request', 'failure', 'user', $userId, [
                'reason' => 'no client membership',
            ]);
            return ['sent' => false, 'expires_at' => null];
        }

        $minted = $this->codes->mint($organizationId, $userId, $email);

        $this->mail->send(
            $email,
            self::CODE_SUBJECT,
            $this->codeBody($minted['code'], $this->clock->displayDateTime($minted['expires_at'])),
            self::CODE_TEMPLATE_KEY,
            null,
            $organizationId,
            hash('sha256', 'login_code|' . $minted['id'])
        );

        $this->audit->record('client.code_request', 'success', 'user', $userId, [
            'count' => $minted['revoked'],
        ], $organizationId);

        return ['sent' => true, 'expires_at' => $minted['expires_at']];
    }

    /**
     * Check a code and become a client session.
     *
     * Returns null for every failure, one answer for all of them, for the same
     * reason the request above tells nobody anything.
     *
     * @return array{organization_id:string,user_id:string,email:string}|null
     */
    public function verifyLoginCode(string $email, string $code): ?array
    {
        $email = strtolower(trim($email));

        $this->limiter->hit('client.code.verify', 'email:' . $email);
        $this->limiter->hit('client.code.verify', 'ip:' . $this->hmac->ipDigest('client_login'));

        $row = $this->codes->verify($email, $code);
        if ($row === null) {
            $this->audit->record('client.code_verify', 'failure', 'user', null, [
                'reason' => 'wrong or expired code',
            ]);
            return null;
        }

        $userId = $row['user_id'] === null ? null : (string) $row['user_id'];
        $user = $userId === null ? $this->users->findByEmail($email) : $this->users->find($userId);
        if ($user === null || (int) $user['active'] !== 1) {
            $this->audit->record('client.code_verify', 'failure', 'user', $userId, [
                'reason' => 'account is not active',
            ]);
            return null;
        }
        $userId = (string) $user['id'];

        $organizationId = (string) $row['organization_id'];

        // The membership is re-checked here rather than trusted from the code.
        // A role removed between the code being sent and the code being used
        // must take effect on this click, not on the next one.
        if (!$this->memberships->rolesFor($userId, $organizationId)) {
            $this->audit->record('client.code_verify', 'failure', 'user', $userId, [
                'reason' => 'no client membership',
            ], $organizationId);
            return null;
        }

        $contact = $this->contacts->findByEmail($organizationId, $email);
        $engagement = $this->latestEngagementFor($organizationId);

        $this->establish([
            'organization_id' => $organizationId,
            'engagement_id'   => $engagement === null ? null : (string) $engagement['id'],
            'contact_id'      => $contact === null ? null : (string) $contact['id'],
            'user_id'         => $userId,
            'email'           => $email,
        ]);

        $this->limiter->clear('client.code.verify', 'email:' . $email);
        $this->users->markLoggedIn($userId, $this->clock->nowUtc());

        $this->audit->record('client.code_verify', 'success', 'user', $userId, [], $organizationId);

        return [
            'organization_id' => $organizationId,
            'user_id'         => $userId,
            'email'           => $email,
        ];
    }

    // -----------------------------------------------------------------------
    // The session.
    // -----------------------------------------------------------------------

    /**
     * What this client session is, or null when there is not one.
     *
     * Read on every request, from the database, not from what the session was
     * told at login. A deactivated account or a revoked role takes effect on
     * this click.
     *
     * @return array{
     *     user:array<string,mixed>,
     *     organization_id:string,
     *     engagement:?array<string,mixed>,
     *     contact_id:?string,
     *     email:string,
     *     roles:list<string>
     * }|null
     */
    public function context(): ?array
    {
        $this->session->start();

        if ($this->session->kind() !== SessionManager::KIND_CLIENT) {
            return null;
        }
        $userId = $this->session->userId();
        $organizationId = $this->session->organizationId();
        if ($userId === null || $organizationId === null) {
            return null;
        }

        $user = $this->users->find($userId);
        if ($user === null || (int) $user['active'] !== 1) {
            $this->session->destroy();
            return null;
        }

        $roles = $this->memberships->rolesFor($userId, $organizationId);
        if ($roles === []) {
            $this->session->destroy();
            return null;
        }

        $engagementId = $this->session->get(self::SESSION_ENGAGEMENT);
        $engagement = null;
        if (is_string($engagementId) && $engagementId !== '') {
            $engagement = $this->engagements->findWithOrganization($engagementId);
            // The tenancy check, on every read. An engagement id in a session
            // that does not belong to that session's organization is dropped
            // rather than rendered.
            if ($engagement !== null && (string) $engagement['organization_id'] !== $organizationId) {
                $engagement = null;
            }
        }
        if ($engagement === null) {
            $latest = $this->latestEngagementFor($organizationId);
            $engagement = $latest === null
                ? null
                : $this->engagements->findWithOrganization((string) $latest['id']);
            if ($engagement !== null) {
                $this->session->set(self::SESSION_ENGAGEMENT, (string) $engagement['id']);
            }
        }

        $contactId = $this->session->get(self::SESSION_CONTACT);

        return [
            'user'            => $user,
            'organization_id' => $organizationId,
            'engagement'      => $engagement,
            'contact_id'      => is_string($contactId) && $contactId !== '' ? $contactId : null,
            'email'           => (string) $user['email'],
            'roles'           => $roles,
        ];
    }

    public function signOut(): void
    {
        $userId = $this->session->userId();
        if ($userId !== null && $this->session->kind() === SessionManager::KIND_CLIENT) {
            $this->audit->record('client.sign_out', 'success', 'user', $userId);
        }
        $this->session->destroy();
    }

    // -----------------------------------------------------------------------

    /** @param array{organization_id:string,engagement_id:?string,contact_id:?string,user_id:string,email:string} $who */
    private function establish(array $who): void
    {
        $this->session->establish(
            SessionManager::KIND_CLIENT,
            $who['user_id'],
            $who['organization_id']
        );
        $this->csrf->rotate();
        $this->session->set(self::SESSION_ENGAGEMENT, $who['engagement_id']);
        $this->session->set(self::SESSION_CONTACT, $who['contact_id']);
        $this->session->set(self::SESSION_EMAIL, $who['email']);
    }

    /** The organization a user holds a client role in, if exactly one. */
    private function clientOrganizationFor(string $userId): ?string
    {
        $rows = $this->db->all(
            'SELECT DISTINCT organization_id FROM sa_memberships'
            . ' WHERE user_id = :u AND organization_id IS NOT NULL',
            ['u' => $userId]
        );
        if (count($rows) !== 1) {
            // Nobody holds a client role in two practices yet, and until the
            // page can ask which one they mean, guessing is worse than not
            // letting them in through this door.
            return null;
        }
        return (string) $rows[0]['organization_id'];
    }

    /** @return array<string,mixed>|null the newest open engagement for a practice */
    private function latestEngagementFor(string $organizationId): ?array
    {
        return $this->db->one(
            'SELECT * FROM sa_engagements WHERE organization_id = :o'
            . ' ORDER BY closed_at IS NULL DESC, opened_at DESC',
            ['o' => $organizationId]
        );
    }

    /**
     * A name to open the contact with.
     *
     * The invitation carries an address and not a name, so the practice's own
     * name stands in until the person tells us theirs on the preferences page.
     * An empty name column would print as a blank line on every later screen.
     *
     * @param array<string,mixed> $invitation
     */
    private function nameFromEngagement(array $invitation): string
    {
        $email = (string) $invitation['contact_email'];
        $local = strstr($email, '@', true);
        return $local === false || $local === '' ? 'Contact' : $local;
    }

    /**
     * The sign-in code email. Section 16.2, template 18.
     *
     * Plain text, no link, no attachment, and no mention of which practice it
     * is for. A code that arrives at the wrong address should tell the person
     * who receives it nothing at all.
     */
    private function codeBody(string $code, string $expiresDisplay): string
    {
        $lines = [];
        $lines[] = 'Your Soft Appeals sign-in code is:';
        $lines[] = '';
        $lines[] = '    ' . $code;
        $lines[] = '';
        $lines[] = 'Enter it on the page you started from. It stops working at';
        $lines[] = $expiresDisplay . ', and it can only be used once.';
        $lines[] = '';
        $lines[] = 'If you did not ask for this code, nothing has happened and you';
        $lines[] = 'can ignore this message.';
        $lines[] = '';
        $lines[] = 'Do not reply with patient, member, claim, clinical, or other';
        $lines[] = 'protected health information.';
        $lines[] = '';
        $lines[] = 'Nana Frimpongmaa';
        $lines[] = 'Soft Appeals';

        return implode("\n", $lines) . "\n";
    }
}
