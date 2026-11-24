<?php

namespace App\Support;

use RuntimeException;

/** The partner has not connected Outlook, or Microsoft no longer accepts the connection. */
class OutlookNotConnectedException extends RuntimeException {}
