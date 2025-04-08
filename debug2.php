<?php
// Debug file to read and analyze class-free-plugins-manager.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Reading class-free-plugins-manager.php content:<br>";
$content = file_get_contents('admin/includes/class-free-plugins-manager.php');
if ($content === false) {
    echo "Failed to read file<br>";
} else {
    // Count how many times 'multisite' appears in the file
    $multisite_count = substr_count($content, 'multisite');
    echo "Found 'multisite' {$multisite_count} times in the file<br>";
    
    // Line-by-line analysis to find issues around the multisite references
    $lines = explode("\n", $content);
    
    for ($i = 0; $i < count($lines); $i++) {
        if (strpos($lines[$i], 'multisite') !== false) {
            echo "Line " . ($i+1) . ": " . htmlspecialchars($lines[$i]) . "<br>";
            // Check a few lines before and after for context
            for ($j = max(0, $i-3); $j <= min(count($lines)-1, $i+3); $j++) {
                if ($j != $i) {
                    echo "Context Line " . ($j+1) . ": " . htmlspecialchars($lines[$j]) . "<br>";
                }
            }
            echo "<br>";
        }
    }
} 