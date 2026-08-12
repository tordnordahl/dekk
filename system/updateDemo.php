<?php
declare(strict_types=1);

// Selve handlingen ligger bak DekkPilots vanlige innlogging, CSRF-beskyttelse
// og eksplisitte superadmin-kontroll. En innlogget superadmin slipper derfor
// å skrive passordet en gang til.
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Location: index.php/superadmin/demo-oppdatering', true, 302);
exit;
