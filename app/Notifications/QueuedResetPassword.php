<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;

/**
 * Password-reset mail is deliberately sent in the web request. The reset
 * page must only claim success after SMTP has accepted the message; queuing
 * this notification made delivery failures invisible to the customer.
 */
class QueuedResetPassword extends ResetPassword {}
