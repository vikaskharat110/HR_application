<?php
/** POST /logout — destroys the session and cookie (A7). CSRF-checked by the router. */
declare(strict_types=1);

action_logout();
redirect('/login');
