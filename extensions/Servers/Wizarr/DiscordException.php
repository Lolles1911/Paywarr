<?php

namespace Paymenter\Extensions\Servers\Wizarr;

use Exception;

/**
 * Discord could not answer whether someone is a member: bad configuration,
 * an outage or rate limiting. Never means "not a member".
 */
class DiscordException extends Exception {}
