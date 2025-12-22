<?php
// Minimal test without requiring any files
header('Content-Type: text/plain');
echo "PHP is working\n";
echo "Request method: " . $_SERVER['REQUEST_METHOD'] . "\n";
echo "Request URI: " . $_SERVER['REQUEST_URI'] . "\n";