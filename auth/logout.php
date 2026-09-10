<?php
require_once __DIR__ . '/../includes/bootstrap.php';
logoutUser($pdo);
redirect('/auth/login.php');
