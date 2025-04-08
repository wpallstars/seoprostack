<?php
// Debug file to identify WordPress plugin errors
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Starting debug process<br>";

// First check free-plugins.php syntax
$output = shell_exec('php -l admin/data/free-plugins.php');
echo "Syntax check for free-plugins.php: " . $output . "<br>";

// Then check class-free-plugins-manager.php syntax
$output = shell_exec('php -l admin/includes/class-free-plugins-manager.php');
echo "Syntax check for class-free-plugins-manager.php: " . $output . "<br>";

// Try including the files
echo "Attempting to include free-plugins.php<br>";
include_once 'admin/data/free-plugins.php';
echo "Successfully included free-plugins.php<br>";

echo "Attempting to include class-free-plugins-manager.php<br>";
include_once 'admin/includes/class-free-plugins-manager.php';
echo "Successfully included class-free-plugins-manager.php<br>";

// Check the function output
if (function_exists('wp_allstars_get_free_plugins')) {
    $plugins = wp_allstars_get_free_plugins();
    echo "Function wp_allstars_get_free_plugins() exists and returned:<br>";
    echo "<pre>";
    print_r($plugins);
    echo "</pre>";
} else {
    echo "Function wp_allstars_get_free_plugins() is not defined<br>";
} 