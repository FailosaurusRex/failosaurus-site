<?php
/**
 * ONE-TIME USE — delete this file after running it.
 *
 * Visit this page once to generate the bcrypt hash for your admin password,
 * then paste it into frx-db-config.php and delete this file.
 *
 * Usage: https://failosaurusrex.com/admin/setup-password.php?p=YourPasswordHere
 */

if (empty($_GET['p'])) {
    die('Usage: ?p=YourPassword');
}

$hash = password_hash($_GET['p'], PASSWORD_BCRYPT);
echo '<pre style="font-family:monospace;font-size:1rem;background:#111;color:#39ff14;padding:2rem;">';
echo "Add this line to /home/u197307054/frx-db-config.php:\n\n";
echo '$admin_pass = \'' . $hash . '\';' . "\n\n";
echo "Then DELETE this file (admin/setup-password.php).";
echo '</pre>';
