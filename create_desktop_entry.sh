#!/bin/bash

# Function to prompt for selection
prompt_selection() {
    local prompt="$1"
    shift
    local options=("$@")
    echo "$prompt"
    select choice in "${options[@]}"; do
        if [[ -n $choice ]]; then
            echo "$choice"
            return
        fi
        echo "Invalid selection. Please try again."
    done
}

# Check if AppImage path is provided
if [ $# -eq 0 ]; then
    echo "Usage: $0 <path_to_appimage> [--remove]"
    exit 1
fi

APPIMAGE_PATH=$(readlink -f "$1")
if [ ! -f "$APPIMAGE_PATH" ]; then
    echo "File not found: $APPIMAGE_PATH"
    exit 1
fi

APPIMAGE_FILENAME=$(basename "$APPIMAGE_PATH")
APP_NAME="${APPIMAGE_FILENAME%.*}"

# Define paths
ICON_DIR="${HOME}/.local/share/icons"
DESKTOP_ENTRY_DIR="${HOME}/.local/share/applications"
DESKTOP_ENTRY_PATH="${DESKTOP_ENTRY_DIR}/$APP_NAME.desktop"

# Check if --remove flag is passed
if [ "$2" == "--remove" ]; then
    # Remove desktop entry and icon
    rm -f "$DESKTOP_ENTRY_PATH"
    rm -f "$ICON_DIR/$APP_NAME".*
    echo "Desktop entry and icon removed for $APP_NAME."
    exit 0
fi

# Extract AppImage
TMP_DIR=$(mktemp -d)
"$APPIMAGE_PATH" --appimage-extract --destdir="$TMP_DIR" >/dev/null 2>&1

# Select icon
cd "$TMP_DIR/squashfs-root" || exit 1
ICONS=(*.png *.svg *.xpm)
ICON_SRC=$(prompt_selection "Choose an icon:" "${ICONS[@]}")

# Copy icon
ICON_EXT="${ICON_SRC##*.}"
ICON_DST="${ICON_DIR}/$APP_NAME.$ICON_EXT"
cp "$ICON_SRC" "$ICON_DST"

# Select category
CATEGORIES=("AudioVideo" "Development" "Education" "Game" "Graphics" "Network" "Office" "Science" "Settings" "System" "Utility")
CATEGORY=$(prompt_selection "Choose a category:" "${CATEGORIES[@]}")

# Create desktop entry
mkdir -p "$DESKTOP_ENTRY_DIR"
cat <<EOT > "$DESKTOP_ENTRY_PATH"
[Desktop Entry]
Name=$APP_NAME
Exec="$APPIMAGE_PATH"
Icon=$ICON_DST
Type=Application
Terminal=false
Categories=$CATEGORY
EOT

echo "Desktop entry created: $DESKTOP_ENTRY_PATH"

# Clean up
rm -rf "$TMP_DIR"