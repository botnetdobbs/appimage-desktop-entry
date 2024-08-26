#!/usr/bin/env php
<?php

function getDesktopCategories(): array {
    $categorySet = [];
    $desktopFiles = array_merge(
        glob('/usr/share/applications/*.desktop'),
        glob('/usr/local/share/applications/*.desktop'),
        glob($_SERVER['HOME'] . '/.local/share/applications/*.desktop')
    );
    
    foreach ($desktopFiles as $file) {
        $contents = file_get_contents($file);
        if (preg_match('/^Categories=(.+)$/m', $contents, $matches)) {
            $categories = explode(';', $matches[1]);
            foreach ($categories as $category) {
                if (!empty($category)) {
                    $categorySet[$category] = true;
                }
            }
        }
    }
    
    $categories = array_keys($categorySet);
    sort($categories);
    return $categories;
}

function promptSelection(string $prompt, array $options): string {
    echo $prompt . PHP_EOL;
    foreach ($options as $index => $option) {
        echo ($index + 1) . ") $option" . PHP_EOL;
    }
    
    while (true) {
        $selection = (int)readline("Enter your selection: ");
        if ($selection > 0 && $selection <= count($options)) {
            return $options[$selection - 1];
        }
        echo "Invalid selection. Please try again." . PHP_EOL;
    }
}

function createDesktopEntry(string $appimagePath): void {
    $appimageFullPath = realpath($appimagePath);
    if (!$appimageFullPath || !is_file($appimageFullPath)) {
        die("File not found: $appimagePath" . PHP_EOL);
    }

    $appName = pathinfo($appimageFullPath, PATHINFO_FILENAME);
    $iconDir = $_SERVER['HOME'] . '/.local/share/icons';
    $desktopEntryDir = $_SERVER['HOME'] . '/.local/share/applications';
    $desktopEntryPath = "$desktopEntryDir/$appName.desktop";

    // Extract AppImage
    $tmpDir = sys_get_temp_dir() . '/' . uniqid('appimage_');
    mkdir($tmpDir);
    exec("\"$appimageFullPath\" --appimage-extract --destdir=\"$tmpDir\" > /dev/null 2>&1");

    // Select icon
    $icons = glob("$tmpDir/squashfs-root/*.{png,svg,xpm}", GLOB_BRACE);
    $iconSrc = promptSelection("Choose an icon:", array_map('basename', $icons));
    $iconExt = pathinfo($iconSrc, PATHINFO_EXTENSION);
    $iconDst = "$iconDir/$appName.$iconExt";
    copy("$tmpDir/squashfs-root/$iconSrc", $iconDst);

    // Select category
    $categories = getDesktopCategories();
    $category = promptSelection("Choose a category:", $categories);

    // Create desktop entry
    if (!is_dir($desktopEntryDir)) {
        mkdir($desktopEntryDir, 0755, true);
    }
    $desktopEntry = "[Desktop Entry]\n" .
                    "Name=$appName\n" .
                    "Exec=\"$appimageFullPath\"\n" .
                    "Icon=$iconDst\n" .
                    "Type=Application\n" .
                    "Terminal=false\n" .
                    "Categories=$category\n";
    file_put_contents($desktopEntryPath, $desktopEntry);

    echo "Desktop entry created: $desktopEntryPath" . PHP_EOL;

    // Clean up
    exec("rm -rf \"$tmpDir\"");
}

function removeDesktopEntry(string $appimagePath): void {
    $appName = pathinfo($appimagePath, PATHINFO_FILENAME);
    $iconDir = $_SERVER['HOME'] . '/.local/share/icons';
    $desktopEntryDir = $_SERVER['HOME'] . '/.local/share/applications';
    $desktopEntryPath = "$desktopEntryDir/$appName.desktop";

    // Remove desktop entry
    if (file_exists($desktopEntryPath)) {
        unlink($desktopEntryPath);
    }

    // Remove icon
    $icons = glob("$iconDir/$appName.*");
    foreach ($icons as $icon) {
        unlink($icon);
    }

    echo "Desktop entry and icon removed for $appName." . PHP_EOL;
}

// Main execution
if ($argc < 2) {
    die("Usage: {$argv[0]} <path_to_appimage> [--remove]" . PHP_EOL);
}

$appimagePath = $argv[1];
$isRemove = isset($argv[2]) && $argv[2] === '--remove';

if ($isRemove) {
    removeDesktopEntry($appimagePath);
} else {
    createDesktopEntry($appimagePath);
}