<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (isLoggedIn()) {
    redirect(dashboardUrlForRole(currentUser()['role']));
}
redirect('/auth/login.php');
