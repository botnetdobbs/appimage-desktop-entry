#!/usr/bin/env php

<?php

function getDesktopCategories(): array
{
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

function promptSelection(string $prompt, array $options): string
{
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

function createSymlink(string $appimageFullPath): string
{
    $appName = pathinfo($appimageFullPath, PATHINFO_FILENAME);
    $defaultName = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $appName));

    echo "Suggested command name: $defaultName" . PHP_EOL;

    while (true) {
        $commandName = readline("Enter command name (press Enter to use suggested name): ");
        $commandName = empty($commandName) ? $defaultName : strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $commandName));

        $command = "command -v $commandName"; // Check if command already exists
        exec($command, $output, $returnVar);

        if ($returnVar === 0) {
            $existingPath = trim($output[0]);
            echo "Warning: Command '$commandName' already exists at: $existingPath" . PHP_EOL;
            $tryAgain = strtolower(readline("Would you like to try a different name? (Y/n): "));
            if ($tryAgain !== 'n') {
                continue;
            }
        }

        $symlinkPath = "/usr/local/bin/$commandName";

        if (file_exists($symlinkPath)) {
            echo "Warning: $symlinkPath already exists." . PHP_EOL;
            if (is_link($symlinkPath)) {
                $target = readlink($symlinkPath);
                echo "It points to: $target" . PHP_EOL;
            }
            $override = strtolower(readline("Do you want to override it? (y/N): "));
            if ($override !== 'y') {
                $tryAgain = strtolower(readline("Would you like to try a different name? (Y/n): "));
                if ($tryAgain !== 'n') {
                    continue;
                }
                die("Aborted by user." . PHP_EOL);
            }

            $removeCommand = "sudo rm \"$symlinkPath\"";
            exec($removeCommand, $output, $returnVar);
            if ($returnVar !== 0) {
                die("Failed to remove existing symlink. Make sure you have sudo privileges." . PHP_EOL);
            }
        }

        $command = "sudo ln -s \"$appimageFullPath\" \"$symlinkPath\"";
        exec($command, $output, $returnVar);

        if ($returnVar !== 0) {
            die("Failed to create symlink. Make sure you have sudo privileges." . PHP_EOL);
        }

        echo "Symlink created: $symlinkPath" . PHP_EOL;
        return $commandName;
    }
}

function extractAppImage(string $appimageFullPath, string $tmpDir): void
{
    echo "Extracting AppImage contents..." . PHP_EOL;

    if (!is_executable($appimageFullPath)) {
        echo "Making AppImage executable..." . PHP_EOL;
        if(! chmod($appimageFullPath, 0755)) {
            die("Failed to make AppImage executable." . PHP_EOL);
        }
    }

    exec("cd $tmpDir && $appimageFullPath --appimage-extract 2>&1", $output, $returnVar);

    if ($returnVar !== 0) {
        die("Failed to extract AppImage contents. Error: " . implode("\n", $output) . PHP_EOL);
    }

    if (!is_dir("$tmpDir/squashfs-root")) {
        die("Extraction appeared to succeed but squashfs-root directory not found." . PHP_EOL);
    }
}

function selectIcon(string $extractPath, string $appName): string
{
    $currentDir = getcwd();
    chdir($extractPath);

    $icons = glob("*.png");

    if (empty($icons)) {
        die("No .png files found in " . getcwd() . PHP_EOL);
    }

    echo "Choose icon: " . PHP_EOL;
    foreach ($icons as $index => $filename) {
        echo " " . ($index + 1) . ") $filename" . PHP_EOL;
    }

    while (true) {
        $selectedIndex = (int)readline("");
        if ($selectedIndex > 0 && $selectedIndex <= count($icons)) {
            break;
        }
        echo "Invalid selection. Please try again." . PHP_EOL;
    }

    $iconSrc = $icons[$selectedIndex - 1];
    $iconExt = pathinfo($iconSrc, PATHINFO_EXTENSION);
    $iconDir = $_SERVER['HOME'] . '/.local/share/icons';
    $iconDst = "$iconDir/$appName.$iconExt";

    if (!is_dir($iconDir)) {
        mkdir($iconDir, 0755, true);
    }

    if (!copy($iconSrc, $iconDst)) {
        die("Failed to copy icon from $iconSrc to $iconDst" . PHP_EOL);
    }

    // Restore original directory
    chdir($currentDir);

    return $iconDst;
}

function createDesktopEntry(string $appimagePath): void
{
    $appimageFullPath = realpath($appimagePath);
    if (!$appimageFullPath || !is_file($appimageFullPath)) {
        die("File not found: $appimagePath" . PHP_EOL);
    }

    // Create symlink first
    $commandName = createSymlink($appimageFullPath);

    $appName = pathinfo($appimageFullPath, PATHINFO_FILENAME);
    $desktopEntryDir = $_SERVER['HOME'] . '/.local/share/applications';
    $desktopEntryPath = "$desktopEntryDir/$appName.desktop";

    $tmpDir = sys_get_temp_dir() . '/' . uniqid('appimage_');
    mkdir($tmpDir);

    // Extract AppImage
    extractAppImage($appimageFullPath, $tmpDir);

    // Select icon
    $iconPath = selectIcon("$tmpDir/squashfs-root", $appName);

    // Select category
    $categories = getDesktopCategories();
    $category = promptSelection("Choose a category:", $categories);

    // Create desktop entry
    if (!is_dir($desktopEntryDir)) {
        mkdir($desktopEntryDir, 0755, true);
    }

    $desktopEntry = "[Desktop Entry]\n" .
        "Name=$appName\n" .
        "Exec=$commandName\n" .
        "Icon=$iconPath\n" .
        "Type=Application\n" .
        "Terminal=false\n" .
        "Categories=$category\n";

    file_put_contents($desktopEntryPath, $desktopEntry);
    echo "Desktop entry created: $desktopEntryPath" . PHP_EOL;

    // Clean up
    exec("rm -rf \"$tmpDir\"");
}

function removeDesktopEntry(string $appimagePath): void
{
    $appName = pathinfo($appimagePath, PATHINFO_FILENAME);
    $iconDir = $_SERVER['HOME'] . '/.local/share/icons';
    $desktopEntryDir = $_SERVER['HOME'] . '/.local/share/applications';
    $desktopEntryPath = "$desktopEntryDir/$appName.desktop";

    // Remove symlink if exists
    $defaultName = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $appName));
    $symlinkPath = "/usr/local/bin/$defaultName";
    if (is_link($symlinkPath)) {
        exec("sudo rm \"$symlinkPath\"");
        echo "Symlink removed: $symlinkPath" . PHP_EOL;
    }

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
