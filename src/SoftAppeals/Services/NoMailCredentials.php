<?php
declare(strict_types=1);

namespace SoftAppeals\Services;

/**
 * Thrown by the mail transport when there is nothing to connect with, as
 * opposed to a connection that was made and refused. MailService records the
 * two as different categories, because "no credentials here" is a
 * configuration fact and "the server said no" is an incident.
 */
final class NoMailCredentials extends \RuntimeException
{
}
